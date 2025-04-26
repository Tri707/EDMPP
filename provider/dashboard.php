<?php
// Initialize session and verify authentication
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Security: Redirect if not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Initialize variables with default values
$providerProfileImage = '../default.png';
$providerId = 0;
$providerData = [];
$dashboardStats = [
    'total_bookings' => 0,
    'recent_bookings' => 0,
    'total_quotes' => 0,
    'recent_quotes' => 0,
    'total_earnings' => 0,
    'recent_earnings' => 0,
    'avg_rating' => 0,
    'total_reviews' => 0,
    'recent_reviews' => 0,
    'completed_bookings' => 0,
    'confirmed_bookings' => 0,
    'pending_bookings' => 0,
    'cancelled_bookings' => 0
];
$upcomingBookings = [];
$pendingQuotes = [];
$recentReviews = [];
$bookingStatusBreakdown = [];
$revenueByDay = [];
$completionRate = 0;

// Calculate date ranges for statistics
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$thisWeekStart = date('Y-m-d', strtotime('this week Monday'));
$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$last30Days = date('Y-m-d', strtotime('-30 days'));

// Get provider profile information
try {
    // Query to get provider user data with proper join
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties, 
                            p.experience 
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider' AND u.status = 'active'");
    $stmt->execute([$userId]);
    $providerDataResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($providerDataResult) {
        $providerData = $providerDataResult;
        $providerId = (int)$providerData['provider_id'];
        
        // Set profile image path with enhanced security checks
        if (!empty($providerData['profile_image'])) {
            if (preg_match('/^https?:\/\//i', $providerData['profile_image'])) {
                // External URL - validate if needed
                $providerProfileImage = filter_var($providerData['profile_image'], FILTER_SANITIZE_URL);
            } else {
                // Local file - validate path and existence
                $imagePath = '../profile_images/' . basename($providerData['profile_image']);
                if (file_exists($imagePath) && is_readable($imagePath)) {
                    $providerProfileImage = $imagePath;
                }
            }
        }
    } else {
        // Redirect to error page if user is not a provider
        error_log("Provider data not found for user ID: $userId");
        header('Location: ../error.php?message=Provider%20data%20not%20found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Fetch dashboard statistics for this provider
try {
    if ($providerId) {
        // Optimized query for provider statistics - reducing subqueries for better performance
        // Using joins instead of multiple subqueries
        
        // Total and recent bookings
        $bookingsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                COALESCE(SUM(total_price), 0) as total_earnings,
                COALESCE(SUM(CASE WHEN created_at >= ? THEN total_price ELSE 0 END), 0) as recent_earnings
            FROM bookings 
            WHERE provider_id = ?
        ";
        
        $bookingsStmt = $pdo->prepare($bookingsQuery);
        $bookingsStmt->execute([$last30Days, $last30Days, $providerId]);
        $bookingsStats = $bookingsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Quote requests count
        $quotesQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN qr.created_at >= ? THEN 1 ELSE 0 END) as recent
            FROM notifications n
            JOIN quote_requests qr ON n.reference_id = qr.id
            WHERE n.provider_id = ? AND n.type = 'quote_request'
        ";
        
        $quotesStmt = $pdo->prepare($quotesQuery);
        $quotesStmt->execute([$last30Days, $providerId]);
        $quotesStats = $quotesStmt->fetch(PDO::FETCH_ASSOC);
        
        // Reviews statistics
        $reviewsQuery = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent,
                COALESCE(AVG(rating), 0) as avg_rating
            FROM reviews
            WHERE provider_id = ?
        ";
        
        $reviewsStmt = $pdo->prepare($reviewsQuery);
        $reviewsStmt->execute([$last30Days, $providerId]);
        $reviewsStats = $reviewsStmt->fetch(PDO::FETCH_ASSOC);
        
        // Combine all statistics
        $dashboardStats = [
            'total_bookings' => (int)$bookingsStats['total'],
            'recent_bookings' => (int)$bookingsStats['recent'],
            'total_quotes' => (int)$quotesStats['total'],
            'recent_quotes' => (int)$quotesStats['recent'],
            'total_earnings' => (float)$bookingsStats['total_earnings'],
            'recent_earnings' => (float)$bookingsStats['recent_earnings'],
            'avg_rating' => (float)$reviewsStats['avg_rating'],
            'total_reviews' => (int)$reviewsStats['total'],
            'recent_reviews' => (int)$reviewsStats['recent'],
            'completed_bookings' => (int)$bookingsStats['completed'],
            'confirmed_bookings' => (int)$bookingsStats['confirmed'],
            'pending_bookings' => (int)$bookingsStats['pending'],
            'cancelled_bookings' => (int)$bookingsStats['cancelled']
        ];
        
        // Calculate completion rate
        $totalBookings = 
            $dashboardStats['completed_bookings'] + 
            $dashboardStats['confirmed_bookings'] + 
            $dashboardStats['pending_bookings'] + 
            $dashboardStats['cancelled_bookings'];
        
        $completionRate = ($totalBookings > 0) ? 
            round(($dashboardStats['completed_bookings'] / $totalBookings) * 100, 1) : 0;
        
        // Upcoming bookings (pending and confirmed, sorted by date)
        $upcomingQuery = "
            SELECT 
                b.id, 
                b.booking_date, 
                b.booking_time, 
                b.status, 
                b.total_price,
                b.service_id,
                COALESCE(s.name, 'General Service') as service_name,
                c.id as customer_id,
                c.first_name as customer_first_name,
                c.last_name as customer_last_name,
                c.profile_image as customer_image,
                c.phone as customer_phone,
                c.email as customer_email
            FROM bookings b
            JOIN users c ON b.customer_id = c.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.provider_id = ? 
            AND b.status IN ('pending', 'confirmed') 
            AND (b.booking_date > CURDATE() OR (b.booking_date = CURDATE() AND b.booking_time >= CURTIME()))
            ORDER BY b.booking_date ASC, b.booking_time ASC
            LIMIT 5
        ";
        
        $upcomingStmt = $pdo->prepare($upcomingQuery);
        $upcomingStmt->execute([$providerId]);
        $upcomingBookings = $upcomingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Pending quote requests for this provider using efficient join
        $pendingQuotesQuery = "
            SELECT 
                q.id, 
                q.device_type, 
                q.issue_description,
                q.status, 
                q.created_at,
                u.id as customer_id,
                u.first_name,
                u.last_name,
                u.email,
                u.phone
            FROM quote_requests q
            JOIN users u ON q.customer_id = u.id
            JOIN notifications n ON n.reference_id = q.id AND n.provider_id = ? AND n.type = 'quote_request'
            WHERE q.status = 'pending'
            ORDER BY q.created_at DESC
            LIMIT 5
        ";
        
        $pendingQuotesStmt = $pdo->prepare($pendingQuotesQuery);
        $pendingQuotesStmt->execute([$providerId]);
        $pendingQuotes = $pendingQuotesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Recent reviews for this provider
        $reviewsQuery = "
            SELECT 
                r.id, 
                r.rating, 
                r.comment, 
                r.created_at,
                b.id as booking_id,
                cu.id as customer_id,
                cu.first_name as customer_first_name,
                cu.last_name as customer_last_name,
                cu.profile_image as customer_image
            FROM reviews r
            JOIN users cu ON r.customer_id = cu.id
            LEFT JOIN bookings b ON r.booking_id = b.id
            WHERE r.provider_id = ?
            ORDER BY r.created_at DESC
            LIMIT 5
        ";
        
        $reviewsStmt = $pdo->prepare($reviewsQuery);
        $reviewsStmt->execute([$providerId]);
        $recentReviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Booking status breakdown
        $statusQuery = "
            SELECT 
                status,
                COUNT(*) as count
            FROM bookings
            WHERE provider_id = ?
            GROUP BY status
            ORDER BY count DESC
        ";
        
        $statusStmt = $pdo->prepare($statusQuery);
        $statusStmt->execute([$providerId]);
        $bookingStatusBreakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Revenue by day for the last 30 days
        $revenueQuery = "
            SELECT 
                DATE(created_at) as date,
                COALESCE(SUM(total_price), 0) as revenue
            FROM bookings
            WHERE provider_id = ? AND created_at >= ?
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ";
        
        $revenueStmt = $pdo->prepare($revenueQuery);
        $revenueStmt->execute([$providerId, $last30Days]);
        $revenueByDay = $revenueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching provider dashboard data: " . $e->getMessage());
    // Default values already set
}

// Format data for charts
$revenueChartData = [];
foreach ($revenueByDay as $row) {
    $revenueChartData[] = [
        'date' => date('M j', strtotime($row['date'])),
        'revenue' => (float)$row['revenue']
    ];
}

$statusChartData = [];
foreach ($bookingStatusBreakdown as $row) {
    $statusChartData[] = [
        'name' => ucfirst($row['status']),
        'value' => (int)$row['count']
    ];
}

// Ensure we have proper default chart data if none exists
if (empty($statusChartData)) {
    $statusChartData = [
        ['name' => 'Completed', 'value' => 0],
        ['name' => 'Confirmed', 'value' => 0],
        ['name' => 'Pending', 'value' => 0],
        ['name' => 'Cancelled', 'value' => 0]
    ];
}

// Encode chart data for JavaScript - use JSON_NUMERIC_CHECK to properly convert numbers
$revenueChartJson = json_encode($revenueChartData ?: [], JSON_NUMERIC_CHECK);
$statusChartJson = json_encode($statusChartData ?: [], JSON_NUMERIC_CHECK);

// Calculate today's earnings
$todayEarnings = 0;
foreach ($revenueByDay as $row) {
    if ($row['date'] === $today) {
        $todayEarnings = (float)$row['revenue'];
        break;
    }
}

// Get unread messages count
try {
    $unreadMessagesQuery = "
        SELECT COUNT(*) as count
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
    ";
    
    $unreadMessagesStmt = $pdo->prepare($unreadMessagesQuery);
    $unreadMessagesStmt->execute([$userId]);
    $unreadMessagesCount = (int)$unreadMessagesStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching unread messages count: " . $e->getMessage());
    $unreadMessagesCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Dashboard - FixItNow</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Theme CSS Variables */
        :root {
            /* Light Theme Variables (default) */
            --primary-color: #7952b3;      /* Purple */
            --primary-hover: #6941a0;      /* Darker Purple */
            --primary-light: #e4dafc;      /* Light Purple */
            --accent-color: #37b24d;       /* Green */
            --accent-light: #d3f9d8;       /* Light Green */
            --text-color: #212529;         /* Dark text for light mode */
            --text-muted: #6c757d;         /* Muted text for light mode */
            --bg-color: #f2f0f7;           /* Light purple background */
            --card-bg: #ffffff;            /* Card background */
            --header-bg: #212529;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #212529;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --modal-bg: #ffffff;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
            --sidebar-bg: #303238;         /* Sidebar background */
            --sidebar-hover: #3e4148;      /* Sidebar hover */
        }
        
        /* Dark Theme Variables - Enhanced for better vibrancy */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #ffffff;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #18181b;           /* Slightly less dark background */
            --card-bg: #242429;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #242429;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        
        .main-container {
            display: flex;
            flex: 1;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 1rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            transition: background-color 0.3s ease;
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
        }
        
        /* Theme Toggle Button */
        .theme-toggle-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: transparent;
            border: 2px solid rgba(255, 255, 255, 0.2);
            color: var(--header-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .theme-toggle-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        /* Currency Icon */
        .currency-icon {
            vertical-align: middle;
            margin-right: 3px;
            margin-top: -3px;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            flex-shrink: 0;
            box-shadow: 0.25rem 0 1rem var(--shadow-color);
            transition: all 0.3s ease;
            z-index: 999;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 76px;
            }
            
            .sidebar .nav-text {
                display: none;
            }
            
            .sidebar .nav-link {
                justify-content: center;
            }
            
            .content-area {
                margin-left: 76px;
            }
        }
        
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .sidebar-nav .nav-link {
            color: var(--footer-text);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav .nav-link:hover {
            color: var(--header-text);
            background-color: var(--sidebar-hover);
        }
        
        .sidebar-nav .nav-link.active {
            color: var(--header-text);
            background: linear-gradient(90deg, var(--primary-color) 0%, transparent 100%);
        }
        
        .sidebar-nav .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background-color: var(--accent-color);
        }
        
        .sidebar-nav .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            transition: all 0.3s ease;
        }
        
        .page-title {
            font-weight: 700;
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 60px;
            height: 3px;
            background-color: var(--primary-color);
        }
        
        /* Card Styles */
        .card {
            border-radius: 0.75rem;
            border: none;
            background-color: var(--card-bg);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .card-footer {
            background-color: rgba(0, 0, 0, 0.05);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        /* Stats Card */
        .stat-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .stat-icon {
            font-size: 3.5rem;
            opacity: 0.1;
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .stat-change {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .stat-card.success {
            border-left: 4px solid var(--accent-color);
        }
        
        .stat-card.primary {
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.warning {
            border-left: 4px solid #ffc107;
        }
        
        .stat-card.info {
            border-left: 4px solid #0dcaf0;
        }
        
        /* Chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-bottom: 1.5rem;
        }
        
        /* Dashboard action buttons */
        .action-button {
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Activity list */
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item:hover {
            background-color: rgba(var(--primary-color-rgb), 0.05);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 1rem;
        }
        
        .activity-icon.booking {
            background-color: var(--primary-color);
        }
        
        .activity-icon.quote {
            background-color: #ffc107;
        }
        
        .activity-icon.review {
            background-color: var(--accent-color);
        }
        
        .activity-icon.user {
            background-color: #0dcaf0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .activity-info {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.confirmed {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        /* Rating Stars */
        .rating-stars {
            color: #ffc107;
            font-size: 0.9rem;
        }
        
        /* Schedule Card */
        .schedule-card .booking-time {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .schedule-card .booking-customer {
            font-weight: 600;
        }
        
        .schedule-card .booking-service {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .schedule-card .booking-price {
            font-weight: 700;
            color: var(--accent-color);
        }
        
        /* Profile Verification Badge */
        .verification-badge {
            display: inline-flex;
            align-items: center;
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }
        
        .verification-badge.pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        /* Progress bar */
        .progress {
            height: 0.75rem;
            border-radius: 1rem;
            background-color: rgba(0, 0, 0, 0.1);
        }
        
        .progress-bar {
            border-radius: 1rem;
        }

        /* Added: Better mobile responsiveness */
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .action-button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            
            .stat-value {
                font-size: 1.5rem;
            }
            
            .card-header {
                padding: 0.75rem 1rem;
            }
            
            .card-body {
                padding: 1rem;
            }
        }

        /* Added: Improved loading state */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--bg-color);
            opacity: 0.7;
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .spinner-container {
            text-align: center;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading dashboard...</p>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="text-decoration-none d-flex align-items-center">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-primary">Provider Portal</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($providerProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo isset($providerData['first_name']) ? htmlspecialchars($providerData['first_name']) : 'Provider'; ?></span>
                            <?php if(isset($providerData['is_verified']) && $providerData['is_verified'] == 1): ?>
                                <i class="fas fa-tools text-primary ms-1" title="Verified Provider"></i>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="services.php"><i class="fas fa-cogs me-2"></i> My Services</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">My Schedule</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">My Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="earnings.php">
                            <span class="nav-icon"><i class="fas fa-wallet"></i></span>
                            <span class="nav-text">Earnings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <!-- Provider Status Summary -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="page-title">Provider Dashboard</h2>
                    <p class="text-muted">
                        Welcome back, <?php echo isset($providerData['first_name']) && isset($providerData['last_name']) ? 
                        htmlspecialchars($providerData['first_name'] . ' ' . $providerData['last_name']) : 'Provider'; ?>! 
                        Here's your activity overview.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <?php if(isset($providerData['is_verified']) && $providerData['is_verified'] == 1): ?>
                        <div class="verification-badge">
                            <i class="fas fa-tools me-2"></i> Verified Provider
                        </div>
                    <?php else: ?>
                        <div class="verification-badge pending">
                            <i class="fas fa-clock me-2"></i> Verification Pending
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card success h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-money-bill-wave stat-icon text-success"></i>
                            <h6 class="mb-3">Total Earnings</h6>
                            <div class="d-flex align-items-baseline mb-1">
                                <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                <div class="stat-value"><?php echo number_format((float)$dashboardStats['total_earnings'], 0); ?></div>
                            </div>
                            <div class="stat-change text-success">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php 
                                    $earningsGrowth = 0;
                                    if ((float)$dashboardStats['total_earnings'] > 0) {
                                        $previousEarnings = (float)$dashboardStats['total_earnings'] - (float)$dashboardStats['recent_earnings'];
                                        $earningsGrowth = $previousEarnings > 0 ? 
                                            ((float)$dashboardStats['recent_earnings'] / $previousEarnings) * 100 : 100;
                                    }
                                    echo number_format($earningsGrowth, 1);
                                ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card primary h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-calendar-check stat-icon text-primary"></i>
                            <h6 class="mb-3">Bookings</h6>
                            <div class="stat-value"><?php echo number_format((int)$dashboardStats['total_bookings']); ?></div>
                            <div class="stat-change text-primary">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php 
                                    $bookingsGrowth = 0;
                                    if ((int)$dashboardStats['total_bookings'] > 0) {
                                        $bookingsGrowth = ((int)$dashboardStats['recent_bookings'] / (int)$dashboardStats['total_bookings']) * 100;
                                    }
                                    echo number_format($bookingsGrowth, 1);
                                ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card warning h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-star stat-icon text-warning"></i>
                            <h6 class="mb-3">Average Rating</h6>
                            <div class="stat-value d-flex align-items-center">
                                <?php echo number_format((float)$dashboardStats['avg_rating'], 1); ?>
                                <div class="rating-stars ms-2">
                                    <?php
                                        $rating = (float)$dashboardStats['avg_rating'];
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= floor($rating)) {
                                                echo '<i class="fas fa-star"></i>';
                                            } elseif ($i - 0.5 <= $rating) {
                                                echo '<i class="fas fa-star-half-alt"></i>';
                                            } else {
                                                echo '<i class="far fa-star"></i>';
                                            }
                                        }
                                    ?>
                                </div>
                            </div>
                            <div class="stat-label">
                                Based on <?php echo number_format((int)$dashboardStats['total_reviews']); ?> reviews
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card info h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-check-circle stat-icon text-info"></i>
                            <h6 class="mb-3">Completion Rate</h6>
                            <div class="stat-value"><?php echo $completionRate; ?>%</div>
                            <div class="progress mt-2">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $completionRate; ?>%" 
                                     aria-valuenow="<?php echo $completionRate; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Action Buttons -->
            <div class="row g-4 mb-4">
                <div class="col-lg-12">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="schedule.php" class="btn btn-primary action-button">
                            <i class="fas fa-calendar-alt me-2"></i>View Schedule
                        </a>
                        <a href="services.php" class="btn btn-info action-button">
                            <i class="fas fa-cogs me-2"></i>Manage Services
                        </a>
                        <a href="quotes.php" class="btn btn-warning action-button">
                            <i class="fas fa-file-invoice-dollar me-2"></i>Pending Quotes 
                            <?php if(count($pendingQuotes) > 0): ?>
                            <span class="badge bg-light text-dark ms-1"><?php echo count($pendingQuotes); ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="bookings.php?status=pending" class="btn btn-secondary action-button">
                            <i class="fas fa-hourglass-half me-2"></i>Pending Bookings
                            <?php if($dashboardStats['pending_bookings'] > 0): ?>
                            <span class="badge bg-light text-dark ms-1"><?php echo $dashboardStats['pending_bookings']; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Charts and Schedule Section -->
            <div class="row g-4 mb-4">
                <!-- Earnings Chart -->
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Earnings Trend (Last 30 Days)</h5>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active" data-period="daily">Daily</button>
                                <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" id="earningsChart"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Booking Status Distribution -->
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Booking Status</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" id="statusChart"></div>
                            <div class="mt-3">
                                <div class="small text-muted mb-2">Bookings breakdown</div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Completed</span>
                                    <span class="text-success"><?php echo number_format((int)$dashboardStats['completed_bookings']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Confirmed</span>
                                    <span class="text-primary"><?php echo number_format((int)$dashboardStats['confirmed_bookings']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span>Pending</span>
                                    <span class="text-warning"><?php echo number_format((int)$dashboardStats['pending_bookings']); ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span>Cancelled</span>
                                    <span class="text-danger"><?php echo number_format((int)$dashboardStats['cancelled_bookings']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Upcoming Bookings and Quote Requests -->
            <div class="row g-4">
                <!-- Upcoming Bookings -->
                <div class="col-lg-6">
                    <div class="card h-100 schedule-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Upcoming Bookings</h5>
                            <a href="schedule.php" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($upcomingBookings)): ?>
                                <div class="p-4 text-center">
                                    <div class="text-muted mb-3">
                                        <i class="fas fa-calendar-day fa-3x"></i>
                                    </div>
                                    <h6>No upcoming bookings</h6>
                                    <p class="small text-muted">You don't have any upcoming bookings at the moment.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($upcomingBookings as $booking): ?>
                                        <div class="list-group-item border-0 border-bottom">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="booking-time">
                                                    <i class="far fa-calendar-alt me-1"></i>
                                                    <?php echo date('D, M j, Y', strtotime($booking['booking_date'])); ?> at 
                                                    <?php echo date('g:i A', strtotime($booking['booking_time'])); ?>
                                                </div>
                                                <span class="status-badge <?php echo htmlspecialchars(strtolower($booking['status'])); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($booking['status'])); ?>
                                                </span>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-8">
                                                    <div class="booking-customer mb-1">
                                                        <i class="far fa-user me-1"></i>
                                                        <?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?>
                                                    </div>
                                                    <div class="booking-service mb-1">
                                                        <i class="fas fa-cog me-1"></i>
                                                        <?php echo htmlspecialchars($booking['service_name']); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <i class="fas fa-phone-alt me-1"></i>
                                                        <?php echo htmlspecialchars($booking['customer_phone']); ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-md-end mt-2 mt-md-0">
                                                    <div class="booking-price mb-2">
                                                        <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="14" height="14">
                                                        <?php echo number_format((float)$booking['total_price'], 0); ?>
                                                    </div>
                                                    <a href="booking-detail.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye me-1"></i> View
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($upcomingBookings)): ?>
                        <div class="card-footer text-center">
                            <a href="schedule.php" class="text-decoration-none">View all upcoming bookings</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Recent Reviews and Pending Quotes -->
                <div class="col-lg-6">
                    <!-- Recent Reviews -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Reviews</h5>
                            <a href="reviews.php" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recentReviews)): ?>
                                <div class="p-4 text-center">
                                    <div class="text-muted mb-3">
                                        <i class="fas fa-star fa-3x"></i>
                                    </div>
                                    <h6>No reviews yet</h6>
                                    <p class="small text-muted">You haven't received any reviews from customers yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentReviews as $review): ?>
                                        <div class="list-group-item border-0 border-bottom">
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="me-2">
                                                    <?php if (!empty($review['customer_image'])): ?>
                                                        <img src="<?php echo htmlspecialchars($review['customer_image']); ?>" alt="Customer" class="rounded-circle" width="40" height="40">
                                                    <?php else: ?>
                                                        <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                                            <?php 
                                                                $initials = strtoupper(substr($review['customer_first_name'] ?? 'U', 0, 1) . substr($review['customer_last_name'] ?? 'N', 0, 1));
                                                                echo $initials;
                                                            ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($review['customer_first_name'] . ' ' . $review['customer_last_name']); ?></div>
                                                    <div class="small text-muted"><?php echo date('M j, Y', strtotime($review['created_at'])); ?></div>
                                                </div>
                                                <div class="ms-auto">
                                                    <div class="rating-stars">
                                                        <?php
                                                            $rating = (float)$review['rating'];
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                if ($i <= $rating) {
                                                                    echo '<i class="fas fa-star"></i>';
                                                                } else {
                                                                    echo '<i class="far fa-star"></i>';
                                                                }
                                                            }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="mb-0 small"><?php echo htmlspecialchars($review['comment']); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($recentReviews)): ?>
                        <div class="card-footer text-center">
                            <a href="reviews.php" class="text-decoration-none">View all reviews</a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pending Quote Requests -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Pending Quote Requests</h5>
                            <a href="quotes.php" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($pendingQuotes)): ?>
                                <div class="p-4 text-center">
                                    <div class="text-muted mb-3">
                                        <i class="fas fa-file-invoice-dollar fa-3x"></i>
                                    </div>
                                    <h6>No pending quotes</h6>
                                    <p class="small text-muted">You don't have any pending quote requests at the moment.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($pendingQuotes as $quote): ?>
                                        <div class="list-group-item border-0 border-bottom">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <div class="fw-bold">
                                                    <i class="fas fa-mobile-alt me-1"></i>
                                                    <?php echo htmlspecialchars(ucfirst($quote['device_type'])); ?> Repair
                                                </div>
                                                <span class="status-badge pending">
                                                    Pending
                                                </span>
                                            </div>
                                            <div class="small text-muted mb-2">
                                                <?php echo htmlspecialchars(substr($quote['issue_description'], 0, 100)); ?>
                                                <?php if (strlen($quote['issue_description']) > 100): ?>...<?php endif; ?>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="small">
                                                    <i class="far fa-user me-1"></i>
                                                    <?php echo htmlspecialchars($quote['first_name'] . ' ' . $quote['last_name']); ?>
                                                </div>
                                                <a href="quote-detail.php?id=<?php echo $quote['id']; ?>" class="btn btn-sm btn-outline-warning">
                                                    <i class="fas fa-reply me-1"></i> Respond
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($pendingQuotes)): ?>
                        <div class="card-footer text-center">
                            <a href="quotes.php" class="text-decoration-none">View all quote requests</a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading overlay when page is loaded
            document.getElementById('loadingOverlay').style.display = 'none';
            
            // Theme toggle functionality
            const themeToggleBtn = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            
            // Function to set theme
            function setTheme(isDark) {
                if (isDark) {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                }
            }
            
            // Check for saved theme preference or prefer-color-scheme
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                setTheme(savedTheme === 'dark');
            } else {
                // Default to dark theme
                setTheme(true);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
            
            // Earnings Chart - with improved tooltip and responsive design
            const earningsChartData = <?php echo $revenueChartJson; ?>;
            
            const earningsChartCtx = document.getElementById('earningsChart').getContext('2d');
            const earningsChart = new Chart(earningsChartCtx, {
                type: 'line',
                data: {
                    labels: earningsChartData.map(item => item.date),
                    datasets: [{
                        label: 'Earnings',
                        data: earningsChartData.map(item => item.revenue),
                        backgroundColor: 'rgba(55, 178, 77, 0.1)',
                        borderColor: 'rgba(55, 178, 77, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(55, 178, 77, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return 'Earnings: SAR ' + context.parsed.y.toLocaleString();
                                }
                            },
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            titleFont: {
                                size: 13
                            },
                            bodyFont: {
                                size: 12
                            },
                            padding: 10,
                            cornerRadius: 4
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 45
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                borderDash: [2, 4],
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'SAR ' + value.toLocaleString();
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'nearest'
                    }
                }
            });
            
            // Booking Status Chart - with improved colors and animation
            const statusChartData = <?php echo $statusChartJson; ?>;
            
            const statusChartCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusChartCtx, {
                type: 'doughnut',
                data: {
                    labels: statusChartData.map(item => item.name),
                    datasets: [{
                        data: statusChartData.map(item => item.value),
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.8)',  // completed - slightly more vibrant green
                            'rgba(13, 110, 253, 0.8)', // confirmed - blue
                            'rgba(255, 193, 7, 0.8)',  // pending - yellow
                            'rgba(220, 53, 69, 0.8)'   // cancelled - red
                        ],
                        borderWidth: 1,
                        borderColor: 'rgba(255,255,255,0.2)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                },
                                padding: 15
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            titleFont: {
                                size: 13
                            },
                            bodyFont: {
                                size: 12
                            },
                            padding: 10,
                            cornerRadius: 4,
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((acc, data) => acc + data, 0);
                                    const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return `${label}: ${value} (${percentage}%)`;
                                }
                            }
                        }
                    },
                    cutout: '70%',
                    animation: {
                        animateScale: true,
                        animateRotate: true
                    }
                }
            });
            
            // Weekly/Daily Toggle for the Earnings Chart
            const periodButtons = document.querySelectorAll('[data-period]');
            
            periodButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const period = this.getAttribute('data-period');
                    
                    // Remove active class from all buttons
                    periodButtons.forEach(btn => btn.classList.remove('active'));
                    
                    // Add active class to clicked button
                    this.classList.add('active');
                    
                    if (period === 'weekly') {
                        // Group data by week
                        const weeklyData = groupDataByWeek(earningsChartData);
                        updateEarningsChart(weeklyData);
                    } else {
                        // Reset to daily data
                        updateEarningsChart(earningsChartData);
                    }
                });
            });
            
            // Function to group data by week
            function groupDataByWeek(dailyData) {
                const weeks = {};
                
                dailyData.forEach(item => {
                    const date = new Date(`${new Date().getFullYear()} ${item.date}`);
                    const weekNum = getWeekNumber(date);
                    const weekLabel = `Week ${weekNum}`;
                    
                    if (!weeks[weekLabel]) {
                        weeks[weekLabel] = { label: weekLabel, total: 0 };
                    }
                    
                    weeks[weekLabel].total += item.revenue;
                });
                
                return Object.values(weeks).map(week => ({
                    date: week.label,
                    revenue: week.total
                }));
            }
            
            // Get week number of the year
            function getWeekNumber(date) {
                const firstDayOfYear = new Date(date.getFullYear(), 0, 1);
                const pastDaysOfYear = (date - firstDayOfYear) / 86400000;
                return Math.ceil((pastDaysOfYear + firstDayOfYear.getDay() + 1) / 7);
            }
            
            // Update earnings chart with new data
            function updateEarningsChart(data) {
                earningsChart.data.labels = data.map(item => item.date);
                earningsChart.data.datasets[0].data = data.map(item => item.revenue);
                earningsChart.update();
            }
        });
    </script>
</body>
</html>