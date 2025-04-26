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
    // Query to get admin user data - also check status is active
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists in database
    if (!$userData) {
        // User not found in database or not an active admin
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
    // Also redirect on database error for safety
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
} catch (Exception $e) {
    error_log("General error fetching user data: " . $e->getMessage());
    // Also redirect on general error for safety
    session_unset();
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// Process actions
$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process technician verification
    if (isset($_POST['action']) && $_POST['action'] === 'verifyTechnician') {
        try {
            $providerId = clean_input($_POST['provider_id']);
            $verified = clean_input($_POST['verified']); // '0' or '1'
            
            // Update provider verification status
            $stmt = $pdo->prepare("UPDATE providers SET is_verified = ? WHERE id = ?");
            $stmt->execute([$verified, $providerId]);
            
            $actionMessage = "Technician verification status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating verification status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process technician repair price update
    if (isset($_POST['action']) && $_POST['action'] === 'updateHourlyRate') {
        try {
            $providerId = clean_input($_POST['provider_id']);
            $repairPrice = clean_input($_POST['hourly_rate']); 
            
            // Update provider repair price (still using hourly_rate field in database)
            $stmt = $pdo->prepare("UPDATE providers SET hourly_rate = ? WHERE id = ?");
            $stmt->execute([$repairPrice, $providerId]);
            
            $actionMessage = "Repair price updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating repair price: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
}

// Pagination settings
$limit = 15; // Technicians per page
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page); // Ensure page is at least 1
$offset = ($page - 1) * $limit;

// Handle search and filters
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$verificationFilter = isset($_GET['verification']) ? clean_input($_GET['verification']) : '';
$experienceFilter = isset($_GET['experience']) ? clean_input($_GET['experience']) : '';
$specialtyFilter = isset($_GET['specialty']) ? clean_input($_GET['specialty']) : '';
$locationFilter = isset($_GET['location']) ? clean_input($_GET['location']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'created_at';
$sortDir = isset($_GET['dir']) ? (clean_input($_GET['dir']) === 'asc' ? 'ASC' : 'DESC') : 'DESC';

// Validate sort column to prevent SQL injection
$allowedSortColumns = ['created_at', 'hourly_rate', 'specialties', 'experience', 'is_verified', 'location', 'first_name', 'last_name'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'created_at'; // Default sort
}

// Build query and parameters
$params = [];
$query = "SELECT p.*, 
            u.id as user_id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.phone, 
            u.username, 
            u.status, 
            u.created_at,
            (SELECT COUNT(*) FROM bookings WHERE provider_id = p.id) AS booking_count,
            (SELECT AVG(rating) FROM reviews WHERE provider_id = p.id) AS avg_rating
          FROM providers p
          JOIN users u ON p.user_id = u.id
          WHERE 1=1";

// Add search condition
if (!empty($search)) {
    $query .= " AND (u.username LIKE ? OR u.email LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ? OR p.specialties LIKE ?)";
    $searchParam = "%{$search}%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

// Add verification filter
if ($verificationFilter !== '') {
    $query .= " AND p.is_verified = ?";
    $params[] = $verificationFilter;
}

// Add experience filter
if (!empty($experienceFilter)) {
    $query .= " AND p.experience = ?";
    $params[] = $experienceFilter;
}

// Add specialty filter
if (!empty($specialtyFilter)) {
    $query .= " AND p.specialties LIKE ?";
    $params[] = "%{$specialtyFilter}%";
}

// Add location filter
if (!empty($locationFilter)) {
    $query .= " AND p.location LIKE ?";
    $params[] = "%{$locationFilter}%";
}

// Add status filter - only show active users by default
if (!isset($_GET['include_inactive']) || $_GET['include_inactive'] !== '1') {
    $query .= " AND u.status = 'active'";
}

// Add order by clause
$query .= " ORDER BY " . ($sortBy === 'first_name' ? "u.first_name" : ($sortBy === 'last_name' ? "u.last_name" : ($sortBy === 'created_at' ? "u.created_at" : "p.".$sortBy))) . " {$sortDir}";

// Total records query (for pagination)
$countQuery = str_replace("SELECT p.*, 
            u.id as user_id, 
            u.first_name, 
            u.last_name, 
            u.email, 
            u.phone, 
            u.username, 
            u.status, 
            u.created_at,
            (SELECT COUNT(*) FROM bookings WHERE provider_id = p.id) AS booking_count,
            (SELECT AVG(rating) FROM reviews WHERE provider_id = p.id) AS avg_rating", "SELECT COUNT(*) as total", $query);
$countQuery = preg_replace('/ORDER BY .* (ASC|DESC)/i', '', $countQuery);

try {
    // Get total count
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $totalTechnicians = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Add limit for pagination
    $query .= " LIMIT {$offset}, {$limit}";
    
    // Get technicians
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $technicians = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total pages
    $totalPages = ceil($totalTechnicians / $limit);
} catch (PDOException $e) {
    error_log("Database error fetching technicians: " . $e->getMessage());
    $technicians = [];
    $totalTechnicians = 0;
    $totalPages = 0;
    
    $actionMessage = "Error fetching technicians: " . $e->getMessage();
    $actionType = "danger";
}

// Get verification stats
try {
    $stmt = $pdo->query("
        SELECT is_verified, COUNT(*) as count 
        FROM providers 
        GROUP BY is_verified
    ");
    $verificationStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format into associative array
    $verificationCounts = [
        '0' => 0, // Unverified
        '1' => 0  // Verified
    ];
    
    foreach ($verificationStats as $stat) {
        $verificationCounts[$stat['is_verified']] = $stat['count'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching verification stats: " . $e->getMessage());
    $verificationCounts = [
        '0' => 0,
        '1' => 0
    ];
}

// Get top specialties (for filter dropdown)
try {
    $specialtiesQuery = "
        SELECT DISTINCT SUBSTRING_INDEX(SUBSTRING_INDEX(p.specialties, ',', n.n), ',', -1) as specialty,
               COUNT(*) as count
        FROM providers p
        JOIN (
            SELECT 1 as n UNION ALL
            SELECT 2 UNION ALL
            SELECT 3 UNION ALL
            SELECT 4 UNION ALL
            SELECT 5
        ) n ON CHAR_LENGTH(p.specialties) - CHAR_LENGTH(REPLACE(p.specialties, ',', '')) >= n.n - 1
        WHERE p.specialties != '' AND p.specialties IS NOT NULL
        GROUP BY specialty
        ORDER BY count DESC
        LIMIT 10
    ";
    
    $stmt = $pdo->query($specialtiesQuery);
    $specialties = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching specialties: " . $e->getMessage());
    $specialties = [];
}

// Get locations (for filter dropdown)
try {
    $stmt = $pdo->query("
        SELECT DISTINCT location, COUNT(*) as count
        FROM providers 
        WHERE location IS NOT NULL AND location != ''
        GROUP BY location
        ORDER BY count DESC
        LIMIT 10
    ");
    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching locations: " . $e->getMessage());
    $locations = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Management - FixItNow Admin</title>
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
        
        /* Technician profile elements */
        .tech-profile-small {
            display: flex;
            align-items: center;
        }
        
        .tech-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
        }
        
        .tech-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Specialty pills */
        .specialty-pill {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            background-color: rgba(167, 135, 255, 0.15);
            color: var(--primary-color);
            border-radius: 1rem;
            font-size: 0.75rem;
            margin-right: 0.25rem;
            margin-bottom: 0.25rem;
        }
        
        /* Verification badge */
        .verification-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .verification-badge.verified {
            background-color: rgba(25, 135, 84, 0.2);
            color: #40c057;
        }
        
        .verification-badge.unverified {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        /* Experience badge */
        .experience-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.6rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        /* Star rating */
        .star-rating {
            color: #ffc107; /* Bootstrap warning color for stars */
            font-size: 0.875rem;
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
        
        /* Status pill styles */
        .status-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-pill.active {
            background-color: rgba(25, 135, 84, 0.2);
            color: #40c057;
        }
        
        .status-pill.inactive {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
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
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <h1 class="page-title">Technician Management</h1>
            
            <!-- Statistics Cards -->
            <div class="row g-4 mb-4">
                <div class="col-md-6 col-lg-3">
                    <div class="card stat-card">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h6 class="text-muted mb-2">Total Technicians</h6>
                                    <h3 class="mb-0"><?php echo number_format($totalTechnicians); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-primary-soft p-3">
                                    <i class="fas fa-user-cog text-primary"></i>
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
                                    <h6 class="text-muted mb-2">Verified</h6>
                                    <h3 class="mb-0"><?php echo number_format($verificationCounts['1']); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-success-soft p-3">
                                    <i class="fas fa-check-circle text-success"></i>
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
                                    <h6 class="text-muted mb-2">Pending Verification</h6>
                                    <h3 class="mb-0"><?php echo number_format($verificationCounts['0']); ?></h3>
                                </div>
                                <div class="icon-box rounded-circle bg-warning-soft p-3">
                                    <i class="fas fa-hourglass-half text-warning"></i>
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
                                    <h6 class="text-muted mb-2">Avg. Repair Price</h6>
                                    <h3 class="mb-0">
                                        <?php 
                                        try {
                                            $stmt = $pdo->query("SELECT ROUND(AVG(hourly_rate), 2) as avg_rate FROM providers WHERE hourly_rate > 0");
                                            $avg_rate = $stmt->fetch(PDO::FETCH_ASSOC)['avg_rate'];
                                            // FIX: Add null check to prevent passing NULL to number_format
                                            echo '<img src="sar/sar.png" alt="SAR" style="height:20px; margin-right:4px; vertical-align:text-bottom;"> ' . 
                                                 number_format($avg_rate ?? 0, 2);
                                        } catch (PDOException $e) {
                                            echo '<img src="sar/sar.png" alt="SAR" style="height:20px; margin-right:4px; vertical-align:text-bottom;"> 0.00';
                                        }
                                        ?>
                                    </h3>
                                </div>
                                <div class="icon-box rounded-circle bg-info-soft p-3">
                                    <i class="fas fa-tools text-info"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter and Search Area -->
            <div class="filter-area mb-4">
                <form action="technicians.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search Technicians</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                            <input type="text" class="form-control" id="search" name="search" placeholder="Name, email, specialties..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="verification" class="form-label">Verification</label>
                        <select class="form-select" id="verification" name="verification">
                            <option value="">All</option>
                            <option value="1" <?php echo $verificationFilter === '1' ? 'selected' : ''; ?>>Verified</option>
                            <option value="0" <?php echo $verificationFilter === '0' ? 'selected' : ''; ?>>Unverified</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="experience" class="form-label">Experience</label>
                        <select class="form-select" id="experience" name="experience">
                            <option value="">All</option>
                            <option value="1-3" <?php echo $experienceFilter === '1-3' ? 'selected' : ''; ?>>1-3 Years</option>
                            <option value="3-5" <?php echo $experienceFilter === '3-5' ? 'selected' : ''; ?>>3-5 Years</option>
                            <option value="5-10" <?php echo $experienceFilter === '5-10' ? 'selected' : ''; ?>>5-10 Years</option>
                            <option value="10+" <?php echo $experienceFilter === '10+' ? 'selected' : ''; ?>>10+ Years</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="specialty" class="form-label">Specialty</label>
                        <select class="form-select" id="specialty" name="specialty">
                            <option value="">All Specialties</option>
                            <?php foreach ($specialties as $specialty): ?>
                                <option value="<?php echo htmlspecialchars(trim($specialty['specialty'])); ?>" <?php echo $specialtyFilter === trim($specialty['specialty']) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars(trim($specialty['specialty']))); ?> (<?php echo $specialty['count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="location" class="form-label">Location</label>
                        <select class="form-select" id="location" name="location">
                            <option value="">All Locations</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?php echo htmlspecialchars($location['location']); ?>" <?php echo $locationFilter === $location['location'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($location['location']); ?> (<?php echo $location['count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-12 d-flex justify-content-between mt-3">
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                            <a href="technicians.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-redo me-2"></i>Reset
                            </a>
                            <div class="form-check form-check-inline ms-3">
                                <input class="form-check-input" type="checkbox" id="include_inactive" name="include_inactive" value="1" <?php echo (isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1') ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="include_inactive">Include inactive accounts</label>
                            </div>
                        </div>
                        <div>
                            <a href="add-technician.php" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Add New Technician
                            </a>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Technicians Table -->
            <div class="data-table mb-4">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Technician</th>
                                <th>Specialties</th>
                                <th>
                                    Experience
                                    <a href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'experience', 'dir' => ($sortBy === 'experience' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'experience'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    Location
                                    <a href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'location', 'dir' => ($sortBy === 'location' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'location'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    Repair Price
                                    <a href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'hourly_rate', 'dir' => ($sortBy === 'hourly_rate' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'hourly_rate'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>
                                    Verification
                                    <a href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['sort' => 'is_verified', 'dir' => ($sortBy === 'is_verified' && $sortDir === 'ASC') ? 'desc' : 'asc'])); ?>" class="text-decoration-none">
                                        <span class="sort-indicator">
                                            <?php if ($sortBy === 'is_verified'): ?>
                                                <i class="fas fa-sort-<?php echo $sortDir === 'ASC' ? 'up' : 'down'; ?>"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort"></i>
                                            <?php endif; ?>
                                        </span>
                                    </a>
                                </th>
                                <th>Stats</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($technicians)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4">
                                    <i class="fas fa-search fa-2x mb-3 text-muted"></i>
                                    <p>No technicians found matching your criteria.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($technicians as $tech): ?>
                            <tr>
                                <td>
                                    <div class="tech-profile-small">
                                        <div class="tech-avatar-small">
                                            <img src="<?php echo getUserProfileImage($tech['user_id'], '../default.png'); ?>" alt="Technician">
                                        </div>
                                        <div>
                                            <div class="fw-medium"><?php echo htmlspecialchars($tech['first_name'] . ' ' . $tech['last_name']); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars($tech['email']); ?></div>
                                            <?php if ($tech['status'] === 'inactive'): ?>
                                            <span class="status-pill inactive">
                                                <i class="fas fa-times-circle me-1"></i>Inactive
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    if (!empty($tech['specialties'])) {
                                        $specialtiesList = explode(',', $tech['specialties']);
                                        foreach ($specialtiesList as $specialty) {
                                            $specialty = trim($specialty);
                                            if (!empty($specialty)) {
                                                echo '<span class="specialty-pill">' . ucfirst(htmlspecialchars($specialty)) . '</span>';
                                            }
                                        }
                                    } else {
                                        echo '<span class="text-muted small">Not specified</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php if (!empty($tech['experience'])): ?>
                                    <span class="experience-badge">
                                        <i class="fas fa-briefcase me-1"></i>
                                        <?php echo htmlspecialchars($tech['experience']); ?> Years
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted small">Not specified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($tech['location'])): ?>
                                    <i class="fas fa-map-marker-alt text-danger me-1"></i>
                                    <?php echo htmlspecialchars($tech['location']); ?>
                                    <?php else: ?>
                                    <span class="text-muted small">Not specified</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tech['hourly_rate'] > 0): ?>
                                    <span class="fw-medium">
                                        <img src="sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                        <?php echo number_format($tech['hourly_rate'], 2); ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="text-muted small">Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($tech['is_verified'] == 1): ?>
                                    <span class="verification-badge verified">
                                        <i class="fas fa-check-circle me-1"></i>Verified
                                    </span>
                                    <?php else: ?>
                                    <span class="verification-badge unverified">
                                        <i class="fas fa-times-circle me-1"></i>Unverified
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column">
                                        <div>
                                            <i class="fas fa-calendar-check text-primary me-1"></i>
                                            <span class="fw-medium"><?php echo $tech['booking_count']; ?></span> bookings
                                        </div>
                                        <div>
                                            <?php if ($tech['avg_rating']): ?>
                                            <span class="star-rating">
                                                <?php
                                                $rating = round($tech['avg_rating'] * 2) / 2; // Round to nearest 0.5
                                                for ($i = 1; $i <= 5; $i++) {
                                                    if ($i <= $rating) {
                                                        echo '<i class="fas fa-star"></i>';
                                                    } elseif ($i - 0.5 == $rating) {
                                                        echo '<i class="fas fa-star-half-alt"></i>';
                                                    } else {
                                                        echo '<i class="far fa-star"></i>';
                                                    }
                                                }
                                                ?>
                                                <span class="text-muted">(<?php echo number_format($rating, 1); ?>)</span>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-muted small">No ratings yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- View technician details button -->
                                        <a href="technician-details.php?id=<?php echo $tech['id']; ?>" class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <!-- Edit technician button -->
                                        <a href="edit-technician.php?id=<?php echo $tech['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <!-- Verification toggle button -->
                                        <?php if ($tech['status'] === 'active'): ?>
                                        <?php if ($tech['is_verified'] == 0): ?>
                                        <form method="post" action="technicians.php" class="d-inline">
                                            <input type="hidden" name="action" value="verifyTechnician">
                                            <input type="hidden" name="provider_id" value="<?php echo $tech['id']; ?>">
                                            <input type="hidden" name="verified" value="1">
                                            <button type="submit" class="btn btn-sm btn-success" title="Verify Technician">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="post" action="technicians.php" class="d-inline">
                                            <input type="hidden" name="action" value="verifyTechnician">
                                            <input type="hidden" name="provider_id" value="<?php echo $tech['id']; ?>">
                                            <input type="hidden" name="verified" value="0">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Unverify Technician">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <!-- More actions dropdown -->
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li><a class="dropdown-item" href="user-details.php?id=<?php echo $tech['user_id']; ?>">
                                                    <i class="fas fa-user me-2"></i>User Profile
                                                </a></li>
                                                
                                                <li><a class="dropdown-item" href="technician-bookings.php?id=<?php echo $tech['id']; ?>">
                                                    <i class="fas fa-calendar-check me-2"></i>View Bookings
                                                </a></li>
                                                
                                                <li><a class="dropdown-item" href="technician-services.php?id=<?php echo $tech['id']; ?>">
                                                    <i class="fas fa-cogs me-2"></i>View Services
                                                </a></li>
                                                
                                                <li><a class="dropdown-item" href="technician-reviews.php?id=<?php echo $tech['id']; ?>">
                                                    <i class="fas fa-star me-2"></i>View Reviews
                                                </a></li>
                                                
                                                <li><hr class="dropdown-divider"></li>
                                                
                                                <li>
                                                    <a href="#" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#hourlyRateModal" 
                                                       data-tech-id="<?php echo $tech['id']; ?>" 
                                                       data-tech-name="<?php echo htmlspecialchars($tech['first_name'] . ' ' . $tech['last_name']); ?>" 
                                                       data-hourly-rate="<?php echo $tech['hourly_rate']; ?>">
                                                        <i class="fas fa-tools me-2"></i>Update Repair Price
                                                    </a>
                                                </li>
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
                        <a class="page-link" href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" aria-label="Previous">
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
                        <a class="page-link" href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <li class="page-item">
                        <a class="page-link" href="technicians.php?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" aria-label="Next">
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
                Showing <?php echo ($totalTechnicians) ? $offset + 1 : 0; ?> - <?php echo min($offset + $limit, $totalTechnicians); ?> of <?php echo $totalTechnicians; ?> technicians
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Repair Price Update Modal -->
    <div class="modal fade" id="hourlyRateModal" tabindex="-1" aria-labelledby="hourlyRateModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="hourlyRateModalLabel">Update Repair Price</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="hourlyRateForm" method="post" action="technicians.php">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="updateHourlyRate">
                        <input type="hidden" name="provider_id" id="modalTechId" value="">
                        
                        <p>Update repair price for <span id="modalTechName" class="fw-bold"></span>:</p>
                        
                        <div class="mb-3">
                            <label for="hourly_rate" class="form-label">Repair Price</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <img src="sar/sar.png" alt="SAR" height="18">
                                </span>
                                <input type="number" class="form-control" id="hourly_rate" name="hourly_rate" step="0.01" min="0" required>
                            </div>
                            <div class="form-text">Set the complete repair price for this technician's services.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Price</button>
                    </div>
                </form>
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
            
            // Handle hourly rate modal
            const hourlyRateModal = document.getElementById('hourlyRateModal');
            if (hourlyRateModal) {
                hourlyRateModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const techId = button.getAttribute('data-tech-id');
                    const techName = button.getAttribute('data-tech-name');
                    const hourlyRate = button.getAttribute('data-hourly-rate');
                    
                    document.getElementById('modalTechId').value = techId;
                    document.getElementById('modalTechName').textContent = techName;
                    document.getElementById('hourly_rate').value = hourlyRate || '';
                });
            }
            
            // Auto-submit form when checkbox changes
            const includeInactiveCheckbox = document.getElementById('include_inactive');
            if (includeInactiveCheckbox) {
                includeInactiveCheckbox.addEventListener('change', function() {
                    this.form.submit();
                });
            }
        });
    </script>
</body>
</html>