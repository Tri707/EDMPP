
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

// Redirect if not logged in
if (!$loggedIn) {
    $_SESSION['error_message'] = 'Please log in to view quote confirmations.';
    header('Location: login.php');
    exit;
}

// Redirect if logged in as provider or admin
if ($userRole !== 'customer') {
    $_SESSION['error_message'] = 'Only customers can view quote confirmations.';
    header('Location: index.php');
    exit;
}

// Check if request ID is provided
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($requestId <= 0) {
    $_SESSION['error_message'] = 'Invalid quote request ID.';
    header('Location: customer/dashboard.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$requestInfo = null;
$requestImages = [];
$errorMessage = '';
$successMessage = '';
$deviceTypes = [
    'smartphone' => 'Smartphone',
    'laptop' => 'Laptop',
    'tablet' => 'Tablet',
    'desktop' => 'Desktop',
    'gaming' => 'Gaming Console',
    'tv' => 'TV/Monitor'
];

// Get request info
try {
    $requestQuery = "
        SELECT qr.*, u.first_name, u.last_name, u.email, u.phone
        FROM quote_requests qr
        JOIN users u ON qr.customer_id = u.id
        WHERE qr.id = ? AND qr.customer_id = ?
    ";
    
    $requestStmt = $conn->prepare($requestQuery);
    $requestStmt->bind_param("ii", $requestId, $userId);
    $requestStmt->execute();
    $requestResult = $requestStmt->get_result();
    
    if ($requestResult && $requestResult->num_rows > 0) {
        $requestInfo = $requestResult->fetch_assoc();
        
        // Get request images
        $imagesQuery = "
            SELECT * FROM quote_request_media
            WHERE request_id = ?
            ORDER BY id ASC
        ";
        
        $imagesStmt = $conn->prepare($imagesQuery);
        $imagesStmt->bind_param("i", $requestId);
        $imagesStmt->execute();
        $imagesResult = $imagesStmt->get_result();
        
        if ($imagesResult && $imagesResult->num_rows > 0) {
            while ($row = $imagesResult->fetch_assoc()) {
                $requestImages[] = $row;
            }
        }
    } else {
        $errorMessage = 'Quote request not found or you do not have permission to view it.';
    }
} catch (Exception $e) {
    error_log("Request query error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching quote request information. Please try again later.";
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

// Close database connection when done
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="FixItNow - Quote Request Confirmation">
    <title>Quote Request Confirmation - FixItNow</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    
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
            --header-bg: #212529;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #212529;          /* Footer background */
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
            font-family: 'Roboto', sans-serif;
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
            box-shadow: 0 2px 15px var(--shadow-color);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .logo-text:hover {
            transform: scale(1.02);
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
            padding: 0.6rem 1.1rem;
            border-radius: 0.375rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            transform: translateY(-1px);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 2px 8px rgba(121, 82, 179, 0.4);
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
            margin-right: 10px;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #fff;
        }
        
        /* Confirmation Page Styles */
        .confirmation-section {
            padding: 5rem 0;
        }
        
        .confirmation-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 5px 20px var(--shadow-color);
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .confirmation-icon {
            width: 100px;
            height: 100px;
            background-color: var(--accent-light);
            color: var(--accent-color);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1.5rem;
        }
        
        .confirmation-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .confirmation-message {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            color: var(--text-muted);
        }
        
        .quote-details-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 5px 20px var(--shadow-color);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .quote-details-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
        }
        
        .quote-detail-item {
            margin-bottom: 1.25rem;
        }
        
        .quote-detail-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }
        
        .quote-detail-value {
            font-weight: 400;
            color: var(--text-color);
        }
        
        .quote-images {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .quote-image {
            width: 100px;
            height: 100px;
            border-radius: 0.5rem;
            object-fit: cover;
            border: 2px solid var(--border-color);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .quote-image:hover {
            transform: scale(1.05);
            border-color: var(--primary-color);
        }
        
        .next-steps-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 5px 20px var(--shadow-color);
            padding: 2rem;
        }
        
        .next-steps-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--primary-color);
        }
        
        .step-item {
            display: flex;
            margin-bottom: 1.5rem;
        }
        
        .step-number {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .step-content h4 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .step-content p {
            color: var(--text-muted);
            margin-bottom: 0;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2.5rem;
            justify-content: center;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.4);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(121, 82, 179, 0.4);
        }
        
        /* Modal Styles */
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 10px 30px var(--shadow-color);
        }
        
        .modal-header {
            border-bottom-color: var(--border-color);
        }
        
        .modal-footer {
            border-top-color: var(--border-color);
        }
        
        /* Footer Styles (Same as index.php) */
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
            margin-bottom: 0.75rem;
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
            .confirmation-title {
                font-size: 1.75rem;
            }
            
            .quote-details-title, .next-steps-title {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .confirmation-section {
                padding: 3rem 0;
            }
            
            .confirmation-icon {
                width: 80px;
                height: 80px;
                font-size: 2.5rem;
            }
            
            .confirmation-title {
                font-size: 1.5rem;
            }
            
            .confirmation-message {
                font-size: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
                gap: 0.75rem;
            }
        }
        
        @media (max-width: 576px) {
            .confirmation-card, .quote-details-card, .next-steps-card {
                padding: 1.5rem;
            }
            
            .quote-image {
                width: 70px;
                height: 70px;
            }
        }
    </style>
