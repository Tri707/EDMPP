<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Include database connection
require_once '../conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Validate provider ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: find-provider.php");
    exit();
}

$providerId = (int)$_GET['id'];

// Get user information if logged in
$userData = null;
$profileImage = '../images/default.png';

if ($loggedIn) {
    try {
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
    } catch (PDOException $e) {
        error_log("Database error fetching user data: " . $e->getMessage());
        $profileImage = '../images/default.png';
    }
}

// Get provider data
$provider = null;
$providerUser = null;
$services = [];
$reviews = [];

try {
    // Get provider information
    $providerQuery = "SELECT p.*, AVG(r.rating) as average_rating, COUNT(r.id) as review_count 
                     FROM providers p 
                     LEFT JOIN reviews r ON p.id = r.provider_id
                     WHERE p.id = ?
                     GROUP BY p.id";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider) {
        // Provider not found
        header("Location: find-provider.php");
        exit();
    }
    
    // Get provider user information
    $providerUserQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($providerUserQuery);
    $stmt->execute([$provider['user_id']]);
    $providerUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerUser) {
        // Provider user not found
        header("Location: find-provider.php");
        exit();
    }
    
    // Set provider profile image
    $providerProfileImage = '../images/default.png';
    if (!empty($providerUser['profile_image'])) {
        $providerProfileImage = $providerUser['profile_image'];
        if (!preg_match('/^https?:\/\//', $providerProfileImage)) {
            $providerProfileImage = '../' . ltrim(str_replace('../', '', $providerProfileImage), '/');
            
            if (!file_exists($providerProfileImage)) {
                $providerProfileImage = '../images/default.png';
            }
        }
    }
    
    // Get provider services
    $servicesQuery = "SELECT * FROM services WHERE provider_id = ? AND is_active = 1 ORDER BY category, price";
    $stmt = $pdo->prepare($servicesQuery);
    $stmt->execute([$providerId]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group services by category
    $servicesByCategory = [];
    foreach ($services as $service) {
        $category = $service['category'];
        if (!isset($servicesByCategory[$category])) {
            $servicesByCategory[$category] = [];
        }
        $servicesByCategory[$category][] = $service;
    }
    
    // Get provider reviews
    $reviewsQuery = "
        SELECT r.*, u.first_name, u.last_name, u.profile_image 
        FROM reviews r 
        JOIN users u ON r.customer_id = u.id 
        WHERE r.provider_id = ? AND r.is_published = 1
        ORDER BY r.created_at DESC
    ";
    $stmt = $pdo->prepare($reviewsQuery);
    $stmt->execute([$providerId]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate rating distribution
    $ratingDistribution = [
        5 => 0,
        4 => 0,
        3 => 0,
        2 => 0,
        1 => 0
    ];
    
    foreach ($reviews as $review) {
        $rating = floor($review['rating']);
        if (isset($ratingDistribution[$rating])) {
            $ratingDistribution[$rating]++;
        }
    }
    
    // Get total review count
    $totalReviews = count($reviews);
    
    // Calculate average rating
    $averageRating = $provider['average_rating'] ?? 0;
    
    // Calculate completion rate (you would need a jobs/bookings table to do this properly)
    // For now, let's simulate a high completion rate
    $completionRate = 95;
    
    // Check if the user has favorited this provider
    $isFavorited = false;
    if ($loggedIn && $userRole === 'customer') {
        try {
            $favoriteQuery = "SELECT id FROM favorites WHERE customer_id = ? AND provider_id = ?";
            $stmt = $pdo->prepare($favoriteQuery);
            $stmt->execute([$userId, $providerId]);
            $isFavorited = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Database error checking favorites: " . $e->getMessage());
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    header("Location: find-provider.php");
    exit();
}

// Process favorite/unfavorite
if ($loggedIn && $userRole === 'customer' && isset($_POST['action'])) {
    if ($_POST['action'] === 'favorite') {
        try {
            // Check if already favorited to avoid duplicates
            $checkQuery = "SELECT id FROM favorites WHERE customer_id = ? AND provider_id = ?";
            $stmt = $pdo->prepare($checkQuery);
            $stmt->execute([$userId, $providerId]);
            
            if ($stmt->rowCount() === 0) {
                $favoriteQuery = "INSERT INTO favorites (customer_id, provider_id) VALUES (?, ?)";
                $stmt = $pdo->prepare($favoriteQuery);
                $stmt->execute([$userId, $providerId]);
                $isFavorited = true;
            }
        } catch (PDOException $e) {
            error_log("Database error adding favorite: " . $e->getMessage());
        }
    } elseif ($_POST['action'] === 'unfavorite') {
        try {
            $unfavoriteQuery = "DELETE FROM favorites WHERE customer_id = ? AND provider_id = ?";
            $stmt = $pdo->prepare($unfavoriteQuery);
            $stmt->execute([$userId, $providerId]);
            $isFavorited = false;
        } catch (PDOException $e) {
            error_log("Database error removing favorite: " . $e->getMessage());
        }
    }
}

// Get unread notification count for the header
$unreadCount = 0;
if ($loggedIn && $userRole === 'customer') {
    try {
        $unreadQuery = "
            SELECT COUNT(*) FROM notifications 
            WHERE customer_id = ? AND is_read = 0 AND status = 'pending'
        ";
        $stmt = $pdo->prepare($unreadQuery);
        $stmt->execute([$userId]);
        $unreadCount = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Database error counting unread notifications: " . $e->getMessage());
    }
}

// Helper functions
function formatExperience($experience) {
    switch($experience) {
        case '0-1':
            return 'Less than 1 year';
        case '1-3':
            return '1-3 years';
        case '3-5':
            return '3-5 years';
        case '5-10':
            return '5-10 years';
        case '10+':
            return 'More than 10 years';
        default:
            return 'Not specified';
    }
}

function formatDate($dateString) {
    return date('M j, Y', strtotime($dateString));
}

function formatStars($rating) {
    $html = '';
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $fullStars) {
            $html .= '<i class="fas fa-star"></i>';
        } elseif ($halfStar && $i === $fullStars + 1) {
            $html .= '<i class="fas fa-star-half-alt"></i>';
        } else {
            $html .= '<i class="far fa-star"></i>';
        }
    }
    
    return $html;
}

// Default active tab
$activeTab = isset($_GET['tab']) ? clean_input($_GET['tab']) : 'about';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Provider Profile - FixItNow</title>
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
        
        /* Card Styles */
        .card {
            background-color: var(--card-bg);
            border: none;
            border-radius: 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            transition: box-shadow 0.3s ease, transform 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }
        
        .card-title {
            margin-bottom: 0;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        /* Profile Styles */
        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 2rem;
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
        }
        
        .profile-image-container {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 5px solid var(--primary-color);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .profile-verified-badge {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            border: 3px solid var(--card-bg);
        }
        
        .profile-name {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .profile-title {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .profile-rating {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .rating-stars {
            color: #ffc107;
            margin-right: 0.5rem;
        }
        
        .profile-stats {
            display: flex;
            justify-content: center;
            gap: 2rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .profile-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        /* Profile Navigation Tabs */
        .profile-nav {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .profile-nav .nav-item {
            flex: 1;
        }
        
        .profile-nav .nav-link {
            text-align: center;
            padding: 1rem;
            color: var(--text-color);
            border-radius: 0;
            border: none;
            transition: all 0.2s ease;
        }
        
        .profile-nav .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .profile-nav .nav-link:hover:not(.active) {
            background-color: var(--primary-light);
        }
        
        /* Service Card */
        .service-card {
            display: flex;
            background-color: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            margin-bottom: 1.5rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1.5rem var(--shadow-color);
        }
        
        .service-card-body {
            flex: 1;
            padding: 1.5rem;
        }
        
        .service-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .service-category {
            display: inline-block;
            background-color: var(--primary-light);
            color: var(--primary-color);
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .service-price {
            color: var(--primary-color);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .service-duration {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        
        .service-duration i {
            margin-right: 0.5rem;
        }
        
        /* Review Styles */
        .review-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .reviewer-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
        }
        
        .reviewer-info {
            flex: 1;
        }
        
        .reviewer-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .review-date {
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .review-rating {
            color: #ffc107;
            margin-bottom: 0.5rem;
        }
        
        .review-text {
            line-height: 1.6;
        }
        
        /* Rating Distribution */
        .rating-distribution {
            margin-bottom: 2rem;
        }
        
        .rating-bar {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .rating-label {
            display: flex;
            align-items: center;
            width: 80px;
        }
        
        .rating-label i {
            color: #ffc107;
            margin-right: 0.25rem;
        }
        
        .rating-progress {
            flex: 1;
            height: 8px;
            background-color: var(--border-color);
            border-radius: 4px;
            margin: 0 1rem;
            overflow: hidden;
        }
        
        .rating-progress-fill {
            height: 100%;
            background-color: #ffc107;
            border-radius: 4px;
        }
        
        .rating-count {
            width: 40px;
            text-align: right;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        /* Favorite Button */
        .favorite-btn {
            background-color: transparent;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            line-height: 1;
            padding: 0.5rem;
        }
        
        .favorite-btn:hover, .favorite-btn.active {
            color: #ff3b5c;
            transform: scale(1.1);
        }
        
        /* Action button */
        .action-button {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 0.5rem;
            padding: 0.625rem 1.25rem;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            text-decoration: none;
            cursor: pointer;
        }
        
        .action-button:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            color: white;
        }
        
        .action-button.secondary {
            background-color: var(--sidebar-active);
            color: var(--text-color);
        }
        
        .action-button.secondary:hover {
            background-color: var(--border-color);
            color: var(--text-color);
        }
        
        .action-button.outline {
            background-color: transparent;
            border: 2px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .action-button.outline:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Specialty Tags */
        .specialty-tag {
            display: inline-block;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 2rem;
            padding: 0.3rem 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Information Items */
        .info-item {
            display: flex;
            margin-bottom: 1rem;
        }
        
        .info-icon {
            color: var(--primary-color);
            margin-right: 1rem;
            width: 20px;
            text-align: center;
        }
        
        .info-content {
            flex: 1;
        }
        
        .info-label {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            color: var(--text-muted);
        }
        
        /* Contact Form */
        .contact-form .form-label {
            font-weight: 500;
        }
        
        .contact-form .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            padding: 0.75rem;
            border-radius: 0.5rem;
        }
        
        .contact-form .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
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
            
            .profile-stats {
                gap: 1rem;
            }
            
            .profile-actions {
                flex-direction: column;
                width: 100%;
            }
            
            .profile-actions .action-button {
                width: 100%;
                margin-bottom: 0.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .profile-header {
                padding: 1.5rem;
            }
            
            .profile-image {
                width: 120px;
                height: 120px;
            }
            
            .profile-nav .nav-link {
                padding: 0.75rem 0.5rem;
                font-size: 0.9rem;
            }
            
            .service-card {
                flex-direction: column;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .profile-stats {
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .stat-item {
                width: calc(50% - 0.5rem);
            }
            
            .profile-nav .nav-link {
                font-size: 0.8rem;
                padding: 0.6rem 0.3rem;
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
                    <?php if ($loggedIn && $userRole === 'customer'): ?>
                    <!-- Notifications -->
                    <div class="dropdown me-3">
                        <button class="btn btn-dark position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if($unreadCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $unreadCount; ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                            <li><h6 class="dropdown-header">Notifications</h6></li>
                            <?php if($unreadCount === 0): ?>
                                <li><div class="dropdown-item text-muted">No new notifications</div></li>
                            <?php else: ?>
                                <?php 
                                // Get the most recent unread notifications
                                $recentQuery = "
                                    SELECT DISTINCT n.message, n.created_at, n.type, n.reference_id
                                    FROM notifications n
                                    WHERE n.customer_id = ? AND n.is_read = 0 AND n.status = 'pending'
                                    ORDER BY n.created_at DESC
                                    LIMIT 5
                                ";
                                $stmt = $pdo->prepare($recentQuery);
                                $stmt->execute([$userId]);
                                $recentNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach($recentNotifications as $notification): 
                                ?>
                                <li>
                                    <a class="dropdown-item" href="notifications.php">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($notification['message'] ?? 'New notification'); ?></h6>
                                            <small class="text-muted"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['type'] ?? ''); ?></small>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="notifications.php">View all notifications</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Theme Toggle -->
                    <button class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon theme-icon-dark d-none"></i>
                        <i class="fas fa-sun theme-icon-light"></i>
                    </button>
                    
                    <?php if ($loggedIn): ?>
                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="btn btn-dark d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline">
                                <?php 
                                if ($userData && !empty($userData['first_name'])) {
                                    echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
                                } else {
                                    echo htmlspecialchars($userData['username'] ?? 'User');
                                }
                                ?>
                            </span>
                            <i class="fas fa-chevron-down ms-2 small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <?php if ($userRole === 'customer'): ?>
                            <li><a class="dropdown-item" href="my-bookings.php"><i class="fas fa-calendar-check me-2"></i> My Bookings</a></li>
                            <li><a class="dropdown-item" href="quote-requests.php"><i class="fas fa-file-invoice-dollar me-2"></i> Quote Requests</a></li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <!-- Login/Register Buttons -->
                    <a href="../login.php?action=login" class="btn btn-outline-light me-2">Login</a>
                    <a href="../login.php?action=register" class="btn btn-primary">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>
    
    <?php if ($loggedIn && $userRole === 'customer'): ?>
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
                    <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </li>
                <li>
                    <a href="find-provider.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'find-provider.php' || basename($_SERVER['PHP_SELF']) == 'provider-profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-search"></i> Find a Provider
                    </a>
                </li>
                <li>
                    <a href="my-bookings.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-bookings.php' || basename($_SERVER['PHP_SELF']) == 'booking-details.php' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-check"></i> My Bookings
                    </a>
                </li>
                <li>
                    <a href="quote-requests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'quote-requests.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i> Quote Requests
                    </a>
                </li>
                <li>
                    <a href="my-devices.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-devices.php' ? 'active' : ''; ?>">
                        <i class="fas fa-laptop"></i> My Devices
                    </a>
                </li>
                <li>
                    <a href="repair-history.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'repair-history.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> Repair History
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if($unreadCount > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $unreadCount; ?></span>
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
    <?php else: ?>
    <!-- Regular Content Without Sidebar -->
    <div class="container py-4">
    <?php endif; ?>
            
            <!-- Page Header - Back Button -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="find-provider.php" class="action-button secondary">
                    <i class="fas fa-arrow-left"></i> Back to Providers
                </a>
                
                <?php if ($loggedIn && $userRole === 'customer'): ?>
                <!-- Favorite Toggle Form -->
                <form method="post" id="favoriteForm">
                    <input type="hidden" name="action" value="<?php echo $isFavorited ? 'unfavorite' : 'favorite'; ?>">
                    <button type="submit" class="favorite-btn <?php echo $isFavorited ? 'active' : ''; ?>" aria-label="Toggle favorite">
                        <?php if ($isFavorited): ?>
                        <i class="fas fa-heart"></i>
                        <?php else: ?>
                        <i class="far fa-heart"></i>
                        <?php endif; ?>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            
            <!-- Provider Profile Header -->
            <div class="profile-header">
                <div class="profile-image-container">
                    <img src="<?php echo htmlspecialchars($providerProfileImage); ?>" alt="<?php echo htmlspecialchars($providerUser['first_name']); ?>" class="profile-image">
                    <?php if ($provider['is_verified']): ?>
                    <div class="profile-verified-badge" title="Verified Provider">
                        <i class="fas fa-check"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <h1 class="profile-name"><?php echo htmlspecialchars($providerUser['first_name'] . ' ' . $providerUser['last_name']); ?></h1>
                
                <p class="profile-title">
                    <?php 
                    if (!empty($provider['specialties'])) {
                        $specialties = explode(',', $provider['specialties']);
                        echo htmlspecialchars(ucfirst(implode(' & ', array_map('ucfirst', $specialties)))) . ' Specialist';
                    } else {
                        echo 'Repair Specialist';
                    }
                    ?>
                </p>
                
                <div class="profile-rating">
                    <div class="rating-stars">
                        <?php echo formatStars($averageRating); ?>
                    </div>
                    <span><?php echo number_format($averageRating, 1); ?> (<?php echo $totalReviews; ?> reviews)</span>
                </div>
                
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?php echo formatExperience($provider['experience']); ?></div>
                        <div class="stat-label">Experience</div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-value"><?php echo count($services); ?></div>
                        <div class="stat-label">Services</div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $completionRate; ?>%</div>
                        <div class="stat-label">Completion Rate</div>
                    </div>
                    
                    <div class="stat-item">
                        <div class="stat-value"><?php echo $provider['hourly_rate'] > 0 ? '$' . number_format($provider['hourly_rate'], 2) . '/hr' : 'Varies'; ?></div>
                        <div class="stat-label">Rate</div>
                    </div>
                </div>
                
                <div class="profile-actions">
                    <?php if ($loggedIn && $userRole === 'customer'): ?>
                    <a href="request-quote.php?provider_id=<?php echo $providerId; ?>" class="action-button">
                        <i class="fas fa-file-invoice-dollar"></i> Request a Quote
                    </a>
                    
                    <a href="book-service.php?provider_id=<?php echo $providerId; ?>" class="action-button">
                        <i class="fas fa-calendar-plus"></i> Book a Service
                    </a>
                    
                    <a href="message-provider.php?provider_id=<?php echo $providerId; ?>" class="action-button outline">
                        <i class="fas fa-comment"></i> Message
                    </a>
                    <?php else: ?>
                    <a href="../login.php?action=login" class="action-button">
                        <i class="fas fa-sign-in-alt"></i> Login to Book Services
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Profile Navigation Tabs -->
            <ul class="nav nav-pills profile-nav mb-4">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'about' ? 'active' : ''; ?>" href="provider-profile.php?id=<?php echo $providerId; ?>&tab=about">
                        <i class="fas fa-user me-2"></i> About
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'services' ? 'active' : ''; ?>" href="provider-profile.php?id=<?php echo $providerId; ?>&tab=services">
                        <i class="fas fa-tools me-2"></i> Services
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'reviews' ? 'active' : ''; ?>" href="provider-profile.php?id=<?php echo $providerId; ?>&tab=reviews">
                        <i class="fas fa-star me-2"></i> Reviews
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'contact' ? 'active' : ''; ?>" href="provider-profile.php?id=<?php echo $providerId; ?>&tab=contact">
                        <i class="fas fa-envelope me-2"></i> Contact
                    </a>
                </li>
            </ul>
            
            <!-- Tab Content -->
            <div class="tab-content">
                <!-- About Tab -->
                <?php if ($activeTab === 'about'): ?>
                <div class="tab-pane fade show active">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">About <?php echo htmlspecialchars($providerUser['first_name']); ?></h5>
                                </div>
                                <div class="card-body">
                                    <?php if (!empty($provider['bio'])): ?>
                                        <p><?php echo nl2br(htmlspecialchars($provider['bio'])); ?></p>
                                    <?php else: ?>
                                        <p>No bio provided yet.</p>
                                    <?php endif; ?>
                                    
                                    <h6 class="mt-4 mb-3">Specialties</h6>
                                    <div>
                                        <?php 
                                        if (!empty($provider['specialties'])) {
                                            $specialties = explode(',', $provider['specialties']);
                                            foreach ($specialties as $specialty) {
                                                echo '<span class="specialty-tag">' . ucfirst(htmlspecialchars($specialty)) . '</span>';
                                            }
                                        } else {
                                            echo '<p>No specialties listed.</p>';
                                        }
                                        ?>
                                    </div>
                                    
                                    <?php if (!empty($provider['education'])): ?>
                                    <h6 class="mt-4 mb-3">Education & Certifications</h6>
                                    <p><?php echo nl2br(htmlspecialchars($provider['education'])); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Location</div>
                                            <div class="info-value"><?php echo htmlspecialchars($provider['location'] ?? 'Not specified'); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-briefcase"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Experience</div>
                                            <div class="info-value"><?php echo formatExperience($provider['experience']); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-dollar-sign"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Hourly Rate</div>
                                            <div class="info-value">
                                                <?php echo $provider['hourly_rate'] > 0 ? '$' . number_format($provider['hourly_rate'], 2) . '/hour' : 'Varies by service'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!empty($provider['response_time'])): ?>
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-clock"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Response Time</div>
                                            <div class="info-value"><?php echo htmlspecialchars($provider['response_time']); ?></div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-user-check"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Member Since</div>
                                            <div class="info-value"><?php echo date('F Y', strtotime($provider['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Services Tab -->
                <?php if ($activeTab === 'services'): ?>
                <div class="tab-pane fade show active">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title">Services Offered</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($services)): ?>
                            <div class="alert alert-info">
                                This provider hasn't added any services yet.
                            </div>
                            <?php else: ?>
                                <?php foreach ($servicesByCategory as $category => $categoryServices): ?>
                                <div class="mb-4">
                                    <h5 class="mb-3"><?php echo ucfirst(htmlspecialchars($category)); ?> Services</h5>
                                    
                                    <?php foreach ($categoryServices as $service): ?>
                                    <div class="service-card">
                                        <div class="service-card-body">
                                            <span class="service-category"><?php echo ucfirst(htmlspecialchars($service['category'])); ?></span>
                                            <h4 class="service-name"><?php echo htmlspecialchars($service['name']); ?></h4>
                                            <p><?php echo htmlspecialchars($service['description']); ?></p>
                                            
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div class="service-price">$<?php echo number_format($service['price'], 2); ?></div>
                                                
                                                <div class="service-duration">
                                                    <i class="far fa-clock"></i>
                                                    <?php 
                                                    $duration = $service['duration'] ?? 60;
                                                    if ($duration < 60) {
                                                        echo $duration . ' minutes';
                                                    } else {
                                                        $hours = floor($duration / 60);
                                                        $minutes = $duration % 60;
                                                        echo $hours . ' hour' . ($hours > 1 ? 's' : '');
                                                        if ($minutes > 0) {
                                                            echo ' ' . $minutes . ' min';
                                                        }
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                            
                                            <?php if ($loggedIn && $userRole === 'customer'): ?>
                                            <div class="mt-3">
                                                <a href="book-service.php?provider_id=<?php echo $providerId; ?>&service_id=<?php echo $service['id']; ?>" class="action-button">
                                                    <i class="fas fa-calendar-plus"></i> Book Now
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Reviews Tab -->
                <?php if ($activeTab === 'reviews'): ?>
                <div class="tab-pane fade show active">
                    <div class="row">
                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Rating Summary</h5>
                                </div>
                                <div class="card-body text-center">
                                    <div class="display-4 fw-bold mb-2"><?php echo number_format($averageRating, 1); ?></div>
                                    <div class="rating-stars mb-3" style="font-size: 1.5rem;">
                                        <?php echo formatStars($averageRating); ?>
                                    </div>
                                    <p class="text-muted"><?php echo $totalReviews; ?> reviews in total</p>
                                    
                                    <div class="rating-distribution mt-4">
                                        <?php for ($i = 5; $i >= 1; $i--): ?>
                                        <div class="rating-bar">
                                            <div class="rating-label">
                                                <i class="fas fa-star"></i> <?php echo $i; ?>
                                            </div>
                                            
                                            <div class="rating-progress">
                                                <?php
                                                $percentage = $totalReviews > 0 ? ($ratingDistribution[$i] / $totalReviews) * 100 : 0;
                                                ?>
                                                <div class="rating-progress-fill" style="width: <?php echo $percentage; ?>%"></div>
                                            </div>
                                            
                                            <div class="rating-count"><?php echo $ratingDistribution[$i]; ?></div>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Customer Reviews</h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($reviews)): ?>
                                    <div class="alert alert-info">
                                        This provider doesn't have any reviews yet.
                                    </div>
                                    <?php else: ?>
                                        <?php foreach ($reviews as $review): ?>
                                        <div class="review-card">
                                            <div class="review-header">
                                                <?php
                                                $reviewerImage = $review['profile_image'];
                                                if (empty($reviewerImage)) {
                                                    $reviewerImage = '../images/default.png';
                                                } elseif (!preg_match('/^https?:\/\//', $reviewerImage)) {
                                                    $reviewerImage = '../' . ltrim(str_replace('../', '', $reviewerImage), '/');
                                                    
                                                    if (!file_exists($reviewerImage)) {
                                                        $reviewerImage = '../images/default.png';
                                                    }
                                                }
                                                ?>
                                                <img src="<?php echo htmlspecialchars($reviewerImage); ?>" alt="Reviewer" class="reviewer-avatar">
                                                
                                                <div class="reviewer-info">
                                                    <div class="reviewer-name"><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></div>
                                                    <div class="review-date"><?php echo formatDate($review['created_at']); ?></div>
                                                </div>
                                            </div>
                                            
                                            <div class="review-rating">
                                                <?php echo formatStars($review['rating']); ?>
                                                <span class="ms-2"><?php echo number_format($review['rating'], 1); ?></span>
                                            </div>
                                            
                                            <?php if (!empty($review['comment'])): ?>
                                            <div class="review-text">
                                                <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Contact Tab -->
                <?php if ($activeTab === 'contact'): ?>
                <div class="tab-pane fade show active">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Contact Information</h5>
                                </div>
                                <div class="card-body">
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Location</div>
                                            <div class="info-value"><?php echo htmlspecialchars($provider['location'] ?? 'Not specified'); ?></div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($loggedIn && $userRole === 'customer'): ?>
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-envelope"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Email</div>
                                            <div class="info-value"><?php echo htmlspecialchars($providerUser['email']); ?></div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-phone"></i>
                                        </div>
                                        <div class="info-content">
                                            <div class="info-label">Phone</div>
                                            <div class="info-value"><?php echo htmlspecialchars($providerUser['phone']); ?></div>
                                        </div>
                                    </div>
                                    <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i> Login to view contact information or send a message.
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if ($loggedIn && $userRole === 'customer'): ?>
                            <div class="card mt-4">
                                <div class="card-header">
                                    <h5 class="card-title">Quick Actions</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-3">
                                        <a href="book-service.php?provider_id=<?php echo $providerId; ?>" class="action-button">
                                            <i class="fas fa-calendar-plus"></i> Book a Service
                                        </a>
                                        
                                        <a href="request-quote.php?provider_id=<?php echo $providerId; ?>" class="action-button">
                                            <i class="fas fa-file-invoice-dollar"></i> Request a Quote
                                        </a>
                                        
                                        <a href="message-provider.php?provider_id=<?php echo $providerId; ?>" class="action-button outline">
                                            <i class="fas fa-comment"></i> Send a Message
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-lg-6">
                            <?php if ($loggedIn && $userRole === 'customer'): ?>
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Send a Message</h5>
                                </div>
                                <div class="card-body">
                                    <form action="message-provider.php" method="post" class="contact-form">
                                        <input type="hidden" name="provider_id" value="<?php echo $providerId; ?>">
                                        
                                        <div class="mb-3">
                                            <label for="subject" class="form-label">Subject</label>
                                            <input type="text" class="form-control" id="subject" name="subject" required placeholder="e.g., Question about Phone Repair">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="message" class="form-label">Message</label>
                                            <textarea class="form-control" id="message" name="message" rows="5" required placeholder="Type your message here..."></textarea>
                                        </div>
                                        
                                        <div class="d-grid">
                                            <button type="submit" class="action-button">
                                                <i class="fas fa-paper-plane"></i> Send Message
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title">Contact Provider</h5>
                                </div>
                                <div class="card-body">
                                    <div class="text-center py-4">
                                        <i class="fas fa-envelope-open-text fa-4x mb-3 text-muted"></i>
                                        <h5>Want to contact this provider?</h5>
                                        <p class="text-muted mb-4">Create an account or log in to send messages, book services, or request quotes.</p>
                                        
                                        <div class="d-grid gap-2">
                                            <a href="../login.php?action=login" class="action-button">
                                                <i class="fas fa-sign-in-alt"></i> Login
                                            </a>
                                            <a href="../login.php?action=register" class="action-button outline">
                                                <i class="fas fa-user-plus"></i> Register
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
        const loadingSpinner = document.getElementById('loadingSpinner');
        
        // Hide spinner when page is loaded
        document.addEventListener('DOMContentLoaded', function() {
            loadingSpinner.classList.remove('show');
        });
        
        // Show spinner when navigating away
        document.querySelectorAll('a:not([download])').forEach(link => {
            link.addEventListener('click', function(e) {
                // Don't show for same-page links, modal triggers, or links with external targets
                if (
                    this.getAttribute('href').startsWith('#') || 
                    this.getAttribute('target') === '_blank' ||
                    this.hasAttribute('data-bs-toggle')
                ) {
                    return;
                }
                
                loadingSpinner.classList.add('show');
            });
        });
        
        // Submit buttons should also show spinner
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                loadingSpinner.classList.add('show');
            });
        });
    </script>
</body>
</html>