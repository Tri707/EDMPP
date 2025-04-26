<?php
session_start();

// Add CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
if (!isset($pdo)) {
    include '../conn.php';
}

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to prevent XSS when outputting data
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Initialize arrays and variables
$userData = null;
$providerData = null;
$profileImage = '../default.png';
$notifications = [];
$quoteRequests = [];
$totalRequests = 0;
$errors = [];
$success = '';
$providerSpecialties = [];

// Pagination variables
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Validate pagination parameters
if ($page < 1) $page = 1;
if ($limit < 5) $limit = 5;
if ($limit > 50) $limit = 50;

// Filter variables
$status = isset($_GET['status']) ? clean_input($_GET['status']) : 'pending';
$deviceType = isset($_GET['device_type']) ? clean_input($_GET['device_type']) : '';
$searchTerm = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'newest';
$showAllRequests = isset($_GET['show_all']) && $_GET['show_all'] == '1';

// Validate status
$validStatuses = ['all', 'pending', 'quoted', 'accepted', 'completed', 'cancelled'];
if (!in_array($status, $validStatuses)) {
    $status = 'pending';
}

// Database error handler
function handleDatabaseError($e, $operation) {
    error_log("Database error in {$operation}: " . $e->getMessage());
    return false;
}

// Load user and provider data
try {
    // Get user data
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get provider data
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$userId]);
    $providerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Extract provider specialties
    if ($providerData && !empty($providerData['specialties'])) {
        $providerSpecialties = explode(',', $providerData['specialties']);
        
        // Handle special case where "computer" might map to both laptop and desktop
        if (in_array('computer', $providerSpecialties)) {
            $providerSpecialties[] = 'laptop';
            $providerSpecialties[] = 'desktop';
        }
    }
    
    // Set profile image path with proper security checks
    if ($userData && !empty($userData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
            // External URL
            $profileImage = $userData['profile_image'];
        } else {
            // Local file - safely handle path
            $profileImage = '../profile_images/' . basename($userData['profile_image']);
        }
    }
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching user/provider data');
}

// Get provider ID
$providerId = $providerData['id'] ?? 0;
if (!$providerId) {
    $errors[] = "Provider profile not found. Please complete your profile setup.";
}

