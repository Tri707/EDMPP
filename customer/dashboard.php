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
    $upcomingBookings = [];
    $recentBookings = [];
    $notifications = [];
    $notificationCount = 0;
    $dashboardStats = [
        'total_bookings' => 0,
        'pending_bookings' => 0,
        'confirmed_bookings' => 0,
        'completed_bookings' => 0,
        'cancelled_bookings' => 0
    ];
    $errorMessage = '';
    $successMessage = '';
    $userInitials = 'CN'; // Default initials

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
        // Query to get customer user data with proper validation
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
        
        // Get dashboard statistics
        $statsQuery = "
            SELECT 
                COUNT(*) as total_bookings,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
                SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings
            FROM bookings
            WHERE customer_id = ?
        ";
        
        $statsStmt = $conn->prepare($statsQuery);
        $statsStmt->bind_param("i", $userId);
        $statsStmt->execute();
        $statsResult = $statsStmt->get_result();
        $stats = $statsResult->fetch_assoc();
        $statsStmt->close();
        
        if ($stats) {
            $dashboardStats = [
                'total_bookings' => (int)$stats['total_bookings'],
                'pending_bookings' => (int)$stats['pending_bookings'],
                'confirmed_bookings' => (int)$stats['confirmed_bookings'],
                'completed_bookings' => (int)$stats['completed_bookings'],
                'cancelled_bookings' => (int)$stats['cancelled_bookings']
            ];
        }
        
        // Get upcoming bookings (confirmed and pending, ordered by date)
        $upcomingQuery = "
            SELECT b.*, 
                p.id as provider_id,
                u.first_name as provider_first_name, 
                u.last_name as provider_last_name,
                u.phone as provider_phone,
                s.name as service_name,
                s.price as service_price
            FROM bookings b
            LEFT JOIN providers p ON b.provider_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.customer_id = ? 
            AND b.status IN ('pending', 'confirmed')
            AND b.booking_date >= CURDATE()
            ORDER BY b.booking_date ASC, b.booking_time ASC
            LIMIT 5
        ";
        
        $upcomingStmt = $conn->prepare($upcomingQuery);
        $upcomingStmt->bind_param("i", $userId);
        $upcomingStmt->execute();
        $upcomingResult = $upcomingStmt->get_result();
        $upcomingBookings = [];
        
        while ($row = $upcomingResult->fetch_assoc()) {
            $upcomingBookings[] = $row;
        }
        $upcomingStmt->close();
        
        // Get recent bookings (all statuses, ordered by date desc)
        $recentQuery = "
            SELECT b.*, 
                p.id as provider_id,
                u.first_name as provider_first_name, 
                u.last_name as provider_last_name,
                s.name as service_name,
                s.price as service_price
            FROM bookings b
            LEFT JOIN providers p ON b.provider_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.customer_id = ? 
            ORDER BY b.created_at DESC
            LIMIT 5
        ";
        
        $recentStmt = $conn->prepare($recentQuery);
        $recentStmt->bind_param("i", $userId);
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        $recentBookings = [];
        
        while ($row = $recentResult->fetch_assoc()) {
            $recentBookings[] = $row;
        }
        $recentStmt->close();
        
        // Get unread notifications for the customer and count
        $notifQuery = "
            SELECT * FROM quote_notifications
            WHERE recipient_id = ?
            AND is_read = 0
            ORDER BY created_at DESC
            LIMIT 5
        ";
        
        $notifStmt = $conn->prepare($notifQuery);
        $notifStmt->bind_param("i", $userId);
        $notifStmt->execute();
        $notifResult = $notifStmt->get_result();
        $notifications = [];
        
        while ($row = $notifResult->fetch_assoc()) {
            $notifications[] = $row;
        }
        $notificationCount = count($notifications);
        $notifStmt->close();
        
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
        $errorMessage = "An error occurred while fetching your data. Please try again later.";
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

    // Function to safely format times
    function formatTime($timeString, $format = 'h:i A') {
        if (empty($timeString)) return 'N/A';
        
        try {
            $date = new DateTime($timeString);
            return $date->format($format);
        } catch (Exception $e) {
            return 'Invalid Time';
        }
    }

    // Function to safely format prices with the currency image
    function formatPrice($price, $currencyImgPath = '../sar/sar.png') {
        if (empty($price) || !is_numeric($price)) return 'Not set';
        
        // Use the image path for currency display
        $currencyImg = '<img src="' . htmlspecialchars($currencyImgPath, ENT_QUOTES, 'UTF-8') . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
        
        return $currencyImg . ' ' . number_format((float)$price, 2);
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

    // Close database connection when done
    // $conn->close(); // Uncomment this line if you want to close the connection at the end of the file
    ?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <meta name="description" content="Customer dashboard for FixItNow service booking platform">
        <meta name="robots" content="noindex, nofollow">
        <title>Customer Dashboard - FixItNow</title>
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
            
            /* Stats Cards */
            .stat-card {
                display: flex;
                align-items: center;
                padding: 1.5rem;
            }
            
            .stat-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin-right: 1.25rem;
                font-size: 1.5rem;
            }
            
            .stat-icon.primary {
                background-color: rgba(var(--bs-primary-rgb), 0.1);
                color: var(--primary-color);
            }
            
            .stat-icon.success {
                background-color: rgba(var(--bs-success-rgb), 0.1);
                color: var(--success-color);
            }
            
            .stat-icon.warning {
                background-color: rgba(var(--bs-warning-rgb), 0.1);
                color: var(--warning-color);
            }
            
            .stat-icon.danger {
                background-color: rgba(var(--bs-danger-rgb), 0.1);
                color: var(--danger-color);
            }
            
            .stat-details {
                flex: 1;
            }
            
            .stat-value {
                font-size: 1.75rem;
                font-weight: 700;
                line-height: 1.2;
                margin-bottom: 0.25rem;
            }
            
            .stat-label {
                color: var(--text-muted);
                font-size: 0.875rem;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            /* User profile card */
            .user-profile-card {
                display: flex;
                align-items: center;
                padding: 1.5rem;
            }
            
            .user-profile-image {
                width: 100px;
                height: 100px;
                border-radius: 50%;
                overflow: hidden;
                margin-right: 1.5rem;
                border: 3px solid var(--primary-color);
            }
            
            .user-profile-image img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }
            
            .user-profile-details {
                flex: 1;
            }
            
            .user-name {
                font-size: 1.5rem;
                font-weight: 700;
                margin-bottom: 0.25rem;
            }
            
            .user-email {
                color: var(--text-muted);
                margin-bottom: 1rem;
            }
            
            .user-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .meta-item {
                display: flex;
                align-items: center;
            }
            
            .meta-icon {
                margin-right: 0.5rem;
                color: var(--primary-color);
            }
            
            /* Booking items */
            .booking-item {
                display: flex;
                padding: 1.25rem;
                border-bottom: 1px solid var(--border-color);
                transition: background-color 0.3s ease;
            }
            
            .booking-item:last-child {
                border-bottom: none;
            }
            
            .booking-item:hover {
                background-color: rgba(var(--bs-primary-rgb), 0.05);
            }
            
            .booking-date {
                min-width: 60px;
                text-align: center;
                margin-right: 1rem;
            }
            
            .booking-date .day {
                font-size: 1.5rem;
                font-weight: 700;
                line-height: 1;
            }
            
            .booking-date .month {
                font-size: 0.75rem;
                text-transform: uppercase;
                color: var(--text-muted);
            }
            
            .booking-content {
                flex: 1;
            }
            
            .booking-title {
                font-weight: 600;
                margin-bottom: 0.25rem;
            }
            
            .booking-meta {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                margin-bottom: 0.5rem;
                font-size: 0.875rem;
            }
            
            .booking-meta-item {
                display: flex;
                align-items: center;
            }
            
            .booking-meta-icon {
                margin-right: 0.25rem;
                opacity: 0.7;
            }
            
            /* Status badges */
            .status-badge {
                display: inline-flex;
                align-items: center;
                padding: 0.35rem 0.75rem;
                border-radius: 2rem;
                font-size: 0.75rem;
                font-weight: 600;
                text-transform: capitalize;
            }
            
            .status-badge.pending {
                background-color: rgba(var(--bs-warning-rgb), 0.1);
                color: var(--bs-warning);
            }
            
            .status-badge.confirmed {
                background-color: rgba(var(--bs-primary-rgb), 0.1);
                color: var(--primary-color);
            }
            
            .status-badge.completed {
                background-color: rgba(var(--bs-success-rgb), 0.1);
                color: var(--bs-success);
            }
            
            .status-badge.cancelled {
                background-color: rgba(var(--bs-danger-rgb), 0.1);
                color: var(--bs-danger);
            }
            
            /* Notification item - already styled above in the new header section */
            
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
            
            /* Accessibility improvements */
            .sr-only {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border-width: 0;
            }
            
            /* Focus visible styles for keyboard navigation */
            .action-btn:focus-visible,
            .header-icon-btn:focus-visible,
            .btn:focus-visible,
            .nav-link:focus-visible {
                outline: 2px solid var(--primary-color);
                outline-offset: 2px;
            }
            
            /* Currency icon styling */
            .currency-icon {
                display: inline-block;
                vertical-align: middle;
            }
            
            /* Responsive adjustments */
            @media (max-width: 992px) {
                .dashboard-grid {
                    grid-template-columns: 1fr;
                }
                
                .nav-button {
                    padding: 0.5rem 0.75rem;
                    font-size: 0.85rem;
                }
                
                .nav-button i {
                    margin-right: 0.3rem;
                }
            }
            
            @media (max-width: 576px) {
                .stat-card {
                    flex-direction: column;
                    text-align: center;
                }
                
                .stat-icon {
                    margin-right: 0;
                    margin-bottom: 1rem;
                }
                
                .user-profile-card {
                    flex-direction: column;
                    text-align: center;
                }
                
                .user-profile-image {
                    margin-right: 0;
                    margin-bottom: 1rem;
                }
                
                .user-meta {
                    justify-content: center;
                }
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
                    <a href="dashboard.php" class="mobile-menu-item active">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                    <a href="bookings.php" class="mobile-menu-item">
                        <i class="fas fa-calendar-check"></i> My Bookings
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
                <a class="nav-link active" href="dashboard.php">
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
                        <a class="nav-link " href="notifications.php">
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
                    <h1 class="page-title">Dashboard</h1>
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
                
                <!-- Welcome Card -->
                <div class="card mb-4">
                    <div class="user-profile-card">
                        <div class="user-profile-image">
                            <img src="<?php echo htmlspecialchars($userProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($userData['first_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="user-profile-details">
                        <h2 class="user-name">
                            Welcome back, <?php echo htmlspecialchars(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>!
                        </h2>
                            <div class="user-email mb-3">
                                <i class="fas fa-envelope me-2" aria-hidden="true"></i><?php echo htmlspecialchars($userData['email'] ?? 'No email provided', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="user-meta">
                                <div class="meta-item">
                                    <i class="fas fa-phone meta-icon" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars($userData['phone'] ?? 'No phone provided', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-calendar-alt meta-icon" aria-hidden="true"></i>
                                    Joined <?php echo isset($userData['created_at']) ? formatDate($userData['created_at']) : 'Unknown'; ?>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-circle meta-icon" style="color: <?php echo isset($userData['status']) && $userData['status'] === 'active' ? '#4cd963' : '#6c757d'; ?>" aria-hidden="true"></i>
                                    <?php echo isset($userData['status']) ? ucfirst(htmlspecialchars($userData['status'], ENT_QUOTES, 'UTF-8')) : 'Unknown'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Stats Cards Row -->
                <div class="row mb-4">
                    <div class="col-md-6 col-lg-3 mb-3 mb-lg-0">
                        <div class="card">
                            <div class="stat-card">
                                <div class="stat-icon primary">
                                    <i class="fas fa-calendar-check" aria-hidden="true"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $dashboardStats['total_bookings']; ?></div>
                                    <div class="stat-label">Total Bookings</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3 mb-3 mb-lg-0">
                        <div class="card">
                            <div class="stat-card">
                                <div class="stat-icon warning">
                                    <i class="fas fa-clock" aria-hidden="true"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $dashboardStats['pending_bookings'] + $dashboardStats['confirmed_bookings']; ?></div>
                                    <div class="stat-label">Active Bookings</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3 mb-3 mb-lg-0">
                        <div class="card">
                            <div class="stat-card">
                                <div class="stat-icon success">
                                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $dashboardStats['completed_bookings']; ?></div>
                                    <div class="stat-label">Completed</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-lg-3">
                        <div class="card">
                            <div class="stat-card">
                                <div class="stat-icon danger">
                                    <i class="fas fa-times-circle" aria-hidden="true"></i>
                                </div>
                                <div class="stat-details">
                                    <div class="stat-value"><?php echo $dashboardStats['cancelled_bookings']; ?></div>
                                    <div class="stat-label">Cancelled</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6 col-lg-4">
                                <a href="book-service.php" class="btn btn-primary w-100 py-3">
                                    <i class="fas fa-calendar-plus me-2" aria-hidden="true"></i>Book a Service
                                </a>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <a href="quotes.php" class="btn btn-outline-primary w-100 py-3">
                                    <i class="fas fa-clipboard-list me-2" aria-hidden="true"></i>Request a Quote
                                </a>
                            </div>
                            <div class="col-md-6 col-lg-4">
                                <a href="bookings.php" class="btn btn-outline-primary w-100 py-3">
                                    <i class="fas fa-list me-2" aria-hidden="true"></i>View All Bookings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Upcoming Bookings -->
                    <div class="col-lg-8 mb-4">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Upcoming Bookings</h5>
                                <a href="bookings.php" class="btn btn-sm btn-primary">View All</a>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($upcomingBookings)): ?>
                                <div class="empty-state">
                                    <div class="empty-state-icon">
                                        <i class="fas fa-calendar" aria-hidden="true"></i>
                                    </div>
                                    <h3 class="empty-state-title">No Upcoming Bookings</h3>
                                    <p class="empty-state-text">
                                        You don't have any upcoming bookings at the moment.
                                    </p>
                                    <a href="book-service.php" class="btn btn-primary">Book a Service</a>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($upcomingBookings as $booking): ?>
                                    <div class="booking-item">
                                        <?php
                                        $bookingDate = isset($booking['booking_date']) ? new DateTime($booking['booking_date']) : null;
                                        ?>
                                        <div class="booking-date">
                                            <?php if ($bookingDate): ?>
                                            <div class="day"><?php echo $bookingDate->format('d'); ?></div>
                                            <div class="month"><?php echo $bookingDate->format('M'); ?></div>
                                            <?php else: ?>
                                            <div class="day">--</div>
                                            <div class="month">---</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="booking-content">
                                            <h6 class="booking-title">
                                                <?php echo htmlspecialchars($booking['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?>
                                            </h6>
                                            <div class="booking-meta">
                                                <div class="booking-meta-item">
                                                    <i class="fas fa-clock booking-meta-icon" aria-hidden="true"></i>
                                                    <?php echo formatTime($booking['booking_time'] ?? ''); ?>
                                                </div>
                                                <div class="booking-meta-item">
                                                    <i class="fas fa-user-cog booking-meta-icon" aria-hidden="true"></i>
                                                    <?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                </div>
                                                <div class="booking-meta-item">
                                                    <i class="fas fa-tag booking-meta-icon" aria-hidden="true"></i>
                                                    <?php echo formatPrice($booking['total_price'] ?? 0); ?>
                                                </div>
                                            </div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="status-badge <?php echo htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo ucfirst(htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                                                </span>
                                                <a href="booking-details.php?id=<?php echo (int)$booking['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                    View Details
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notifications -->
                    <div class="col-lg-4 mb-4">
                        <div class="card h-100">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Recent Notifications</h5>
                                <a href="notifications.php" class="btn btn-sm btn-primary">View All</a>
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
                                    <?php foreach ($notifications as $notification): ?>
                                    <div class="notification-item d-flex align-items-start">
                                        <?php
                                        $notifIcon = 'info-circle';
                                        $notifType = 'primary';
                                        
                                        if (isset($notification['type'])) {
                                            switch ($notification['type']) {
                                                case 'new_quote':
                                                    $notifIcon = 'check-circle';
                                                    $notifType = 'success';
                                                    break;
                                                case 'new_request':
                                                    $notifIcon = 'exclamation-triangle';
                                                    $notifType = 'warning';
                                                    break;
                                                case 'quote_accepted':
                                                case 'quote_rejected':
                                                    $notifIcon = 'times-circle';
                                                    $notifType = 'danger';
                                                    break;
                                            }
                                        }
                                        ?>
                                        <div class="notification-icon <?php echo $notifType; ?>">
                                            <i class="fas fa-<?php echo $notifIcon; ?>" aria-hidden="true"></i>
                                        </div>
                                        <div class="notification-content">
                                            <div class="notification-title">
                                                <?php echo htmlspecialchars($notification['message'] ?? 'Notification', ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                            <div class="notification-time">
                                                <?php echo timeAgo($notification['created_at'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Recent Bookings -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Recent Bookings</h5>
                        <a href="bookings.php" class="btn btn-sm btn-primary">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($recentBookings)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-calendar-times" aria-hidden="true"></i>
                            </div>
                            <h3 class="empty-state-title">No Booking History</h3>
                            <p class="empty-state-text">
                                You haven't made any bookings yet.
                            </p>
                            <a href="book-service.php" class="btn btn-primary">Book a Service</a>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">ID</th>
                                        <th scope="col">Service</th>
                                        <th scope="col">Provider</th>
                                        <th scope="col">Date & Time</th>
                                        <th scope="col">Price</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBookings as $booking): ?>
                                    <tr>
                                        <td>#<?php echo htmlspecialchars($booking['id'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($booking['service_name'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <div class="d-flex flex-column">
                                                <div class="fw-bold"><?php echo formatDate($booking['booking_date'] ?? ''); ?></div>
                                                <div class="text-muted small"><?php echo formatTime($booking['booking_time'] ?? ''); ?></div>
                                            </div>
                                        </td>
                                        <td><?php echo formatPrice($booking['total_price'] ?? 0); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo ucfirst(htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="booking-details.php?id=<?php echo (int)$booking['id']; ?>" class="btn btn-sm btn-outline-primary">
                                                View
                                            </a>
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