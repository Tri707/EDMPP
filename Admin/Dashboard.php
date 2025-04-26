<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Calculate date ranges
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$thisWeekStart = date('Y-m-d', strtotime('this week Monday'));
$thisMonthStart = date('Y-m-01');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$last30Days = date('Y-m-d', strtotime('-30 days'));

// Initialize dashboard data
$dashboardStats = [];
$recentBookings = [];
$recentQuotes = [];
$recentReviews = [];
$recentUsers = [];
$topTechnicians = [];
$deviceTypeBreakdown = [];
$bookingStatusBreakdown = [];
$revenueByDay = [];

// Fetch dashboard statistics
try {
    // Overall statistics
    $statsQuery = "
        SELECT 
            (SELECT COUNT(*) FROM bookings) as total_bookings,
            (SELECT COUNT(*) FROM bookings WHERE created_at >= ?) as recent_bookings,
            (SELECT COUNT(*) FROM quote_requests) as total_quotes,
            (SELECT COUNT(*) FROM quote_requests WHERE created_at >= ?) as recent_quotes,
            (SELECT COUNT(*) FROM users WHERE role = 'customer') as total_customers,
            (SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at >= ?) as new_customers,
            (SELECT COUNT(*) FROM users WHERE role = 'provider') as total_technicians,
            (SELECT COUNT(*) FROM providers WHERE is_verified = 1) as verified_technicians,
            (SELECT COALESCE(SUM(total_price), 0) FROM bookings) as total_revenue,
            (SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE created_at >= ?) as recent_revenue,
            (SELECT COALESCE(AVG(rating), 0) FROM reviews) as avg_rating,
            (SELECT COUNT(*) FROM reviews) as total_reviews,
            (SELECT COUNT(*) FROM reviews WHERE created_at >= ?) as recent_reviews
    ";
    
    $statsStmt = $pdo->prepare($statsQuery);
    $statsStmt->execute([$last30Days, $last30Days, $last30Days, $last30Days, $last30Days]);
    $dashboardStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Recent bookings
    $bookingsQuery = "
        SELECT 
            b.id, 
            b.booking_date, 
            b.booking_time, 
            b.status, 
            b.total_price,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            p.id as provider_id,
            u.first_name as provider_first_name,
            u.last_name as provider_last_name
        FROM bookings b
        JOIN users c ON b.customer_id = c.id
        JOIN providers p ON b.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        ORDER BY b.created_at DESC
        LIMIT 5
    ";
    
    $bookingsStmt = $pdo->prepare($bookingsQuery);
    $bookingsStmt->execute();
    $recentBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent quote requests
    $quotesQuery = "
        SELECT 
            q.id, 
            q.device_type, 
            q.status, 
            q.created_at,
            u.first_name,
            u.last_name
        FROM quote_requests q
        JOIN users u ON q.customer_id = u.id
        ORDER BY q.created_at DESC
        LIMIT 5
    ";
    
    $quotesStmt = $pdo->prepare($quotesQuery);
    $quotesStmt->execute();
    $recentQuotes = $quotesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent reviews
    $reviewsQuery = "
        SELECT 
            r.id, 
            r.rating, 
            r.comment, 
            r.created_at,
            cu.first_name as customer_first_name,
            cu.last_name as customer_last_name,
            pr.first_name as provider_first_name,
            pr.last_name as provider_last_name
        FROM reviews r
        JOIN users cu ON r.customer_id = cu.id
        JOIN providers p ON r.provider_id = p.id
        JOIN users pr ON p.user_id = pr.id
        ORDER BY r.created_at DESC
        LIMIT 5
    ";
    
    $reviewsStmt = $pdo->prepare($reviewsQuery);
    $reviewsStmt->execute();
    $recentReviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent users
    $usersQuery = "
        SELECT 
            id,
            username,
            first_name,
            last_name,
            email,
            role,
            created_at
        FROM users
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    $usersStmt = $pdo->prepare($usersQuery);
    $usersStmt->execute();
    $recentUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top technicians by revenue
    $technicianQuery = "
        SELECT 
            p.id as provider_id,
            u.first_name,
            u.last_name,
            COUNT(b.id) as booking_count,
            COALESCE(SUM(b.total_price), 0) as total_revenue,
            COALESCE(AVG(r.rating), 0) as avg_rating
        FROM providers p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN bookings b ON b.provider_id = p.id
        LEFT JOIN reviews r ON r.provider_id = p.id
        GROUP BY p.id, u.first_name, u.last_name
        ORDER BY total_revenue DESC
        LIMIT 5
    ";
    
    $technicianStmt = $pdo->prepare($technicianQuery);
    $technicianStmt->execute();
    $topTechnicians = $technicianStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Device type breakdown
    $deviceTypeQuery = "
        SELECT 
            device_type,
            COUNT(*) as count
        FROM quote_requests
        GROUP BY device_type
        ORDER BY count DESC
    ";
    
    $deviceTypeStmt = $pdo->prepare($deviceTypeQuery);
    $deviceTypeStmt->execute();
    $deviceTypeBreakdown = $deviceTypeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Booking status breakdown
    $statusQuery = "
        SELECT 
            status,
            COUNT(*) as count
        FROM bookings
        GROUP BY status
        ORDER BY count DESC
    ";
    
    $statusStmt = $pdo->prepare($statusQuery);
    $statusStmt->execute();
    $bookingStatusBreakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Revenue by day for the last 30 days
    $revenueQuery = "
        SELECT 
            DATE(created_at) as date,
            COALESCE(SUM(total_price), 0) as revenue
        FROM bookings
        WHERE created_at >= ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ";
    
    $revenueStmt = $pdo->prepare($revenueQuery);
    $revenueStmt->execute([$last30Days]);
    $revenueByDay = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error fetching dashboard data: " . $e->getMessage());
}

