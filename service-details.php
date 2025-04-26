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

// Database connection
include 'conn.php';

// Set proper character set
if ($conn) {
    $conn->set_charset("utf8mb4");
}

// Initialize variables
$serviceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$service = null;
$provider = null;
$relatedServices = [];
$reviews = [];
$averageRating = 0;
$reviewCount = 0;
$errorMessage = '';
$successMessage = '';
$deviceIcons = [
    'smartphone' => 'fa-mobile-alt',
    'laptop' => 'fa-laptop',
    'tablet' => 'fa-tablet-alt',
    'desktop' => 'fa-desktop',
    'gaming' => 'fa-gamepad',
    'tv' => 'fa-tv'
];
$deviceTypesDisplay = [
    'smartphone' => 'Smartphone Repair',
    'laptop' => 'Laptop Repair',
    'tablet' => 'Tablet Repair',
    'desktop' => 'Desktop Repair',
    'gaming' => 'Gaming Device Repair',
    'tv' => 'TV Repair'
];

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Function to safely format prices with the currency image
function formatPrice($price, $currencyImgPath = 'sar/sar.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    // Use the image path for currency display
    $currencyImg = '<img src="' . h($currencyImgPath) . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}

// Function to format rating stars
function formatRatingStars($rating, $maxStars = 5) {
    $html = '<div class="rating-stars">';
    $fullStars = floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5;
    $emptyStars = $maxStars - $fullStars - ($halfStar ? 1 : 0);
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $html .= '<i class="fas fa-star-half-alt"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star"></i>';
    }
    
    $html .= '</div>';
    return $html;
}

// Check for valid service ID
if (!$serviceId) {
    $errorMessage = 'Invalid service ID.';
} else if (!$conn) {
    $errorMessage = 'Database connection failed.';
} else {
    try {
        // Get service details
        $serviceQuery = "
            SELECT s.*, 
                   p.id as provider_id, 
                   p.is_verified, 
                   p.hourly_rate,
                   p.bio, 
                   p.location,
                   p.experience,
                   u.first_name, 
                   u.last_name,
                   u.profile_image
            FROM services s
            JOIN providers p ON s.provider_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE s.id = ? AND s.is_active = 1 AND u.status = 'active'
        ";
        
        $stmt = $conn->prepare($serviceQuery);
        $stmt->bind_param('i', $serviceId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $service = $result->fetch_assoc();
            $providerId = $service['provider_id'];
            
            // Get provider details
            $providerQuery = "
                SELECT p.*, 
                       u.first_name, 
                       u.last_name, 
                       u.profile_image,
                       COUNT(DISTINCT r.id) as review_count, 
                       IFNULL(AVG(r.rating), 0) as avg_rating,
                       COUNT(DISTINCT b.id) as completed_jobs
                FROM providers p
                JOIN users u ON p.user_id = u.id
                LEFT JOIN reviews r ON p.id = r.provider_id
                LEFT JOIN bookings b ON p.id = b.provider_id AND b.status = 'completed'
                WHERE p.id = ?
                GROUP BY p.id
            ";
            
            $stmt = $conn->prepare($providerQuery);
            $stmt->bind_param('i', $providerId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $provider = $result->fetch_assoc();
                $averageRating = floatval($provider['avg_rating']);
                $reviewCount = intval($provider['review_count']);
            }
            
            // Get service reviews
            $reviewsQuery = "
                SELECT r.*, 
                       u.first_name, 
                       u.last_name, 
                       u.profile_image
                FROM reviews r
                JOIN users u ON r.customer_id = u.id
                JOIN bookings b ON r.booking_id = b.id
                JOIN services s ON b.service_id = s.id
                WHERE s.id = ? OR (r.provider_id = ? AND r.is_published = 1)
                ORDER BY r.created_at DESC
                LIMIT 5
            ";
            
            $stmt = $conn->prepare($reviewsQuery);
            $stmt->bind_param('ii', $serviceId, $providerId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $reviews[] = $row;
                }
            }
            
            // Get related services (same category from same provider or other providers)
            $relatedQuery = "
                SELECT s.*, 
                       p.id as provider_id, 
                       p.is_verified,
                       u.first_name, 
                       u.last_name,
                       COUNT(DISTINCT r.id) as review_count, 
                       IFNULL(AVG(r.rating), 0) as avg_rating
                FROM services s
                JOIN providers p ON s.provider_id = p.id
                JOIN users u ON p.user_id = u.id
                LEFT JOIN reviews r ON p.id = r.provider_id
                WHERE s.category = ? AND s.id != ? AND s.is_active = 1 AND u.status = 'active'
                GROUP BY s.id
                ORDER BY p.id = ? DESC, IFNULL(AVG(r.rating), 0) DESC
                LIMIT 3
            ";
            
            $stmt = $conn->prepare($relatedQuery);
            $category = $service['category'];
            $stmt->bind_param('sii', $category, $serviceId, $providerId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $relatedServices[] = $row;
                }
            }
        } else {
            $errorMessage = 'Service not found.';
        }
    } catch (Exception $e) {
        error_log("Error fetching service details: " . $e->getMessage());
        $errorMessage = 'An error occurred while retrieving service details. Please try again later.';
    }
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

