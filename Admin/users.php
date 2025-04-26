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

// Function to get user profile image URL
function getUserProfileImage($userId, $defaultImage = '../default.png') {
    try {
        global $pdo;
        
        // Check if we have a valid database connection
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            // Include database connection if not already included
            include '../conn.php';
        }
        
        // Query to get user profile image
        $stmt = $pdo->prepare("
            SELECT profile_image 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If user has a profile image, validate and return it
        if ($result && !empty($result['profile_image'])) {
            // Check if it's an external URL
            if (preg_match('/^https?:\/\//', $result['profile_image'])) {
                return $result['profile_image'];
            }
            
            // Construct local path
            $imagePath = '../profile_images/' . basename($result['profile_image']);
            
            // Validate if file exists
            if (file_exists($imagePath)) {
                return $imagePath;
            }
        }
        
        // Return default image if no valid profile image found
        return $defaultImage;
    } catch (PDOException $e) {
        // Log error
        error_log("Database error getting profile image: " . $e->getMessage());
        return $defaultImage;
    } catch (Exception $e) {
        // Log general error
        error_log("Error getting profile image: " . $e->getMessage());
        return $defaultImage;
    }
}

// Get user information for the logged-in admin
$userData = null;
$profileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists in database
    if (!$userData) {
        // User not in database or not an admin or not active
        // Destroy session and redirect to login page
        session_unset();
        session_destroy();
        header('Location: ../login.php');
        exit;
    }
    
    // Set profile image path
    $profileImage = getUserProfileImage($userId);
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    // Redirect on database error
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
} catch (Exception $e) {
    error_log("General error fetching user data: " . $e->getMessage());
    // Redirect on general error
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// Process actions
$actionMessage = '';
$actionType = '';

// Check for session messages (from redirects)
if (isset($_SESSION['action_message']) && !empty($_SESSION['action_message'])) {
    $actionMessage = $_SESSION['action_message'];
    $actionType = $_SESSION['action_type'] ?? 'info';
    
    // Clear session messages
    unset($_SESSION['action_message']);
    unset($_SESSION['action_type']);
}

// Debug POST data (remove in production)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("POST data received: " . print_r($_POST, true));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process user status changes
    if (isset($_POST['action']) && $_POST['action'] === 'updateUserStatus') {
        try {
            $targetUserId = (int)clean_input($_POST['user_id']);
            $newStatus = clean_input($_POST['status']); // 'active' or 'inactive'
            $returnUrl = isset($_POST['return_url']) ? $_POST['return_url'] : 'users.php';
            
            // Validate inputs
            if ($targetUserId <= 0) {
                throw new Exception("Invalid user ID provided");
            }
            
            if (!in_array($newStatus, ['active', 'inactive'])) {
                throw new Exception("Invalid status value");
            }
            
            // Update user status
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $targetUserId]);
            
            if ($stmt->rowCount() > 0) {
                // Add success message to session to persist after redirect
                $_SESSION['action_message'] = "User status updated successfully.";
                $_SESSION['action_type'] = "success";
            } else {
                $_SESSION['action_message'] = "No changes made. User might not exist or already has that status.";
                $_SESSION['action_type'] = "warning";
            }
            
            // Redirect back to the page that initiated the request
            header("Location: " . $returnUrl);
            exit;
            
        } catch (PDOException $e) {
            $actionMessage = "Database error updating user status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = "Error updating user status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process user deletion (soft delete by making inactive)
    if (isset($_POST['action']) && $_POST['action'] === 'deleteUser') {
        try {
            $targetUserId = (int)clean_input($_POST['user_id']);
            $returnUrl = isset($_POST['return_url']) ? $_POST['return_url'] : 'users.php';
            
            // Validate input
            if ($targetUserId <= 0) {
                throw new Exception("Invalid user ID provided");
            }
            
            // Don't allow deleting own account
            if ($targetUserId == $userId) {
                $_SESSION['action_message'] = "You cannot delete your own account.";
                $_SESSION['action_type'] = "warning";
            } else {
                // First, check if the user exists
                $checkStmt = $pdo->prepare("SELECT id, status FROM users WHERE id = ?");
                $checkStmt->execute([$targetUserId]);
                $userExists = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$userExists) {
                    throw new Exception("User not found");
                }
                
                // Soft delete by setting status to inactive
                $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
                $result = $stmt->execute([$targetUserId]);
                
                if ($result && $stmt->rowCount() > 0) {
                    $_SESSION['action_message'] = "User has been deactivated successfully.";
                    $_SESSION['action_type'] = "success";
                } else {
                    if ($userExists['status'] === 'inactive') {
                        $_SESSION['action_message'] = "User is already inactive.";
                        $_SESSION['action_type'] = "info";
                    } else {
                        $_SESSION['action_message'] = "No changes made. Please try again.";
                        $_SESSION['action_type'] = "warning";
                    }
                }
            }
            
            // Redirect back to the page that initiated the request
            header("Location: " . $returnUrl);
            exit;
            
        } catch (PDOException $e) {
            $actionMessage = "Database error deactivating user: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = "Error deactivating user: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
}

// Pagination settings
$limit = 15; // Users per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $limit;

// Handle search and filters
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$roleFilter = isset($_GET['role']) ? clean_input($_GET['role']) : '';
$statusFilter = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'created_at';
$sortDir = isset($_GET['dir']) ? (clean_input($_GET['dir']) === 'asc' ? 'ASC' : 'DESC') : 'DESC';

// Validate sort column to prevent SQL injection
$allowedSortColumns = ['id', 'username', 'email', 'role', 'first_name', 'last_name', 'status', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'created_at'; // Default sort
}

// Build query and parameters
$params = [];
$query = "SELECT u.*, 
            CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END AS is_provider
          FROM users u
          LEFT JOIN providers p ON u.id = p.user_id
          WHERE 1=1";

// Add search condition
if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Add role filter
if (!empty($roleFilter)) {
    $query .= " AND u.role = ?";
    $params[] = $roleFilter;
}

// Add status filter
if (!empty($statusFilter)) {
    $query .= " AND u.status = ?";
    $params[] = $statusFilter;
}

// Add order by clause
$query .= " ORDER BY {$sortBy} {$sortDir}";

// Total records query (for pagination)
$countQuery = str_replace("SELECT u.*, 
            CASE WHEN p.id IS NOT NULL THEN 1 ELSE 0 END AS is_provider", "SELECT COUNT(*) as total", $query);
$countQuery = preg_replace('/ORDER BY .* (ASC|DESC)/i', '', $countQuery);

try {
    // Get total count
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Add limit for pagination
    $query .= " LIMIT {$offset}, {$limit}";
    
    // Get users
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $totalPages = ceil($totalUsers / $limit);
} catch (PDOException $e) {
    error_log("Database error fetching users: " . $e->getMessage());
    $users = [];
    $totalUsers = 0;
    $totalPages = 0;
    
    $actionMessage = "Error fetching users: " . $e->getMessage();
    $actionType = "danger";
}

// Get user role stats
try {
    $stmt = $pdo->query("
        SELECT role, COUNT(*) as count 
        FROM users 
        GROUP BY role
    ");
    $roleStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format into associative array
    $roleCounts = [
        'admin' => 0,
        'provider' => 0,
        'customer' => 0
    ];
    
    foreach ($roleStats as $stat) {
        $roleCounts[$stat['role']] = $stat['count'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching role stats: " . $e->getMessage());
    $roleCounts = [
        'admin' => 0,
        'provider' => 0,
        'customer' => 0
    ];
}

// Get user status stats
try {
    $stmt = $pdo->query("
        SELECT status, COUNT(*) as count 
        FROM users 
        GROUP BY status
    ");
    $statusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format into associative array
    $statusCounts = [
        'active' => 0,
        'inactive' => 0
    ];
    
    foreach ($statusStats as $stat) {
        $statusCounts[$stat['status']] = $stat['count'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching status stats: " . $e->getMessage());
    $statusCounts = [
        'active' => 0,
        'inactive' => 0
    ];
}

// Function to build query string for current request
function buildQueryString($exclude = []) {
    $params = $_GET;
    
    // Remove any params that should be excluded
    foreach ($exclude as $key) {
        if (isset($params[$key])) {
            unset($params[$key]);
        }
    }
    
    return http_build_query($params);
}

// Current URL for form actions
$currentUrl = $_SERVER['PHP_SELF'];
if (!empty($_SERVER['QUERY_STRING'])) {
    $currentUrl .= '?' . $_SERVER['QUERY_STRING'];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - FixItNow Admin</title>
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
        .stat-card {
            border-radius: 1rem;
            border: none;
            background-color: var(--card-bg);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Data Tables */
        .data-table {
            background-color: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .data-table .table {
            margin-bottom: 0;
        }
        
        .data-table .table th {
            border-top: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.03em;
        }
        
        .data-table .table td {
            vertical-align: middle;
        }
        
        /* Filter area */
        .filter-area {
            background-color: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* User profile elements */
        .user-profile-small {
            display: flex;
            align-items: center;
        }
        
        .user-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
        }
        
        .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Status styles */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-badge.active {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.inactive {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        /* Role badge styles */
        .role-badge {
            padding: 0.35rem 0.6rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .role-badge.admin {
            background-color: #dc3545;
            color: white;
        }
        
        .role-badge.provider {
            background-color: #0d6efd;
            color: white;
        }
        
        .role-badge.customer {
            background-color: #198754;
            color: white;
        }
        
        /* Pagination styles */
        .pagination .page-link {
            border-radius: 0.5rem;
            margin: 0 0.2rem;
            color: var(--primary-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        /* Form Styles */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
            border-color: var(--primary-color);
        }
        
        /* Sort indicators */
        .sort-indicator {
            display: inline-block;
            margin-left: 0.25rem;
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
                    <?php if($loggedIn && isset($userData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($userData['first_name']); ?></span>
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
                        <a class="nav-link active" href="users.php">
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
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <h1 class="page-title">User Management</h1>
            
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="card stat-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-2">Total Users</h6>
                                    <h3 class="mb-0"><?php echo number_format($totalUsers); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-primary-soft p-3">
                                    <i class="fas fa-users text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card stat-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-2">Active Users</h6>
                                    <h3 class="mb-0"><?php echo number_format($statusCounts['active']); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-success-soft p-3">
                                    <i class="fas fa-user-check text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card stat-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-2">Technicians</h6>
                                    <h3 class="mb-0"><?php echo number_format($roleCounts['provider']); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-info-soft p-3">
                                    <i class="fas fa-user-cog text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="card stat-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-2">Customers</h6>
                                    <h3 class="mb-0"><?php echo number_format($roleCounts['customer']); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-warning-soft p-3">
                                    <i class="fas fa-user text-warning"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter and Search Area -->
            <div class="filter-area mb-4">
                <form action="users.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search Users</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="role" class="form-label">Role</label>
                        <select class="form-select" id="role" name="role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            <option value="provider" <?php echo $roleFilter === 'provider' ? 'selected' : ''; ?>>Technician</option>
                            <option value="customer" <?php echo $roleFilter === 'customer' ? 'selected' : ''; ?>>Customer</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="sort" class="form-label">Sort By</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Join Date</option>
                            <option value="username" <?php echo $sortBy === 'username' ? 'selected' : ''; ?>>Username</option>
                            <option value="first_name" <?php echo $sortBy === 'first_name' ? 'selected' : ''; ?>>Name</option>
                            <option value="status" <?php echo $sortBy === 'status' ? 'selected' : ''; ?>>Status</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="dir" class="form-label">Order</label>
                        <select class="form-select" id="dir" name="dir">
                            <option value="desc" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                            <option value="asc" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                        </select>
                    </div>
                    
                    <div class="col-md-12 d-flex justify-content-between mt-3">
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="users.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                        </div>
                        <div>
                            <a href="add-user.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Add New User
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Users Table -->
            <div class="data-table mb-4">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th style="width: 60px">ID</th>
                                <th style="width: 30%">User Info</th>
                                <th>
                                    Role
                                    <a href="users.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'role', 'dir' => ($sortBy === 'role' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'role'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>Contact</th>
                                <th>
                                    Joined
                                    <a href="users.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'created_at', 'dir' => ($sortBy === 'created_at' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'created_at'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    Status
                                    <a href="users.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'status', 'dir' => ($sortBy === 'status' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'status'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4">
                                    <i class="fas fa-search fa-2x mb-3 text-muted"></i>
                                    <p>No users found matching your criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo $user['id']; ?></td>
                                <td>
                                    <div class="user-profile-small">
                                        <div class="user-avatar-small">
                                            <img src="<?php echo getUserProfileImage($user['id'], '../default.png'); ?>" alt="User">
                                        </div>
                                        <div>
                                            <div class="fw-medium"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                            <div class="small text-muted">@<?php echo htmlspecialchars($user['username']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge <?php echo $user['role']; ?>">
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                    <?php if ($user['is_provider']): ?>
                                    <span class="badge bg-info ms-1">Technician Profile</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="small">
                                        <div><i class="fas fa-envelope me-2 text-muted"></i><?php echo htmlspecialchars($user['email']); ?></div>
                                        <div><i class="fas fa-phone me-2 text-muted"></i><?php echo htmlspecialchars($user['phone']); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $user['status']; ?>">
                                        <i class="fas fa-<?php echo $user['status'] === 'active' ? 'check-circle' : 'times-circle'; ?> me-1"></i>
                                        <?php echo ucfirst($user['status']); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- View user details button -->
                                        <a href="user-details.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <!-- Edit user button -->
                                        <a href="edit-user.php?id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <!-- Status toggle dropdown -->
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-<?php echo $user['status'] === 'active' ? 'success' : 'warning'; ?> dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Change Status">
                                                <i class="fas fa-toggle-<?php echo $user['status'] === 'active' ? 'on' : 'off'; ?>"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($user['status'] === 'active'): ?>
                                                <li>
                                                    <form method="post" action="users.php">
                                                        <input type="hidden" name="action" value="updateUserStatus">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="status" value="inactive">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($currentUrl); ?>">
                                                        <button type="submit" class="dropdown-item text-warning">
                                                            <i class="fas fa-toggle-off me-2"></i>Deactivate
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php else: ?>
                                                <li>
                                                    <form method="post" action="users.php">
                                                        <input type="hidden" name="action" value="updateUserStatus">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                        <input type="hidden" name="status" value="active">
                                                        <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($currentUrl); ?>">
                                                        <button type="submit" class="dropdown-item text-success">
                                                            <i class="fas fa-toggle-on me-2"></i>Activate
                                                        </button>
                                                    </form>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                        
                                        <!-- More actions dropdown -->
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <?php if ($user['is_provider']): ?>
                                                <li><a class="dropdown-item" href="technician-details.php?id=<?php echo $user['id']; ?>">
                                                    <i class="fas fa-id-badge me-2"></i>Technician Profile
                                                </a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <?php endif; ?>
                                                
                                                <li><a class="dropdown-item" href="user-bookings.php?user_id=<?php echo $user['id']; ?>">
                                                    <i class="fas fa-calendar-check me-2"></i>Bookings
                                                </a></li>
                                                
                                                <li><a class="dropdown-item" href="user-quotes.php?user_id=<?php echo $user['id']; ?>">
                                                    <i class="fas fa-clipboard-list me-2"></i>Quotes
                                                </a></li>
                                                
                                                <?php if ($user['id'] != $userId): // Don't allow deleting own account ?>
                                                <li><hr class="dropdown-divider"></li>
                                                <li>
                                                    <button type="button" class="dropdown-item text-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal" 
                                                        data-user-id="<?php echo $user['id']; ?>" 
                                                        data-user-name="<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>"
                                                        data-return-url="<?php echo htmlspecialchars($currentUrl); ?>">
                                                        <i class="fas fa-trash-alt me-2"></i>Delete
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="users.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <a class="page-link" href="#" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    
                    if ($endPage - $startPage < 4 && $startPage > 1) {
                        $startPage = max(1, $endPage - 4);
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): 
                    ?>
                    <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                        <a class="page-link" href="users.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="users.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <a class="page-link" href="#" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            
            <div class="text-center text-muted mt-2">
                Showing <?php echo ($totalUsers) ? $offset + 1 : 0; ?> - <?php echo min($offset + $limit, $totalUsers); ?> of <?php echo $totalUsers; ?> users
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteModalLabel">Confirm User Deactivation</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to deactivate <span id="deleteUserName" class="fw-bold"></span>?</p>
                    <p class="text-danger">This action will set the user's status to inactive. The user will no longer be able to log in.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form id="deleteUserForm" method="post" action="">
                        <input type="hidden" name="action" value="deleteUser">
                        <input type="hidden" name="user_id" id="deleteUserId" value="">
                        <input type="hidden" name="return_url" id="deleteReturnUrl" value="">
                        <button type="submit" class="btn btn-danger">Deactivate User</button>
                    </form>
                </div>
            </div>
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
            
            // Handle delete user modal
            const deleteModal = document.getElementById('deleteModal');
            if (deleteModal) {
                deleteModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const userId = button.getAttribute('data-user-id');
                    const userName = button.getAttribute('data-user-name');
                    const returnUrl = button.getAttribute('data-return-url');
                    
                    // Set the form's action attribute
                    const form = document.getElementById('deleteUserForm');
                    form.action = 'users.php';
                    
                    // Set the user ID in the hidden field
                    document.getElementById('deleteUserId').value = userId;
                    document.getElementById('deleteUserName').textContent = userName;
                    
                    // Set the return URL if provided
                    if (returnUrl) {
                        document.getElementById('deleteReturnUrl').value = returnUrl;
                    }
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