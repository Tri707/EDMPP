<?php
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
include 'conn.php';

// Initialize variables with default values
$providerProfileImage = '../default.png';
$providerId = 0;
$providerData = [];
$reviews = [];
$reviewStats = [
    'total' => 0,
    'average' => 0,
    'star_counts' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0],
    'recent_count' => 0
];
$message = '';
$messageType = '';

// Handle review response submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'respond' && isset($_POST['review_id']) && isset($_POST['response'])) {
        try {
            $reviewId = filter_input(INPUT_POST, 'review_id', FILTER_VALIDATE_INT);
            $response = filter_input(INPUT_POST, 'response', FILTER_SANITIZE_SPECIAL_CHARS);
            
            if (empty($response)) {
                throw new Exception("Response cannot be empty");
            }
            
            // Verify the review belongs to this provider
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM reviews r
                WHERE r.id = ? AND r.provider_id = ?
            ");
            $checkStmt->execute([$reviewId, $providerId]);
            
            if ((int)$checkStmt->fetchColumn() === 0) {
                throw new Exception("Invalid review");
            }
            
            // Add the response
            $stmt = $pdo->prepare("
                UPDATE reviews 
                SET provider_response = ?, response_date = NOW()
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([$response, $reviewId, $providerId]);
            
            $message = "Your response has been added successfully.";
            $messageType = "success";
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = "danger";
        }
    }
}

// Get provider profile information
try {
    // Query to get provider user data with proper join
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties 
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
                }
            }
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

