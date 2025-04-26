<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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
$bookings = [];
$userInitials = 'CN'; // Default initials
$errorMessage = '';
$successMessage = '';

// Pagination variables
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;
$totalBookings = 0;
$totalPages = 1;

// Filter variables - using filter_input instead of direct $_GET
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$searchQuery = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$dateFilter = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$sortBy = filter_input(INPUT_GET, 'sort', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'newest';

// Additional filter variables
$serviceType = filter_input(INPUT_GET, 'service_type', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$paymentStatus = filter_input(INPUT_GET, 'payment', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$dateFrom = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$dateTo = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$priceMin = filter_input(INPUT_GET, 'price_min', FILTER_VALIDATE_FLOAT) ?: 0;
$priceMax = filter_input(INPUT_GET, 'price_max', FILTER_VALIDATE_FLOAT) ?: 0;

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
    
    // Get user initials for avatar
    if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
    } elseif (!empty($userData['first_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
    } elseif (!empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
    }
    
    // Build the WHERE clause for filtering
    $whereClause = "b.customer_id = ?";
    $queryParams = [$userId];
    $paramTypes = "i";
    
    // Status filter
    if ($statusFilter && $statusFilter !== 'all') {
        $whereClause .= " AND b.status = ?";
        $queryParams[] = $statusFilter;
        $paramTypes .= "s";
    }
    
    // Date filter
    if ($dateFilter) {
        $whereClause .= " AND b.booking_date = ?";
        $queryParams[] = $dateFilter;
        $paramTypes .= "s";
    }
    
    // Date range filters
    if ($dateFrom) {
        $whereClause .= " AND b.booking_date >= ?";
        $queryParams[] = $dateFrom;
        $paramTypes .= "s";
    }
    
    if ($dateTo) {
        $whereClause .= " AND b.booking_date <= ?";
        $queryParams[] = $dateTo;
        $paramTypes .= "s";
    }
    
    // Payment status filter
    if ($paymentStatus) {
        $whereClause .= " AND b.payment_status = ?";
        $queryParams[] = $paymentStatus;
        $paramTypes .= "s";
    }
    
    // Service type filter
    if ($serviceType) {
        $whereClause .= " AND s.category = ?";
        $queryParams[] = $serviceType;
        $paramTypes .= "s";
    }
    
    // Price range filters
    if ($priceMin > 0) {
        $whereClause .= " AND b.total_price >= ?";
        $queryParams[] = $priceMin;
        $paramTypes .= "d";
    }
    
    if ($priceMax > 0) {
        $whereClause .= " AND b.total_price <= ?";
        $queryParams[] = $priceMax;
        $paramTypes .= "d";
    }
    
    // Search query
    if ($searchQuery) {
        $whereClause .= " AND (s.name LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $searchPattern = "%{$searchQuery}%";
        $queryParams[] = $searchPattern;
        $queryParams[] = $searchPattern;
        $queryParams[] = $searchPattern;
        $paramTypes .= "sss";
    }
    
    // Count total bookings for pagination
    $countQuery = "
        SELECT COUNT(*) as total
        FROM bookings b
        LEFT JOIN services s ON b.service_id = s.id
        LEFT JOIN providers p ON b.provider_id = p.id
        LEFT JOIN users u ON p.user_id = u.id
        WHERE {$whereClause}
    ";
    
    $countStmt = $conn->prepare($countQuery);
    $countStmt->bind_param($paramTypes, ...$queryParams);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $countRow = $countResult->fetch_assoc();
    $totalBookings = $countRow['total'];
    $totalPages = ceil($totalBookings / $perPage);
    $countStmt->close();
    
    // Sort order
    $orderBy = "b.created_at DESC"; // Default ordering
    if ($sortBy === 'oldest') {
        $orderBy = "b.created_at ASC";
    } elseif ($sortBy === 'date_asc') {
        $orderBy = "b.booking_date ASC, b.booking_time ASC";
    } elseif ($sortBy === 'date_desc') {
        $orderBy = "b.booking_date DESC, b.booking_time DESC";
    } elseif ($sortBy === 'price_asc') {
        $orderBy = "b.total_price ASC";
    } elseif ($sortBy === 'price_desc') {
        $orderBy = "b.total_price DESC";
    }
    
    // Get bookings with pagination - Added has_review check field
    $bookingsQuery = "
        SELECT b.*, 
            p.id as provider_id,
            u.first_name as provider_first_name, 
            u.last_name as provider_last_name,
            u.profile_image as provider_image,
            u.phone as provider_phone,
            s.name as service_name,
            s.description as service_description,
            s.price as service_price,
            s.duration as service_duration,
            s.category as service_category,
            (SELECT COUNT(*) > 0 FROM reviews WHERE booking_id = b.id) as has_review
        FROM bookings b
        LEFT JOIN providers p ON b.provider_id = p.id
        LEFT JOIN users u ON p.user_id = u.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT ?, ?
    ";
    
    $bookingsStmt = $conn->prepare($bookingsQuery);
    $bookingsStmt->bind_param($paramTypes . "ii", ...[...$queryParams, $offset, $perPage]);
    $bookingsStmt->execute();
    $bookingsResult = $bookingsStmt->get_result();
    $bookings = [];
    
    while ($row = $bookingsResult->fetch_assoc()) {
        $bookings[] = $row;
    }
    $bookingsStmt->close();
    
    // Get booking statistics for filter summary
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
    
    // Process cancellation requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
        // CSRF token verification
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $_SESSION['error_message'] = "Security validation failed. Please try again.";
            header('Location: bookings.php');
            exit;
        }
        
        $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
        $cancellationNotes = isset($_POST['cancellation_notes']) ? 
            trim(htmlspecialchars($_POST['cancellation_notes'], ENT_QUOTES, 'UTF-8')) : '';
        
        if ($bookingId > 0) {
            // First check if the booking exists and belongs to this customer
            $checkStmt = $conn->prepare("SELECT id, status FROM bookings WHERE id = ? AND customer_id = ?");
            $checkStmt->bind_param("ii", $bookingId, $userId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $booking = $checkResult->fetch_assoc();
            $checkStmt->close();
            
            if ($booking && ($booking['status'] === 'pending' || $booking['status'] === 'confirmed')) {
                // Begin transaction
                $conn->begin_transaction();
                
                try {
                    // Update booking status
                    $updateStmt = $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
                    $updateStmt->bind_param("i", $bookingId);
                    $updateSuccess = $updateStmt->execute();
                    $updateStmt->close();
                    
                    if ($updateSuccess) {
                        // Add to booking status history
                        $historyStmt = $conn->prepare("INSERT INTO booking_status_history (booking_id, status, created_by, user_id, notes) VALUES (?, 'cancelled', 'customer', ?, ?)");
                        $historyStmt->bind_param("iis", $bookingId, $userId, $cancellationNotes);
                        $historyStmt->execute();
                        $historyStmt->close();
                        
                        // Commit transaction
                        $conn->commit();
                        
                        $_SESSION['success_message'] = "Booking #$bookingId has been cancelled successfully.";
                    } else {
                        // Rollback transaction
                        $conn->rollback();
                        $_SESSION['error_message'] = "Failed to cancel the booking. Please try again.";
                    }
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $_SESSION['error_message'] = "An error occurred. Please try again.";
                    error_log("Error cancelling booking: " . $e->getMessage());
                }
                
                // Redirect to refresh the page
                header('Location: bookings.php');
                exit;
            } else {
                $_SESSION['error_message'] = "Cannot cancel this booking. It may have already been completed or cancelled.";
                header('Location: bookings.php');
                exit;
            }
        }
    }
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching your bookings. Please try again later.";
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
$conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Manage your bookings on FixItNow service booking platform">
    <meta name="robots" content="noindex, nofollow">
    <title>My Bookings - FixItNow</title>
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
        
        /* Filter and Search Styles */
        .filter-card {
            margin-bottom: 1.5rem;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .filter-card .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-card:hover {
            box-shadow: 0 0.5rem 1.5rem var(--shadow-color);
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }
        
        .filter-item {
            flex: 1;
            min-width: 150px;
            position: relative;
        }
        
        .filter-label {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
            transition: color 0.2s ease;
        }
        
        .filter-item:hover .filter-label {
            color: var(--primary-color);
        }
        
        .filter-icon {
            color: var(--text-muted);
        }
        
        .filter-item .form-control:focus,
        .filter-item .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        .filter-toggle {
            cursor: pointer;
            font-size: 0.875rem;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .filter-toggle:hover {
            text-decoration: underline;
        }
        
        .advanced-filters {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.5s ease;
        }
        
        .advanced-filters.show {
            max-height: 500px;
        }
        
        /* Booking card */
        .booking-card {
            transition: all 0.3s ease;
            margin-bottom: 1.5rem;
            border-radius: 0.75rem;
            overflow: hidden;
            position: relative;
        }
        
        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.75rem 1.5rem var(--shadow-color);
        }
        
        .booking-card.pending {
            border-top: 3px solid var(--warning-color);
        }
        
        .booking-card.confirmed {
            border-top: 3px solid var(--primary-color);
        }
        
        .booking-card.completed {
            border-top: 3px solid var(--success-color);
        }
        
        .booking-card.cancelled {
            border-top: 3px solid var(--danger-color);
        }
        
        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background-color: rgba(0, 0, 0, 0.03);
            border-bottom: 1px solid var(--border-color);
            position: relative;
        }
        
        .booking-id {
            font-weight: 600;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .booking-id i {
            color: var(--primary-color);
        }
        
        .booking-body {
            padding: 1.5rem;
            position: relative;
        }
        
        .booking-ribbon {
            position: absolute;
            top: 10px;
            right: -5px;
            padding: 0.25rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            transform: rotate(45deg);
            z-index: 1;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: none;
        }
        
        .booking-card:hover .booking-ribbon {
            display: block;
        }
        
        .booking-card.has-notes:before {
            content: '';
            position: absolute;
            top: 15px;
            right: 15px;
            width: 10px;
            height: 10px;
            background-color: var(--warning-color);
            border-radius: 50%;
            z-index: 2;
        }
        
        .booking-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .booking-meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--text-muted);
            letter-spacing: 0.5px;
            margin-bottom: 0.25rem;
        }
        
        .meta-value {
            font-weight: 600;
        }
        
        .service-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .service-description {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .provider-info {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .provider-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            margin-right: 1rem;
            background-color: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .provider-details {
            flex: 1;
        }
        
        .provider-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .provider-contact {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .booking-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }
        
        /* Chat message bubbles */
        .message-bubble {
            border-radius: 1rem;
            max-width: 75%;
            margin-bottom: 0.5rem;
            word-wrap: break-word;
        }
        
        /* Rating stars */
        .rating-stars {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .rating-star {
            cursor: pointer;
            color: #ddd;
            transition: all 0.2s ease;
        }
        
        .rating-star.active i, 
        .rating-star:hover i {
            color: #ffc107;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }
        
        .pagination .page-item {
            margin: 0 0.25rem;
        }
        
        .pagination .page-link {
            border-radius: 0.375rem;
            border: none;
            padding: 0.5rem 0.75rem;
            color: var(--text-color);
            background-color: var(--card-bg);
            box-shadow: 0 0.125rem 0.25rem var(--shadow-color);
            transition: all 0.2s ease;
        }
        
        .pagination .page-link:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
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
            margin-bottom: 1rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        /* Modal Styles */
        .modal-content {
            border: none;
            border-radius: 0.75rem;
            background-color: var(--modal-bg);
            box-shadow: 0 1rem 3rem var(--shadow-color);
        }
        
        .modal-header {
            border-bottom-color: var(--border-color);
            padding: 1.25rem 1.5rem;
        }
        
        .modal-footer {
            border-top-color: var(--border-color);
            padding: 1.25rem 1.5rem;
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
        
        /* Accessibility */
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
            .filter-form {
                flex-direction: column;
                gap: 1rem;
            }
            
            .filter-item {
                width: 100%;
            }
            
            .booking-meta {
                gap: 1rem;
            }
            
            .booking-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .booking-actions .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .booking-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .provider-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .provider-avatar {
                margin-bottom: 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .booking-meta {
                flex-direction: column;
                gap: 0.75rem;
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
                    <button type="button" class="mobile-menu-toggle d-lg-none me-3" id="mobileMenuToggle" aria-label="Toggle mobile menu">
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
                <a href="bookings.php" class="mobile-menu-item active">
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
                        <a class="nav-link" href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="bookings.php">
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
            <div class="mb-4 d-flex justify-content-between align-items-center">
                <h1 class="page-title">My Bookings</h1>
                <a href="book-service.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle me-2"></i>New Booking
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
            
            <!-- Booking Stats Summary -->
            <div class="card filter-card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3 d-flex justify-content-center align-items-center rounded-circle" style="width: 48px; height: 48px; background-color: rgba(var(--bs-primary-rgb), 0.1);">
                                    <i class="fas fa-calendar-check fa-lg" style="color: var(--primary-color);"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Total Bookings</div>
                                    <div class="fw-bold fs-4"><?php echo (int)($stats['total_bookings'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3 d-flex justify-content-center align-items-center rounded-circle" style="width: 48px; height: 48px; background-color: rgba(var(--bs-primary-rgb), 0.1);">
                                    <i class="fas fa-clock fa-lg" style="color: var(--warning-color);"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Upcoming</div>
                                    <div class="fw-bold fs-4"><?php echo (int)(($stats['pending_bookings'] ?? 0) + ($stats['confirmed_bookings'] ?? 0)); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3 d-flex justify-content-center align-items-center rounded-circle" style="width: 48px; height: 48px; background-color: rgba(var(--bs-success-rgb), 0.1);">
                                    <i class="fas fa-check-circle fa-lg" style="color: var(--success-color);"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Completed</div>
                                    <div class="fw-bold fs-4"><?php echo (int)($stats['completed_bookings'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6">
                            <div class="d-flex align-items-center">
                                <div class="me-3 d-flex justify-content-center align-items-center rounded-circle" style="width: 48px; height: 48px; background-color: rgba(var(--bs-danger-rgb), 0.1);">
                                    <i class="fas fa-times-circle fa-lg" style="color: var(--danger-color);"></i>
                                </div>
                                <div>
                                    <div class="text-muted small">Cancelled</div>
                                    <div class="fw-bold fs-4"><?php echo (int)($stats['cancelled_bookings'] ?? 0); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter and Search -->
            <div class="card filter-card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Filter Bookings</h5>
                    <div class="filter-toggle" id="advancedFilterToggle">
                        <span id="toggleText">Show Advanced Filters</span>
                        <i class="fas fa-chevron-down" id="toggleIcon"></i>
                    </div>
                </div>
                <div class="card-body">
                    <form action="bookings.php" method="GET" class="filter-form">
                        <div class="filter-item">
                            <label for="statusFilter" class="filter-label">
                                <i class="fas fa-tag me-1"></i> Status
                            </label>
                            <select name="status" id="statusFilter" class="form-select">
                                <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $statusFilter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="filter-item">
                            <label for="dateFilter" class="filter-label">
                                <i class="fas fa-calendar me-1"></i> Booking Date
                            </label>
                            <input type="date" name="date" id="dateFilter" class="form-control" value="<?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="filter-item">
                            <label for="searchFilter" class="filter-label">
                                <i class="fas fa-search me-1"></i> Search
                            </label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search filter-icon" aria-hidden="true"></i></span>
                                <input type="text" name="search" id="searchFilter" class="form-control" placeholder="Search provider or service" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="filter-item">
                            <label for="sortFilter" class="filter-label">
                                <i class="fas fa-sort me-1"></i> Sort By
                            </label>
                            <select name="sort" id="sortFilter" class="form-select">
                                <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                <option value="date_asc" <?php echo $sortBy === 'date_asc' ? 'selected' : ''; ?>>Booking Date (Ascending)</option>
                                <option value="date_desc" <?php echo $sortBy === 'date_desc' ? 'selected' : ''; ?>>Booking Date (Descending)</option>
                                <option value="price_asc" <?php echo $sortBy === 'price_asc' ? 'selected' : ''; ?>>Price (Low to High)</option>
                                <option value="price_desc" <?php echo $sortBy === 'price_desc' ? 'selected' : ''; ?>>Price (High to Low)</option>
                            </select>
                        </div>
                        
                        <div class="filter-item" style="flex: 0 0 auto; align-self: flex-end;">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2" aria-hidden="true"></i>Apply Filters
                            </button>
                        </div>
                        <div class="filter-item" style="flex: 0 0 auto; align-self: flex-end;">
                            <a href="bookings.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-redo me-2" aria-hidden="true"></i>Reset
                            </a>
                        </div>
                        
                        <!-- Advanced Filters (Initially Hidden) -->
                        <div class="advanced-filters w-100" id="advancedFilters">
                            <hr class="my-3">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="serviceFilter" class="filter-label">
                                            <i class="fas fa-cogs me-1"></i> Service Type
                                        </label>
                                        <select name="service_type" id="serviceFilter" class="form-select">
                                            <option value="">All Services</option>
                                            <option value="smartphone" <?php echo $serviceType === 'smartphone' ? 'selected' : ''; ?>>Smartphone Repair</option>
                                            <option value="laptop" <?php echo $serviceType === 'laptop' ? 'selected' : ''; ?>>Laptop Repair</option>
                                            <option value="tablet" <?php echo $serviceType === 'tablet' ? 'selected' : ''; ?>>Tablet Repair</option>
                                            <option value="desktop" <?php echo $serviceType === 'desktop' ? 'selected' : ''; ?>>Desktop Repair</option>
                                            <option value="tv" <?php echo $serviceType === 'tv' ? 'selected' : ''; ?>>TV Repair</option>
                                            <option value="gaming" <?php echo $serviceType === 'gaming' ? 'selected' : ''; ?>>Gaming Device Repair</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="paymentFilter" class="filter-label">
                                            <i class="fas fa-credit-card me-1"></i> Payment Status
                                        </label>
                                        <select name="payment" id="paymentFilter" class="form-select">
                                            <option value="">All Payment Statuses</option>
                                            <option value="paid" <?php echo $paymentStatus === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                            <option value="unpaid" <?php echo $paymentStatus === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                            <option value="refunded" <?php echo $paymentStatus === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="dateFromFilter" class="filter-label">
                                            <i class="fas fa-calendar-alt me-1"></i> Date Range (From)
                                        </label>
                                        <input type="date" name="date_from" id="dateFromFilter" class="form-control" 
                                               value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="dateToFilter" class="filter-label">
                                            <i class="fas fa-calendar-alt me-1"></i> Date Range (To)
                                        </label>
                                        <input type="date" name="date_to" id="dateToFilter" class="form-control"
                                               value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="priceMinFilter" class="filter-label">
                                            <i class="fas fa-dollar-sign me-1"></i> Min Price
                                        </label>
                                        <input type="number" name="price_min" id="priceMinFilter" class="form-control" 
                                               placeholder="0" min="0" value="<?php echo $priceMin > 0 ? $priceMin : ''; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="priceMaxFilter" class="filter-label">
                                            <i class="fas fa-dollar-sign me-1"></i> Max Price
                                        </label>
                                        <input type="number" name="price_max" id="priceMaxFilter" class="form-control" 
                                               placeholder="No limit" min="0" value="<?php echo $priceMax > 0 ? $priceMax : ''; ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Bookings List -->
            <?php if (empty($bookings)): ?>
            <div class="card">
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-calendar-times" aria-hidden="true"></i>
                    </div>
                    <h3 class="empty-state-title">No bookings found</h3>
                    <p class="empty-state-text">
                        <?php if (!empty($searchQuery) || $statusFilter !== 'all' || !empty($dateFilter)): ?>
                            No bookings match your filter criteria. Try adjusting your filters or search query.
                        <?php else: ?>
                            You haven't made any bookings yet. Book a service to get started.
                        <?php endif; ?>
                    </p>
                    <a href="book-service.php" class="btn btn-primary">Book a Service</a>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($bookings as $booking): ?>
                <div class="card booking-card <?php echo htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8'); ?> <?php echo !empty($booking['notes']) ? 'has-notes' : ''; ?>">
                    <?php if ($booking['status'] === 'confirmed'): ?>
                    <div class="booking-ribbon">Confirmed</div>
                    <?php endif; ?>
                    
                    <div class="booking-header">
                        <div class="booking-id">
                            <i class="fas fa-bookmark"></i>
                            Booking #<?php echo htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8'); ?>
                            <span class="text-muted ms-2 fs-6">
                                <i class="fas fa-clock me-1"></i><?php echo timeAgo($booking['created_at'] ?? ''); ?>
                            </span>
                        </div>
                        <span class="status-badge <?php echo htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <i class="fas fa-<?php 
                                switch($booking['status']) {
                                    case 'pending': echo 'hourglass'; break;
                                    case 'confirmed': echo 'check'; break;
                                    case 'completed': echo 'check-double'; break;
                                    case 'cancelled': echo 'ban'; break;
                                    default: echo 'circle';
                                }
                            ?> me-1"></i>
                            <?php echo ucfirst(htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                    </div>
                    <div class="booking-body">
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="service-name">
                                    <i class="fas fa-tools me-2 text-primary"></i>
                                    <?php echo htmlspecialchars($booking['service_name'] ?? 'Service Not Specified', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                
                                <?php if (!empty($booking['service_description'])): ?>
                                <div class="service-description">
                                    <?php echo htmlspecialchars($booking['service_description'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <div class="booking-meta-item text-md-end mb-2">
                                    <span class="meta-label">Total Price</span>
                                    <span class="meta-value fs-4 fw-bold">
                                        <?php echo formatPrice($booking['total_price'] ?? 0); ?>
                                    </span>
                                </div>
                                <div class="booking-meta-item text-md-end">
                                    <span class="meta-label">Payment Status</span>
                                    <span class="meta-value">
                                        <?php if ($booking['payment_status'] === 'paid'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                        <?php elseif ($booking['payment_status'] === 'refunded'): ?>
                                            <span class="badge bg-info"><i class="fas fa-undo me-1"></i> Refunded</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Unpaid</span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <div class="me-4 p-3 rounded-circle" style="background-color: rgba(var(--bs-primary-rgb), 0.1);">
                                        <i class="fas fa-calendar-day fs-4 text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Appointment Date</div>
                                        <div class="fw-bold fs-5"><?php echo formatDate($booking['booking_date'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <div class="me-4 p-3 rounded-circle" style="background-color: rgba(var(--bs-primary-rgb), 0.1);">
                                        <i class="fas fa-clock fs-4 text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="text-muted small">Appointment Time</div>
                                        <div class="fw-bold fs-5"><?php echo formatTime($booking['booking_time'] ?? ''); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="provider-info p-3 rounded" style="background-color: rgba(var(--bs-light-rgb), 0.5);">
                            <h6 class="mb-3"><i class="fas fa-user-cog me-2 text-primary"></i>Service Provider</h6>
                            <div class="d-flex align-items-center">
                                <?php
                                $providerInitials = 'PT';
                                if (!empty($booking['provider_first_name']) && !empty($booking['provider_last_name'])) {
                                    $providerInitials = strtoupper(substr($booking['provider_first_name'], 0, 1) . substr($booking['provider_last_name'], 0, 1));
                                }
                                ?>
                                <div class="provider-avatar">
                                    <?php echo $providerInitials; ?>
                                </div>
                                <div class="provider-details">
                                    <div class="provider-name">
                                        <?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php if (!empty($booking['provider_phone'])): ?>
                                    <div class="provider-contact">
                                        <i class="fas fa-phone-alt me-2" aria-hidden="true"></i><?php echo htmlspecialchars($booking['provider_phone'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($booking['notes'])): ?>
                        <div class="alert alert-warning mt-3">
                            <h6 class="mb-1"><i class="fas fa-sticky-note me-2"></i>Notes</h6>
                            <p class="mb-0"><?php echo htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <div class="booking-actions mt-4">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo (int)$booking['id']; ?>">
                                <i class="fas fa-eye me-2" aria-hidden="true"></i>View Details
                            </button>
                            
                            <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo (int)$booking['id']; ?>">
                                <i class="fas fa-times-circle me-2" aria-hidden="true"></i>Cancel Booking
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($booking['status'] === 'completed'): ?>
                                <?php if (isset($booking['has_review']) && !$booking['has_review']): ?>
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo (int)$booking['id']; ?>">
                                        <i class="fas fa-star me-2" aria-hidden="true"></i>Leave Review
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-outline-secondary" disabled>
                                        <i class="fas fa-check-circle me-2" aria-hidden="true"></i>Review Submitted
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#messageModal<?php echo (int)$booking['id']; ?>">
                                <i class="fas fa-comment-alt me-2" aria-hidden="true"></i>Message Provider
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Details Modal -->
                <div class="modal fade" id="detailsModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="detailsModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="detailsModalLabel<?php echo (int)$booking['id']; ?>">Booking #<?php echo (int)$booking['id']; ?> Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Booking Information</h6>
                                        <div class="mb-2">
                                            <span class="text-muted">Status:</span>
                                            <span class="status-badge <?php echo htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8'); ?> ms-2">
                                                <?php echo ucfirst(htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8')); ?>
                                            </span>
                                        </div>
                                        <div class="mb-2">
                                            <span class="text-muted">Date:</span>
                                            <span class="fw-semibold ms-2"><?php echo formatDate($booking['booking_date'] ?? ''); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <span class="text-muted">Time:</span>
                                            <span class="fw-semibold ms-2"><?php echo formatTime($booking['booking_time'] ?? ''); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <span class="text-muted">Price:</span>
                                            <span class="fw-semibold ms-2"><?php echo formatPrice($booking['total_price'] ?? 0); ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <span class="text-muted">Payment Status:</span>
                                            <span class="fw-semibold ms-2">
                                                <?php if ($booking['payment_status'] === 'paid'): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                                <?php elseif ($booking['payment_status'] === 'refunded'): ?>
                                                    <span class="badge bg-info"><i class="fas fa-undo me-1"></i> Refunded</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Unpaid</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="mb-2">
                                            <span class="text-muted">Created:</span>
                                            <span class="fw-semibold ms-2"><?php echo formatDate($booking['created_at'] ?? '', 'M d, Y h:i A'); ?></span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="fw-bold mb-3">Service Information</h6>
                                        <div class="mb-2">
                                            <span class="text-muted">Service:</span>
                                            <span class="fw-semibold ms-2"><?php echo htmlspecialchars($booking['service_name'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <?php if (!empty($booking['service_duration'])): ?>
                                        <div class="mb-2">
                                            <span class="text-muted">Duration:</span>
                                            <span class="fw-semibold ms-2"><?php echo htmlspecialchars($booking['service_duration'] ?? '', ENT_QUOTES, 'UTF-8'); ?> minutes</span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mb-2">
                                            <span class="text-muted">Provider:</span>
                                            <span class="fw-semibold ms-2">
                                                <?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                            </span>
                                        </div>
                                        <?php if (!empty($booking['provider_phone'])): ?>
                                        <div class="mb-2">
                                            <span class="text-muted">Phone:</span>
                                            <span class="fw-semibold ms-2"><?php echo htmlspecialchars($booking['provider_phone'], ENT_QUOTES, 'UTF-8'); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($booking['service_description'])): ?>
                                <div class="mb-4">
                                    <h6 class="fw-bold mb-2">Service Description</h6>
                                    <p><?php echo htmlspecialchars($booking['service_description'], ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($booking['notes'])): ?>
                                <div class="alert alert-warning">
                                    <h6 class="fw-bold mb-2"><i class="fas fa-sticky-note me-2"></i>Booking Notes</h6>
                                    <p class="mb-0"><?php echo htmlspecialchars($booking['notes'], ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                                <?php endif; ?>
                                
                                <!-- Booking Status History (If available) -->
                                <?php 
                                // This would require an additional query to get the booking history
                                // For now, we'll just show a placeholder
                                ?>
                                <div class="mt-4">
                                    <h6 class="fw-bold mb-3">Booking Status History</h6>
                                    <div class="status-timeline border-start border-2 ps-4 position-relative">
                                        <div class="status-item mb-3">
                                            <div class="status-dot position-absolute bg-primary rounded-circle" style="width: 12px; height: 12px; left: -6px;"></div>
                                            <div class="fw-semibold">Booking Created</div>
                                            <div class="text-muted small"><?php echo formatDate($booking['created_at'] ?? '', 'M d, Y h:i A'); ?></div>
                                        </div>
                                        
                                        <?php if ($booking['status'] !== 'pending'): ?>
                                        <div class="status-item mb-3">
                                            <div class="status-dot position-absolute bg-primary rounded-circle" style="width: 12px; height: 12px; left: -6px;"></div>
                                            <div class="fw-semibold">Status Changed to <?php echo ucfirst(htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8')); ?></div>
                                            <div class="text-muted small"><?php echo formatDate($booking['updated_at'] ?? '', 'M d, Y h:i A'); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                
                                <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal<?php echo (int)$booking['id']; ?>" data-bs-dismiss="modal">
                                    <i class="fas fa-times-circle me-2"></i>Cancel Booking
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Cancel Modal -->
                <div class="modal fade" id="cancelModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="cancelModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="cancelModalLabel<?php echo (int)$booking['id']; ?>">
                                    Cancel Booking #<?php echo (int)$booking['id']; ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form action="bookings.php" method="POST">
                                <div class="modal-body">
                                    <p>Are you sure you want to cancel this booking?</p>
                                    <p class="text-danger"><strong>Note:</strong> This action cannot be undone.</p>
                                    
                                    <div class="mb-3">
                                        <label for="cancellationNotes<?php echo (int)$booking['id']; ?>" class="form-label">
                                            Cancellation Reason (optional)
                                        </label>
                                        <textarea class="form-control" id="cancellationNotes<?php echo (int)$booking['id']; ?>" 
                                            name="cancellation_notes" rows="3"></textarea>
                                    </div>
                                    
                                    <!-- CSRF Token -->
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                                    <input type="hidden" name="action" value="cancel_booking">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Booking</button>
                                    <button type="submit" class="btn btn-danger">Yes, Cancel Booking</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Review Modal -->
                <?php if ($booking['status'] === 'completed'): ?>
                <div class="modal fade" id="reviewModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="reviewModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="reviewModalLabel<?php echo (int)$booking['id']; ?>">Leave a Review</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form id="reviewForm<?php echo (int)$booking['id']; ?>" action="submit-review.php" method="POST">
                                <div class="modal-body">
                                    <p>Share your experience with <strong><?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong> for the service: <strong><?php echo htmlspecialchars($booking['service_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></strong></p>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Rating</label>
                                        <div class="rating-stars mb-2">
                                            <div class="d-flex justify-content-center">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <div class="rating-star mx-1 fs-3" data-value="<?php echo $i; ?>" data-booking-id="<?php echo (int)$booking['id']; ?>">
                                                    <i class="far fa-star"></i>
                                                </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <input type="hidden" name="rating" id="ratingValue<?php echo (int)$booking['id']; ?>" value="0" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                    <label for="reviewComment<?php echo (int)$booking['id']; ?>" class="form-label">Your Review</label>
                                        <textarea class="form-control" id="reviewComment<?php echo (int)$booking['id']; ?>" name="comment" rows="4" placeholder="Share your experience with this service provider..."></textarea>
                                    </div>
                                    
                                    <!-- CSRF Token -->
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="booking_id" value="<?php echo (int)$booking['id']; ?>">
                                    <input type="hidden" name="provider_id" value="<?php echo (int)$booking['provider_id']; ?>">
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-primary" onclick="submitReview(<?php echo (int)$booking['id']; ?>)">Submit Review</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Message Modal -->
                <div class="modal fade" id="messageModal<?php echo (int)$booking['id']; ?>" tabindex="-1" aria-labelledby="messageModalLabel<?php echo (int)$booking['id']; ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="messageModalLabel<?php echo (int)$booking['id']; ?>">
                                    Message Provider - <?php echo htmlspecialchars(($booking['provider_first_name'] ?? '') . ' ' . ($booking['provider_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="chat-container bg-light p-3 rounded" style="height: 300px; overflow-y: auto;" id="chatContainer<?php echo (int)$booking['id']; ?>">
                                    <div class="text-center text-muted py-5">
                                        <i class="fas fa-comments fa-3x mb-3"></i>
                                        <p>Loading messages...</p>
                                    </div>
                                </div>
                                
                                <form id="messageForm<?php echo (int)$booking['id']; ?>" class="mt-3">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="messageInput<?php echo (int)$booking['id']; ?>" placeholder="Type your message here..." required>
                                        <button class="btn btn-primary" type="button" onclick="sendMessage(<?php echo (int)$booking['id']; ?>, <?php echo (int)$booking['provider_id']; ?>)">
                                            <i class="fas fa-paper-plane"></i> Send
                                        </button>
                                    </div>
                                    <!-- CSRF Token for AJAX request -->
                                    <input type="hidden" id="csrf_token<?php echo (int)$booking['id']; ?>" value="<?php echo $_SESSION['csrf_token']; ?>">
                                </form>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <nav aria-label="Bookings pagination">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo ($page - 1); ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchQuery); ?>&date=<?php echo urlencode($dateFilter); ?>&sort=<?php echo urlencode($sortBy); ?>" aria-label="Previous">
                                <span aria-hidden="true"><i class="fas fa-chevron-left" aria-hidden="true"></i></span>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php 
                        // Show limited page numbers with ellipsis for large number of pages
                        $startPage = max(1, min($page - 2, $totalPages - 4));
                        $endPage = min($totalPages, max($page + 2, 5));
                        
                        if ($startPage > 1) {
                            echo '<li class="page-item"><a class="page-link" href="?page=1&status=' . urlencode($statusFilter) . '&search=' . urlencode($searchQuery) . '&date=' . urlencode($dateFilter) . '&sort=' . urlencode($sortBy) . '">1</a></li>';
                            if ($startPage > 2) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                        <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchQuery); ?>&date=<?php echo urlencode($dateFilter); ?>&sort=<?php echo urlencode($sortBy); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                        <?php endfor; 
                        
                        if ($endPage < $totalPages) {
                            if ($endPage < $totalPages - 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . '&status=' . urlencode($statusFilter) . '&search=' . urlencode($searchQuery) . '&date=' . urlencode($dateFilter) . '&sort=' . urlencode($sortBy) . '">' . $totalPages . '</a></li>';
                        }
                        ?>
                        
                        <?php if ($page < $totalPages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?php echo ($page + 1); ?>&status=<?php echo urlencode($statusFilter); ?>&search=<?php echo urlencode($searchQuery); ?>&date=<?php echo urlencode($dateFilter); ?>&sort=<?php echo urlencode($sortBy); ?>" aria-label="Next">
                                <span aria-hidden="true"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">© 2025 FixItNow. All rights reserved.</p>
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
            
            // Advanced filters toggle
            const advancedFilterToggle = document.getElementById('advancedFilterToggle');
            const advancedFilters = document.getElementById('advancedFilters');
            const toggleText = document.getElementById('toggleText');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (advancedFilterToggle && advancedFilters) {
                advancedFilterToggle.addEventListener('click', function() {
                    advancedFilters.classList.toggle('show');
                    const isOpen = advancedFilters.classList.contains('show');
                    
                    if (isOpen) {
                        toggleText.textContent = 'Hide Advanced Filters';
                        toggleIcon.classList.remove('fa-chevron-down');
                        toggleIcon.classList.add('fa-chevron-up');
                    } else {
                        toggleText.textContent = 'Show Advanced Filters';
                        toggleIcon.classList.remove('fa-chevron-up');
                        toggleIcon.classList.add('fa-chevron-down');
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
            
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-warning)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Add date range selection functionality
            const dateFromFilter = document.getElementById('dateFromFilter');
            const dateToFilter = document.getElementById('dateToFilter');
            
            if (dateFromFilter && dateToFilter) {
                dateFromFilter.addEventListener('change', function() {
                    dateToFilter.min = this.value;
                });
                
                dateToFilter.addEventListener('change', function() {
                    dateFromFilter.max = this.value;
                });
            }
            
            // Setup rating stars
            const ratingStars = document.querySelectorAll('.rating-star');
            ratingStars.forEach(star => {
                star.addEventListener('mouseover', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const starValue = parseInt(this.getAttribute('data-value'));
                    
                    // Highlight this star and all stars before it
                    document.querySelectorAll('.rating-star[data-booking-id="'+bookingId+'"]').forEach(s => {
                        const sValue = parseInt(s.getAttribute('data-value'));
                        if (sValue <= starValue) {
                            s.querySelector('i').classList.remove('far');
                            s.querySelector('i').classList.add('fas');
                            s.querySelector('i').style.color = '#ffc107';
                        } else {
                            s.querySelector('i').classList.remove('fas');
                            s.querySelector('i').classList.add('far');
                            s.querySelector('i').style.color = '';
                        }
                    });
                });
                
                star.addEventListener('click', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const starValue = parseInt(this.getAttribute('data-value'));
                    document.getElementById('ratingValue'+bookingId).value = starValue;
                    
                    // Keep the stars highlighted after click
                    document.querySelectorAll('.rating-star[data-booking-id="'+bookingId+'"]').forEach(s => {
                        const sValue = parseInt(s.getAttribute('data-value'));
                        if (sValue <= starValue) {
                            s.querySelector('i').classList.remove('far');
                            s.querySelector('i').classList.add('fas');
                            s.querySelector('i').style.color = '#ffc107';
                        } else {
                            s.querySelector('i').classList.remove('fas');
                            s.querySelector('i').classList.add('far');
                            s.querySelector('i').style.color = '';
                        }
                    });
                });
                
                star.addEventListener('mouseout', function() {
                    const bookingId = this.getAttribute('data-booking-id');
                    const currentRating = parseInt(document.getElementById('ratingValue'+bookingId).value);
                    
                    // If no rating selected, revert to empty stars
                    if (currentRating === 0) {
                        document.querySelectorAll('.rating-star[data-booking-id="'+bookingId+'"]').forEach(s => {
                            s.querySelector('i').classList.remove('fas');
                            s.querySelector('i').classList.add('far');
                            s.querySelector('i').style.color = '';
                        });
                    } else {
                        // Otherwise keep current rating highlighted
                        document.querySelectorAll('.rating-star[data-booking-id="'+bookingId+'"]').forEach(s => {
                            const sValue = parseInt(s.getAttribute('data-value'));
                            if (sValue <= currentRating) {
                                s.querySelector('i').classList.remove('far');
                                s.querySelector('i').classList.add('fas');
                                s.querySelector('i').style.color = '#ffc107';
                            } else {
                                s.querySelector('i').classList.remove('fas');
                                s.querySelector('i').classList.add('far');
                                s.querySelector('i').style.color = '';
                            }
                        });
                    }
                });
            });
        });
        
        // Submit review function
        function submitReview(bookingId) {
            const form = document.getElementById('reviewForm' + bookingId);
            const rating = document.getElementById('ratingValue' + bookingId).value;
            const comment = document.getElementById('reviewComment' + bookingId).value;
            
            if (rating === '0') {
                alert('Please select a rating before submitting your review.');
                return false;
            }
            
            form.submit();
        }
        
        // Load and send messages functions
        function loadMessages(bookingId) {
            const chatContainer = document.getElementById('chatContainer' + bookingId);
            
            fetch('get-messages.php?booking_id=' + bookingId + '&csrf_token=' + document.getElementById('csrf_token' + bookingId).value)
                .then(response => response.json())
                .then(data => {
                    if (data.status === 'success') {
                        let messagesHTML = '';
                        if (data.messages.length === 0) {
                            messagesHTML = `<div class="text-center text-muted py-5">
                                <i class="fas fa-comments fa-3x mb-3"></i>
                                <p>No messages yet. Start the conversation!</p>
                            </div>`;
                        } else {
                            data.messages.forEach(msg => {
                                const isOutgoing = msg.sender_id == <?php echo $userId; ?>;
                                const messageClass = isOutgoing ? 'justify-content-end' : 'justify-content-start';
                                const bubbleClass = isOutgoing ? 'bg-primary text-white' : 'bg-light';
                                
                                messagesHTML += `
                                <div class="d-flex ${messageClass} mb-3">
                                    <div class="message-bubble p-2 px-3 rounded ${bubbleClass}" style="max-width: 75%;">
                                        <div class="message-text">${escapeHTML(msg.message)}</div>
                                        <div class="message-time small text-${isOutgoing ? 'light' : 'muted'} mt-1">
                                            ${new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}
                                        </div>
                                    </div>
                                </div>`;
                            });
                        }
                        chatContainer.innerHTML = messagesHTML;
                        chatContainer.scrollTop = chatContainer.scrollHeight;
                    } else {
                        chatContainer.innerHTML = `<div class="alert alert-danger">
                            Error loading messages: ${escapeHTML(data.message)}
                        </div>`;
                    }
                })
                .catch(error => {
                    chatContainer.innerHTML = `<div class="alert alert-danger">
                        Failed to load messages. Please try again.
                    </div>`;
                    console.error('Error loading messages:', error);
                });
        }
        
        // Helper function to escape HTML to prevent XSS
        function escapeHTML(str) {
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        
        function sendMessage(bookingId, providerId) {
            const input = document.getElementById('messageInput' + bookingId);
            const message = input.value.trim();
            const csrfToken = document.getElementById('csrf_token' + bookingId).value;
            
            if (message === '') return;
            
            const formData = new FormData();
            formData.append('booking_id', bookingId);
            formData.append('provider_id', providerId);
            formData.append('message', message);
            formData.append('csrf_token', csrfToken);
            
            fetch('send-message.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    input.value = '';
                    loadMessages(bookingId);
                } else {
                    alert('Failed to send message: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error sending message. Please try again.');
                console.error('Error sending message:', error);
            });
        }
        
        // Add listeners to message modals to load messages when opened
        document.addEventListener('DOMContentLoaded', function() {
            const messageModals = document.querySelectorAll('[id^="messageModal"]');
            messageModals.forEach(modal => {
                modal.addEventListener('show.bs.modal', function() {
                    const bookingId = this.id.replace('messageModal', '');
                    loadMessages(bookingId);
                });
            });
        });
        
        // Print booking function
        function printBooking(bookingId) {
            // Get CSRF token
            const csrfToken = '<?php echo $_SESSION["csrf_token"]; ?>';
            
            // Create a new window for printing
            const printWindow = window.open('', '_blank', 'width=800,height=600');
            
            // Fetch booking details via AJAX
            fetch('get-booking-details.php?id=' + bookingId + '&csrf_token=' + csrfToken)
                .then(response => response.text())
                .then(html => {
                    printWindow.document.open();
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>Booking #${bookingId} - Print</title>
                            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                            <style>
                                body { padding: 20px; font-family: Arial, sans-serif; }
                                .booking-header { border-bottom: 2px solid #7952b3; padding-bottom: 15px; margin-bottom: 20px; }
                                .booking-title { font-size: 24px; font-weight: bold; }
                                .booking-info { margin-bottom: 30px; }
                                .info-label { font-weight: bold; color: #6c757d; }
                                .qr-code { text-align: center; margin: 20px 0; }
                                .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
                                @media print {
                                    .no-print { display: none; }
                                    body { padding: 0; }
                                    .container { width: 100%; max-width: 100%; }
                                }
                            </style>
                        </head>
                        <body>
                            <div class="container">
                                <div class="booking-header d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="booking-title">FixItNow Booking Confirmation</div>
                                        <div>Booking #${bookingId}</div>
                                    </div>
                                    <div>
                                        <img src="../logo.png" alt="FixItNow Logo" style="height: 50px;">
                                    </div>
                                </div>
                                
                                <div id="bookingContent">
                                    ${html}
                                </div>
                                
                                <div class="qr-code">
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=fixitnow-booking-${bookingId}" alt="Booking QR Code">
                                    <p>Scan to view booking details</p>
                                </div>
                                
                                <div class="footer">
                                    <p>Thank you for choosing FixItNow for your repair needs!</p>
                                    <p>For any questions or assistance, please contact us at support@fixitnow.com</p>
                                    <p>&copy; 2025 FixItNow. All rights reserved.</p>
                                </div>
                                
                                <div class="mt-4 no-print text-center">
                                    <button class="btn btn-primary" onclick="window.print()">Print</button>
                                    <button class="btn btn-secondary ms-2" onclick="window.close()">Close</button>
                                </div>
                            </div>
                        </body>
                        </html>
                    `);
                    printWindow.document.close();
                })
                .catch(error => {
                    console.error('Error fetching booking details:', error);
                    printWindow.document.write('<html><body><h3>Error loading booking details.</h3></body></html>');
                    printWindow.document.close();
                });
        }
        
        // Function to show booking overview in modal
        function viewBookingOverview(bookingId) {
            const csrfToken = '<?php echo $_SESSION["csrf_token"]; ?>';
            const modal = new bootstrap.Modal(document.getElementById('bookingOverviewModal'));
            const modalContent = document.getElementById('bookingOverviewContent');
            modalContent.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-3x"></i><p class="mt-3">Loading booking details...</p></div>';
            
            fetch('get-booking-overview.php?id=' + bookingId + '&csrf_token=' + csrfToken)
                .then(response => response.text())
                .then(html => {
                    modalContent.innerHTML = html;
                })
                .catch(error => {
                    console.error('Error fetching booking overview:', error);
                    modalContent.innerHTML = '<div class="alert alert-danger">Error loading booking details. Please try again.</div>';
                });
                
            modal.show();
        }
    </script>
    
    <!-- Additional Needed Modal for Quick View -->
    <div class="modal fade" id="bookingOverviewModal" tabindex="-1" aria-labelledby="bookingOverviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingOverviewModalLabel">Booking Overview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="bookingOverviewContent">
                    <!-- Content will be loaded dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="viewFullDetailsBtn">View Full Details</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>