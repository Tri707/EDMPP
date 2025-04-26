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

// Handle actions
$action = isset($_GET['action']) ? $_GET['action'] : '';
$quoteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';

// Handle quote request status change
if ($action == 'update-status' && $quoteId > 0 && isset($_POST['status'])) {
    $newStatus = $_POST['status'];
    $validStatuses = ['pending', 'quoted', 'accepted', 'completed', 'cancelled'];
    
    if (in_array($newStatus, $validStatuses)) {
        try {
            $updateStmt = $pdo->prepare("UPDATE quote_requests SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $quoteId]);
            $message = "Quote request status updated successfully.";
        } catch (PDOException $e) {
            $message = "Error updating status: " . $e->getMessage();
        }
    }
}

// Handle quote request deletion
if ($action == 'delete' && $quoteId > 0) {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Delete all quote notifications related to this request
        $deleteNotificationsStmt = $pdo->prepare("DELETE FROM quote_notifications WHERE request_id = ?");
        $deleteNotificationsStmt->execute([$quoteId]);
        
        // Delete all quotes related to this request
        $deleteQuotesStmt = $pdo->prepare("DELETE FROM quotes WHERE request_id = ?");
        $deleteQuotesStmt->execute([$quoteId]);
        
        // Delete the quote request
        $deleteRequestStmt = $pdo->prepare("DELETE FROM quote_requests WHERE id = ?");
        $deleteRequestStmt->execute([$quoteId]);
        
        // Commit transaction
        $pdo->commit();
        
        $message = "Quote request and all related data deleted successfully.";
    } catch (PDOException $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $message = "Error deleting quote request: " . $e->getMessage();
    }
}

// Filters
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$deviceTypeFilter = isset($_GET['device_type']) ? $_GET['device_type'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$recordsPerPage = 10;
$offset = ($page - 1) * $recordsPerPage;

// Build the base query
$quoteRequestsQuery = "
    SELECT 
        qr.*, 
        u.first_name, 
        u.last_name, 
        u.email,
        u.phone,
        (SELECT COUNT(*) FROM quotes WHERE request_id = qr.id) as quotes_count
    FROM 
        quote_requests qr
    JOIN 
        users u ON qr.customer_id = u.id
    WHERE 
        1=1
";

$countQuery = "
    SELECT 
        COUNT(*) as total
    FROM 
        quote_requests qr
    JOIN 
        users u ON qr.customer_id = u.id
    WHERE 
        1=1
";

// Initialize query parameters array
$queryParams = [];
$countParams = [];

// Apply filters
if (!empty($statusFilter) && $statusFilter != 'all') {
    $quoteRequestsQuery .= " AND qr.status = ?";
    $countQuery .= " AND qr.status = ?";
    $queryParams[] = $statusFilter;
    $countParams[] = $statusFilter;
}

if (!empty($deviceTypeFilter) && $deviceTypeFilter != 'all') {
    $quoteRequestsQuery .= " AND qr.device_type = ?";
    $countQuery .= " AND qr.device_type = ?";
    $queryParams[] = $deviceTypeFilter;
    $countParams[] = $deviceTypeFilter;
}

if (!empty($searchTerm)) {
    $quoteRequestsQuery .= " AND (
        qr.id LIKE ? OR 
        u.first_name LIKE ? OR 
        u.last_name LIKE ? OR 
        u.email LIKE ? OR 
        qr.device_brand LIKE ? OR 
        qr.device_model LIKE ? OR
        qr.issue_description LIKE ?
    )";
    $countQuery .= " AND (
        qr.id LIKE ? OR 
        u.first_name LIKE ? OR 
        u.last_name LIKE ? OR 
        u.email LIKE ? OR 
        qr.device_brand LIKE ? OR 
        qr.device_model LIKE ? OR
        qr.issue_description LIKE ?
    )";
    
    $searchParam = "%$searchTerm%";
    for ($i = 0; $i < 7; $i++) {
        $queryParams[] = $searchParam;
        $countParams[] = $searchParam;
    }
}

if (!empty($dateFrom)) {
    $quoteRequestsQuery .= " AND DATE(qr.created_at) >= ?";
    $countQuery .= " AND DATE(qr.created_at) >= ?";
    $queryParams[] = $dateFrom;
    $countParams[] = $dateFrom;
}

if (!empty($dateTo)) {
    $quoteRequestsQuery .= " AND DATE(qr.created_at) <= ?";
    $countQuery .= " AND DATE(qr.created_at) <= ?";
    $queryParams[] = $dateTo;
    $countParams[] = $dateTo;
}

