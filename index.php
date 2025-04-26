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
$conn->set_charset("utf8mb4");

// Initialize variables
$services = [];
$popularProviders = [];
$errorMessage = '';
$successMessage = '';

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Function to safely format prices with the currency image
function formatPrice($price, $currencyImgPath = 'img/usd.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    // Use the image path for currency display
    $currencyImg = '<img src="' . h($currencyImgPath) . '" alt="USD" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}

// Get services using prepared statements for better security
try {
    $servicesQuery = "
        SELECT s.*, p.id as provider_id, p.is_verified,
               CONCAT(u.first_name, ' ', u.last_name) as provider_name, 
               COUNT(r.id) as review_count, 
               AVG(r.rating) as avg_rating
        FROM services s
        JOIN providers p ON s.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        LEFT JOIN reviews r ON p.id = r.provider_id
        WHERE s.is_active = 1 AND u.status = 'active'
        GROUP BY s.id
        ORDER BY avg_rating DESC, review_count DESC
        LIMIT 6
    ";
    
    $servicesResult = $conn->query($servicesQuery);
    
    if ($servicesResult && $servicesResult->num_rows > 0) {
        while ($row = $servicesResult->fetch_assoc()) {
            $services[] = $row;
        }
    }
    
    // Get popular providers using prepared statements
    $providersQuery = "
        SELECT p.*, 
               u.first_name, u.last_name, u.profile_image,
               COUNT(r.id) as review_count, 
               AVG(r.rating) as avg_rating,
               COUNT(DISTINCT b.id) as booking_count
        FROM providers p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN reviews r ON p.id = r.provider_id
        LEFT JOIN bookings b ON p.id = b.provider_id AND b.status IN ('completed', 'confirmed')
        WHERE u.status = 'active'
        GROUP BY p.id
        ORDER BY avg_rating DESC, booking_count DESC, review_count DESC
        LIMIT 6
    ";
    
    $providersResult = $conn->query($providersQuery);
    
    if ($providersResult && $providersResult->num_rows > 0) {
        while ($row = $providersResult->fetch_assoc()) {
            $popularProviders[] = $row;
        }
    }

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
    <meta name="description" content="FixItNow - Service Booking Platform for Repairs and Maintenance">
    <title>FixItNow - Tech Repair Service Booking Platform</title>
    
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
        
        /* Hero Section */
        .hero-section {
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            padding: 5rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
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
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .hero-title {
            font-size: 3.2rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            letter-spacing: -0.5px;
        }
        
        .hero-subtitle {
            font-size: 1.3rem;
            margin-bottom: 2rem;
            opacity: 0.95;
            line-height: 1.6;
        }
        
        /* Button Styles */
        .btn {
            transition: all 0.3s ease;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.18);
        }
        
        .btn-light {
            background: #ffffff;
            color: var(--primary-color);
        }
        
        .btn-outline-light {
            border: 2px solid rgba(255, 255, 255, 0.8);
            color: #ffffff;
        }
        
        .btn-outline-light:hover {
            background: rgba(255, 255, 255, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Search Form */
        .search-form {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 1.8rem;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
        }
        
        .search-form h3 {
            font-weight: 700;
            font-size: 1.4rem;
            text-align: center;
            margin-bottom: 1.5rem;
            color: var(--text-color);
        }
        
        /* Features Section */
        .features-section {
            padding: 5rem 0;
            background-color: var(--bg-color);
        }
        
        .section-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .section-description {
            font-size: 1.1rem;
            color: var(--text-muted);
            text-align: center;
            max-width: 800px;
            margin: 0 auto 3rem;
        }
        
        .feature-card {
            text-align: center;
            padding: 2.5rem 1.5rem;
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .feature-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 2rem;
        }
        
        .feature-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        /* Services Section */
        .services-section {
            padding: 5rem 0;
            background-color: var(--bg-color);
        }
        
        .service-card {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
        }
        
        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .service-content {
            padding: 1.8rem;
            height: 100%;
        }
        
        .service-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .service-provider {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .service-description {
            font-size: 0.95rem;
            margin-bottom: 1.5rem;
            height: 60px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        
        .service-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2rem;
        }
        
        .service-price {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-color);
        }
        
        .service-rating {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
        }
        
        .service-rating i {
            color: #ffc107;
            margin-right: 0.25rem;
        }
        
        /* Providers Section */
        .providers-section {
            padding: 5rem 0;
            background-color: var(--primary-light);
        }
        
        .provider-card {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            text-align: center;
            padding-top: 2.5rem;
            padding-bottom: 2rem;
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
            margin-bottom: 1.2rem;
        }
        
        .provider-stats {
            display: flex;
            justify-content: center;
            gap: 1.8rem;
            margin-bottom: 1.8rem;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .stat-value {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 0.2rem;
        }

        /* Verification badge */
        .verified-badge {
            color: var(--primary-color);
        }
        
        /* How It Works Section */
        .how-it-works-section {
            padding: 5rem 0;
            background-color: var(--bg-color);
        }
        
        .step-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 2.5rem 1.5rem;
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            height: 100%;
            position: relative;
        }
        
        .step-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .step-number {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 1.8rem;
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.3);
        }
        
        .step-title {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        /* Quote Request Section */
        .quote-section {
            padding: 5rem 0;
            background-color: var(--primary-light);
        }
        
        .quote-card {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 12px 36px var(--shadow-color);
        }
        
        .quote-form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .quote-form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }
        
        /* Testimonials Section */
        .testimonials-section {
            padding: 5rem 0;
            background-color: var(--primary-light);
        }
        
        .testimonial-card {
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            padding: 2.5rem;
            height: 100%;
            position: relative;
        }
        
        .testimonial-quote {
            font-size: 4rem;
            position: absolute;
            top: -20px;
            left: 20px;
            color: var(--primary-color);
            opacity: 0.2;
        }
        
        .testimonial-text {
            font-size: 1.1rem;
            font-style: italic;
            margin-bottom: 1.5rem;
        }
        
        .testimonial-author {
            display: flex;
            align-items: center;
        }
        
        .author-image {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
        }
        
        .author-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .author-rating {
            color: #ffc107;
        }
        
        /* Call to Action Section */
        .cta-section {
            padding: 5rem 0;
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
        
        /* Device Category Cards */
        .device-category {
            display: block;
            text-align: center;
            padding: 1.8rem;
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--text-color);
            height: 100%;
        }
        
        .device-category:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.12);
            color: var(--primary-color);
        }
        
        .device-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .device-name {
            font-weight: 600;
            font-size: 1.1rem;
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
        
        /* Custom Dashboard Buttons */
        .dashboard-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            font-weight: 600;
            border-radius: 8px;
        }
        
        /* Enhanced Quote Request Section */
        .quote-section {
            padding: 5rem 0;
            background-color: #f0e6ff; /* Light purple background */
            position: relative;
        }

        .section-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.5rem;
        }

        .section-description {
            color: #666;
            font-size: 1.1rem;
            max-width: 800px;
            margin: 0 auto 2.5rem;
        }

        .quote-card {
            background-color: #fff;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            border: none;
        }

        .quote-header {
            background-color: #7952b3;
            color: white;
            padding: 1.25rem;
            font-size: 1.2rem;
            font-weight: 600;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }

        .quote-body {
            padding: 2rem;
        }

        .quote-form-group {
            margin-bottom: 1.5rem;
        }

        .quote-form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.95rem;
            color: #444;
        }

        .quote-form-control {
            width: 100%;
            padding: 0.8rem 1rem;
            font-size: 1rem;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 8px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .quote-form-control:focus {
            border-color: #7952b3;
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(121, 82, 179, 0.25);
        }

        .quote-form-control::placeholder {
            color: #adb5bd;
            opacity: 1;
        }

        .file-upload-wrapper {
            position: relative;
        }

        .form-help-text {
            margin-top: 0.5rem;
            font-size: 0.85rem;
            color: #6c757d;
            display: flex;
            align-items: center;
        }

        .help-icon {
            display: inline-block;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background-color: #007bff;
            margin-right: 8px;
        }

        .quote-submit-wrapper {
            text-align: center;
            margin-top: 2rem;
        }

        .quote-submit-btn {
            background-color: #7952b3;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0.8rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quote-submit-btn:hover {
            background-color: #6941a0;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.4);
        }
        
        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .hero-title {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 2rem;
            }
            
            .feature-card, .service-card, .provider-card, .step-card {
                margin-bottom: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .hero-title {
                font-size: 2rem;
            }
            
            .hero-section {
                padding: 3rem 0;
            }
            
            .section-title {
                font-size: 1.75rem;
            }
            
            .section-description {
                font-size: 1rem;
            }
            
            .feature-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .feature-title {
                font-size: 1.25rem;
            }
            
            .cta-title {
                font-size: 2rem;
            }
            
            .cta-subtitle {
                font-size: 1.1rem;
            }
            
            .quote-body {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .hero-title {
                font-size: 1.75rem;
            }
            
            .hero-subtitle {
                font-size: 1rem;
            }
            
            .section-title {
                font-size: 1.5rem;
            }
            
            .footer-column {
                margin-bottom: 2rem;
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
                    <a href="index.php" class="nav-button active">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                    <a href="<?php echo $loggedIn ? 'contact.php' : 'login.php?redirect=contact'; ?>" class="nav-button">
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
                        <!-- User is not logged in -->
                        <a href="login.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="register.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="container-fluid d-lg-none mt-3 d-none" id="mobileMenu">
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action active">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="find-technician.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
                <a href="<?php echo $loggedIn ? 'contact.php' : 'login.php?redirect=contact'; ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-envelope me-2"></i> Contact
                </a>
            </div>
        </div>
    </header>
    
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="row align-items-center hero-content">
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <h1 class="hero-title">Fast and Reliable Tech Repair Services</h1>
                    <p class="hero-subtitle">Find expert technicians for all your device repair needs. Book a service today and get your devices fixed in no time.</p>
                    <div class="d-flex flex-wrap gap-3">
                        <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="btn btn-light">
                            <i class="fas fa-search me-2"></i> Find a Technician
                        </a>
                        <a href="<?php echo $loggedIn ? 'services.php' : 'login.php?redirect=services'; ?>" class="btn btn-outline-light">
                            <i class="fas fa-cogs me-2"></i> Browse Services
                        </a>
                        <?php if ($isCustomer): ?>
                        <a href="#quoteSection" class="btn btn-outline-light">
                            <i class="fas fa-file-invoice me-2"></i> Request a Quote
                        </a>
                        <?php else: ?>
                        <a href="<?php echo $loggedIn ? '#' : 'login.php?redirect=quote'; ?>" class="btn btn-outline-light">
                            <i class="fas fa-file-invoice me-2"></i> <?php echo $loggedIn ? 'Customer Only Services' : 'Login for Quotes'; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="search-form">
                        <h3 class="mb-4">What device do you need to fix?</h3>
                        <div class="row g-4">
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=smartphone' : 'login.php?redirect=find-technician&device=smartphone'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <div class="device-name">Smartphone</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=laptop' : 'login.php?redirect=find-technician&device=laptop'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-laptop"></i>
                                    </div>
                                    <div class="device-name">Laptop</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=tablet' : 'login.php?redirect=find-technician&device=tablet'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-tablet-alt"></i>
                                    </div>
                                    <div class="device-name">Tablet</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=desktop' : 'login.php?redirect=find-technician&device=desktop'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-desktop"></i>
                                    </div>
                                    <div class="device-name">Desktop</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=gaming' : 'login.php?redirect=find-technician&device=gaming'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-gamepad"></i>
                                    </div>
                                    <div class="device-name">Gaming</div>
                                </a>
                            </div>
                            <div class="col-6 col-md-4">
                                <a href="<?php echo $loggedIn ? 'find-technician.php?device=tv' : 'login.php?redirect=find-technician&device=tv'; ?>" class="device-category">
                                    <div class="device-icon">
                                        <i class="fas fa-tv"></i>
                                    </div>
                                    <div class="device-name">TV</div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <h2 class="section-title">Why Choose FixItNow</h2>
            <p class="section-description">We connect you with expert technicians to get your devices fixed quickly and reliably. Here's what makes us different.</p>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h3 class="feature-title">Verified Experts</h3>
                        <p>All our technicians are thoroughly vetted and verified for their expertise and professionalism.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3 class="feature-title">Fast Repairs</h3>
                        <p>Get quick responses and fast repair services, often on the same day or within 24 hours.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3 class="feature-title">Service Guarantee</h3>
                        <p>Our services come with a guarantee to ensure your satisfaction and device functionality.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <h3 class="feature-title">Competitive Prices</h3>
                        <p>Get quality repairs at competitive prices with transparent pricing and no hidden fees.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Services Section -->
    <section class="services-section">
        <div class="container">
            <h2 class="section-title">Featured Services</h2>
            <p class="section-description">Browse through our most popular repair and maintenance services.</p>
            
            <div class="row g-4">
                <?php if (empty($services)): ?>
                    <div class="col-12 text-center">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> No services available at the moment. Please check back later.
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($services as $service): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="service-card">
                                <div class="service-content">
                                    <h3 class="service-title"><?php echo h($service['name']); ?></h3>
                                    <p class="service-provider">
                                        <i class="fas fa-user-cog me-1"></i> 
                                        <?php echo h($service['provider_name']); ?>
                                        <?php if (isset($service['is_verified']) && (int)$service['is_verified'] === 1): ?>
                                            <i class="fas fa-tools text-primary ms-1" title="Verified Technician"></i>
                                        <?php endif; ?>
                                    </p>
                                    <p class="service-description">
                                        <?php echo h($service['description']); ?>
                                    </p>
                                    <div class="service-meta">
                                        <div class="service-price">
                                            <?php echo formatPrice($service['price']); ?>
                                        </div>
                                        <div class="service-rating">
                                            <i class="fas fa-star"></i>
                                            <?php echo number_format($service['avg_rating'] ?? 0, 1); ?>
                                            (<?php echo (int)$service['review_count']; ?> reviews)
                                        </div>
                                    </div>
                                    <a href="<?php echo $loggedIn ? 'service-details.php?id=' . (int)$service['id'] : 'login.php?redirect=service-details&id=' . (int)$service['id']; ?>" class="btn btn-primary w-100">View Details</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="text-center mt-5">
                <a href="<?php echo $loggedIn ? 'services.php' : 'login.php?redirect=services'; ?>" class="btn btn-lg btn-outline-primary">
                    View All Services <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>
    
    <!-- How It Works Section -->
    <section class="how-it-works-section">
        <div class="container">
            <h2 class="section-title">How It Works</h2>
            <p class="section-description">Getting your devices fixed has never been easier. Just follow these simple steps.</p>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="step-card">
                        <div class="step-number">1</div>
                        <h3 class="step-title">Choose a Device</h3>
                        <p>Select the type of device you need to repair from our wide range of supported devices.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="step-card">
                        <div class="step-number">2</div>
                        <h3 class="step-title">Find a Technician</h3>
                        <p>Browse through our verified technicians and choose the one that best fits your needs.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="step-card">
                        <div class="step-number">3</div>
                        <h3 class="step-title">Book a Service</h3>
                        <p>Schedule an appointment at your convenient time or request a quote for your repair.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="step-card">
                        <div class="step-number">4</div>
                        <h3 class="step-title">Get It Fixed</h3>
                        <p>Meet the technician, get your device fixed, and enjoy a like-new performance.</p>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <a href="how-it-works.php" class="btn btn-lg btn-outline-primary">
                    Learn More <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>
    
    <!-- Request a Quote Section - Only visible for customers -->
    <?php if ($isCustomer): ?>
    <section class="quote-section" id="quoteSection">
        <div class="container">
            <h2 class="section-title text-center mb-2">Request a Repair Quote</h2>
            <p class="section-description text-center mb-5">Get a free estimate for your device repair from our expert technicians.</p>
            
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-8 col-xl-7">
                    <div class="quote-card">
                        <div class="quote-header">
                            <i class="fas fa-file-invoice me-2"></i> Request a Free Repair Quote
                        </div>
                        <div class="quote-body">
                            <form action="process_quote_request.php" method="POST" enctype="multipart/form-data" class="quote-form">
                                <input type="hidden" name="_token" value="<?php echo h($_SESSION['csrf_token']); ?>">
                                
                                <div class="quote-form-group">
                                    <label for="device_type">Device Type <span class="text-danger">*</span></label>
                                    <select class="form-select quote-form-control" name="device_type" id="device_type" required>
                                        <option value="">Select Device Type</option>
                                        <option value="smartphone">Smartphone</option>
                                        <option value="laptop">Laptop</option>
                                        <option value="tablet">Tablet</option>
                                        <option value="desktop">Desktop Computer</option>
                                        <option value="gaming">Gaming Console</option>
                                        <option value="tv">Television</option>
                                    </select>
                                </div>
                                
                                <div class="quote-form-group">
                                    <label for="device_brand">Device Brand</label>
                                    <input type="text" class="form-control quote-form-control" name="device_brand" id="device_brand" placeholder="e.g., Apple, Samsung, Dell">
                                </div>
                                
                                <div class="quote-form-group">
                                    <label for="device_model">Device Model</label>
                                    <input type="text" class="form-control quote-form-control" name="device_model" id="device_model" placeholder="e.g., iPhone 13, Galaxy S22, XPS 15">
                                </div>
                                
                                <div class="quote-form-group">
                                    <label for="issue_description">Description of the Issue <span class="text-danger">*</span></label>
                                    <textarea class="form-control quote-form-control" name="issue_description" id="issue_description" rows="4" placeholder="Please describe the problem in detail" required></textarea>
                                </div>
                                
                                <div class="quote-form-group">
                                    <label for="additional_info">Additional Information</label>
                                    <textarea class="form-control quote-form-control" name="additional_info" id="additional_info" rows="3" placeholder="Any other details that might help the technician"></textarea>
                                </div>
                                
                                <div class="quote-form-group">
                                    <label for="device_image">Upload Device Image</label>
                                    <div class="file-upload-wrapper">
                                        <input type="file" class="form-control quote-form-control" name="device_image" id="device_image" accept="image/*">
                                    </div>
                                    <div class="form-help-text">
                                        <span class="help-icon"></span> A clear image of the issue helps the technician provide a more accurate quote.
                                    </div>
                                </div>
                                
                                <div class="quote-submit-wrapper">
                                    <button type="submit" class="btn quote-submit-btn">
                                        <i class="fas fa-paper-plane me-2"></i> Submit Quote Request
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php else: ?>
    <!-- Alternative section for non-customers -->
    <section class="quote-section" id="quoteSection">
        <div class="container">
            <h2 class="section-title text-center mb-2">Request a Repair Quote</h2>
            <div class="row justify-content-center">
                <div class="col-md-10 col-lg-8 col-xl-7">
                    <div class="quote-card">
                        <div class="quote-header">
                            <i class="fas fa-file-invoice me-2"></i> Customer Only Feature
                        </div>
                        <div class="quote-body text-center py-5">
                            <?php if ($loggedIn): ?>
                                <i class="fas fa-user-lock fa-4x text-muted mb-4"></i>
                                <h4>This feature is only available for customers</h4>
                                <p class="mb-4">To request repair quotes, you need a customer account.</p>
                            <?php else: ?>
                                <i class="fas fa-user-lock fa-4x text-muted mb-4"></i>
                                <h4>Login Required</h4>
                                <p class="mb-4">Please login or create a customer account to request repair quotes.</p>
                                <div class="d-flex justify-content-center gap-3">
                                    <a href="login.php?redirect=quote" class="btn btn-primary">
                                        <i class="fas fa-sign-in-alt me-2"></i> Login
                                    </a>
                                    <a href="register.php" class="btn btn-outline-primary">
                                        <i class="fas fa-user-plus me-2"></i> Create Account
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- Providers Section -->
    <section class="providers-section">
        <div class="container">
            <h2 class="section-title">Our Top Technicians</h2>
            <p class="section-description">Meet our highly-rated technicians who are ready to help you with any device repair.</p>
            
            <div class="row g-4">
                <?php if (empty($popularProviders)): ?>
                    <div class="col-12 text-center">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> No technicians available at the moment. Please check back later.
                        </div>
                    </div>
                <?php else: ?>
                    <?php 
                    // Limit to top 3 providers
                    $topProviders = array_slice($popularProviders, 0, 3);
                    foreach ($topProviders as $provider): 
                    ?>
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
                                ?>
                                
                                <img src="<?php echo $profileImage; ?>" class="provider-image" alt="<?php echo $providerName; ?>">
                                <h3 class="provider-name">
                                    <?php echo $providerName; ?>
                                    <?php if (isset($provider['is_verified']) && (int)$provider['is_verified'] === 1): ?>
                                        <i class="fas fa-tools text-primary ms-1" title="Verified Technician"></i>
                                    <?php endif; ?>
                                </h3>
                                <p class="provider-specialty"><?php echo $specialtiesText; ?></p>
                                
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
                                
                                <a href="<?php echo $loggedIn ? 'technician-profile.php?id=' . (int)$provider['id'] : 'login.php?redirect=technician-profile&id=' . (int)$provider['id']; ?>" class="btn btn-primary mt-2">View Profile</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div class="text-center mt-5">
                <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="btn btn-lg btn-outline-dark">
                    View All Technicians <i class="fas fa-arrow-right ms-2"></i>
                </a>
            </div>
        </div>
    </section>
    
    <!-- Call to Action Section -->
    <section class="cta-section">
        <div class="container cta-content">
            <h2 class="cta-title">Ready to get your device fixed?</h2>
            <p class="cta-subtitle">Join thousands of satisfied customers who've successfully repaired their devices with us. Start your repair journey today!</p>
            
            <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                <?php if ($loggedIn): ?>
                    <a href="find-technician.php" class="btn btn-light btn-lg">
                        <i class="fas fa-search me-2"></i> Find a Technician
                    </a>
                    <?php if ($isCustomer): ?>
                    <a href="#quoteSection" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-file-invoice me-2"></i> Request a Quote
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="register.php" class="btn btn-light btn-lg">
                        <i class="fas fa-user-plus me-2"></i> Create an Account
                    </a>
                    <a href="login.php?redirect=quote" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-sign-in-alt me-2"></i> Log In
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
                        <li><a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>">Find Technician</a></li>
                        <li><a href="<?php echo $loggedIn ? 'services.php' : 'login.php?redirect=services'; ?>">Services</a></li>
                        <li><a href="how-it-works.php">How It Works</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="<?php echo $loggedIn ? 'contact.php' : 'login.php?redirect=contact'; ?>">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Customers</h4>
                    <ul class="footer-links">
                        <li><a href="register.php">Sign Up</a></li>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="<?php echo $loggedIn ? 'book-service.php' : 'login.php?redirect=book-service'; ?>">Book a Service</a></li>
                        <?php if ($isCustomer): ?>
                        <li><a href="#quoteSection">Request a Quote</a></li>
                        <?php else: ?>
                        <li><a href="<?php echo $loggedIn ? '#' : 'login.php?redirect=quote'; ?>">Request a Quote</a></li>
                        <?php endif; ?>
                        <li><a href="faq.php">FAQ</a></li>
                        <li><a href="<?php echo $loggedIn ? 'support.php' : 'login.php?redirect=support'; ?>">Support</a></li>
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
            
            // Smooth scroll for hash links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const targetId = this.getAttribute('href');
                    if (targetId === '#' || !targetId) return;
                    
                    const targetElement = document.querySelector(targetId);
                    
                    if (targetElement) {
                        window.scrollTo({
                            top: targetElement.offsetTop,
                            behavior: 'smooth'
                        });
                    } else if (targetId === '#quoteSection' && !document.getElementById('quoteSection')) {
                        // If quote section doesn't exist or user is not logged in, redirect to login
                        window.location.href = 'login.php?redirect=quote';
                    }
                });
            });
        });
    </script>
</body>
</html>