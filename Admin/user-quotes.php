<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Authentication check
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$adminId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// CSRF protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Initialize variables
$adminData = [];
$adminProfileImage = '../default.png';
$userData = [];
$userQuotes = [];
$technicians = [];
$errorMessage = '';
$successMessage = '';
$statusFilter = '';
$deviceFilter = '';
$startDate = '';
$endDate = '';
$totalQuotes = 0;
$totalPages = 1;
$prevPage = null;
$nextPage = null;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;

// Check for session messages (from redirects)
if (isset($_SESSION['action_message']) && !empty($_SESSION['action_message'])) {
    $successMessage = $_SESSION['action_message'];
    unset($_SESSION['action_message']);
}

// Build current URL for return_url parameter
$currentUrl = $_SERVER['REQUEST_URI'];

try {
    // Query to get admin user data
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
    if ($adminData && !empty($adminData['profile_image'])) {
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

if (!$userId) {
    header('Location: users.php');
    exit;
}

// Process quote status update with CSRF protection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote_status'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security validation failed. Please try again.";
    } else {
        $quoteId = isset($_POST['quote_id']) ? filter_var($_POST['quote_id'], FILTER_VALIDATE_INT) : 0;
        $requestId = isset($_POST['request_id']) ? filter_var($_POST['request_id'], FILTER_VALIDATE_INT) : 0;
        $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        
        $validStatuses = ['pending', 'quoted', 'accepted', 'completed', 'cancelled'];
        
        if (!$requestId) {
            $errorMessage = "Invalid quote request ID.";
        } elseif (!in_array($newStatus, $validStatuses)) {
            $errorMessage = "Invalid status value.";
        } else {
            try {
                // Start transaction
                $pdo->beginTransaction();
                
                // Check if the request exists first
                $checkStmt = $pdo->prepare("SELECT id, status FROM quote_requests WHERE id = ?");
                $checkStmt->execute([$requestId]);
                $request = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$request) {
                    throw new PDOException("Quote request with ID $requestId not found");
                }
                
                $currentStatus = $request['status'];
                
                // Update quote request status
                $stmt = $pdo->prepare("
                    UPDATE quote_requests 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $requestId]);
                
                // If a specific quote was accepted, update its status
                if ($quoteId && $newStatus === 'accepted') {
                    $stmt = $pdo->prepare("
                        UPDATE quotes 
                        SET status = 'accepted', updated_at = NOW() 
                        WHERE id = ?
                    ");
                    $stmt->execute([$quoteId]);
                    
                    // Mark other quotes for this request as rejected
                    $stmt = $pdo->prepare("
                        UPDATE quotes 
                        SET status = 'rejected', updated_at = NOW() 
                        WHERE request_id = ? AND id != ?
                    ");
                    $stmt->execute([$requestId, $quoteId]);
                }
                
                // Insert into notification log with proper escaping
                $safeNotes = empty($notes) ? "Status updated via admin panel" : "Status updated via admin panel: " . $notes;
                $stmt = $pdo->prepare("
                    INSERT INTO notification_logs 
                    (action, error_message)
                    VALUES (?, ?)
                ");
                $stmt->execute([
                    'admin_quote_status_update', 
                    "Admin updated quote request #$requestId from $currentStatus to $newStatus. Notes: $safeNotes"
                ]);
                
                // Commit transaction
                $pdo->commit();
                
                $successMessage = "Quote status updated successfully.";
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log("Database error updating quote status: " . $e->getMessage());
                $errorMessage = "An error occurred while updating the quote status.";
            }
        }
    }
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
    $validStatuses = ['pending', 'quoted', 'accepted', 'completed', 'cancelled'];
    $statusFilter = isset($_GET['status']) && in_array($_GET['status'], $validStatuses) ? $_GET['status'] : '';
    
    // Validate device filter using whitelist
    $validDevices = ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'];
    $deviceFilter = isset($_GET['device_type']) && in_array($_GET['device_type'], $validDevices) ? $_GET['device_type'] : '';
    
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
    
    // Fetch all technicians for filter/display with improved error handling
    try {
        $techStmt = $pdo->query("
            SELECT p.id, u.first_name, u.last_name 
            FROM providers p
            JOIN users u ON p.user_id = u.id
            WHERE u.status = 'active'
            ORDER BY u.first_name, u.last_name
        ");
        $technicians = $techStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching technicians: " . $e->getMessage());
        $technicians = [];
    }
    
    // Build query based on user role
    if ($userData['role'] === 'customer') {
        // Get quote requests made by this customer
        $query = "
            SELECT qr.*,
                   COUNT(q.id) AS quote_count
            FROM quote_requests qr
            LEFT JOIN quotes q ON qr.id = q.request_id
            WHERE qr.customer_id = ?
        ";
        
        $countQuery = "
            SELECT COUNT(*) 
            FROM quote_requests 
            WHERE customer_id = ?
        ";
        
        $params = [$userId];
    } else if ($userData['role'] === 'provider' && $userData['provider_id']) {
        // Get quotes provided by this technician
        $query = "
            SELECT qr.*, 
                   q.id AS quote_id,
                   q.price,
                   q.estimated_time,
                   q.status AS quote_status,
                   u.first_name AS customer_first_name,
                   u.last_name AS customer_last_name
            FROM quotes q
            JOIN quote_requests qr ON q.request_id = qr.id
            JOIN users u ON qr.customer_id = u.id
            WHERE q.provider_id = ?
        ";
        
        $countQuery = "
            SELECT COUNT(*) 
            FROM quotes 
            WHERE provider_id = ?
        ";
        
        $params = [$userData['provider_id']];
    } else {
        // Default to empty results for other roles
        $query = "SELECT 1 FROM quote_requests WHERE 1=0";
        $countQuery = "SELECT 0";
        $params = [];
    }
    
    // Add status filter if provided
    if (!empty($statusFilter)) {
        if ($userData['role'] === 'provider') {
            $query .= " AND q.status = ?";
            $countQuery .= " AND status = ?";
        } else {
            $query .= " AND qr.status = ?";
            $countQuery .= " AND status = ?";
        }
        $params[] = $statusFilter;
    }
    
    // Add device type filter if provided
    if (!empty($deviceFilter)) {
        $query .= " AND qr.device_type = ?";
        $countQuery .= " AND device_type = ?";
        $params[] = $deviceFilter;
    }
    
    // Add date range filter if provided
    if (!empty($startDate)) {
        $query .= " AND qr.created_at >= ?";
        $countQuery .= " AND created_at >= ?";
        $params[] = $startDate . ' 00:00:00';
    }
    
    if (!empty($endDate)) {
        $query .= " AND qr.created_at <= ?";
        $countQuery .= " AND created_at <= ?";
        $params[] = $endDate . ' 23:59:59';
    }
    
    // Add grouping for customer query
    if ($userData['role'] === 'customer') {
        $query .= " GROUP BY qr.id";
    }
    
    // Add sorting and pagination
    $query .= " ORDER BY qr.created_at DESC LIMIT ? OFFSET ?";
    $queryParams = $params;
    $queryParams[] = $perPage;
    $queryParams[] = $offset;
    
    // Execute the queries with improved error handling
    try {
        $stmt = $pdo->prepare($query);
        $stmt->execute($queryParams);
        $userQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get total count for pagination
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($params); // Use original params without limit and offset
        $totalQuotes = (int)$countStmt->fetchColumn();
        
        // Calculate pagination values
        $totalPages = ceil($totalQuotes / $perPage);
        $prevPage = ($page > 1) ? $page - 1 : null;
        $nextPage = ($page < $totalPages) ? $page + 1 : null;
        
        // For customer view, get quotes for each request
        if ($userData['role'] === 'customer' && !empty($userQuotes)) {
            foreach ($userQuotes as &$request) {
                try {
                    $quoteStmt = $pdo->prepare("
                        SELECT q.*, 
                               u.first_name, 
                               u.last_name,
                               p.specialties 
                        FROM quotes q
                        JOIN providers p ON q.provider_id = p.id
                        JOIN users u ON p.user_id = u.id
                        WHERE q.request_id = ?
                        ORDER BY q.created_at DESC
                    ");
                    $quoteStmt->execute([$request['id']]);
                    $request['quotes'] = $quoteStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    error_log("Error fetching quotes for request #" . $request['id'] . ": " . $e->getMessage());
                    $request['quotes'] = [];
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Database error executing main query: " . $e->getMessage());
        $errorMessage = "An error occurred while fetching quote data.";
        $userQuotes = [];
        $totalQuotes = 0;
        $totalPages = 1;
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching user data. Please try again later.";
    $userData = [];
    $userQuotes = [];
}

// Build pagination URL with improved security and parameter handling
function buildPaginationUrl($page, $currentParams = []) {
    $params = $_GET;
    $params['page'] = (int)$page; // Ensure the page is an integer
    
    if (!empty($currentParams)) {
        $params = array_merge($params, $currentParams);
    }
    
    // Filter out null and empty values
    $params = array_filter($params, function($value) { 
        return $value !== null && $value !== ''; 
    });
    
    // Use proper URL encoding for URL parameters
    return '?' . http_build_query($params);
}

// Function to get device icon
function getDeviceIcon($deviceType) {
    switch ($deviceType) {
        case 'smartphone':
            return 'fas fa-mobile-alt';
        case 'laptop':
            return 'fas fa-laptop';
        case 'tablet':
            return 'fas fa-tablet-alt';
        case 'desktop':
            return 'fas fa-desktop';
        case 'gaming':
            return 'fas fa-gamepad';
        case 'tv':
            return 'fas fa-tv';
        default:
            return 'fas fa-microchip';
    }
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

// Function to safely format prices
function formatPrice($price, $currency = 'SAR') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    return $currency . ' ' . number_format((float)$price, 2);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>User Quote Requests - FixItNow Admin</title>
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
        
        .status-badge.quoted {
            background-color: rgba(var(--bs-info-rgb), 0.1);
            color: var(--bs-info);
        }
        
        .status-badge.accepted {
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
        
        .status-badge.rejected {
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
        
        /* Quote list styles */
        .quote-item {
            border-left: 3px solid var(--primary-color);
            margin-bottom: 1rem;
            padding: 1rem;
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-radius: 0.5rem;
        }
        
        .quote-item.accepted {
            border-left-color: var(--bs-success);
            background-color: rgba(var(--bs-success-rgb), 0.05);
        }
        
        .quote-item.rejected {
            border-left-color: var(--bs-danger);
            background-color: rgba(var(--bs-danger-rgb), 0.05);
        }
        
        .device-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            border-radius: 50%;
            margin-right: 0.5rem;
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
                    <?php if($loggedIn && isset($adminData['username'])): ?>
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
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                            <span class="nav-text">Quote Requests</span>
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
                <h1 class="page-title">User Quote Requests</h1>
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
                                    <span class="role-badge <?php echo strtolower($userData['role'] ?? ''); ?> ms-2">
                                        <?php echo htmlspecialchars($userData['role'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </h2>
                                <div class="user-email mb-3">
                                    <i class="fas fa-envelope me-2" aria-hidden="true"></i><?php echo htmlspecialchars($userData['email'] ?? 'No email provided', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <?php if (isset($userData['role']) && $userData['role'] === 'customer'): ?>
                                <a href="user-bookings.php?user_id=<?php echo (int)$userId; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-calendar-check me-2" aria-hidden="true"></i>View Bookings
                                </a>
                                <?php endif; ?>
                                
                            </div>
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
                <form method="get" action="user-quotes.php" id="filter-form">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="mb-0">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $statusFilter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="quoted" <?php echo $statusFilter === 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                                    <option value="accepted" <?php echo $statusFilter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                    <option value="completed" <?php echo $statusFilter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $statusFilter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-0">
                                <label for="device_type" class="form-label">Device Type</label>
                                <select class="form-select" id="device_type" name="device_type">
                                    <option value="">All Devices</option>
                                    <option value="smartphone" <?php echo $deviceFilter === 'smartphone' ? 'selected' : ''; ?>>Smartphone</option>
                                    <option value="laptop" <?php echo $deviceFilter === 'laptop' ? 'selected' : ''; ?>>Laptop</option>
                                    <option value="tablet" <?php echo $deviceFilter === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                    <option value="desktop" <?php echo $deviceFilter === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                    <option value="gaming" <?php echo $deviceFilter === 'gaming' ? 'selected' : ''; ?>>Gaming Console</option>
                                    <option value="tv" <?php echo $deviceFilter === 'tv' ? 'selected' : ''; ?>>TV</option>
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
                        <div class="col-md-2 d-flex align-items-end">
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
            
            <!-- Quotes Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        Quote Requests
                        <?php if (!empty($statusFilter) || !empty($deviceFilter) || !empty($startDate) || !empty($endDate)): ?>
                        <small class="text-muted">(Filtered)</small>
                        <?php endif; ?>
                    </h5>
                    <span class="badge bg-primary rounded-pill"><?php echo $totalQuotes; ?> Requests</span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($userQuotes)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-clipboard-list" aria-hidden="true"></i>
                        </div>
                        <h3 class="empty-state-title">No Quote Requests Found</h3>
                        <p class="empty-state-text">
                            <?php if (!empty($statusFilter) || !empty($deviceFilter) || !empty($startDate) || !empty($endDate)): ?>
                            No quote requests match your filter criteria. Try adjusting your filters.
                            <?php else: ?>
                            This user has no quote requests yet.
                            <?php endif; ?>
                        </p>
                        <?php if (!empty($statusFilter) || !empty($deviceFilter) || !empty($startDate) || !empty($endDate)): ?>
                        <button type="button" id="clear-filter" class="btn btn-primary">
                            <i class="fas fa-filter me-2" aria-hidden="true"></i>Clear Filters
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    
                    <?php if ($userData['role'] === 'customer'): ?>
                    <!-- Customer View - Show Quote Requests -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Device</th>
                                    <th scope="col">Issue</th>
                                    <th scope="col">Quotes</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userQuotes as $request): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($request['id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo formatDate($request['created_at']); ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="device-icon">
                                                <i class="<?php echo getDeviceIcon($request['device_type']); ?>" aria-hidden="true"></i>
                                            </span>
                                            <div>
                                                <?php echo ucfirst(htmlspecialchars($request['device_type'], ENT_QUOTES, 'UTF-8')); ?>
                                                <?php if (!empty($request['device_brand']) && $request['device_brand'] !== 'Not specified'): ?>
                                                <div class="small text-muted"><?php echo htmlspecialchars($request['device_brand'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($request['issue_description'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($request['issue_description'], ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary rounded-pill">
                                            <?php echo isset($request['quote_count']) ? (int)$request['quote_count'] : count($request['quotes'] ?? []); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo ucfirst(htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="action-btn" data-bs-toggle="modal" data-bs-target="#viewRequestModal<?php echo (int)$request['id']; ?>" title="View Details">
                                            <i class="fas fa-eye" aria-hidden="true"></i><span class="sr-only">View details</span>
                                        </button>
                                        <button class="action-btn" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?php echo (int)$request['id']; ?>" title="Update Status">
                                            <i class="fas fa-edit" aria-hidden="true"></i><span class="sr-only">Update status</span>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <?php else: ?>
                    <!-- Provider View - Show Submitted Quotes -->
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Date</th>
                                    <th scope="col">Customer</th>
                                    <th scope="col">Device</th>
                                    <th scope="col">Price</th>
                                    <th scope="col">Quote Status</th>
                                    <th scope="col">Request Status</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userQuotes as $quote): ?>
                                <tr>
                                    <td>#<?php echo htmlspecialchars($quote['quote_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo formatDate($quote['created_at']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($quote['customer_first_name'] . ' ' . $quote['customer_last_name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <span class="device-icon">
                                                <i class="<?php echo getDeviceIcon($quote['device_type']); ?>" aria-hidden="true"></i>
                                            </span>
                                            <div>
                                                <?php echo ucfirst(htmlspecialchars($quote['device_type'], ENT_QUOTES, 'UTF-8')); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong><?php echo formatPrice($quote['price']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($quote['quote_status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo ucfirst(htmlspecialchars($quote['quote_status'], ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo ucfirst(htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <button class="action-btn" data-bs-toggle="modal" data-bs-target="#viewQuoteModal<?php echo (int)$quote['quote_id']; ?>" title="View Details">
                                            <i class="fas fa-eye" aria-hidden="true"></i><span class="sr-only">View details</span>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                    
                    <?php endif; ?>
                </div>
                
                <?php if ($totalPages > 1): ?>
                <!-- Pagination Footer -->
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        Showing <?php echo min(($page - 1) * $perPage + 1, $totalQuotes); ?> to <?php echo min($page * $perPage, $totalQuotes); ?> of <?php echo $totalQuotes; ?> quote requests
                    </div>
                    
                    <nav aria-label="Pagination navigation">
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
    
    <!-- Dynamic Modals for Quote Requests -->
    <?php if ($userData['role'] === 'customer'): ?>
    <?php foreach ($userQuotes as $request): ?>
    <!-- View Request Modal -->
    <div class="modal fade" id="viewRequestModal<?php echo (int)$request['id']; ?>" tabindex="-1" aria-labelledby="viewRequestModalLabel<?php echo (int)$request['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewRequestModalLabel<?php echo (int)$request['id']; ?>">Quote Request #<?php echo (int)$request['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-4">
                        <span class="status-badge <?php echo htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo ucfirst(htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                        <span class="text-muted">
                            <i class="fas fa-calendar-alt me-1" aria-hidden="true"></i> <?php echo formatDate($request['created_at'], 'F d, Y'); ?>
                        </span>
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Device Information</h6>
                            <p class="mb-1">
                                <i class="<?php echo getDeviceIcon($request['device_type']); ?> me-2 text-primary" aria-hidden="true"></i>
                                <strong>Type:</strong> <?php echo ucfirst(htmlspecialchars($request['device_type'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                            <?php if (!empty($request['device_brand']) && $request['device_brand'] !== 'Not specified'): ?>
                            <p class="mb-1">
                                <i class="fas fa-tag me-2 text-primary" aria-hidden="true"></i>
                                <strong>Brand:</strong> <?php echo htmlspecialchars($request['device_brand'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php endif; ?>
                            <?php if (!empty($request['device_model']) && $request['device_model'] !== 'Not specified'): ?>
                            <p class="mb-1">
                                <i class="fas fa-info-circle me-2 text-primary" aria-hidden="true"></i>
                                <strong>Model:</strong> <?php echo htmlspecialchars($request['device_model'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <?php endif; ?>
                            <p class="mb-1">
                                <i class="fas fa-heart me-2 text-primary" aria-hidden="true"></i>
                                <strong>Condition:</strong> <?php echo ucfirst(htmlspecialchars($request['device_condition'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-muted mb-2">Service Details</h6>
                            <p class="mb-1">
                                <i class="fas fa-map-marker-alt me-2 text-primary" aria-hidden="true"></i>
                                <strong>Location:</strong> <?php echo htmlspecialchars($request['location'], ENT_QUOTES, 'UTF-8'); ?>
                            </p>
                            <p class="mb-1">
                                <i class="fas fa-exclamation-circle me-2 text-primary" aria-hidden="true"></i>
                                <strong>Urgency:</strong> <?php echo ucfirst(htmlspecialchars($request['urgency'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                            <p class="mb-1">
                                <i class="fas fa-tools me-2 text-primary" aria-hidden="true"></i>
                                <strong>Service Type:</strong> <?php echo ucfirst(htmlspecialchars($request['service_type'], ENT_QUOTES, 'UTF-8')); ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Issue Description</h6>
                        <div class="p-3 bg-light rounded">
                            <?php echo nl2br(htmlspecialchars($request['issue_description'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($request['additional_info'])): ?>
                    <div class="mb-4">
                        <h6 class="text-muted mb-2">Additional Information</h6>
                        <div class="p-3 bg-light rounded">
                            <?php echo nl2br(htmlspecialchars($request['additional_info'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($request['quotes'])): ?>
                    <div>
                        <h6 class="text-muted mb-3">Quotes Received (<?php echo count($request['quotes']); ?>)</h6>
                        
                        <?php foreach ($request['quotes'] as $quote): ?>
                        <div class="quote-item <?php echo htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="d-flex justify-content-between">
                                <h6><?php echo htmlspecialchars($quote['first_name'] . ' ' . $quote['last_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <span class="status-badge <?php echo htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo ucfirst(htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8')); ?>
                                </span>
                            </div>
                            <div class="row g-3 mt-1">
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-coins me-2 text-warning" aria-hidden="true"></i>
                                        <div>
                                            <small class="text-muted d-block">Price</small>
                                            <strong><?php echo formatPrice($quote['price']); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-clock me-2 text-info" aria-hidden="true"></i>
                                        <div>
                                            <small class="text-muted d-block">Estimated Time</small>
                                            <strong><?php echo htmlspecialchars($quote['estimated_time'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-shield-alt me-2 text-success" aria-hidden="true"></i>
                                        <div>
                                            <small class="text-muted d-block">Warranty</small>
                                            <strong><?php echo htmlspecialchars($quote['warranty'] ?? 'Standard', ENT_QUOTES, 'UTF-8'); ?></strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($quote['parts_needed'])): ?>
                            <div class="mt-2">
                                <small class="text-muted">Parts Needed:</small>
                                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($quote['parts_needed'], ENT_QUOTES, 'UTF-8')); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($quote['description'])): ?>
                            <div class="mt-2">
                                <small class="text-muted">Description:</small>
                                <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($quote['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($request['status'] === 'pending' || $request['status'] === 'quoted'): ?>
                            <div class="mt-3 text-end">
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="update_quote_status" value="1">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                                    <input type="hidden" name="quote_id" value="<?php echo (int)$quote['id']; ?>">
                                    <input type="hidden" name="status" value="accepted">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="fas fa-check me-1" aria-hidden="true"></i> Accept Quote
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 bg-light rounded">
                        <i class="fas fa-quote-left fa-2x mb-3 text-muted" aria-hidden="true"></i>
                        <p>No quotes have been received for this request yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal<?php echo (int)$request['id']; ?>" data-bs-dismiss="modal">
                        Update Status
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal<?php echo (int)$request['id']; ?>" tabindex="-1" aria-labelledby="updateStatusModalLabel<?php echo (int)$request['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel<?php echo (int)$request['id']; ?>">Update Quote Request Status #<?php echo (int)$request['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="user_id" value="<?php echo (int)$userId; ?>">
                    <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                    <input type="hidden" name="update_quote_status" value="1">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="status<?php echo (int)$request['id']; ?>" class="form-label">Status</label>
                            <select class="form-select" id="status<?php echo (int)$request['id']; ?>" name="status" required>
                                <option value="pending" <?php echo $request['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="quoted" <?php echo $request['status'] === 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                                <option value="accepted" <?php echo $request['status'] === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                <option value="completed" <?php echo $request['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $request['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notes<?php echo (int)$request['id']; ?>" class="form-label">Notes</label>
                            <textarea class="form-control" id="notes<?php echo (int)$request['id']; ?>" name="notes" rows="3" placeholder="Optional notes about this status change" maxlength="500"></textarea>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2" aria-hidden="true"></i>
                            Changing the status will affect any associated quotes and may trigger notifications.
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
    
    <?php else: ?>
    <!-- Provider modals for quotes submitted -->
    <?php foreach ($userQuotes as $quote): ?>
    <!-- View Quote Modal -->
    <div class="modal fade" id="viewQuoteModal<?php echo (int)$quote['quote_id']; ?>" tabindex="-1" aria-labelledby="viewQuoteModalLabel<?php echo (int)$quote['quote_id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewQuoteModalLabel<?php echo (int)$quote['quote_id']; ?>">Quote Details #<?php echo (int)$quote['quote_id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="status-badge <?php echo htmlspecialchars($quote['quote_status'], ENT_QUOTES, 'UTF-8'); ?>">
                            Quote: <?php echo ucfirst(htmlspecialchars($quote['quote_status'], ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                        <span class="status-badge <?php echo htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8'); ?>">
                            Request: <?php echo ucfirst(htmlspecialchars($quote['status'], ENT_QUOTES, 'UTF-8')); ?>
                        </span>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Customer</h6>
                        <div class="d-flex align-items-center">
                            <i class="fas fa-user me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo htmlspecialchars($quote['customer_first_name'] . ' ' . $quote['customer_last_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Device</h6>
                        <div class="d-flex align-items-center">
                            <i class="<?php echo getDeviceIcon($quote['device_type']); ?> me-2 text-primary" aria-hidden="true"></i>
                            <div>
                                <strong><?php echo ucfirst(htmlspecialchars($quote['device_type'], ENT_QUOTES, 'UTF-8')); ?></strong>
                                <?php if (!empty($quote['device_brand']) && $quote['device_brand'] !== 'Not specified'): ?>
                                <div class="small"><?php echo htmlspecialchars($quote['device_brand'] . ' ' . $quote['device_model'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Issue</h6>
                        <div>
                            <?php echo nl2br(htmlspecialchars($quote['issue_description'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    </div>
                    
                    <div class="mb-3 border-bottom pb-3">
                        <h6 class="text-muted">Quote Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-coins me-2 text-warning" aria-hidden="true"></i>
                                    <div>
                                        <small class="text-muted d-block">Price</small>
                                        <strong><?php echo formatPrice($quote['price']); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-clock me-2 text-info" aria-hidden="true"></i>
                                    <div>
                                        <small class="text-muted d-block">Estimated Time</small>
                                        <strong><?php echo htmlspecialchars($quote['estimated_time'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

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
                window.location.href = 'user-quotes.php?user_id=<?php echo (int)$userId; ?>';
            });
            
            // Clear filter button (in empty state)
            const clearFilterBtn = document.getElementById('clear-filter');
            if (clearFilterBtn) {
                clearFilterBtn.addEventListener('click', function() {
                    window.location.href = 'user-quotes.php?user_id=<?php echo (int)$userId; ?>';
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