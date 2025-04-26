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
    // Store current page with query parameters for redirect after login
    $redirectUrl = 'find-technician.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirectUrl .= '?' . $_SERVER['QUERY_STRING'];
    }
    $_SESSION['redirect_after_login'] = $redirectUrl;
    
    header('Location: login.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$providers = [];
$locations = [];
$deviceTypes = ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'];
$totalProviders = 0;
$errorMessage = '';
$successMessage = '';

// Get search params
$searchLocation = isset($_GET['location']) ? $_GET['location'] : '';
$searchDevice = isset($_GET['device']) ? $_GET['device'] : '';
$searchKeyword = isset($_GET['keyword']) ? $_GET['keyword'] : '';
$searchRating = isset($_GET['min_rating']) ? (int)$_GET['min_rating'] : 0;
$searchVerified = isset($_GET['verified']) ? (int)$_GET['verified'] : 0;

// Default sorting
$sortBy = isset($_GET['sort']) ? $_GET['sort'] : 'rating';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 9; // Show 9 technicians per page
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

// Build query to get technicians with filters
try {
    // Start building the base query
    $baseQuery = "
        FROM providers p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN reviews r ON p.id = r.provider_id
        LEFT JOIN bookings b ON p.id = b.provider_id AND b.status IN ('completed', 'confirmed')
        WHERE u.status = 'active'
    ";

    // Filters
    $whereConditions = [];
    $havingConditions = [];
    $params = [];
    $types = '';

    if (!empty($searchLocation)) {
        $whereConditions[] = "p.location = ?";
        $params[] = $searchLocation;
        $types .= 's';
    }

    if (!empty($searchDevice)) {
        $whereConditions[] = "p.specialties LIKE ?";
        $params[] = '%' . $searchDevice . '%';
        $types .= 's';
    }

    if (!empty($searchKeyword)) {
        $whereConditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR p.bio LIKE ?)";
        $params[] = '%' . $searchKeyword . '%';
        $params[] = '%' . $searchKeyword . '%';
        $params[] = '%' . $searchKeyword . '%';
        $types .= 'sss';
    }

    if ($searchVerified) {
        $whereConditions[] = "p.is_verified = 1";
    }

    // Combine WHERE conditions
    if (!empty($whereConditions)) {
        $baseQuery .= " AND " . implode(" AND ", $whereConditions);
    }

    // Group by provider
    $baseQuery .= " GROUP BY p.id";

    // Having conditions
    if ($searchRating > 0) {
        $havingConditions[] = "AVG(r.rating) >= ?";
    }

    // Build COUNT query separately
    $countQuery = "
        SELECT COUNT(*) as total FROM (
            SELECT p.id
            $baseQuery
            " . (!empty($havingConditions) ? " HAVING " . implode(" AND ", $havingConditions) : "") . "
        ) as subquery
    ";

    $stmt = $conn->prepare($countQuery);
    
    $countParams = $params;
    $countTypes = $types;

    if ($searchRating > 0) {
        $countParams[] = $searchRating;
        $countTypes .= 'd';
    }

    if (!empty($countParams)) {
        $stmt->bind_param($countTypes, ...$countParams);
    }

    $stmt->execute();
    $countResult = $stmt->get_result();
    $totalRow = $countResult->fetch_assoc();
    $totalProviders = $totalRow['total'];
    $stmt->close();

    // Pagination
    $totalPages = max(ceil($totalProviders / $perPage), 1);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    // Order by
    switch ($sortBy) {
        case 'rating':
            $orderBy = "avg_rating DESC, review_count DESC";
            break;
        case 'reviews':
            $orderBy = "review_count DESC, avg_rating DESC";
            break;
        case 'experience':
            $orderBy = "CASE 
                          WHEN p.experience = '10+' THEN 5
                          WHEN p.experience = '5-10' THEN 4
                          WHEN p.experience = '3-5' THEN 3
                          WHEN p.experience = '1-3' THEN 2
                          WHEN p.experience = '0-1' THEN 1
                          ELSE 0
                        END DESC, avg_rating DESC";
            break;
        case 'bookings':
            $orderBy = "booking_count DESC, avg_rating DESC";
            break;
        default:
            $orderBy = "avg_rating DESC, review_count DESC";
    }

    // Final query with select
    $providersQuery = "
        SELECT p.*, 
               u.first_name, u.last_name, u.profile_image,
               COUNT(DISTINCT r.id) as review_count,
               AVG(r.rating) as avg_rating,
               COUNT(DISTINCT b.id) as booking_count
        $baseQuery
        " . (!empty($havingConditions) ? " HAVING " . implode(" AND ", $havingConditions) : "") . "
        ORDER BY $orderBy
        LIMIT ?, ?
    ";

    $stmt = $conn->prepare($providersQuery);

    $providersParams = $params;
    $providersTypes = $types;

    if ($searchRating > 0) {
        $providersParams[] = $searchRating;
        $providersTypes .= 'd';
    }

    // Pagination params
    $providersParams[] = $offset;
    $providersParams[] = $perPage;
    $providersTypes .= 'ii';

    $stmt->bind_param($providersTypes, ...$providersParams);
    $stmt->execute();
    $providersResult = $stmt->get_result();

    $providers = [];

    if ($providersResult && $providersResult->num_rows > 0) {
        while ($row = $providersResult->fetch_assoc()) {
            $providers[] = $row;
        }
    }

    $stmt->close();

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
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
    <meta name="description" content="Find expert technicians for your device repair needs at FixItNow">
    <title>Find a Technician - FixItNow</title>
    
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
        
        .device-icon-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 1rem;
        }
        
        .device-filter-item {
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
        
        .device-filter-item:hover {
            background-color: var(--primary-light);
        }
        
        .device-filter-item.active {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
        }
        
        .device-filter-icon {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .device-filter-name {
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        /* Rating Slider */
        .rating-slider {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        .rating-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 0.5rem;
        }
        
        .rating-label {
            font-size: 0.8rem;
            color: var(--text-muted);
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
        
        /* Provider Card */
        .provider-card {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            text-align: center;
            padding: 2rem 1.5rem;
        }
        
        .provider-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .provider-image {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            margin: 0 auto 1rem;
            border: 3px solid var(--primary-color);
        }
        
        .provider-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            color: var(--text-color);
        }
        
        .provider-specialty {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .provider-location {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .provider-location i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .provider-stats {
            display: flex;
            justify-content: center;
            gap: 1.2rem;
            margin-bottom: 1.2rem;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .stat-value {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }
        
        .provider-actions {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .verified-badge {
            display: inline-flex;
            align-items: center;
            font-size: 0.9rem;
            color: #0d6efd; /* Bootstrap blue color */
            margin-bottom: 0.75rem;
        }
        
        .verified-badge i {
            margin-right: 0.5rem;
            color: #0d6efd; /* Ensuring the icon is also blue */
        }
        
        .verified-badge i {
            margin-right: 0.5rem;
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
            
            .device-icon-grid {
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
            
            .device-icon-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .provider-card {
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
            
            .provider-image {
                width: 100px;
                height: 100px;
            }
            
            .provider-stats {
                gap: 0.75rem;
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
                    <a href="find-technician.php" class="nav-button active">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button">
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
                        <!-- User is not logged in - this should never show due to redirect -->
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
                <a href="find-technician.php" class="list-group-item list-group-item-action active">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action">
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
            <h1 class="page-title">Find a Technician</h1>
            <p class="page-description">Browse through our network of qualified and verified technicians to get your devices fixed quickly and professionally.</p>
        </div>
    </section>
    
    <!-- Filter Section -->
    <section class="filter-section">
        <div class="container">
            <div class="filter-card">
                <form action="find-technician.php" method="GET" id="filterForm">
                    <input type="hidden" name="_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                    <div class="row">
                        <div class="col-lg-4 mb-4">
                            <div class="filter-title">Device Type</div>
                            <div class="device-icon-grid">
                                <!-- Device type selection -->
                                <?php
                                $deviceIcons = [
                                    'smartphone' => 'fa-mobile-alt',
                                    'laptop' => 'fa-laptop',
                                    'tablet' => 'fa-tablet-alt',
                                    'desktop' => 'fa-desktop',
                                    'gaming' => 'fa-gamepad',
                                    'tv' => 'fa-tv'
                                ];
                                
                                foreach ($deviceTypes as $device) {
                                    $activeClass = ($searchDevice === $device) ? 'active' : '';
                                    $icon = $deviceIcons[$device] ?? 'fa-tools';
                                    $deviceName = ucfirst($device);
                                    
                                    echo '<div class="device-filter-item ' . $activeClass . '" data-device="' . $device . '">';
                                    echo '<div class="device-filter-icon"><i class="fas ' . $icon . '"></i></div>';
                                    echo '<div class="device-filter-name">' . $deviceName . '</div>';
                                    echo '</div>';
                                }
                                ?>
                            </div>
                            <input type="hidden" name="device" id="deviceInput" value="<?php echo h($searchDevice); ?>">
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
                                <input type="text" class="form-control filter-form-control" name="keyword" placeholder="Search by name or keyword" value="<?php echo h($searchKeyword); ?>">
                            </div>
                        </div>
                        
                        <div class="col-lg-4 mb-4">
                            <div class="filter-title">Rating</div>
                            <input type="range" class="form-range rating-slider" min="0" max="5" step="1" id="ratingSlider" name="min_rating" value="<?php echo $searchRating; ?>">
                            <div class="rating-labels">
                                <span class="rating-label">Any Rating</span>
                                <span class="rating-label selected-rating" id="ratingValue">
                                    <?php echo $searchRating > 0 ? $searchRating . '+ Stars' : 'Any Rating'; ?>
                                </span>
                            </div>
                            
                            <div class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="verified" id="verifiedCheck" value="1" <?php echo $searchVerified ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="verifiedCheck">
                                <div class="verified-badge">
                                        <i class="fas fa-tools"></i><b>Verified Technician</b> 
                                    </div>
                                </label>
                            </div>
                            
                            <div class="mt-4">
                                <div class="filter-title">Sort By</div>
                                <select class="form-select filter-form-control" name="sort" id="sortSelect">
                                    <option value="rating" <?php echo $sortBy === 'rating' ? 'selected' : ''; ?>>Highest Rating</option>
                                    <option value="reviews" <?php echo $sortBy === 'reviews' ? 'selected' : ''; ?>>Most Reviews</option>
                                    <option value="experience" <?php echo $sortBy === 'experience' ? 'selected' : ''; ?>>Most Experience</option>
                                    <option value="bookings" <?php echo $sortBy === 'bookings' ? 'selected' : ''; ?>>Most Bookings</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12 text-center">
                            <button type="submit" class="btn btn-primary px-5">
                                <i class="fas fa-search me-2"></i> Apply Filters
                            </button>
                            <a href="find-technician.php" class="btn btn-outline-secondary ms-2">
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
                    <?php if ($totalProviders === 0): ?>
                        No technicians found
                    <?php elseif ($totalProviders === 1): ?>
                        1 technician found
                    <?php else: ?>
                        <?php echo $totalProviders; ?> technicians found
                    <?php endif; ?>
                    
                    <?php if (!empty($searchDevice) || !empty($searchLocation)): ?>
                        <span class="text-muted">
                            <?php
                            $filterParts = [];
                            if (!empty($searchDevice)) {
                                $filterParts[] = ucfirst($searchDevice) . ' repair';
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
            
            <?php if (!empty($providers)): ?>
                <div class="row g-4">
                    <?php foreach ($providers as $provider): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="provider-card">
                                <?php 
                                $profileImage = !empty($provider['profile_image']) ? 'profile_images/' . h($provider['profile_image']) : 'profile_images/../default.png';
                                $providerName = h($provider['first_name'] . ' ' . $provider['last_name']);
                                $specialties = !empty($provider['specialties']) ? explode(',', $provider['specialties']) : [];
                                $specialtiesText = '';
                                
                                foreach ($specialties as $specialty) {
                                    switch (trim($specialty)) {
                                        case 'smartphone':
                                            $specialtiesText .= 'Smartphone, ';
                                            break;
                                        case 'laptop':
                                            $specialtiesText .= 'Laptop, ';
                                            break;
                                        case 'tablet':
                                            $specialtiesText .= 'Tablet, ';
                                            break;
                                        case 'desktop':
                                            $specialtiesText .= 'Desktop, ';
                                            break;
                                        case 'gaming':
                                            $specialtiesText .= 'Gaming, ';
                                            break;
                                        case 'tv':
                                            $specialtiesText .= 'TV, ';
                                            break;
                                    }
                                }
                                
                                $specialtiesText = rtrim($specialtiesText, ', ');
                                if (empty($specialtiesText)) {
                                    $specialtiesText = 'General Repairs';
                                }
                                
                                $experience = '';
                                switch ($provider['experience']) {
                                    case '0-1':
                                        $experience = 'Less than 1 year';
                                        break;
                                    case '1-3':
                                        $experience = '1-3 years';
                                        break;
                                    case '3-5':
                                        $experience = '3-5 years';
                                        break;
                                    case '5-10':
                                        $experience = '5-10 years';
                                        break;
                                    case '10+':
                                        $experience = 'Over 10 years';
                                        break;
                                    default:
                                        $experience = 'Not specified';
                                }
                                ?>
                                
                                <img src="<?php echo $profileImage; ?>" class="provider-image" alt="<?php echo $providerName; ?>">
                                <h3 class="provider-name"><?php echo $providerName; ?></h3>
                                
                                <?php if ((int)$provider['is_verified'] === 1): ?>
                                    <div class="verified-badge">
                                        <i class="fas fa-tools"></i> Verified Technician
                                    </div>
                                <?php endif; ?>
                                
                                <p class="provider-specialty"><?php echo $specialtiesText; ?></p>
                                
                                <?php if (!empty($provider['location'])): ?>
                                    <div class="provider-location">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo h($provider['location']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="provider-stats">
                                    <div class="stat-item">
                                        <div class="stat-value">
                                            <?php echo number_format($provider['avg_rating'] ?? 0, 1); ?>
                                            <i class="fas fa-star text-warning ms-1"></i>
                                        </div>
                                        <div class="stat-label">Rating</div>
                                    </div>
                                    
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int)$provider['review_count']; ?></div>
                                        <div class="stat-label">Reviews</div>
                                    </div>
                                    
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int)$provider['booking_count']; ?></div>
                                        <div class="stat-label">Jobs</div>
                                    </div>
                                </div>
                                
                                <div class="provider-actions">
                                    <a href="technician-profile.php?id=<?php echo (int)$provider['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-user me-2"></i> View Profile
                                    </a>
                                    
                                    <?php if ($isCustomer): ?>
                                        <a href="book-service.php?provider_id=<?php echo (int)$provider['id']; ?>" class="btn btn-outline-primary">
                                            <i class="fas fa-calendar-check me-2"></i> Book Service
                                        </a>
                                    <?php endif; ?>
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
                                <a class="page-link" href="<?php echo ($page <= 1) ? '#' : '?page=' . ($page - 1) . '&location=' . urlencode($searchLocation) . '&device=' . urlencode($searchDevice) . '&keyword=' . urlencode($searchKeyword) . '&min_rating=' . $searchRating . '&verified=' . $searchVerified . '&sort=' . $sortBy; ?>" aria-label="Previous">
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
                                    <a class="page-link" href="?page=<?php echo $i; ?>&location=<?php echo urlencode($searchLocation); ?>&device=<?php echo urlencode($searchDevice); ?>&keyword=<?php echo urlencode($searchKeyword); ?>&min_rating=<?php echo $searchRating; ?>&verified=<?php echo $searchVerified; ?>&sort=<?php echo $sortBy; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next page link -->
                            <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo ($page >= $totalPages) ? '#' : '?page=' . ($page + 1) . '&location=' . urlencode($searchLocation) . '&device=' . urlencode($searchDevice) . '&keyword=' . urlencode($searchKeyword) . '&min_rating=' . $searchRating . '&verified=' . $searchVerified . '&sort=' . $sortBy; ?>" aria-label="Next">
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
                        <i class="fas fa-search"></i>
                    </div>
                    <h2 class="no-results-title">No technicians found</h2>
                    <p class="no-results-message">
                        We couldn't find any technicians that match your search criteria. Please try adjusting your filters or search for a different device type.
                    </p>
                    <a href="find-technician.php" class="btn btn-lg btn-primary">
                        <i class="fas fa-undo me-2"></i> Reset Filters
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Call to Action Section -->
    <section class="cta-section">
        <div class="container cta-content">
            <h2 class="cta-title">Need help choosing a technician?</h2>
            <p class="cta-subtitle">Our customer support team can help you find the right technician for your specific device repair needs.</p>
            
            <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                <a href="contact.php" class="btn btn-light btn-lg">
                    <i class="fas fa-headset me-2"></i> Contact Support
                </a>
                
                <?php if ($isCustomer): ?>
                <a href="request-quote.php" class="btn btn-outline-light btn-lg">
                    <i class="fas fa-file-invoice me-2"></i> Request a Quote
                </a>
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
                        <?php if ($isCustomer): ?>
                        <li><a href="request-quote.php">Request a Quote</a></li>
                        <?php else: ?>
                        <li><a href="login.php?redirect=quote">Request a Quote</a></li>
                        <?php endif; ?>
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
            
            // Device type selection
            const deviceItems = document.querySelectorAll('.device-filter-item');
            const deviceInput = document.getElementById('deviceInput');
            
            deviceItems.forEach(function(item) {
                item.addEventListener('click', function() {
                    // Remove active class from all items
                    deviceItems.forEach(function(el) {
                        el.classList.remove('active');
                    });
                    
                    // Add active class to clicked item
                    this.classList.add('active');
                    
                    // Update hidden input value
                    deviceInput.value = this.getAttribute('data-device');
                });
            });
            
            // Rating slider
            const ratingSlider = document.getElementById('ratingSlider');
            const ratingValue = document.getElementById('ratingValue');
            
            if (ratingSlider && ratingValue) {
                ratingSlider.addEventListener('input', function() {
                    const value = this.value;
                    if (value > 0) {
                        ratingValue.textContent = value + '+ Stars';
                    } else {
                        ratingValue.textContent = 'Any Rating';
                    }
                });
            }
            
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
            
            // Add CSRF protection to forms
            const forms = document.querySelectorAll('form');
            forms.forEach(function(form) {
                if (!form.querySelector('input[name="_token"]')) {
                    const csrfToken = '<?php echo isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : ''; ?>';
                    if (csrfToken) {
                        const tokenInput = document.createElement('input');
                        tokenInput.type = 'hidden';
                        tokenInput.name = '_token';
                        tokenInput.value = csrfToken;
                        form.appendChild(tokenInput);
                    }
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
        });
    </script>
</body>
</html>