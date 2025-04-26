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

// Check if any accounts exist in the database
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($count['count'] == 0) {
        // No accounts exist, redirect to login page
        header('Location: ../login.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error checking user accounts: " . $e->getMessage());
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

// Handle review actions
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['review_id'])) {
        $action = $_POST['action'];
        $reviewId = (int)$_POST['review_id'];
        
        try {
            if ($action === 'publish') {
                $stmt = $pdo->prepare("UPDATE reviews SET is_published = 1 WHERE id = ?");
                $stmt->execute([$reviewId]);
                $successMessage = 'Review published successfully.';
            } elseif ($action === 'unpublish') {
                $stmt = $pdo->prepare("UPDATE reviews SET is_published = 0 WHERE id = ?");
                $stmt->execute([$reviewId]);
                $successMessage = 'Review hidden successfully.';
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
                $stmt->execute([$reviewId]);
                $successMessage = 'Review deleted successfully.';
            }
        } catch (PDOException $e) {
            $errorMessage = 'Database error: ' . $e->getMessage();
        }
    }
}

// Define pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Define filters
$rating = isset($_GET['rating']) ? $_GET['rating'] : '';
$published = isset($_GET['published']) ? $_GET['published'] : '';
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';

// Initialize stats
$stats = [
    'total_reviews' => 0,
    'average_rating' => 0,
    'published_count' => 0,
    'unpublished_count' => 0,
    'five_star_count' => 0,
    'four_star_count' => 0,
    'three_star_count' => 0,
    'two_star_count' => 0,
    'one_star_count' => 0
];

