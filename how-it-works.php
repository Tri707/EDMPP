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

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

// Check for flash messages
$errorMessage = '';
$successMessage = '';

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
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Learn how FixItNow works and how to get your devices repaired quickly and efficiently">
    <title>How It Works - FixItNow</title>
    
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
        
        /* Process Steps */
        .process-section {
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
        
        .process-timeline {
            position: relative;
            max-width: 1000px;
            margin: 0 auto;
        }
        
        .process-timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 4px;
            background-color: var(--primary-light);
            transform: translateX(-50%);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 5rem;
        }
        
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .timeline-content {
            position: relative;
            width: 45%;
            padding: 2rem;
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .timeline-content:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .timeline-item:nth-child(odd) .timeline-content {
            margin-right: auto;
        }
        
        .timeline-item:nth-child(even) .timeline-content {
            margin-left: auto;
        }
        
        .timeline-number {
            position: absolute;
            top: 0;
            left: 50%;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translate(-50%, -50%);
            z-index: 10;
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.3);
        }
        
        .timeline-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transform: translate(-50%, -50%);
            z-index: 10;
            box-shadow: 0 5px 15px var(--shadow-color);
        }
        
        .timeline-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .timeline-description {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        .timeline-action {
            margin-top: 1.5rem;
        }
        
        /* Customer Benefits */
        .benefits-section {
            padding: 5rem 0;
            background-color: var(--primary-light);
        }
        
        .benefit-card {
            padding: 2.5rem;
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            height: 100%;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            text-align: center;
        }
        
        .benefit-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .benefit-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        
        .benefit-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .benefit-description {
            font-size: 1rem;
            color: var(--text-muted);
        }
        
        /* FAQ Section */
        .faq-section {
            padding: 5rem 0;
            background-color: var(--bg-color);
        }
        
        .accordion-item {
            border: none;
            background-color: transparent;
            margin-bottom: 1rem;
        }
        
        .accordion-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            font-weight: 600;
            padding: 1.25rem;
            border-radius: 12px !important;
            box-shadow: 0 4px 12px var(--shadow-color);
        }
        
        .accordion-button:not(.collapsed) {
            background-color: var(--primary-color);
            color: white;
        }
        
        .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
        }
        
        .accordion-body {
            background-color: var(--card-bg);
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
            padding: 1.5rem;
            color: var(--text-muted);
        }
        
        /* Technician Process */
        .technician-section {
            padding: 5rem 0;
            background-color: var(--primary-light);
        }
        
        .process-card {
            padding: 2rem;
            border-radius: 16px;
            background-color: var(--card-bg);
            box-shadow: 0 8px 24px var(--shadow-color);
            height: 100%;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
        }
        
        .process-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px var(--shadow-color);
        }
        
        .process-step {
            position: absolute;
            top: -20px;
            left: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .process-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-align: center;
        }
        
        .process-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .process-description {
            font-size: 0.95rem;
            color: var(--text-muted);
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
            .process-timeline::before {
                left: 30px;
            }
            
            .timeline-content {
                width: calc(100% - 80px);
                margin-left: 80px !important;
            }
            
            .timeline-icon {
                left: 30px;
                transform: translate(-50%, -50%);
            }
            
            .timeline-number {
                left: 30px;
                transform: translate(-50%, -50%);
            }
            
            .page-title {
                font-size: 2rem;
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
            
            .section-title {
                font-size: 1.75rem;
            }
            
            .section-description {
                font-size: 1rem;
            }
            
            .timeline-content {
                padding: 1.5rem;
            }
            
            .timeline-title {
                font-size: 1.25rem;
            }
            
            .benefit-card, .process-card {
                margin-bottom: 2rem;
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
            
            .section-title {
                font-size: 1.5rem;
            }
            
            .timeline-content {
                padding: 1.25rem;
                width: calc(100% - 60px);
                margin-left: 60px !important;
            }
            
            .timeline-icon {
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
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
                    <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="<?php echo $loggedIn ? 'services.php' : 'login.php?redirect=services'; ?>" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button active">
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
                <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="<?php echo $loggedIn ? 'services.php' : 'login.php?redirect=services'; ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action active">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
                <a href="<?php echo $loggedIn ? 'contact.php' : 'login.php?redirect=contact'; ?>" class="list-group-item list-group-item-action">
                    <i class="fas fa-envelope me-2"></i> Contact
                </a>
            </div>
        </div>
    </header>
    
    <!-- Page Title Section -->
    <section class="page-title-section">
        <div class="container text-center page-title-content">
            <h1 class="page-title">How FixItNow Works</h1>
            <p class="page-description">Discover how our platform makes device repair simple, convenient, and reliable. Follow these easy steps to get your devices fixed quickly by qualified technicians.</p>
        </div>
    </section>
    
    <!-- Process Timeline Section -->
    <section class="process-section" id="customer-process">
        <div class="container">
            <h2 class="section-title">For Customers</h2>
            <p class="section-description">Getting your device fixed is simple and straightforward with FixItNow. Here's how the process works:</p>
            
            <div class="process-timeline">
                <div class="timeline-item">
                    <div class="timeline-number">1</div>
                    <div class="timeline-icon">
                        <i class="fas fa-user-plus"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Create an Account</h3>
                        <p class="timeline-description">Sign up for a free account on FixItNow. This lets you book services, track repairs, and communicate with technicians.</p>
                        <div class="timeline-action">
                            <a href="register.php" class="btn btn-primary">
                                <i class="fas fa-user-plus me-2"></i> Sign Up Now
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">2</div>
                    <div class="timeline-icon">
                        <i class="fas fa-laptop-medical"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Describe Your Device Issue</h3>
                        <p class="timeline-description">Tell us what device you need repaired and what the problem is. Add photos if available to help technicians better understand the issue.</p>
                        <ul class="mt-3">
                            <li>Choose your device type (smartphone, laptop, tablet, etc.)</li>
                            <li>Describe the problem in detail</li>
                            <li>Upload images of the damaged device (optional)</li>
                        </ul>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">3</div>
                    <div class="timeline-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Find a Technician</h3>
                        <p class="timeline-description">Browse through our network of qualified technicians based on location, specialties, ratings, and reviews. Filter to find the perfect match for your repair needs.</p>
                        <div class="timeline-action">
                            <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="btn btn-primary">
                                <i class="fas fa-search me-2"></i> Find a Technician
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">4</div>
                    <div class="timeline-icon">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Get a Quote</h3>
                        <p class="timeline-description">Request quotes from technicians for your specific repair. Compare prices, estimated completion times, and warranty options before making a decision.</p>
                        <p>You can either:</p>
                        <ul>
                            <li>Book a specific service with fixed pricing</li>
                            <li>Request a custom quote for your unique repair needs</li>
                        </ul>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">5</div>
                    <div class="timeline-icon">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Book Your Repair</h3>
                        <p class="timeline-description">Accept a quote and schedule your repair at a convenient time. Choose from available time slots offered by the technician.</p>
                        <p>After booking:</p>
                        <ul>
                            <li>Receive a confirmation notification</li>
                            <li>Get details about your appointment</li>
                            <li>Have direct messaging access to your technician</li>
                        </ul>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">6</div>
                    <div class="timeline-icon">
                        <i class="fas fa-tools"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Get Your Device Fixed</h3>
                        <p class="timeline-description">The technician will repair your device according to the agreed terms. You'll receive updates throughout the repair process.</p>
                        <p>During repair:</p>
                        <ul>
                            <li>Track repair status in real-time</li>
                            <li>Receive notifications about progress</li>
                            <li>Communicate with your technician if needed</li>
                        </ul>
                    </div>
                </div>
                
                <div class="timeline-item">
                    <div class="timeline-number">7</div>
                    <div class="timeline-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="timeline-content">
                        <h3 class="timeline-title">Review and Rate</h3>
                        <p class="timeline-description">After your repair is complete, share your experience by rating and reviewing the technician. Your feedback helps other customers make informed decisions.</p>
                        <p>Consider factors like:</p>
                        <ul>
                            <li>Quality of repair</li>
                            <li>Communication</li>
                            <li>Timeliness</li>
                            <li>Overall satisfaction</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Benefits Section -->
    <section class="benefits-section">
        <div class="container">
            <h2 class="section-title">Why Choose FixItNow</h2>
            <p class="section-description">Our platform offers numerous advantages for getting your devices repaired easily and reliably.</p>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-user-check"></i>
                        </div>
                        <h3 class="benefit-title">Verified Technicians</h3>
                        <p class="benefit-description">All technicians on our platform are thoroughly vetted and verified for their expertise, ensuring you receive high-quality service.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <h3 class="benefit-title">Service Guarantee</h3>
                        <p class="benefit-description">Our repairs come with a service guarantee, giving you peace of mind that your device will be fixed correctly.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3 class="benefit-title">Fast Turnaround</h3>
                        <p class="benefit-description">Get your devices repaired quickly with our efficient booking system and responsive technicians.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <h3 class="benefit-title">Transparent Pricing</h3>
                        <p class="benefit-description">View clear pricing information upfront with no hidden fees or surprises when you get your bill.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-comments"></i>
                        </div>
                        <h3 class="benefit-title">Direct Communication</h3>
                        <p class="benefit-description">Message your technician directly through our platform for updates and information about your repair.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-4">
                    <div class="benefit-card">
                        <div class="benefit-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <h3 class="benefit-title">Convenient Scheduling</h3>
                        <p class="benefit-description">Book repairs at times that work for your schedule with our flexible appointment system.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Technician Process -->
    <section class="technician-section" id="technician-process">
        <div class="container">
            <h2 class="section-title">For Technicians</h2>
            <p class="section-description">Join our platform as a technician to expand your business and connect with customers in need of repair services.</p>
            
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="process-card">
                        <div class="process-step">1</div>
                        <div class="process-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <h3 class="process-title">Create a Provider Account</h3>
                        <p class="process-description">Sign up for a provider account by filling out your professional information, specialties, and experience.</p>
                        <div class="mt-4">
                            <a href="join-as-provider.php" class="btn btn-primary w-100">
                                <i class="fas fa-user-plus me-2"></i> Join as Provider
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="process-card">
                        <div class="process-step">2</div>
                        <div class="process-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <h3 class="process-title">Set Up Your Services</h3>
                        <p class="process-description">Create service listings with descriptions, pricing, and availability for customers to book directly.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="process-card">
                        <div class="process-step">3</div>
                        <div class="process-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <h3 class="process-title">Manage Your Schedule</h3>
                        <p class="process-description">Set your availability and manage bookings through your personalized provider dashboard.</p>
                    </div>
                </div>
                
                <div class="col-md-6 col-lg-3">
                    <div class="process-card">
                        <div class="process-step">4</div>
                        <div class="process-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h3 class="process-title">Provide Expert Service</h3>
                        <p class="process-description">Complete repairs for customers, updating them on progress and building your reputation.</p>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-5">
                <a href="provider-resources.php" class="btn btn-lg btn-outline-dark">
                    <i class="fas fa-info-circle me-2"></i> Learn More About Becoming a Provider
                </a>
            </div>
        </div>
    </section>
    
    <!-- FAQ Section -->
    <section class="faq-section" id="faqs">
        <div class="container">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-description">Find answers to common questions about using the FixItNow platform.</p>
            
            <div class="row">
                <div class="col-lg-10 mx-auto">
                    <div class="accordion" id="faqAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1" aria-expanded="true" aria-controls="faq1">
                                    How do I know if a technician is qualified?
                                </button>
                            </h2>
                            <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    All technicians on our platform undergo a verification process to ensure they have the required skills and experience. You can also check their profile for verification badges, reviews from previous customers, and their specialized expertise before booking. Technicians with a blue checkmark have been verified by our team.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2" aria-expanded="false" aria-controls="faq2">
                                    What types of devices can I get repaired?
                                </button>
                            </h2>
                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    FixItNow supports repairs for a wide range of devices including smartphones, laptops, tablets, desktop computers, gaming consoles, and TVs. Our technicians specialize in various brands and models, so you can find the right expert for your specific device.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3" aria-expanded="false" aria-controls="faq3">
                                    How does payment work?
                                </button>
                            </h2>
                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    You can pay for services directly through our platform using secure payment methods. For standard services, you'll see the price upfront before booking. For custom repair quotes, you'll receive a detailed quote with cost breakdown before confirming. Payment is processed only after you approve the repair terms.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4" aria-expanded="false" aria-controls="faq4">
                                    What if I'm not satisfied with the repair?
                                </button>
                            </h2>
                            <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    All repairs through FixItNow come with a satisfaction guarantee. If you're not happy with the repair, you can contact the technician directly to address the issue. If the problem persists, our customer support team will help resolve the situation, which may include arranging for additional repairs or providing a refund according to our service guarantee policy.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq5" aria-expanded="false" aria-controls="faq5">
                                    How do I become a technician on FixItNow?
                                </button>
                            </h2>
                            <div id="faq5" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    To join as a technician, click on "Join as Provider" and complete the application form. You'll need to provide details about your experience, specialties, and service offerings. Our team will review your application, and upon approval, you can set up your profile and start receiving repair requests from customers in your area.
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq6" aria-expanded="false" aria-controls="faq6">
                                    Can I cancel a booking?
                                </button>
                            </h2>
                            <div id="faq6" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                <div class="accordion-body">
                                    Yes, you can cancel a booking through your account dashboard. Please note that cancellation policies may vary depending on how close to the appointment time you cancel. Most technicians offer free cancellation 24-48 hours before the scheduled appointment. For last-minute cancellations, a fee may apply depending on the technician's policy.
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-5">
                        <a href="faq.php" class="btn btn-lg btn-outline-primary">
                            <i class="fas fa-question-circle me-2"></i> View All FAQs
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Call to Action Section -->
    <section class="cta-section">
        <div class="container cta-content">
            <h2 class="cta-title">Ready to get your device fixed?</h2>
            <p class="cta-subtitle">Join thousands of satisfied customers who've successfully repaired their devices with FixItNow. Start your repair journey today!</p>
            
            <div class="d-flex flex-column flex-md-row justify-content-center gap-3">
                <?php if ($loggedIn): ?>
                    <a href="<?php echo $loggedIn ? 'find-technician.php' : 'login.php?redirect=find-technician'; ?>" class="btn btn-light btn-lg">
                        <i class="fas fa-search me-2"></i> Find a Technician
                    </a>
                    <?php if ($isCustomer): ?>
                    <a href="request-quote.php" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-file-invoice me-2"></i> Request a Quote
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                    <a href="register.php" class="btn btn-light btn-lg">
                        <i class="fas fa-user-plus me-2"></i> Create an Account
                    </a>
                    <a href="join-as-provider.php" class="btn btn-outline-light btn-lg">
                        <i class="fas fa-tools me-2"></i> Join as a Technician
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
                        <li><a href="request-quote.php">Request a Quote</a></li>
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
            
            // Smooth scroll for hash links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    const targetId = this.getAttribute('href');
                    
                    if (targetId === '#' || targetId === '') return;
                    
                    if (document.querySelector(targetId)) {
                        e.preventDefault();
                        
                        document.querySelector(targetId).scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
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
            
            // Add CSRF token to forms
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
        });
    </script>
</body>
</html>