// Close database connection
if ($conn) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="<?php echo $service ? h($service['name']) . ' - ' . h($service['description']) : 'Service Details - FixItNow'; ?>">
    <title><?php echo $service ? h($service['name']) . ' - FixItNow' : 'Service Details - FixItNow'; ?></title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Date Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        /* Breadcrumb Styles */
        .breadcrumb-section {
            background-color: var(--bg-color);
            padding: 1rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .breadcrumb {
            margin-bottom: 0;
            background-color: transparent;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .breadcrumb-item a:hover {
            color: var(--primary-hover);
        }
        
        .breadcrumb-item.active {
            color: var(--text-muted);
        }
        
        /* Service Details Section */
        .service-details-section {
            padding: 3rem 0;
        }
        
        .service-header {
            margin-bottom: 2rem;
        }
        
        .service-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .service-category {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 50px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .service-image {
            width: 100%;
            height: 300px;
            background-color: var(--primary-light);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        
        .service-image i {
            font-size: 5rem;
            color: var(--primary-color);
        }
        
        .service-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
        }
        
        .meta-item i {
            margin-right: 0.5rem;
            color: var(--primary-color);
            font-size: 1.25rem;
        }
        
        .meta-label {
            font-weight: 600;
            margin-right: 0.5rem;
        }
        
        .meta-value {
            color: var(--text-muted);
        }
        
        .service-description {
            margin-bottom: 2rem;
            line-height: 1.8;
        }
        
        /* Provider Card */
        .provider-card {
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .provider-header {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .provider-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 3px solid var(--primary-color);
        }
        
        .provider-info {
            flex-grow: 1;
        }
        
        .provider-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
        }
        
        .verified-badge {
            display: inline-flex;
            align-items: center;
            font-size: 0.8rem;
            color: #0d6efd; /* Bootstrap blue color */
            margin-left: 10px;
        }
        
        .verified-badge i {
            margin-right: 4px;
            color: #0d6efd;
        }
        
        .provider-specialty {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-bottom: 0.5rem;
        }
        
        .provider-location {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .provider-location i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .provider-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .provider-bio {
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            color: var(--text-muted);
        }
        
        .rating-stars {
            color: #ffc107;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .rating-count {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        /* Booking Card */
        .booking-card {
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            padding: 2rem;
        }
        
        .booking-header {
            margin-bottom: 1.5rem;
        }
        
        .booking-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .booking-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .booking-meta {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .booking-meta-item {
            display: flex;
            align-items: center;
        }
        
        .booking-meta-item i {
            width: 24px;
            margin-right: 0.75rem;
            color: var(--primary-color);
        }
        
        .booking-form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .booking-form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .booking-form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }
        
        .booking-cta {
            text-align: center;
        }
        
        /* Reviews Section */
        .reviews-section {
            padding: 3rem 0;
            background-color: var(--primary-light);
        }
        
        .section-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .review-card {
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .review-header {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .reviewer-image {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
        }
        
        .reviewer-info {
            flex-grow: 1;
        }
        
        .reviewer-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .review-date {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .review-rating {
            margin-left: auto;
        }
        
        .review-comment {
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        /* Related Services Section */
        .related-section {
            padding: 3rem 0;
        }
        
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
        
        .card-image {
            width: 100%;
            height: 160px;
            background-color: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-size: 3rem;
        }
        
        .card-content {
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }
        
        .card-category {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .card-provider {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
        }
        
        .card-provider i {
            margin-right: 8px;
            color: var(--primary-color);
        }
        
        .card-description {
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            flex-grow: 1;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2rem;
        }
        
        .card-price {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-color);
        }
        
        .card-duration {
            display: flex;
            align-items: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .card-duration i {
            margin-right: 5px;
        }
        
        .card-rating {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            margin-bottom: 1.2rem;
        }
        
        .card-rating i {
            color: #ffc107;
            margin-right: 0.25rem;
        }
        
        .card-actions {
            margin-top: auto;
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
        
        /* Error State */
        .error-container {
            text-align: center;
            padding: 5rem 0;
        }
        
        .error-icon {
            font-size: 5rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            opacity: 0.5;
        }
        
        .error-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .error-message {
            font-size: 1.25rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .service-title {
                font-size: 1.75rem;
            }
            
            .service-image {
                height: 250px;
            }
            
            .booking-card {
                margin-top: 2rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .service-meta {
                flex-direction: column;
                gap: 0.75rem;
            }
            
            .provider-stats {
                flex-wrap: wrap;
                gap: 1rem;
            }
            
            .stat-item {
                flex-basis: 45%;
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
            .service-title {
                font-size: 1.5rem;
            }
            
            .service-image {
                height: 200px;
            }
            
            .provider-header {
                flex-direction: column;
                text-align: center;
            }
            
            .provider-image {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .provider-info {
                text-align: center;
            }
            
            .provider-name {
                justify-content: center;
            }
            
            .provider-location {
                justify-content: center;
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
                        <!-- User is not logged in -->
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
            </div>
        </div>
    </header>
    
    <!-- Breadcrumb Section -->
    <section class="breadcrumb-section">
        <div class="container">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="services.php">Services</a></li>
                    <?php if ($service): ?>
                        <li class="breadcrumb-item active" aria-current="page"><?php echo h($service['name']); ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item active" aria-current="page">Service Details</li>
                    <?php endif; ?>
                </ol>
            </nav>
        </div>
    </section>
    
    <?php if (!empty($errorMessage)): ?>
        <!-- Error Section -->
        <section class="error-container">
            <div class="container">
                <div class="error-icon">
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h2 class="error-title">Oops! Something went wrong</h2>
                <p class="error-message"><?php echo h($errorMessage); ?></p>
                <a href="services.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Services
                </a>
            </div>
        </section>
    <?php elseif ($service): ?>
        <!-- Service Details Section -->
        <section class="service-details-section">
            <div class="container">
                <?php if (!empty($successMessage)): ?>
                    <div class="alert alert-success mb-4" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo h($successMessage); ?>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-lg-8">
                        <div class="service-header">
                            <div class="service-category">
                                <?php 
                                $deviceType = isset($service['category']) ? h($service['category']) : '';
                                echo isset($deviceTypesDisplay[$deviceType]) ? $deviceTypesDisplay[$deviceType] : ucfirst($deviceType); 
                                ?>
                            </div>
                            <h1 class="service-title"><?php echo h($service['name']); ?></h1>
                            <div class="service-meta">
                                <div class="meta-item">
                                    <i class="fas fa-money-bill-wave"></i>
                                    <span class="meta-label">Price:</span>
                                    <span class="meta-value"><?php echo formatPrice($service['price']); ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="far fa-clock"></i>
                                    <span class="meta-label">Duration:</span>
                                    <span class="meta-value"><?php echo isset($service['duration']) ? h($service['duration']) . ' min' : 'Not specified'; ?></span>
                                </div>
                                <div class="meta-item">
                                    <i class="fas fa-star"></i>
                                    <span class="meta-label">Rating:</span>
                                    <span class="meta-value"><?php echo number_format($averageRating, 1); ?> (<?php echo $reviewCount; ?> reviews)</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="service-image">
                            <?php 
                            $deviceType = isset($service['category']) ? h($service['category']) : '';
                            $deviceIcon = isset($deviceIcons[$deviceType]) ? $deviceIcons[$deviceType] : 'fa-tools';
                            ?>
                            <i class="fas <?php echo $deviceIcon; ?>"></i>
                        </div>
                        
                        <div class="service-description">
                            <h3>Service Description</h3>
                            <p><?php echo h($service['description']); ?></p>
                        </div>
                        
                        <?php if ($provider): ?>
                            <div class="provider-card">
                                <h3 class="mb-4">About the Technician</h3>
                                <div class="provider-header">
                                    <?php 
                                    $profileImage = !empty($provider['profile_image']) ? 'profile_images/' . h($provider['profile_image']) : 'profile_images/default.png';
                                    ?>
                                    <img src="<?php echo $profileImage; ?>" class="provider-image" alt="<?php echo h($provider['first_name'] . ' ' . $provider['last_name']); ?>">
                                    <div class="provider-info">
                                        <h4 class="provider-name">
                                            <?php echo h($provider['first_name'] . ' ' . $provider['last_name']); ?>
                                            <?php if (isset($provider['is_verified']) && (int)$provider['is_verified'] === 1): ?>
                                                <span class="verified-badge">
                                                    <i class="fas fa-check-circle"></i> Verified
                                                </span>
                                            <?php endif; ?>
                                        </h4>
                                        <?php
                                        $experience = '';
                                        if (isset($provider['experience'])) {
                                            switch ($provider['experience']) {
                                                case '0-1':
                                                    $experience = 'Less than 1 year experience';
                                                    break;
                                                case '1-3':
                                                    $experience = '1-3 years experience';
                                                    break;
                                                case '3-5':
                                                    $experience = '3-5 years experience';
                                                    break;
                                                case '5-10':
                                                    $experience = '5-10 years experience';
                                                    break;
                                                case '10+':
                                                    $experience = 'Over 10 years experience';
                                                    break;
                                                default:
                                                    $experience = 'Experience not specified';
                                            }
                                        }
                                        ?>
                                        <div class="provider-specialty"><?php echo $experience; ?></div>
                                        <?php if (isset($provider['location']) && !empty($provider['location'])): ?>
                                            <div class="provider-location">
                                                <i class="fas fa-map-marker-alt"></i> <?php echo h($provider['location']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="provider-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo number_format($averageRating, 1); ?></div>
                                        <div class="stat-label">Rating</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo $reviewCount; ?></div>
                                        <div class="stat-label">Reviews</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo isset($provider['completed_jobs']) ? (int)$provider['completed_jobs'] : 0; ?></div>
                                        <div class="stat-label">Jobs Completed</div>
                                    </div>
                                </div>
                                
                                <?php if (isset($provider['bio']) && !empty($provider['bio'])): ?>
                                    <div class="provider-bio">
                                        <h5 class="mb-2">About Me</h5>
                                        <p><?php echo h($provider['bio']); ?></p>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="text-center">
                                    <a href="technician-profile.php?id=<?php echo (int)$provider['id']; ?>" class="btn btn-outline-primary">
                                        <i class="fas fa-user me-2"></i> View Full Profile
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="booking-card">
                            <div class="booking-header">
                                <h3 class="booking-title">Book This Service</h3>
                                <div class="booking-price"><?php echo formatPrice($service['price']); ?></div>
                            </div>
                            
                            <div class="booking-meta">
                                <div class="booking-meta-item">
                                    <i class="far fa-clock"></i>
                                    <span><?php echo isset($service['duration']) ? h($service['duration']) . ' min duration' : 'Duration not specified'; ?></span>
                                </div>
                                <div class="booking-meta-item">
                                    <i class="fas fa-tools"></i>
                                    <span><?php echo isset($deviceTypesDisplay[$deviceType]) ? $deviceTypesDisplay[$deviceType] : ucfirst($deviceType); ?></span>
                                </div>
                                <?php if (isset($provider['is_verified']) && (int)$provider['is_verified'] === 1): ?>
                                    <div class="booking-meta-item">
                                        <i class="fas fa-check-circle text-info"></i>
                                        <span>Verified Technician</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($isCustomer): ?>
                                <form action="process-booking.php" method="POST" id="bookingForm">
                                    <input type="hidden" name="service_id" value="<?php echo (int)$serviceId; ?>">
                                    <input type="hidden" name="provider_id" value="<?php echo isset($provider['id']) ? (int)$provider['id'] : 0; ?>">
                                    
                                    <div class="mb-3">
                                        <label for="bookingDate" class="booking-form-label">Select Date</label>
                                        <input type="text" class="form-control booking-form-control" id="bookingDate" name="booking_date" placeholder="Select a date" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="bookingTime" class="booking-form-label">Select Time</label>
                                        <select class="form-select booking-form-control" id="bookingTime" name="booking_time" required>
                                            <option value="">Select a time</option>
                                            <option value="09:00:00">09:00 AM</option>
                                            <option value="10:00:00">10:00 AM</option>
                                            <option value="11:00:00">11:00 AM</option>
                                            <option value="12:00:00">12:00 PM</option>
                                            <option value="13:00:00">01:00 PM</option>
                                            <option value="14:00:00">02:00 PM</option>
                                            <option value="15:00:00">03:00 PM</option>
                                            <option value="16:00:00">04:00 PM</option>
                                            <option value="17:00:00">05:00 PM</option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="bookingNotes" class="booking-form-label">Notes (Optional)</label>
                                        <textarea class="form-control booking-form-control" id="bookingNotes" name="notes" rows="3" placeholder="Any specific requirements or additional information"></textarea>
                                    </div>
                                    
                                    <div class="booking-cta">
                                        <button type="submit" class="btn btn-primary btn-lg w-100">
                                            <i class="fas fa-calendar-check me-2"></i> Book Now
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="text-center py-4 border-top border-bottom mb-4">
                                    <i class="fas fa-user-lock fa-3x mb-3 text-muted"></i>
                                    <h5>Login to Book This Service</h5>
                                    <p class="text-muted">You need to be logged in as a customer to book this service.</p>
                                    <?php if ($loggedIn): ?>
                                        <p class="text-danger mb-0">Your account doesn't have customer privileges.</p>
                                    <?php else: ?>
                                        <div class="d-grid gap-2">
                                            <a href="login.php?redirect=service&id=<?php echo (int)$serviceId; ?>" class="btn btn-primary">
                                                <i class="fas fa-sign-in-alt me-2"></i> Login
                                            </a>
                                            <a href="register.php" class="btn btn-outline-primary">
                                                <i class="fas fa-user-plus me-2"></i> Register
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="text-center mt-4">
                                <button type="button" class="btn btn-outline-primary" id="contactProviderBtn">
                                    <i class="fas fa-envelope me-2"></i> Contact Provider
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Reviews Section -->
        <?php if (!empty($reviews)): ?>
            <section class="reviews-section">
                <div class="container">
                    <h2 class="section-title">Customer Reviews</h2>
                    
                    <div class="row">
                        <div class="col-lg-8 mx-auto">
                            <?php foreach ($reviews as $review): ?>
                                <div class="review-card">
                                    <div class="review-header">
                                        <?php 
                                        $reviewerImage = !empty($review['profile_image']) ? 'profile_images/' . h($review['profile_image']) : 'profile_images/default.png';
                                        ?>
                                        <img src="<?php echo $reviewerImage; ?>" class="reviewer-image" alt="<?php echo h($review['first_name'] . ' ' . $review['last_name']); ?>">
                                        <div class="reviewer-info">
                                            <div class="reviewer-name"><?php echo h($review['first_name'] . ' ' . $review['last_name']); ?></div>
                                            <div class="review-date"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></div>
                                        </div>
                                        <div class="review-rating">
                                            <?php echo formatRatingStars($review['rating']); ?>
                                        </div>
                                    </div>
                                    <div class="review-comment">
                                        <?php echo h($review['comment']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div class="text-center mt-4">
                                <a href="technician-profile.php?id=<?php echo (int)$provider['id']; ?>#reviews" class="btn btn-outline-dark">
                                    <i class="fas fa-star me-2"></i> See All Reviews
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Related Services Section -->
        <?php if (!empty($relatedServices)): ?>
            <section class="related-section">
                <div class="container">
                    <h2 class="section-title">Related Services</h2>
                    
                    <div class="row">
                        <?php foreach ($relatedServices as $relatedService): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="service-card">
                                    <?php
                                    $relDeviceType = isset($relatedService['category']) ? h($relatedService['category']) : '';
                                    $relDeviceIcon = isset($deviceIcons[$relDeviceType]) ? $deviceIcons[$relDeviceType] : 'fa-tools';
                                    ?>
                                    <div class="card-image">
                                        <i class="fas <?php echo $relDeviceIcon; ?>"></i>
                                    </div>
                                    <div class="card-content">
                                        <div class="card-category">
                                            <?php echo isset($deviceTypesDisplay[$relDeviceType]) ? $deviceTypesDisplay[$relDeviceType] : ucfirst($relDeviceType); ?>
                                        </div>
                                        <h3 class="card-title"><?php echo h($relatedService['name']); ?></h3>
                                        <div class="card-provider">
                                            <i class="fas fa-user-cog"></i> <?php echo h($relatedService['first_name'] . ' ' . $relatedService['last_name']); ?>
                                            <?php if (isset($relatedService['is_verified']) && (int)$relatedService['is_verified'] === 1): ?>
                                                <span class="verified-badge">
                                                    <i class="fas fa-check-circle"></i> Verified
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="card-description"><?php echo h($relatedService['description']); ?></div>
                                        <div class="card-meta">
                                            <div class="card-price"><?php echo formatPrice($relatedService['price']); ?></div>
                                            <div class="card-duration">
                                                <i class="far fa-clock"></i> <?php echo isset($relatedService['duration']) ? h($relatedService['duration']) . ' min' : 'N/A'; ?>
                                            </div>
                                        </div>
                                        <div class="card-rating">
                                            <i class="fas fa-star"></i> <?php echo number_format($relatedService['avg_rating'], 1); ?>
                                            <span class="ms-1 text-muted">(<?php echo (int)$relatedService['review_count']; ?> reviews)</span>
                                        </div>
                                        <div class="card-actions">
                                            <a href="service-details.php?id=<?php echo (int)$relatedService['id']; ?>" class="btn btn-primary w-100">
                                                <i class="fas fa-info-circle me-2"></i> View Details
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        
        <!-- Call to Action Section -->
        <section class="cta-section">
            <div class="container cta-content">
                <h2 class="cta-title">Need another service?</h2>
                <p class="cta-subtitle">Explore our wide range of device repair services or find a technician that matches your specific needs.</p>
                
                <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                    <a href="services.php" class="btn btn-light btn-lg">
                        <i class="fas fa-cogs me-2"></i> Browse All Services
                    </a>
                    <a href="find-technician.php" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-search me-2"></i> Find a Technician
                    </a>
                </div>
            </div>
        </section>
    <?php endif; ?>
    
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
                        <li><a href="#quoteSection">Request a Quote</a></li>
                        <?php else: ?>
                        <li><a href="<?php echo $loggedIn ? '#' : 'login.php?redirect=quote'; ?>">Request a Quote</a></li>
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
    
    <!-- Contact Provider Modal -->
    <div class="modal fade" id="contactProviderModal" tabindex="-1" aria-labelledby="contactProviderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="contactProviderModalLabel">Contact Provider</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if ($loggedIn): ?>
                        <form id="contactForm">
                            <div class="mb-3">
                                <label for="messageSubject" class="form-label">Subject</label>
                                <input type="text" class="form-control" id="messageSubject" placeholder="Query about service">
                            </div>
                            <div class="mb-3">
                                <label for="messageContent" class="form-label">Message</label>
                                <textarea class="form-control" id="messageContent" rows="5" placeholder="Your message to the provider"></textarea>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-lock fa-3x mb-3 text-muted"></i>
                            <h5>Login to Contact Provider</h5>
                            <p class="text-muted mb-4">You need to be logged in to contact the provider.</p>
                            <div class="d-grid gap-2">
                                <a href="login.php?redirect=service&id=<?php echo (int)$serviceId; ?>" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i> Login
                                </a>
                                <a href="register.php" class="btn btn-outline-primary">
                                    <i class="fas fa-user-plus me-2"></i> Register
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($loggedIn): ?>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="sendMessageBtn">
                            <i class="fas fa-paper-plane me-2"></i> Send Message
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    <!-- Date Picker JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
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
            
            // Date picker initialization
            if (document.getElementById('bookingDate')) {
                flatpickr("#bookingDate", {
                    minDate: "today",
                    dateFormat: "Y-m-d",
                    disable: [
                        function(date) {
                            // Disable weekends (0 is Sunday, 6 is Saturday)
                            return (date.getDay() === 0);
                        }
                    ]
                });
            }
            
            // Contact provider modal
            const contactProviderBtn = document.getElementById('contactProviderBtn');
            if (contactProviderBtn) {
                contactProviderBtn.addEventListener('click', function() {
                    const contactModal = new bootstrap.Modal(document.getElementById('contactProviderModal'));
                    contactModal.show();
                });
            }
            
            // Send message button
            const sendMessageBtn = document.getElementById('sendMessageBtn');
            if (sendMessageBtn) {
                sendMessageBtn.addEventListener('click', function() {
                    // Simulate sending message
                    const subject = document.getElementById('messageSubject').value;
                    const message = document.getElementById('messageContent').value;
                    
                    if (!subject || !message) {
                        alert('Please fill in both subject and message fields.');
                        return;
                    }
                    
                    // Here you would typically make an AJAX call to send the message
                    // For now, we'll just show a success message
                    alert('Your message has been sent to the provider.');
                    
                    // Close the modal
                    const contactModal = bootstrap.Modal.getInstance(document.getElementById('contactProviderModal'));
                    contactModal.hide();
                });
            }
            
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
        });
    </script>
</body>
</html>