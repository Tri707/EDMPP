<?php
/**
 * Provider Bookings - FixItNow Platform
 * 
 * This file displays all bookings for a provider, with filtering
 * and sorting options.
 */

// Initialize session and verify authentication
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Security: Redirect if not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
require_once 'conn.php';

// Verify that database connection is established
if (!isset($pdo) || $pdo === null) {
    error_log("Database connection not established. Check conn.php file.");
    header('Location: ../error.php?message=Database%20connection%20failed');
    exit;
}

// Initialize variables with default values
$providerProfileImage = '../default.png'; // Set default image path
$providerId = 0;
$providerData = [];
$bookings = [];
$message = '';
$alertType = '';
$total_bookings = 0;
$total_pages = 1;
$current_page = 1;

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page); // Ensure page is at least 1

// Filtering options
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_filter = isset($_GET['date']) ? $_GET['date'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Sorting options
$sort_by = isset($_GET['sort']) ? $_GET['sort'] : 'date_desc';

// Get provider profile information
try {
    // Get provider user data
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties, 
                            p.experience 
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider' AND u.status = 'active'");
    $stmt->execute([$userId]);
    $providerDataResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($providerDataResult) {
        $providerData = $providerDataResult;
        $providerId = (int)$providerData['provider_id'];
        
        // Set profile image path with enhanced security checks
        if (!empty($providerData['profile_image'])) {
            if (preg_match('/^https?:\/\//i', $providerData['profile_image'])) {
                // External URL - validate if needed
                $providerProfileImage = filter_var($providerData['profile_image'], FILTER_SANITIZE_URL);
            } else {
                // Local file - validate path and existence
                $imagePath = '../profile_images/' . basename($providerData['profile_image']);
                if (file_exists($imagePath) && is_readable($imagePath)) {
                    $providerProfileImage = $imagePath;
                } else {
                    $providerProfileImage = '../default.png'; // Use default if file doesn't exist
                }
            }
        } else {
            $providerProfileImage = '../default.png'; // Use default if no image specified
        }
    } else {
        // Redirect to error page if user is not a provider
        error_log("Provider data not found for user ID: $userId");
        header('Location: ../error.php?message=Provider%20data%20not%20found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Get bookings with filtering and pagination
try {
    // Build the base query
    $query = "SELECT 
                b.*,
                u.first_name as customer_first_name,
                u.last_name as customer_last_name,
                u.profile_image as customer_profile_image,
                s.name as service_name
              FROM bookings b 
              LEFT JOIN users u ON b.customer_id = u.id
              LEFT JOIN services s ON b.service_id = s.id
              WHERE b.provider_id = ?";
    
    $params = [$providerId];
    
    // Apply filters
    if ($status_filter !== 'all') {
        $query .= " AND b.status = ?";
        $params[] = $status_filter;
    }
    
    if ($date_filter !== 'all') {
        switch ($date_filter) {
            case 'today':
                $query .= " AND DATE(b.booking_date) = CURDATE()";
                break;
            case 'tomorrow':
                $query .= " AND DATE(b.booking_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
                break;
            case 'this_week':
                $query .= " AND YEARWEEK(b.booking_date, 1) = YEARWEEK(CURDATE(), 1)";
                break;
            case 'next_week':
                $query .= " AND YEARWEEK(b.booking_date, 1) = YEARWEEK(DATE_ADD(CURDATE(), INTERVAL 1 WEEK), 1)";
                break;
            case 'this_month':
                $query .= " AND YEAR(b.booking_date) = YEAR(CURDATE()) AND MONTH(b.booking_date) = MONTH(CURDATE())";
                break;
            case 'past':
                $query .= " AND b.booking_date < CURDATE()";
                break;
            case 'upcoming':
                $query .= " AND b.booking_date >= CURDATE()";
                break;
        }
    }
    
    // Apply search
    if (!empty($search_query)) {
        $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR s.name LIKE ? OR b.notes LIKE ?)";
        $search_param = "%" . $search_query . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Apply sorting
    switch ($sort_by) {
        case 'date_asc':
            $query .= " ORDER BY b.booking_date ASC, b.booking_time ASC";
            break;
        case 'date_desc':
            $query .= " ORDER BY b.booking_date DESC, b.booking_time DESC";
            break;
        case 'price_asc':
            $query .= " ORDER BY b.total_price ASC";
            break;
        case 'price_desc':
            $query .= " ORDER BY b.total_price DESC";
            break;
        case 'status':
            $query .= " ORDER BY b.status ASC";
            break;
        case 'customer':
            $query .= " ORDER BY u.first_name ASC, u.last_name ASC";
            break;
        default:
            $query .= " ORDER BY b.booking_date DESC, b.booking_time DESC";
    }
    
    // Count total bookings for pagination
    $count_query = preg_replace('/SELECT\s+b\.\*,.*?FROM/i', 'SELECT COUNT(*) FROM', $query);
    $count_query = preg_replace('/ORDER BY.*$/i', '', $count_query);
    
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_bookings = $stmt->fetchColumn();
    
    // Calculate pagination
    $total_pages = ceil($total_bookings / $items_per_page);
    $current_page = min($current_page, max(1, $total_pages));
    $offset = ($current_page - 1) * $items_per_page;
    
    // Get paginated results
    $query .= " LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $items_per_page;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching bookings: " . $e->getMessage());
    $message = 'An error occurred while fetching bookings. Please try again later.';
    $alertType = 'danger';
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle bulk status update
    if (isset($_POST['action']) && $_POST['action'] === 'bulk_update') {
        try {
            if (!isset($_POST['booking_ids']) || !is_array($_POST['booking_ids']) || empty($_POST['booking_ids'])) {
                throw new Exception('No bookings selected.');
            }
            
            $newStatus = $_POST['new_status'];
            $bookingIds = array_map('intval', $_POST['booking_ids']);
            
            // Validate status
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid status value.');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update booking statuses
            $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
            $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id IN ($placeholders) AND provider_id = ?");
            $params = array_merge([$newStatus], $bookingIds, [$providerId]);
            $updateStmt->execute($params);
            
            $updated = $updateStmt->rowCount();
            
            if ($updated > 0) {
                // Add status history records
                $historyStmt = $pdo->prepare("INSERT INTO booking_status_history 
                                           (booking_id, status, created_by, user_id, notes) 
                                           VALUES (?, ?, 'provider', ?, 'Bulk status update')");
                
                foreach ($bookingIds as $bookingId) {
                    $historyStmt->execute([$bookingId, $newStatus, $userId]);
                }
                
                // Commit transaction
                $pdo->commit();
                
                $message = "Successfully updated status for $updated booking(s).";
                $alertType = 'success';
                
                // Refresh the page to show updated data
                header("Location: bookings.php?status=$status_filter&date=$date_filter&search=" . urlencode($search_query) . "&sort=$sort_by&page=$current_page&success=1");
                exit;
            } else {
                // Rollback transaction
                $pdo->rollBack();
                
                $message = 'No bookings were updated. You may not have permission to update some of the selected bookings.';
                $alertType = 'warning';
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Error updating booking statuses: " . $e->getMessage());
            $message = 'An error occurred: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }
}

// Show success message if redirected after successful update
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Booking status(es) updated successfully!';
    $alertType = 'success';
}

// Helper function to format date and time
function formatDateTime($date, $time = null) {
    if ($time) {
        return date('D, M j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($time));
    }
    return date('D, M j, Y', strtotime($date));
}

// Get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending':
            return 'warning';
        case 'confirmed':
            return 'primary';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Helper function to generate pagination URL
function getPaginationUrl($page) {
    global $status_filter, $date_filter, $search_query, $sort_by;
    return "bookings.php?status=$status_filter&date=$date_filter&search=" . urlencode($search_query) . "&sort=$sort_by&page=$page";
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        
        /* Booking list styles */
        .booking-item {
            transition: all 0.2s ease;
            border-radius: 0.5rem;
            position: relative;
        }
        
        .booking-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .booking-item .badge {
            font-size: 0.8rem;
        }
        
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
        }
        
        .customer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-text {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
        }
        
        /* Filter and search styles */
        .filter-card {
            background-color: var(--card-bg);
            padding: 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-card .form-select,
        .filter-card .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Form controls for dark theme */
        [data-bs-theme="dark"] .form-control, 
        [data-bs-theme="dark"] .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        [data-bs-theme="dark"] .form-control:focus, 
        [data-bs-theme="dark"] .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
        }
        
        /* Status pills */
        .status-pill {
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        /* Price display */
        .price-display {
            font-weight: 700;
        }
        
        .currency-icon {
            vertical-align: middle;
            margin-right: 2px;
            height: 14px;
        }
        
        /* Pagination custom styles */
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
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--bg-color);
            opacity: 0.7;
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .spinner-container {
            text-align: center;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }

        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }
            
            .d-actions {
                flex-direction: column;
            }
            
            .d-actions .btn {
                margin-bottom: 0.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading bookings...</p>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="dashboard.php" class="text-decoration-none d-flex align-items-center">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-primary">Provider Portal</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($providerProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo isset($providerData['first_name']) ? htmlspecialchars($providerData['first_name']) : 'Provider'; ?></span>
                            <?php if(isset($providerData['is_verified']) && $providerData['is_verified'] == 1): ?>
                                <i class="fas fa-tools text-primary ms-1" title="Verified Provider"></i>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="services.php"><i class="fas fa-cogs me-2"></i> My Services</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
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
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">My Schedule</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">My Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="earnings.php">
                            <span class="nav-icon"><i class="fas fa-wallet"></i></span>
                            <span class="nav-text">Earnings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <!-- Page Title and Actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">My Bookings</h2>
                    <p class="text-muted">Manage your customer bookings and appointments</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Dashboard
                    </a>
                    <a href="schedule.php" class="btn btn-primary">
                        <i class="fas fa-calendar-alt me-1"></i> View Schedule
                    </a>
                </div>
            </div>
            
            <!-- Display alert message if set -->
            <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $alertType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4">
                                <?php 
                                // Count total bookings
                                $total = count($bookings) + ($current_page - 1) * $items_per_page;
                                echo min($total, $total_bookings); 
                                ?>
                            </div>
                            <div class="text-muted">Total Bookings</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-primary">
                                <?php 
                                // Count confirmed bookings
                                $confirmed = 0;
                                foreach ($bookings as $booking) {
                                    if ($booking['status'] === 'confirmed') $confirmed++;
                                }
                                echo $confirmed;
                                ?>
                            </div>
                            <div class="text-muted">Confirmed</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-success">
                                <?php 
                                // Count completed bookings
                                $completed = 0;
                                foreach ($bookings as $booking) {
                                    if ($booking['status'] === 'completed') $completed++;
                                }
                                echo $completed;
                                ?>
                            </div>
                            <div class="text-muted">Completed</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-warning">
                                <?php 
                                // Count pending bookings
                                $pending = 0;
                                foreach ($bookings as $booking) {
                                    if ($booking['status'] === 'pending') $pending++;
                                }
                                echo $pending;
                                ?>
                            </div>
                            <div class="text-muted">Pending</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters and Search -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Bookings</h5>
                </div>
                <div class="card-body">
                    <form action="bookings.php" method="get" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="date" class="form-label">Date Range</label>
                            <select class="form-select" id="date" name="date">
                                <option value="all" <?php echo $date_filter === 'all' ? 'selected' : ''; ?>>All Dates</option>
                                <option value="today" <?php echo $date_filter === 'today' ? 'selected' : ''; ?>>Today</option>
                                <option value="tomorrow" <?php echo $date_filter === 'tomorrow' ? 'selected' : ''; ?>>Tomorrow</option>
                                <option value="this_week" <?php echo $date_filter === 'this_week' ? 'selected' : ''; ?>>This Week</option>
                                <option value="next_week" <?php echo $date_filter === 'next_week' ? 'selected' : ''; ?>>Next Week</option>
                                <option value="this_month" <?php echo $date_filter === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                <option value="upcoming" <?php echo $date_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                <option value="past" <?php echo $date_filter === 'past' ? 'selected' : ''; ?>>Past</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="sort" class="form-label">Sort By</label>
                            <select class="form-select" id="sort" name="sort">
                                <option value="date_desc" <?php echo $sort_by === 'date_desc' ? 'selected' : ''; ?>>Date (Newest First)</option>
                                <option value="date_asc" <?php echo $sort_by === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest First)</option>
                                <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Price (Highest First)</option>
                                <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Price (Lowest First)</option>
                                <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                                <option value="customer" <?php echo $sort_by === 'customer' ? 'selected' : ''; ?>>Customer Name</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="search" name="search" placeholder="Customer name, service..." value="<?php echo htmlspecialchars($search_query); ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Bulk Actions Form Start -->
            <form action="bookings.php" method="post" id="bulkActionsForm">
                <input type="hidden" name="action" value="bulk_update">
                
                <!-- Bookings List -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Bookings (<?php echo $total_bookings; ?>)</h5>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-cog me-1"></i> Bulk Actions
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                                <li>
                                    <button type="button" class="dropdown-item" onclick="updateBulkStatus('confirmed')">
                                        <i class="fas fa-check text-primary me-2"></i> Mark as Confirmed
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" onclick="updateBulkStatus('completed')">
                                        <i class="fas fa-check-double text-success me-2"></i> Mark as Completed
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" onclick="updateBulkStatus('cancelled')">
                                        <i class="fas fa-times text-danger me-2"></i> Mark as Cancelled
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <button type="button" class="dropdown-item" onclick="selectAllBookings()">
                                        <i class="fas fa-check-square me-2"></i> Select All
                                    </button>
                                </li>
                                <li>
                                    <button type="button" class="dropdown-item" onclick="deselectAllBookings()">
                                        <i class="fas fa-square me-2"></i> Deselect All
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($bookings)): ?>
                            <div class="text-center py-5">
                                <div class="text-muted mb-3">
                                    <i class="fas fa-calendar-times fa-4x"></i>
                                </div>
                                <h5>No bookings found</h5>
                                <p>Try adjusting your filters or check back later for new bookings.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th width="40">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="selectAll" onchange="toggleAllBookings()">
                                                </div>
                                            </th>
                                            <th>Customer</th>
                                            <th>Service</th>
                                            <th>Date & Time</th>
                                            <th>Price</th>
                                            <th>Status</th>
                                            <th>Payment</th>
                                            <th width="100">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bookings as $booking): ?>
                                            <tr>
                                                <td>
                                                    <div class="form-check">
                                                        <input class="form-check-input booking-checkbox" type="checkbox" name="booking_ids[]" value="<?php echo $booking['id']; ?>">
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="customer-avatar me-2">
                                                            <?php if (!empty($booking['customer_profile_image'])): ?>
                                                                <?php
                                                                if (preg_match('/^https?:\/\//i', $booking['customer_profile_image'])) {
                                                                    $imagePath = $booking['customer_profile_image'];
                                                                } else {
                                                                    $imagePath = '../profile_images/' . basename($booking['customer_profile_image']);
                                                                    if (!file_exists($imagePath) || !is_readable($imagePath)) {
                                                                        $imagePath = '../default.png';
                                                                    }
                                                                }
                                                                ?>
                                                                <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Customer">
                                                            <?php else: ?>
                                                                <div class="avatar-text">
                                                                    <?php 
                                                                    $initials = '';
                                                                    if (!empty($booking['customer_first_name'])) {
                                                                        $initials .= strtoupper(substr($booking['customer_first_name'], 0, 1));
                                                                    }
                                                                    if (!empty($booking['customer_last_name'])) {
                                                                        $initials .= strtoupper(substr($booking['customer_last_name'], 0, 1));
                                                                    }
                                                                    echo $initials ?: 'C';
                                                                    ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div>
                                                            <div class="fw-bold">
                                                                <?php 
                                                                $customerName = trim($booking['customer_first_name'] . ' ' . $booking['customer_last_name']);
                                                                echo htmlspecialchars($customerName ?: 'Unknown Customer'); 
                                                                ?>
                                                            </div>
                                                            <div class="small text-muted">ID: <?php echo $booking['customer_id']; ?></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($booking['service_name'] ?: 'General Service'); ?>
                                                </td>
                                                <td>
                                                    <div class="fw-bold">
                                                        <?php echo formatDateTime($booking['booking_date']); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        <?php echo date('g:i A', strtotime($booking['booking_time'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="price-display">
                                                        <img src="../admin/sar/sar.png" alt="" class="currency-icon">
                                                        <?php echo number_format((float)$booking['total_price'], 2); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo getStatusBadgeClass($booking['status']); ?> status-pill">
                                                        <?php echo ucfirst($booking['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $booking['payment_status'] === 'paid' ? 'success' : ($booking['payment_status'] === 'refunded' ? 'info' : 'warning'); ?> status-pill">
                                                        <?php echo ucfirst($booking['payment_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="booking-detail.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-primary" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <span class="visually-hidden">Toggle Dropdown</span>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="booking-detail.php?id=<?php echo $booking['id']; ?>">
                                                                    <i class="fas fa-eye text-primary me-2"></i> View Details
                                                                </a>
                                                            </li>
                                                            <?php if ($booking['status'] !== 'confirmed'): ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item" onclick="updateSingleStatus(<?php echo $booking['id']; ?>, 'confirmed')">
                                                                    <i class="fas fa-check text-primary me-2"></i> Mark as Confirmed
                                                                </button>
                                                            </li>
                                                            <?php endif; ?>
                                                            <?php if ($booking['status'] !== 'completed'): ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item" onclick="updateSingleStatus(<?php echo $booking['id']; ?>, 'completed')">
                                                                    <i class="fas fa-check-double text-success me-2"></i> Mark as Completed
                                                                </button>
                                                            </li>
                                                            <?php endif; ?>
                                                            <?php if ($booking['status'] !== 'cancelled'): ?>
                                                            <li>
                                                                <button type="button" class="dropdown-item" onclick="updateSingleStatus(<?php echo $booking['id']; ?>, 'cancelled')">
                                                                    <i class="fas fa-times text-danger me-2"></i> Mark as Cancelled
                                                                </button>
                                                            </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Pagination -->
<?php if ($total_pages > 1): ?>
<div class="card-footer">
    <nav aria-label="Bookings pagination">
        <ul class="pagination justify-content-center mb-0">
            <!-- First Page and Previous -->
            <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo getPaginationUrl(1); ?>" aria-label="First">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
            <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $current_page > 1 ? getPaginationUrl($current_page - 1) : '#'; ?>" aria-label="Previous">
                    <span aria-hidden="true">&lsaquo;</span>
                </a>
            </li>
            
            <?php 
            // Calculate which page numbers to show
            $total_visible_pages = 5; // Number of page links to show
            
            if ($total_pages <= $total_visible_pages) {
                // If we have fewer pages than the limit, show all pages
                $start_page = 1;
                $end_page = $total_pages;
            } else {
                // Calculate start and end pages
                $half = floor($total_visible_pages / 2);
                
                if ($current_page <= $half + 1) {
                    // Near the start
                    $start_page = 1;
                    $end_page = $total_visible_pages;
                } elseif ($current_page >= $total_pages - $half) {
                    // Near the end
                    $start_page = $total_pages - $total_visible_pages + 1;
                    $end_page = $total_pages;
                } else {
                    // In the middle
                    $start_page = $current_page - $half;
                    $end_page = $current_page + $half;
                }
            }
            
            // Show page numbers
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
                <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                    <a class="page-link" href="<?php echo getPaginationUrl($i); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            
            <!-- Next and Last Page -->
            <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo $current_page < $total_pages ? getPaginationUrl($current_page + 1) : '#'; ?>" aria-label="Next">
                    <span aria-hidden="true">&rsaquo;</span>
                </a>
            </li>
            <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="<?php echo getPaginationUrl($total_pages); ?>" aria-label="Last">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        </ul>
    </nav>
</div>
<?php endif; ?>
                
                <!-- Hidden input for the new status, populated by JavaScript -->
                <input type="hidden" name="new_status" id="newStatusInput" value="">
            </form>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading overlay when page is loaded
            document.getElementById('loadingOverlay').style.display = 'none';
            
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
            
            // Check for saved theme preference or prefer-color-scheme
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
            
            // Auto-submit the form when filters change
            document.getElementById('status').addEventListener('change', function() {
                this.form.submit();
            });
            
            document.getElementById('date').addEventListener('change', function() {
                this.form.submit();
            });
            
            document.getElementById('sort').addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // Toggle all checkboxes
        function toggleAllBookings() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.booking-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
        }
        
        // Select all bookings
        function selectAllBookings() {
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = true;
            toggleAllBookings();
        }
        
        // Deselect all bookings
        function deselectAllBookings() {
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = false;
            toggleAllBookings();
        }
        
        // Update status for a single booking
        function updateSingleStatus(bookingId, status) {
            // Uncheck all bookings
            deselectAllBookings();
            
            // Find and check the specific booking
            const checkbox = document.querySelector(`.booking-checkbox[value="${bookingId}"]`);
            if (checkbox) {
                checkbox.checked = true;
            }
            
            // Set the status and submit the form
            updateBulkStatus(status);
        }
        
        // Update status for all selected bookings
        function updateBulkStatus(status) {
            // Set the status
            document.getElementById('newStatusInput').value = status;
            
            // Check if any bookings are selected
            const checkboxes = document.querySelectorAll('.booking-checkbox:checked');
            if (checkboxes.length === 0) {
                alert('Please select at least one booking to update.');
                return;
            }
            
            // Confirm the action
            if (confirm(`Are you sure you want to mark ${checkboxes.length} booking(s) as ${status}?`)) {
                // Show loading overlay
                document.getElementById('loadingOverlay').style.display = 'flex';
                
                // Submit the form
                document.getElementById('bulkActionsForm').submit();
            }
        }
    </script>
</body>
</html>