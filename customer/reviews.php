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
$reviews = [];
$pendingReviews = [];
$notificationCount = 0;
$recentNotifications = [];
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

// Process review submission if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_review') {
    // Validate and sanitize input
    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $providerId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
    $rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0;
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    
    // Basic validation
    if ($bookingId <= 0 || $providerId <= 0 || $rating <= 0 || $rating > 5 || empty($comment)) {
        $errorMessage = "Please provide all required fields for your review.";
    } else {
        try {
            // Check if booking exists and belongs to the customer
            $checkStmt = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND customer_id = ? AND status = 'completed'");
            $checkStmt->bind_param("ii", $bookingId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows !== 1) {
                $errorMessage = "Invalid booking or you're not authorized to review this booking.";
            } else {
                // Check if review already exists
                $existingStmt = $conn->prepare("SELECT id FROM reviews WHERE booking_id = ? AND customer_id = ?");
                $existingStmt->bind_param("ii", $bookingId, $userId);
                $existingStmt->execute();
                $existingResult = $existingStmt->get_result();
                
                if ($existingResult->num_rows > 0) {
                    // Update existing review
                    $reviewId = $existingResult->fetch_assoc()['id'];
                    $updateStmt = $conn->prepare("UPDATE reviews SET rating = ?, comment = ?, updated_at = NOW() WHERE id = ?");
                    $updateStmt->bind_param("dsi", $rating, $comment, $reviewId);
                    
                    if ($updateStmt->execute()) {
                        $successMessage = "Your review has been updated successfully.";
                    } else {
                        $errorMessage = "Failed to update your review. Please try again.";
                    }
                    
                    $updateStmt->close();
                } else {
                    // Insert new review
                    $insertStmt = $conn->prepare("INSERT INTO reviews (booking_id, customer_id, provider_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
                    $insertStmt->bind_param("iiids", $bookingId, $userId, $providerId, $rating, $comment);
                    
                    if ($insertStmt->execute()) {
                        $successMessage = "Your review has been submitted successfully.";
                    } else {
                        $errorMessage = "Failed to submit your review. Please try again.";
                    }
                    
                    $insertStmt->close();
                }
                
                $existingStmt->close();
            }
            
            $checkStmt->close();
        } catch (Exception $e) {
            error_log("Review submission error: " . $e->getMessage());
            $errorMessage = "An error occurred while processing your review. Please try again later.";
        }
    }
}

