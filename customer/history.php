<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a customer
if (!$loggedIn || $userRole !== 'customer') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get user information
$userData = null;
$profileImage = '../images/default.png';

try {
    // Check if we should use 'name' or 'username' field
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path safely
    if ($userData && !empty($userData['profile_image'])) {
        $profileImage = $userData['profile_image'];
        // Check if it's an external URL
        if (preg_match('/^https?:\/\//', $profileImage)) {
            // External URLs are safe to use as-is
        } else {
            // Local file paths need secure handling
            $profileImage = str_replace('../', '', $profileImage); // Remove any traversal attempts
            $profileImage = '../' . ltrim($profileImage, '/');
            
            // Verify path validity
            if (!file_exists($profileImage)) {
                $profileImage = '../images/default.png';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $profileImage = '../images/default.png';
}

// Get recent notifications
$notifications = [];
try {
    $notifQuery = "
        SELECT id, message, device_type, created_at 
        FROM notifications
        WHERE customer_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching notifications: " . $e->getMessage());
}

// Handle filters
$activityType = isset($_GET['type']) ? clean_input($_GET['type']) : 'all';
$timeFrame = isset($_GET['time']) ? clean_input($_GET['time']) : 'all';
$sortOrder = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'DESC';
$searchKeyword = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$perPage = 10; // Items per page

// Validate filter inputs
$validActivityTypes = ['all', 'quotes', 'repairs', 'bookings'];
$activityType = in_array($activityType, $validActivityTypes) ? $activityType : 'all';

$validTimeFrames = ['all', 'week', 'month', 'year'];
$timeFrame = in_array($timeFrame, $validTimeFrames) ? $timeFrame : 'all';

$validSortOrders = ['ASC', 'DESC'];
$sortOrder = in_array(strtoupper($sortOrder), $validSortOrders) ? strtoupper($sortOrder) : 'DESC';

// Prepare time filter condition
$timeCondition = '';
if ($timeFrame === 'week') {
    $timeCondition = " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)";
} elseif ($timeFrame === 'month') {
    $timeCondition = " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
} elseif ($timeFrame === 'year') {
    $timeCondition = " AND created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}

// Search condition
$searchCondition = '';
if (!empty($searchKeyword)) {
    $searchCondition = " AND (device_type LIKE ? OR status LIKE ?)";
}

// Get history items based on filters
$historyItems = [];
$totalItems = 0;

try {
    // Generate a UNION query for different activity types
    $unionQuery = '';
    $countQuery = '';
    $queryParams = [];
    
    // Quote requests
    if ($activityType === 'all' || $activityType === 'quotes') {
        $quoteQuery = "
            SELECT 
                'quote' as activity_type,
                id,
                device_type,
                issue_description as description,
                status,
                created_at,
                updated_at,
                NULL as price,
                NULL as provider_id,
                NULL as provider_name,
                NULL as booking_date
            FROM quote_requests 
            WHERE customer_id = ? $timeCondition 
        ";
        
        if (!empty($searchCondition)) {
            $quoteQuery .= $searchCondition;
            $queryParams[] = $userId;
            $queryParams[] = "%$searchKeyword%";
            $queryParams[] = "%$searchKeyword%";
        } else {
            $queryParams[] = $userId;
        }
        
        $unionQuery .= $quoteQuery;
        $countQuery .= "SELECT COUNT(*) FROM quote_requests WHERE customer_id = ? $timeCondition" . 
                       (!empty($searchCondition) ? $searchCondition : "");
    }
    
    // Repairs/Bookings
    if ($activityType === 'all' || $activityType === 'repairs' || $activityType === 'bookings') {
        // If we already have a query, add UNION
        if (!empty($unionQuery)) {
            $unionQuery .= " UNION ";
            $countQuery .= " + ";
        }
        
        $bookingQuery = "
            SELECT 
                'booking' as activity_type,
                b.id,
                IFNULL(b.device_type, 'Not specified') as device_type,
                b.notes as description,
                b.status,
                b.created_at,
                b.updated_at,
                b.total_price as price,
                b.provider_id,
                u.username as provider_name,
                b.booking_date
            FROM bookings b
            LEFT JOIN providers p ON b.provider_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            WHERE b.customer_id = ? $timeCondition
        ";
        
        if (!empty($searchCondition)) {
            $bookingQuery .= $searchCondition;
            $queryParams[] = $userId;
            $queryParams[] = "%$searchKeyword%";
            $queryParams[] = "%$searchKeyword%";
        } else {
            $queryParams[] = $userId;
        }
        
        $unionQuery .= $bookingQuery;
        $countQuery .= "SELECT COUNT(*) FROM bookings WHERE customer_id = ? $timeCondition" . 
                       (!empty($searchCondition) ? $searchCondition : "");
    }
    
    // Get total count
    $countStatement = $pdo->prepare("SELECT ($countQuery) as total");
    
    $countParams = [];
    foreach ($queryParams as $param) {
        if ($param === $userId) {
            $countParams[] = $userId;
        } elseif (is_string($param) && strpos($param, '%') === 0) {
            $countParams[] = $param;
        }
    }
    
    $countStatement->execute($countParams);
    $totalItems = $countStatement->fetchColumn();
    
    // Calculate pagination
    $totalPages = ceil($totalItems / $perPage);
    $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
    $offset = ($page - 1) * $perPage;
    
    // Final query with sorting and pagination
    $finalQuery = "
        SELECT * FROM (
            $unionQuery
        ) as combined_results
        ORDER BY created_at $sortOrder
        LIMIT $offset, $perPage
    ";
    
    $stmt = $pdo->prepare($finalQuery);
    $stmt->execute($queryParams);
    $historyItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error fetching history: " . $e->getMessage());
    $errorMessage = "Unable to load your history. Please try again later.";
}

// Helper function for pagination URLs
function getPaginationUrl($page, $type, $time, $sort, $search) {
    $url = "?page=$page&type=$type&time=$time&sort=$sort";
    if (!empty($search)) {
        $url .= "&search=" . urlencode($search);
    }
    return $url;
}

// Helper function to determine appropriate device icon
function getDeviceIcon($device_type) {
    switch ($device_type) {
        case 'laptop': return 'laptop';
        case 'desktop': return 'desktop';
        case 'smartphone': return 'mobile-alt';
        case 'tablet': return 'tablet-alt';
        case 'gaming': return 'gamepad';
        case 'tv': return 'tv';
        default: return 'microchip';
    }
}

// Helper function to format status badges
function getStatusBadge($status) {
    switch ($status) {
        case 'pending':
            return '<span class="status-badge pending">Pending</span>';
        case 'accepted':
            return '<span class="status-badge accepted">Accepted</span>';
        case 'completed':
            return '<span class="status-badge completed">Completed</span>';
        case 'cancelled':
            return '<span class="status-badge cancelled">Cancelled</span>';
        case 'confirmed':
            return '<span class="status-badge accepted">Confirmed</span>';
        case 'in_progress':
        case 'in-progress':
            return '<span class="status-badge in-progress">In Progress</span>';
        default:
            return '<span class="status-badge">' . ucfirst($status) . '</span>';
    }
}

// Helper function to format dates
function formatDate($dateString) {
    $date = new DateTime($dateString);
    return $date->format('M d, Y, g:i a');
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Activity History - FixItNow</title>
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
            transition: background-color 0.3s ease, color 0.3s ease;
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
            transition: background-color 0.3s ease;
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
            transition: all 0.2s ease;
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
        
        .dashboard-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease;
        }
        
        /* Filter bar styles */
        .filter-bar {
            background-color: var(--card-bg);
            border-radius: 1rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            transition: all 0.3s ease;
        }
        
        .filter-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0;
        }
        
        .filter-tabs .nav-link {
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            border: 1px solid var(--border-color);
        }
        
        .filter-tabs .nav-link:hover:not(.active) {
            background-color: var(--sidebar-active);
            transform: translateY(-2px);
        }
        
        .filter-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: var(--header-text);
            border-color: var(--primary-color);
            box-shadow: 0 4px 8px rgba(var(--bs-primary-rgb), 0.3);
        }
        
        .filter-tabs .badge {
            margin-left: 0.5rem;
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            transition: all 0.2s ease;
        }
        
        /* History timeline styles */
        .history-timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .history-timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0.75rem;
            width: 2px;
            background-color: var(--border-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 2rem;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-dot {
            position: absolute;
            left: -2rem;
            width: 1.5rem;
            height: 1.5rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.8rem;
            z-index: 1;
        }
        
        .timeline-card {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            transition: all 0.3s ease;
        }
        
        .timeline-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            border-color: var(--primary-color);
        }
        
        .timeline-header {
            padding: 1rem;
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .timeline-body {
            padding: 1rem;
        }
        
        .timeline-footer {
            padding: 0.75rem 1rem;
            background-color: rgba(var(--bs-primary-rgb), 0.02);
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Status badge styles */
        .status-badge {
            border-radius: 30px;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.in-progress {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .status-badge.accepted {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        /* Activity type badges */
        .activity-badge {
            font-size: 0.7rem;
            padding: 0.25rem 0.5rem;
            border-radius: 30px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .activity-badge.quote {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .activity-badge.booking {
            background-color: rgba(102, 16, 242, 0.1);
            color: #6610f2;
        }
        
        /* Empty state styles */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            color: var(--text-muted);
            opacity: 0.7;
        }
        
        /* Form control styling */
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        /* Pagination styles */
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .pagination .page-link {
            color: var(--primary-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-link:hover {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner.show {
            display: flex;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                padding: 1rem;
            }
            
            .dashboard-wrapper {
                flex-direction: column;
            }
            
            .sidebar-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .sidebar-menu li {
                margin-right: 0.5rem;
                margin-bottom: 0.5rem;
            }
            
            .filter-tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
                white-space: nowrap;
                padding-bottom: 0.5rem;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none; /* Firefox */
            }
            
            .filter-tabs::-webkit-scrollbar {
                display: none; /* Chrome, Safari and Opera */
            }
            
            .history-timeline {
                padding-left: 1.5rem;
            }
            
            .timeline-dot {
                left: -1.5rem;
                width: 1.25rem;
                height: 1.25rem;
                font-size: 0.7rem;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .filter-form {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .filter-form .form-select,
            .filter-form .input-group {
                width: 100%;
            }
            
            .timeline-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .timeline-header .status-badge {
                margin-top: 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .timeline-footer {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="text-decoration-none">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Notifications -->
                    <div class="dropdown me-3">
                        <button class="btn btn-dark position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if(count($notifications) > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo count($notifications); ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                            <li><h6 class="dropdown-header">Notifications</h6></li>
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted">No new notifications</div></li>
                            <?php else: ?>
                                <?php foreach($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item" href="notifications.php">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($notification['message'] ?? 'New notification'); ?></h6>
                                            <small class="text-muted"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['device_type'] ?? ''); ?></small>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="notifications.php">View all notifications</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <!-- Theme Toggle -->
                    <button class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon theme-icon-dark d-none"></i>
                        <i class="fas fa-sun theme-icon-light"></i>
                    </button>
                    
                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="btn btn-dark d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline">
                                <?php 
                                // Display either name or username, depending on what's available
                                if (isset($userData['name']) && !empty($userData['name'])) {
                                    echo htmlspecialchars($userData['name']);
                                } elseif (isset($userData['username']) && !empty($userData['username'])) {
                                    echo htmlspecialchars($userData['username']);
                                } elseif (isset($userData['first_name']) && !empty($userData['first_name'])) {
                                    echo htmlspecialchars($userData['first_name'] . ' ' . ($userData['last_name'] ?? ''));
                                } else {
                                    echo 'User';
                                }
                                ?>
                            </span>
                            <i class="fas fa-chevron-down ms-2 small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Dashboard Layout -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="my-quotes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-quotes.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="new-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'new-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i> New Request
                    </a>
                </li>
                <li>
                    <a href="history.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'history.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> History
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i> Settings
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
            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h2 class="fw-bold mb-0">Activity History</h2>
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar mb-4">
                <form action="history.php" method="get" class="d-flex flex-wrap gap-3 align-items-center filter-form">
                    <!-- Activity Type Filter -->
                    <div class="filter-select">
                        <label for="typeFilter" class="form-label mb-1">Activity Type</label>
                        <select class="form-select" id="typeFilter" name="type" onchange="this.form.submit()">
                            <option value="all" <?php echo $activityType === 'all' ? 'selected' : ''; ?>>All Activities</option>
                            <option value="quotes" <?php echo $activityType === 'quotes' ? 'selected' : ''; ?>>Quote Requests</option>
                            <option value="repairs" <?php echo $activityType === 'repairs' ? 'selected' : ''; ?>>Repairs</option>
                            <option value="bookings" <?php echo $activityType === 'bookings' ? 'selected' : ''; ?>>Bookings</option>
                        </select>
                    </div>
                    
                    <!-- Time Range Filter -->
                    <div class="filter-select">
                        <label for="timeFilter" class="form-label mb-1">Time Range</label>
                        <select class="form-select" id="timeFilter" name="time" onchange="this.form.submit()">
                            <option value="all" <?php echo $timeFrame === 'all' ? 'selected' : ''; ?>>All Time</option>
                            <option value="week" <?php echo $timeFrame === 'week' ? 'selected' : ''; ?>>Last Week</option>
                            <option value="month" <?php echo $timeFrame === 'month' ? 'selected' : ''; ?>>Last Month</option>
                            <option value="year" <?php echo $timeFrame === 'year' ? 'selected' : ''; ?>>Last Year</option>
                        </select>
                    </div>
                    
                    <!-- Sort Order -->
                    <div class="filter-select">
                        <label for="sortFilter" class="form-label mb-1">Sort By</label>
                        <select class="form-select" id="sortFilter" name="sort" onchange="this.form.submit()">
                            <option value="DESC" <?php echo $sortOrder === 'DESC' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="ASC" <?php echo $sortOrder === 'ASC' ? 'selected' : ''; ?>>Oldest First</option>
                        </select>
                    </div>
                    
                    <!-- Search Field -->
                    <div class="ms-auto">
                        <label for="searchFilter" class="form-label mb-1">Search</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="searchFilter" name="search" placeholder="Search..." value="<?php echo htmlspecialchars($searchKeyword); ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- History Timeline -->
            <?php if(empty($historyItems)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-history"></i>
                </div>
                <h4>No Activity History Found</h4>
                <p class="text-muted mb-4">There are no records matching your current filters.</p>
                <a href="history.php" class="btn btn-primary">
                    <i class="fas fa-sync-alt me-2"></i> View All Activity
                </a>
            </div>
            <?php else: ?>
            <div class="history-timeline">
                <?php foreach($historyItems as $item): ?>
                <div class="timeline-item">
                    <div class="timeline-dot" title="<?php echo ucfirst($item['activity_type']); ?>">
                        <?php if($item['activity_type'] === 'quote'): ?>
                            <i class="fas fa-file-invoice-dollar"></i>
                        <?php elseif($item['activity_type'] === 'booking'): ?>
                            <i class="fas fa-tools"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <div>
                                <span class="activity-badge <?php echo $item['activity_type']; ?>">
                                    <?php echo $item['activity_type']; ?>
                                </span>
                                <h5 class="mb-0 mt-1">
                                    <?php 
                                    $title = $item['activity_type'] === 'quote' 
                                        ? 'Quote Request #' . $item['id'] 
                                        : 'Booking #' . $item['id'];
                                    echo $title; 
                                    ?>
                                </h5>
                                <div class="timeline-date mt-1">
                                    <?php echo formatDate($item['created_at']); ?>
                                </div>
                            </div>
                            
                            <?php echo getStatusBadge($item['status']); ?>
                        </div>
                        
                        <div class="timeline-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-<?php echo getDeviceIcon($item['device_type']); ?> me-2"></i>
                                        <strong><?php echo htmlspecialchars(ucfirst($item['device_type'])); ?></strong>
                                    </div>
                                    
                                    <p>
                                        <?php 
                                        $description = $item['description'] ?? 'No description available';
                                        echo htmlspecialchars(substr($description, 0, 150) . (strlen($description) > 150 ? '...' : '')); 
                                        ?>
                                    </p>
                                </div>
                                
                                <div class="col-md-6">
                                    <?php if($item['activity_type'] === 'booking'): ?>
                                    <div class="mb-2">
                                        <strong>Technician:</strong> 
                                        <?php echo !empty($item['provider_name']) ? htmlspecialchars($item['provider_name']) : 'Not assigned'; ?>
                                    </div>
                                    
                                    <?php if(!empty($item['booking_date'])): ?>
                                    <div class="mb-2">
                                        <strong>Scheduled For:</strong> 
                                        <?php echo date('M d, Y', strtotime($item['booking_date'])); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($item['price'])): ?>
                                    <div class="mb-2">
                                        <strong>Price:</strong> 
                                        $<?php echo number_format($item['price'], 2); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="timeline-footer">
                            <div class="text-muted">
                                <strong>Last Updated:</strong> <?php echo formatDate($item['updated_at']); ?>
                            </div>
                            
                            <div>
                                <?php if($item['activity_type'] === 'quote'): ?>
                                <a href="quote-details.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </a>
                                <?php elseif($item['activity_type'] === 'booking'): ?>
                                <a href="booking-details.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if($totalPages > 1): ?>
            <div class="d-flex justify-content-center mt-4">
                <nav aria-label="History pagination">
                    <ul class="pagination">
                        <!-- Previous page link -->
                        <?php if($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo getPaginationUrl($page - 1, $activityType, $timeFrame, $sortOrder, $searchKeyword); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link" aria-hidden="true">&laquo;</span>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page number links -->
                        <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4 && $startPage > 1) {
                            $startPage = max(1, $endPage - 4);
                        }
                        
                        for($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo getPaginationUrl($i, $activityType, $timeFrame, $sortOrder, $searchKeyword); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next page link -->
                        <?php if($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo getPaginationUrl($page + 1, $activityType, $timeFrame, $sortOrder, $searchKeyword); ?>" aria-label="Next">
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
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="py-4 bg-dark text-light">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
            <div class="mt-2">
                <a href="../terms.php" class="text-muted me-3">Terms of Service</a>
                <a href="../privacy.php" class="text-muted me-3">Privacy Policy</a>
                <a href="../contact.php" class="text-muted">Contact Us</a>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme toggler
        const themeToggle = document.getElementById('themeToggle');
        const htmlElement = document.documentElement;
        const darkIcon = document.querySelector('.theme-icon-dark');
        const lightIcon = document.querySelector('.theme-icon-light');
        
        // Check for saved theme preference or use the system preference
        const storedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        
        // Set initial theme
        if (storedTheme === 'dark') {
            htmlElement.setAttribute('data-bs-theme', 'dark');
            darkIcon.classList.remove('d-none');
            lightIcon.classList.add('d-none');
        } else {
            htmlElement.setAttribute('data-bs-theme', 'light');
            lightIcon.classList.remove('d-none');
            darkIcon.classList.add('d-none');
        }
        
        // Toggle theme on button click
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            htmlElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            if (newTheme === 'dark') {
                darkIcon.classList.remove('d-none');
                lightIcon.classList.add('d-none');
            } else {
                lightIcon.classList.remove('d-none');
                darkIcon.classList.add('d-none');
            }
        });
        
        // Loading spinner
        document.addEventListener('DOMContentLoaded', function() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            
            // Hide spinner when page is loaded
            loadingSpinner.classList.remove('show');
            
            // Form submission with loading spinner
            const historyFilters = document.querySelector('form');
            
            if (historyFilters) {
                historyFilters.addEventListener('submit', function() {
                    loadingSpinner.classList.add('show');
                });
            }
            
            // Show spinner when navigating away
            document.querySelectorAll('a:not([download])').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Don't show for same-page links or links with external targets
                    if (this.getAttribute('href').startsWith('#') || this.getAttribute('target') === '_blank') {
                        return;
                    }
                    
                    loadingSpinner.classList.add('show');
                });
            });
        });
    </script>
</body>
</html>