// Format data for charts
$revenueChartData = [];
foreach ($revenueByDay as $row) {
    $revenueChartData[] = [
        'date' => date('M j', strtotime($row['date'])),
        'revenue' => (float)$row['revenue']
    ];
}

$deviceTypeChartData = [];
foreach ($deviceTypeBreakdown as $row) {
    $deviceTypeChartData[] = [
        'name' => ucfirst($row['device_type']),
        'value' => (int)$row['count']
    ];
}

$statusChartData = [];
foreach ($bookingStatusBreakdown as $row) {
    $statusChartData[] = [
        'name' => ucfirst($row['status']),
        'value' => (int)$row['count']
    ];
}

// Encode chart data for JavaScript
$revenueChartJson = json_encode($revenueChartData);
$deviceTypeChartJson = json_encode($deviceTypeChartData);
$statusChartJson = json_encode($statusChartData);

// Calculate today's revenue
$todayRevenue = 0;
$todayBookings = 0;
foreach ($revenueByDay as $row) {
    if ($row['date'] === $today) {
        $todayRevenue = (float)$row['revenue'];
        break;
    }
}

// Calculate booking completion rate
$completedBookings = 0;
$totalBookingsCount = 0;
foreach ($bookingStatusBreakdown as $row) {
    $totalBookingsCount += $row['count'];
    if ($row['status'] === 'completed') {
        $completedBookings = $row['count'];
    }
}
$completionRate = ($totalBookingsCount > 0) ? round(($completedBookings / $totalBookingsCount) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - FixItNow Admin</title>
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
        
        /* Currency Icon */
        .currency-icon {
            vertical-align: middle;
            margin-right: 3px;
            margin-top: -3px;
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
        
        /* Stats Card */
        .stat-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .stat-icon {
            font-size: 3.5rem;
            opacity: 0.1;
            position: absolute;
            top: 50%;
            right: 1rem;
            transform: translateY(-50%);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .stat-change {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .stat-card.success {
            border-left: 4px solid var(--accent-color);
        }
        
        .stat-card.primary {
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.warning {
            border-left: 4px solid #ffc107;
        }
        
        .stat-card.info {
            border-left: 4px solid #0dcaf0;
        }
        
        /* Chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-bottom: 1.5rem;
        }
        
        /* Dashboard action buttons */
        .action-button {
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .action-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Activity list */
        .activity-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item:hover {
            background-color: rgba(var(--primary-color-rgb), 0.05);
        }
        
        .activity-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            margin-right: 1rem;
        }
        
        .activity-icon.booking {
            background-color: var(--primary-color);
        }
        
        .activity-icon.quote {
            background-color: #ffc107;
        }
        
        .activity-icon.review {
            background-color: var(--accent-color);
        }
        
        .activity-icon.user {
            background-color: #0dcaf0;
        }
        
        .activity-content {
            flex: 1;
        }
        
        .activity-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .activity-info {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.confirmed {
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
        
        /* Rating Stars */
        .rating-stars {
            color: #ffc107;
            font-size: 0.9rem;
        }
        

        
        /* Progress bar */
        .progress {
            height: 0.75rem;
            border-radius: 1rem;
            background-color: rgba(0, 0, 0, 0.1);
        }
        
        .progress-bar {
            border-radius: 1rem;
        }
        
        /* Task list */
        .task-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        
        .task-item:last-child {
            border-bottom: none;
        }
        
        .task-item:hover {
            background-color: rgba(var(--primary-color-rgb), 0.05);
        }
        
        .task-checkbox {
            margin-right: 1rem;
        }
        
        .task-title {
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .task-info {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .task-badge {
            font-size: 0.7rem;
            padding: 0.2rem 0.5rem;
            border-radius: 50rem;
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
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-danger">Admin Panel</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn && isset($adminData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($adminData['first_name']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                        <a class="nav-link active" href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="users.php">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="technicians.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Technicians</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span class="nav-text">Settings</span>
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
            
            <!-- Quick Stats -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card success h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-money-bill-wave stat-icon text-success"></i>
                            <h6 class="mb-3">Total Revenue</h6>
                            <div class="d-flex align-items-baseline mb-1">
                                <img src="sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                <div class="stat-value"><?php echo number_format((float)$dashboardStats['total_revenue'], 0); ?></div>
                            </div>
                            <div class="stat-change text-success">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php echo number_format(((float)$dashboardStats['recent_revenue'] / ((float)$dashboardStats['total_revenue'] - (float)$dashboardStats['recent_revenue'] + 0.1)) * 100, 1); ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card primary h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-calendar-check stat-icon text-primary"></i>
                            <h6 class="mb-3">Total Bookings</h6>
                            <div class="stat-value"><?php echo number_format((int)$dashboardStats['total_bookings']); ?></div>
                            <div class="stat-change text-primary">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php echo $dashboardStats['total_bookings'] > 0 ? number_format(((int)$dashboardStats['recent_bookings'] / (int)$dashboardStats['total_bookings']) * 100, 1) : 0; ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card warning h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-clipboard-list stat-icon text-warning"></i>
                            <h6 class="mb-3">Quote Requests</h6>
                            <div class="stat-value"><?php echo number_format((int)$dashboardStats['total_quotes']); ?></div>
                            <div class="stat-change text-warning">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php echo $dashboardStats['total_quotes'] > 0 ? number_format(((int)$dashboardStats['recent_quotes'] / (int)$dashboardStats['total_quotes']) * 100, 1) : 0; ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stat-card info h-100">
                        <div class="card-body p-3">
                            <i class="fas fa-users stat-icon text-info"></i>
                            <h6 class="mb-3">Customers</h6>
                            <div class="stat-value"><?php echo number_format((int)$dashboardStats['total_customers']); ?></div>
                            <div class="stat-change text-info">
                                <i class="fas fa-caret-up me-1"></i>
                                <?php echo $dashboardStats['total_customers'] > 0 ? number_format(((int)$dashboardStats['new_customers'] / (int)$dashboardStats['total_customers']) * 100, 1) : 0; ?>% (30 days)
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Action Buttons -->
            <div class="row g-4 mb-4">
                <div class="col-lg-12">
                    <div class="d-flex flex-wrap gap-2">
                        <a href="bookings.php" class="btn btn-primary action-button">
                            <i class="fas fa-calendar-plus me-2"></i>New Booking
                        </a>
                        <a href="users.php?action=add" class="btn btn-success action-button">
                            <i class="fas fa-user-plus me-2"></i>Add User
                        </a>
                        <a href="technicians.php?action=add" class="btn btn-info action-button">
                            <i class="fas fa-user-cog me-2"></i>Add Technician
                        </a>
                        <a href="services.php?action=add" class="btn btn-secondary action-button">
                            <i class="fas fa-plus-circle me-2"></i>New Service
                        </a>
                        <a href="reports.php" class="btn btn-warning action-button">
                            <i class="fas fa-chart-bar me-2"></i>View Reports
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Charts and Tables Section -->
            <div class="row g-4 mb-4">
                <!-- Revenue Chart -->
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Revenue Trend (Last 30 Days)</h5>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary active" data-period="daily">Daily</button>
                                <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="chart-container" id="revenueChart"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Pie Charts -->
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Platform Overview</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <h6>Device Type Distribution</h6>
                                <div class="chart-container" style="height: 150px;" id="deviceTypeChart"></div>
                            </div>
                            <div>
                                <h6>Booking Status Distribution</h6>
                                <div class="chart-container" style="height: 150px;" id="statusChart"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Activity and Top Technicians -->
            <div class="row g-4">
                <!-- Recent Activities -->
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Activities</h5>
                            <a href="#" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <div class="activity-list">
                                <!-- Recent Bookings -->
                                <?php foreach ($recentBookings as $booking): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="activity-icon booking">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="activity-title">New Booking #<?php echo htmlspecialchars($booking['id']); ?></div>
                                            <span class="status-badge <?php echo htmlspecialchars(strtolower($booking['status'])); ?>">
                                                <?php echo htmlspecialchars(ucfirst($booking['status'])); ?>
                                            </span>
                                        </div>
                                        <div class="activity-info">
                                            <?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?> 
                                            → 
                                            <?php echo htmlspecialchars($booking['provider_first_name'] . ' ' . $booking['provider_last_name']); ?>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('F j, Y', strtotime($booking['booking_date'])); ?> at <?php echo date('g:i A', strtotime($booking['booking_time'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Recent Quote Requests -->
                                <?php foreach ($recentQuotes as $quote): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="activity-icon quote">
                                        <i class="fas fa-clipboard-list"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="activity-title">Quote Request #<?php echo htmlspecialchars($quote['id']); ?></div>
                                            <span class="status-badge <?php echo htmlspecialchars(strtolower($quote['status'])); ?>">
                                                <?php echo htmlspecialchars(ucfirst($quote['status'])); ?>
                                            </span>
                                        </div>
                                        <div class="activity-info">
                                            <?php echo htmlspecialchars(ucfirst($quote['device_type'])); ?> repair by <?php echo htmlspecialchars($quote['first_name'] . ' ' . $quote['last_name']); ?>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('F j, Y, g:i A', strtotime($quote['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- Recent Reviews -->
                                <?php foreach ($recentReviews as $review): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="activity-icon review">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">
                                            New Review 
                                            <span class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= round($review['rating'])): ?>
                                                        <i class="fas fa-star"></i>
                                                    <?php else: ?>
                                                        <i class="far fa-star"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                            </span>
                                        </div>
                                        <div class="activity-info">
                                            <?php echo htmlspecialchars($review['customer_first_name'] . ' ' . $review['customer_last_name']); ?> 
                                            → 
                                            <?php echo htmlspecialchars($review['provider_first_name'] . ' ' . $review['provider_last_name']); ?>
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('F j, Y, g:i A', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <!-- New User Registrations -->
                                <?php foreach ($recentUsers as $user): ?>
                                <div class="activity-item d-flex align-items-center">
                                    <div class="activity-icon user">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <div class="activity-content">
                                        <div class="activity-title">New User Registration</div>
                                        <div class="activity-info">
                                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?> 
                                            (<?php echo htmlspecialchars(ucfirst($user['role'])); ?>)
                                        </div>
                                        <div class="activity-time">
                                            <?php echo date('F j, Y, g:i A', strtotime($user['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Top Technicians -->
                <div class="col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Top Technicians</h5>
                            <a href="technicians.php" class="text-muted text-decoration-none">
                                <small>View All</small>
                                <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Technician</th>
                                            <th>Bookings</th>
                                            <th>Revenue</th>
                                            <th>Rating</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topTechnicians as $technician): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <div class="avatar bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                            <?php 
                                                                $initials = strtoupper(substr($technician['first_name'], 0, 1) . substr($technician['last_name'], 0, 1));
                                                                echo $initials;
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <a href="technician-detail.php?id=<?php echo $technician['provider_id']; ?>" class="text-decoration-none">
                                                            <?php echo htmlspecialchars($technician['first_name'] . ' ' . $technician['last_name']); ?>
                                                        </a>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo number_format((int)$technician['booking_count']); ?></td>
                                            <td>
                                                <img src="sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                                <?php echo number_format((float)$technician['total_revenue'], 0); ?>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="me-2 rating-stars">
                                                        <?php 
                                                            $rating = (float)$technician['avg_rating'];
                                                            for ($i = 1; $i <= 5; $i++) {
                                                                if ($i <= floor($rating)) {
                                                                    echo '<i class="fas fa-star"></i>';
                                                                } elseif ($i - 0.5 <= $rating) {
                                                                    echo '<i class="fas fa-star-half-alt"></i>';
                                                                } else {
                                                                    echo '<i class="far fa-star"></i>';
                                                                }
                                                            }
                                                        ?>
                                                    </div>
                                                    <div><?php echo number_format($rating, 1); ?></div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($topTechnicians)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4">No technician data available.</td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            
            // Revenue Chart
            <?php if (!empty($revenueChartData)): ?>
            var revenueChartData = <?php echo $revenueChartJson; ?>;
            
            const revenueChartCtx = document.getElementById('revenueChart').getContext('2d');
            const revenueChart = new Chart(revenueChartCtx, {
                type: 'line',
                data: {
                    labels: revenueChartData.map(item => item.date),
                    datasets: [{
                        label: 'Revenue',
                        data: revenueChartData.map(item => item.revenue),
                        backgroundColor: 'rgba(118, 81, 236, 0.1)',
                        borderColor: 'rgba(118, 81, 236, 1)',
                        borderWidth: 2,
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: 'rgba(118, 81, 236, 1)',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return 'Revenue: SAR ' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                borderDash: [2, 4],
                                drawBorder: false
                            },
                            ticks: {
                                callback: function(value) {
                                    return 'SAR ' + value.toLocaleString();
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Device Type Chart
            <?php if (!empty($deviceTypeChartData)): ?>
            var deviceTypeChartData = <?php echo $deviceTypeChartJson; ?>;
            
            const deviceTypeChartCtx = document.getElementById('deviceTypeChart').getContext('2d');
            const deviceTypeChart = new Chart(deviceTypeChartCtx, {
                type: 'doughnut',
                data: {
                    labels: deviceTypeChartData.map(item => item.name),
                    datasets: [{
                        data: deviceTypeChartData.map(item => item.value),
                        backgroundColor: [
                            'rgba(118, 81, 236, 0.8)',
                            'rgba(55, 178, 77, 0.8)',
                            'rgba(255, 193, 7, 0.8)',
                            'rgba(13, 202, 240, 0.8)',
                            'rgba(220, 53, 69, 0.8)',
                            'rgba(108, 117, 125, 0.8)'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
            <?php endif; ?>
            
            // Booking Status Chart
            <?php if (!empty($statusChartData)): ?>
            var statusChartData = <?php echo $statusChartJson; ?>;
            
            const statusChartCtx = document.getElementById('statusChart').getContext('2d');
            const statusChart = new Chart(statusChartCtx, {
                type: 'doughnut',
                data: {
                    labels: statusChartData.map(item => item.name),
                    datasets: [{
                        data: statusChartData.map(item => item.value),
                        backgroundColor: [
                            'rgba(255, 193, 7, 0.8)',  // pending
                            'rgba(13, 110, 253, 0.8)', // confirmed
                            'rgba(25, 135, 84, 0.8)',  // completed
                            'rgba(220, 53, 69, 0.8)'   // cancelled
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12,
                                font: {
                                    size: 11
                                }
                            }
                        }
                    },
                    cutout: '70%'
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html>