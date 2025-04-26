<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isCustomer = $loggedIn && $userRole === 'customer';

// Redirect to login page if not logged in
if (!$loggedIn) {
    // Save the current page as the redirect destination after login
    $_SESSION['redirect_after_login'] = 'services.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
    header('Location: login.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$services = [];
$categories = ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'];
$locations = [];
$totalServices = 0;
$errorMessage = '';
$successMessage = '';

// Get search params
$searchLocation = isset($_GET['location']) ? $_GET['location'] : '';
$searchCategory = isset($_GET['category']) ? $_GET['category'] : '';
$searchKeyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';
$minPrice = isset($_GET['min_price']) && is_numeric($_GET['min_price']) ? (float)$_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) && is_numeric($_GET['max_price']) ? (float)$_GET['max_price'] : null;

// Default sorting
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'popularity';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 9; // Show 9 services per page
$offset = ($page - 1) * $perPage;

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Function to safely format prices with the currency image
function formatPrice($price, $currencyImgPath = 'sar/sar.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    // Use the image path for currency display
    $currencyImg = '<img src="' . h($currencyImgPath) . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}

// Get all locations for the filter
try {
    $locationsQuery = "SELECT DISTINCT location FROM providers WHERE location IS NOT NULL AND location != '' ORDER BY location";
    $locationsResult = $conn->query($locationsQuery);
    
    if ($locationsResult && $locationsResult->num_rows > 0) {
        while ($row = $locationsResult->fetch_assoc()) {
            $locations[] = $row['location'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching locations: " . $e->getMessage());
}

// Build query to get services with filters
try {
    // Start building the query
    $baseQuery = "
        FROM services s
        JOIN providers p ON s.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN (
            SELECT service_id, COUNT(*) as booking_count 
            FROM bookings 
            WHERE status IN ('completed', 'confirmed')
            GROUP BY service_id
        ) b ON s.id = b.service_id
        WHERE s.is_active = 1 AND s.deleted_by_provider = 0 AND u.status = 'active'
    ";
    
    // Add filters if provided
    $whereConditions = [];
    $params = [];
    $types = '';
    
    if (!empty($searchLocation)) {
        $whereConditions[] = "p.location = ?";
        $params[] = $searchLocation;
        $types .= 's';
    }
    
    if (!empty($searchCategory)) {
        $whereConditions[] = "s.category = ?";
        $params[] = $searchCategory;
        $types .= 's';
    }
    
    if (!empty($searchKeyword)) {
        $whereConditions[] = "(s.name LIKE ? OR s.description LIKE ?)";
        $params[] = '%' . $searchKeyword . '%';
        $params[] = '%' . $searchKeyword . '%';
        $types .= 'ss';
    }
    
    if ($minPrice !== null) {
        $whereConditions[] = "s.price >= ?";
        $params[] = $minPrice;
        $types .= 'd';
    }
    
    if ($maxPrice !== null) {
        $whereConditions[] = "s.price <= ?";
        $params[] = $maxPrice;
        $types .= 'd';
    }
    
    // Combine WHERE conditions
    if (!empty($whereConditions)) {
        $baseQuery .= " AND " . implode(" AND ", $whereConditions);
    }
    
    // Count total services matching the criteria
    $countQuery = "SELECT COUNT(*) as total " . $baseQuery;
    $stmt = $conn->prepare($countQuery);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $countResult = $stmt->get_result();
    $totalRow = $countResult->fetch_assoc();
    $totalServices = $totalRow['total'];
    $stmt->close();
    
    // Calculate total pages
    $totalPages = ceil($totalServices / $perPage);
    
    // Ensure page is within valid range
    $page = max(1, min($page, $totalPages > 0 ? $totalPages : 1));
    $offset = ($page - 1) * $perPage;
    
    // Add ORDER BY clause based on sort preference
    switch ($sortBy) {
        case 'price_low':
            $orderBy = "s.price ASC";
            break;
        case 'price_high':
            $orderBy = "s.price DESC";
            break;
        case 'popularity':
            $orderBy = "COALESCE(b.booking_count, 0) DESC";
            break;
        case 'duration':
            $orderBy = "s.duration ASC";
            break;
        default:
            $orderBy = "COALESCE(b.booking_count, 0) DESC";
    }
    
    // Final query with pagination
    $servicesQuery = "
        SELECT s.*, 
               p.location as provider_location,
               u.first_name, u.last_name, u.profile_image,
               p.is_verified,
               COALESCE(b.booking_count, 0) as booking_count
        " . $baseQuery . "
        ORDER BY " . $orderBy . "
        LIMIT ?, ?
    ";
    
    $stmt = $conn->prepare($servicesQuery);
    
    // Add pagination parameters
    $params[] = $offset;
    $params[] = $perPage;
    $types .= 'ii';
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $servicesResult = $stmt->get_result();
    
    if ($servicesResult && $servicesResult->num_rows > 0) {
        while ($row = $servicesResult->fetch_assoc()) {
            $services[] = $row;
        }
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching data. Please try again later.";
}

// Check for flash messages
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Close database connection when done
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Browse through our wide range of device repair services at FixItNow">
    <title>Services - FixItNow</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            --bg-color: #f8f9fa;           /* Light background */
            --card-bg: #ffffff;            /* Card background */
            --header-bg: #1e1e24;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #1e1e24;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --modal-bg: #ffffff;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
            --danger-color: #dc3545;       /* Danger/red color */
            --warning-color: #ffc107;      /* Warning/yellow color */
            --success-color: #28a745;      /* Success/green color */
        }
            
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #f8f9fa;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #212529;           /* Slightly less dark background */
            --card-bg: #2c3034;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #2c3034;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.6rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .logo-text:hover {
            transform: scale(1.03);
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            font-weight: 900;
        }
        
        /* Navigation Styles */
        .nav-button {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 8px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.25s ease;
            font-weight: 500;
            font-size: 0.95rem;
            margin: 0 5px;
            position: relative;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            transform: translateY(-2px);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 12px rgba(121, 82, 179, 0.3);
        }
        
        .nav-button.active:before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 15px;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #fff;
        }
        
        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            padding: 3rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .page-title-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('images/pattern.svg') repeat;
            opacity: 0.1;
            z-index: 1;
        }
        
        .page-title-content {
            position: relative;
            z-index: 2;
        }
        
        .page-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            letter-spacing: -0.5px;
        }
        
        .page-description {
            font-size: 1.1rem;
            opacity: 0.95;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
        }
        
        /* Button Styles */
        .btn {
            transition: all 0.3s ease;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Filter Section */
        .filter-section {
            background-color: var(--bg-color);
            padding: 2rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .filter-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 6px 18px var(--shadow-color);
        }
        
        .filter-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 1.2rem;
            color: var(--text-color);
        }
        
        .filter-form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .filter-form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }
        
        .category-icon-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 1rem;
        }
        
        .category-filter-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 1rem 0.5rem;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .category-filter-item:hover {
            background-color: var(--primary-light);
        }
        
        .category-filter-item.active {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
        }
        
        .category-filter-icon {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .category-filter-name {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Price Range Slider */
        .price-range-container {
            margin-bottom: 1.5rem;
        }
        
        .price-inputs {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 10px;
        }
        
        .price-input {
            width: 48%;
        }
        
        /* Search Results Section */
        .results-section {
            padding: 3rem 0;
        }
        
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .results-count {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .sort-select {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--input-bg);
            color: var(--text-color);
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Service Card */
        .service-card {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .service-image-container {
            position: relative;
            padding-top: 60%; /* Aspect ratio for the image area */
            overflow: hidden;
            background-color: var(--primary-light);
        }
        
        .service-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 4rem;
            color: var(--primary-color);
            opacity: 0.8;
        }
        
        .service-category-badge {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 5px 12px;
            border-radius: 20px;
            background-color: var(--primary-color);
            color: white;
            font-size: 0.8rem;
            font-weight: 600;
            z-index: 2;
        }
        
        .service-content {
            padding: 1.5rem;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .service-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            line-height: 1.3;
        }
        
        .service-provider {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .provider-image {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-right: 10px;
            object-fit: cover;
        }
        
        .provider-name {
            margin-right: 6px;
        }
        
        .verified-badge {
            display: inline-flex;
            align-items: center;
            font-size: 0.8rem;
            color: #0d6efd;
            margin-left: 5px;
        }
        
        .verified-badge i {
            margin-right: 3px;
        }
        
        .service-description {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 1.2rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            flex-grow: 1;
        }
        
        .service-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.2rem;
        }
        
        .service-price {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-color);
        }
        
        .service-duration {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .service-duration i {
            margin-right: 5px;
        }
        
        .service-location {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 1.2rem;
        }
        
        .service-location i {
            margin-right: 5px;
        }
        
        .service-actions {
            margin-top: auto;
        }
        
        /* Pagination */
        .pagination-container {
            margin-top: 3rem;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .page-item {
            margin: 0 2px;
        }
        
        .page-link {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .page-link:hover {
            background-color: var(--primary-light);
            color: var(--primary-color);
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-item.disabled .page-link {
            opacity: 0.5;
            pointer-events: none;
        }
        
        /* No Results State */
        .no-results {
            text-align: center;
            padding: 3rem 0;
        }
        
        .no-results-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .no-results-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .no-results-message {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Call to Action Section */
        .cta-section {
            padding: 4rem 0;
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .cta-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('images/pattern.svg') repeat;
            opacity: 0.1;
            z-index: 1;
        }
        
        .cta-content {
            position: relative;
            z-index: 2;
        }
        
        .cta-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
        }
        
        .cta-subtitle {
            font-size: 1.25rem;
            margin-bottom: 2rem;
            opacity: 0.95;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Back to Top Button */
        .back-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.4);
            cursor: pointer;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            z-index: 999;
        }
        
        .back-to-top.show {
            opacity: 1;
            visibility: visible;
        }
        
        .back-to-top:hover {
            transform: translateY(-5px);
            background-color: var(--primary-hover);
        }
        
        /* Footer Styles */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 4rem 0 2rem;
        }
        
        .footer-logo {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: white;
            margin-bottom: 1rem;
            display: block;
        }
        
        .footer-logo .highlight {
            color: var(--accent-color);
        }
        
        .footer-about {
            margin-bottom: 2rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-title {
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-links a:hover {
            color: white;
            opacity: 1;
        }
        
        .footer-contact {
            margin-bottom: 0.85rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-contact i {
            margin-right: 0.5rem;
            width: 20px;
            text-align: center;
        }
        
        .social-icons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .social-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .social-icon:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
            color: white;
        }
        
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 1.5rem;
            margin-top: 3rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .copyright {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .page-title {
                font-size: 2rem;
            }
            
            .category-icon-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .results-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .page-title-section {
                padding: 2rem 0;
            }
            
            .page-title {
                font-size: 1.75rem;
            }
            
            .category-icon-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .service-card {
                margin-bottom: 1.5rem;
            }
            
            .cta-title {
                font-size: 1.75rem;
            }
            
            .cta-subtitle {
                font-size: 1.1rem;
            }
        }
        
        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .filter-card {
                padding: 1rem;
            }
            
            .back-to-top {
                width: 40px;
                height: 40px;
                font-size: 1rem;
                bottom: 15px;
                right: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Back to Top Button -->
    <button class="back-to-top" id="backToTop" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>
    
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="logo-text">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="d-none d-lg-flex">
                    <a href="index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button active">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                    <a href="contact.php" class="nav-button">
                        <i class="fas fa-envelope"></i> Contact
                    </a>
                </div>
                
                <!-- Authentication Buttons -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="btn btn-outline-light d-lg-none me-2" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <?php if ($loggedIn): ?>
                        <!-- User is logged in -->
                        <?php if ($userRole === 'customer'): ?>
                            <a href="customer/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'provider'): ?>
                            <a href="provider/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'admin'): ?>
                            <a href="admin/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Admin Panel
                            </a>
                        <?php endif; ?>
                        
                        <a href="logout.php" class="btn btn-outline-light ms-2">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    <?php else: ?>
                        <!-- User is not logged in (this should never show due to redirect) -->
                        <a href="login.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="Register.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="container-fluid d-lg-none mt-3 d-none" id="mobileMenu">
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="find-technician.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action active">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
                <a href="contact.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-envelope me-2"></i> Contact
                </a>
            </div>
        </div>
    </header>
    
    <!-- Page Title Section -->
    <section class="page-title-section">
        <div class="container text-center page-title-content">
            <h1 class="page-title">Our Repair Services</h1>
            <p class="page-description">Browse through our comprehensive range of device repair services offered by our network of skilled technicians.</p>
        </div>
    </section>
    
    <!-- Filter Section -->
    <section class="filter-section">
        <div class="container">
            <div class="filter-card">
                <form action="services.php" method="GET" id="filterForm">
                    <input type="hidden" name="_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    
                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="filter-title">Device Category</div>
                            <div class="category-icon-grid">
                                <!-- Category type selection -->
                                <?php
                                $categoryIcons = [
                                    'smartphone' => 'fa-mobile-alt',
                                    'laptop' => 'fa-laptop',
                                    'tablet' => 'fa-tablet-alt',
                                    'desktop' => 'fa-desktop',
                                    'gaming' => 'fa-gamepad',
                                    'tv' => 'fa-tv'
                                ];
                                
                                foreach ($categories as $category) {
                                    $activeClass = ($searchCategory === $category) ? 'active' : '';
                                    $icon = $categoryIcons[$category] ?? 'fa-tools';
                                    $categoryName = ucfirst($category);
                                    
                                    echo '<div class="category-filter-item ' . $activeClass . '" data-category="' . $category . '">';
                                    echo '<div class="category-filter-icon"><i class="fas ' . $icon . '"></i></div>';
                                    echo '<div class="category-filter-name">' . $categoryName . '</div>';
                                    echo '</div>';
                                }
                                ?>
                            </div>
                            <input type="hidden" name="category" id="categoryInput" value="<?php echo h($searchCategory); ?>">
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="filter-title">Location</div>
                            <select class="form-select filter-form-control" name="location">
                                <option value="">All Locations</option>
                                <?php
                                foreach ($locations as $location) {
                                    $selected = ($searchLocation === $location) ? 'selected' : '';
                                    echo '<option value="' . h($location) . '" ' . $selected . '>' . h($location) . '</option>';
                                }
                                ?>
                            </select>
                            
                            <div class="mt-4">
                                <div class="filter-title">Search</div>
                                <input type="text" class="form-control filter-form-control" name="keyword" placeholder="Search by service name or description" value="<?php echo h($searchKeyword); ?>">
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="filter-title">Price Range (SAR)</div>
                            <div class="price-range-container">
                                <div class="price-inputs">
                                    <div class="price-input">
                                        <input type="number" class="form-control filter-form-control" name="min_price" placeholder="Min" value="<?php echo $minPrice ?? ''; ?>">
                                    </div>
                                    <div class="price-input">
                                        <input type="number" class="form-control filter-form-control" name="max_price" placeholder="Max" value="<?php echo $maxPrice ?? ''; ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <div class="filter-title">Sort By</div>
                                <select class="form-select filter-form-control" name="sort" id="sortSelect">
                                    <option value="popularity" <?php echo $sortBy === 'popularity' ? 'selected' : ''; ?>>Most Popular</option>
                                    <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                    <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                                    <option value="duration" <?php echo $sortBy === 'duration' ? 'selected' : ''; ?>>Shortest Duration</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 text-center">
                            <button type="submit" class="btn btn-primary px-5">
                                <i class="fas fa-search me-2"></i> Apply Filters
                            </button>
                            <a href="services.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-undo me-2"></i> Reset Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>
    
    <!-- Search Results Section -->
    <section class="results-section">
        <div class="container">
            <div class="results-header">
                <div class="results-count">
                    <?php if ($totalServices === 0): ?>
                        No services found
                    <?php elseif ($totalServices === 1): ?>
                        1 service found
                    <?php else: ?>
                        <?php echo $totalServices; ?> services found
                    <?php endif; ?>
                    
                    <?php if (!empty($searchCategory) || !empty($searchLocation)): ?>
                        <span class="text-muted">
                            <?php
                            $filterParts = [];
                            if (!empty($searchCategory)) {
                                $filterParts[] = ucfirst($searchCategory) . ' repair';
                            }
                            if (!empty($searchLocation)) {
                                $filterParts[] = 'in ' . $searchLocation;
                            }
                            if (!empty($filterParts)) {
                                echo ' for ' . implode(' ', $filterParts);
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (!empty($services)): ?>
                <div class="row g-4">
                    <?php foreach ($services as $service): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="service-card">
                                <?php 
                                $categoryText = ucfirst($service['category']);
                                $serviceIcon = $categoryIcons[$service['category']] ?? 'fa-tools';
                                $profileImage = !empty($service['profile_image']) ? 'profile_images/' . h($service['profile_image']) : 'profile_images/../default.png';
                                $providerName = h($service['first_name'] . ' ' . $service['last_name']);
                                $formattedDuration = $service['duration'] . ' min';
                                $providerLocation = !empty($service['provider_location']) ? h($service['provider_location']) : 'Not specified';
                                ?>
                                
                                <div class="service-image-container">
                                    <div class="service-icon">
                                        <i class="fas <?php echo $serviceIcon; ?>"></i>
                                    </div>
                                    <div class="service-category-badge">
                                        <?php echo $categoryText; ?>
                                    </div>
                                </div>
                                
                                <div class="service-content">
                                    <h3 class="service-title"><?php echo h($service['name']); ?></h3>
                                    
                                    <div class="service-provider">
                                        <img src="<?php echo $profileImage; ?>" class="provider-image" alt="<?php echo $providerName; ?>">
                                        <span class="provider-name"><?php echo $providerName; ?></span>
                                        <?php if ((int)$service['is_verified'] === 1): ?>
                                            <span class="verified-badge">
                                                <i class="fas fa-tools"></i> Verified
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="service-description"><?php echo h($service['description']); ?></p>
                                    
                                    <div class="service-meta">
                                        <div class="service-price"><?php echo formatPrice($service['price']); ?></div>
                                        <div class="service-duration">
                                            <i class="far fa-clock"></i> <?php echo $formattedDuration; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="service-location">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo $providerLocation; ?>
                                    </div>
                                    
                                    <div class="service-actions">
                                        <a href="service-details.php?id=<?php echo (int)$service['id']; ?>" class="btn btn-primary w-100">
                                            View Details
                                        </a>
                                        
                                        <?php if ($isCustomer): ?>
                                            <!-- User is logged in as customer -->
                                            <a href="book-service.php?service_id=<?php echo (int)$service['id']; ?>&provider_id=<?php echo (int)$service['provider_id']; ?>" class="btn btn-outline-primary w-100 mt-2">
                                                Book Now
                                            </a>
                                        <?php else: ?>
                                            <!-- User is logged in but not a customer -->
                                            <button class="btn btn-outline-secondary w-100 mt-2" disabled>
                                                Customer Only
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination-container">
                        <ul class="pagination">
                            <!-- Previous page link -->
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page <= 1) ? '#' : '?page=' . ($page - 1) . '&location=' . urlencode($searchLocation) . '&category=' . urlencode($searchCategory) . '&keyword=' . urlencode($searchKeyword) . '&min_price=' . $minPrice . '&max_price=' . $maxPrice . '&sort=' . $sortBy; ?>" aria-label="Previous">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $startPage + 4);
                            
                            if ($endPage - $startPage < 4 && $startPage > 1) {
                                $startPage = max(1, $endPage - 4);
                            }
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                                <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&location=<?php echo urlencode($searchLocation); ?>&category=<?php echo urlencode($searchCategory); ?>&keyword=<?php echo urlencode($searchKeyword); ?>&min_price=<?php echo $minPrice; ?>&max_price=<?php echo $maxPrice; ?>&sort=<?php echo $sortBy; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next page link -->
                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page >= $totalPages) ? '#' : '?page=' . ($page + 1) . '&location=' . urlencode($searchLocation) . '&category=' . urlencode($searchCategory) . '&keyword=' . urlencode($searchKeyword) . '&min_price=' . $minPrice . '&max_price=' . $maxPrice . '&sort=' . $sortBy; ?>" aria-label="Next">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- No Results State -->
                <div class="no-results">
                    <div class="no-results-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <h2 class="no-results-title">No services found</h2>
                    <p class="no-results-message">
                        We couldn't find any services that match your search criteria. Please try adjusting your filters or search for a different device category.
                    </p>
                    <a href="services.php" class="btn btn-lg btn-primary">
                        <i class="fas fa-undo me-2"></i> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Call to Action Section -->
    <section class="cta-section">
        <div class="container cta-content">
            <h2 class="cta-title">Can't find the service you need?</h2>
            <p class="cta-subtitle">Our customer support team can help you find the right service for your specific device repair needs, or you can request a custom quote.</p>
            
            <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                <a href="contact.php" class="btn btn-light btn-lg">
                    <i class="fas fa-headset me-2"></i> Contact Support
                </a>
                
                <?php if ($isCustomer): ?>
                <a href="request-quote.php" class="btn btn-outline-light btn-lg">
                    <i class="fas fa-file-invoice me-2"></i> Request a Quote
                </a>
                <?php else: ?>
                <button class="btn btn-outline-light btn-lg" disabled>
                    <i class="fas fa-file-invoice me-2"></i> Customer Only Service
                </button>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="footer-logo">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <p class="footer-about">
                        FixItNow is a platform connecting users with expert technicians for all kinds of device repairs. We make device repair easy, reliable, and accessible to everyone.
                    </p>
                    <div class="social-icons">
                        <a href="#" class="social-icon" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="find-technician.php">Find Technician</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="how-it-works.php">How It Works</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Customers</h4>
                    <ul class="footer-links">
                        <li><a href="register.php">Sign Up</a></li>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="book-service.php">Book a Service</a></li>
                        <li><a href="request-quote.php">Request a Quote</a></li>
                        <li><a href="faq.php">FAQ</a></li>
                        <li><a href="support.php">Support</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Technicians</h4>
                    <ul class="footer-links">
                        <li><a href="join-as-provider.php">Join as a Provider</a></li>
                        <li><a href="provider/login.php">Provider Login</a></li>
                        <li><a href="provider-terms.php">Provider Terms</a></li>
                        <li><a href="provider-resources.php">Resources</a></li>
                        <li><a href="provider-faq.php">Provider FAQ</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-12">
                    <h4 class="footer-title">Contact Us</h4>
                    <div class="footer-contact">
                        <i class="fas fa-map-marker-alt"></i> 123 Tech Street, Repair City
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-phone"></i> +1 (555) 123-4567
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-envelope"></i> support@fixitnow.com
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="copyright">
                    &copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.
                </div>
                <div>
                    <a href="privacy.php" class="me-3 text-white-50">Privacy Policy</a>
                    <a href="terms.php" class="text-white-50">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('d-none');
                    
                    // Change icon based on menu state
                    const icon = mobileMenuToggle.querySelector('i');
                    if (mobileMenu.classList.contains('d-none')) {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    } else {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-times');
                    }
                });
            }
            
            // Back to top button
            const backToTopButton = document.getElementById('backToTop');
            
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTopButton.classList.add('show');
                } else {
                    backToTopButton.classList.remove('show');
                }
            });
            
            backToTopButton.addEventListener('click', function() {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
            
            // Category type selection
            const categoryItems = document.querySelectorAll('.category-filter-item');
            const categoryInput = document.getElementById('categoryInput');
            
            categoryItems.forEach(function(item) {
                item.addEventListener('click', function() {
                    // Remove active class from all items
                    categoryItems.forEach(function(el) {
                        el.classList.remove('active');
                    });
                    
                    // Add active class to clicked item
                    this.classList.add('active');
                    
                    // Update hidden input value
                    categoryInput.value = this.getAttribute('data-category');
                });
            });
            
            // Sort select auto-submit
            const sortSelect = document.getElementById('sortSelect');
            
            if (sortSelect) {
                sortSelect.addEventListener('change', function() {
                    document.getElementById('filterForm').submit();
                });
            }
            
            // Theme Toggle Functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                htmlElement.setAttribute('data-bs-theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                
                if (currentTheme === 'dark') {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                }
            });
            
            // Auto dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    if (typeof bootstrap !== 'undefined') {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    } else {
                        // Fallback if bootstrap JS isn't loaded
                        alert.style.display = 'none';
                    }
                });
            }, 5000);
            
            // Add CSRF token to all forms
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                if (!form.querySelector('input[name="_token"]')) {
                    const csrfToken = '<?php echo $_SESSION['csrf_token']; ?>';
                    const tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = '_token';
                    tokenInput.value = csrfToken;
                    form.appendChild(tokenInput);
                }
            });
        });
    </script>
</body>
</html>