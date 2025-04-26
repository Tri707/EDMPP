<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Authentication check
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin' || $adminId <= 0) {
    header('Location: ../login.php');
    exit;
}

// Include database connection
require_once 'conn.php';

// Initialize variables
$adminData = [];
$adminProfileImage = '../default.png';
$userData = [];
$userBookings = [];
$errorMessage = '';
$successMessage = '';
$statusFilter = '';
$startDate = '';
$endDate = '';
$totalBookings = 0;
$totalPages = 1;
$prevPage = null;
$nextPage = null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

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
    // Query to get admin user data with proper validation
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$adminId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$adminData) {
        // If admin data doesn't exist, log them out
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=invalid_session');
        exit;
    }
    
    // Set profile image path with proper validation
    if (!empty($adminData['profile_image'])) {
        if (filter_var($adminData['profile_image'], FILTER_VALIDATE_URL)) {
            // URL-based image with validation
            $adminProfileImage = $adminData['profile_image'];
        } else {
            // File-based image with protection against directory traversal
            $imageFile = basename($adminData['profile_image']);
            $imagePath = '../profile_images/' . $imageFile;
            if (file_exists($imagePath) && is_file($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
    $errorMessage = "System error: Unable to fetch admin data.";
}

// Get user ID from query string and validate
$userId = isset($_GET['user_id']) ? filter_var($_GET['user_id'], FILTER_VALIDATE_INT) : 0;

if ($userId <= 0) {
    header('Location: users.php');
    exit;
}

// Process booking status update with CSRF protection and enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking_status'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Security validation failed. Please try again.";
        // Redirect immediately when CSRF validation fails
        $redirectParams = $_GET;
        $queryString = http_build_query($redirectParams);
        header("Location: user-bookings.php?$queryString");
        exit;
    }
    
    // Process form data with validation
    $bookingId = isset($_POST['booking_id']) ? filter_var($_POST['booking_id'], FILTER_VALIDATE_INT) : 0;
    $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    if ($bookingId <= 0) {
        $_SESSION['error_message'] = "Invalid booking ID.";
    } elseif (!in_array($newStatus, ['pending', 'confirmed', 'completed', 'cancelled'])) {
        $_SESSION['error_message'] = "Invalid status value.";
    } else {
        try {
            // Start transaction for data consistency
            $pdo->beginTransaction();
            
            // Check if the booking exists first
            $checkStmt = $pdo->prepare("SELECT id, status FROM bookings WHERE id = ?");
            $checkStmt->execute([$bookingId]);
            $booking = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) {
                throw new PDOException("Booking with ID $bookingId not found");
            }
            
            $currentStatus = $booking['status'];
            
            // Update booking status using prepared statement
            $updateStmt = $pdo->prepare("
                UPDATE bookings 
                SET status = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            
            $updateResult = $updateStmt->execute([$newStatus, $bookingId]);
            
            if (!$updateResult) {
                throw new PDOException("Update statement failed: " . implode(", ", $updateStmt->errorInfo()));
            }
            
            $rowsAffected = $updateStmt->rowCount();
            
            // Insert into booking status history - FIXED: Removed previous_status column
            $historyStmt = $pdo->prepare("
                INSERT INTO booking_status_history 
                (booking_id, status, created_by, user_id, notes, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $safeNotes = empty($notes) ? "Status updated via admin panel" : "Status updated via admin panel: " . $notes;
            // Modified to remove current status parameter
            $historyResult = $historyStmt->execute([
                $bookingId, 
                $newStatus, 
                'admin', 
                $adminId, 
                $safeNotes
            ]);
            
            if (!$historyResult) {
                throw new PDOException("History insert failed: " . implode(", ", $historyStmt->errorInfo()));
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Set success message
            if ($rowsAffected > 0) {
                $_SESSION['success_message'] = "Booking status successfully updated from $currentStatus to $newStatus.";
            } else if ($currentStatus === $newStatus) {
                $_SESSION['success_message'] = "No change in booking status (already $currentStatus).";
            } else {
                $_SESSION['success_message'] = "Booking status set to $newStatus.";
            }
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Database error updating booking status: " . $e->getMessage());
            $_SESSION['error_message'] = "Database error: " . $e->getMessage();
        } catch (Exception $e) {
            // Handle other exceptions
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("General error updating booking status: " . $e->getMessage());
            $_SESSION['error_message'] = "An error occurred during processing.";
        }
    }
    
    // Redirect back to the same page to prevent form resubmission
    // Maintain all GET parameters
    $redirectParams = $_GET;
    $queryString = http_build_query($redirectParams);
    
    header("Location: user-bookings.php?$queryString");
    exit;
}

// Get user information
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               CASE WHEN u.role = 'provider' THEN p.id ELSE NULL END as provider_id
        FROM users u
        LEFT JOIN providers p ON u.id = p.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        header('Location: users.php?error=user_not_found');
        exit;
    }
    
    // Set user profile image with proper validation
    $userProfileImage = '../default.png';
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
    
    // Validate status filter using whitelist
    $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
    $statusFilter = isset($_GET['status']) && in_array($_GET['status'], $validStatuses) ? $_GET['status'] : '';
    
    // Validate date inputs with regex pattern matching
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    $startDate = isset($_GET['start_date']) && preg_match($datePattern, $_GET['start_date']) ? $_GET['start_date'] : '';
    $endDate = isset($_GET['end_date']) && preg_match($datePattern, $_GET['end_date']) ? $_GET['end_date'] : '';
    
    // Additional date validation
    if (!empty($startDate) && !empty($endDate)) {
        $startDateObj = new DateTime($startDate);
        $endDateObj = new DateTime($endDate);
        
        // Ensure start date is before end date
        if ($startDateObj > $endDateObj) {
            $temp = $startDate;
            $startDate = $endDate;
            $endDate = $temp;
        }
    }
    
    // Pagination setup
    $offset = ($page - 1) * $perPage;
    
    // Build query based on user role with proper table joining
    if ($userData['role'] === 'customer') {
        $query = "
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
        ";
        
        $countQuery = "
            SELECT COUNT(*) 
            FROM bookings 
            WHERE customer_id = ?
        ";
        
        $params = [$userId];
    } else {
        // For provider users
        $providerId = $userData['provider_id'] ?? 0;
        
        if ($providerId <= 0) {
            $errorMessage = "Invalid provider information. Please contact support.";
            $userBookings = [];
        } else {
            $query = "
                SELECT b.*, 
                       c.first_name as customer_first_name, 
                       c.last_name as customer_last_name,
                       c.phone as customer_phone,
                       s.name as service_name,
                       s.price as service_price
                FROM bookings b
                JOIN users c ON b.customer_id = c.id
                LEFT JOIN services s ON b.service_id = s.id
                WHERE b.provider_id = ?
            ";
            
            $countQuery = "
                SELECT COUNT(*) 
                FROM bookings 
                WHERE provider_id = ?
            ";
            
            $params = [$providerId];
        }
    }
    
    // Only proceed if we have valid query parameters
    if (!empty($query) && !empty($params)) {
        // Add status filter if provided
        if (!empty($statusFilter)) {
            $query .= " AND b.status = ?";
            $countQuery .= " AND status = ?";
            $params[] = $statusFilter;
        }
        
        // Add date range filter if provided
        if (!empty($startDate)) {
            $query .= " AND b.booking_date >= ?";
            $countQuery .= " AND booking_date >= ?";
            $params[] = $startDate;
        }
        
        if (!empty($endDate)) {
            $query .= " AND b.booking_date <= ?";
            $countQuery .= " AND booking_date <= ?";
            $params[] = $endDate;
        }
        
        // Add sorting and pagination
        $query .= " ORDER BY b.booking_date DESC, b.booking_time DESC LIMIT ? OFFSET ?";
        $queryParams = $params;
        $queryParams[] = $perPage;
        $queryParams[] = $offset;
        
        // Execute the queries with improved error handling
        $stmt = $pdo->prepare($query);
        $stmt->execute($queryParams);
        $userBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count for pagination
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params); // Use original params without limit and offset
        $totalBookings = (int)$countStmt->fetchColumn();
        
        // Calculate pagination values
        $totalPages = ceil($totalBookings / $perPage);
        $prevPage = ($page > 1) ? $page - 1 : null;
        $nextPage = ($page < $totalPages) ? $page + 1 : null;
    }
    
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching user data. Please try again later.";
    $userBookings = [];
}

// Build pagination URL with improved security and parameter handling
function buildPaginationUrl($page, $currentParams = []) {
    $params = $_GET;
    $params['page'] = (int)$page; // Ensure the page is an integer
    
    if (!empty($currentParams)) {
        foreach ($currentParams as $key => $value) {
            $params[$key] = $value;
        }
    }
    
    // Filter out null and empty values
    $params = array_filter($params, function($value) { 
        return $value !== null && $value !== ''; 
    });
    
    // Use proper URL encoding for URL parameters
    return '?' . http_build_query($params);
}

// Function to safely output dates
function formatDate($dateString, $format = 'M d, Y') {
    if (empty($dateString)) return 'N/A';
    
    try {
        $date = new DateTime($dateString);
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// Function to safely output time
function formatTime($timeString, $format = 'h:i A') {
    if (empty($timeString)) return 'N/A';
    
    try {
        $date = new DateTime($timeString);
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Time';
    }
}

// Function to safely format prices - UPDATED to use currency image
function formatPrice($price, $currencyImgPath = 'sar/sar.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    // Use the image path for currency display
    $currencyImg = '<img src="' . htmlspecialchars($currencyImgPath, ENT_QUOTES, 'UTF-8') . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Admin panel for managing user bookings">
    <meta name="robots" content="noindex, nofollow">
    <title>User Bookings - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Date Range Picker CSS -->
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />
    
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
            --danger-color: #dc3545;       /* Danger/red color */
            --danger-light: #f8d7da;       /* Light danger background */
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
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --danger-light: #482930;       /* Darker danger background for dark mode */
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
        
        /* Role badges */
        .role-badge {
            display: inline-flex;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.375rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-badge.admin {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        .role-badge.provider {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
        }
        
        .role-badge.customer {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
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
            border: none;
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
        
        /* Date picker customization */
        .daterangepicker {
            background-color: var(--card-bg);
            border-color: var(--border-color);
            color: var(--text-color);
        }
        
        .daterangepicker .calendar-table {
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .daterangepicker td.available:hover, 
        .daterangepicker th.available:hover {
            background-color: var(--primary-light);
        }
        
        .daterangepicker td.active, 
        .daterangepicker td.active:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .daterangepicker .drp-buttons {
            border-top-color: var(--border-color);
        }
        
        .daterangepicker .drp-selected {
            color: var(--text-muted);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 500px;
            margin: 0 auto 1.5rem;
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
        
        .action-btn:focus,
        .theme-toggle-btn:focus,
        .btn:focus {
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.5);
            outline: none;
        }
        
        /* Focus visible styles for keyboard navigation */
        .action-btn:focus-visible,
        .theme-toggle-btn:focus-visible,
        .btn:focus-visible,
        .nav-link:focus-visible,
        .form-control:focus-visible,
        .form-select:focus-visible {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
        
        /* Currency icon styling */
        .currency-icon {
            display: inline-block;
            vertical-align: middle;
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
                        <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-danger">Admin Panel</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon" aria-hidden="true"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn && isset($adminData['first_name'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($adminProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($adminData['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2" aria-hidden="true"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2" aria-hidden="true"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout</a></li>
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
                            <span class="nav-icon"><i class="fas fa-tachometer-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="users.php">
                            <span class="nav-icon"><i class="fas fa-users" aria-hidden="true"></i></span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="technicians.php">
                            <span class="nav-icon"><i class="fas fa-user-cog" aria-hidden="true"></i></span>
                            <span class="nav-text">Technicians</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs" aria-hidden="true"></i></span>
                            <span class="nav-text">Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star" aria-hidden="true"></i></span>
                            <span class="nav-text">Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar" aria-hidden="true"></i></span>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog" aria-hidden="true"></i></span>
                            <span class="nav-text">Settings</span>
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
                <h1 class="page-title">User Bookings</h1>
                <a href="users.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2" aria-hidden="true"></i>Back to Users
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
            
            <!-- User Profile Card -->
            <div class="card mb-4">
                <div class="user-profile-card">
                    <div class="user-profile-image">
                        <img src="<?php echo htmlspecialchars($userProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($userData['first_name'] ?? 'User', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="user-profile-details">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h2 class="user-name">
                                    <?php echo htmlspecialchars(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (isset($userData['role'])): ?>
                                    <span class="role-badge <?php echo htmlspecialchars(strtolower($userData['role']), ENT_QUOTES, 'UTF-8'); ?> ms-2">
                                        <?php echo htmlspecialchars($userData['role'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                    <?php endif; ?>
                                </h2>
                                <div class="user-email mb-3">
                                    <i class="fas fa-envelope me-2" aria-hidden="true"></i><?php echo htmlspecialchars($userData['email'] ?? 'No email provided', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <?php if (isset($userData['role']) && $userData['role'] === 'customer'): ?>
                            <a href="user-quotes.php?user_id=<?php echo (int)$userId; ?>" class="btn btn-outline-primary">
                                <i class="fas fa-clipboard-list me-2" aria-hidden="true"></i>View Quote Requests
                            </a>
                            <?php endif; ?>
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
            
            <!-- Filter Card -->
            <div class="filter-card">
                <form method="get" action="user-bookings.php" id="filter-form">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="mb-0">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-0">
                                <label for="date_range" class="form-label">Date Range</label>
                                <input type="text" class="form-control" id="date_range" name="date_range" placeholder="Select date range">
                                <input type="hidden" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                                <button type="button" id="reset-filter" class="btn btn-outline-secondary" aria-label="Reset filters">
                                    <i class="fas fa-redo" aria-hidden="true"></i><span class="sr-only">Reset filters</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Bookings Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Bookings
                        <?php if (!empty($statusFilter) || !empty($startDate) || !empty($endDate)): ?>
                        <small class="text-muted">(Filtered)</small>
                        <?php endif; ?>
                    </h5>
                    <span class="badge bg-primary rounded-pill"><?php echo $totalBookings; ?> Bookings</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($userBookings)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-calendar-times" aria-hidden="true"></i>
                        </div>
                        <h3 class="empty-state-title">No Bookings Found</h3>
                        <p class="empty-state-text">
                            <?php if (!empty($statusFilter) || !empty($startDate) || !empty($endDate)): ?>
                            No bookings match your filter criteria. Try adjusting your filters.
                            <?php else: ?>
                            This user has no bookings yet.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($statusFilter) || !empty($startDate) || !empty($endDate)): ?>
                        <button type="button" id="clear-filter" class="btn btn-primary">
                            <i class="fas fa-filter me-2" aria-hidden="true"></i>Clear Filters
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Date & Time</th>
                                    <?php if (isset($userData['role']) && $userData['role'] === 'customer'): ?>
                                    <th scope="col">Provider</th>
                                    <?php else: ?>
                                    <th scope="col">Customer</th>
                                    <?php endif; ?>
                                    <th scope="col">Service</th>
                                    <th scope="col">Price</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Payment</th>
                                    <th scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userBookings as $booking): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <div class="fw-bold"><?php echo formatDate($booking['booking_date'] ?? ''); ?></div>
                                            <div class="text-muted small"><?php echo formatTime($booking['booking_time'] ?? ''); ?></div>
                                        </div>
                                    </td>
                                    <?php if (isset($userData['role']) && $userData['role'] === 'customer'): ?>
                                    <td>
                                        <a href="user-profile.php?user_id=<?php echo (int)($booking['provider_id'] ?? 0); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        </a>
                                    </td>
                                    <?php else: ?>
                                    <td>
                                        <a href="user-profile.php?user_id=<?php echo (int)($booking['customer_id'] ?? 0); ?>" class="text-decoration-none">
                                            <div><?php echo htmlspecialchars(($booking['customer_first_name'] ?? '') . ' ' . ($booking['customer_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="text-muted small"><?php echo htmlspecialchars($booking['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                    <td>
                                        <?php if (!empty($booking['service_name'])): ?>
                                        <?php echo htmlspecialchars($booking['service_name'], ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                        <span class="text-muted">Not specified</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo formatPrice($booking['total_price'] ?? 0); ?>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo ucfirst(htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $paymentStatusClass = 'pending';
                                        if (isset($booking['payment_status'])) {
                                            if ($booking['payment_status'] === 'paid') {
                                                $paymentStatusClass = 'completed';
                                            } elseif ($booking['payment_status'] === 'refunded') {
                                                $paymentStatusClass = 'cancelled';
                                            }
                                        }
                                        ?>
                                        <span class="status-badge <?php echo $paymentStatusClass; ?>">
                                            <?php echo ucfirst(htmlspecialchars($booking['payment_status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <button class="action-btn" data-bs-toggle="modal" data-bs-target="#viewBookingModal<?php echo (int)$booking['id']; ?>" title="View Details">
                                                <i class="fas fa-eye" aria-hidden="true"></i><span class="sr-only">View details</span>
                                            </button>
                                            <button class="action-btn" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?php echo (int)$booking['id']; ?>" title="Update Status">
                                                <i class="fas fa-edit" aria-hidden="true"></i><span class="sr-only">Update status</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($totalPages > 1): ?>
                <!-- Pagination Footer -->
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        Showing <?php echo min(($page - 1) * $perPage + 1, $totalBookings); ?> to <?php echo min($page * $perPage, $totalBookings); ?> of <?php echo $totalBookings; ?> bookings
                    </div>
                    
                    <nav aria-label="Booking results pagination">
                        <ul class="pagination mb-0">
                            <?php if ($prevPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($prevPage); ?>" aria-label="Previous page">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link" aria-hidden="true">&laquo;</span>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>" aria-label="Page <?php echo $i; ?>" <?php echo $i === $page ? 'aria-current="page"' : ''; ?>><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($nextPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($nextPage); ?>" aria-label="Next page">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link" aria-hidden="true">&raquo;</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Dynamic Modals for Bookings -->
    <?php foreach ($userBookings as $booking): ?>
    <!-- View Booking Modal -->
    <div class="modal fade" id="viewBookingModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="viewBookingModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewBookingModalLabel<?php echo (int)$booking['id']; ?>">Booking Details #<?php echo (int)$booking['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="status-badge <?php echo htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo ucfirst(htmlspecialchars($booking['status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                        <?php 
                        $paymentStatusClass = 'pending';
                        if (isset($booking['payment_status'])) {
                            if ($booking['payment_status'] === 'paid') {
                                $paymentStatusClass = 'completed';
                            } elseif ($booking['payment_status'] === 'refunded') {
                                $paymentStatusClass = 'cancelled';
                            }
                        }
                        ?>
                        <span class="status-badge <?php echo $paymentStatusClass; ?>">
                            <?php echo ucfirst(htmlspecialchars($booking['payment_status'] ?? 'pending', ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Date & Time</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-calendar-alt me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo formatDate($booking['booking_date'] ?? '', 'F d, Y'); ?></strong><br>
                                <span><?php echo formatTime($booking['booking_time'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (isset($userData['role']) && $userData['role'] === 'customer'): ?>
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Provider</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user-cog me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Customer</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo htmlspecialchars(($booking['customer_first_name'] ?? '') . ' ' . ($booking['customer_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                <span><?php echo htmlspecialchars($booking['customer_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Service</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-cog me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <?php if (!empty($booking['service_name'])): ?>
                                <strong><?php echo htmlspecialchars($booking['service_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php else: ?>
                                <span class="text-muted">Not specified</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Price</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-tag me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo formatPrice($booking['total_price'] ?? 0); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($booking['notes'])): ?>
                    <div class="mb-3">
                        <h6 class="text-muted">Notes</h6>
                        <div class="d-flex align-items-start">
                            <i class="fas fa-sticky-note me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <?php echo nl2br(htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8')); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?php echo (int)$booking['id']; ?>" data-bs-dismiss="modal">
                        Update Status
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="updateStatusModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel<?php echo (int)$booking['id']; ?>">Update Booking Status #<?php echo (int)$booking['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="user-bookings.php?user_id=<?php echo (int)$userId; ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                    <input type="hidden" name="update_booking_status" value="1">
                    
                    <!-- Preserve all current GET parameters -->
                    <?php foreach ($_GET as $key => $value): ?>
                        <?php if ($key !== 'user_id'): ?>
                        <input type="hidden" name="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="status<?php echo (int)$booking['id']; ?>" class="form-label">Status</label>
                            <select class="form-select" id="status<?php echo (int)$booking['id']; ?>" name="status" required>
                                <option value="pending" <?php echo ($booking['status'] ?? '') === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo ($booking['status'] ?? '') === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo ($booking['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo ($booking['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notes<?php echo (int)$booking['id']; ?>" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes<?php echo (int)$booking['id']; ?>" name="notes" rows="3" placeholder="Optional notes about this status change" maxlength="500"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2" aria-hidden="true"></i>
                            Changing the status will send a notification to the user.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Moment.js -->
    <script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>
    <!-- Date Range Picker -->
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
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
            
            // Check for saved theme preference
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
            
            // Initialize Date Range Picker with improved error handling
            try {
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;
                
                let initialDateRange = {};
                if (startDate && endDate) {
                    initialDateRange = {
                        startDate: moment(startDate, 'YYYY-MM-DD', true).isValid() ? moment(startDate) : moment(),
                        endDate: moment(endDate, 'YYYY-MM-DD', true).isValid() ? moment(endDate) : moment()
                    };
                }
                
                $('#date_range').daterangepicker({
                    autoUpdateInput: false,
                    locale: {
                        cancelLabel: 'Clear',
                        format: 'YYYY-MM-DD'
                    },
                    ranges: {
                       'Today': [moment(), moment()],
                       'Yesterday': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                       'Last 7 Days': [moment().subtract(6, 'days'), moment()],
                       'Last 30 Days': [moment().subtract(29, 'days'), moment()],
                       'This Month': [moment().startOf('month'), moment().endOf('month')],
                       'Last Month': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')]
                    },
                    ...initialDateRange
                });
                
                // When date range is selected
                $('#date_range').on('apply.daterangepicker', function(ev, picker) {
                    $(this).val(picker.startDate.format('YYYY-MM-DD') + ' - ' + picker.endDate.format('YYYY-MM-DD'));
                    
                    // Update hidden fields
                    document.getElementById('start_date').value = picker.startDate.format('YYYY-MM-DD');
                    document.getElementById('end_date').value = picker.endDate.format('YYYY-MM-DD');
                });
                
                // When date range is cleared
                $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
                    $(this).val('');
                    
                    // Clear hidden fields
                    document.getElementById('start_date').value = '';
                    document.getElementById('end_date').value = '';
                });
                
                // Set initial value of date range picker
                if (startDate && endDate) {
                    $('#date_range').val(startDate + ' - ' + endDate);
                }
            } catch (error) {
                console.error('Error initializing date range picker:', error);
                // Fallback to basic date inputs if daterangepicker fails
                const dateRangeInput = document.getElementById('date_range');
                if (dateRangeInput) {
                    dateRangeInput.type = 'text';
                    dateRangeInput.placeholder = 'Use format: YYYY-MM-DD - YYYY-MM-DD';
                }
            }
            
            // Reset filter button
            document.getElementById('reset-filter').addEventListener('click', function() {
                window.location.href = 'user-bookings.php?user_id=<?php echo (int)$userId; ?>';
            });
            
            // Clear filter button (in empty state)
            const clearFilterBtn = document.getElementById('clear-filter');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', function() {
                    window.location.href = 'user-bookings.php?user_id=<?php echo (int)$userId; ?>';
                });
            }
            
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