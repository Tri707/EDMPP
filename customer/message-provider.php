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
    $providerData = [];
    $providerImage = '../default.png';
    $messages = [];
    $notificationCount = 0;
    $errorMessage = '';
    $successMessage = '';
    $userInitials = 'CN'; // Default initials
    $providerInitials = 'TP'; // Default provider initials
    $providerId = 0;
    $bookingId = 0;
    $bookingDetails = null;
    $hasActiveBooking = false;
    $recentBookings = [];

    // Get provider ID from URL with validation
    if (isset($_GET['provider']) && is_numeric($_GET['provider'])) {
        $providerId = (int)$_GET['provider'];
    } else {
        // Redirect if no valid provider ID
        $_SESSION['error_message'] = "Invalid provider ID. Please select a valid provider.";
        header('Location: dashboard.php');
        exit;
    }

    // Get optional booking ID if provided
    if (isset($_GET['booking']) && is_numeric($_GET['booking'])) {
        $bookingId = (int)$_GET['booking'];
    }

    // Handle message submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message']) && !empty($_POST['message'])) {
        $message = trim($_POST['message']);
        $messageBookingId = isset($_POST['booking_id']) && is_numeric($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
        $quoteId = isset($_POST['quote_id']) && is_numeric($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;
        
        try {
            // First, verify that the provider exists
            $providerCheckStmt = $conn->prepare("SELECT p.id, u.id as user_id FROM providers p JOIN users u ON p.user_id = u.id WHERE u.id = ? AND u.status = 'active'");
            $providerCheckStmt->bind_param("i", $providerId);
            $providerCheckStmt->execute();
            $providerCheckResult = $providerCheckStmt->get_result();
            
            if ($providerCheckResult->num_rows === 0) {
                throw new Exception("Provider not found or inactive.");
            }
            
            $providerInfo = $providerCheckResult->fetch_assoc();
            $providerCheckStmt->close();
            
            // Insert the message into the database
            $insertStmt = $conn->prepare("
                INSERT INTO messages (sender_id, receiver_id, booking_id, quote_id, message, is_read, created_at)
                VALUES (?, ?, ?, ?, ?, 0, NOW())
            ");
            $insertStmt->bind_param("iiiss", $userId, $providerId, $messageBookingId, $quoteId, $message);
            $insertStmt->execute();
            
            if ($insertStmt->affected_rows > 0) {
                $successMessage = "Message sent successfully.";
            } else {
                $errorMessage = "Failed to send message. Please try again.";
            }
            $insertStmt->close();
            
        } catch (Exception $e) {
            $errorMessage = "Error: " . $e->getMessage();
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
                // URL-based image
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
        
        // Get user initials for avatar
        if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
            $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
        } elseif (!empty($userData['first_name'])) {
            $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
        } elseif (!empty($userData['last_name'])) {
            $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
        }
        
        // Query to get provider data
        $providerQuery = "
            SELECT u.*, p.id as provider_id, p.specialties, p.experience, p.hourly_rate, 
                p.bio, p.location, p.availability, p.is_verified
            FROM users u
            JOIN providers p ON u.id = p.user_id
            WHERE u.id = ? AND u.role = 'provider'
        ";
        
        $providerStmt = $conn->prepare($providerQuery);
        $providerStmt->bind_param("i", $providerId);
        $providerStmt->execute();
        $providerResult = $providerStmt->get_result();
        
        if ($providerResult->num_rows === 0) {
            // Provider not found
            $_SESSION['error_message'] = "Provider not found. Please select a valid provider.";
            header('Location: dashboard.php');
            exit;
        }
        
        $providerData = $providerResult->fetch_assoc();
        $providerStmt->close();
        
        // Set provider profile image path
        if (!empty($providerData['profile_image'])) {
            if (filter_var($providerData['profile_image'], FILTER_VALIDATE_URL)) {
                // URL-based image
                $providerImage = $providerData['profile_image'];
            } else {
                // File-based image with protection against directory traversal
                $imageFile = basename($providerData['profile_image']);
                $imagePath = '../profile_images/' . $imageFile;
                if (file_exists($imagePath) && is_file($imagePath)) {
                    $providerImage = $imagePath;
                }
            }
        }
        
        // Get provider initials for avatar
        if (!empty($providerData['first_name']) && !empty($providerData['last_name'])) {
            $providerInitials = strtoupper(substr($providerData['first_name'], 0, 1) . substr($providerData['last_name'], 0, 1));
        } elseif (!empty($providerData['first_name'])) {
            $providerInitials = strtoupper(substr($providerData['first_name'], 0, 2));
        } elseif (!empty($providerData['last_name'])) {
            $providerInitials = strtoupper(substr($providerData['last_name'], 0, 2));
        }
        
        // Get booking details if booking ID provided
        if ($bookingId > 0) {
            $bookingQuery = "
                SELECT b.*, s.name as service_name, s.price as service_price
                FROM bookings b
                LEFT JOIN services s ON b.service_id = s.id
                WHERE b.id = ? AND b.customer_id = ? AND b.provider_id = ?
            ";
            
            $bookingStmt = $conn->prepare($bookingQuery);
            $bookingStmt->bind_param("iii", $bookingId, $userId, $providerData['provider_id']);
            $bookingStmt->execute();
            $bookingResult = $bookingStmt->get_result();
            
            if ($bookingResult->num_rows > 0) {
                $bookingDetails = $bookingResult->fetch_assoc();
            }
            $bookingStmt->close();
        }
        
        // Check if there are any active bookings with this provider
        $activeBookingQuery = "
            SELECT b.id, b.booking_date, b.booking_time, b.status, s.name as service_name
            FROM bookings b
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.customer_id = ? AND b.provider_id = ? AND b.status IN ('pending', 'confirmed')
            ORDER BY b.booking_date, b.booking_time
            LIMIT 3
        ";
        
        $activeBookingStmt = $conn->prepare($activeBookingQuery);
        $activeBookingStmt->bind_param("ii", $userId, $providerData['provider_id']);
        $activeBookingStmt->execute();
        $activeBookingResult = $activeBookingStmt->get_result();
        
        $hasActiveBooking = $activeBookingResult->num_rows > 0;
        $recentBookings = [];
        
        while ($row = $activeBookingResult->fetch_assoc()) {
            $recentBookings[] = $row;
        }
        $activeBookingStmt->close();
        
        // Get all messages between the customer and this provider
        $messagesQuery = "
            SELECT m.* 
            FROM messages m
            WHERE (m.sender_id = ? AND m.receiver_id = ?) 
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ";
        
        $messagesStmt = $conn->prepare($messagesQuery);
        $messagesStmt->bind_param("iiii", $userId, $providerId, $providerId, $userId);
        $messagesStmt->execute();
        $messagesResult = $messagesStmt->get_result();
        
        $messages = [];
        while ($row = $messagesResult->fetch_assoc()) {
            $messages[] = $row;
        }
        $messagesStmt->close();
        
        // Mark any unread messages as read
        if (!empty($messages)) {
            $updateReadStmt = $conn->prepare("
                UPDATE messages
                SET is_read = 1
                WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
            ");
            $updateReadStmt->bind_param("ii", $providerId, $userId);
            $updateReadStmt->execute();
            $updateReadStmt->close();
        }
        
        // Get unread notifications count for the customer
        $notifQuery = "
            SELECT COUNT(*) as count FROM quote_notifications
            WHERE recipient_id = ?
            AND is_read = 0
        ";
        
        $notifStmt = $conn->prepare($notifQuery);
        $notifStmt->bind_param("i", $userId);
        $notifStmt->execute();
        $notifResult = $notifStmt->get_result();
        $notificationCount = $notifResult->fetch_assoc()['count'];
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
        $errorMessage = "An error occurred while fetching data. Please try again later.";
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
    $conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Message your service provider with FixItNow">
    <meta name="robots" content="noindex, nofollow">
    <title>Message Provider - FixItNow</title>
    
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
        
        /* Header Styles */
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
        
        /* Back button */
        .back-button {
            display: inline-flex;
            align-items: center;
            margin-right: 1rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .back-button:hover {
            color: var(--primary-color);
            transform: translateX(-3px);
        }
        
        /* Message area specific styles */
        .message-container {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 400px);
            min-height: 400px;
        }
        
        .provider-info-card {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
        }
        
        .provider-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-color);
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
            margin-right: 1.5rem;
            flex-shrink: 0;
        }
        
        .provider-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .provider-details {
            flex: 1;
        }
        
        .provider-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .provider-meta {
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        
        .provider-specialties {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 0.75rem;
        }
        
        .specialty-badge {
            background-color: var(--primary-light);
            color: var(--primary-color);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .verified-badge {
            background-color: var(--accent-light);
            color: var(--accent-color);
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .verified-badge i {
            margin-right: 0.25rem;
        }
        
        .booking-selection {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .current-booking {
            background-color: var(--primary-light);
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .booking-info h5 {
            color: var(--primary-color);
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .booking-time {
            font-size: 0.85rem;
            color: var(--text-color);
            opacity: 0.8;
        }
        
        .booking-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .booking-badge.pending {
            background-color: var(--warning-color);
            color: var(--text-color);
        }
        
        .booking-badge.confirmed {
            background-color: var(--primary-color);
            color: white;
        }
        
        .booking-badge.completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .messages-area {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .messages-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
        }
        
        .messages-list {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
        }
        
        .message {
            display: flex;
            margin-bottom: 1.5rem;
        }
        
        .message.outgoing {
            justify-content: flex-end;
        }
        
        .message-content {
            max-width: 70%;
            padding: 1rem;
            border-radius: 1rem;
            position: relative;
        }
        
        .message.incoming .message-content {
            background-color: var(--bg-color);
            border: 1px solid var(--border-color);
            border-top-left-radius: 0;
        }
        
        .message.outgoing .message-content {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-top-right-radius: 0;
        }
        
        .message-text {
            margin-bottom: 0.5rem;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: right;
        }
        
        .booking-tag {
            display: inline-block;
            background-color: rgba(0, 0, 0, 0.05);
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 0.25rem;
            margin-top: 0.25rem;
        }
        
        .message-form {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .message-input-group {
            display: flex;
        }
        
        .message-input {
            flex: 1;
            border-radius: 2rem 0 0 2rem !important;
            padding-left: 1.25rem;
        }
        
        .send-button {
            border-radius: 0 2rem 2rem 0 !important;
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }
        
        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 2rem;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 3.5rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 350px;
            margin-bottom: 1.5rem;
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
            .provider-info-card {
                flex-direction: column;
                text-align: center;
            }
            
            .provider-avatar {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .provider-specialties {
                justify-content: center;
            }
            
            .message-content {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
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
                    
                    <!-- Notification Button -->
                    <div class="dropdown me-3">
                        <button class="header-icon-btn" type="button" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo $notificationCount > 9 ? '9+' : $notificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <div class="p-3 border-bottom">
                                <h6 class="mb-0">Notifications</h6>
                            </div>
                            <?php if (empty($recentNotifications)): ?>
                            <div class="p-3 text-center">
                                <p class="text-muted mb-0">No notifications yet</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($recentNotifications as $notification): ?>
                                <div class="notification-item">
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
                            <div class="p-2 border-top text-center">
                                <a href="notifications.php" class="btn btn-link btn-sm view-all">View all notifications</a>
                            </div>
                        </div>
                    </div>
                    
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
                <a href="profile.php" class="mobile-menu-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
                <a href="messages.php" class="mobile-menu-item active">
                    <i class="fas fa-envelope"></i> Messages
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
                        <a class="nav-link" href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="messages.php">
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
            <div class="d-flex align-items-center mb-4">
                <a href="messages.php" class="back-button">
                    <i class="fas fa-chevron-left me-1"></i> Back to Messages
                </a>
                <h1 class="page-title mb-0">Message Provider</h1>
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
            
            <!-- Provider Info Card -->
            <div class="provider-info-card">
                <?php if (!empty($providerImage) && $providerImage !== '../default.png'): ?>
                    <div class="provider-avatar">
                        <img src="<?php echo htmlspecialchars($providerImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Provider">
                    </div>
                <?php else: ?>
                    <div class="provider-avatar">
                        <?php echo htmlspecialchars($providerInitials, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>
                
                <div class="provider-details">
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                        <h3 class="provider-name mb-0">
                            <?php echo htmlspecialchars(($providerData['first_name'] ?? '') . ' ' . ($providerData['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </h3>
                        
                        <?php if (isset($providerData['is_verified']) && $providerData['is_verified']): ?>
                        <div class="verified-badge">
                            <i class="fas fa-check-circle" aria-hidden="true"></i> Verified
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="provider-meta">
                        <div class="location">
                            <i class="fas fa-map-marker-alt me-1" aria-hidden="true"></i>
                            <?php echo htmlspecialchars($providerData['location'] ?? 'Location not specified', ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        
                        <?php if (!empty($providerData['experience'])): ?>
                        <div class="experience mt-1">
                            <i class="fas fa-briefcase me-1" aria-hidden="true"></i>
                            <?php 
                            $experience = $providerData['experience'];
                            if ($experience === '0-1') {
                                echo 'Less than 1 year experience';
                            } elseif ($experience === '1-3') {
                                echo '1-3 years experience';
                            } elseif ($experience === '3-5') {
                                echo '3-5 years experience';
                            } elseif ($experience === '5+') {
                                echo 'More than 5 years experience';
                            } else {
                                echo htmlspecialchars($experience, ENT_QUOTES, 'UTF-8') . ' years experience';
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($providerData['specialties'])): ?>
                    <div class="provider-specialties">
                        <?php 
                        $specialties = explode(',', $providerData['specialties']);
                        foreach ($specialties as $specialty):
                            if (!empty(trim($specialty))):
                        ?>
                        <div class="specialty-badge">
                            <?php echo htmlspecialchars(ucfirst(trim($specialty)), ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Booking Selection (if any active bookings) -->
            <?php if ($hasActiveBooking): ?>
            <div class="booking-selection">
                <h4 class="mb-3">Your Bookings with This Provider</h4>
                
                <!-- Current booking (if specific booking is selected) -->
                <?php if (!empty($bookingDetails)): ?>
                <div class="current-booking">
                    <div class="booking-info">
                        <h5><?php echo htmlspecialchars($bookingDetails['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?></h5>
                        <div class="booking-time">
                            <i class="fas fa-calendar-alt me-1" aria-hidden="true"></i>
                            <?php echo formatDate($bookingDetails['booking_date']); ?> at <?php echo formatTime($bookingDetails['booking_time']); ?>
                        </div>
                    </div>
                    <div class="booking-badge <?php echo htmlspecialchars($bookingDetails['status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo ucfirst(htmlspecialchars($bookingDetails['status'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Active bookings dropdown -->
                <div class="mb-3">
                    <label for="bookingSelect" class="form-label">Select Booking</label>
                    <select class="form-select" id="bookingSelect" onchange="window.location.href=this.value">
                        <option value="message-provider.php?provider=<?php echo (int)$providerId; ?>">General Message (No Booking)</option>
                        
                        <?php foreach ($recentBookings as $booking): ?>
                        <option value="message-provider.php?provider=<?php echo (int)$providerId; ?>&booking=<?php echo (int)$booking['id']; ?>" 
                                <?php echo ($bookingId === (int)$booking['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($booking['service_name'] ?? 'Booking', ENT_QUOTES, 'UTF-8'); ?> - 
                            <?php echo formatDate($booking['booking_date']); ?> - 
                            <?php echo ucfirst(htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8')); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Messages Area -->
            <div class="message-container">
                <div class="messages-area">
                    <div class="messages-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>Conversation with <?php echo htmlspecialchars(($providerData['first_name'] ?? '') . ' ' . ($providerData['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                            
                            <?php if (!empty($bookingDetails)): ?>
                            <span class="badge bg-primary">
                                <i class="fas fa-calendar-check me-1" aria-hidden="true"></i>
                                Booking #<?php echo (int)$bookingDetails['id']; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="messages-list" id="messagesList">
                        <?php if (empty($messages)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-comments" aria-hidden="true"></i>
                            </div>
                            <h3 class="empty-state-title">No Messages Yet</h3>
                            <p class="empty-state-text">
                                Start the conversation by sending your first message to this provider.
                            </p>
                        </div>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <?php $isOutgoing = $message['sender_id'] === $userId; ?>
                                <div class="message <?php echo $isOutgoing ? 'outgoing' : 'incoming'; ?>">
                                    <div class="message-content">
                                        <div class="message-text">
                                            <?php echo htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        
                                        <?php if (!empty($message['booking_id'])): ?>
                                        <div class="booking-tag">
                                            <i class="fas fa-calendar-check me-1" aria-hidden="true"></i>
                                            Booking #<?php echo (int)$message['booking_id']; ?>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="message-time">
                                            <?php echo formatDate($message['created_at'], 'M d, Y h:i A'); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div class="message-form">
                        <form method="POST" action="message-provider.php?provider=<?php echo (int)$providerId; ?><?php echo $bookingId ? '&booking=' . (int)$bookingId : ''; ?>">
                            <?php if ($bookingId): ?>
                            <input type="hidden" name="booking_id" value="<?php echo (int)$bookingId; ?>">
                            <?php endif; ?>
                            
                            <div class="message-input-group">
                                <input type="text" name="message" class="form-control message-input" placeholder="Type your message..." required autofocus>
                                <button type="submit" class="btn btn-primary send-button">
                                    <i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Send
                                </button>
                            </div>
                        </form>
                    </div>
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
            
            // Scroll to the bottom of the messages container
            const messagesList = document.getElementById('messagesList');
            if (messagesList) {
                messagesList.scrollTop = messagesList.scrollHeight;
            }
        });
    </script>
</body>
</html> 