// Process review deletion if requested
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_review') {
    $reviewId = isset($_POST['review_id']) ? (int)$_POST['review_id'] : 0;
    
    if ($reviewId <= 0) {
        $errorMessage = "Invalid review specified for deletion.";
    } else {
        try {
            // Check if review exists and belongs to the customer
            $checkStmt = $conn->prepare("SELECT id FROM reviews WHERE id = ? AND customer_id = ?");
            $checkStmt->bind_param("ii", $reviewId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows !== 1) {
                $errorMessage = "Invalid review or you're not authorized to delete this review.";
            } else {
                // Delete the review
                $deleteStmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
                $deleteStmt->bind_param("i", $reviewId);
                
                if ($deleteStmt->execute()) {
                    $successMessage = "Your review has been deleted successfully.";
                } else {
                    $errorMessage = "Failed to delete your review. Please try again.";
                }
                
                $deleteStmt->close();
            }
            
            $checkStmt->close();
        } catch (Exception $e) {
            error_log("Review deletion error: " . $e->getMessage());
            $errorMessage = "An error occurred while deleting your review. Please try again later.";
        }
    }
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
    
    // Get user initials for avatar
    if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
    } elseif (!empty($userData['first_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
    } elseif (!empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
    }
    
    // Get submitted reviews
    $reviewsQuery = "
        SELECT r.*, 
            b.booking_date, 
            b.booking_time,
            s.name AS service_name, 
            u.first_name AS provider_first_name, 
            u.last_name AS provider_last_name,
            u.profile_image AS provider_image
        FROM reviews r
        JOIN bookings b ON r.booking_id = b.id
        JOIN providers p ON r.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE r.customer_id = ?
        ORDER BY r.created_at DESC
    ";
    
    $reviewsStmt = $conn->prepare($reviewsQuery);
    $reviewsStmt->bind_param("i", $userId);
    $reviewsStmt->execute();
    $reviewsResult = $reviewsStmt->get_result();
    
    while ($row = $reviewsResult->fetch_assoc()) {
        $reviews[] = $row;
    }
    $reviewsStmt->close();
    
    // Get completed bookings without reviews (pending reviews)
    $pendingQuery = "
        SELECT b.*, 
            p.id AS provider_id,
            s.name AS service_name,
            u.first_name AS provider_first_name,
            u.last_name AS provider_last_name,
            u.profile_image AS provider_image
        FROM bookings b
        JOIN providers p ON b.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN services s ON b.service_id = s.id
        LEFT JOIN reviews r ON b.id = r.booking_id AND r.customer_id = b.customer_id
        WHERE b.customer_id = ?
        AND b.status = 'completed'
        AND r.id IS NULL
        ORDER BY b.booking_date DESC
    ";
    
    $pendingStmt = $conn->prepare($pendingQuery);
    $pendingStmt->bind_param("i", $userId);
    $pendingStmt->execute();
    $pendingResult = $pendingStmt->get_result();
    
    while ($row = $pendingResult->fetch_assoc()) {
        $pendingReviews[] = $row;
    }
    $pendingStmt->close();
    
    // Get unread notifications count for the customer
    $notifCountQuery = "
        SELECT COUNT(*) AS count FROM quote_notifications
        WHERE recipient_id = ?
        AND is_read = 0
    ";
    
    $notifCountStmt = $conn->prepare($notifCountQuery);
    $notifCountStmt->bind_param("i", $userId);
    $notifCountStmt->execute();
    $notifCountResult = $notifCountStmt->get_result();
    $notificationCount = $notifCountResult->fetch_assoc()['count'];
    $notifCountStmt->close();
    
    // Get recent notifications for dropdown
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
    
    while ($row = $recentNotifResult->fetch_assoc()) {
        $recentNotifications[] = $row;
    }
    $recentNotifStmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching your data. Please try again later.";
}

// Function to format stars for displaying ratings
function formatStars($rating) {
    $rating = max(0, min(5, $rating)); // Ensure rating is between 0 and 5
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    $html = '';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star text-warning"></i>';
    }
    
    // Half star if needed
    if ($halfStar) {
        $html .= '<i class="fas fa-star-half-alt text-warning"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star text-warning"></i>';
    }
    
    return $html;
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

// Truncate text to a specific length
function truncateText($text, $maxLength = 100) {
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    
    return substr($text, 0, $maxLength) . '...';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Manage your reviews - FixItNow Customer Dashboard">
    <meta name="robots" content="noindex, nofollow">
    <title>My Reviews - FixItNow</title>
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
        
        /* Enhanced Header Styles */
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
        
        /* Review Card Styles */
        .review-card {
            margin-bottom: 1.5rem;
            transition: transform 0.2s ease;
        }
        
        .review-card:hover {
            transform: translateY(-5px);
        }
        
        .review-header {
            display: flex;
            align-items: center;
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .review-provider-image {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 2px solid var(--primary-light);
        }
        
        .review-provider-initials {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.25rem;
            margin-right: 1rem;
        }
        
        .review-provider-details {
            flex: 1;
        }
        
        .review-provider-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .review-service {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .review-date {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .review-rating {
            font-size: 1.25rem;
            margin-left: auto;
        }
        
        .review-content {
            padding: 1.25rem;
        }
        
        .review-text {
            margin-bottom: 1rem;
            line-height: 1.6;
            color: var(--text-color);
        }
        
        .review-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        /* Star Rating Input */
        .rating-input {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
        }
        
        .rating-input input {
            display: none;
        }
        
        .rating-input label {
            cursor: pointer;
            font-size: 1.5rem;
            color: #dddddd;
            padding: 0 0.1rem;
            transition: color 0.2s;
        }
        
        .rating-input label:hover,
        .rating-input label:hover ~ label,
        .rating-input input:checked ~ label {
            color: #ffc107;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
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
            max-width: 400px;
            margin: 0 auto 1.5rem;
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
        @media (max-width: 992px) {
            .review-card {
                margin-bottom: 1rem;
            }
            
            .review-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .review-provider-image,
            .review-provider-initials {
                margin-right: 0;
                margin-bottom: 0.75rem;
            }
            
            .review-rating {
                margin-left: 0;
                margin-top: 0.75rem;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .review-actions {
                flex-direction: column;
            }
            
            .review-actions .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- Improved Header -->
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
                <a href="reviews.php" class="mobile-menu-item active">
                    <i class="fas fa-star"></i> My Reviews
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
                        <a class="nav-link active" href="reviews.php">
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
                <h1 class="page-title">My Reviews</h1>
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
            
            <!-- Pending Reviews Section -->
            <?php if (!empty($pendingReviews)): ?>
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Pending Reviews</h5>
                    <span class="badge bg-warning rounded-pill"><?php echo count($pendingReviews); ?></span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-4">You have completed services that you haven't reviewed yet. Share your experience to help other customers.</p>
                    
                    <div class="row">
                        <?php foreach ($pendingReviews as $booking): ?>
                        <div class="col-lg-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h6 class="mb-0"><?php echo htmlspecialchars($booking['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?></h6>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex align-items-center mb-3">
                                        <?php if (!empty($booking['provider_image'])): ?>
                                        <img src="<?php echo htmlspecialchars($booking['provider_image'], ENT_QUOTES, 'UTF-8'); ?>" class="review-provider-image" alt="Provider">
                                        <?php else: ?>
                                        <div class="review-provider-initials">
                                            <?php 
                                            $providerInitials = 'PR';
                                            if (!empty($booking['provider_first_name']) && !empty($booking['provider_last_name'])) {
                                                $providerInitials = strtoupper(substr($booking['provider_first_name'], 0, 1) . substr($booking['provider_last_name'], 0, 1));
                                            }
                                            echo $providerInitials;
                                            ?>
                                        </div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="fw-bold"><?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-muted small">
                                                Completed on <?php echo formatDate($booking['booking_date'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <button type="button" class="btn btn-primary w-100" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo (int)$booking['id']; ?>">
                                        <i class="fas fa-star me-2" aria-hidden="true"></i>Write a Review
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Review Modal -->
                            <div class="modal fade" id="reviewModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="reviewModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="reviewModalLabel<?php echo (int)$booking['id']; ?>">Write a Review</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="POST" action="reviews.php">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="submit_review">
                                                <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                                                <input type="hidden" name="provider_id" value="<?php echo (int)$booking['provider_id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Service</label>
                                                    <div><?php echo htmlspecialchars($booking['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Provider</label>
                                                    <div><?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Rating</label>
                                                    <div class="rating-input">
                                                        <input type="radio" id="star5-<?php echo (int)$booking['id']; ?>" name="rating" value="5" required>
                                                        <label for="star5-<?php echo (int)$booking['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="star4-<?php echo (int)$booking['id']; ?>" name="rating" value="4">
                                                        <label for="star4-<?php echo (int)$booking['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="star3-<?php echo (int)$booking['id']; ?>" name="rating" value="3">
                                                        <label for="star3-<?php echo (int)$booking['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="star2-<?php echo (int)$booking['id']; ?>" name="rating" value="2">
                                                        <label for="star2-<?php echo (int)$booking['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="star1-<?php echo (int)$booking['id']; ?>" name="rating" value="1">
                                                        <label for="star1-<?php echo (int)$booking['id']; ?>"><i class="fas fa-star"></i></label>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="review-comment-<?php echo (int)$booking['id']; ?>" class="form-label fw-bold">Your Review</label>
                                                    <textarea class="form-control" id="review-comment-<?php echo (int)$booking['id']; ?>" name="comment" rows="4" placeholder="Share your experience with the service..." required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Submit Review</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- My Reviews Section -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">My Reviews</h5>
                    <?php if (!empty($reviews)): ?>
                    <span class="badge bg-primary rounded-pill"><?php echo count($reviews); ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($reviews)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-star" aria-hidden="true"></i>
                        </div>
                        <h3 class="empty-state-title">No Reviews Yet</h3>
                        <p class="empty-state-text">
                            You haven't submitted any reviews yet. After you complete a service, you can share your experience to help other customers.
                        </p>
                        <a href="bookings.php" class="btn btn-primary">
                            <i class="fas fa-calendar-check me-2" aria-hidden="true"></i>View My Bookings
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($reviews as $review): ?>
                        <div class="col-lg-6">
                            <div class="card review-card">
                                <div class="review-header">
                                    <?php if (!empty($review['provider_image'])): ?>
                                    <img src="<?php echo htmlspecialchars($review['provider_image'], ENT_QUOTES, 'UTF-8'); ?>" class="review-provider-image" alt="Provider">
                                    <?php else: ?>
                                    <div class="review-provider-initials">
                                        <?php 
                                        $providerInitials = 'PR';
                                        if (!empty($review['provider_first_name']) && !empty($review['provider_last_name'])) {
                                            $providerInitials = strtoupper(substr($review['provider_first_name'], 0, 1) . substr($review['provider_last_name'], 0, 1));
                                        }
                                        echo $providerInitials;
                                        ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="review-provider-details">
                                        <div class="review-provider-name">
                                            <?php echo htmlspecialchars(($review['provider_first_name'] ?? '') . ' ' . ($review['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="review-service">
                                            <?php echo htmlspecialchars($review['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="review-date">
                                            Reviewed on <?php echo formatDate($review['created_at'] ?? '', 'M d, Y'); ?>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <?php echo formatStars($review['rating'] ?? 0); ?>
                                    </div>
                                </div>
                                <div class="review-content">
                                    <div class="review-text">
                                        <?php echo htmlspecialchars($review['comment'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="review-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editReviewModal<?php echo (int)$review['id']; ?>">
                                            <i class="fas fa-edit me-1" aria-hidden="true"></i>Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteReviewModal<?php echo (int)$review['id']; ?>">
                                            <i class="fas fa-trash-alt me-1" aria-hidden="true"></i>Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Edit Review Modal -->
                            <div class="modal fade" id="editReviewModal<?php echo (int)$review['id']; ?>" tabindex="-1" aria-labelledby="editReviewModalLabel<?php echo (int)$review['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="editReviewModalLabel<?php echo (int)$review['id']; ?>">Edit Review</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <form method="POST" action="reviews.php">
                                            <div class="modal-body">
                                                <input type="hidden" name="action" value="submit_review">
                                                <input type="hidden" name="booking_id" value="<?php echo (int)$review['booking_id']; ?>">
                                                <input type="hidden" name="provider_id" value="<?php echo (int)$review['provider_id']; ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Service</label>
                                                    <div><?php echo htmlspecialchars($review['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Provider</label>
                                                    <div><?php echo htmlspecialchars(($review['provider_first_name'] ?? '') . ' ' . ($review['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label class="form-label fw-bold">Rating</label>
                                                    <div class="rating-input">
                                                        <input type="radio" id="edit-star5-<?php echo (int)$review['id']; ?>" name="rating" value="5" <?php echo ($review['rating'] == 5) ? 'checked' : ''; ?> required>
                                                        <label for="edit-star5-<?php echo (int)$review['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="edit-star4-<?php echo (int)$review['id']; ?>" name="rating" value="4" <?php echo ($review['rating'] == 4) ? 'checked' : ''; ?>>
                                                        <label for="edit-star4-<?php echo (int)$review['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="edit-star3-<?php echo (int)$review['id']; ?>" name="rating" value="3" <?php echo ($review['rating'] == 3) ? 'checked' : ''; ?>>
                                                        <label for="edit-star3-<?php echo (int)$review['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="edit-star2-<?php echo (int)$review['id']; ?>" name="rating" value="2" <?php echo ($review['rating'] == 2) ? 'checked' : ''; ?>>
                                                        <label for="edit-star2-<?php echo (int)$review['id']; ?>"><i class="fas fa-star"></i></label>
                                                        
                                                        <input type="radio" id="edit-star1-<?php echo (int)$review['id']; ?>" name="rating" value="1" <?php echo ($review['rating'] == 1) ? 'checked' : ''; ?>>
                                                        <label for="edit-star1-<?php echo (int)$review['id']; ?>"><i class="fas fa-star"></i></label>
                                                    </div>
                                                </div>
                                                
                                                <div class="mb-3">
                                                    <label for="edit-review-comment-<?php echo (int)$review['id']; ?>" class="form-label fw-bold">Your Review</label>
                                                    <textarea class="form-control" id="edit-review-comment-<?php echo (int)$review['id']; ?>" name="comment" rows="4" required><?php echo htmlspecialchars($review['comment'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-primary">Update Review</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Delete Review Modal -->
                            <div class="modal fade" id="deleteReviewModal<?php echo (int)$review['id']; ?>" tabindex="-1" aria-labelledby="deleteReviewModalLabel<?php echo (int)$review['id']; ?>" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="deleteReviewModalLabel<?php echo (int)$review['id']; ?>">Delete Review</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Are you sure you want to delete your review for:</p>
                                            <p class="fw-bold"><?php echo htmlspecialchars($review['service_name'] ?? 'Service Booking', ENT_QUOTES, 'UTF-8'); ?> by <?php echo htmlspecialchars(($review['provider_first_name'] ?? '') . ' ' . ($review['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                            <p class="text-muted">This action cannot be undone.</p>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <form method="POST" action="reviews.php">
                                                <input type="hidden" name="action" value="delete_review">
                                                <input type="hidden" name="review_id" value="<?php echo (int)$review['id']; ?>">
                                                <button type="submit" class="btn btn-danger">Delete Review</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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