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
    
    // Log user data for debugging
    error_log("User data: " . json_encode($userData));
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $profileImage = '../images/default.png';
}

// Check for bookings from booking.php and create quote requests if needed
try {
    // First, check for recent bookings that don't have associated quote requests
    $bookingQuery = "
        SELECT b.*, p.id as provider_id, u.username as provider_name
        FROM bookings b
        JOIN providers p ON b.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE b.customer_id = ? 
        AND NOT EXISTS (
            SELECT 1 FROM quote_requests qr 
            WHERE qr.customer_id = b.customer_id 
            AND qr.issue_description LIKE CONCAT('%', b.notes, '%')
        )
        ORDER BY b.created_at DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->prepare($bookingQuery);
    $stmt->execute([$userId]);
    $uncategorizedBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If bookings exist that don't have corresponding quote requests, create them
    foreach ($uncategorizedBookings as $booking) {
        if (!empty($booking['notes'])) {
            $insertQuery = "
                INSERT INTO quote_requests (
                    customer_id, device_type, issue_description, status, created_at
                ) VALUES (
                    ?, ?, ?, 'pending', NOW()
                )
            ";
            
            $deviceType = isset($booking['device_type']) ? $booking['device_type'] : 'desktop';
            
            $stmt = $pdo->prepare($insertQuery);
            $stmt->execute([
                $userId,
                $deviceType,
                $booking['notes']
            ]);
            
            // Log the creation
            $requestId = $pdo->lastInsertId();
            error_log("Created quote request #$requestId from booking #" . $booking['id']);
            
            // Create a notification for the newly created quote request
            try {
                $notifQuery = "
                    INSERT INTO notification_logs (
                        action, error_message
                    ) VALUES (
                        'quote_from_booking', ?
                    )
                ";
                $stmt = $pdo->prepare($notifQuery);
                $stmt->execute(["Created quote request #$requestId from booking #" . $booking['id']]);
            } catch (PDOException $e) {
                error_log("Error creating notification: " . $e->getMessage());
            }
        }
    }
    
    if (!empty($uncategorizedBookings)) {
        // If we created new quote requests, redirect to refresh the page
        header("Location: my-quotes.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Error processing bookings: " . $e->getMessage());
}

// Handle filters
$statusFilter = isset($_GET['status']) ? clean_input($_GET['status']) : 'all';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'created_at';
$sortOrder = isset($_GET['order']) ? clean_input($_GET['order']) : 'DESC';
$page = isset($_GET['page']) ? (int)clean_input($_GET['page']) : 1;
$perPage = 10; // Items per page

// Validate status filter parameter
$allowedStatusFilters = ['all', 'pending', 'accepted', 'completed', 'cancelled'];
$statusFilter = in_array($statusFilter, $allowedStatusFilters) ? $statusFilter : 'all';

// Validate sort parameters
$allowedSortFields = ['created_at', 'device_type', 'quote_count', 'status'];
$sortBy = in_array($sortBy, $allowedSortFields) ? $sortBy : 'created_at';
$allowedSortOrders = ['ASC', 'DESC'];
$sortOrder = in_array($sortOrder, $allowedSortOrders) ? $sortOrder : 'DESC';

// Get total item count for pagination
$totalItems = 0;
try {
    $countQuery = "SELECT COUNT(*) FROM quote_requests WHERE customer_id = ?";
    if ($statusFilter !== 'all') {
        $countQuery .= " AND status = ?";
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute([$userId, $statusFilter]);
    } else {
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute([$userId]);
    }
    $totalItems = $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error counting items: " . $e->getMessage());
}

// Calculate total pages
$totalPages = ceil($totalItems / $perPage);
$page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
$offset = ($page - 1) * $perPage;

// Get quote requests with filters
$quotes = [];
try {
    $query = "
        SELECT qr.*,
               COUNT(DISTINCT q.id) as quote_count
        FROM quote_requests qr
        LEFT JOIN quotes q ON qr.id = q.request_id
        WHERE qr.customer_id = ?
    ";
    
    // Apply status filter if not 'all'
    $params = [$userId];
    if ($statusFilter !== 'all') {
        $query .= " AND qr.status = ?";
        $params[] = $statusFilter;
    }
    
    $query .= " GROUP BY qr.id ORDER BY " . $sortBy . " " . $sortOrder;
    $query .= " LIMIT ?, ?"; // Add pagination limits
    
    $stmt = $pdo->prepare($query);
    
    // Add pagination parameters
    $params[] = $offset;
    $params[] = $perPage;
    
    // Bind parameters with correct types
    for ($i = 0; $i < count($params); $i++) {
        if ($i >= count($params) - 2) { // Last two params are offset and limit
            $stmt->bindValue($i + 1, $params[$i], PDO::PARAM_INT);
        } else {
            $stmt->bindValue($i + 1, $params[$i]);
        }
    }
    
    $stmt->execute();
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching quotes: " . $e->getMessage());
    $errorMessage = "Unable to load your quote requests. Please try again later.";
}

// Get counts of quotes by status
$statusCounts = [
    'pending' => 0,
    'accepted' => 0,
    'completed' => 0,
    'cancelled' => 0
];

try {
    $countQuery = "
        SELECT status, COUNT(*) as count
        FROM quote_requests
        WHERE customer_id = ?
        GROUP BY status
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute([$userId]);
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($counts as $count) {
        if (isset($statusCounts[$count['status']])) {
            $statusCounts[$count['status']] = $count['count'];
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching status counts: " . $e->getMessage());
}

// Get recent notifications
$notifications = [];
try {
    // Optimize notifications query to fetch only needed data
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

// Helper function to create pagination URLs while preserving filters
function getPaginationUrl($page, $status, $sort, $order) {
    return "?page=" . $page . "&status=" . $status . "&sort=" . $sort . "&order=" . $order;
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

// Helper function to format the description field
function getDescription($quote) {
    if (isset($quote['issue_description']) && !empty($quote['issue_description'])) {
        return $quote['issue_description'];
    } else if (isset($quote['description']) && !empty($quote['description'])) {
        return $quote['description'];
    }
    return 'No description available';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>My Quote Requests - FixItNow</title>
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
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .status-badge {
            border-radius: 30px;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.accepted {
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
        
        /* Table styles */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            border-top: none;
            color: var(--text-muted);
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tr:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        /* Mobile card view */
        .mobile-quote-card {
            border-radius: 1rem;
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid var(--border-color);
            margin-bottom: 1rem;
        }
        
        .mobile-quote-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .mobile-quote-card .card-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .mobile-quote-card .card-body {
            padding: 1.25rem;
        }
        
        /* New Quote Button */
        .new-quote-btn {
            background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
            border: none;
            color: white;
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            box-shadow: 0 4px 10px rgba(var(--bs-primary-rgb), 0.3);
            text-decoration: none;
        }
        
        .new-quote-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(var(--bs-primary-rgb), 0.4);
            color: white;
            text-decoration: none;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            color: var(--text-muted);
            opacity: 0.7;
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
        
        /* Debug box */
        .debug-box {
            background-color: rgba(255, 255, 0, 0.1);
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 5px;
            border: 1px dashed #999;
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
            
            .filter-tabs .nav-item {
                display: inline-block;
                flex-shrink: 0;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .filter-bar {
                padding: 1rem;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 1rem;
            }
            
            .page-header .new-quote-btn {
                align-self: stretch;
                justify-content: center;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .filter-bar .d-flex {
                flex-direction: column;
                gap: 1rem;
            }
            
            .filter-bar .ms-auto {
                margin-left: 0 !important;
                width: 100%;
            }
            
            .mobile-quote-card .card-body .btn {
                width: 100%;
                justify-content: center;
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
                    <a href="new-request.php">
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
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between mb-4 page-header">
                <h2 class="fw-bold mb-0">My Quote Requests</h2>
                <a href="new-request.php" class="new-quote-btn">
                    <i class="fas fa-plus-circle"></i> New Request
                </a>
            </div>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <div class="d-flex flex-wrap align-items-center justify-content-between">
                    <!-- Status Tabs -->
                    <ul class="nav nav-pills filter-tabs">
                        <li class="nav-item">
                            <a class="nav-link <?php echo $statusFilter == 'all' ? 'active' : ''; ?>" href="?status=all&sort=<?php echo $sortBy; ?>&order=<?php echo $sortOrder; ?>">
                                All <span class="badge rounded-pill bg-secondary"><?php echo $totalItems; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $statusFilter == 'pending' ? 'active' : ''; ?>" href="?status=pending&sort=<?php echo $sortBy; ?>&order=<?php echo $sortOrder; ?>">
                                Pending <span class="badge rounded-pill bg-warning"><?php echo $statusCounts['pending']; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $statusFilter == 'accepted' ? 'active' : ''; ?>" href="?status=accepted&sort=<?php echo $sortBy; ?>&order=<?php echo $sortOrder; ?>">
                                Accepted <span class="badge rounded-pill bg-primary"><?php echo $statusCounts['accepted']; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $statusFilter == 'completed' ? 'active' : ''; ?>" href="?status=completed&sort=<?php echo $sortBy; ?>&order=<?php echo $sortOrder; ?>">
                                Completed <span class="badge rounded-pill bg-success"><?php echo $statusCounts['completed']; ?></span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $statusFilter == 'cancelled' ? 'active' : ''; ?>" href="?status=cancelled&sort=<?php echo $sortBy; ?>&order=<?php echo $sortOrder; ?>">
                                Cancelled <span class="badge rounded-pill bg-danger"><?php echo $statusCounts['cancelled']; ?></span>
                            </a>
                        </li>
                    </ul>
                    
                    <!-- Sort Options -->
                    <div class="dropdown ms-auto">
                        <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-sort me-1"></i> Sort by
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="sortDropdown">
                            <li>
                                <a class="dropdown-item <?php echo ($sortBy == 'created_at' && $sortOrder == 'DESC') ? 'active' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&sort=created_at&order=DESC">
                                    <i class="fas fa-calendar-alt me-2"></i> Newest First
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($sortBy == 'created_at' && $sortOrder == 'ASC') ? 'active' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&sort=created_at&order=ASC">
                                    <i class="fas fa-calendar-alt me-2"></i> Oldest First
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($sortBy == 'device_type' && $sortOrder == 'ASC') ? 'active' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&sort=device_type&order=ASC">
                                    <i class="fas fa-laptop me-2"></i> Device Type (A-Z)
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item <?php echo ($sortBy == 'quote_count' && $sortOrder == 'DESC') ? 'active' : ''; ?>" href="?status=<?php echo $statusFilter; ?>&sort=quote_count&order=DESC">
                                    <i class="fas fa-file-invoice-dollar me-2"></i> Most Quotes
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Quote Requests List -->
            <div class="dashboard-card">
                <?php if(empty($quotes)): ?>
                <!-- Empty State -->
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <h4>No Quote Requests Found</h4>
                    <p class="text-muted">You don't have any quote requests matching the selected filter.</p>
                    <a href="new-request.php" class="btn btn-primary mt-3">
                        <i class="fas fa-plus-circle me-2"></i> Create New Request
                    </a>
                </div>
                <?php else: ?>
                <!-- Desktop Table View -->
                <div class="table-responsive d-none d-lg-block">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Request #</th>
                                <th>Device</th>
                                <th>Description</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Quotes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($quotes as $quote): ?>
                            <tr>
                                <td>
                                    <strong>#REQ-<?php echo str_pad($quote['id'], 5, '0', STR_PAD_LEFT); ?></strong>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-<?php echo getDeviceIcon($quote['device_type']); ?> me-2"></i>
                                        <?php echo htmlspecialchars(ucfirst($quote['device_type'] ?? 'Unknown')); ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $description = getDescription($quote);
                                    echo htmlspecialchars(substr($description, 0, 50) . (strlen($description) > 50 ? '...' : '')); 
                                    ?>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($quote['created_at'])); ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $quote['status']; ?>">
                                        <?php echo ucfirst($quote['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-secondary rounded-pill">
                                        <?php echo $quote['quote_count']; ?> Quote<?php echo $quote['quote_count'] != 1 ? 's' : ''; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="quote-details.php?id=<?php echo $quote['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye me-1"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Mobile Card View -->
                <div class="d-lg-none">
                    <?php foreach($quotes as $quote): ?>
                    <div class="mobile-quote-card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-bold">#REQ-<?php echo str_pad($quote['id'], 5, '0', STR_PAD_LEFT); ?></span>
                            <span class="status-badge <?php echo $quote['status']; ?>">
                                <?php echo ucfirst($quote['status']); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <i class="fas fa-<?php echo getDeviceIcon($quote['device_type']); ?> me-2"></i>
                                <strong><?php echo htmlspecialchars(ucfirst($quote['device_type'] ?? 'Unknown')); ?></strong>
                            </div>
                            <p class="mb-2">
                                <?php 
                                $description = getDescription($quote);
                                echo htmlspecialchars(substr($description, 0, 100) . (strlen($description) > 100 ? '...' : '')); 
                                ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center text-muted small mb-3">
                                <span><i class="fas fa-calendar-alt me-1"></i> <?php echo date('M d, Y', strtotime($quote['created_at'])); ?></span>
                                <span><i class="fas fa-file-invoice-dollar me-1"></i> <?php echo $quote['quote_count']; ?> Quote<?php echo $quote['quote_count'] != 1 ? 's' : ''; ?></span>
                            </div>
                            <a href="quote-details.php?id=<?php echo $quote['id']; ?>" class="btn btn-primary w-100">
                                <i class="fas fa-eye me-1"></i> View Details
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <div class="d-flex justify-content-center pt-3 pb-3">
                    <nav aria-label="Page navigation">
                        <ul class="pagination">
                            <!-- Previous page link -->
                            <?php if($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getPaginationUrl($page - 1, $statusFilter, $sortBy, $sortOrder); ?>" aria-label="Previous">
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
                                    <a class="page-link" href="<?php echo getPaginationUrl($i, $statusFilter, $sortBy, $sortOrder); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next page link -->
                            <?php if($page < $totalPages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?php echo getPaginationUrl($page + 1, $statusFilter, $sortBy, $sortOrder); ?>" aria-label="Next">
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
            
            // Also show spinner on form submissions
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function() {
                    loadingSpinner.classList.add('show');
                });
            });
        });
    </script>
</body>
</html>