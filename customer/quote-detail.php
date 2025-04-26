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
include '../conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$userData = [];
$userProfileImage = '../default.png';
$userInitials = 'CN'; // Default initials
$quoteRequest = null;
$quotes = [];
$errorMessage = '';
$successMessage = '';
$notificationCount = 0;
$recentNotifications = [];
$media = [];
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check for flash messages from previous redirects
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle accepting a quote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'accept_quote') {
    try {
        $quoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : 0;
        
        if ($quoteId <= 0) {
            throw new Exception('Invalid quote ID.');
        }
        
        // Verify the quote belongs to this customer's request
        $verifyStmt = $conn->prepare("
            SELECT q.id, q.provider_id, p.user_id as technician_id, qr.status as request_status
            FROM quotes q
            JOIN quote_requests qr ON q.request_id = qr.id
            JOIN providers p ON q.provider_id = p.id
            WHERE q.id = ? AND qr.customer_id = ? AND qr.status = 'quoted'
        ");
        $verifyStmt->bind_param("ii", $quoteId, $userId);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        $quoteData = $verifyResult->fetch_assoc();
        $verifyStmt->close();
        
        if (!$quoteData) {
            throw new Exception('Quote not found or cannot be accepted at this time.');
        }
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Update quote status to accepted
        $updateQuoteStmt = $conn->prepare("
            UPDATE quotes 
            SET status = 'accepted' 
            WHERE id = ?
        ");
        $updateQuoteStmt->bind_param("i", $quoteId);
        $updateQuoteStmt->execute();
        $updateQuoteStmt->close();
        
        // Update request status to accepted
        $updateRequestStmt = $conn->prepare("
            UPDATE quote_requests 
            SET status = 'accepted' 
            WHERE id = ? AND customer_id = ?
        ");
        $updateRequestStmt->bind_param("ii", $requestId, $userId);
        $updateRequestStmt->execute();
        $updateRequestStmt->close();
        
        // Update all other quotes for this request to rejected
        $rejectOtherStmt = $conn->prepare("
            UPDATE quotes 
            SET status = 'rejected' 
            WHERE request_id = ? AND id != ?
        ");
        $rejectOtherStmt->bind_param("ii", $requestId, $quoteId);
        $rejectOtherStmt->execute();
        $rejectOtherStmt->close();
        
        // Create notification for the technician
        $notifyStmt = $conn->prepare("
            INSERT INTO quote_notifications (
                recipient_id, 
                request_id, 
                type, 
                message
            ) VALUES (?, ?, 'quote_accepted', 'Your quote has been accepted! The customer is ready to proceed with the repair.')
        ");
        $notifyStmt->bind_param("ii", $quoteData['technician_id'], $requestId);
        $notifyStmt->execute();
        $notifyStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $successMessage = 'You have successfully accepted the quote. The technician has been notified.';
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errorMessage = $e->getMessage();
    }
}

// Handle cancelling a quote request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_request') {
    try {
        // Verify the request belongs to this customer and is in a cancellable state
        $verifyStmt = $conn->prepare("
            SELECT id, status
            FROM quote_requests
            WHERE id = ? AND customer_id = ? AND status IN ('pending', 'quoted')
        ");
        $verifyStmt->bind_param("ii", $requestId, $userId);
        $verifyStmt->execute();
        $verifyResult = $verifyStmt->get_result();
        $requestData = $verifyResult->fetch_assoc();
        $verifyStmt->close();
        
        if (!$requestData) {
            throw new Exception('Quote request not found or cannot be cancelled at this time.');
        }
        
        // Begin transaction
        $conn->begin_transaction();
        
        // Update request status to cancelled
        $updateRequestStmt = $conn->prepare("
            UPDATE quote_requests 
            SET status = 'cancelled' 
            WHERE id = ? AND customer_id = ?
        ");
        $updateRequestStmt->bind_param("ii", $requestId, $userId);
        $updateRequestStmt->execute();
        $updateRequestStmt->close();
        
        // Update all quotes for this request to 'rejected' since it's cancelled
        $updateQuotesStmt = $conn->prepare("
            UPDATE quotes 
            SET status = 'rejected' 
            WHERE request_id = ?
        ");
        $updateQuotesStmt->bind_param("i", $requestId);
        $updateQuotesStmt->execute();
        $updateQuotesStmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $successMessage = 'The quote request has been cancelled successfully.';
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errorMessage = $e->getMessage();
    }
}

try {
    // Get customer user data with proper validation
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
    
    // Verify the request ID and ownership
    if ($requestId <= 0) {
        throw new Exception('Invalid quote request ID.');
    }
    
    // Get quote request details
    $requestStmt = $conn->prepare("
        SELECT qr.*
        FROM quote_requests qr
        WHERE qr.id = ? AND qr.customer_id = ?
    ");
    $requestStmt->bind_param("ii", $requestId, $userId);
    $requestStmt->execute();
    $requestResult = $requestStmt->get_result();
    $quoteRequest = $requestResult->fetch_assoc();
    $requestStmt->close();
    
    if (!$quoteRequest) {
        throw new Exception('Quote request not found or you do not have permission to view it.');
    }
    
    // Get media files for the request
    $mediaStmt = $conn->prepare("
        SELECT * FROM quote_request_media
        WHERE request_id = ?
    ");
    $mediaStmt->bind_param("i", $requestId);
    $mediaStmt->execute();
    $mediaResult = $mediaStmt->get_result();
    
    while ($row = $mediaResult->fetch_assoc()) {
        $media[] = $row;
    }
    
    $mediaStmt->close();
    
    // Get quotes for this request
    $quotesStmt = $conn->prepare("
        SELECT q.*, 
               p.id as provider_id,
               u.first_name as provider_first_name,
               u.last_name as provider_last_name,
               u.profile_image as provider_profile_image,
               COALESCE((SELECT COUNT(*) FROM bookings WHERE provider_id = p.id), 0) as completed_jobs,
               (SELECT ROUND(AVG(rating), 1) FROM reviews WHERE provider_id = p.id) as avg_rating
        FROM quotes q
        JOIN providers p ON q.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE q.request_id = ?
        ORDER BY 
            CASE 
                WHEN q.status = 'accepted' THEN 1
                WHEN q.status = 'pending' THEN 2
                ELSE 3
            END,
            q.price ASC
    ");
    $quotesStmt->bind_param("i", $requestId);
    $quotesStmt->execute();
    $quotesResult = $quotesStmt->get_result();
    
    while ($row = $quotesResult->fetch_assoc()) {
        $quotes[] = $row;
    }
    
    $quotesStmt->close();
    
    // Get unread notifications count for the customer
    $notifCountQuery = "
        SELECT COUNT(*) as count FROM quote_notifications
        WHERE recipient_id = ? AND is_read = 0
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
    $errorMessage = $e->getMessage();
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

// Function to get icon for device type
function getDeviceIcon($deviceType) {
    switch (strtolower($deviceType)) {
        case 'smartphone':
            return '<i class="fas fa-mobile-alt"></i>';
        case 'laptop':
            return '<i class="fas fa-laptop"></i>';
        case 'tablet':
            return '<i class="fas fa-tablet-alt"></i>';
        case 'desktop':
            return '<i class="fas fa-desktop"></i>';
        case 'gaming':
            return '<i class="fas fa-gamepad"></i>';
        case 'tv':
            return '<i class="fas fa-tv"></i>';
        default:
            return '<i class="fas fa-microchip"></i>';
    }
}

// Function to get badge color for status
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending':
            return 'warning';
        case 'quoted':
            return 'info';
        case 'accepted':
            return 'primary';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        case 'rejected':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Function to clean a provider name
function getProviderName($firstName, $lastName) {
    $name = trim($firstName . ' ' . $lastName);
    return empty($name) ? 'Unknown Provider' : $name;
}

// Function to format rating with stars
function formatRating($rating) {
    if (!is_numeric($rating)) return 'No Ratings';
    
    $rating = round($rating * 2) / 2; // Round to nearest 0.5
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    $html = '';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star text-warning"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $html .= '<i class="fas fa-star-half-alt text-warning"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star text-warning"></i>';
    }
    
    return $html . ' <span class="rating-value">(' . number_format($rating, 1) . ')</span>';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="View quote request details and technician quotes">
    <meta name="robots" content="noindex, nofollow">
    <title>Quote Request Details - FixItNow</title>
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
        
        /* Request Detail Styles */
        .request-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .request-status {
            margin-left: auto;
        }
        
        .request-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .request-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .request-meta-item {
            display: flex;
            align-items: center;
        }
        
        .request-meta-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            border-radius: 50%;
            margin-right: 0.75rem;
        }
        
        .request-meta-content {
            display: flex;
            flex-direction: column;
        }
        
        .request-meta-label {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .request-meta-value {
            font-weight: 600;
        }
        
        .request-description {
            margin-bottom: 1.5rem;
        }
        
        /* Media Gallery */
        .media-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            grid-gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .media-item {
            aspect-ratio: 1;
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        
        .media-item:hover {
            transform: scale(1.05);
        }
        
        .media-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Quote Card Styles */
        .quote-card {
            border: 2px solid transparent;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .quote-card.accepted {
            border-color: var(--success-color);
        }
        
        .quote-card.accepted::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            border-style: solid;
            border-width: 0 40px 40px 0;
            border-color: transparent var(--success-color) transparent transparent;
        }
        
        .quote-card.accepted::after {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            position: absolute;
            top: 3px;
            right: 7px;
            color: white;
            font-size: 0.75rem;
        }
        
        .quote-header {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .provider-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .provider-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .provider-info {
            flex: 1;
        }
        
        .provider-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .provider-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .provider-meta-item {
            display: flex;
            align-items: center;
        }
        
        .provider-meta-item i {
            margin-right: 0.5rem;
        }
        
        .quote-status {
            position: absolute;
            top: 1rem;
            right: 1rem;
            z-index: 1;
        }
        
        .quote-content {
            padding: 1.5rem;
        }
        
        .quote-section {
            margin-bottom: 1.5rem;
        }
        
        .quote-section:last-child {
            margin-bottom: 0;
        }
        
        .quote-section-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .quote-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .quote-price .currency-icon {
            height: 24px;
            margin-right: 0.5rem;
        }
        
        .quote-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .quote-meta-item {
            display: flex;
            align-items: center;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            padding: 0.5rem 1rem;
            border-radius: 50rem;
        }
        
        .quote-meta-item i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .quote-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
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
        
        .status-badge.quoted {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
        }
        
        .status-badge.accepted {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        .status-badge.completed {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        .status-badge.cancelled {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        .status-badge.rejected {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        .status-badge i {
            margin-right: 0.5rem;
        }
        
        /* Rating stars */
        .rating-stars {
            color: var(--warning-color);
        }
        
        .rating-value {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .request-meta {
                flex-direction: column;
                gap: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .request-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .request-status {
                margin-left: 0;
            }
            
            .quote-header {
                flex-direction: column;
                text-align: center;
            }
            
            .provider-avatar {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .provider-meta {
                justify-content: center;
            }
            
            .quote-actions {
                flex-direction: column;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .media-gallery {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
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
        
        /* Currency icon styling */
        .currency-icon {
            display: inline-block;
            vertical-align: middle;
        }
        
        /* Lightbox */
        .lightbox {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .lightbox.show {
            display: flex;
        }
        
        .lightbox-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
        }
        
        .lightbox-img {
            max-width: 100%;
            max-height: 90vh;
            object-fit: contain;
            border: 2px solid rgba(255, 255, 255, 0.2);
            border-radius: 0.5rem;
        }
        
        .lightbox-close {
            position: absolute;
            top: -2rem;
            right: 0;
            background: transparent;
            border: none;
            color: white;
            font-size: 2rem;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .lightbox-close:hover {
            color: #dc3545;
        }
        
        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .lightbox-nav:hover {
            background: rgba(0, 0, 0, 0.8);
        }
        
        .lightbox-prev {
            left: 1rem;
        }
        
        .lightbox-next {
            right: 1rem;
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
                    
                    <!-- Notifications Button -->
                    <div class="dropdown me-3">
                        <button type="button" class="header-icon-btn" id="notificationDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo $notificationCount > 9 ? '9+' : $notificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationDropdown">
                            <h6 class="dropdown-header">Recent Notifications</h6>
                            
                            <?php if (empty($recentNotifications)): ?>
                            <div class="text-center py-3">
                                <i class="fas fa-bell-slash text-muted mb-2" style="font-size: 1.5rem;"></i>
                                <p class="text-muted mb-0 small">No notifications yet</p>
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
                                        <i class="fas fa-<?php echo $notifIcon; ?>"></i>
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
                            
                            <div class="dropdown-divider"></div>
                            <a href="notifications.php" class="dropdown-item text-center view-all">
                                View All Notifications
                            </a>
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
                <a href="quotes.php" class="mobile-menu-item active">
                    <i class="fas fa-clipboard-list"></i> Quote Requests
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
                        <a class="nav-link active" href="quotes.php">
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
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="page-title">Quote Request Details</h1>
                    <p class="text-muted">
                        View your request details and compare quotes from technicians
                    </p>
                </div>
                <a href="quotes.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Quotes
                </a>
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
            
            <?php if ($quoteRequest): ?>
            <!-- Request Details Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <div class="request-header">
                        <h5 class="mb-0">
                            <i class="fas fa-clipboard-list me-2"></i>
                            Request #<?php echo htmlspecialchars($quoteRequest['id'], ENT_QUOTES, 'UTF-8'); ?>
                        </h5>
                        <div class="request-status">
                            <span class="status-badge <?php echo htmlspecialchars($quoteRequest['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fas fa-circle me-1"></i>
                                <?php echo ucfirst(htmlspecialchars($quoteRequest['status'], ENT_QUOTES, 'UTF-8')); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <h3 class="request-title">
                        <?php echo ucfirst(htmlspecialchars($quoteRequest['device_type'], ENT_QUOTES, 'UTF-8')); ?> Repair
                        <?php if (!empty($quoteRequest['device_brand']) || !empty($quoteRequest['device_model'])): ?>
                            - <?php echo htmlspecialchars(($quoteRequest['device_brand'] ?? '') . ' ' . ($quoteRequest['device_model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </h3>
                    
                    <div class="request-meta">
                        <div class="request-meta-item">
                            <div class="request-meta-icon">
                                <?php echo getDeviceIcon($quoteRequest['device_type']); ?>
                            </div>
                            <div class="request-meta-content">
                                <div class="request-meta-label">Device Type</div>
                                <div class="request-meta-value"><?php echo ucfirst(htmlspecialchars($quoteRequest['device_type'], ENT_QUOTES, 'UTF-8')); ?></div>
                            </div>
                        </div>
                        
                        <div class="request-meta-item">
                            <div class="request-meta-icon">
                                <i class="fas fa-tag"></i>
                            </div>
                            <div class="request-meta-content">
                                <div class="request-meta-label">Condition</div>
                                <div class="request-meta-value"><?php echo ucfirst(htmlspecialchars($quoteRequest['device_condition'] ?? 'Not specified', ENT_QUOTES, 'UTF-8')); ?></div>
                            </div>
                        </div>
                        
                        <div class="request-meta-item">
                            <div class="request-meta-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="request-meta-content">
                                <div class="request-meta-label">Urgency</div>
                                <div class="request-meta-value"><?php echo ucfirst(htmlspecialchars($quoteRequest['urgency'] ?? 'Medium', ENT_QUOTES, 'UTF-8')); ?></div>
                            </div>
                        </div>
                        
                        <div class="request-meta-item">
                            <div class="request-meta-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="request-meta-content">
                                <div class="request-meta-label">Requested on</div>
                                <div class="request-meta-value"><?php echo formatDate($quoteRequest['created_at']); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Issue Description -->
                    <div class="request-description">
                        <h5>Issue Description</h5>
                        <div class="p-3 bg-light rounded">
                            <?php echo nl2br(htmlspecialchars($quoteRequest['issue_description'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    </div>
                    
                    <!-- Additional Information (if any) -->
                    <?php if (!empty($quoteRequest['additional_info'])): ?>
                    <div class="request-description">
                        <h5>Additional Information</h5>
                        <div class="p-3 bg-light rounded">
                            <?php echo nl2br(htmlspecialchars($quoteRequest['additional_info'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Media Gallery (if any) -->
                    <?php if (!empty($media)): ?>
                    <div>
                        <h5>Attached Photos</h5>
                        <div class="media-gallery">
                            <?php foreach ($media as $index => $item): ?>
                                <?php if (strpos($item['file_type'], 'image/') === 0): ?>
                                <div class="media-item" data-index="<?php echo $index; ?>" onclick="openLightbox(<?php echo $index; ?>)">
                                    <img src="../<?php echo htmlspecialchars($item['file_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Request Image">
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <div>
                        <a href="quotes.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Quotes
                        </a>
                    </div>
                    <?php if ($quoteRequest['status'] === 'pending' || $quoteRequest['status'] === 'quoted'): ?>
                    <form action="quote-detail.php?id=<?php echo $requestId; ?>" method="post" onsubmit="return confirm('Are you sure you want to cancel this quote request?');">
                        <input type="hidden" name="action" value="cancel_request">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i> Cancel Request
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Technician Quotes -->
            <h3 class="mb-3">Technician Quotes <?php if (!empty($quotes)): ?><span class="badge bg-primary ms-2"><?php echo count($quotes); ?></span><?php endif; ?></h3>
            
            <?php if (empty($quotes)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="mb-3">
                        <i class="fas fa-hourglass-half text-muted" style="font-size: 3rem;"></i>
                    </div>
                    <h4>No Quotes Yet</h4>
                    <p class="text-muted mb-4">
                        Your request has been sent to our technicians. <br>
                        Please check back later for quotes.
                    </p>
                    <a href="quotes.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Quotes
                    </a>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($quotes as $quote): ?>
                <div class="card quote-card mb-4 <?php echo $quote['status'] === 'accepted' ? 'accepted' : ''; ?>">
                    <div class="quote-header">
                        <?php
                        $providerImage = '../default.png';
                        if (!empty($quote['provider_profile_image'])) {
                            if (filter_var($quote['provider_profile_image'], FILTER_VALIDATE_URL)) {
                                $providerImage = $quote['provider_profile_image'];
                            } else {
                                $imagePath = '../profile_images/' . basename($quote['provider_profile_image']);
                                if (file_exists($imagePath) && is_file($imagePath)) {
                                    $providerImage = $imagePath;
                                }
                            }
                        }
                        ?>
                        <div class="provider-avatar">
                            <img src="<?php echo htmlspecialchars($providerImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Provider">
                        </div>
                        <div class="provider-info">
                            <h4 class="provider-name">
                                <?php echo htmlspecialchars(getProviderName($quote['provider_first_name'], $quote['provider_last_name']), ENT_QUOTES, 'UTF-8'); ?>
                            </h4>
                            <div class="provider-meta">
                                <div class="provider-meta-item">
                                    <i class="fas fa-star" aria-hidden="true"></i>
                                    <?php echo formatRating($quote['avg_rating'] ?? 0); ?>
                                </div>
                                <div class="provider-meta-item">
                                    <i class="fas fa-briefcase" aria-hidden="true"></i>
                                    <?php echo $quote['completed_jobs']; ?> repairs completed
                                </div>
                            </div>
                        </div>
                        <div class="quote-status">
                            <span class="status-badge <?php echo htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo ucfirst(htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8')); ?>
                            </span>
                        </div>
                    </div>
                    <div class="quote-content">
                        <div class="quote-price">
                            <img src="../sar/sar.png" alt="SAR" class="currency-icon">
                            <?php echo number_format((float)$quote['price'], 2); ?>
                        </div>
                        
                        <div class="quote-meta">
                            <div class="quote-meta-item">
                                <i class="fas fa-clock" aria-hidden="true"></i>
                                Estimated Time: <?php echo htmlspecialchars($quote['estimated_time'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="quote-meta-item">
                                <i class="fas fa-shield-alt" aria-hidden="true"></i>
                                Warranty: <?php echo htmlspecialchars($quote['warranty'] ?? 'No warranty', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        
                        <div class="quote-section">
                            <div class="quote-section-title">Repair Details</div>
                            <p><?php echo nl2br(htmlspecialchars($quote['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        
                        <?php if (!empty($quote['parts_needed'])): ?>
                        <div class="quote-section">
                            <div class="quote-section-title">Parts Needed</div>
                            <p><?php echo nl2br(htmlspecialchars($quote['parts_needed'], ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($quote['notes'])): ?>
                        <div class="quote-section">
                            <div class="quote-section-title">Additional Notes</div>
                            <p><?php echo nl2br(htmlspecialchars($quote['notes'], ENT_QUOTES, 'UTF-8')); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($quoteRequest['status'] === 'quoted' && $quote['status'] === 'pending'): ?>
                        <div class="quote-actions">
                            <form action="quote-detail.php?id=<?php echo $requestId; ?>" method="post" onsubmit="return confirm('Are you sure you want to accept this quote? Other quotes will be rejected.');">
                                <input type="hidden" name="action" value="accept_quote">
                                <input type="hidden" name="quote_id" value="<?php echo $quote['id']; ?>">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check me-2"></i> Accept This Quote
                                </button>
                            </form>
                            
                            <a href="messages.php?new=1&recipient_id=<?php echo (int)$quote['technician_id']; ?>&request_id=<?php echo $requestId; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-comments me-2"></i> Message Provider
                            </a>
                        </div>
                        <?php elseif ($quote['status'] === 'accepted'): ?>
                        <div class="quote-actions">
                            <a href="messages.php?new=1&recipient_id=<?php echo (int)$quote['technician_id']; ?>&request_id=<?php echo $requestId; ?>" class="btn btn-primary">
                                <i class="fas fa-comments me-2"></i> Message Provider
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <div class="mb-3">
                        <i class="fas fa-exclamation-circle text-danger" style="font-size: 3rem;"></i>
                    </div>
                    <h4>Quote Request Not Found</h4>
                    <p class="text-muted mb-4">
                        The quote request you're looking for does not exist or you do not have permission to view it.
                    </p>
                    <a href="quotes.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Quotes
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Lightbox for image gallery -->
    <div class="lightbox" id="imageLightbox">
        <div class="lightbox-content">
            <img src="" alt="Enlarged Image" class="lightbox-img" id="lightboxImage">
            <button type="button" class="lightbox-close" onclick="closeLightbox()">&times;</button>
            <button type="button" class="lightbox-nav lightbox-prev" onclick="changeImage(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <button type="button" class="lightbox-nav lightbox-next" onclick="changeImage(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
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
        
        // Image lightbox functionality
        let currentImageIndex = 0;
        const lightbox = document.getElementById('imageLightbox');
        const lightboxImage = document.getElementById('lightboxImage');
        const mediaItems = document.querySelectorAll('.media-item');
        
        function openLightbox(index) {
            currentImageIndex = index;
            updateLightboxImage();
            lightbox.classList.add('show');
            document.body.style.overflow = 'hidden'; // Prevent scrolling
        }
        
        function closeLightbox() {
            lightbox.classList.remove('show');
            document.body.style.overflow = ''; // Restore scrolling
        }
        
        function changeImage(direction) {
            currentImageIndex = (currentImageIndex + direction + mediaItems.length) % mediaItems.length;
            updateLightboxImage();
        }
        
        function updateLightboxImage() {
            if (mediaItems[currentImageIndex]) {
                const img = mediaItems[currentImageIndex].querySelector('img');
                if (img) {
                    lightboxImage.src = img.src;
                }
            }
        }
        
        // Close lightbox when clicking outside the image
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });
        
        // Keyboard navigation for lightbox
        document.addEventListener('keydown', function(e) {
            if (!lightbox.classList.contains('show')) return;
            
            if (e.key === 'Escape') {
                closeLightbox();
            } else if (e.key === 'ArrowLeft') {
                changeImage(-1);
            } else if (e.key === 'ArrowRight') {
                changeImage(1);
            }
        });
    </script>
</body>
</html>