// Get notifications
try {
    $notifQuery = "
        SELECT * FROM notifications
        WHERE provider_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([$providerId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching notifications');
}

// Build query conditions based on filters
$conditions = [];
$params = [];

// Define status condition
if ($status !== 'all') {
    $conditions[] = "qr.status = ?";
    $params[] = $status;
}

// Define device type condition
if (!empty($deviceType)) {
    $conditions[] = "qr.device_type = ?";
    $params[] = $deviceType;
}

// Define search condition
if (!empty($searchTerm)) {
    $conditions[] = "(qr.issue_description LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR qr.device_brand LIKE ? OR qr.device_model LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Only filter by specialties if not showing all requests
if (!$showAllRequests && !empty($providerSpecialties)) {
    $placeholders = array_fill(0, count($providerSpecialties), '?');
    $conditions[] = "qr.device_type IN (" . implode(',', $placeholders) . ")";
    $params = array_merge($params, $providerSpecialties);
}

// Combine conditions
$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Define sorting
$orderClause = "ORDER BY qr.created_at DESC"; // Default sorting (newest)

if ($sortBy === 'oldest') {
    $orderClause = "ORDER BY qr.created_at ASC";
} elseif ($sortBy === 'urgency_high') {
    $orderClause = "ORDER BY FIELD(qr.urgency, 'high', 'medium', 'low') ASC, qr.created_at DESC";
} elseif ($sortBy === 'urgency_low') {
    $orderClause = "ORDER BY FIELD(qr.urgency, 'low', 'medium', 'high') ASC, qr.created_at DESC";
}

// Get total count for pagination
try {
    $countQuery = "
        SELECT COUNT(*) as total
        FROM quote_requests qr
        JOIN users u ON qr.customer_id = u.id
        $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRequests = $result['total'] ?? 0;
} catch (PDOException $e) {
    handleDatabaseError($e, 'counting quote requests');
}

// Calculate pagination values
$totalPages = ceil($totalRequests / $limit);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Get quote requests with pagination
try {
    $requestsQuery = "
        SELECT qr.*, 
               u.username as customer_name,
               u.first_name,
               u.last_name, 
               u.email as customer_email,
               u.phone as customer_phone,
               (SELECT COUNT(*) FROM quotes q WHERE q.request_id = qr.id) as quote_count
        FROM quote_requests qr
        JOIN users u ON qr.customer_id = u.id
        $whereClause
        $orderClause
        LIMIT ?, ?
    ";
    
    $stmt = $pdo->prepare($requestsQuery);
    $params[] = $offset;
    $params[] = $limit;
    $stmt->execute($params);
    $quoteRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching quote requests');
    $errors[] = "Failed to retrieve quote requests. Please try again later.";
}

// Handle request status update (if form is submitted)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Security validation failed. Please try again.";
    } else {
        $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
        $newStatus = isset($_POST['new_status']) ? clean_input($_POST['new_status']) : '';
        
        // Validate status
        if (!in_array($newStatus, ['pending', 'quoted', 'cancelled'])) {
            $errors[] = "Invalid status selected.";
        } else {
            try {
                // First check if request exists and provider has permission
                $checkQuery = "
                    SELECT * FROM quote_requests 
                    WHERE id = ? AND (specific_provider_id IS NULL OR specific_provider_id = ?)
                ";
                $stmt = $pdo->prepare($checkQuery);
                $stmt->execute([$requestId, $providerId]);
                $request = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$request) {
                    $errors[] = "Quote request not found or you don't have permission to update it.";
                } else {
                    // Check if device type matches provider specialties
                    if (!$showAllRequests && !empty($providerSpecialties) && !in_array($request['device_type'], $providerSpecialties)) {
                        $errors[] = "This request doesn't match your specialties.";
                    } else {
                        // Update the status
                        $updateQuery = "UPDATE quote_requests SET status = ? WHERE id = ?";
                        $stmt = $pdo->prepare($updateQuery);
                        $stmt->execute([$newStatus, $requestId]);
                        
                        if ($stmt->rowCount() > 0) {
                            $success = "Quote request status updated successfully.";
                            
                            // Create a notification for the customer
                            $notificationQuery = "
                                INSERT INTO notifications (customer_id, type, reference_id, message, status)
                                VALUES (?, 'quote_request', ?, ?, 'pending')
                            ";
                            
                            $message = "Your quote request has been marked as " . ucfirst($newStatus);
                            $stmt = $pdo->prepare($notificationQuery);
                            $stmt->execute([$request['customer_id'], $requestId, $message]);
                            
                            // Redirect to prevent form resubmission
                            $showAllParam = $showAllRequests ? '&show_all=1' : '';
                            header("Location: quote-requests.php?status={$status}&device_type={$deviceType}&search={$searchTerm}&sort={$sortBy}&page={$page}{$showAllParam}&success=1");
                            exit();
                        } else {
                            $errors[] = "Failed to update request status.";
                        }
                    }
                }
            } catch (PDOException $e) {
                handleDatabaseError($e, 'updating quote request status');
                $errors[] = "Database error occurred while updating status.";
            }
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Quote request status updated successfully.";
}

// Function to get device type icon
function getDeviceIcon($deviceType) {
    switch ($deviceType) {
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

// Function to get urgency label and class
function getUrgencyLabel($urgency) {
    switch ($urgency) {
        case 'high':
            return '<span class="badge bg-danger">High</span>';
        case 'medium':
            return '<span class="badge bg-warning text-dark">Medium</span>';
        case 'low':
            return '<span class="badge bg-info text-dark">Low</span>';
        default:
            return '<span class="badge bg-secondary">Standard</span>';
    }
}

// Function to get status label and class
function getStatusLabel($status) {
    switch ($status) {
        case 'pending':
            return '<span class="badge bg-warning text-dark">Pending</span>';
        case 'quoted':
            return '<span class="badge bg-info text-dark">Quoted</span>';
        case 'accepted':
            return '<span class="badge bg-success">Accepted</span>';
        case 'completed':
            return '<span class="badge bg-primary">Completed</span>';
        case 'cancelled':
            return '<span class="badge bg-danger">Cancelled</span>';
        default:
            return '<span class="badge bg-secondary">Unknown</span>';
    }
}

// Function to format date
function formatDate($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return "Just now";
            }
            return $diff->i . " min ago";
        }
        return $diff->h . " hour" . ($diff->h > 1 ? "s" : "") . " ago";
    } elseif ($diff->days == 1) {
        return "Yesterday at " . $date->format('g:i A');
    } elseif ($diff->days < 7) {
        return $diff->days . " day" . ($diff->days > 1 ? "s" : "") . " ago";
    } else {
        return $date->format('M j, Y g:i A');
    }
}

// Function to truncate text
function truncateText($text, $length = 100, $append = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $append;
}

// Get a readable specialties list
function getSpecialtiesList($specialties) {
    if (empty($specialties)) {
        return "None";
    }
    
    // Capitalize each specialty
    $formatted = array_map(function($s) {
        return ucfirst($s);
    }, $specialties);
    
    return implode(', ', $formatted);
}

// Determine theme preference
$theme = 'light';
if (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') {
    $theme = 'dark';
} elseif (isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) && 
         $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark') {
    $theme = 'dark';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage customer quote requests and create repair quotes - FixItNow Provider Portal">
    <title>Quote Requests - FixItNow Provider</title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" as="script">
    
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
            --sidebar-bg: #f8f9fa;         /* Sidebar background */
            --sidebar-active: #e9ecef;     /* Sidebar active item */
            
            /* Animation speeds */
            --transition-speed: 0.3s;
        }
        
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #9775fa;      /* Lighter Purple for dark mode */
            --primary-hover: #845ef7;      /* Purple hover for dark mode */
            --primary-light: #382d52;      /* Darker purple light for dark mode */
            --accent-color: #40c057;       /* Brighter Green for dark mode */
            --accent-light: #215c2e;       /* Darker green light for dark mode */
            --text-color: #e9ecef;         /* Light text for dark mode */
            --text-muted: #adb5bd;         /* Muted text for dark mode */
            --bg-color: #121212;           /* Dark background */
            --card-bg: #1e1e1e;            /* Card background */
            --header-bg: #0f0f0f;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #0f0f0f;          /* Footer background */
            --footer-text: #adb5bd;        /* Footer text */
            --border-color: #343a40;       /* Border color */
            --input-bg: #2b2b2b;           /* Input background */
            --input-border: #444;          /* Input border */
            --modal-bg: #1e1e1e;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.3); /* Shadow color */
            --sidebar-bg: #181818;         /* Sidebar background */
            --sidebar-active: #2c2c2c;     /* Sidebar active item */
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            transition: background-color var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }
        
        .site-header .container {
            max-width: 1400px;
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            transition: color var(--transition-speed) ease;
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            transition: color var(--transition-speed) ease;
        }
        
        /* Header Transparent with Scroll Effect (Optional) */
        .site-header.transparent {
            background-color: transparent;
            box-shadow: none;
        }
        
        .site-header.shadow-on-scroll {
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Improved Navigation Links */
        .header-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .header-nav a.nav-link {
            color: var(--header-text);
            opacity: 0.85;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all var(--transition-speed) ease;
            border-radius: 0.5rem;
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .header-nav a.nav-link:hover {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .header-nav a.nav-link.active {
            opacity: 1;
            color: var(--header-text);
            background-color: var(--primary-color);
        }
        
        .header-nav a.nav-link .badge {
            position: relative;
            top: -2px;
        }
        
        /* Search Bar Styling */
        .search-container {
            max-width: 350px;
            width: 100%;
        }
        
        .search-container input {
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border: 1px solid transparent;
            transition: all var(--transition-speed) ease;
        }
        
        .search-container input:focus {
            background-color: var(--card-bg);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.15);
        }
        
        .search-container button {
            background: transparent;
            border: none;
        }
        
        /* Buttons & Icon Buttons */
        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            border: none;
            padding: 0;
            position: relative;
        }
        
        .btn-icon:hover, .btn-icon:focus {
            background-color: rgba(255, 255, 255, 0.2);
            color: var(--header-text);
        }
        
        /* Enhanced Notifications */
        .notification-dropdown {
            width: 320px;
            max-height: 480px;
            overflow-y: auto;
            padding: 0;
            border: none;
            border-radius: 0.75rem;
        }
        
        .notification-item {
            transition: background-color var(--transition-speed) ease;
            border-left: 3px solid transparent;
        }
        
        .notification-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-left-color: var(--primary-color);
        }
        
        .notification-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Dashboard Specific Styles */
        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 76px); /* Header height */
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            padding: 1.5rem 1rem;
            border-right: 1px solid var(--border-color);
            transition: background-color var(--transition-speed) ease, transform var(--transition-speed) ease;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--text-color);
            transition: all var(--transition-speed) ease;
        }
        
        .sidebar-menu a:hover {
            background-color: var(--sidebar-active);
        }
        
        .sidebar-menu a.active {
            background-color: var(--primary-color);
            color: var(--header-text);
        }
        
        .sidebar-menu i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }
        
        .dashboard-content {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        
        /* Filter Card Styles */
        .filter-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* Quote Request Card Styles */
        .request-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .request-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .request-card .card-header {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: none;
            padding: 1rem;
        }
        
        .request-card .device-icon {
            width: 48px;
            height: 48px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .request-card .customer-info {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .request-card .customer-info .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        /* Timeline styles */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 7px;
            width: 2px;
            background-color: var(--primary-light);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .timeline-marker {
            position: absolute;
            top: 0;
            left: -2rem;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: var(--primary-color);
            transform: translateX(0);
        }
        
        /* Detail list styles */
        .detail-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .detail-list li {
            display: flex;
            margin-bottom: 0.75rem;
        }
        
        .detail-list .detail-label {
            min-width: 120px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        /* Status Badge Styles */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Filter Pills */
        .filter-pill {
            display: inline-flex;
            align-items: center;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 20px;
            padding: 0.35rem 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .filter-pill .remove-filter {
            margin-left: 0.5rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity var(--transition-speed) ease;
        }
        
        .filter-pill .remove-filter:hover {
            opacity: 1;
        }
        
        /* Pagination Styles */
        .pagination .page-item .page-link {
            border-radius: 0.5rem;
            margin: 0 0.25rem;
            color: var(--primary-color);
            transition: all var(--transition-speed) ease;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        /* Quick action button */
        .quick-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 0.25rem 1rem var(--shadow-color);
            transition: all var(--transition-speed) ease;
            z-index: 100;
        }
        
        .quick-action-btn:hover {
            transform: scale(1.1);
            background-color: var(--primary-hover);
            color: white;
        }
        
        /* Specialties badge */
        .specialty-match {
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 2;
        }
        
        /* Mobile Offcanvas */
        .offcanvas {
            max-width: 320px;
            border-right: 1px solid var(--border-color);
            background-color: var(--card-bg);
        }
        
        .offcanvas-title {
            font-weight: 700;
            color: var(--accent-color);
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            
            .dashboard-content {
                padding: 1.5rem 1rem;
            }
            
            .request-card .card-header {
                flex-direction: column !important;
                align-items: flex-start !important;
            }
            
            .request-card .device-info {
                margin-bottom: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .filter-card .filter-body {
                flex-direction: column;
            }
            
            .filter-card .filter-item {
                margin-bottom: 1rem;
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .request-card {
                margin-bottom: 1rem;
            }
            
            .request-card .customer-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .request-card .customer-info .customer-avatar {
                margin-bottom: 0.5rem;
            }
            
            .request-card .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .request-card .action-buttons .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-auto d-flex align-items-center">
                    <!-- Logo with higher contrast -->
                    <a href="index.php" class="text-decoration-none d-flex align-items-center" aria-label="FixItNow Home">
                        <div class="logo-text">
                            <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                        </div>
                    </a>
                </div>
                
                <!-- Search Bar (New) -->
                <div class="col d-none d-lg-block mx-4">
                    <div class="search-container position-relative">
                        <form action="search.php" method="GET" class="d-flex">
                            <input type="text" name="q" class="form-control bg-light border-0 rounded-pill ps-4 pe-5" placeholder="Search for repairs, customers, parts..." aria-label="Search">
                            <button type="submit" class="btn position-absolute end-0 top-0 h-100 px-3" aria-label="Submit search">
                                <i class="fas fa-search text-muted" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Desktop Navigation -->
                <div class="col-auto d-none d-lg-block me-auto">
                    <nav class="header-nav" aria-label="Main navigation">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1" aria-hidden="true"></i> Dashboard
                        </a>
                        <a class="nav-link active" href="quote-requests.php" aria-current="page">
                            <i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i> Quotes
                        </a>
                      
                    </nav>
                </div>
                
                <!-- Right Side Controls -->
                <div class="col-auto d-flex align-items-center gap-2">
                    <!-- Quick Actions Button (New) -->
                    <div class="dropdown d-none d-md-block">
                        <button class="btn btn-primary rounded-pill px-3 py-1" type="button" id="quickActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bolt me-md-1" aria-hidden="true"></i>
                            <span class="d-none d-md-inline">Quick Actions</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="quickActionsDropdown">
                            <li><h6 class="dropdown-header">Common Tasks</h6></li>
                            <li><a class="dropdown-item" href="create-quote.php"><i class="fas fa-file-invoice me-2" aria-hidden="true"></i> Create Quote</a></li>
                            <li><a class="dropdown-item" href="schedule-repair.php"><i class="fas fa-calendar-plus me-2" aria-hidden="true"></i> Schedule Repair</a></li>
                            <li><a class="dropdown-item" href="update-repair.php"><i class="fas fa-wrench me-2" aria-hidden="true"></i> Update Repair Status</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="add-service.php"><i class="fas fa-plus-circle me-2" aria-hidden="true"></i> Add New Service</a></li>
                        </ul>
                    </div>
                    
                    <!-- Notifications -->
                    <div class="dropdown">
                        <button class="btn btn-icon position-relative notification-badge" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                            <?php if(count($notifications) > 0): ?>
                            <span class="badge bg-danger rounded-pill position-absolute top-0 end-0 translate-middle" aria-label="<?php echo count($notifications); ?> unread notifications"><?php echo count($notifications); ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown shadow-lg" aria-labelledby="notificationsDropdown">
                            <li><h6 class="dropdown-header d-flex justify-content-between align-items-center">
                                Notifications
                                <?php if(count($notifications) > 0): ?>
                                <a href="mark-all-read.php" class="text-decoration-none text-muted small">
                                    <i class="fas fa-check-double" aria-hidden="true"></i> Mark all read
                                </a>
                                <?php endif; ?>
                            </h6></li>
                            
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted d-flex align-items-center py-3">
                                    <div class="text-center w-100">
                                        <i class="fas fa-bell-slash fa-2x mb-2 text-muted" aria-hidden="true"></i>
                                        <p class="mb-0">No new notifications</p>
                                    </div>
                                </div></li>
                            <?php else: ?>
                                <?php foreach($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item notification-item p-3 border-bottom" href="notification-details.php?id=<?php echo $notification['id']; ?>">
                                        <div class="d-flex">
                                            <?php 
                                            // Determine icon based on notification type
                                            $icon = 'bell';
                                            $iconClass = 'primary';
                                            if ($notification['type'] == 'quote_request') {
                                                $icon = 'file-invoice-dollar';
                                                $iconClass = 'success';
                                            } elseif ($notification['type'] == 'booking') {
                                                $icon = 'calendar-check';
                                                $iconClass = 'info';
                                            } elseif ($notification['type'] == 'message') {
                                                $icon = 'envelope';
                                                $iconClass = 'warning';
                                            } elseif ($notification['type'] == 'review') {
                                                $icon = 'star';
                                                $iconClass = 'danger';
                                            }
                                            ?>
                                            <div class="flex-shrink-0 notification-icon bg-<?php echo $iconClass; ?>-light text-<?php echo $iconClass; ?> rounded-circle p-2 me-3">
                                                <i class="fas fa-<?php echo $icon; ?>" aria-hidden="true"></i>
                                            </div>
                                            <div class="flex-grow-1 notification-content">
                                                <div class="d-flex w-100 justify-content-between mb-1">
                                                    <strong><?php echo e(truncateText($notification['message'], 40)); ?></strong>
                                                    <small class="text-muted ms-2"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                                </div>
                                                <?php if(!empty($notification['details'])): ?>
                                                <p class="text-muted small mb-0">
                                                    <?php echo e(truncateText($notification['details'], 60)); ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider m-0"></li>
                                <li><a class="dropdown-item text-center p-2" href="notifications.php">View All Notifications</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="btn btn-icon" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-sun" id="themeIcon" aria-hidden="true"></i>
                    </button>
                    
                    <!-- User Profile -->
                    <?php if($loggedIn && isset($userData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn d-flex align-items-center gap-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="position-relative">
                                <img src="<?php echo e($profileImage); ?>" alt="" class="rounded-circle" width="36" height="36">
                                <span class="position-absolute bottom-0 end-0 bg-success rounded-circle p-1 border border-white" title="Online" aria-hidden="true"></span>
                            </div>
                            <div class="d-none d-md-block text-start">
                                <div class="text-nowrap fw-semibold"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                                <div class="text-muted small text-nowrap"><?php echo e($userData['role']); ?></div>
                            </div>
                            <i class="fas fa-chevron-down small ms-1 d-none d-md-inline" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="userDropdown">
                            <li>
                                <div class="dropdown-item d-flex align-items-center py-2 px-3">
                                    <div class="flex-shrink-0 me-2 d-md-none">
                                        <div class="fw-semibold"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                                        <div class="text-muted small"><?php echo e($userData['email']); ?></div>
                                    </div>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider d-md-none my-1"></li>
                            <?php if($userRole == 'admin'): ?>
                                <li><a class="dropdown-item py-2" href="admin/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Admin Dashboard</a></li>
                            <?php elseif($userRole == 'provider'): ?>
                                <li><a class="dropdown-item py-2" href="provider/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Provider Dashboard</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item py-2" href="customer/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> My Account</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item py-2" href="profile.php"><i class="fas fa-user-cog me-2" aria-hidden="true"></i> Edit Profile</a></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item py-2" href="help.php"><i class="fas fa-question-circle me-2" aria-hidden="true"></i> Help Center</a></li>
                            <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div>
                        <a href="login.php" class="btn btn-outline-light me-2">Login</a>
                        <a href="signup.php" class="btn btn-success">Sign Up</a>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Mobile Menu Toggle -->
                    <button class="navbar-toggler btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-expanded="false" aria-label="Toggle navigation">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile Navigation Offcanvas -->
        <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="mobileNavLabel">
                    <i class="fas fa-tools me-2" aria-hidden="true"></i>FixItNow
                </h5>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body p-0">
                <!-- Mobile Search -->
                <div class="p-3">
                    <form action="search.php" method="GET">
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Search..." aria-label="Search">
                            <button class="btn btn-outline-secondary" type="submit" aria-label="Search"><i class="fas fa-search" aria-hidden="true"></i></button>
                        </div>
                    </form>
                </div>
                
                <!-- Mobile Menu -->
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Dashboard
                    </a>
                    <a href="quote-requests.php" class="list-group-item list-group-item-action py-3 active">
                        <i class="fas fa-file-invoice-dollar me-2" aria-hidden="true"></i> Quote Requests
                    </a>
                    <a href="my-quotes.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-comment-dollar me-2" aria-hidden="true"></i> My Quotes
                    </a>
                    <a href="my-repairs.php" class="list-group-item list-group-item-action py-3">
                    </a>
                    <a href="schedule.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-calendar-alt me-2" aria-hidden="true"></i> My Schedule
                    </a>
                    <a href="earnings.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-money-bill-wave me-2" aria-hidden="true"></i> Earnings
                    </a>
                    <a href="reviews.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-star me-2" aria-hidden="true"></i> My Reviews
                    </a>
                    <a href="notifications.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-bell me-2" aria-hidden="true"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="p-3 mt-4 border-top">
                    <a href="profile.php" class="btn btn-outline-primary w-100 mb-2">
                        <i class="fas fa-user-cog me-2" aria-hidden="true"></i> Profile Settings
                    </a>
                    <a href="logout.php" class="btn btn-danger w-100">
                        <i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar d-none d-lg-block">
            <h5 class="mb-3">Provider Dashboard</h5>
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Overview
                    </a>
                </li>
                <li>
                    <a href="quote-requests.php" class="active">
                        <i class="fas fa-file-invoice-dollar"></i> Quote Requests
                    </a>
                </li>
                <li>
                    <a href="my-quotes.php">
                        <i class="fas fa-comment-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="schedule.php">
                        <i class="fas fa-calendar-alt"></i> My Schedule
                    </a>
                </li>
                <li>
                    <a href="earnings.php">
                        <i class="fas fa-money-bill-wave"></i> Earnings
                    </a>
                </li>
                <li>
                    <a href="reviews.php">
                        <i class="fas fa-star"></i> My Reviews
                    </a>
                </li>
                <li>
                    <a href="notifications.php">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="../profile.php">
                        <i class="fas fa-user-cog"></i> Profile Settings
                    </a>
                </li>
                <li>
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="mb-1">Quote Requests</h1>
                        <p class="text-muted mb-0">Browse and respond to customer repair quote requests</p>
                        <div class="mt-2 text-muted">
                            <strong>Your specialties:</strong> <?php echo getSpecialtiesList($providerSpecialties); ?>
                        </div>
                    </div>
                    <a href="create-quote.php" class="btn btn-primary d-none d-md-block">
                        <i class="fas fa-plus me-2"></i> Create New Quote
                    </a>
                </div>
                
                <?php if(!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <ul class="mb-0">
                        <?php foreach($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <!-- Filters Card -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form action="quote-requests.php" method="GET" class="mb-0">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="quoted" <?php echo $status === 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                                        <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="device_type" class="form-label">Device Type</label>
                                    <select name="device_type" id="device_type" class="form-select">
                                        <option value="" <?php echo empty($deviceType) ? 'selected' : ''; ?>>All Devices</option>
                                        <option value="smartphone" <?php echo $deviceType === 'smartphone' ? 'selected' : ''; ?>>Smartphone</option>
                                        <option value="laptop" <?php echo $deviceType === 'laptop' ? 'selected' : ''; ?>>Laptop</option>
                                        <option value="tablet" <?php echo $deviceType === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                        <option value="desktop" <?php echo $deviceType === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                        <option value="gaming" <?php echo $deviceType === 'gaming' ? 'selected' : ''; ?>>Gaming Console</option>
                                        <option value="tv" <?php echo $deviceType === 'tv' ? 'selected' : ''; ?>>TV</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select name="sort" id="sort" class="form-select">
                                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="urgency_high" <?php echo $sortBy === 'urgency_high' ? 'selected' : ''; ?>>Highest Urgency</option>
                                        <option value="urgency_low" <?php echo $sortBy === 'urgency_low' ? 'selected' : ''; ?>>Lowest Urgency</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="search" class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search" name="search" placeholder="Search..." value="<?php echo e($searchTerm); ?>">
                                        <button class="btn btn-outline-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                
                                
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="quote-requests.php" class="btn btn-outline-secondary ms-2">
                                        <i class="fas fa-times me-1"></i> Clear Filters
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Active Filters -->
                            <?php if(!empty($status) || !empty($deviceType) || !empty($searchTerm) || $sortBy !== 'newest' || $showAllRequests): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="d-flex align-items-center">
                                    <strong class="me-2">Active Filters:</strong>
                                    <div>
                                        <?php if($status !== 'all'): ?>
                                        <div class="filter-pill">
                                            Status: <?php echo ucfirst($status); ?>
                                            <a href="<?php echo '?status=all' . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : '') . ($showAllRequests ? '&show_all=1' : ''); ?>" class="remove-filter" aria-label="Remove status filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($deviceType)): ?>
                                        <div class="filter-pill">
                                            Device: <?php echo ucfirst($deviceType); ?>
                                            <a href="<?php echo '?status=' . $status . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : '') . ($showAllRequests ? '&show_all=1' : ''); ?>" class="remove-filter" aria-label="Remove device type filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($searchTerm)): ?>
                                        <div class="filter-pill">
                                            Search: "<?php echo e($searchTerm); ?>"
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : '') . ($showAllRequests ? '&show_all=1' : ''); ?>" class="remove-filter" aria-label="Remove search filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($sortBy !== 'newest'): ?>
                                        <div class="filter-pill">
                                            Sort: <?php echo ucfirst(str_replace('_', ' ', $sortBy)); ?>
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($showAllRequests ? '&show_all=1' : ''); ?>" class="remove-filter" aria-label="Remove sort filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($showAllRequests): ?>
                                        <div class="filter-pill">
                                            Including: All device types
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Show only my specialties">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <a href="quote-requests.php" class="btn btn-sm btn-outline-secondary ms-2">Clear All</a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="mb-0">
                        <strong><?php echo $totalRequests; ?></strong> quote request<?php echo $totalRequests !== 1 ? 's' : ''; ?> found
                        <?php if($status !== 'all'): ?>
                        with status <strong><?php echo ucfirst($status); ?></strong>
                        <?php endif; ?>
                        <?php if(!empty($deviceType)): ?>
                        for <strong><?php echo ucfirst($deviceType); ?></strong> devices
                        <?php endif; ?>
                        <?php if(!$showAllRequests): ?>
                        within your specialties
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-flex align-items-center">
                        <label for="limit" class="form-label mb-0 me-2">Show:</label>
                        <select id="limit" name="limit" class="form-select form-select-sm" style="width: auto;" onchange="window.location.href='?status=<?php echo $status; ?>&device_type=<?php echo $deviceType; ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo $sortBy; ?>&page=1&limit='+this.value+'<?php echo $showAllRequests ? '&show_all=1' : ''; ?>'">
                            <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                        </select>
                    </div>
                </div>
                
                <!-- Quote Requests List -->
                <?php if(empty($quoteRequests)): ?>
                <div class="card mb-4">
                    <div class="card-body empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-search"></i>
                        </div>
                        <h3>No Quote Requests Found</h3>
                        <p class="text-muted mb-3">
                            <?php if(!empty($searchTerm) || !empty($deviceType) || $status !== 'all'): ?>
                            No quote requests match your current filter criteria. Try adjusting your filters.
                            <?php elseif(!$showAllRequests): ?>
                            No requests found that match your specialties. Try enabling "Show requests outside my specialties".
                            <?php else: ?>
                            There are no quote requests available at the moment. Check back later.
                            <?php endif; ?>
                        </p>
                        <?php if(!$showAllRequests): ?>
                        <a href="quote-requests.php?show_all=1" class="btn btn-primary me-2">Show All Requests</a>
                        <?php endif; ?>
                        <a href="quote-requests.php" class="btn btn-outline-secondary">Clear Filters</a>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach($quoteRequests as $request): ?>
                    <div class="card request-card mb-4 position-relative">
                        <?php 
                        // Check if this request matches provider specialties
                        $isSpecialtyMatch = in_array($request['device_type'], $providerSpecialties);
                        if ($isSpecialtyMatch): 
                        ?>
                      
                        <?php endif; ?>
                        
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="device-icon me-3">
                                    <?php echo getDeviceIcon($request['device_type']); ?>
                                </div>
                                <div>
                                    <h5 class="mb-0"><?php echo ucfirst($request['device_type']); ?> Repair</h5>
                                    <div class="text-muted small">Request #<?php echo $request['id']; ?> • <?php echo formatDate($request['created_at']); ?></div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center">
                                <?php echo getUrgencyLabel($request['urgency']); ?>
                                <div class="ms-2"><?php echo getStatusLabel($request['status']); ?></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="customer-info mb-3">
                                        <div class="customer-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo e($request['first_name'] . ' ' . $request['last_name']); ?></h6>
                                            <div class="text-muted small">
                                                <a href="mailto:<?php echo e($request['customer_email']); ?>" class="text-decoration-none">
                                                    <i class="fas fa-envelope me-1"></i> <?php echo e($request['customer_email']); ?>
                                                </a>
                                                <?php if(!empty($request['customer_phone'])): ?>
                                                <span class="mx-1">•</span>
                                                <a href="tel:<?php echo e($request['customer_phone']); ?>" class="text-decoration-none">
                                                    <i class="fas fa-phone me-1"></i> <?php echo e($request['customer_phone']); ?>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6>Issue Description</h6>
                                    <p><?php echo e($request['issue_description']); ?></p>
                                    
                                    <?php if($request['quote_count'] > 0): ?>
                                    <div class="alert alert-info d-flex align-items-center" role="alert">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <div>
                                            This request has <?php echo $request['quote_count']; ?> quote<?php echo $request['quote_count'] > 1 ? 's' : ''; ?> already submitted.
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <h6>Device Details</h6>
                                    <ul class="detail-list mb-4">
                                        <li>
                                            <span class="detail-label">Type:</span>
                                            <span><?php echo ucfirst($request['device_type']); ?></span>
                                        </li>
                                        <?php if(!empty($request['device_brand']) && $request['device_brand'] !== 'Not specified'): ?>
                                        <li>
                                            <span class="detail-label">Brand:</span>
                                            <span><?php echo e($request['device_brand']); ?></span>
                                        </li>
                                        <?php endif; ?>
                                        <?php if(!empty($request['device_model']) && $request['device_model'] !== 'Not specified'): ?>
                                        <li>
                                            <span class="detail-label">Model:</span>
                                            <span><?php echo e($request['device_model']); ?></span>
                                        </li>
                                        <?php endif; ?>
                                        <li>
                                            <span class="detail-label">Condition:</span>
                                            <span><?php echo ucfirst($request['device_condition']); ?></span>
                                        </li>
                                        <li>
                                            <span class="detail-label">Location:</span>
                                            <span><?php echo e($request['location'] !== 'Not specified' ? $request['location'] : 'Not specified'); ?></span>
                                        </li>
                                        <li>
                                            <span class="detail-label">Urgency:</span>
                                            <span><?php echo ucfirst($request['urgency']); ?></span>
                                        </li>
                                        <li>
                                            <span class="detail-label">Service Type:</span>
                                            <span><?php echo ucfirst($request['service_type']); ?></span>
                                        </li>
                                    </ul>
                                    
                                    <?php if(!empty($request['additional_info'])): ?>
                                    <h6>Additional Information</h6>
                                    <p><?php echo e($request['additional_info']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted">Submitted: <?php echo date('M d, Y', strtotime($request['created_at'])); ?></span>
                                </div>
                                <div class="action-buttons d-flex gap-2">
                                    <?php if($request['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <input type="hidden" name="new_status" value="cancelled">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to decline this quote request?')">
                                            <i class="fas fa-times me-1"></i> Decline
                                        </button>
                                    </form>
                                    <a href="create-quote.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-file-invoice-dollar me-1"></i> Create Quote
                                    </a>
                                    <?php elseif($request['status'] === 'quoted'): ?>
                                    <a href="quote-details.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye me-1"></i> View Quotes
                                    </a>
                                    <?php elseif($request['status'] === 'accepted'): ?>
                                    <a href="repair-details.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-tools me-1"></i> View Repair
                                    </a>
                                    <?php elseif($request['status'] === 'completed'): ?>
                                    <a href="repair-details.php?request_id=<?php echo $request['id']; ?>" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-clipboard-check me-1"></i> View Completed Repair
                                    </a>
                                    <?php elseif($request['status'] === 'cancelled'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled>
                                        <i class="fas fa-ban me-1"></i> Cancelled
                                    </button>
                                    <?php endif; ?>
                                    
                                    <a href="request-details.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-info-circle me-1"></i> Full Details
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <nav aria-label="Quote requests pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . ($page - 1) . '&limit=' . $limit . ($showAllRequests ? '&show_all=1' : ''); ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4 && $totalPages > 5) {
                            $startPage = max(1, $endPage - 4);
                        }
                        ?>
                        
                        <?php if($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=1&limit=' . $limit . ($showAllRequests ? '&show_all=1' : ''); ?>">1</a>
                        </li>
                        <?php if($startPage > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . $i . '&limit=' . $limit . ($showAllRequests ? '&show_all=1' : ''); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if($endPage < $totalPages): ?>
                        <?php if($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . $totalPages . '&limit=' . $limit . ($showAllRequests ? '&show_all=1' : ''); ?>"><?php echo $totalPages; ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . ($page + 1) . '&limit=' . $limit . ($showAllRequests ? '&show_all=1' : ''); ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Quick Action Floating Button (Mobile Only) -->
    <a href="create-quote.php" class="quick-action-btn d-md-none">
        <i class="fas fa-plus"></i>
    </a>

    <!-- Footer -->
    <footer class="py-4 bg-dark text-light mt-auto">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="../privacy.php" class="text-light me-3">Privacy Policy</a>
                    <a href="../terms.php" class="text-light me-3">Terms of Service</a>
                    <a href="../contact.php" class="text-light">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Initialize tooltips -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Theme toggle functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.querySelector('html');
            
            // Check for saved theme preference or use device preference
            const savedTheme = localStorage.getItem('theme');
            
            if (savedTheme) {
                htmlElement.setAttribute('data-bs-theme', savedTheme);
                updateIcon(savedTheme);
            } else {
                // Use device preference if no saved preference
                const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const initialTheme = prefersDarkMode ? 'dark' : 'light';
                htmlElement.setAttribute('data-bs-theme', initialTheme);
                updateIcon(initialTheme);
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                
                // Also set as cookie for server-side detection
                document.cookie = `theme=${newTheme}; path=/; max-age=31536000`; // 1 year
                
                updateIcon(newTheme);
            });
            
            function updateIcon(theme) {
                if (theme === 'dark') {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
            
            // Auto-submit form when checkbox changes
            const showAllCheckbox = document.getElementById('showAllDevices');
            if (showAllCheckbox) {
                showAllCheckbox.addEventListener('change', function() {
                    this.closest('form').submit();
                });
            }
        });
    </script>
</body>
</html>