<?php
    // Start session securely
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();

    // Authentication check
    $loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    // Redirect if not customer
    if (!$loggedIn || $userRole !== 'customer' || $userId <= 0) {
        header('Location: ../login.php?redirect=customer');
        exit;
    }

    // Database connection using MySQLi
    include 'conn.php';

    // Set proper character set
    $conn->set_charset("utf8mb4");

    // Initialize variables
    $userData = [];
    $userProfileImage = '../default.png';
    $notifications = [];
    $notificationCount = 0; 
    $userInitials = 'CN'; // Default initials
    $totalNotifications = 0;
    $currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $perPage = 10;
    $offset = ($currentPage - 1) * $perPage;
    $errorMessage = '';
    $successMessage = '';

    // Process mark as read action
    if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
        $notificationId = (int)$_GET['mark_read'];
        
        try {
            // Verify notification belongs to this user first
            $checkStmt = $conn->prepare("
                SELECT id FROM quote_notifications 
                WHERE id = ? AND recipient_id = ?
            ");
            $checkStmt->bind_param("ii", $notificationId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                // Update notification to read
                $updateStmt = $conn->prepare("
                    UPDATE quote_notifications 
                    SET is_read = 1 
                    WHERE id = ?
                ");
                $updateStmt->bind_param("i", $notificationId);
                $updateStmt->execute();
                $updateStmt->close();
                
                $successMessage = "Notification marked as read";
            }
            $checkStmt->close();
        } catch (Exception $e) {
            error_log("Error marking notification as read: " . $e->getMessage());
            $errorMessage = "An error occurred while updating notifications";
        }
    }

    // Process mark all as read action
    if (isset($_GET['mark_all_read'])) {
        try {
            $markAllStmt = $conn->prepare("
                UPDATE quote_notifications 
                SET is_read = 1 
                WHERE recipient_id = ? AND is_read = 0
            ");
            $markAllStmt->bind_param("i", $userId);
            $markAllStmt->execute();
            
            if ($markAllStmt->affected_rows > 0) {
                $successMessage = "All notifications marked as read";
            }
            $markAllStmt->close();
        } catch (Exception $e) {
            error_log("Error marking all notifications as read: " . $e->getMessage());
            $errorMessage = "An error occurred while updating notifications";
        }
    }

    // Check for flash messages from previous redirects
    if (isset($_SESSION['error_message'])) {
        $errorMessage = $_SESSION['error_message'];
        unset($_SESSION['error_message']);
    }

    if (isset($_SESSION['success_message'])) {
        $successMessage = $_SESSION['success_message'];
        unset($_SESSION['success_message']);
    }

    try {
        // Query to get customer user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer'");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        $stmt->close();
        
        if (!$userData) {
            // If customer data doesn't exist, log them out
            session_unset();
            session_destroy();
            header('Location: ../login.php?error=invalid_session');
            exit;
        }
        
        // Set profile image path with proper validation
        if (!empty($userData['profile_image'])) {
            if (filter_var($userData['profile_image'], FILTER_VALIDATE_URL)) {
                // URL-based image with validation
                $userProfileImage = $userData['profile_image'];
            } else {
                // File-based image with protection against directory traversal
                $imageFile = basename($userData['profile_image']);
                $imagePath = '../profile_images/' . $imageFile;
                if (file_exists($imagePath) && is_file($imagePath)) {
                    $userProfileImage = $imagePath;
                }
            }
        }
        
        // Get user initials for avatar (matching the bookings.php approach)
        if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
            $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
        } elseif (!empty($userData['first_name'])) {
            $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
        } elseif (!empty($userData['last_name'])) {
            $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
        }

        // Get total count of notifications
        $countStmt = $conn->prepare("
            SELECT COUNT(*) as total 
            FROM quote_notifications 
            WHERE recipient_id = ?
        ");
        $countStmt->bind_param("i", $userId);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $totalRow = $countResult->fetch_assoc();
        $totalNotifications = $totalRow['total'];
        $countStmt->close();
        
        // Calculate pagination
        $totalPages = ceil($totalNotifications / $perPage);
        $currentPage = min($currentPage, max(1, $totalPages));
        
        // Get notifications for the current page
        $notifQuery = "
            SELECT n.*, 
                   q.device_type, 
                   q.issue_description,
                   (SELECT COUNT(*) FROM quotes WHERE quotes.request_id = n.request_id) as quote_count
            FROM quote_notifications n
            LEFT JOIN quote_requests q ON n.request_id = q.id
            WHERE n.recipient_id = ?
            ORDER BY n.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $notifStmt = $conn->prepare($notifQuery);
        $notifStmt->bind_param("iii", $userId, $perPage, $offset);
        $notifStmt->execute();
        $notifResult = $notifStmt->get_result();
        $notifications = [];
        
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notifStmt->close();
        
        // Get unread notification count
        $unreadQuery = "
            SELECT COUNT(*) as unread_count 
            FROM quote_notifications
            WHERE recipient_id = ? AND is_read = 0
        ";
        
        $unreadStmt = $conn->prepare($unreadQuery);
        $unreadStmt->bind_param("i", $userId);
        $unreadStmt->execute();
        $unreadResult = $unreadStmt->get_result();
        $unreadRow = $unreadResult->fetch_assoc();
        $notificationCount = $unreadRow['unread_count'];
        $unreadStmt->close();
        
        // Get recent notifications for dropdown (including read ones)
        $recentNotifQuery = "
            SELECT * FROM quote_notifications 
            WHERE recipient_id = ? 
            ORDER BY created_at DESC 
            LIMIT 3
        ";
        
        $recentNotifStmt = $conn->prepare($recentNotifQuery);
        $recentNotifStmt->bind_param("i", $userId);
        $recentNotifStmt->execute();
        $recentNotifResult = $recentNotifStmt->get_result();
        $recentNotifications = [];
        
        while ($row = $recentNotifResult->fetch_assoc()) {
            $recentNotifications[] = $row;
        }
        $recentNotifStmt->close();
        
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        $errorMessage = "An error occurred while fetching notifications. Please try again later.";
    }

    // Function to get readable time from timestamp
    function timeAgo($timestamp) {
        if (empty($timestamp)) return 'N/A';
        
        try {
            $time = new DateTime($timestamp);
            $now = new DateTime();
            $diff = $now->getTimestamp() - $time->getTimestamp();
            
            if ($diff < 60) {
                return 'Just now';
            } elseif ($diff < 3600) {
                $mins = floor($diff / 60);
                return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 86400) {
                $hours = floor($diff / 3600);
                return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
            } elseif ($diff < 604800) {
                $days = floor($diff / 86400);
                return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
            } else {
                return formatDate($timestamp);
            }
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }

    // Function to safely format dates
    function formatDate($dateString, $format = 'M d, Y') {
        if (empty($dateString)) return 'N/A';
        
        try {
            $date = new DateTime($dateString);
            return $date->format($format);
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }

    // Get notification type icon and class
    function getNotificationTypeInfo($type) {
        switch ($type) {
            case 'new_quote':
                return ['icon' => 'fa-check-circle', 'class' => 'success'];
            case 'quote_accepted':
                return ['icon' => 'fa-thumbs-up', 'class' => 'primary'];
            case 'quote_rejected':
                return ['icon' => 'fa-thumbs-down', 'class' => 'danger'];
            case 'repair_completed':
                return ['icon' => 'fa-tools', 'class' => 'success'];
            case 'new_request':
                return ['icon' => 'fa-clipboard-list', 'class' => 'warning'];
            default:
                return ['icon' => 'fa-bell', 'class' => 'primary'];
        }
    }
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Customer notifications for FixItNow service booking platform">
    <meta name="robots" content="noindex, nofollow">
    <title>Notifications - FixItNow</title>
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
            --bg-color: #f8f9fa;           /* Light background */
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
            --danger-color: #dc3545;       /* Danger/red color */
            --danger-light: #f8d7da;       /* Light danger background */
            --warning-color: #ffc107;      /* Warning/yellow color */
            --success-color: #28a745;      /* Success/green color */
        }
        
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #f8f9fa;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #212529;           /* Slightly less dark background */
            --card-bg: #2c3034;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #2c3034;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --danger-light: #482930;       /* Darker danger background for dark mode */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
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
        
        /* Enhanced Header Styles (from bookings.php) */
        .site-header {
            background-color: #212529;
            padding: 0.75rem 0;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: #fff;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .logo-text:hover {
            transform: scale(1.02);
            color: #fff;
        }
        
        .logo-text .highlight {
            color: #4cd963;
            font-weight: 900;
        }
        
        /* Main Navigation Styles */
        .main-nav {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .nav-button {
            display: flex;
            align-items: center;
            padding: 0.6rem 1.1rem;
            border-radius: 0.375rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }
        
        .nav-button.active {
            background-color: #7952b3;
            color: white;
            box-shadow: 0 2px 8px rgba(121, 82, 179, 0.4);
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Header Icon Buttons */
        .header-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .header-icon-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }
        
        .header-icon-btn:active {
            transform: translateY(0);
        }
        
        /* Notification Badge */
        .notification-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(220, 53, 69, 0.4);
        }
        
        /* Notification Dropdown */
        .notification-dropdown {
            width: 320px;
            padding: 0.5rem 0;
            max-height: 400px;
            overflow-y: auto;
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
        }
        
        .notification-item {
            display: flex;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .notification-icon.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .notification-icon.primary {
            background-color: rgba(121, 82, 179, 0.1);
            color: #7952b3;
        }
        
        .notification-icon.warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .notification-icon.danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .notification-text {
            font-size: 0.825rem;
            color: #6c757d;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #adb5bd;
            margin-top: 0.25rem;
        }
        
        .view-all {
            font-weight: 500;
            padding: 0.5rem;
            color: #7952b3;
        }
        
        .view-all:hover {
            background-color: rgba(121, 82, 179, 0.05);
            color: #6941a0;
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }
        
        .user-dropdown-toggle {
            display: flex;
            align-items: center;
            padding: 0.4rem 0.6rem;
            border-radius: 0.375rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.95);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .user-dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .user-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            background-color: #7952b3;
            color: white;
            border-radius: 50%;
            font-size: 0.85rem;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(121, 82, 179, 0.3);
        }
        
        .dropdown-menu {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
            padding: 0.5rem 0;
            min-width: 220px;
        }
        
        .dropdown-item {
            padding: 0.65rem 1.25rem;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: rgba(121, 82, 179, 0.05);
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            color: #6c757d;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mobile-menu-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        
        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 71px; /* Height of header */
            left: 0;
            width: 100%;
            background-color: #212529;
            z-index: 999;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            overflow-y: auto;
            max-height: calc(100vh - 71px);
        }
        
        .mobile-menu.show {
            display: block;
        }
        
        .mobile-menu-inner {
            padding: 1rem 0;
        }
        
        .mobile-menu-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        
        .mobile-menu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-left-color: rgba(255, 255, 255, 0.2);
        }
        
        .mobile-menu-item.active {
            background-color: rgba(121, 82, 179, 0.1);
            color: #fff;
            border-left-color: #7952b3;
        }
        
        .mobile-menu-item i {
            width: 24px;
            margin-right: 0.75rem;
            text-align: center;
        }
        
        .mobile-menu-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 0.5rem 1.5rem;
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
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
        
        /* Notifications List */
        .notifications-list .notification-item {
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s ease;
        }
        
        .notifications-list .notification-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .notifications-list .notification-item.unread {
            background-color: rgba(var(--bs-primary-rgb), 0.08);
        }
        
        .notification-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .notification-actions .btn {
            padding: 0.3rem 0.6rem;
            font-size: 0.85rem;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 1rem;
        }
        
        .empty-state-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 300px;
            margin: 0 auto 1rem;
            font-size: 0.875rem;
        }
        
        /* Pagination Custom Styles */
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            border: none;
            margin: 0 0.15rem;
            border-radius: 0.375rem;
            color: var(--primary-color);
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .page-link:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-color: transparent;
            color: var(--primary-hover);
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 5px rgba(var(--bs-primary-rgb), 0.3);
        }
        
        .page-item.disabled .page-link {
            opacity: 0.5;
        }
        
        /* Footer */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 1.5rem 0;
            margin-top: auto;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 1.5rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: #fff;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .notification-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.25rem;
            }
            
            .notification-actions .btn {
                width: 100%;
            }
        }
        
        /* Modal for notification details */
        .notification-modal .modal-header {
            background-color: var(--primary-light);
            border-bottom: none;
        }
        
        .notification-modal .notification-icon-lg {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
        }
        
        .notification-modal .notification-title-lg {
            font-size: 1.25rem;
            font-weight: 600;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .notification-modal .notification-meta {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .notification-modal .meta-item {
            display: flex;
            align-items: center;
        }
        
        .notification-modal .meta-item i {
            margin-right: 0.5rem;
            opacity: 0.7;
        }
    </style>
</head>
<body>
    <!-- Improved Header (from bookings.php) -->
    <header class="site-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="logo-text">
                    <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="main-nav d-none d-lg-flex">
                    <a href="../index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="../find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="../services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="../how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="mobile-menu-toggle d-lg-none me-3" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="header-icon-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></div>
                            <i class="fas fa-chevron-down fa-xs ms-2"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="bookings.php"><i class="fas fa-calendar-check me-2"></i> My Bookings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="mobile-menu d-lg-none" id="mobileMenu">
            <div class="mobile-menu-inner">
                <a href="../index.php" class="mobile-menu-item">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../find-technician.php" class="mobile-menu-item">
                    <i class="fas fa-search"></i> Find Technician
                </a>
                <a href="../services.php" class="mobile-menu-item">
                    <i class="fas fa-cogs"></i> Services
                </a>
                <a href="../how-it-works.php" class="mobile-menu-item">
                    <i class="fas fa-info-circle"></i> How It Works
                </a>
                <div class="mobile-menu-divider"></div>
                <a href="dashboard.php" class="mobile-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="bookings.php" class="mobile-menu-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="notifications.php" class="mobile-menu-item active">
                    <i class="fas fa-bell"></i> Notifications
                </a>
                <a href="profile.php" class="mobile-menu-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
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
                            <span class="nav-icon"><i class="fas fa-tachometer-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
                            <span class="nav-text">My Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star" aria-hidden="true"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
                            <span class="nav-text">My Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <div class="mb-4">
                <h1 class="page-title">Notifications</h1>
            </div>
            
            <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">All Notifications</h5>
                    <?php if ($notificationCount > 0): ?>
                    <a href="?mark_all_read=1" class="btn btn-sm btn-primary">
                        <i class="fas fa-check-double me-1" aria-hidden="true"></i>Mark All as Read
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-bell-slash" aria-hidden="true"></i>
                            </div>
                            <h3 class="empty-state-title">No Notifications</h3>
                            <p class="empty-state-text">
                                You don't have any notifications at the moment.
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <?php $typeInfo = getNotificationTypeInfo($notification['type']); ?>
                                <div class="notification-item d-md-flex align-items-md-center <?php echo $notification['is_read'] ? '' : 'unread'; ?> p-3">
                                    <div class="d-flex align-items-start flex-grow-1 mb-3 mb-md-0">
                                        <div class="notification-icon <?php echo $typeInfo['class']; ?> me-3">
                                            <i class="fas <?php echo $typeInfo['icon']; ?>" aria-hidden="true"></i>
                                        </div>
                                        <div class="notification-content">
                                            <div class="notification-title">
                                                <?php echo htmlspecialchars($notification['message'] ?? 'Notification', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="notification-time">
                                                <?php echo timeAgo($notification['created_at'] ?? ''); ?>
                                            </div>
                                            <?php if ($notification['quote_count'] > 0 && $notification['type'] === 'new_request'): ?>
                                            <div class="mt-2">
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle me-1" aria-hidden="true"></i>
                                                    <?php echo $notification['quote_count']; ?> quote<?php echo $notification['quote_count'] > 1 ? 's' : ''; ?> received
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="notification-actions ms-auto">
                                        <?php if ($notification['request_id']): ?>
                                        <a href="quote-details.php?id=<?php echo (int)$notification['request_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1" aria-hidden="true"></i>View Details
                                        </a>
                                        <?php endif; ?>
                                        <?php if (!$notification['is_read']): ?>
                                        <a href="?mark_read=<?php echo (int)$notification['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-check me-1" aria-hidden="true"></i>Mark as Read
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="card-footer">
                            <nav aria-label="Page navigation">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $currentPage - 1; ?>" aria-label="Previous">
                                            <span aria-hidden="true"><i class="fas fa-chevron-left fa-xs" aria-hidden="true"></i></span>
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <li class="page-item <?php echo $currentPage == $i ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $currentPage + 1; ?>" aria-label="Next">
                                            <span aria-hidden="true"><i class="fas fa-chevron-right fa-xs" aria-hidden="true"></i></span>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">© 2023 FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <ul class="footer-links d-flex flex-wrap justify-content-md-end">
                        <li><a href="../about.php">About</a></li>
                        <li><a href="../contact.php">Contact</a></li>
                        <li><a href="../privacy.php">Privacy Policy</a></li>
                        <li><a href="../terms.php">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('show');
                    const isOpen = mobileMenu.classList.contains('show');
                    mobileMenuToggle.innerHTML = isOpen ? 
                        '<i class="fas fa-times"></i>' : 
                        '<i class="fas fa-bars"></i>';
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenu.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        if (mobileMenu.classList.contains('show')) {
                            mobileMenu.classList.remove('show');
                            mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                        }
                    }
                });
            }
            
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
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                setTheme(savedTheme === 'dark');
            } else {
                // Default to light theme for customer side
                setTheme(false);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>