// Get reviews for this provider with customer data
try {
    if ($providerId) {
        // Get all reviews
        $stmt = $pdo->prepare("
            SELECT r.*, 
                   u.first_name as customer_first_name,
                   u.last_name as customer_last_name,
                   u.profile_image as customer_image,
                   b.booking_date,
                   s.name as service_name,
                   s.category as service_category
            FROM reviews r
            JOIN users u ON r.customer_id = u.id
            JOIN bookings b ON r.booking_id = b.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE r.provider_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$providerId]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate review statistics
        $last30Days = date('Y-m-d', strtotime('-30 days'));
        
        $statsStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                COALESCE(AVG(rating), 0) as average,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star,
                SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_count
            FROM reviews
            WHERE provider_id = ?
        ");
        $statsStmt->execute([$last30Days, $providerId]);
        $statsResult = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($statsResult) {
            $reviewStats = [
                'total' => (int)$statsResult['total'],
                'average' => round((float)$statsResult['average'], 1),
                'star_counts' => [
                    5 => (int)$statsResult['five_star'],
                    4 => (int)$statsResult['four_star'],
                    3 => (int)$statsResult['three_star'],
                    2 => (int)$statsResult['two_star'],
                    1 => (int)$statsResult['one_star']
                ],
                'recent_count' => (int)$statsResult['recent_count']
            ];
        }
        
        // Get monthly review data for chart
        $monthlyStmt = $pdo->prepare("
            SELECT 
                DATE_FORMAT(created_at, '%Y-%m') as month,
                COUNT(*) as count,
                COALESCE(AVG(rating), 0) as average_rating
            FROM reviews
            WHERE provider_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month ASC
        ");
        $monthlyStmt->execute([$providerId]);
        $monthlyData = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format data for chart
        $chartLabels = [];
        $chartCounts = [];
        $chartRatings = [];
        
        foreach ($monthlyData as $data) {
            // Format date as 'Jan 2025', etc.
            $date = date_create_from_format('Y-m', $data['month']);
            $formattedDate = date_format($date, 'M Y');
            
            $chartLabels[] = $formattedDate;
            $chartCounts[] = (int)$data['count'];
            $chartRatings[] = round((float)$data['average_rating'], 1);
        }
        
        $chartData = [
            'labels' => $chartLabels,
            'counts' => $chartCounts,
            'ratings' => $chartRatings
        ];
        $chartDataJson = json_encode($chartData, JSON_NUMERIC_CHECK);
    }
} catch (PDOException $e) {
    error_log("Database error fetching reviews: " . $e->getMessage());
    // Default values already set
}

// Get unread messages count for nav indicator
try {
    $unreadMessagesQuery = "
        SELECT COUNT(*) as count
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
    ";
    
    $unreadMessagesStmt = $pdo->prepare($unreadMessagesQuery);
    $unreadMessagesStmt->execute([$userId]);
    $unreadMessagesCount = (int)$unreadMessagesStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching unread messages count: " . $e->getMessage());
    $unreadMessagesCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews - FixItNow</title>
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
            --primary-color-rgba: rgba(121, 82, 179, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(55, 178, 77, 0.05);   /* Green with alpha */
            --star-color: #ffc107;         /* Star rating color */
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
            --primary-color-rgba: rgba(166, 135, 255, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(55, 178, 77, 0.05);    /* Green with alpha */
            --star-color: #ffc107;         /* Star rating color - same in dark mode */
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
        
        /* Review Card */
        .review-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }
        
        .review-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .review-card.low-rating {
            border-left-color: #dc3545; /* Red for low ratings */
        }
        
        .review-card.medium-rating {
            border-left-color: #fd7e14; /* Orange for medium ratings */
        }
        
        .review-card.high-rating {
            border-left-color: #198754; /* Green for high ratings */
        }
        
        /* Rating Stars */
        .rating-stars {
            color: var(--star-color);
            font-size: 0.9rem;
            display: inline-flex;
        }
        
        .rating-stars.large {
            font-size: 1.5rem;
        }
        
        /* Avatar */
        .avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: white;
            overflow: hidden;
        }
        
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Stats Containers */
        .stats-container {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            flex: 1;
            min-width: 200px;
            padding: 1.5rem;
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease;
            text-align: center;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card.primary {
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-card.success {
            border-left: 4px solid var(--accent-color);
        }
        
        .stat-card.warning {
            border-left: 4px solid var(--star-color);
        }
        
        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        /* Chart container */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin-bottom: 1.5rem;
        }
        
        /* Rating Distribution Bar */
        .rating-bar-container {
            margin-bottom: 1rem;
        }
        
        .rating-bar {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .rating-label {
            min-width: 40px;
            margin-right: 0.5rem;
        }
        
        .rating-count {
            min-width: 40px;
            text-align: right;
            margin-left: 0.5rem;
        }
        
        .progress {
            flex-grow: 1;
            height: 10px;
            border-radius: 5px;
            background-color: rgba(0,0,0,0.1);
        }
        
        .progress-bar {
            border-radius: 5px;
        }
        
        /* Filter toolbar */
        .filter-toolbar {
            margin-bottom: 1.5rem;
        }
        
        /* Review response form */
        .review-response-form {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed var(--border-color);
        }
        
        .review-response {
            margin-top: 1rem;
            padding: 1rem;
            background-color: var(--primary-color-rgba);
            border-radius: 0.5rem;
            position: relative;
        }
        
        .review-response::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 20px;
            border-left: 10px solid transparent;
            border-right: 10px solid transparent;
            border-bottom: 10px solid var(--primary-color-rgba);
        }
        
        .response-date {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
        }
        
        .service-tag {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.25rem;
            margin-right: 0.5rem;
        }
        
        /* Service Category Colors */
        .service-tag.smartphone {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        
        .service-tag.laptop {
            background-color: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
        }
        
        .service-tag.tablet {
            background-color: rgba(253, 126, 20, 0.1);
            color: #fd7e14;
        }
        
        .service-tag.desktop {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .service-tag.gaming {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .service-tag.tv {
            background-color: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
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
        
        /* Empty state */
        .empty-state {
            padding: 3rem;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .stats-container {
                flex-direction: column;
            }
            
            .stat-card {
                min-width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .review-info {
                flex-direction: column;
                align-items: flex-start !important;
            }
            
            .review-meta {
                margin-top: 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .stat-value {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading reviews...</p>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="text-decoration-none d-flex align-items-center">
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
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                            <?php if($unreadMessagesCount > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-2"><?php echo $unreadMessagesCount; ?></span>
                            <?php endif; ?>
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
                        <a class="nav-link active" href="reviews.php">
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
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="page-title">My Reviews</h2>
                    <p class="text-muted">
                        View and respond to customer reviews of your services.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <?php if($reviewStats['total'] > 0): ?>
                    <div class="d-flex align-items-center justify-content-end">
                        <div class="rating-stars large me-2">
                            <?php
                            $rating = $reviewStats['average'];
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
                        <div class="h3 mb-0"><?php echo number_format($reviewStats['average'], 1); ?></div>
                    </div>
                    <div class="text-muted">Overall Rating</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Alert Message -->
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if($reviewStats['total'] > 0): ?>
            <!-- Review Stats -->
            <div class="row g-4 mb-4">
                <!-- Stats Cards -->
                <div class="col-md-6">
                    <div class="stats-container">
                        <div class="stat-card primary">
                            <div class="stat-icon text-primary">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($reviewStats['total']); ?></div>
                            <div class="stat-label">Total Reviews</div>
                        </div>
                        
                        <div class="stat-card warning">
                            <div class="stat-icon text-warning">
                                <i class="fas fa-star"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($reviewStats['average'], 1); ?></div>
                            <div class="stat-label">Average Rating</div>
                        </div>
                        
                        <div class="stat-card success">
                            <div class="stat-icon text-success">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($reviewStats['recent_count']); ?></div>
                            <div class="stat-label">Last 30 Days</div>
                        </div>
                    </div>
                </div>
                
                <!-- Rating Distribution -->
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Rating Distribution</h5>
                        </div>
                        <div class="card-body">
                            <div class="rating-bar-container">
                                <?php 
                                for ($i = 5; $i >= 1; $i--) {
                                    $count = $reviewStats['star_counts'][$i];
                                    $percentage = $reviewStats['total'] > 0 ? ($count / $reviewStats['total']) * 100 : 0;
                                    
                                    // Choose color based on rating
                                    $barColor = '';
                                    switch($i) {
                                        case 5: $barColor = 'bg-success'; break;
                                        case 4: $barColor = 'bg-info'; break;
                                        case 3: $barColor = 'bg-primary'; break;
                                        case 2: $barColor = 'bg-warning'; break;
                                        case 1: $barColor = 'bg-danger'; break;
                                    }
                                ?>
                                <div class="rating-bar">
                                    <div class="rating-label"><?php echo $i; ?> <i class="fas fa-star text-warning"></i></div>
                                    <div class="progress">
                                        <div class="progress-bar <?php echo $barColor; ?>" role="progressbar" style="width: <?php echo $percentage; ?>%" aria-valuenow="<?php echo $percentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <div class="rating-count"><?php echo $count; ?></div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Review Trend Chart -->
            <?php if (!empty($chartLabels)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">Review Trends</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="reviewTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Filter Toolbar -->
            <div class="filter-toolbar">
                <div class="card">
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="reviewSearch" placeholder="Search reviews...">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="ratingFilter">
                                    <option value="all">All Ratings</option>
                                    <option value="5">5 Stars</option>
                                    <option value="4">4 Stars</option>
                                    <option value="3">3 Stars</option>
                                    <option value="2">2 Stars</option>
                                    <option value="1">1 Star</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="responseFilter">
                                    <option value="all">All Reviews</option>
                                    <option value="responded">Responded</option>
                                    <option value="not-responded">Not Responded</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="sortFilter">
                                    <option value="newest">Newest First</option>
                                    <option value="oldest">Oldest First</option>
                                    <option value="highest">Highest Rating</option>
                                    <option value="lowest">Lowest Rating</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Reviews List -->
            <div id="reviewsContainer">
                <?php foreach ($reviews as $review): 
                    // Determine card class based on rating
                    $ratingClass = '';
                    if ($review['rating'] >= 4) {
                        $ratingClass = 'high-rating';
                    } elseif ($review['rating'] >= 3) {
                        $ratingClass = 'medium-rating';
                    } else {
                        $ratingClass = 'low-rating';
                    }
                    
                    // Check if this review has been responded to
                    $hasResponse = !empty($review['provider_response']);
                ?>
                <div class="review-item" 
                     data-rating="<?php echo $review['rating']; ?>"
                     data-responded="<?php echo $hasResponse ? 'yes' : 'no'; ?>">
                    <div class="card review-card <?php echo $ratingClass; ?> mb-4">
                        <div class="card-body">
                            <!-- Review Header -->
                            <div class="d-flex justify-content-between align-items-center review-info">
                                <div class="d-flex align-items-center">
                                    <div class="avatar me-3 bg-primary">
                                        <?php if (!empty($review['customer_image'])): ?>
                                            <img src="<?php echo htmlspecialchars($review['customer_image']); ?>" alt="Customer">
                                        <?php else: ?>
                                            <?php 
                                                $initials = mb_substr($review['customer_first_name'], 0, 1) . mb_substr($review['customer_last_name'], 0, 1);
                                                echo htmlspecialchars(strtoupper($initials));
                                            ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h5 class="mb-0"><?php echo htmlspecialchars($review['customer_first_name'] . ' ' . $review['customer_last_name']); ?></h5>
                                        <div class="text-muted small">
                                            <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="review-meta text-end">
                                    <div class="rating-stars mb-1">
                                        <?php
                                        $rating = $review['rating'];
                                        for ($i = 1; $i <= 5; $i++) {
                                            if ($i <= $rating) {
                                                echo '<i class="fas fa-star"></i>';
                                            } else {
                                                echo '<i class="far fa-star"></i>';
                                            }
                                        }
                                        ?>
                                    </div>
                                    <?php if(!empty($review['service_name'])): ?>
                                    <div class="service-tag <?php echo htmlspecialchars($review['service_category']); ?>">
                                        <?php 
                                        $icon = 'fas fa-cog';
                                        switch ($review['service_category']) {
                                            case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                            case 'laptop': $icon = 'fas fa-laptop'; break;
                                            case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                            case 'desktop': $icon = 'fas fa-desktop'; break;
                                            case 'gaming': $icon = 'fas fa-gamepad'; break;
                                            case 'tv': $icon = 'fas fa-tv'; break;
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?> me-1"></i>
                                        <?php echo htmlspecialchars($review['service_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Review Content -->
                            <div class="mt-3">
                                <p><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                            </div>
                            
                            <!-- Provider Response (if any) -->
                            <?php if(!empty($review['provider_response'])): ?>
                            <div class="review-response">
                                <h6 class="mb-2"><i class="fas fa-reply me-2"></i>Your Response</h6>
                                <p><?php echo nl2br(htmlspecialchars($review['provider_response'])); ?></p>
                                <div class="response-date">
                                    <i class="far fa-clock me-1"></i>
                                    <?php echo date('F j, Y', strtotime($review['response_date'])); ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <!-- Response Form -->
                            <div class="review-response-form">
                                <form method="post" action="">
                                    <input type="hidden" name="action" value="respond">
                                    <input type="hidden" name="review_id" value="<?php echo $review['id']; ?>">
                                    <div class="mb-3">
                                        <label for="response-<?php echo $review['id']; ?>" class="form-label">
                                            <i class="fas fa-reply me-2"></i>Add Your Response
                                        </label>
                                        <textarea class="form-control" id="response-<?php echo $review['id']; ?>" name="response" rows="3" placeholder="Write your response to this review..."></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-2"></i>Send Response
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- No Results Message (initially hidden) -->
            <div id="noResults" class="card text-center p-4 d-none">
                <div class="card-body">
                    <i class="fas fa-search fa-3x mb-3 text-muted"></i>
                    <h5>No matching reviews found</h5>
                    <p class="text-muted">Try changing your search criteria or check back later for new reviews.</p>
                </div>
            </div>
            <?php else: ?>
            <!-- No Reviews Yet -->
            <div class="card">
                <div class="card-body empty-state">
                    <div class="empty-state-icon">
                        <i class="far fa-star"></i>
                    </div>
                    <h4>No Reviews Yet</h4>
                    <p class="text-muted mb-4">You haven't received any customer reviews yet. Reviews will appear here when customers rate your services.</p>
                    <a href="services.php" class="btn btn-primary">
                        <i class="fas fa-cogs me-2"></i>Manage Your Services
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            
            // Review Trend Chart
            const reviewTrendChart = document.getElementById('reviewTrendChart');
            if (reviewTrendChart) {
                <?php if (!empty($chartDataJson)): ?>
                const chartData = <?php echo $chartDataJson; ?>;
                
                new Chart(reviewTrendChart, {
                    type: 'bar',
                    data: {
                        labels: chartData.labels,
                        datasets: [
                            {
                                label: 'Number of Reviews',
                                data: chartData.counts,
                                backgroundColor: 'rgba(111, 66, 193, 0.6)',
                                borderColor: 'rgba(111, 66, 193, 1)',
                                borderWidth: 1,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Average Rating',
                                data: chartData.ratings,
                                type: 'line',
                                backgroundColor: 'rgba(255, 193, 7, 0.2)',
                                borderColor: 'rgba(255, 193, 7, 1)',
                                borderWidth: 2,
                                pointBackgroundColor: 'rgba(255, 193, 7, 1)',
                                pointBorderColor: '#fff',
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                fill: false,
                                tension: 0.4,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        scales: {
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                title: {
                                    display: true,
                                    text: 'Number of Reviews'
                                },
                                min: 0,
                                suggestedMax: Math.max(...chartData.counts) + 1
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                title: {
                                    display: true,
                                    text: 'Average Rating'
                                },
                                min: 0,
                                max: 5,
                                grid: {
                                    drawOnChartArea: false
                                }
                            }
                        }
                    }
                });
                <?php endif; ?>
            }
            
            // Filter functionality
            const reviewSearch = document.getElementById('reviewSearch');
            const ratingFilter = document.getElementById('ratingFilter');
            const responseFilter = document.getElementById('responseFilter');
            const sortFilter = document.getElementById('sortFilter');
            const reviewsContainer = document.getElementById('reviewsContainer');
            const reviewItems = document.querySelectorAll('.review-item');
            const noResults = document.getElementById('noResults');
            
            function filterReviews() {
                if (!reviewSearch || !ratingFilter || !responseFilter || !reviewItems || !noResults) {
                    return;
                }
                
                const searchText = reviewSearch.value.toLowerCase();
                const ratingValue = ratingFilter.value;
                const responseValue = responseFilter.value;
                const sortValue = sortFilter.value;
                
                let visibleCount = 0;
                let visibleItems = [];
                
                // First filter the items
                reviewItems.forEach(item => {
                    const reviewContent = item.querySelector('p').textContent.toLowerCase();
                    const customerName = item.querySelector('h5').textContent.toLowerCase();
                    const rating = item.getAttribute('data-rating');
                    const responded = item.getAttribute('data-responded');
                    
                    const matchesSearch = searchText === '' || 
                                         reviewContent.includes(searchText) || 
                                         customerName.includes(searchText);
                    
                    const matchesRating = ratingValue === 'all' || rating === ratingValue;
                    
                    const matchesResponse = responseValue === 'all' || 
                                          (responseValue === 'responded' && responded === 'yes') ||
                                          (responseValue === 'not-responded' && responded === 'no');
                    
                    const isVisible = matchesSearch && matchesRating && matchesResponse;
                    
                    if (isVisible) {
                        visibleCount++;
                        visibleItems.push(item);
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                // Then sort the visible items
                if (visibleItems.length > 0 && reviewsContainer) {
                    visibleItems.sort((a, b) => {
                        const aDate = new Date(a.querySelector('.text-muted.small').textContent);
                        const bDate = new Date(b.querySelector('.text-muted.small').textContent);
                        const aRating = parseInt(a.getAttribute('data-rating'));
                        const bRating = parseInt(b.getAttribute('data-rating'));
                        
                        switch(sortValue) {
                            case 'newest':
                                return bDate - aDate;
                            case 'oldest':
                                return aDate - bDate;
                            case 'highest':
                                return bRating === aRating ? bDate - aDate : bRating - aRating;
                            case 'lowest':
                                return aRating === bRating ? bDate - aDate : aRating - bRating;
                            default:
                                return 0;
                        }
                    });
                    
                    // Reappend in sorted order
                    visibleItems.forEach(item => {
                        reviewsContainer.appendChild(item);
                    });
                }
                
                // Show/hide no results message
                if (noResults) {
                    noResults.classList.toggle('d-none', visibleCount > 0);
                }
            }
            
            // Attach event listeners for filtering
            if (reviewSearch) {
                reviewSearch.addEventListener('input', filterReviews);
            }
            
            if (ratingFilter) {
                ratingFilter.addEventListener('change', filterReviews);
            }
            
            if (responseFilter) {
                responseFilter.addEventListener('change', filterReviews);
            }
            
            if (sortFilter) {
                sortFilter.addEventListener('change', filterReviews);
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert.alert-success, .alert.alert-danger');
                alerts.forEach(function(alert) {
                    const bsAlert = bootstrap.Alert.getInstance(alert);
                    if (bsAlert) {
                        bsAlert.close();
                    } else {
                        const newBsAlert = new bootstrap.Alert(alert);
                        newBsAlert.close();
                    }
                });
            }, 5000);
        });
    </script>
</body>
</html>