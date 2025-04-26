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
$userInitials = 'CN'; // Default initials
$quoteRequests = [];
$errorMessage = '';
$successMessage = '';
$notificationCount = 0;
$recentNotifications = [];

// Pagination variables
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$recordsPerPage = 10;
$offset = ($currentPage - 1) * $recordsPerPage;
$totalRecords = 0;
$totalPages = 0;

// Filter variables
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$deviceFilter = isset($_GET['device']) ? $_GET['device'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Handle submission of new quote request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_quote_request') {
    try {
        // Validate inputs
        $deviceType = isset($_POST['device_type']) ? trim($_POST['device_type']) : '';
        $deviceBrand = isset($_POST['device_brand']) ? trim($_POST['device_brand']) : '';
        $deviceModel = isset($_POST['device_model']) ? trim($_POST['device_model']) : '';
        $issueDescription = isset($_POST['issue_description']) ? trim($_POST['issue_description']) : '';
        
        // Basic validation
        if (empty($deviceType)) {
            throw new Exception('Device type is required.');
        }
        
        if (empty($issueDescription)) {
            throw new Exception('Issue description is required.');
        }
        
        // Optional fields
        $deviceCondition = isset($_POST['device_condition']) ? trim($_POST['device_condition']) : 'good';
        $urgency = isset($_POST['urgency']) ? trim($_POST['urgency']) : 'medium';
        $additionalInfo = isset($_POST['additional_info']) ? trim($_POST['additional_info']) : '';
        
        // Insert into quote_requests table
        $insertStmt = $conn->prepare("INSERT INTO quote_requests (
            customer_id, 
            device_type, 
            device_brand, 
            device_model, 
            issue_description, 
            device_condition, 
            urgency, 
            additional_info, 
            status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        $insertStmt->bind_param(
            "isssssss", 
            $userId, 
            $deviceType, 
            $deviceBrand, 
            $deviceModel, 
            $issueDescription, 
            $deviceCondition, 
            $urgency, 
            $additionalInfo
        );
        
        if ($insertStmt->execute()) {
            $quoteRequestId = $insertStmt->insert_id;
            $insertStmt->close();
            
            // Handle file uploads if any
            if (!empty($_FILES['quote_files']['name'][0])) {
                $uploadDir = '../uploads/request_images/';
                
                // Create directory if it doesn't exist
                if (!file_exists($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                
                // Loop through each file
                foreach ($_FILES['quote_files']['name'] as $key => $fileName) {
                    if ($_FILES['quote_files']['error'][$key] === UPLOAD_ERR_OK) {
                        $tempFile = $_FILES['quote_files']['tmp_name'][$key];
                        $fileType = $_FILES['quote_files']['type'][$key];
                        $fileSize = $_FILES['quote_files']['size'][$key];
                        
                        // Validate file type (only images)
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/jpg'];
                        if (!in_array($fileType, $allowedTypes)) {
                            continue; // Skip files that are not images
                        }
                        
                        // Validate file size (max 5MB)
                        if ($fileSize > 5 * 1024 * 1024) {
                            continue; // Skip files larger than 5MB
                        }
                        
                        // Generate a unique filename
                        $uniqueFilename = uniqid() . '_' . basename($fileName);
                        $targetFile = $uploadDir . $uniqueFilename;
                        
                        // Move the file
                        if (move_uploaded_file($tempFile, $targetFile)) {
                            // Insert into quote_request_media table
                            $mediaStmt = $conn->prepare("INSERT INTO quote_request_media (
                                request_id, 
                                file_name, 
                                original_name, 
                                file_path, 
                                file_type, 
                                media_type
                            ) VALUES (?, ?, ?, ?, ?, 'image')");
                            
                            $filePath = 'uploads/request_images/' . $uniqueFilename;
                            
                            $mediaStmt->bind_param(
                                "issss", 
                                $quoteRequestId, 
                                $uniqueFilename, 
                                $fileName, 
                                $filePath, 
                                $fileType
                            );
                            
                            $mediaStmt->execute();
                            $mediaStmt->close();
                        }
                    }
                }
            }
            
            // Use stored procedure to create notifications for providers
            $notifyStmt = $conn->prepare("CALL create_quote_notifications(?, ?, ?)");
            $notifyStmt->bind_param("iss", $userId, $deviceType, $issueDescription);
            $notifyStmt->execute();
            $notifyStmt->close();
            
            $successMessage = 'Your quote request has been submitted successfully! Technicians will respond shortly.';
        } else {
            throw new Exception('Failed to submit quote request. Please try again.');
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Handle cancellation of quote request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_quote_request') {
    try {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        
        if ($requestId <= 0) {
            throw new Exception('Invalid request ID.');
        }
        
        // Verify the quote request belongs to this customer
        $checkStmt = $conn->prepare("SELECT id FROM quote_requests WHERE id = ? AND customer_id = ? AND status = 'pending'");
        $checkStmt->bind_param("ii", $requestId, $userId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception('Quote request not found or cannot be cancelled.');
        }
        
        $checkStmt->close();
        
        // Update status to cancelled
        $updateStmt = $conn->prepare("UPDATE quote_requests SET status = 'cancelled' WHERE id = ? AND customer_id = ?");
        $updateStmt->bind_param("ii", $requestId, $userId);
        
        if ($updateStmt->execute()) {
            $updateStmt->close();
            $successMessage = 'Quote request has been cancelled successfully.';
        } else {
            throw new Exception('Failed to cancel quote request. Please try again.');
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
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
    
    // Count total number of quote requests for pagination
    $countQuery = "SELECT COUNT(*) as total FROM quote_requests WHERE customer_id = ?";
    $countParams = [$userId];
    
    // Apply filters to count
    if ($statusFilter !== 'all') {
        $countQuery .= " AND status = ?";
        $countParams[] = $statusFilter;
    }
    
    if ($deviceFilter !== 'all') {
        $countQuery .= " AND device_type = ?";
        $countParams[] = $deviceFilter;
    }
    
    if (!empty($search)) {
        $countQuery .= " AND (device_brand LIKE ? OR device_model LIKE ? OR issue_description LIKE ?)";
        $searchTerm = "%$search%";
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
        $countParams[] = $searchTerm;
    }
    
    $countStmt = $conn->prepare($countQuery);
    
    // Bind parameters dynamically
    if (!empty($countParams)) {
        $countTypes = str_repeat('s', count($countParams));
        $countTypes[0] = 'i'; // First parameter is always userId (integer)
        $countStmt->bind_param($countTypes, ...$countParams);
    }
    
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRecords = $countResult->fetch_assoc()['total'];
    $countStmt->close();
    
    $totalPages = ceil($totalRecords / $recordsPerPage);
    
    // Ensure current page is within valid range
    if ($currentPage < 1) {
        $currentPage = 1;
    } elseif ($currentPage > $totalPages && $totalPages > 0) {
        $currentPage = $totalPages;
    }
    
    // Build query to get quote requests
    $query = "
        SELECT qr.*, 
               COUNT(DISTINCT q.id) as quote_count,
               MAX(q.price) as highest_quote,
               MIN(q.price) as lowest_quote
        FROM quote_requests qr
        LEFT JOIN quotes q ON qr.id = q.request_id
        WHERE qr.customer_id = ?
    ";
    
    $params = [$userId];
    
    // Apply filters
    if ($statusFilter !== 'all') {
        $query .= " AND qr.status = ?";
        $params[] = $statusFilter;
    }
    
    if ($deviceFilter !== 'all') {
        $query .= " AND qr.device_type = ?";
        $params[] = $deviceFilter;
    }
    
    if (!empty($search)) {
        $query .= " AND (qr.device_brand LIKE ? OR qr.device_model LIKE ? OR qr.issue_description LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $query .= " GROUP BY qr.id ORDER BY qr.created_at DESC LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $recordsPerPage;
    
    $stmt = $conn->prepare($query);
    
    // Bind parameters dynamically
    if (!empty($params)) {
        $types = str_repeat('s', count($params));
        $types[0] = 'i'; // First parameter is always userId (integer)
        
        // Last two parameters are always limit parameters (integers)
        $types[count($params) - 2] = 'i';
        $types[count($params) - 1] = 'i';
        
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $quoteRequests = [];
    
    while ($row = $result->fetch_assoc()) {
        // Get the media for each quote request
        $mediaQuery = "SELECT * FROM quote_request_media WHERE request_id = ? LIMIT 1";
        $mediaStmt = $conn->prepare($mediaQuery);
        $mediaStmt->bind_param("i", $row['id']);
        $mediaStmt->execute();
        $mediaResult = $mediaStmt->get_result();
        $mediaRow = $mediaResult->fetch_assoc();
        $mediaStmt->close();
        
        if ($mediaRow) {
            $row['media'] = $mediaRow;
        } else {
            $row['media'] = null;
        }
        
        $quoteRequests[] = $row;
    }
    
    $stmt->close();
    
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
        default:
            return 'secondary';
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Customer quote requests for FixItNow service booking platform">
    <meta name="robots" content="noindex, nofollow">
    <title>Quote Requests - FixItNow</title>
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
        
        /* Quote Request Cards */
        .quote-request-card {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .quote-request-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .quote-img-wrap {
            position: relative;
            height: 180px;
            overflow: hidden;
            background-color: #f8f9fa;
        }
        
        [data-bs-theme="dark"] .quote-img-wrap {
            background-color: #343a40;
        }
        
        .quote-img-wrap img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .quote-request-card:hover .quote-img-wrap img {
            transform: scale(1.05);
        }
        
        .quote-device-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background-color: var(--primary-color);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quote-status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .quote-status-badge.pending {
            background-color: var(--warning-color);
            color: #212529;
        }
        
        .quote-status-badge.quoted {
            background-color: var(--primary-color);
            color: white;
        }
        
        .quote-status-badge.accepted {
            background-color: var(--success-color);
            color: white;
        }
        
        .quote-status-badge.completed {
            background-color: var(--success-color);
            color: white;
        }
        
        .quote-status-badge.cancelled {
            background-color: var(--danger-color);
            color: white;
        }
        
        .quote-content {
            padding: 1.5rem;
        }
        
        .quote-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            line-height: 1.3;
        }
        
        .quote-meta {
            margin-bottom: 1rem;
        }
        
        .quote-meta-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .quote-meta-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            border-radius: 50%;
        }
        
        .quote-meta-text {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .quote-description {
            margin-bottom: 1.5rem;
            max-height: 100px;
            overflow: hidden;
            position: relative;
        }
        
        .quote-description.truncated::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 40px;
            background: linear-gradient(to bottom, transparent, var(--card-bg));
        }
        
        .quote-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .quote-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        /* Filter card */
        .filter-card {
            background-color: var(--card-bg);
            padding: 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            opacity: 0.2;
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .pagination .page-link:hover {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
        }
        
        /* Device type filter pills */
        .device-filter {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .device-filter i {
            margin-right: 0.35rem;
        }
        
        .device-filter.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .device-filter:not(.active) {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
        }
        
        .device-filter:hover {
            transform: translateY(-2px);
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
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .filter-wrap {
                flex-direction: column;
            }
            
            .filter-wrap .form-group {
                width: 100%;
                margin-bottom: 1rem;
            }
            
            .nav-button {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
            
            .nav-button i {
                margin-right: 0.3rem;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .quote-img-wrap {
                height: 150px;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .quote-title {
                font-size: 1.1rem;
            }
            
            .quote-price {
                font-size: 1.25rem;
            }
            
            .quote-actions {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .quote-actions .btn {
                width: 100%;
            }
        }
        
        /* File Upload Styles */
        .file-upload {
            position: relative;
            padding: 1.5rem;
            border: 2px dashed var(--border-color);
            border-radius: 0.5rem;
            text-align: center;
            transition: all 0.3s ease;
            background-color: rgba(0, 0, 0, 0.02);
            margin-bottom: 1rem;
        }
        
        .file-upload.drag-over {
            border-color: var(--primary-color);
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .file-upload-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .file-upload-text {
            margin-bottom: 0.5rem;
        }
        
        .file-upload-subtext {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .file-upload-input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        .file-list {
            margin-top: 1rem;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: 0.375rem;
            margin-bottom: 0.5rem;
        }
        
        .file-item-icon {
            margin-right: 0.75rem;
            color: var(--primary-color);
        }
        
        .file-item-name {
            flex: 1;
            font-size: 0.875rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .file-item-size {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-right: 0.75rem;
        }
        
        .file-item-remove {
            color: var(--danger-color);
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        
        .file-item-remove:hover {
            opacity: 1;
        }
        
        .preview-thumbnails {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .preview-thumbnail {
            width: 80px;
            height: 80px;
            border-radius: 0.375rem;
            overflow: hidden;
            position: relative;
        }
        
        .preview-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .preview-thumbnail-remove {
            position: absolute;
            top: 0.25rem;
            right: 0.25rem;
            width: 20px;
            height: 20px;
            background-color: rgba(0, 0, 0, 0.5);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.2s;
        }
        
        .preview-thumbnail:hover .preview-thumbnail-remove {
            opacity: 1;
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
                    <h1 class="page-title">Quote Requests</h1>
                    <p class="text-muted">Request quotes for device repairs and view technician responses</p>
                </div>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quoteRequestModal">
                    <i class="fas fa-plus me-2"></i> New Quote Request
                </button>
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
            
            <!-- Filter section -->
            <div class="filter-card">
                <form action="quotes.php" method="get" class="row g-3">
                    <div class="col-md-4">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="quoted" <?php echo $statusFilter === 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                            <option value="accepted" <?php echo $statusFilter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                            <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="device" class="form-label">Device Type</label>
                        <select class="form-select" id="device" name="device">
                            <option value="all" <?php echo $deviceFilter === 'all' ? 'selected' : ''; ?>>All Devices</option>
                            <option value="smartphone" <?php echo $deviceFilter === 'smartphone' ? 'selected' : ''; ?>>Smartphone</option>
                            <option value="laptop" <?php echo $deviceFilter === 'laptop' ? 'selected' : ''; ?>>Laptop</option>
                            <option value="tablet" <?php echo $deviceFilter === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                            <option value="desktop" <?php echo $deviceFilter === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                            <option value="gaming" <?php echo $deviceFilter === 'gaming' ? 'selected' : ''; ?>>Gaming Console</option>
                            <option value="tv" <?php echo $deviceFilter === 'tv' ? 'selected' : ''; ?>>TV</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="search" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <?php if (empty($quoteRequests)): ?>
            <!-- Empty state -->
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                    </div>
                    <h3 class="empty-state-title">No Quote Requests Found</h3>
                    <p class="empty-state-text">
                        <?php if (!empty($search) || $statusFilter !== 'all' || $deviceFilter !== 'all'): ?>
                            No quote requests match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            You haven't submitted any quote requests yet. Click the "New Quote Request" button to get started.
                        <?php endif; ?>
                    </p>
                    <?php if (!empty($search) || $statusFilter !== 'all' || $deviceFilter !== 'all'): ?>
                        <a href="quotes.php" class="btn btn-outline-primary">Clear Filters</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quoteRequestModal">
                            <i class="fas fa-plus me-2"></i> New Quote Request
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- Quote requests list -->
            <div class="row">
                <?php foreach ($quoteRequests as $request): ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="card quote-request-card h-100">
                        <div class="quote-img-wrap">
                            <?php if ($request['media']): ?>
                                <img src="../<?php echo htmlspecialchars($request['media']['file_path'], ENT_QUOTES, 'UTF-8'); ?>" alt="Device Image">
                            <?php else: ?>
                                <img src="../assets/images/default-device.jpg" alt="Default Device Image">
                            <?php endif; ?>
                            <div class="quote-device-badge">
                                <?php echo getDeviceIcon($request['device_type']); ?>
                                <?php echo ucfirst(htmlspecialchars($request['device_type'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                            <div class="quote-status-badge <?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo ucfirst(htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        </div>
                        <div class="quote-content">
                            <h3 class="quote-title">
                                <?php echo ucfirst(htmlspecialchars($request['device_type'], ENT_QUOTES, 'UTF-8')); ?> Repair
                                <?php if (!empty($request['device_brand']) || !empty($request['device_model'])): ?>
                                    - <?php echo htmlspecialchars(($request['device_brand'] ?? '') . ' ' . ($request['device_model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                            </h3>
                            <div class="quote-meta">
                                <div class="quote-meta-item">
                                    <div class="quote-meta-icon">
                                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                    </div>
                                    <div class="quote-meta-text">
                                        Requested on <?php echo formatDate($request['created_at']); ?>
                                    </div>
                                </div>
                                <div class="quote-meta-item">
                                    <div class="quote-meta-icon">
                                        <i class="fas fa-tag" aria-hidden="true"></i>
                                    </div>
                                    <div class="quote-meta-text">
                                        Condition: <?php echo ucfirst(htmlspecialchars($request['device_condition'] ?? 'Not specified', ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                </div>
                                <div class="quote-meta-item">
                                    <div class="quote-meta-icon">
                                        <i class="fas fa-clock" aria-hidden="true"></i>
                                    </div>
                                    <div class="quote-meta-text">
                                        Urgency: <?php echo ucfirst(htmlspecialchars($request['urgency'] ?? 'Medium', ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="quote-description <?php echo strlen($request['issue_description']) > 150 ? 'truncated' : ''; ?>">
                                <?php echo nl2br(htmlspecialchars($request['issue_description'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                            <?php if ($request['quote_count'] > 0): ?>
                            <div class="quote-price">
                                <?php if ($request['lowest_quote'] == $request['highest_quote']): ?>
                                    <?php echo formatPrice($request['lowest_quote']); ?>
                                <?php else: ?>
                                    <?php echo formatPrice($request['lowest_quote']); ?> - <?php echo formatPrice($request['highest_quote']); ?>
                                <?php endif; ?>
                                <span class="badge rounded-pill bg-primary ms-2">
                                    <?php echo $request['quote_count']; ?> Quote<?php echo $request['quote_count'] > 1 ? 's' : ''; ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <div class="quote-price text-muted">
                                <i class="fas fa-hourglass-half me-2" aria-hidden="true"></i> Awaiting quotes from technicians
                            </div>
                            <?php endif; ?>
                            <div class="quote-actions">
                                <a href="quote-detail.php?id=<?php echo (int)$request['id']; ?>" class="btn btn-primary">
                                    <i class="fas fa-eye me-2" aria-hidden="true"></i> View Details
                                </a>
                                <?php if ($request['status'] === 'pending'): ?>
                                <form action="quotes.php" method="post" onsubmit="return confirm('Are you sure you want to cancel this quote request?');">
                                    <input type="hidden" name="action" value="cancel_quote_request">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger">
                                        <i class="fas fa-times me-2" aria-hidden="true"></i> Cancel
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="card">
                <div class="card-body">
                    <nav aria-label="Quote requests pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="quotes.php?page=1&status=<?php echo urlencode($statusFilter); ?>&device=<?php echo urlencode($deviceFilter); ?>&search=<?php echo urlencode($search); ?>" aria-label="First">
                                    <span aria-hidden="true">&laquo;&laquo;</span>
                                </a>
                            </li>
                            <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="quotes.php?page=<?php echo $currentPage - 1; ?>&status=<?php echo urlencode($statusFilter); ?>&device=<?php echo urlencode($deviceFilter); ?>&search=<?php echo urlencode($search); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            
                            <?php
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($totalPages, $currentPage + 2);
                            
                            if ($startPage > 1) {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++) {
                                echo '<li class="page-item ' . ($i === $currentPage ? 'active' : '') . '">';
                                echo '<a class="page-link" href="quotes.php?page=' . $i . '&status=' . urlencode($statusFilter) . '&device=' . urlencode($deviceFilter) . '&search=' . urlencode($search) . '">' . $i . '</a>';
                                echo '</li>';
                            }
                            
                            if ($endPage < $totalPages) {
                                echo '<li class="page-item disabled"><a class="page-link" href="#">...</a></li>';
                            }
                            ?>
                            
                            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="quotes.php?page=<?php echo $currentPage + 1; ?>&status=<?php echo urlencode($statusFilter); ?>&device=<?php echo urlencode($deviceFilter); ?>&search=<?php echo urlencode($search); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="quotes.php?page=<?php echo $totalPages; ?>&status=<?php echo urlencode($statusFilter); ?>&device=<?php echo urlencode($deviceFilter); ?>&search=<?php echo urlencode($search); ?>" aria-label="Last">
                                    <span aria-hidden="true">&raquo;&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Quote Request Modal -->
    <div class="modal fade" id="quoteRequestModal" tabindex="-1" aria-labelledby="quoteRequestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quoteRequestModalLabel">New Quote Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="quotes.php" method="post" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="submit_quote_request">
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="device_type" class="form-label">Device Type <span class="text-danger">*</span></label>
                                <select class="form-select" id="device_type" name="device_type" required>
                                    <option value="" selected disabled>Select device type</option>
                                    <option value="smartphone">Smartphone</option>
                                    <option value="laptop">Laptop</option>
                                    <option value="tablet">Tablet</option>
                                    <option value="desktop">Desktop</option>
                                    <option value="gaming">Gaming Console</option>
                                    <option value="tv">TV</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="device_brand" class="form-label">Brand</label>
                                <input type="text" class="form-control" id="device_brand" name="device_brand" placeholder="e.g. Apple, Samsung, HP...">
                            </div>
                            <div class="col-md-4">
                                <label for="device_model" class="form-label">Model</label>
                                <input type="text" class="form-control" id="device_model" name="device_model" placeholder="e.g. iPhone 13, Galaxy S21...">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="device_condition" class="form-label">Device Condition</label>
                                <select class="form-select" id="device_condition" name="device_condition">
                                    <option value="good" selected>Good</option>
                                    <option value="fair">Fair</option>
                                    <option value="poor">Poor</option>
                                    <option value="damaged">Damaged</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="urgency" class="form-label">Urgency</label>
                                <select class="form-select" id="urgency" name="urgency">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="issue_description" class="form-label">Issue Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="issue_description" name="issue_description" rows="5" placeholder="Describe the issue with your device in detail..." required></textarea>
                            <div class="form-text">
                                Be specific about the problems you're experiencing to get more accurate quotes.
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="additional_info" class="form-label">Additional Information</label>
                            <textarea class="form-control" id="additional_info" name="additional_info" rows="3" placeholder="Any additional details that might help..."></textarea>
                        </div>
                        
                        <!-- File Upload Section -->
                        <div class="mb-3">
                            <label class="form-label">Upload Images (Optional)</label>
                            <div class="file-upload" id="dropArea">
                                <div class="file-upload-icon">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                </div>
                                <div class="file-upload-text">
                                    <strong>Drag and drop files here</strong> or click to browse
                                </div>
                                <div class="file-upload-subtext">
                                    Upload images of your device to help technicians better understand the issue.
                                </div>
                                <input type="file" name="quote_files[]" id="fileInput" class="file-upload-input" multiple accept="image/*">
                            </div>
                            <div id="previewContainer" class="preview-thumbnails" style="display: none;"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
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
            
            // File upload preview
            const fileInput = document.getElementById('fileInput');
            const dropArea = document.getElementById('dropArea');
            const previewContainer = document.getElementById('previewContainer');
            
            // Prevent default drag behaviors
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, preventDefaults, false);
                document.body.addEventListener(eventName, preventDefaults, false);
            });
            
            // Highlight drop area when dragging over it
            ['dragenter', 'dragover'].forEach(eventName => {
                dropArea.addEventListener(eventName, highlight, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dropArea.addEventListener(eventName, unhighlight, false);
            });
            
            // Handle dropped files
            dropArea.addEventListener('drop', handleDrop, false);
            
            // Handle selected files via input
            fileInput.addEventListener('change', function() {
                handleFiles(this.files);
            });
            
            function preventDefaults(e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            function highlight() {
                dropArea.classList.add('drag-over');
            }
            
            function unhighlight() {
                dropArea.classList.remove('drag-over');
            }
            
            function handleDrop(e) {
                const dt = e.dataTransfer;
                const files = dt.files;
                handleFiles(files);
            }
            
            function handleFiles(files) {
                if (files.length > 0) {
                    previewContainer.style.display = 'flex';
                }
                
                [...files].forEach(previewFile);
            }
            
            function previewFile(file) {
                // Only process image files
                if (!file.type.match('image.*')) {
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const thumbnail = document.createElement('div');
                    thumbnail.className = 'preview-thumbnail';
                    
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    thumbnail.appendChild(img);
                    
                    const removeBtn = document.createElement('div');
                    removeBtn.className = 'preview-thumbnail-remove';
                    removeBtn.innerHTML = '<i class="fas fa-times"></i>';
                    removeBtn.addEventListener('click', function() {
                        thumbnail.remove();
                        
                        // Hide container if no more previews
                        if (previewContainer.children.length === 0) {
                            previewContainer.style.display = 'none';
                        }
                    });
                    
                    thumbnail.appendChild(removeBtn);
                    previewContainer.appendChild(thumbnail);
                };
                
                reader.readAsDataURL(file);
            }
            
            // Form validation
            const quoteRequestForm = document.querySelector('#quoteRequestModal form');
            quoteRequestForm.addEventListener('submit', function(e) {
                const deviceType = document.getElementById('device_type');
                const issueDescription = document.getElementById('issue_description');
                
                let valid = true;
                
                if (!deviceType.value) {
                    deviceType.classList.add('is-invalid');
                    valid = false;
                } else {
                    deviceType.classList.remove('is-invalid');
                }
                
                if (!issueDescription.value.trim()) {
                    issueDescription.classList.add('is-invalid');
                    valid = false;
                } else {
                    issueDescription.classList.remove('is-invalid');
                }
                
                if (!valid) {
                    e.preventDefault();
                }
            });
            
            // Auto-submit filter form when select options change
            document.getElementById('status').addEventListener('change', function() {
                this.form.submit();
            });
            
            document.getElementById('device').addEventListener('change', function() {
                this.form.submit();
            });
        });
    </script>
</body>
</html>