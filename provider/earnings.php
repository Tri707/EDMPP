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
$earningsStats = [
    'total_earnings' => 0,
    'current_month' => 0,
    'previous_month' => 0,
    'growth_percentage' => 0,
    'pending_amount' => 0,
    'avg_per_booking' => 0,
    'completed_bookings' => 0,
    'total_bookings' => 0
];
$recentPayments = [];
$earningsByService = [];
$earningsByCategory = [];
$monthlyEarnings = [];
$weeklyEarnings = [];
$topServices = [];

// Date ranges for statistics
$currentMonth = date('Y-m-01');
$previousMonth = date('Y-m-01', strtotime('-1 month'));
$endPreviousMonth = date('Y-m-t', strtotime('-1 month'));
$currentYear = date('Y-01-01');
$last12Months = date('Y-m-01', strtotime('-12 months'));
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get provider profile information
try {
    // Query to get provider user data with proper join
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties 
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

// Get earnings statistics for this provider
try {
    if ($providerId) {
        // Total earnings
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) as total_earnings
            FROM bookings
            WHERE provider_id = ? AND status IN ('completed', 'confirmed')
        ");
        $stmt->execute([$providerId]);
        $earningsStats['total_earnings'] = (float)$stmt->fetchColumn();
        
        // Current month earnings
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) as current_month
            FROM bookings
            WHERE provider_id = ? 
            AND status IN ('completed', 'confirmed')
            AND booking_date >= ? AND booking_date <= LAST_DAY(?)
        ");
        $stmt->execute([$providerId, $currentMonth, $currentMonth]);
        $earningsStats['current_month'] = (float)$stmt->fetchColumn();
        
        // Previous month earnings
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) as previous_month
            FROM bookings
            WHERE provider_id = ? 
            AND status IN ('completed', 'confirmed')
            AND booking_date >= ? AND booking_date <= ?
        ");
        $stmt->execute([$providerId, $previousMonth, $endPreviousMonth]);
        $earningsStats['previous_month'] = (float)$stmt->fetchColumn();
        
        // Calculate growth percentage
        if ($earningsStats['previous_month'] > 0) {
            $earningsStats['growth_percentage'] = (($earningsStats['current_month'] - $earningsStats['previous_month']) / $earningsStats['previous_month']) * 100;
        } elseif ($earningsStats['current_month'] > 0) {
            $earningsStats['growth_percentage'] = 100; // If previous month was 0 but current month has earnings
        } else {
            $earningsStats['growth_percentage'] = 0;
        }
        
        // Pending payments amount
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) as pending_amount
            FROM bookings
            WHERE provider_id = ? 
            AND status = 'confirmed'
            AND payment_status = 'unpaid'
        ");
        $stmt->execute([$providerId]);
        $earningsStats['pending_amount'] = (float)$stmt->fetchColumn();
        
        // Booking statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_bookings,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_bookings,
                COALESCE(SUM(total_price), 0) as total_paid
            FROM bookings
            WHERE provider_id = ?
        ");
        $stmt->execute([$providerId]);
        $bookingStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $earningsStats['total_bookings'] = (int)$bookingStats['total_bookings'];
        $earningsStats['completed_bookings'] = (int)$bookingStats['completed_bookings'];
        
        // Calculate average earnings per booking
        if ($earningsStats['completed_bookings'] > 0) {
            $earningsStats['avg_per_booking'] = (float)$bookingStats['total_paid'] / $earningsStats['completed_bookings'];
        }
        
        // Recent payments/bookings
        $stmt = $pdo->prepare("
            SELECT 
                b.id, 
                b.booking_date, 
                b.booking_time,
                b.total_price,
                b.status,
                b.payment_status,
                b.created_at,
                s.name as service_name,
                s.category as service_category,
                u.first_name,
                u.last_name
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            JOIN users u ON b.customer_id = u.id
            WHERE b.provider_id = ?
            ORDER BY b.booking_date DESC
            LIMIT 10
        ");
        $stmt->execute([$providerId]);
        $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get earnings by service
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.name,
                s.category,
                COUNT(b.id) as booking_count,
                COALESCE(SUM(b.total_price), 0) as service_earnings
            FROM services s
            LEFT JOIN bookings b ON s.id = b.service_id AND b.status IN ('completed', 'confirmed')
            WHERE s.provider_id = ? AND s.deleted_by_provider = 0
            GROUP BY s.id
            ORDER BY service_earnings DESC
        ");
        $stmt->execute([$providerId]);
        $earningsByService = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get earnings by category
        $stmt = $pdo->prepare("
            SELECT 
                s.category,
                COUNT(DISTINCT b.id) as booking_count,
                COALESCE(SUM(b.total_price), 0) as category_earnings
            FROM services s
            JOIN bookings b ON s.id = b.service_id AND b.status IN ('completed', 'confirmed')
            WHERE s.provider_id = ?
            GROUP BY s.category
            ORDER BY category_earnings DESC
        ");
        $stmt->execute([$providerId]);
        $earningsByCategory = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Monthly earnings for last 12 months
        $stmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(booking_date, '%Y-%m') as month,
                COUNT(id) as booking_count,
                COALESCE(SUM(total_price), 0) as monthly_earnings
            FROM bookings
            WHERE provider_id = ? 
            AND status IN ('completed', 'confirmed')
            AND booking_date >= ?
            GROUP BY DATE_FORMAT(booking_date, '%Y-%m')
            ORDER BY month ASC
        ");
        $stmt->execute([$providerId, $last12Months]);
        $monthlyEarningsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format monthly data for chart
        $months = [];
        $earnings = [];
        $counts = [];
        
        foreach ($monthlyEarningsData as $data) {
            $monthDate = DateTime::createFromFormat('Y-m', $data['month']);
            $formattedMonth = $monthDate->format('M Y');
            
            $months[] = $formattedMonth;
            $earnings[] = (float)$data['monthly_earnings'];
            $counts[] = (int)$data['booking_count'];
        }
        
        $monthlyEarnings = [
            'labels' => $months,
            'earnings' => $earnings,
            'counts' => $counts
        ];
        
        // Weekly earnings for current year
        $stmt = $pdo->prepare("
            SELECT 
                YEARWEEK(booking_date, 3) as yearweek,
                MIN(booking_date) as week_start,
                COALESCE(SUM(total_price), 0) as weekly_earnings
            FROM bookings
            WHERE provider_id = ? 
            AND status IN ('completed', 'confirmed')
            AND booking_date >= ?
            GROUP BY YEARWEEK(booking_date, 3)
            ORDER BY yearweek ASC
        ");
        $stmt->execute([$providerId, $currentYear]);
        $weeklyEarningsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format weekly data for chart
        $weeks = [];
        $weeklyValues = [];
        
        foreach ($weeklyEarningsData as $data) {
            $weekStart = new DateTime($data['week_start']);
            $formattedWeek = 'Week ' . $weekStart->format('W');
            
            $weeks[] = $formattedWeek;
            $weeklyValues[] = (float)$data['weekly_earnings'];
        }
        
        $weeklyEarnings = [
            'labels' => $weeks,
            'earnings' => $weeklyValues
        ];
        
        // Top earning services
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                s.name,
                s.category,
                COUNT(b.id) as booking_count,
                COALESCE(SUM(b.total_price), 0) as service_earnings
            FROM services s
            JOIN bookings b ON s.id = b.service_id AND b.status IN ('completed', 'confirmed')
            WHERE s.provider_id = ?
            GROUP BY s.id
            ORDER BY service_earnings DESC
            LIMIT 5
        ");
        $stmt->execute([$providerId]);
        $topServices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error fetching earnings data: " . $e->getMessage());
    // Default values already set
}

// Get unread messages count for nav indicator
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

// JSON encode chart data for JavaScript
$monthlyEarningsJson = json_encode($monthlyEarnings, JSON_NUMERIC_CHECK);
$weeklyEarningsJson = json_encode($weeklyEarnings, JSON_NUMERIC_CHECK);
$categoryEarningsJson = json_encode($earningsByCategory, JSON_NUMERIC_CHECK);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earnings - FixItNow</title>
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
            --primary-color-rgba: rgba(121, 82, 179, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(55, 178, 77, 0.05);   /* Green with alpha */
            --success-color: #198754;      /* Success color */
            --warning-color: #ffc107;      /* Warning color */
            --danger-color: #dc3545;       /* Danger color */
            --info-color: #0dcaf0;         /* Info color */
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
            --primary-color-rgba: rgba(166, 135, 255, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(55, 178, 77, 0.05);    /* Green with alpha */
            --success-color: #198754;      /* Success color */
            --warning-color: #ffc107;      /* Warning color */
            --danger-color: #dc3545;       /* Danger color */
            --info-color: #0dcaf0;         /* Info color */
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
        .stats-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .stats-card.primary {
            border-left: 4px solid var(--primary-color);
        }
        
        .stats-card.success {
            border-left: 4px solid var(--success-color);
        }
        
        .stats-card.warning {
            border-left: 4px solid var(--warning-color);
        }
        
        .stats-card.info {
            border-left: 4px solid var(--info-color);
        }
        
        .stats-card.danger {
            border-left: 4px solid var(--danger-color);
        }
        
        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .stats-label {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .stats-icon {
            font-size: 3rem;
            position: absolute;
            top: 1rem;
            right: 1rem;
            opacity: 0.1;
        }
        
        .stats-change {
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        .stats-up {
            color: var(--success-color);
        }
        
        .stats-down {
            color: var(--danger-color);
        }
        
        /* Chart container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        /* Payment Status Badges */
        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.15);
            color: #ffc107;
        }
        
        .status-badge.paid {
            background-color: rgba(25, 135, 84, 0.15);
            color: #198754;
        }
        
        .status-badge.refunded {
            background-color: rgba(220, 53, 69, 0.15);
            color: #dc3545;
        }
        
        .status-badge.unpaid {
            background-color: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }
        
        /* Date Filter */
        .date-filter {
            margin-bottom: 1.5rem;
        }
        
        /* Service Category Colors */
        .service-tag {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.25rem;
            margin-right: 0.5rem;
        }
        
        .service-tag.smartphone {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        
        .service-tag.laptop {
            background-color: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
        }
        
        .service-tag.tablet {
            background-color: rgba(253, 126, 20, 0.1);
            color: #fd7e14;
        }
        
        .service-tag.desktop {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .service-tag.gaming {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .service-tag.tv {
            background-color: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
        }
        
        /* Top Services List */
        .top-service-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .top-service-item:last-child {
            border-bottom: none;
        }
        
        .top-service-name {
            display: flex;
            align-items: center;
        }
        
        .top-service-icon {
            width: 32px;
            height: 32px;
            border-radius: 4px;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        
        .top-service-amount {
            font-weight: 600;
        }
        
        /* Loading overlay */
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
        
        /* Empty state */
        .empty-state {
            padding: 3rem;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        /* Progress circle */
        .progress-circle {
            width: 140px;
            height: 140px;
            margin: 0 auto;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .progress-circle-bar {
            position: absolute;
            width: 100%;
            height: 100%;
        }
        
        .progress-circle-value {
            position: relative;
            font-size: 2rem;
            font-weight: 700;
        }
        
        /* Earnings Table */
        .earnings-table th, .earnings-table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .chart-container {
                height: 250px;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .stats-value {
                font-size: 1.5rem;
            }
            
            .chart-container {
                height: 220px;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .stats-icon {
                display: none;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading earnings data...</p>
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
                        <a class="nav-link" href="dashboard.php">
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
                            <?php if($unreadMessagesCount > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-2"><?php echo $unreadMessagesCount; ?></span>
                            <?php endif; ?>
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
                        <a class="nav-link active" href="earnings.php">
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
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="page-title">Earnings</h2>
                    <p class="text-muted">
                        Track your income, payment history, and earnings statistics.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <h3 class="text-success mb-0">
                        <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                        <?php echo number_format($earningsStats['total_earnings'], 0); ?>
                    </h3>
                    <div class="text-muted">Total Earnings</div>
                </div>
            </div>
            
            <!-- Date Range Filter -->
            <div class="date-filter">
                <div class="card">
                    <div class="card-body p-3">
                        <form method="get" action="" class="row g-2 align-items-center">
                            <div class="col-md-4">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary w-100">Apply Filter</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <?php if ($earningsStats['total_earnings'] > 0): ?>
            <!-- Earnings Stats Cards -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card success">
                        <div class="card-body">
                            <i class="fas fa-money-bill-wave stats-icon text-success"></i>
                            <h6 class="stats-label">This Month</h6>
                            <div class="stats-value">
                                <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                <?php echo number_format($earningsStats['current_month'], 0); ?>
                            </div>
                            <div class="stats-change <?php echo $earningsStats['growth_percentage'] >= 0 ? 'stats-up' : 'stats-down'; ?>">
                                <?php if ($earningsStats['growth_percentage'] >= 0): ?>
                                <i class="fas fa-caret-up me-1"></i>
                                <?php else: ?>
                                <i class="fas fa-caret-down me-1"></i>
                                <?php endif; ?>
                                <?php echo abs(number_format($earningsStats['growth_percentage'], 1)); ?>% vs. last month
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card primary">
                        <div class="card-body">
                            <i class="fas fa-clipboard-check stats-icon text-primary"></i>
                            <h6 class="stats-label">Completed Bookings</h6>
                            <div class="stats-value"><?php echo number_format($earningsStats['completed_bookings']); ?></div>
                            <div class="stats-change">
                                <span class="text-muted">
                                    <?php 
                                    $completionRate = $earningsStats['total_bookings'] > 0 ? 
                                        round(($earningsStats['completed_bookings'] / $earningsStats['total_bookings']) * 100, 1) : 0;
                                    echo $completionRate; 
                                    ?>% completion rate
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card warning">
                        <div class="card-body">
                            <i class="fas fa-hand-holding-usd stats-icon text-warning"></i>
                            <h6 class="stats-label">Average Per Booking</h6>
                            <div class="stats-value">
                                <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                <?php echo number_format($earningsStats['avg_per_booking'], 0); ?>
                            </div>
                            <div class="stats-change">
                                <span class="text-muted">Per completed booking</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card info">
                        <div class="card-body">
                            <i class="fas fa-hourglass-half stats-icon text-info"></i>
                            <h6 class="stats-label">Pending Amount</h6>
                            <div class="stats-value">
                                <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                <?php echo number_format($earningsStats['pending_amount'], 0); ?>
                            </div>
                            <div class="stats-change">
                                <span class="text-muted">From confirmed bookings</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="row g-4 mb-4">
                <!-- Monthly Earnings Chart -->
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Monthly Earnings</h5>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Chart period">
                                <button type="button" class="btn btn-outline-secondary active" data-period="monthly">Monthly</button>
                                <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" id="earningsChart"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Earnings by Category -->
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Earnings by Category</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" id="categoryChart"></div>
                            
                            <?php if(!empty($earningsByCategory)): ?>
                            <div class="mt-3">
                                <?php foreach($earningsByCategory as $category): ?>
                                <div class="d-flex justify-content-between mb-2">
                                    <div class="service-tag <?php echo htmlspecialchars($category['category']); ?>">
                                        <?php 
                                        $icon = 'fas fa-cog';
                                        switch ($category['category']) {
                                            case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                            case 'laptop': $icon = 'fas fa-laptop'; break;
                                            case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                            case 'desktop': $icon = 'fas fa-desktop'; break;
                                            case 'gaming': $icon = 'fas fa-gamepad'; break;
                                            case 'tv': $icon = 'fas fa-tv'; break;
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?> me-1"></i>
                                        <?php echo ucfirst(htmlspecialchars($category['category'])); ?>
                                    </div>
                                    <span class="fw-bold">
                                        <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="12" height="12">
                                        <?php echo number_format($category['category_earnings'], 0); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Top Services and Recent Payments -->
            <div class="row g-4">
                <!-- Top Earning Services -->
                <div class="col-lg-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Top Earning Services</h5>
                        </div>
                        <div class="card-body">
                            <?php if(empty($topServices)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-chart-pie fa-3x text-muted mb-3"></i>
                                <p>No earnings data available for services yet.</p>
                            </div>
                            <?php else: ?>
                            <div class="top-services-list">
                                <?php foreach($topServices as $index => $service): ?>
                                <div class="top-service-item">
                                    <div class="top-service-name">
                                        <div class="top-service-icon">
                                            <?php 
                                            $icon = 'fas fa-cog';
                                            switch ($service['category']) {
                                                case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                                case 'laptop': $icon = 'fas fa-laptop'; break;
                                                case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                                case 'desktop': $icon = 'fas fa-desktop'; break;
                                                case 'gaming': $icon = 'fas fa-gamepad'; break;
                                                case 'tv': $icon = 'fas fa-tv'; break;
                                            }
                                            ?>
                                            <i class="<?php echo $icon; ?>"></i>
                                        </div>
                                        <div>
                                            <div><?php echo htmlspecialchars($service['name']); ?></div>
                                            <small class="text-muted"><?php echo $service['booking_count']; ?> bookings</small>
                                        </div>
                                    </div>
                                    <div class="top-service-amount">
                                        <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="14" height="14">
                                        <?php echo number_format($service['service_earnings'], 0); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Payments -->
                <div class="col-lg-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Payments</h5>
                            <a href="bookings.php" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <?php if(empty($recentPayments)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                <p>No payment records available yet.</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table mb-0 earnings-table">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Customer</th>
                                            <th>Service</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($recentPayments as $payment): ?>
                                        <tr>
                                            <td>
                                                <div><?php echo date('M j, Y', strtotime($payment['booking_date'])); ?></div>
                                                <small class="text-muted"><?php echo date('g:i A', strtotime($payment['booking_time'])); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                            <td>
                                                <?php if(!empty($payment['service_name'])): ?>
                                                <div class="service-tag <?php echo htmlspecialchars($payment['service_category']); ?>">
                                                    <?php 
                                                    $icon = 'fas fa-cog';
                                                    switch ($payment['service_category']) {
                                                        case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                                        case 'laptop': $icon = 'fas fa-laptop'; break;
                                                        case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                                        case 'desktop': $icon = 'fas fa-desktop'; break;
                                                        case 'gaming': $icon = 'fas fa-gamepad'; break;
                                                        case 'tv': $icon = 'fas fa-tv'; break;
                                                    }
                                                    ?>
                                                    <i class="<?php echo $icon; ?> me-1"></i>
                                                    <?php echo htmlspecialchars($payment['service_name']); ?>
                                                </div>
                                                <?php else: ?>
                                                <span class="text-muted">General Service</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="fw-bold">
                                                <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="14" height="14">
                                                <?php echo number_format($payment['total_price'], 0); ?>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo strtolower($payment['payment_status']); ?>">
                                                    <?php echo ucfirst($payment['payment_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <!-- No Earnings Yet -->
            <div class="card">
                <div class="card-body empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <h4>No Earnings Yet</h4>
                    <p class="text-muted mb-4">You haven't received any payments yet. Your earnings will appear here once your bookings are completed.</p>
                    <div class="d-flex justify-content-center gap-3">
                        <a href="services.php" class="btn btn-primary">
                            <i class="fas fa-cogs me-2"></i>Manage Services
                        </a>
                        <a href="bookings.php" class="btn btn-outline-primary">
                            <i class="fas fa-clipboard-list me-2"></i>View Bookings
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
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
            
            // Monthly/Weekly Earnings Chart
            const earningsChartElement = document.getElementById('earningsChart');
            if (earningsChartElement) {
                // Get chart data from PHP
                let monthlyData = <?php echo $monthlyEarningsJson ?: '{"labels":[],"earnings":[],"counts":[]}'; ?>;
                let weeklyData = <?php echo $weeklyEarningsJson ?: '{"labels":[],"earnings":[]}'; ?>;
                
                // Initialize with monthly data
                let currentData = monthlyData;
                
                // Create the chart
                const earningsChart = new Chart(earningsChartElement, {
                    type: 'bar',
                    data: {
                        labels: currentData.labels,
                        datasets: [
                            {
                                label: 'Earnings (SAR)',
                                data: currentData.earnings,
                                backgroundColor: 'rgba(25, 135, 84, 0.6)',
                                borderColor: 'rgba(25, 135, 84, 1)',
                                borderWidth: 1,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Bookings',
                                data: currentData.counts || [],
                                type: 'line',
                                backgroundColor: 'rgba(111, 66, 193, 0.1)',
                                borderColor: 'rgba(111, 66, 193, 1)',
                                borderWidth: 2,
                                pointBackgroundColor: 'rgba(111, 66, 193, 1)',
                                pointBorderColor: '#fff',
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                fill: false,
                                tension: 0.4,
                                yAxisID: 'y1',
                                // Hide this dataset if counts doesn't exist
                                hidden: !currentData.counts || currentData.counts.length === 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Earnings (SAR)'
                                },
                                beginAtZero: true
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Number of Bookings'
                                },
                                beginAtZero: true,
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        if (context.dataset.label === 'Earnings (SAR)') {
                                            return 'Earnings: SAR ' + context.raw.toLocaleString();
                                        } else if (context.dataset.label === 'Bookings') {
                                            return 'Bookings: ' + context.raw;
                                        }
                                        return context.dataset.label + ': ' + context.raw;
                                    }
                                }
                            }
                        }
                    }
                });
                
                // Handle period toggle
                const periodButtons = document.querySelectorAll('[data-period]');
                periodButtons.forEach(button => {
                    button.addEventListener('click', function() {
                        const period = this.getAttribute('data-period');
                        
                        // Remove active class from all buttons
                        periodButtons.forEach(btn => btn.classList.remove('active'));
                        
                        // Add active class to clicked button
                        this.classList.add('active');
                        
                        // Update chart data based on period
                        if (period === 'weekly') {
                            updateChartData(earningsChart, weeklyData);
                        } else {
                            updateChartData(earningsChart, monthlyData);
                        }
                    });
                });
                
                // Function to update chart data
                function updateChartData(chart, newData) {
                    chart.data.labels = newData.labels;
                    chart.data.datasets[0].data = newData.earnings;
                    
                    if (newData.counts && newData.counts.length > 0) {
                        chart.data.datasets[1].data = newData.counts;
                        chart.data.datasets[1].hidden = false;
                    } else {
                        chart.data.datasets[1].hidden = true;
                    }
                    
                    chart.update();
                }
            }
            
            // Category Pie Chart
            const categoryChartElement = document.getElementById('categoryChart');
            if (categoryChartElement) {
                // Get chart data from PHP
                let categoryData = <?php echo $categoryEarningsJson ?: '[]'; ?>;
                
                // Format data for Pie Chart
                const labels = categoryData.map(item => ucfirst(item.category));
                const data = categoryData.map(item => item.category_earnings);
                
                // Define dynamic colors based on categories
                const colors = categoryData.map(item => {
                    switch(item.category) {
                        case 'smartphone': return 'rgba(13, 110, 253, 0.7)';
                        case 'laptop': return 'rgba(111, 66, 193, 0.7)';
                        case 'tablet': return 'rgba(253, 126, 20, 0.7)';
                        case 'desktop': return 'rgba(25, 135, 84, 0.7)';
                        case 'gaming': return 'rgba(220, 53, 69, 0.7)';
                        case 'tv': return 'rgba(13, 202, 240, 0.7)';
                        default: return 'rgba(108, 117, 125, 0.7)';
                    }
                });
                
                // Create the chart if we have data
                if (data.length > 0) {
                    new Chart(categoryChartElement, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: colors,
                                borderWidth: 1,
                                borderColor: 'rgba(255, 255, 255, 0.2)'
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
                                        padding: 15
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${label}: SAR ${value.toLocaleString()} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%'
                        }
                    });
                } else {
                    // If no data, display a message
                    categoryChartElement.innerHTML = '<div class="d-flex align-items-center justify-content-center h-100"><p class="text-muted">No category data available</p></div>';
                }
            }
            
            // Helper function to capitalize first letter
            function ucfirst(string) {
                return string.charAt(0).toUpperCase() + string.slice(1);
            }
            
            // Date range validation
            const startDateInput = document.getElementById('start_date');
            const endDateInput = document.getElementById('end_date');
            
            if (startDateInput && endDateInput) {
                endDateInput.addEventListener('change', function() {
                    if (startDateInput.value && this.value && new Date(this.value) < new Date(startDateInput.value)) {
                        alert('End date cannot be earlier than start date');
                        this.value = startDateInput.value;
                    }
                });
                
                startDateInput.addEventListener('change', function() {
                    if (endDateInput.value && this.value && new Date(this.value) > new Date(endDateInput.value)) {
                        endDateInput.value = this.value;
                    }
                });
            }
        });
    </script>
</body>
</html>