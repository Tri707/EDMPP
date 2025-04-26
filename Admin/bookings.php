<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Process AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // Process booking cancellation
        if ($_POST['action'] === 'cancel_booking') {
            $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
            $reason = isset($_POST['cancel_reason']) ? trim($_POST['cancel_reason']) : '';
            $notifyUser = isset($_POST['notify_user']) ? (bool)$_POST['notify_user'] : false;
            
            if (!$bookingId) {
                throw new Exception('Invalid booking ID');
            }
            
            if (empty($reason)) {
                throw new Exception('Cancellation reason is required');
            }
            
            // Check if booking exists and can be cancelled
            $stmt = $pdo->prepare("
                SELECT b.*, c.email as customer_email, c.first_name as customer_first_name
                FROM bookings b
                JOIN users c ON b.customer_id = c.id
                WHERE b.id = ? AND b.status NOT IN ('cancelled', 'completed')
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found or cannot be cancelled');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Update booking status to cancelled
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'cancelled', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$bookingId]);
                
                // Add cancellation note
                $timestamp = date('Y-m-d H:i:s');
                $noteEntry = "[{$timestamp}] Booking cancelled by admin. Reason: {$reason}";
                
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET notes = CONCAT(COALESCE(notes, ''), ?, ?)
                    WHERE id = ?
                ");
                $stmt->execute([PHP_EOL, $noteEntry, $bookingId]);
                
                // Commit transaction
                $pdo->commit();
                
                // Send notification email if requested
                if ($notifyUser && !empty($booking['customer_email'])) {
                    $to = $booking['customer_email'];
                    $subject = 'Your booking has been cancelled - FixItNow';
                    
                    $message = "
                    <html>
                    <head>
                        <title>Booking Cancellation</title>
                    </head>
                    <body>
                        <h2>Booking Cancellation Notice</h2>
                        <p>Dear {$booking['customer_first_name']},</p>
                        <p>We regret to inform you that your booking (#$bookingId) has been cancelled.</p>
                        <p><strong>Booking Details:</strong></p>
                        <ul>
                            <li>Date: " . date('F j, Y', strtotime($booking['booking_date'])) . "</li>
                            <li>Time: " . date('h:i A', strtotime($booking['booking_time'])) . "</li>
                        </ul>
                        <p><strong>Cancellation Reason:</strong> $reason</p>
                        <p>If you have any questions, please contact our support team.</p>
                        <p>Thank you for your understanding.</p>
                        <p>Regards,<br>FixItNow Team</p>
                    </body>
                    </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: noreply@fixitnow.com" . "\r\n";
                    
                    @mail($to, $subject, $message, $headers);
                }
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Booking cancelled successfully',
                    'booking_id' => $bookingId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        // Process booking confirmation
        elseif ($_POST['action'] === 'confirm_booking') {
            $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
            $notifyUser = isset($_POST['notify_user']) ? (bool)$_POST['notify_user'] : false;
            
            if (!$bookingId) {
                throw new Exception('Invalid booking ID');
            }
            
            // Check if booking exists and can be confirmed
            $stmt = $pdo->prepare("
                SELECT b.*, c.email as customer_email, c.first_name as customer_first_name
                FROM bookings b
                JOIN users c ON b.customer_id = c.id
                WHERE b.id = ? AND b.status = 'pending'
            ");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new Exception('Booking not found or cannot be confirmed');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Update booking status to confirmed
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET status = 'confirmed', updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$bookingId]);
                
                // Add confirmation note
                $timestamp = date('Y-m-d H:i:s');
                $noteEntry = "[{$timestamp}] Booking confirmed by admin.";
                
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET notes = CONCAT(COALESCE(notes, ''), ?, ?)
                    WHERE id = ?
                ");
                $stmt->execute([PHP_EOL, $noteEntry, $bookingId]);
                
                // Commit transaction
                $pdo->commit();
                
                // Send notification email if requested
                if ($notifyUser && !empty($booking['customer_email'])) {
                    $to = $booking['customer_email'];
                    $subject = 'Your booking has been confirmed - FixItNow';
                    
                    $message = "
                    <html>
                    <head>
                        <title>Booking Confirmation</title>
                    </head>
                    <body>
                        <h2>Booking Confirmation</h2>
                        <p>Dear {$booking['customer_first_name']},</p>
                        <p>Great news! Your booking (#$bookingId) has been confirmed.</p>
                        <p><strong>Booking Details:</strong></p>
                        <ul>
                            <li>Date: " . date('F j, Y', strtotime($booking['booking_date'])) . "</li>
                            <li>Time: " . date('h:i A', strtotime($booking['booking_time'])) . "</li>
                        </ul>
                        <p>If you have any questions, please contact our support team.</p>
                        <p>Thank you for choosing our service.</p>
                        <p>Regards,<br>FixItNow Team</p>
                    </body>
                    </html>
                    ";
                    
                    $headers = "MIME-Version: 1.0" . "\r\n";
                    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                    $headers .= "From: noreply@fixitnow.com" . "\r\n";
                    
                    @mail($to, $subject, $message, $headers);
                }
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Booking confirmed successfully',
                    'booking_id' => $bookingId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        else {
            throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        error_log("Booking action error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Get filter values
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$paymentFilter = isset($_GET['payment']) ? $_GET['payment'] : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build query
$query = "
    SELECT b.*,
           c.first_name as customer_first_name,
           c.last_name as customer_last_name,
           c.email as customer_email,
           c.phone as customer_phone,
           c.profile_image as customer_profile_image,
           t.first_name as technician_first_name,
           t.last_name as technician_last_name,
           t.email as technician_email,
           s.name as service_name
    FROM bookings b
    JOIN users c ON b.customer_id = c.id
    JOIN providers p ON b.provider_id = p.id
    JOIN users t ON p.user_id = t.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) FROM bookings b
    JOIN users c ON b.customer_id = c.id
    JOIN providers p ON b.provider_id = p.id
    JOIN users t ON p.user_id = t.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE 1=1
";

$queryParams = [];

// Add filters
if (!empty($statusFilter)) {
    $query .= " AND b.status = ?";
    $countQuery .= " AND b.status = ?";
    $queryParams[] = $statusFilter;
}

if (!empty($paymentFilter)) {
    $query .= " AND b.payment_status = ?";
    $countQuery .= " AND b.payment_status = ?";
    $queryParams[] = $paymentFilter;
}

if (!empty($searchQuery)) {
    $query .= " AND (
        c.first_name LIKE ? OR 
        c.last_name LIKE ? OR 
        c.email LIKE ? OR 
        c.phone LIKE ? OR
        t.first_name LIKE ? OR 
        t.last_name LIKE ? OR
        CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR
        CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR
        b.id LIKE ?
    )";
    $countQuery .= " AND (
        c.first_name LIKE ? OR 
        c.last_name LIKE ? OR 
        c.email LIKE ? OR 
        c.phone LIKE ? OR
        t.first_name LIKE ? OR 
        t.last_name LIKE ? OR
        CONCAT(c.first_name, ' ', c.last_name) LIKE ? OR
        CONCAT(t.first_name, ' ', t.last_name) LIKE ? OR
        b.id LIKE ?
    )";
    
    $searchParam = "%{$searchQuery}%";
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
}

if (!empty($dateFrom)) {
    $query .= " AND b.booking_date >= ?";
    $countQuery .= " AND b.booking_date >= ?";
    $queryParams[] = $dateFrom;
}

if (!empty($dateTo)) {
    $query .= " AND b.booking_date <= ?";
    $countQuery .= " AND b.booking_date <= ?";
    $queryParams[] = $dateTo;
}

// Add sorting and pagination
$query .= " ORDER BY b.booking_date DESC, b.booking_time DESC LIMIT {$perPage} OFFSET {$offset}";

// Get bookings
$bookings = [];
$totalBookings = 0;

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($queryParams);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($queryParams);
    $totalBookings = $countStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database error fetching bookings: " . $e->getMessage());
}

