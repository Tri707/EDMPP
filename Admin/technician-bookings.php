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

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        // Check if it's an external URL
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            // Construct local path
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            
            // Validate if file exists
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Check if technician ID is provided
$technicianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$technicianId) {
    // Redirect if no ID provided
    header('Location: technicians.php');
    exit;
}

// Process actions
$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update booking status
    if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') {
        try {
            $bookingId = (int)clean_input($_POST['booking_id']);
            $newStatus = clean_input($_POST['status']);
            
            // Validate status
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception("Invalid status.");
            }
            
            // Verify the booking belongs to this technician
            $stmt = $pdo->prepare("
                SELECT id FROM bookings 
                WHERE id = ? AND provider_id = ?
            ");
            $stmt->execute([$bookingId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid booking ID.");
            }
            
            // Update booking status
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = ?, updated_at = NOW()
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([
                $newStatus,
                $bookingId,
                $technicianId
            ]);
            
            $actionMessage = "Booking status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Database error: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = $e->getMessage();
            $actionType = "warning";
        }
    }
    
    // Update payment status
    if (isset($_POST['action']) && $_POST['action'] === 'updatePayment') {
        try {
            $bookingId = (int)clean_input($_POST['booking_id']);
            $newPaymentStatus = clean_input($_POST['payment_status']);
            
            // Validate payment status
            $validPaymentStatuses = ['unpaid', 'paid', 'refunded'];
            if (!in_array($newPaymentStatus, $validPaymentStatuses)) {
                throw new Exception("Invalid payment status.");
            }
            
            // Verify the booking belongs to this technician
            $stmt = $pdo->prepare("
                SELECT id FROM bookings 
                WHERE id = ? AND provider_id = ?
            ");
            $stmt->execute([$bookingId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid booking ID.");
            }
            
            // Update payment status
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET payment_status = ?, updated_at = NOW()
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([
                $newPaymentStatus,
                $bookingId,
                $technicianId
            ]);
            
            $actionMessage = "Payment status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Database error: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = $e->getMessage();
            $actionType = "warning";
        }
    }
}

// Get technician information
$technicianData = null;

try {
    // Query to get technician data with user information
    $stmt = $pdo->prepare("
        SELECT p.*, 
               u.id as user_id, 
               u.username, 
               u.email, 
               u.first_name, 
               u.last_name, 
               u.phone, 
               u.status as user_status,
               u.profile_image
        FROM providers p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ?
    ");
    
    $stmt->execute([$technicianId]);
    $technicianData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$technicianData) {
        // Redirect if technician not found
        header('Location: technicians.php');
        exit;
    }
    
    // Get technician profile image
    $technicianProfileImage = '../default.png';
    if (!empty($technicianData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $technicianData['profile_image'])) {
            $technicianProfileImage = $technicianData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($technicianData['profile_image']);
            if (file_exists($imagePath)) {
                $technicianProfileImage = $imagePath;
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching technician data: " . $e->getMessage());
    header('Location: technicians.php?error=db');
    exit;
}

// Get bookings
$bookings = [];
$statusFilter = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$dateFilter = isset($_GET['date']) ? clean_input($_GET['date']) : '';
$customerFilter = isset($_GET['customer']) ? clean_input($_GET['customer']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'booking_date';
$sortDir = isset($_GET['dir']) ? (clean_input($_GET['dir']) === 'asc' ? 'ASC' : 'DESC') : 'DESC';

// Validate sort column to prevent SQL injection
$allowedSortColumns = ['id', 'booking_date', 'booking_time', 'status', 'total_price', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'booking_date'; // Default sort
}

try {
    $query = "
        SELECT b.*, 
               c.first_name AS customer_first_name, 
               c.last_name AS customer_last_name,
               c.email AS customer_email,
               c.phone AS customer_phone,
               s.name AS service_name
        FROM bookings b
        JOIN users c ON b.customer_id = c.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE b.provider_id = ?
    ";
    $params = [$technicianId];
    
    // Add status filter
    if (!empty($statusFilter)) {
        $query .= " AND b.status = ?";
        $params[] = $statusFilter;
    }
    
    // Add date filter
    if (!empty($dateFilter)) {
        $query .= " AND b.booking_date = ?";
        $params[] = $dateFilter;
    }
    
    // Add customer filter
    if (!empty($customerFilter)) {
        $query .= " AND (c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ?)";
        $searchTerm = "%{$customerFilter}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // Add sort
    $query .= " ORDER BY b.{$sortBy} {$sortDir}";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching bookings: " . $e->getMessage());
    $bookings = [];
}

// Get booking stats
$bookingStats = [
    'total' => 0,
    'pending' => 0,
    'confirmed' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total_revenue' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN payment_status = 'paid' THEN total_price ELSE 0 END) as total_revenue
        FROM bookings
        WHERE provider_id = ?
    ");
    $stmt->execute([$technicianId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($stats) {
        $bookingStats['total'] = (int)$stats['total'];
        $bookingStats['pending'] = (int)$stats['pending'];
        $bookingStats['confirmed'] = (int)$stats['confirmed'];
        $bookingStats['completed'] = (int)$stats['completed'];
        $bookingStats['cancelled'] = (int)$stats['cancelled'];
        $bookingStats['total_revenue'] = (float)$stats['total_revenue'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching booking stats: " . $e->getMessage());
}

// Get dates with bookings for filter dropdown
$bookingDates = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT booking_date 
        FROM bookings 
        WHERE provider_id = ? 
        ORDER BY booking_date DESC
        LIMIT 30
    ");
    $stmt->execute([$technicianId]);
    $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $bookingDates = $dates;
} catch (PDOException $e) {
    error_log("Database error fetching booking dates: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Bookings - FixItNow Admin</title>
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
        
        /* Technician info */
        .technician-info {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .technician-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .technician-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .technician-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .technician-meta {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.7rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
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
            color: #4cd963;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        /* Payment status badges */
        .payment-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.7rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .payment-badge.unpaid {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .payment-badge.paid {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .payment-badge.refunded {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        /* Filter area */
        .filter-area {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* Booking table */
        .booking-table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.03em;
        }
        
        .booking-info {
            display: flex;
            flex-direction: column;
        }
        
        .booking-id {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .booking-service {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .customer-contact {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .empty-state-message {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .empty-state-description {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Stats cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            text-align: center;
            padding: 1.5rem;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Filter pills */
        .filter-pills .nav-link {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            margin-right: 0.5rem;
            color: var(--text-color);
            font-size: 0.9rem;
            background-color: var(--card-bg);
        }
        
        .filter-pills .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Form Controls */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
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
                        <a class="nav-link active" href="technicians.php">
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
            <!-- Back button and page actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="technician-details.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Technician Details
                </a>
            </div>
            
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Technician Info -->
            <div class="technician-info mb-4">
                <div class="technician-avatar">
                    <img src="<?php echo htmlspecialchars($technicianProfileImage); ?>" alt="<?php echo htmlspecialchars($technicianData['first_name']); ?>">
                </div>
                <div>
                    <div class="technician-name">
                        <?php echo htmlspecialchars($technicianData['first_name'] . ' ' . $technicianData['last_name']); ?>
                    </div>
                    <div class="technician-meta">
                        <div class="me-3">
                            <i class="fas fa-calendar-check text-primary me-1"></i>
                            <?php echo $bookingStats['total']; ?> Bookings
                        </div>
                        <div>
                            <i class="fas fa-money-bill-wave text-success me-1"></i>
                            <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                            <?php echo number_format($bookingStats['total_revenue'], 2); ?> Total Revenue
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Booking Stats -->
            <div class="stats-grid">
                <div class="card stat-card">
                    <div class="text-primary mb-3">
                        <i class="fas fa-calendar-check fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $bookingStats['total']; ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-warning mb-3">
                        <i class="fas fa-clock fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $bookingStats['pending']; ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-info mb-3">
                        <i class="fas fa-clipboard-check fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $bookingStats['confirmed']; ?></div>
                    <div class="stat-label">Confirmed</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-success mb-3">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $bookingStats['completed']; ?></div>
                    <div class="stat-label">Completed</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-danger mb-3">
                        <i class="fas fa-times-circle fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $bookingStats['cancelled']; ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>
            
            <!-- Filter Pills -->
            <div class="filter-pills mb-4">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link <?php echo empty($statusFilter) ? 'active' : ''; ?>" href="technician-bookings.php?id=<?php echo $technicianId; ?>">
                            <i class="fas fa-list me-2"></i>All Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>" href="technician-bookings.php?id=<?php echo $technicianId; ?>&status=pending">
                            <i class="fas fa-clock me-2"></i>Pending
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === 'confirmed' ? 'active' : ''; ?>" href="technician-bookings.php?id=<?php echo $technicianId; ?>&status=confirmed">
                            <i class="fas fa-clipboard-check me-2"></i>Confirmed
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === 'completed' ? 'active' : ''; ?>" href="technician-bookings.php?id=<?php echo $technicianId; ?>&status=completed">
                            <i class="fas fa-check-circle me-2"></i>Completed
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === 'cancelled' ? 'active' : ''; ?>" href="technician-bookings.php?id=<?php echo $technicianId; ?>&status=cancelled">
                            <i class="fas fa-times-circle me-2"></i>Cancelled
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Filter Area -->
            <div class="filter-area mb-4">
                <form action="technician-bookings.php" method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="id" value="<?php echo $technicianId; ?>">
                    
                    <?php if (!empty($statusFilter)): ?>
                    <input type="hidden" name="status" value="<?php echo $statusFilter; ?>">
                    <?php endif; ?>
                    
                    <div class="col-md-4">
                        <label for="customer" class="form-label">Search Customer</label>
                        <input type="text" class="form-control" id="customer" name="customer" placeholder="Name or email" value="<?php echo htmlspecialchars($customerFilter); ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label for="date" class="form-label">Filter by Date</label>
                        <select class="form-select" id="date" name="date">
                            <option value="">All Dates</option>
                            <?php foreach ($bookingDates as $date): ?>
                            <option value="<?php echo $date; ?>" <?php echo $dateFilter === $date ? 'selected' : ''; ?>>
                                <?php echo date('M d, Y', strtotime($date)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label for="sort" class="form-label">Sort By</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="booking_date" <?php echo $sortBy === 'booking_date' ? 'selected' : ''; ?>>Date</option>
                            <option value="booking_time" <?php echo $sortBy === 'booking_time' ? 'selected' : ''; ?>>Time</option>
                            <option value="total_price" <?php echo $sortBy === 'total_price' ? 'selected' : ''; ?>>Price</option>
                            <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Booking Created</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <div class="d-flex">
                            <button type="submit" class="btn btn-primary flex-grow-1 me-2">Filter</button>
                            <a href="technician-bookings.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-redo"></i>
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Bookings Table -->
            <?php if (empty($bookings)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <h3 class="empty-state-message">No bookings found</h3>
                <p class="empty-state-description">
                    <?php if (!empty($statusFilter)): ?>
                    No <?php echo $statusFilter; ?> bookings found for this technician.
                    <?php elseif (!empty($dateFilter)): ?>
                    No bookings found for the selected date.
                    <?php elseif (!empty($customerFilter)): ?>
                    No bookings found matching the search term "<?php echo htmlspecialchars($customerFilter); ?>".
                    <?php else: ?>
                    This technician doesn't have any bookings yet.
                    <?php endif; ?>
                </p>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title m-0">Bookings</h5>
                </div>
                <div class="table-responsive">
                    <table class="table booking-table">
                        <thead>
                            <tr>
                                <th>Booking Info</th>
                                <th>Customer</th>
                                <th>Date & Time</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td>
                                    <div class="booking-info">
                                        <div class="booking-id">#<?php echo $booking['id']; ?></div>
                                        <div class="booking-service">
                                            <?php echo !empty($booking['service_name']) ? htmlspecialchars($booking['service_name']) : 'Custom Service'; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="customer-info">
                                        <div class="customer-name">
                                            <?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?>
                                        </div>
                                        <div class="customer-contact">
                                            <?php echo htmlspecialchars($booking['customer_email']); ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <div class="fw-medium"><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></div>
                                        <div class="small text-muted"><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                    <span class="fw-medium"><?php echo number_format($booking['total_price'], 2); ?></span>
                                </td>
                                <td>
                                    <?php 
                                        $statusClasses = [
                                            'pending' => 'pending',
                                            'confirmed' => 'confirmed',
                                            'completed' => 'completed',
                                            'cancelled' => 'cancelled'
                                        ];
                                        $statusClass = isset($statusClasses[$booking['status']]) ? $statusClasses[$booking['status']] : 'pending';
                                    ?>
                                    <span class="status-badge <?php echo $statusClass; ?>">
                                        <?php echo ucfirst($booking['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $paymentClasses = [
                                            'unpaid' => 'unpaid',
                                            'paid' => 'paid',
                                            'refunded' => 'refunded'
                                        ];
                                        $paymentClass = isset($paymentClasses[$booking['payment_status']]) ? $paymentClasses[$booking['payment_status']] : 'unpaid';
                                    ?>
                                    <span class="payment-badge <?php echo $paymentClass; ?>">
                                        <?php echo ucfirst($booking['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- Status Update Dropdown -->
                                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            Status
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if ($booking['status'] !== 'pending'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updateStatus">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="status" value="pending">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-clock text-warning me-2"></i>Mark as Pending
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['status'] !== 'confirmed'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updateStatus">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="status" value="confirmed">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-clipboard-check text-info me-2"></i>Mark as Confirmed
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['status'] !== 'completed'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updateStatus">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="status" value="completed">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-check-circle text-success me-2"></i>Mark as Completed
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['status'] !== 'cancelled'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updateStatus">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="status" value="cancelled">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-times-circle text-danger me-2"></i>Mark as Cancelled
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                        
                                        <!-- Payment Status Dropdown -->
                                        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                            Payment
                                        </button>
                                        <ul class="dropdown-menu">
                                            <?php if ($booking['payment_status'] !== 'unpaid'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updatePayment">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="payment_status" value="unpaid">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-times text-warning me-2"></i>Mark as Unpaid
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['payment_status'] !== 'paid'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updatePayment">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="payment_status" value="paid">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-check text-success me-2"></i>Mark as Paid
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            
                                            <?php if ($booking['payment_status'] !== 'refunded'): ?>
                                            <li>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="updatePayment">
                                                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                                    <input type="hidden" name="payment_status" value="refunded">
                                                    <button type="submit" class="dropdown-item">
                                                        <i class="fas fa-undo text-info me-2"></i>Mark as Refunded
                                                    </button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                        
                                        <!-- View Booking Details -->
                                        <a href="booking-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-info">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
        });
    </script>
</body>
</html>