// Get review stats safely
try {
    // Get overall stats
    $statsQuery = "
        SELECT 
            COUNT(*) as total_reviews,
            COALESCE(AVG(rating), 0) as average_rating,
            SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published_count,
            SUM(CASE WHEN is_published = 0 THEN 1 ELSE 0 END) as unpublished_count
        FROM reviews
    ";
    $statsStmt = $pdo->query($statsQuery);
    $basicStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Make sure we have values (avoid null errors)
    $stats['total_reviews'] = (int)($basicStats['total_reviews'] ?? 0);
    $stats['average_rating'] = (float)($basicStats['average_rating'] ?? 0);
    $stats['published_count'] = (int)($basicStats['published_count'] ?? 0);
    $stats['unpublished_count'] = (int)($basicStats['unpublished_count'] ?? 0);
    
    // Get rating breakdowns
    if ($stats['total_reviews'] > 0) {
        $ratingBreakdownQuery = "
            SELECT 
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star_count,
                SUM(CASE WHEN rating >= 4 AND rating < 5 THEN 1 ELSE 0 END) as four_star_count,
                SUM(CASE WHEN rating >= 3 AND rating < 4 THEN 1 ELSE 0 END) as three_star_count,
                SUM(CASE WHEN rating >= 2 AND rating < 3 THEN 1 ELSE 0 END) as two_star_count,
                SUM(CASE WHEN rating < 2 THEN 1 ELSE 0 END) as one_star_count
            FROM reviews
        ";
        $ratingStmt = $pdo->query($ratingBreakdownQuery);
        $ratingBreakdown = $ratingStmt->fetch(PDO::FETCH_ASSOC);
        
        // Make sure we have values (avoid null errors)
        $stats['five_star_count'] = (int)($ratingBreakdown['five_star_count'] ?? 0);
        $stats['four_star_count'] = (int)($ratingBreakdown['four_star_count'] ?? 0);
        $stats['three_star_count'] = (int)($ratingBreakdown['three_star_count'] ?? 0);
        $stats['two_star_count'] = (int)($ratingBreakdown['two_star_count'] ?? 0);
        $stats['one_star_count'] = (int)($ratingBreakdown['one_star_count'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Database error fetching review stats: " . $e->getMessage());
}

// Fetch reviews with customer and provider information
$reviews = [];
$totalPages = 0;

try {
    // Base query with improved error handling for provider/user joins
    $query = "
        SELECT r.*, 
               c.first_name AS customer_first_name, c.last_name AS customer_last_name,
               p.first_name AS provider_first_name, p.last_name AS provider_last_name,
               b.booking_date, b.total_price,
               prov.specialties
        FROM reviews r
        JOIN users c ON r.customer_id = c.id
        JOIN providers prov ON r.provider_id = prov.id
        JOIN users p ON prov.user_id = p.id
        LEFT JOIN bookings b ON r.booking_id = b.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add filters
    if ($rating !== '') {
        if ($rating === '5') {
            $query .= " AND r.rating = 5.0";
        } elseif ($rating === '4') {
            $query .= " AND r.rating >= 4.0 AND r.rating < 5.0";
        } elseif ($rating === '3') {
            $query .= " AND r.rating >= 3.0 AND r.rating < 4.0";
        } elseif ($rating === '2') {
            $query .= " AND r.rating >= 2.0 AND r.rating < 3.0";
        } elseif ($rating === '1') {
            $query .= " AND r.rating < 2.0";
        }
    }
    
    if ($published !== '') {
        $query .= " AND r.is_published = ?";
        $params[] = ($published === 'published') ? 1 : 0;
    }
    
    if (!empty($searchTerm)) {
        $query .= " AND (
            c.first_name LIKE ? OR 
            c.last_name LIKE ? OR 
            p.first_name LIKE ? OR 
            p.last_name LIKE ? OR 
            r.comment LIKE ?
        )";
        
        $searchParam = "%{$searchTerm}%";
        for ($i = 0; $i < 5; $i++) {
            $params[] = $searchParam;
        }
    }
    
    // Count total records for pagination (safely)
    try {
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$query}) as count_table");
        $countStmt->execute($params);
        $totalRecords = $countStmt->fetchColumn() ?: 0;
        $totalPages = ceil($totalRecords / $perPage);
    } catch (PDOException $e) {
        error_log("Error counting reviews: " . $e->getMessage());
        $totalRecords = 0;
        $totalPages = 0;
    }
    
    // Only execute the main query if we have records to show
    if ($totalRecords > 0) {
        // Add sorting and pagination
        $query .= " ORDER BY r.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $errorMessage = 'Database error: ' . $e->getMessage();
    error_log("Error fetching reviews: " . $e->getMessage());
    $reviews = [];
    $totalPages = 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Reviews - FixItNow Admin</title>
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
        
        /* Star Ratings */
        .star-rating {
            color: #ffc107;
            font-size: 1.2rem;
        }
        
        .star-rating.half-star::after {
            content: "\f089"; /* fa-star-half-alt */
            position: absolute;
            left: 0;
            top: 0;
        }
        
        /* Review cards */
        .review-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .review-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Pagination */
        .pagination .page-item .page-link {
            color: var(--text-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item .page-link:hover {
            background-color: var(--primary-light);
        }
        
        /* Stats widget */
        .stats-card {
            margin-bottom: 2rem;
            transition: all 0.3s ease;
        }
        
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.75rem 1.5rem var(--shadow-color);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        /* Rating bars */
        .rating-bars {
            padding: 1rem;
        }
        
        .rating-bar {
            height: 8px;
            background-color: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
        }
        
        .rating-bar-fill {
            height: 100%;
            background-color: #ffc107;
        }
        
        /* Badge for published/unpublished */
        .badge-published {
            background-color: #28a745;
            color: white;
        }
        
        .badge-unpublished {
            background-color: #dc3545;
            color: white;
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 50rem;
            font-size: 0.75em;
            font-weight: 700;
            text-transform: uppercase;
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
                        <a class="nav-link active" href="reviews.php">
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
            <h1 class="page-title">Customer Reviews</h1>
            
            <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($successMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($errorMessage); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Review Stats -->
            <div class="row g-4 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-star fa-2x text-warning mb-3"></i>
                            <div class="stat-value"><?php echo $stats['total_reviews']; ?></div>
                            <div class="stat-label">Total Reviews</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-star-half-alt fa-2x text-warning mb-3"></i>
                            <div class="stat-value"><?php echo number_format($stats['average_rating'], 1); ?></div>
                            <div class="stat-label">Average Rating</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-eye fa-2x text-success mb-3"></i>
                            <div class="stat-value"><?php echo $stats['published_count']; ?></div>
                            <div class="stat-label">Published Reviews</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="card stats-card h-100">
                        <div class="card-body text-center">
                            <i class="fas fa-eye-slash fa-2x text-danger mb-3"></i>
                            <div class="stat-value"><?php echo $stats['unpublished_count']; ?></div>
                            <div class="stat-label">Hidden Reviews</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Rating Breakdown -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Rating Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="rating-bars">
                        <?php
                        $totalReviews = $stats['total_reviews'] > 0 ? $stats['total_reviews'] : 1; // Avoid division by zero
                        $ratingStats = [
                            5 => ['count' => $stats['five_star_count'], 'label' => '5 Stars'],
                            4 => ['count' => $stats['four_star_count'], 'label' => '4 Stars'],
                            3 => ['count' => $stats['three_star_count'], 'label' => '3 Stars'],
                            2 => ['count' => $stats['two_star_count'], 'label' => '2 Stars'],
                            1 => ['count' => $stats['one_star_count'], 'label' => '1 Star']
                        ];
                        
                        foreach ($ratingStats as $star => $data) {
                            $percentage = $stats['total_reviews'] > 0 ? ($data['count'] / $totalReviews) * 100 : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <div>
                                    <?php for ($i = 1; $i <= 5; $i++) { ?>
                                        <i class="fa<?php echo $i <= $star ? 's' : 'r'; ?> fa-star text-warning"></i>
                                    <?php } ?>
                                    <span class="ms-2"><?php echo $data['label']; ?></span>
                                </div>
                                <div>
                                    <strong><?php echo $data['count']; ?></strong>
                                    <span class="text-muted">(<?php echo number_format($percentage, 1); ?>%)</span>
                                </div>
                            </div>
                            <div class="rating-bar">
                                <div class="rating-bar-fill" style="width: <?php echo $percentage; ?>%"></div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
            
            <!-- Filter Controls -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Filter Reviews</h5>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
                        <i class="fas fa-filter me-1"></i> Toggle Filters
                    </button>
                </div>
                <div class="collapse show" id="filterCollapse">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-4">
                                <label for="rating" class="form-label">Rating</label>
                                <select class="form-select" id="rating" name="rating">
                                    <option value="">All Ratings</option>
                                    <option value="5" <?php echo $rating === '5' ? 'selected' : ''; ?>>5 Stars</option>
                                    <option value="4" <?php echo $rating === '4' ? 'selected' : ''; ?>>4 Stars</option>
                                    <option value="3" <?php echo $rating === '3' ? 'selected' : ''; ?>>3 Stars</option>
                                    <option value="2" <?php echo $rating === '2' ? 'selected' : ''; ?>>2 Stars</option>
                                    <option value="1" <?php echo $rating === '1' ? 'selected' : ''; ?>>1 Star</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="published" class="form-label">Status</label>
                                <select class="form-select" id="published" name="published">
                                    <option value="">All Reviews</option>
                                    <option value="published" <?php echo $published === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="unpublished" <?php echo $published === 'unpublished' ? 'selected' : ''; ?>>Hidden</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Search by name or review...">
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <a href="reviews.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times me-1"></i> Clear Filters
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i> Apply Filters
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Reviews List -->
            <?php if (empty($reviews)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-star fa-4x mb-3 text-muted"></i>
                    <h3>No Reviews Found</h3>
                    <p class="text-muted">There are no reviews matching your filter criteria.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="row g-4">
                <?php foreach ($reviews as $review): ?>
                <div class="col-lg-6">
                    <div class="card review-card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <div class="star-rating">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <?php if ($i <= floor($review['rating'])): ?>
                                            <i class="fas fa-star"></i>
                                        <?php elseif ($i - 0.5 <= $review['rating']): ?>
                                            <i class="fas fa-star-half-alt"></i>
                                        <?php else: ?>
                                            <i class="far fa-star"></i>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                    <span class="ms-2"><?php echo number_format($review['rating'], 1); ?></span>
                                </div>
                                <div class="mt-1">
                                    <span class="badge <?php echo $review['is_published'] ? 'badge-published' : 'badge-unpublished'; ?>">
                                        <?php echo $review['is_published'] ? 'Published' : 'Hidden'; ?>
                                    </span>
                                    <small class="ms-2 text-muted">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                    </small>
                                </div>
                            </div>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="reviewActions<?php echo $review['id']; ?>" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="reviewActions<?php echo $review['id']; ?>">
                                    <?php if ($review['is_published']): ?>
                                    <li>
                                        <form method="post">
                                            <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                            <input type="hidden" name="action" value="unpublish">
                                            <button type="submit" class="dropdown-item text-warning">
                                                <i class="fas fa-eye-slash me-2"></i> Hide Review
                                            </button>
                                        </form>
                                    </li>
                                    <?php else: ?>
                                    <li>
                                        <form method="post">
                                            <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                            <input type="hidden" name="action" value="publish">
                                            <button type="submit" class="dropdown-item text-success">
                                                <i class="fas fa-eye me-2"></i> Publish Review
                                            </button>
                                        </form>
                                    </li>
                                    <?php endif; ?>
                                    <li>
                                        <form method="post" onsubmit="return confirm('Are you sure you want to delete this review? This action cannot be undone.');">
                                            <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="dropdown-item text-danger">
                                                <i class="fas fa-trash-alt me-2"></i> Delete Review
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h6 class="mb-2">Customer</h6>
                                        <p class="mb-1">
                                            <i class="fas fa-user me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($review['customer_first_name'] . ' ' . $review['customer_last_name']); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="mb-2">Technician</h6>
                                        <p class="mb-1">
                                            <i class="fas fa-user-cog me-2 text-primary"></i>
                                            <?php echo htmlspecialchars($review['provider_first_name'] . ' ' . $review['provider_last_name']); ?>
                                        </p>
                                        <?php if (!empty($review['specialties'])): ?>
                                        <p class="mb-1 small text-muted">
                                            <i class="fas fa-tools me-2"></i>
                                            <?php
                                            $specialties = explode(',', $review['specialties']);
                                            $specialtiesFormatted = array_map('ucfirst', $specialties);
                                            echo implode(', ', $specialtiesFormatted);
                                            ?>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($review['booking_date'])): ?>
                            <div class="mb-3">
                                <h6 class="mb-2">Service Details</h6>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="fas fa-calendar me-2 text-primary"></i>
                                            <span class="fw-semibold">Date:</span>
                                            <?php echo date('F j, Y', strtotime($review['booking_date'])); ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="mb-1">
                                            <i class="fas fa-money-bill-wave me-2 text-primary"></i>
                                            <span class="fw-semibold">Price:</span>
                                            SAR <?php echo number_format($review['total_price'], 2); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div>
                                <h6 class="mb-2">Review Comment</h6>
                                <div class="p-3 bg-light rounded">
                                    <?php if (!empty($review['comment'])): ?>
                                        <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                    <?php else: ?>
                                        <p class="text-muted mb-0"><em>No comment provided</em></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($rating) ? '&rating=' . urlencode($rating) : ''; ?><?php echo !empty($published) ? '&published=' . urlencode($published) : ''; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?><?php echo !empty($rating) ? '&rating=' . urlencode($rating) : ''; ?><?php echo !empty($published) ? '&published=' . urlencode($published) : ''; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($rating) ? '&rating=' . urlencode($rating) : ''; ?><?php echo !empty($published) ? '&published=' . urlencode($published) : ''; ?><?php echo !empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''; ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
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
            
            // Auto-remove alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    const closeButton = alert.querySelector('.btn-close');
                    if (closeButton) {
                        closeButton.click();
                    }
                }, 5000);
            });
        });
    </script>
</body>
</html>