// Calculate pagination values
$totalPages = ceil($totalBookings / $perPage);
$prevPage = ($page > 1) ? $page - 1 : null;
$nextPage = ($page < $totalPages) ? $page + 1 : null;

// Build pagination URL
function buildPaginationUrl($page, $currentParams = []) {
    $params = $_GET;
    $params['page'] = $page;
    
    if (!empty($currentParams)) {
        $params = array_merge($params, $currentParams);
    }
    
    return '?' . http_build_query($params);
}

// Get booking statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'unpaid' => 0,
    'paid' => 0,
    'refunded' => 0
];

try {
    // Total bookings
    $stmt = $pdo->query("SELECT COUNT(*) FROM bookings");
    $stats['total'] = $stmt->fetchColumn();
    
    // Status counts
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count
        FROM bookings
        GROUP BY status
    ");
    $statusCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($statusCounts as $status => $count) {
        $stats[$status] = $count;
    }
    
    // Payment status counts
    $stmt = $pdo->query("
        SELECT payment_status, COUNT(*) as count
        FROM bookings
        GROUP BY payment_status
    ");
    $paymentCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach ($paymentCounts as $status => $count) {
        $stats[$status] = $count;
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching booking stats: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bookings - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Toastr for notifications -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
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
        
        /* Stat Cards */
        .stat-card {
            border-radius: 0.75rem;
            background-color: var(--card-bg);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border-left: 4px solid transparent;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .stat-card.primary {
            border-left-color: var(--primary-color);
        }
        
        .stat-card.success {
            border-left-color: var(--accent-color);
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        
        .stat-card.danger {
            border-left-color: #fa5252;
        }
        
        .stat-card.info {
            border-left-color: #0dcaf0;
        }
        
        .stat-card .stat-icon {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.5rem;
            opacity: 0.2;
        }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .stat-label {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
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
            color: #4cd963;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        .status-badge.unpaid {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.paid {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.refunded {
            background-color: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        /* Table Styles */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            vertical-align: middle;
            border-bottom-width: 1px;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .booking-row-cancelled {
            opacity: 0.6;
        }
        
        /* User profile in table */
        .user-profile {
            display: flex;
            align-items: center;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            line-height: 1.2;
        }
        
        .user-email {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }
        
        /* Filter card */
        .filter-card {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border-top: 4px solid var(--primary-color);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .filter-card .form-control,
        .filter-card .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
        }
        
        .pagination .page-link {
            border-radius: 0.5rem;
            margin: 0 0.2rem;
            border: none;
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination .page-link:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item.disabled .page-link {
            background-color: transparent;
            color: var(--text-muted);
        }
        
        /* Action buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            margin-right: 0.25rem;
        }
        
        .action-btn:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .action-btn.danger {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        .action-btn.danger:hover {
            background-color: var(--bs-danger);
            color: #fff;
        }
        
        .action-btn.success {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        .action-btn.success:hover {
            background-color: var(--bs-success);
            color: #fff;
        }
        
        /* Form controls */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
        }
        
        /* Price display */
        .price-display {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        .price-currency {
            height: 16px;
            margin-right: 0.25rem;
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner-container {
            background-color: var(--card-bg);
            padding: 2rem;
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            text-align: center;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="dashboard.php" class="text-decoration-none d-flex align-items-center">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-danger">Admin Panel</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn && isset($adminData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($adminData['first_name']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                        <a class="nav-link" href="users.php">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="technicians.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Technicians</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span class="nav-text">Settings</span>
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
            <h1 class="page-title">Bookings Management</h1>
            
            <!-- Stats Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Bookings</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['confirmed']; ?></div>
                        <div class="stat-label">Confirmed</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['completed']; ?></div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-card">
                <form method="get" action="bookings.php" id="filter-form">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="mb-0">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="status" class="form-label">Booking Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="payment" class="form-label">Payment Status</label>
                                <select class="form-select" id="payment" name="payment">
                                    <option value="">All Payments</option>
                                    <option value="unpaid" <?php echo $paymentFilter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                    <option value="paid" <?php echo $paymentFilter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="refunded" <?php echo $paymentFilter === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="date_from" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="date_to" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                                <button type="button" id="reset-filter" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Bookings Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Bookings List</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Technician</th>
                                    <th>Date & Time</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Price</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bookings)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No bookings found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($bookings as $booking): ?>
                                <tr id="booking-row-<?php echo $booking['id']; ?>" class="<?php echo $booking['status'] === 'cancelled' ? 'booking-row-cancelled' : ''; ?>">
                                    <td>#<?php echo $booking['id']; ?></td>
                                    <td>
                                        <div class="user-profile">
                                            <?php 
                                            $customerImage = '../default.png';
                                            if (!empty($booking['customer_profile_image'])) {
                                                if (preg_match('/^https?:\/\//', $booking['customer_profile_image'])) {
                                                    $customerImage = $booking['customer_profile_image'];
                                                } else {
                                                    $imagePath = '../profile_images/' . basename($booking['customer_profile_image']);
                                                    if (file_exists($imagePath)) {
                                                        $customerImage = $imagePath;
                                                    }
                                                }
                                            }
                                            ?>
                                            <div class="user-avatar">
                                                <img src="<?php echo $customerImage; ?>" alt="Customer">
                                            </div>
                                            <div class="user-info">
                                                <div class="user-name"><?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?></div>
                                                <div class="user-email"><?php echo htmlspecialchars($booking['customer_email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-name"><?php echo htmlspecialchars($booking['technician_first_name'] . ' ' . $booking['technician_last_name']); ?></div>
                                            <div class="user-email"><?php echo htmlspecialchars($booking['technician_email']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <span><?php echo date('M j, Y', strtotime($booking['booking_date'])); ?></span>
                                            <small class="text-muted"><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $booking['status']; ?>" id="status-badge-<?php echo $booking['id']; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo $booking['payment_status']; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="price-display">
                                            <img src="sar/sar.png" alt="SAR" class="price-currency">
                                            <?php echo number_format($booking['total_price'], 2); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <a href="booking-detail.php?id=<?php echo $booking['id']; ?>" class="action-btn" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            
                                            <?php if ($booking['status'] === 'pending'): ?>
                                            <a href="#" class="action-btn success confirm-booking-btn" data-booking-id="<?php echo $booking['id']; ?>" title="Confirm Booking">
                                                <i class="fas fa-check"></i>
                                            </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['status'] !== 'cancelled' && $booking['status'] !== 'completed'): ?>
                                            <a href="#" class="action-btn danger cancel-booking-btn" data-booking-id="<?php echo $booking['id']; ?>" title="Cancel Booking">
                                                <i class="fas fa-times"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination Footer -->
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        Showing <?php echo min(($page - 1) * $perPage + 1, $totalBookings); ?> to <?php echo min($page * $perPage, $totalBookings); ?> of <?php echo $totalBookings; ?> bookings
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <?php if ($prevPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($prevPage); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;</span>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($nextPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($nextPage); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancel Booking Modal -->
    <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelBookingModalLabel">Cancel Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="cancelBookingForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="cancel_booking">
                        <input type="hidden" name="booking_id" id="cancelBookingId">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Are you sure you want to cancel this booking? This action cannot be undone.
                        </div>
                        
                        <div class="mb-3">
                            <label for="cancel_reason" class="form-label">Cancellation Reason</label>
                            <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" required></textarea>
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="notify_user" name="notify_user" value="1" checked>
                            <label class="form-check-label" for="notify_user">
                                Notify customer via email
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">Cancel Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Confirm Booking Modal -->
    <div class="modal fade" id="confirmBookingModal" tabindex="-1" aria-labelledby="confirmBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="confirmBookingModalLabel">Confirm Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="confirmBookingForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="confirm_booking">
                        <input type="hidden" name="booking_id" id="confirmBookingId">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Are you sure you want to confirm this booking?
                        </div>
                        
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="notify_user_confirm" name="notify_user" value="1" checked>
                            <label class="form-check-label" for="notify_user_confirm">
                                Notify customer via email
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-success">Confirm Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Loading Spinner -->
    <div id="loading-spinner" class="spinner-overlay" style="display: none;">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div>Processing your request...</div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Toastr JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Toastr configuration
            toastr.options = {
                closeButton: true,
                progressBar: true,
                positionClass: "toast-top-right",
                timeOut: 5000
            };
            
            // Reset filter button
            document.getElementById('reset-filter').addEventListener('click', function() {
                window.location.href = 'bookings.php';
            });
            
            // Cancel Booking Modal Setup
            const cancelButtons = document.querySelectorAll('.cancel-booking-btn');
            const cancelModal = new bootstrap.Modal(document.getElementById('cancelBookingModal'));
            
            cancelButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const bookingId = this.getAttribute('data-booking-id');
                    document.getElementById('cancelBookingId').value = bookingId;
                    cancelModal.show();
                });
            });
            
            // Confirm Booking Modal Setup
            const confirmButtons = document.querySelectorAll('.confirm-booking-btn');
            const confirmModal = new bootstrap.Modal(document.getElementById('confirmBookingModal'));
            
            confirmButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    const bookingId = this.getAttribute('data-booking-id');
                    document.getElementById('confirmBookingId').value = bookingId;
                    confirmModal.show();
                });
            });
            
            // Handle cancel booking form submission
            const cancelForm = document.getElementById('cancelBookingForm');
            cancelForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validate form
                const reason = document.getElementById('cancel_reason').value.trim();
                if (!reason) {
                    toastr.error('Please provide a cancellation reason');
                    return;
                }
                
                // Get form data
                const formData = new FormData(this);
                const bookingId = formData.get('booking_id');
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Disable form submit button to prevent multiple submissions
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                const originalBtnText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
                
                // Send AJAX request
                fetch('bookings.php', {
                    method: 'POST',
                    body: formData,
                    cache: 'no-cache',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    
                    if (data.status === 'success') {
                        // Update UI
                        const bookingRow = document.getElementById('booking-row-' + bookingId);
                        const statusBadge = document.getElementById('status-badge-' + bookingId);
                        
                        // Add cancelled class to row
                        bookingRow.classList.add('booking-row-cancelled');
                        
                        // Update status badge
                        statusBadge.className = 'status-badge cancelled';
                        statusBadge.textContent = 'Cancelled';
                        
                        // Remove action buttons
                        const actionButtons = bookingRow.querySelectorAll('.action-btn.danger, .action-btn.success');
                        actionButtons.forEach(btn => btn.remove());
                        
                        // Show success message
                        toastr.success('Booking has been cancelled successfully');
                        
                        // Hide modal
                        cancelModal.hide();
                        
                        // Reset form
                        cancelForm.reset();
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while cancelling the booking');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server. Please try again.');
                    console.error('Error:', error);
                });
            });
            
            // Handle confirm booking form submission
            const confirmForm = document.getElementById('confirmBookingForm');
            confirmForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Get form data
                const formData = new FormData(this);
                const bookingId = formData.get('booking_id');
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Disable form submit button to prevent multiple submissions
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;
                const originalBtnText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Processing...';
                
                // Send AJAX request
                fetch('bookings.php', {
                    method: 'POST',
                    body: formData,
                    cache: 'no-cache',
                    credentials: 'same-origin'
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    
                    if (data.status === 'success') {
                        // Update UI
                        const bookingRow = document.getElementById('booking-row-' + bookingId);
                        const statusBadge = document.getElementById('status-badge-' + bookingId);
                        
                        // Update status badge
                        statusBadge.className = 'status-badge confirmed';
                        statusBadge.textContent = 'Confirmed';
                        
                        // Remove confirm button (but keep cancel button)
                        const confirmBtn = bookingRow.querySelector('.confirm-booking-btn');
                        if (confirmBtn) {
                            confirmBtn.remove();
                        }
                        
                        // Show success message
                        toastr.success('Booking has been confirmed successfully');
                        
                        // Hide modal
                        confirmModal.hide();
                        
                        // Reset form
                        confirmForm.reset();
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while confirming the booking');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server. Please try again.');
                    console.error('Error:', error);
                });
            });
        });
    </script>
</body>
</html>