// Add sorting and pagination
$quoteRequestsQuery .= " ORDER BY qr.created_at DESC LIMIT ?, ?";
$queryParams[] = (int)$offset;
$queryParams[] = (int)$recordsPerPage;

// Execute queries
try {
    // Get total records count for pagination
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $recordsPerPage);
    
    // Get quote requests with pagination
    $stmt = $pdo->prepare($quoteRequestsQuery);
    $stmt->execute($queryParams);
    $quoteRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get device types for filter
    $deviceTypesStmt = $pdo->query("SELECT DISTINCT device_type FROM quote_requests ORDER BY device_type");
    $deviceTypes = $deviceTypesStmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (PDOException $e) {
    error_log("Database error fetching quote requests: " . $e->getMessage());
    $quoteRequests = [];
    $totalRecords = 0;
    $totalPages = 1;
    $deviceTypes = [];
}

// Get quotes for a specific request if we're viewing details
$requestQuotes = [];
if ($action == 'view' && $quoteId > 0) {
    try {
        $quotesStmt = $pdo->prepare("
            SELECT 
                q.*,
                u.first_name as technician_first_name,
                u.last_name as technician_last_name,
                p.specialties,
                p.experience
            FROM 
                quotes q
            JOIN 
                users u ON q.technician_id = u.id
            JOIN
                providers p ON q.provider_id = p.id
            WHERE 
                q.request_id = ?
            ORDER BY 
                q.created_at DESC
        ");
        $quotesStmt->execute([$quoteId]);
        $requestQuotes = $quotesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get quote request details
        $requestDetailsStmt = $pdo->prepare("
            SELECT 
                qr.*,
                u.first_name,
                u.last_name,
                u.email,
                u.phone
            FROM 
                quote_requests qr
            JOIN 
                users u ON qr.customer_id = u.id
            WHERE 
                qr.id = ?
        ");
        $requestDetailsStmt->execute([$quoteId]);
        $requestDetails = $requestDetailsStmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Database error fetching quotes for request: " . $e->getMessage());
        $requestQuotes = [];
        $requestDetails = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Requests - FixItNow Admin</title>
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
        
        .status-badge.quoted {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.accepted {
            background-color: rgba(13, 202, 240, 0.2);
            color: #0dcaf0;
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        /* Device type badges */
        .device-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .device-badge.smartphone {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .device-badge.laptop {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .device-badge.tablet {
            background-color: rgba(102, 16, 242, 0.2);
            color: #6610f2;
        }
        
        .device-badge.desktop {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .device-badge.gaming {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .device-badge.tv {
            background-color: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        /* Filter section */
        .filter-section {
            margin-bottom: 1.5rem;
        }
        
        /* Cursor pointer for clickable rows */
        .clickable-row {
            cursor: pointer;
        }
        
        /* Table styles */
        .table-container {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table thead th {
            border-top: none;
            background-color: rgba(0, 0, 0, 0.05);
            font-weight: 600;
            color: var(--text-color);
        }
        
        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Currency Icon */
        .currency-icon {
            vertical-align: middle;
            margin-right: 3px;
            margin-top: -3px;
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
                        <a class="nav-link active" href="quotes.php">
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
            <?php if (!empty($message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($action == 'view' && isset($requestDetails) && $requestDetails): ?>
                <!-- Quote Request Details View -->
                <h1 class="page-title">Quote Request Details</h1>
                
                <div class="row mb-4">
                    <div class="col-md-12">
                        <a href="quotes.php" class="btn btn-outline-secondary mb-3">
                            <i class="fas fa-arrow-left me-2"></i>Back to Quote Requests
                        </a>
                    </div>
                </div>
                
                <div class="row g-4">
                    <!-- Customer and Request Information -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">Request #<?php echo htmlspecialchars($requestDetails['id']); ?> Information</h5>
                                <span class="status-badge <?php echo htmlspecialchars(strtolower($requestDetails['status'])); ?>">
                                    <?php echo htmlspecialchars(ucfirst($requestDetails['status'])); ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Customer</label>
                                            <div class="fw-medium">
                                                <?php echo htmlspecialchars($requestDetails['first_name'] . ' ' . $requestDetails['last_name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Contact</label>
                                            <div class="fw-medium">
                                                <a href="mailto:<?php echo htmlspecialchars($requestDetails['email']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($requestDetails['email']); ?>
                                                </a>
                                                <br>
                                                <a href="tel:<?php echo htmlspecialchars($requestDetails['phone']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($requestDetails['phone']); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Device Type</label>
                                            <div class="fw-medium">
                                                <span class="device-badge <?php echo htmlspecialchars($requestDetails['device_type']); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($requestDetails['device_type'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Brand & Model</label>
                                            <div class="fw-medium">
                                                <?php 
                                                    $brand = !empty($requestDetails['device_brand']) ? htmlspecialchars($requestDetails['device_brand']) : 'Not specified';
                                                    $model = !empty($requestDetails['device_model']) ? htmlspecialchars($requestDetails['device_model']) : 'Not specified';
                                                    echo $brand . ' / ' . $model;
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Device Condition</label>
                                            <div class="fw-medium">
                                                <?php echo htmlspecialchars(ucfirst($requestDetails['device_condition'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Urgency</label>
                                            <div class="fw-medium">
                                                <?php echo htmlspecialchars(ucfirst($requestDetails['urgency'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Location</label>
                                            <div class="fw-medium">
                                                <?php echo htmlspecialchars($requestDetails['location']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label text-muted">Service Type</label>
                                            <div class="fw-medium">
                                                <?php echo htmlspecialchars(ucfirst($requestDetails['service_type'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Issue Description</label>
                                    <div class="fw-medium">
                                        <?php echo nl2br(htmlspecialchars($requestDetails['issue_description'])); ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($requestDetails['additional_info'])): ?>
                                <div class="mb-3">
                                    <label class="form-label text-muted">Additional Information</label>
                                    <div class="fw-medium">
                                        <?php echo nl2br(htmlspecialchars($requestDetails['additional_info'])); ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label text-muted">Submitted On</label>
                                    <div class="fw-medium">
                                        <?php echo date('F j, Y, g:i A', strtotime($requestDetails['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                                        <i class="fas fa-edit me-2"></i>Update Status
                                    </button>
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteQuoteModal">
                                        <i class="fas fa-trash-alt me-2"></i>Delete Request
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quotes for this request -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0">Quotes Received (<?php echo count($requestQuotes); ?>)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($requestQuotes)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-clipboard-list fa-3x mb-3 text-muted"></i>
                                    <p>No quotes have been submitted for this request yet.</p>
                                </div>
                                <?php else: ?>
                                    <?php foreach ($requestQuotes as $quote): ?>
                                    <div class="card mb-3 <?php echo htmlspecialchars(strtolower($quote['status'])); ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-center mb-3">
                                                <h6 class="mb-0">Quote from <?php echo htmlspecialchars($quote['technician_first_name'] . ' ' . $quote['technician_last_name']); ?></h6>
                                                <span class="status-badge <?php echo htmlspecialchars(strtolower($quote['status'])); ?>">
                                                    <?php echo htmlspecialchars(ucfirst($quote['status'])); ?>
                                                </span>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label text-muted">Estimated Price</label>
                                                        <div class="fw-bold fs-5 text-primary">
                                                            <img src="sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                                            <?php echo htmlspecialchars(number_format($quote['price'], 2)); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label text-muted">Estimated Time</label>
                                                        <div class="fw-medium">
                                                            <?php echo htmlspecialchars($quote['estimated_time']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label text-muted">Description</label>
                                                <div class="fw-medium">
                                                    <?php echo nl2br(htmlspecialchars($quote['description'])); ?>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label text-muted">Warranty</label>
                                                <div class="fw-medium">
                                                    <?php echo htmlspecialchars($quote['warranty']); ?>
                                                </div>
                                            </div>
                                            
                                            <?php if (!empty($quote['parts_needed'])): ?>
                                            <div class="mb-3">
                                                <label class="form-label text-muted">Parts Needed</label>
                                                <div class="fw-medium">
                                                    <?php echo nl2br(htmlspecialchars($quote['parts_needed'])); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($quote['notes'])): ?>
                                            <div class="mb-3">
                                                <label class="form-label text-muted">Additional Notes</label>
                                                <div class="fw-medium">
                                                    <?php echo nl2br(htmlspecialchars($quote['notes'])); ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="mb-3">
                                                <label class="form-label text-muted">Quote Submitted</label>
                                                <div class="fw-medium">
                                                    <?php echo date('F j, Y, g:i A', strtotime($quote['created_at'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Update Status Modal -->
                <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="updateStatusModalLabel">Update Quote Request Status</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <form action="quotes.php?action=update-status&id=<?php echo $quoteId; ?>" method="post">
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select class="form-select" id="status" name="status" required>
                                            <option value="pending" <?php echo $requestDetails['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="quoted" <?php echo $requestDetails['status'] == 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                                            <option value="accepted" <?php echo $requestDetails['status'] == 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                            <option value="completed" <?php echo $requestDetails['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $requestDetails['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
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
                
                <!-- Delete Quote Modal -->
                <div class="modal fade" id="deleteQuoteModal" tabindex="-1" aria-labelledby="deleteQuoteModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteQuoteModalLabel">Confirm Deletion</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to delete this quote request? This will also delete all quotes and notifications associated with this request. This action cannot be undone.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <a href="quotes.php?action=delete&id=<?php echo $quoteId; ?>" class="btn btn-danger">Delete</a>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Quote Requests List View -->
                <h1 class="page-title">Quote Requests</h1>
                
                <!-- Filter Form -->
                <div class="filter-section">
                    <form method="get" action="quotes.php" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $statusFilter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="quoted" <?php echo $statusFilter == 'quoted' ? 'selected' : ''; ?>>Quoted</option>
                                <option value="accepted" <?php echo $statusFilter == 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                <option value="completed" <?php echo $statusFilter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $statusFilter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="device_type" class="form-label">Device Type</label>
                            <select class="form-select" id="device_type" name="device_type">
                                <option value="">All Devices</option>
                                <?php foreach ($deviceTypes as $deviceType): ?>
                                <option value="<?php echo htmlspecialchars($deviceType); ?>" <?php echo $deviceTypeFilter == $deviceType ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($deviceType)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="search" class="form-label">Search</label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Search by ID, customer name, email, or device details" value="<?php echo htmlspecialchars($searchTerm); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="quotes.php" class="btn btn-outline-secondary">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Quote Requests Table -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Quote Requests (<?php echo $totalRecords; ?>)</h5>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" id="refreshBtn">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Customer</th>
                                    <th>Device</th>
                                    <th>Status</th>
                                    <th>Quotes</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($quoteRequests)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">No quote requests found.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($quoteRequests as $request): ?>
                                    <tr class="clickable-row" data-href="quotes.php?action=view&id=<?php echo $request['id']; ?>">
                                        <td>#<?php echo htmlspecialchars($request['id']); ?></td>
                                        <td>
                                            <div>
                                                <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($request['email']); ?></small>
                                        </td>
                                        <td>
                                            <span class="device-badge <?php echo htmlspecialchars($request['device_type']); ?>">
                                                <?php echo htmlspecialchars(ucfirst($request['device_type'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars(strtolower($request['status'])); ?>">
                                                <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($request['quotes_count'] > 0): ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($request['quotes_count']); ?></span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                            <small class="d-block text-muted">
                                                <?php echo date('g:i A', strtotime($request['created_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="quotes.php?action=view&id=<?php echo $request['id']; ?>" class="btn btn-outline-primary" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger delete-btn" data-id="<?php echo $request['id']; ?>" title="Delete">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer">
                        <!-- Pagination -->
                        <nav aria-label="Quote requests pagination">
                            <ul class="pagination justify-content-center mb-0">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo ($page <= 1) ? '#' : '?page=' . ($page - 1) . '&status=' . urlencode($statusFilter) . '&device_type=' . urlencode($deviceTypeFilter) . '&search=' . urlencode($searchTerm) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo); ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($page + 2, $totalPages); $i++): ?>
                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($statusFilter); ?>&device_type=<?php echo urlencode($deviceTypeFilter); ?>&search=<?php echo urlencode($searchTerm); ?>&date_from=<?php echo urlencode($dateFrom); ?>&date_to=<?php echo urlencode($dateTo); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo ($page >= $totalPages) ? '#' : '?page=' . ($page + 1) . '&status=' . urlencode($statusFilter) . '&device_type=' . urlencode($deviceTypeFilter) . '&search=' . urlencode($searchTerm) . '&date_from=' . urlencode($dateFrom) . '&date_to=' . urlencode($dateTo); ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Delete Quote Modal -->
                <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteConfirmModalLabel">Confirm Deletion</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to delete this quote request? This will also delete all quotes and notifications associated with this request. This action cannot be undone.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <a href="#" id="confirmDeleteBtn" class="btn btn-danger">Delete</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
            
            // Clickable rows
            const clickableRows = document.querySelectorAll('.clickable-row');
            clickableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't navigate if clicked on a button or link
                    if (e.target.closest('a, button, .btn')) {
                        return;
                    }
                    window.location.href = this.dataset.href;
                });
            });
            
            // Delete confirmation
            const deleteButtons = document.querySelectorAll('.delete-btn');
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            const deleteConfirmModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const quoteId = this.dataset.id;
                    confirmDeleteBtn.href = 'quotes.php?action=delete&id=' + quoteId;
                    deleteConfirmModal.show();
                });
            });
            
            // Refresh button
            const refreshBtn = document.getElementById('refreshBtn');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function() {
                    window.location.reload();
                });
            }
        });
    </script>
</body>
</html>