</head>

<body>
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
                    <a href="services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="request-quote.php" class="nav-button">
                        <i class="fas fa-file-invoice-dollar"></i> Request Quote
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
                            <a href="customer/dashboard.php" class="btn btn-primary me-2">
                                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'provider'): ?>
                            <a href="provider/dashboard.php" class="btn btn-primary me-2">
                                <i class="fas fa-tachometer-alt me-1"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'admin'): ?>
                            <a href="admin/dashboard.php" class="btn btn-primary me-2">
                                <i class="fas fa-tachometer-alt me-1"></i> Admin Panel
                            </a>
                        <?php endif; ?>
                        
                        <a href="logout.php" class="btn btn-outline-light">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    <?php else: ?>
                        <!-- User is not logged in (shouldn't reach here due to redirect) -->
                        <a href="login.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="register.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Register
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
                <a href="services.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="request-quote.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-file-invoice-dollar me-2"></i> Request Quote
                </a>
            </div>
        </div>
    </header>
    
    <!-- Main Content -->
    <section class="confirmation-section">
        <div class="container">
            <!-- Display error message if any -->
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger mb-4">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                
                <div class="text-center mt-4">
                    <a href="customer/dashboard.php" class="btn btn-primary">
                        <i class="fas fa-tachometer-alt me-2"></i> Go to Dashboard
                    </a>
                </div>
            <?php else: ?>
                <!-- Confirmation Card -->
                <div class="confirmation-card">
                    <div class="confirmation-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h1 class="confirmation-title">Quote Request Submitted!</h1>
                    <p class="confirmation-message">
                        Thank you for submitting your quote request. Our technicians will review your request and send you personalized quotes soon.
                    </p>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> Your quote request ID is: <strong>#<?php echo $requestId; ?></strong>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-lg-7">
                        <!-- Quote Details Card -->
                        <?php if ($requestInfo): ?>
                            <div class="quote-details-card">
                                <h2 class="quote-details-title">Request Details</h2>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="quote-detail-item">
                                            <div class="quote-detail-label">Device Type</div>
                                            <div class="quote-detail-value">
                                                <?php 
                                                $deviceType = $requestInfo['device_type'] ?? '';
                                                echo isset($deviceTypes[$deviceType]) ? htmlspecialchars($deviceTypes[$deviceType]) : htmlspecialchars($deviceType);
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="quote-detail-item">
                                            <div class="quote-detail-label">Status</div>
                                            <div class="quote-detail-value">
                                                <span class="badge bg-primary">
                                                    <?php 
                                                    $status = $requestInfo['status'] ?? '';
                                                    echo ucfirst(htmlspecialchars($status));
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="quote-detail-item">
                                            <div class="quote-detail-label">Device Brand</div>
                                            <div class="quote-detail-value">
                                                <?php echo !empty($requestInfo['device_brand']) ? htmlspecialchars($requestInfo['device_brand']) : 'Not specified'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <div class="quote-detail-item">
                                            <div class="quote-detail-label">Device Model</div>
                                            <div class="quote-detail-value">
                                                <?php echo !empty($requestInfo['device_model']) ? htmlspecialchars($requestInfo['device_model']) : 'Not specified'; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="quote-detail-item">
                                    <div class="quote-detail-label">Device Condition</div>
                                    <div class="quote-detail-value">
                                        <?php 
                                        $condition = $requestInfo['device_condition'] ?? '';
                                        switch ($condition) {
                                            case 'good':
                                                echo 'Good - Minor issues, device works';
                                                break;
                                            case 'fair':
                                                echo 'Fair - Multiple issues, device functions';
                                                break;
                                            case 'poor':
                                                echo 'Poor - Serious issues, limited functionality';
                                                break;
                                            case 'not_working':
                                                echo 'Not Working - Device does not turn on/function';
                                                break;
                                            default:
                                                echo htmlspecialchars($condition);
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="quote-detail-item">
                                    <div class="quote-detail-label">Issue Description</div>
                                    <div class="quote-detail-value">
                                        <?php echo nl2br(htmlspecialchars($requestInfo['issue_description'] ?? '')); ?>
                                    </div>
                                </div>
                                
                                <div class="quote-detail-item">
                                    <div class="quote-detail-label">Urgency</div>
                                    <div class="quote-detail-value">
                                        <?php 
                                        $urgency = $requestInfo['urgency'] ?? '';
                                        switch ($urgency) {
                                            case 'low':
                                                echo 'Low - Can wait for a week or more';
                                                break;
                                            case 'medium':
                                                echo 'Medium - Need it fixed within a few days';
                                                break;
                                            case 'high':
                                                echo 'High - Need it fixed as soon as possible';
                                                break;
                                            case 'emergency':
                                                echo 'Emergency - Need immediate repair';
                                                break;
                                            default:
                                                echo htmlspecialchars($urgency);
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($requestInfo['additional_info'])): ?>
                                    <div class="quote-detail-item">
                                        <div class="quote-detail-label">Additional Information</div>
                                        <div class="quote-detail-value">
                                            <?php echo nl2br(htmlspecialchars($requestInfo['additional_info'])); ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($requestImages)): ?>
                                    <div class="quote-detail-item">
                                        <div class="quote-detail-label">Uploaded Images</div>
                                        <div class="quote-images">
                                            <?php foreach ($requestImages as $image): ?>
                                                <img src="<?php echo htmlspecialchars($image['file_path']); ?>" 
                                                     alt="Device Image" 
                                                     class="quote-image"
                                                     data-bs-toggle="modal"
                                                     data-bs-target="#imageModal"
                                                     data-image-path="<?php echo htmlspecialchars($image['file_path']); ?>"
                                                     data-image-name="<?php echo htmlspecialchars($image['original_name']); ?>">
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="quote-detail-item">
                                    <div class="quote-detail-label">Date Submitted</div>
                                    <div class="quote-detail-value">
                                        <?php 
                                        $createdAt = isset($requestInfo['created_at']) ? new DateTime($requestInfo['created_at']) : null;
                                        echo $createdAt ? $createdAt->format('F j, Y \a\t g:i A') : 'N/A'; 
                                        ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-lg-5">
                        <!-- Next Steps Card -->
                        <div class="next-steps-card">
                            <h2 class="next-steps-title">What Happens Next?</h2>
                            
                            <div class="step-item">
                                <div class="step-number">1</div>
                                <div class="step-content">
                                    <h4>Technical Review</h4>
                                    <p>Our technicians will review your request details and the provided information about your device.</p>
                                </div>
                            </div>
                            
                            <div class="step-item">
                                <div class="step-number">2</div>
                                <div class="step-content">
                                    <h4>Receive Quotes</h4>
                                    <p>Within 24-48 hours, you'll receive repair quotes with pricing, estimated timelines, and service details.</p>
                                </div>
                            </div>
                            
                            <div class="step-item">
                                <div class="step-number">3</div>
                                <div class="step-content">
                                    <h4>Choose a Quote</h4>
                                    <p>Compare the quotes received and select the one that best fits your needs and budget.</p>
                                </div>
                            </div>
                            
                            <div class="step-item">
                                <div class="step-number">4</div>
                                <div class="step-content">
                                    <h4>Schedule Repair</h4>
                                    <p>Once you accept a quote, you can schedule the repair at a convenient time for you.</p>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-4">
                                <i class="fas fa-bell me-2"></i> You will receive notifications when technicians respond to your request. Make sure to check your dashboard regularly.
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <a href="customer/dashboard.php" class="btn btn-primary">
                        <i class="fas fa-tachometer-alt me-2"></i> Go to Dashboard
                    </a>
                    <a href="request-quote.php" class="btn btn-outline-primary">
                        <i class="fas fa-file-invoice-dollar me-2"></i> Submit Another Request
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="imageModalLabel">Image Preview</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body text-center">
                    <img src="" id="modalImage" class="img-fluid" alt="Full size image">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
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
                        <a href="#" class="social-icon">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-icon">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-icon">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-icon">
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
                    &copy; 2023 FixItNow. All rights reserved.
                </div>
                <div>
                    <a href="privacy.php" class="me-3 text-white-50">Privacy Policy</a>
                    <a href="terms.php" class="text-white-50">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
            
            // Image Modal Functionality
            const imageModal = document.getElementById('imageModal');
            if (imageModal) {
                imageModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    const imagePath = button.getAttribute('data-image-path');
                    const imageName = button.getAttribute('data-image-name');
                    
                    const modalImage = document.getElementById('modalImage');
                    const modalTitle = imageModal.querySelector('.modal-title');
                    
                    modalImage.src = imagePath;
                    modalTitle.textContent = imageName || 'Image Preview';
                });
            }
            
            // Auto dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-info)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
        });
    </script>
</body>
</html>