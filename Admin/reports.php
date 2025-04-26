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

// Set default date range (last 30 days)
$defaultStartDate = date('Y-m-d', strtotime('-30 days'));
$defaultEndDate = date('Y-m-d');

// Get filter parameters
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : $defaultStartDate;
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : $defaultEndDate;
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Initialize arrays for chart data
$revenueData = [];
$bookingsData = [];
$quotesData = [];
$deviceTypeData = [];
$topTechnicians = [];
$topCustomers = [];
$statusBreakdown = [];

// Fetch report data based on type
try {
    // Overview Dashboard Stats
    $overviewQuery = "
        SELECT 
            (SELECT COUNT(*) FROM bookings WHERE created_at BETWEEN ? AND ?) as total_bookings,
            (SELECT COUNT(*) FROM users WHERE role = 'customer' AND created_at BETWEEN ? AND ?) as new_customers,
            (SELECT COUNT(*) FROM users WHERE role = 'provider' AND created_at BETWEEN ? AND ?) as new_technicians,
            (SELECT COUNT(*) FROM quote_requests WHERE created_at BETWEEN ? AND ?) as quote_requests,
            (SELECT COALESCE(SUM(total_price), 0) FROM bookings WHERE created_at BETWEEN ? AND ?) as total_revenue,
            (SELECT COALESCE(AVG(rating), 0) FROM reviews WHERE created_at BETWEEN ? AND ?) as avg_rating
    ";
    
    $overviewStmt = $pdo->prepare($overviewQuery);
    $overviewStmt->execute([
        $startDate, $endDate,
        $startDate, $endDate,
        $startDate, $endDate,
        $startDate, $endDate,
        $startDate, $endDate,
        $startDate, $endDate
    ]);
    
    $overviewStats = $overviewStmt->fetch(PDO::FETCH_ASSOC);
    
    // Revenue Over Time (by day for the selected period)
    if ($reportType == 'revenue' || $reportType == 'overview') {
        $revenueQuery = "
            SELECT 
                DATE(created_at) as date,
                COALESCE(SUM(total_price), 0) as revenue,
                COUNT(*) as bookings_count
            FROM bookings
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date
        ";
        
        $revenueStmt = $pdo->prepare($revenueQuery);
        $revenueStmt->execute([$startDate, $endDate]);
        $revenueData = $revenueStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Booking Status Breakdown
    if ($reportType == 'bookings' || $reportType == 'overview') {
        $statusQuery = "
            SELECT 
                status,
                COUNT(*) as count
            FROM bookings
            WHERE created_at BETWEEN ? AND ?
            GROUP BY status
        ";
        
        $statusStmt = $pdo->prepare($statusQuery);
        $statusStmt->execute([$startDate, $endDate]);
        $statusBreakdown = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Bookings by day
        $bookingsQuery = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM bookings
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date
        ";
        
        $bookingsStmt = $pdo->prepare($bookingsQuery);
        $bookingsStmt->execute([$startDate, $endDate]);
        $bookingsData = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Quote Requests Analysis
    if ($reportType == 'quotes' || $reportType == 'overview') {
        $quotesQuery = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as count
            FROM quote_requests
            WHERE created_at BETWEEN ? AND ?
            GROUP BY DATE(created_at)
            ORDER BY date
        ";
        
        $quotesStmt = $pdo->prepare($quotesQuery);
        $quotesStmt->execute([$startDate, $endDate]);
        $quotesData = $quotesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Device type breakdown
        $deviceTypeQuery = "
            SELECT 
                device_type,
                COUNT(*) as count
            FROM quote_requests
            WHERE created_at BETWEEN ? AND ?
            GROUP BY device_type
        ";
        
        $deviceTypeStmt = $pdo->prepare($deviceTypeQuery);
        $deviceTypeStmt->execute([$startDate, $endDate]);
        $deviceTypeData = $deviceTypeStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Top Technicians
    if ($reportType == 'technicians' || $reportType == 'overview') {
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
            LEFT JOIN bookings b ON b.provider_id = p.id AND b.created_at BETWEEN ? AND ?
            LEFT JOIN reviews r ON r.provider_id = p.id AND r.created_at BETWEEN ? AND ?
            GROUP BY p.id, u.first_name, u.last_name
            ORDER BY total_revenue DESC
            LIMIT 10
        ";
        
        $technicianStmt = $pdo->prepare($technicianQuery);
        $technicianStmt->execute([$startDate, $endDate, $startDate, $endDate]);
        $topTechnicians = $technicianStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Top Customers
    if ($reportType == 'customers' || $reportType == 'overview') {
        $customerQuery = "
            SELECT 
                u.id as customer_id,
                u.first_name,
                u.last_name,
                COUNT(b.id) as booking_count,
                COALESCE(SUM(b.total_price), 0) as total_spent
            FROM users u
            JOIN bookings b ON b.customer_id = u.id AND b.created_at BETWEEN ? AND ?
            WHERE u.role = 'customer'
            GROUP BY u.id, u.first_name, u.last_name
            ORDER BY total_spent DESC
            LIMIT 10
        ";
        
        $customerStmt = $pdo->prepare($customerQuery);
        $customerStmt->execute([$startDate, $endDate]);
        $topCustomers = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching report data: " . $e->getMessage());
}

// Convert data for charts
$revenueChartData = [];
foreach ($revenueData as $row) {
    $revenueChartData[] = [
        'date' => date('M j', strtotime($row['date'])),
        'revenue' => (float)$row['revenue']
    ];
}

$bookingsChartData = [];
foreach ($bookingsData as $row) {
    $bookingsChartData[] = [
        'date' => date('M j', strtotime($row['date'])),
        'count' => (int)$row['count']
    ];
}

$quotesChartData = [];
foreach ($quotesData as $row) {
    $quotesChartData[] = [
        'date' => date('M j', strtotime($row['date'])),
        'count' => (int)$row['count']
    ];
}

$deviceTypeChartData = [];
foreach ($deviceTypeData as $row) {
    $deviceTypeChartData[] = [
        'name' => ucfirst($row['device_type']),
        'value' => (int)$row['count']
    ];
}

$statusChartData = [];
foreach ($statusBreakdown as $row) {
    $statusChartData[] = [
        'name' => ucfirst($row['status']),
        'value' => (int)$row['count']
    ];
}

// Encode chart data for JavaScript
$revenueChartJson = json_encode($revenueChartData);
$bookingsChartJson = json_encode($bookingsChartData);
$quotesChartJson = json_encode($quotesChartData);
$deviceTypeChartJson = json_encode($deviceTypeChartData);
$statusChartJson = json_encode($statusChartData);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Dashboard - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- ReCharts CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/recharts/2.1.12/recharts.min.css">
    
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
        
        /* Stats widgets */
        .stat-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        /* Chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-bottom: 1.5rem;
        }
        
        /* Report filter controls */
        .report-controls {
            margin-bottom: 2rem;
        }
        
        /* Report tabs */
        .report-tabs .nav-link {
            color: var(--text-color);
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .report-tabs .nav-link:hover {
            background-color: rgba(var(--primary-color-rgb), 0.1);
        }
        
        .report-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        /* Table styles */
        .report-table th {
            background-color: rgba(0, 0, 0, 0.05);
            font-weight: 600;
        }
        
        .report-table tr {
            transition: background-color 0.2s ease;
        }
        
        .report-table tr:hover {
            background-color: rgba(var(--primary-color-rgb), 0.05);
        }
        
        /* Export buttons */
        .export-button {
            transition: all 0.3s ease;
        }
        
        .export-button:hover {
            transform: translateY(-2px);
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
                        <a class="nav-link" href="dashboard.php">
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
                        <a class="nav-link active" href="reports.php">
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
            <h1 class="page-title">Reports Dashboard</h1>
            
            <!-- Report Controls -->
            <div class="card report-controls">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label for="report_type" class="form-label">Report Type</label>
                            <select class="form-select" id="report_type" name="report_type">
                                <option value="overview" <?php echo $reportType === 'overview' ? 'selected' : ''; ?>>Overview</option>
                                <option value="revenue" <?php echo $reportType === 'revenue' ? 'selected' : ''; ?>>Revenue Analysis</option>
                                <option value="bookings" <?php echo $reportType === 'bookings' ? 'selected' : ''; ?>>Bookings Report</option>
                                <option value="quotes" <?php echo $reportType === 'quotes' ? 'selected' : ''; ?>>Quote Requests</option>
                                <option value="technicians" <?php echo $reportType === 'technicians' ? 'selected' : ''; ?>>Technician Performance</option>
                                <option value="customers" <?php echo $reportType === 'customers' ? 'selected' : ''; ?>>Customer Analysis</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($startDate); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($endDate); ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter me-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted">Reporting Period:</span>
                        <span class="ms-2 fw-bold"><?php echo date('F j, Y', strtotime($startDate)); ?> - <?php echo date('F j, Y', strtotime($endDate)); ?></span>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-secondary export-button" id="exportPDF">
                            <i class="fas fa-file-pdf me-2"></i>Export PDF
                        </button>
                        <button class="btn btn-sm btn-outline-secondary export-button ms-2" id="exportExcel">
                            <i class="fas fa-file-excel me-2"></i>Export Excel
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Quick Stats -->
            <div class="row g-4 mb-4">
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-money-bill-wave fa-2x text-success mb-3"></i>
                            <div class="stat-value">
                                <img src="sar/sar.png" alt="" class="currency-icon" width="24" height="24">
                                <?php echo number_format((float)$overviewStats['total_revenue'], 0); ?>
                            </div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-calendar-check fa-2x text-primary mb-3"></i>
                            <div class="stat-value"><?php echo number_format((int)$overviewStats['total_bookings']); ?></div>
                            <div class="stat-label">Total Bookings</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-clipboard-list fa-2x text-warning mb-3"></i>
                            <div class="stat-value"><?php echo number_format((int)$overviewStats['quote_requests']); ?></div>
                            <div class="stat-label">Quote Requests</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-user-plus fa-2x text-info mb-3"></i>
                            <div class="stat-value"><?php echo number_format((int)$overviewStats['new_customers']); ?></div>
                            <div class="stat-label">New Customers</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-user-cog fa-2x text-secondary mb-3"></i>
                            <div class="stat-value"><?php echo number_format((int)$overviewStats['new_technicians']); ?></div>
                            <div class="stat-label">New Technicians</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-2 col-md-4">
                    <div class="card stat-card h-100">
                        <div class="card-body text-center p-3">
                            <i class="fas fa-star fa-2x text-warning mb-3"></i>
                            <div class="stat-value"><?php echo number_format((float)$overviewStats['avg_rating'], 1); ?></div>
                            <div class="stat-label">Avg. Rating</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Report Content -->
            <?php if ($reportType === 'overview' || $reportType === 'revenue'): ?>
            <!-- Revenue Chart -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Revenue Trend</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary active" data-period="daily">Daily</button>
                        <button type="button" class="btn btn-outline-secondary" data-period="weekly">Weekly</button>
                        <button type="button" class="btn btn-outline-secondary" data-period="monthly">Monthly</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chart-container" id="revenueChart"></div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'overview' || $reportType === 'bookings'): ?>
            <!-- Bookings Chart -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Booking Trends</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container" id="bookingsChart"></div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="mb-3">Booking Status Breakdown</h6>
                            <div class="chart-container" id="statusPieChart"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'overview' || $reportType === 'quotes'): ?>
            <!-- Quote Requests Analysis -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Quote Requests Analysis</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container" id="quotesChart"></div>
                        </div>
                        <div class="col-md-4">
                            <h6 class="mb-3">Device Type Distribution</h6>
                            <div class="chart-container" id="deviceTypePieChart"></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'overview' || $reportType === 'technicians'): ?>
            <!-- Top Technicians -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Top Performing Technicians</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover report-table">
                            <thead>
                                <tr>
                                    <th>Technician</th>
                                    <th>Bookings</th>
                                    <th>Revenue</th>
                                    <th>Avg. Rating</th>
                                    <th>Actions</th>
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
                                                <strong><?php echo htmlspecialchars($technician['first_name'] . ' ' . $technician['last_name']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo number_format((int)$technician['booking_count']); ?></td>
                                    <td>
                                        <img src="sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                        <?php echo number_format((float)$technician['total_revenue'], 2); ?>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-2">
                                                <?php 
                                                    $rating = (float)$technician['avg_rating'];
                                                    for ($i = 1; $i <= 5; $i++) {
                                                        if ($i <= floor($rating)) {
                                                            echo '<i class="fas fa-star text-warning"></i>';
                                                        } elseif ($i - 0.5 <= $rating) {
                                                            echo '<i class="fas fa-star-half-alt text-warning"></i>';
                                                        } else {
                                                            echo '<i class="far fa-star text-warning"></i>';
                                                        }
                                                    }
                                                ?>
                                            </div>
                                            <div><?php echo number_format($rating, 1); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="technician-detail.php?id=<?php echo $technician['provider_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topTechnicians)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">No technician data available for the selected period.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($reportType === 'overview' || $reportType === 'customers'): ?>
            <!-- Top Customers -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Top Customers by Spending</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover report-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Bookings</th>
                                    <th>Total Spent</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topCustomers as $customer): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="me-3">
                                                <div class="avatar bg-info text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 36px; height: 36px;">
                                                    <?php 
                                                        $initials = strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1));
                                                        echo $initials;
                                                    ?>
                                                </div>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo number_format((int)$customer['booking_count']); ?></td>
                                    <td>
                                        <img src="sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                        <?php echo number_format((float)$customer['total_spent'], 2); ?>
                                    </td>
                                    <td>
                                        <a href="customer-detail.php?id=<?php echo $customer['customer_id']; ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye me-1"></i>View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($topCustomers)): ?>
                                <tr>
                                    <td colspan="4" class="text-center">No customer data available for the selected period.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- ReCharts JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/recharts/2.1.12/recharts.min.js"></script>
    
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
            
            // Export buttons
            document.getElementById('exportPDF').addEventListener('click', function() {
                alert('PDF export functionality would be implemented here.');
            });
            
            document.getElementById('exportExcel').addEventListener('click', function() {
                alert('Excel export functionality would be implemented here.');
            });
            
            // Revenue Chart
            <?php if (($reportType === 'overview' || $reportType === 'revenue') && !empty($revenueChartData)): ?>
            var revenueChartData = <?php echo $revenueChartJson; ?>;
            
            // Draw Revenue Chart using Chart.js or another library
            // This is a placeholder for demonstration
            console.log('Revenue Chart Data:', revenueChartData);
            
            const revenueChartContainer = document.getElementById('revenueChart');
            if (revenueChartContainer) {
                revenueChartContainer.innerHTML = '<div class="p-5 text-center"><i class="fas fa-chart-line fa-4x mb-3 text-primary"></i><p>Revenue chart would be displayed here with the provided data.</p></div>';
            }
            <?php endif; ?>
            
            // Bookings Chart
            <?php if (($reportType === 'overview' || $reportType === 'bookings') && !empty($bookingsChartData)): ?>
            var bookingsChartData = <?php echo $bookingsChartJson; ?>;
            
            // Draw Bookings Chart using Chart.js or another library
            // This is a placeholder for demonstration
            console.log('Bookings Chart Data:', bookingsChartData);
            
            const bookingsChartContainer = document.getElementById('bookingsChart');
            if (bookingsChartContainer) {
                bookingsChartContainer.innerHTML = '<div class="p-5 text-center"><i class="fas fa-chart-bar fa-4x mb-3 text-primary"></i><p>Bookings chart would be displayed here with the provided data.</p></div>';
            }
            <?php endif; ?>
            
            // Status Pie Chart
            <?php if (($reportType === 'overview' || $reportType === 'bookings') && !empty($statusChartData)): ?>
            var statusChartData = <?php echo $statusChartJson; ?>;
            
            // Draw Status Pie Chart using Chart.js or another library
            // This is a placeholder for demonstration
            console.log('Status Chart Data:', statusChartData);
            
            const statusPieChartContainer = document.getElementById('statusPieChart');
            if (statusPieChartContainer) {
                statusPieChartContainer.innerHTML = '<div class="p-5 text-center"><i class="fas fa-chart-pie fa-4x mb-3 text-primary"></i><p>Status pie chart would be displayed here with the provided data.</p></div>';
            }
            <?php endif; ?>
            
            // Quotes Chart
            <?php if (($reportType === 'overview' || $reportType === 'quotes') && !empty($quotesChartData)): ?>
            var quotesChartData = <?php echo $quotesChartJson; ?>;
            
            // Draw Quotes Chart using Chart.js or another library
            // This is a placeholder for demonstration
            console.log('Quotes Chart Data:', quotesChartData);
            
            const quotesChartContainer = document.getElementById('quotesChart');
            if (quotesChartContainer) {
                quotesChartContainer.innerHTML = '<div class="p-5 text-center"><i class="fas fa-chart-area fa-4x mb-3 text-primary"></i><p>Quotes chart would be displayed here with the provided data.</p></div>';
            }
            <?php endif; ?>
            
            // Device Type Pie Chart
            <?php if (($reportType === 'overview' || $reportType === 'quotes') && !empty($deviceTypeChartData)): ?>
            var deviceTypeChartData = <?php echo $deviceTypeChartJson; ?>;
            
            // Draw Device Type Pie Chart using Chart.js or another library
            // This is a placeholder for demonstration
            console.log('Device Type Chart Data:', deviceTypeChartData);
            
            const deviceTypePieChartContainer = document.getElementById('deviceTypePieChart');
            if (deviceTypePieChartContainer) {
                deviceTypePieChartContainer.innerHTML = '<div class="p-5 text-center"><i class="fas fa-chart-pie fa-4x mb-3 text-primary"></i><p>Device type pie chart would be displayed here with the provided data.</p></div>';
            }
            <?php endif; ?>
        });
    </script>
</body>
</html>