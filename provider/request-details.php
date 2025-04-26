<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../conn.php';

// Create logs directory if it doesn't exist
$logsDir = '../logs';
if (!file_exists($logsDir)) {
    mkdir($logsDir, 0755, true);
}

// Function to log errors properly
function log_error($message) {
    $logsDir = '../logs';
    $logFile = $logsDir . '/provider_errors.log';
    
    // Make sure the directory exists
    if (!file_exists($logsDir)) {
        mkdir($logsDir, 0755, true);
    }
    
    // Log to file
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", 3, $logFile);
    
    // Also log to PHP error log as backup
    error_log('REQUEST-DETAILS: ' . $message);
}

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get request ID from URL
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Check if ID is valid
if ($requestId <= 0) {
    // Redirect to quotes page with error
    $_SESSION['error_message'] = "Invalid request ID.";
    header("Location: quote-requests.php");
    exit();
}

// Initialize data variables
$requestData = null;
$customerData = null;
$providerData = null;
$error = "";
$specialtiesMatch = false;
$profileImage = '../default.png';

try {
    // Get provider information
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$userId]);
    $providerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerData) {
        log_error("Provider data not found for user ID: $userId");
        $_SESSION['error_message'] = "Provider profile not found. Please update your profile.";
        header("Location: dashboard.php");
        exit();
    }
    
    // Get user data for profile image
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if ($userData && !empty($userData['profile_image'])) {
        $profileImage = $userData['profile_image'];
        if (!preg_match('/^https?:\/\//', $profileImage)) {
            $profileImage = '../uploads/profile_images/' . $profileImage;
            
            // Verify file exists
            if (!file_exists($profileImage)) {
                $profileImage = '../default.png';
            }
        }
    }
    
    // Get request data
    $requestQuery = "
        SELECT qr.*, u.username, u.email, u.first_name, u.last_name, u.phone
        FROM quote_requests qr
        JOIN users u ON qr.customer_id = u.id
        WHERE qr.id = ?
    ";
    $stmt = $pdo->prepare($requestQuery);
    $stmt->execute([$requestId]);
    $requestData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$requestData) {
        log_error("Quote request not found, ID: $requestId");
        $_SESSION['error_message'] = "Quote request not found.";
        header("Location: quote-requests.php");
        exit();
    }
    
    // Extract customer data
    $customerData = [
        'id' => $requestData['customer_id'],
        'username' => $requestData['username'],
        'email' => $requestData['email'],
        'first_name' => $requestData['first_name'],
        'last_name' => $requestData['last_name'],
        'phone' => $requestData['phone']
    ];
    
    // Check if provider's specialties include the device type
    $providerSpecialties = explode(',', $providerData['specialties']);
    $specialtiesMatch = in_array($requestData['device_type'], $providerSpecialties);
    
    if (!$specialtiesMatch) {
        log_error("Provider (ID: {$providerData['id']}) attempted to access request for device type {$requestData['device_type']} not in their specialties.");
    }
    
    // Get other requests by the same customer
    $otherRequestsQuery = "
        SELECT id, device_type, created_at, status
        FROM quote_requests
        WHERE customer_id = ? AND id != ?
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($otherRequestsQuery);
    $stmt->execute([$customerData['id'], $requestId]);
    $otherRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if a quote already exists for this request from this provider
    $existingQuoteQuery = "
        SELECT id, status
        FROM quotes
        WHERE request_id = ? AND technician_id = ?
    ";
    $stmt = $pdo->prepare($existingQuoteQuery);
    $stmt->execute([$requestId, $userId]);
    $existingQuote = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    log_error("Database error: " . $e->getMessage());
    $error = "An error occurred while retrieving request details. Please try again later.";
}

// Handle form submission for new quote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = clean_input($_POST['action']);
    
    if ($action === 'create_quote' && $specialtiesMatch) {
        // Validate inputs
        $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
        $estimatedTime = clean_input($_POST['estimated_time'] ?? '');
        $description = clean_input($_POST['description'] ?? '');
        $warranty = clean_input($_POST['warranty'] ?? 'Standard warranty');
        
        $formErrors = [];
        
        if ($price <= 0) {
            $formErrors[] = "Please enter a valid price.";
        }
        
        if (empty($estimatedTime)) {
            $formErrors[] = "Please enter an estimated time.";
        }
        
        if (empty($description)) {
            $formErrors[] = "Please provide a description.";
        }
        
        if (empty($formErrors)) {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Create the quote
                $createQuoteQuery = "
                    INSERT INTO quotes (
                        request_id, 
                        technician_id,
                        provider_id,
                        price, 
                        estimated_time, 
                        description, 
                        warranty,
                        status, 
                        created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
                ";
                $stmt = $pdo->prepare($createQuoteQuery);
                $stmt->execute([
                    $requestId,
                    $userId,
                    $providerData['id'],
                    $price,
                    $estimatedTime,
                    $description,
                    $warranty
                ]);
                
                $quoteId = $pdo->lastInsertId();
                
                // Create notification for customer
                $notificationQuery = "
                    INSERT INTO quote_notifications (
                        recipient_id, 
                        request_id,
                        type,
                        message,
                        created_at
                    ) VALUES (?, ?, 'new_quote', ?, NOW())
                ";
                
                $notificationMessage = "New quote received for your {$requestData['device_type']} repair request.";
                $stmt = $pdo->prepare($notificationQuery);
                $stmt->execute([
                    $customerData['id'],
                    $requestId,
                    $notificationMessage
                ]);
                
                // Commit transaction
                $pdo->commit();
                
                // Redirect to success page
                $_SESSION['success_message'] = "Quote submitted successfully.";
                header("Location: quote-details.php?id=" . $quoteId);
                exit();
                
            } catch (PDOException $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                log_error("Error creating quote: " . $e->getMessage());
                $error = "An error occurred while submitting your quote. Please try again.";
            }
        }
    } elseif ($action === 'decline') {
        // Handle decline action
        try {
            // Update quote request status
            $declineQuery = "
                INSERT INTO declined_requests (
                    request_id,
                    provider_id,
                    reason,
                    created_at
                ) VALUES (?, ?, ?, NOW())
            ";
            $reason = clean_input($_POST['decline_reason'] ?? 'No reason provided');
            $stmt = $pdo->prepare($declineQuery);
            $stmt->execute([$requestId, $providerData['id'], $reason]);
            
            // Create notification for customer
            $notificationQuery = "
                INSERT INTO quote_notifications (
                    recipient_id, 
                    request_id,
                    type,
                    message,
                    created_at
                ) VALUES (?, ?, 'quote_rejected', ?, NOW())
            ";
            
            $notificationMessage = "A provider has declined your {$requestData['device_type']} repair request.";
            $stmt = $pdo->prepare($notificationQuery);
            $stmt->execute([
                $customerData['id'],
                $requestId,
                $notificationMessage
            ]);
            
            // Redirect to success page
            $_SESSION['success_message'] = "Request declined successfully.";
            header("Location: quote-requests.php");
            exit();
            
        } catch (PDOException $e) {
            log_error("Error declining request: " . $e->getMessage());
            $error = "An error occurred while declining the request. Please try again.";
        }
    }
}

// Function to get device type icon
function getDeviceIcon($deviceType) {
    switch (strtolower($deviceType)) {
        case 'smartphone':
            return 'fa-mobile-alt';
        case 'laptop':
            return 'fa-laptop';
        case 'tablet':
            return 'fa-tablet-alt';
        case 'desktop':
        case 'computer':
            return 'fa-desktop';
        case 'gaming':
        case 'console':
            return 'fa-gamepad';
        case 'tv':
            return 'fa-tv';
        default:
            return 'fa-microchip';
    }
}

// Function to format date
function formatDate($dateString) {
    return date('M d, Y', strtotime($dateString));
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Request Details - FixItNow</title>
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
        
        .header-nav a.nav-link {
            color: var(--header-text);
            opacity: 0.85;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all 0.2s ease;
            border-radius: 0.5rem;
        }
        
        .header-nav a.nav-link:hover {
            opacity: 1;
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .header-nav a.nav-link.active {
            opacity: 1;
            color: var(--header-text);
            background-color: var(--primary-color);
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
        
        /* Quote details styles */
        .breadcrumb {
            margin-bottom: 2rem;
            background-color: var(--card-bg);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item.active {
            color: var(--text-muted);
        }
        
        .detail-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
            border: none;
        }
        
        .detail-card .card-header {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: none;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .detail-card .card-header .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.75rem;
            border-radius: 30px;
        }
        
        .customer-section {
            background-color: var(--primary-light);
            border-radius: 1rem;
            padding: 1.5rem;
        }
        
        .customer-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--card-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .customer-info {
            margin-bottom: 0.25rem;
            display: flex;
            align-items: center;
        }
        
        .customer-info i {
            width: 20px;
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        .device-icon {
            width: 60px;
            height: 60px;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 1rem;
            color: var(--primary-color);
        }
        
        .detail-row {
            margin-bottom: 1rem;
            display: flex;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
        }
        
        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .detail-label {
            font-weight: 600;
            min-width: 150px;
            color: var(--text-muted);
        }
        
        .quote-form label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .quote-form .form-control,
        .quote-form .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
        }
        
        .quote-form .input-group-text {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .notification-badge {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
            border-radius: 0.5rem;
            padding: 0.25rem 0.75rem;
            margin-top: 1rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
        }
        
        .notification-badge i {
            margin-right: 0.5rem;
        }
        
        .other-request-card {
            border-radius: 0.5rem;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s ease;
        }
        
        .other-request-card:hover {
            transform: translateX(5px);
            border-color: var(--primary-color);
        }
        
        .other-request-card .badge {
            font-size: 0.7rem;
        }
        
        /* Specialty mismatch warning */
        .specialty-mismatch {
            background-color: rgba(var(--bs-warning-rgb), 0.15);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--bs-warning);
        }
        
        .specialty-mismatch-icon {
            font-size: 2rem;
            color: var(--bs-warning);
            margin-right: 1rem;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        
        .loading-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        
        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
            }
            
            .sidebar-menu li {
                margin-right: 0.5rem;
                margin-bottom: 0.5rem;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .detail-label {
                min-width: 120px;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-spinner"></div>
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
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($userData['username'] ?? 'Provider'); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar d-none d-lg-block">
            <h5 class="mb-3">Provider Dashboard</h5>
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Overview
                    </a>
                </li>
                <li>
                    <a href="quote-requests.php" class="active">
                        <i class="fas fa-file-invoice-dollar"></i> Quote Requests
                    </a>
                </li>
                <li>
                    <a href="my-quotes.php">
                        <i class="fas fa-comment-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="schedule.php">
                        <i class="fas fa-calendar-alt"></i> My Schedule
                    </a>
                </li>
                <li>
                    <a href="earnings.php">
                        <i class="fas fa-money-bill-wave"></i> Earnings
                    </a>
                </li>
                <li>
                    <a href="reviews.php">
                        <i class="fas fa-star"></i> My Reviews
                    </a>
                </li>
                <li>
                    <a href="notifications.php">
                        <i class="fas fa-bell"></i> Notifications
                    </a>
                </li>
                <li>
                    <a href="../profile.php">
                        <i class="fas fa-user-cog"></i> Profile Settings
                    </a>
                </li>
                <li>
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Mobile Navigation -->
        <div class="d-lg-none p-3">
            <div class="dropdown w-100 mb-3">
                <button class="btn btn-primary dropdown-toggle w-100" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-bars me-2"></i> Provider Menu
                </button>
                <ul class="dropdown-menu w-100">
                    <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Overview</a></li>
                    <li><a class="dropdown-item active" href="quote-requests.php"><i class="fas fa-file-invoice-dollar me-2"></i> Quote Requests</a></li>
                    <li><a class="dropdown-item" href="my-quotes.php"><i class="fas fa-comment-dollar me-2"></i> My Quotes</a></li>
                    <li><a class="dropdown-item" href="schedule.php"><i class="fas fa-calendar-alt me-2"></i> My Schedule</a></li>
                    <li><a class="dropdown-item" href="earnings.php"><i class="fas fa-money-bill-wave me-2"></i> Earnings</a></li>
                    <li><a class="dropdown-item" href="reviews.php"><i class="fas fa-star me-2"></i> My Reviews</a></li>
                    <li><a class="dropdown-item" href="notifications.php"><i class="fas fa-bell me-2"></i> Notifications</a></li>
                    <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user-cog me-2"></i> Profile Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="quote-requests.php">Quote Requests</a></li>
                    <li class="breadcrumb-item active">Request #<?php echo $requestId; ?></li>
                </ol>
            </nav>
            
            <!-- Page Title -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="fs-3">Quote Request Details</h1>
                <span class="badge bg-warning">PENDING</span>
            </div>
            
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($formErrors) && !empty($formErrors)): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0">
                    <?php foreach ($formErrors as $formError): ?>
                    <li><?php echo $formError; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <?php if (!$specialtiesMatch && $requestData): ?>
            <!-- Specialty Mismatch Warning -->
            <div class="specialty-mismatch mb-4">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle specialty-mismatch-icon"></i>
                    <div>
                        <h5 class="mb-2">Specialty Mismatch</h5>
                        <p class="mb-2">This request is for a <strong><?php echo htmlspecialchars(ucfirst($requestData['device_type'])); ?></strong> repair, which is not in your list of specialties.</p>
                        <p class="mb-0">You cannot submit a quote for this request. Please update your profile to add this specialty if you have the skills to repair this device type.</p>
                        
                        <div class="mt-3">
                            <a href="../profile.php?section=specialties" class="btn btn-warning btn-sm">
                                <i class="fas fa-edit me-1"></i> Update Your Specialties
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($existingQuote) && $existingQuote): ?>
            <div class="alert alert-info" role="alert">
                <i class="fas fa-info-circle me-2"></i> You have already submitted a quote for this request. 
                <a href="quote-details.php?id=<?php echo $existingQuote['id']; ?>" class="alert-link">View your quote</a>.
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-lg-8">
                    <!-- Request Details Card -->
                    <div class="card detail-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Repair Request</h5>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Request #<?php echo $requestId; ?> submitted on <?php echo formatDate($requestData['created_at']); ?></p>
                            
                            <h5 class="mb-3">Issue Details</h5>
                            <p><?php echo htmlspecialchars($requestData['issue_description'] ?? 'No description provided'); ?></p>
                            
                            <h5 class="mt-4 mb-3">Device Information</h5>
                            <div class="d-flex mb-3">
                                <div class="device-icon">
                                    <i class="fas <?php echo getDeviceIcon($requestData['device_type'] ?? ''); ?>"></i>
                                </div>
                                <div>
                                    <h6>Device Type:</h6>
                                    <p class="fs-5 fw-bold mb-0"><?php echo htmlspecialchars(ucfirst($requestData['device_type'] ?? 'Unknown')); ?></p>
                                </div>
                            </div>
                            
                            <?php if (!empty($requestData['device_brand']) && $requestData['device_brand'] !== 'Not specified'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Brand:</div>
                                <div><?php echo htmlspecialchars($requestData['device_brand']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['device_model']) && $requestData['device_model'] !== 'Not specified'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Model:</div>
                                <div><?php echo htmlspecialchars($requestData['device_model']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['device_condition']) && $requestData['device_condition'] !== 'good'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Condition:</div>
                                <div><?php echo htmlspecialchars(ucfirst($requestData['device_condition'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['service_type']) && $requestData['service_type'] !== 'repair'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Service Type:</div>
                                <div><?php echo htmlspecialchars(ucfirst($requestData['service_type'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['urgency']) && $requestData['urgency'] !== 'medium'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Urgency:</div>
                                <div>
                                    <?php if ($requestData['urgency'] === 'high'): ?>
                                    <span class="badge bg-danger">High</span>
                                    <?php elseif ($requestData['urgency'] === 'low'): ?>
                                    <span class="badge bg-success">Low</span>
                                    <?php else: ?>
                                    <span class="badge bg-warning">Medium</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['location']) && $requestData['location'] !== 'Not specified'): ?>
                            <div class="detail-row">
                                <div class="detail-label">Location:</div>
                                <div><?php echo htmlspecialchars($requestData['location']); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($requestData['additional_info'])): ?>
                            <div class="detail-row">
                                <div class="detail-label">Additional Info:</div>
                                <div><?php echo htmlspecialchars($requestData['additional_info']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($specialtiesMatch && !isset($existingQuote)): ?>
                    <!-- Quote Form -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <h5 class="mb-0">Submit Quote</h5>
                        </div>
                        <div class="card-body">
                            <form class="quote-form" method="POST" action="" id="quoteForm">
                                <input type="hidden" name="action" value="create_quote">
                                
                                <div class="mb-3">
                                    <label for="price">Price (SAR)</label>
                                    <div class="input-group">
                                        <span class="input-group-text">SAR</span>
                                        <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="estimated_time">Estimated Repair Time</label>
                                    <input type="text" class="form-control" id="estimated_time" name="estimated_time" placeholder="e.g. 2-3 days" required>
                                    <div class="form-text text-muted">Provide an estimate of how long the repair will take.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="warranty">Warranty</label>
                                    <select class="form-select" id="warranty" name="warranty">
                                        <option value="Standard warranty">Standard warranty (30 days)</option>
                                        <option value="Extended warranty (90 days)">Extended warranty (90 days)</option>
                                        <option value="Premium warranty (180 days)">Premium warranty (180 days)</option>
                                        <option value="No warranty">No warranty</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description">Description</label>
                                    <textarea class="form-control" id="description" name="description" rows="4" placeholder="Provide details about your quote, repair process, etc." required></textarea>
                                </div>
                                
                                <div class="action-buttons">
                                    <button type="submit" class="btn btn-primary btn-lg" id="submitQuoteBtn">
                                        <i class="fas fa-paper-plane me-2"></i> Submit Quote
                                    </button>
                                    <a href="quote-requests.php" class="btn btn-outline-secondary btn-lg">
                                        Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php elseif (!isset($existingQuote)): ?>
                    <!-- Decline Form -->
                    <div class="card detail-card">
                        <div class="card-header">
                            <h5 class="mb-0">Decline Request</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" id="declineForm">
                                <input type="hidden" name="action" value="decline">
                                
                                <div class="mb-3">
                                    <label for="decline_reason">Reason for Declining (Optional)</label>
                                    <textarea class="form-control" id="decline_reason" name="decline_reason" rows="3" placeholder="Provide a reason for declining this request..."></textarea>
                                </div>
                                
                                <div class="action-buttons">
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#confirmDeclineModal">
                                        <i class="fas fa-times-circle me-2"></i> Decline Request
                                    </button>
                                    <a href="quote-requests.php" class="btn btn-outline-secondary">
                                        Back to Requests
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Confirm Decline Modal -->
                    <div class="modal fade" id="confirmDeclineModal" tabindex="-1" aria-labelledby="confirmDeclineModalLabel" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="confirmDeclineModalLabel">Confirm Decline</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <p>Are you sure you want to decline this request? This action cannot be undone.</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="button" class="btn btn-danger" id="confirmDeclineBtn">Yes, Decline Request</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="col-lg-4">
                    <!-- Customer Information -->
                    <div class="card detail-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Customer Information</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="customer-section">
                                <div class="text-center">
                                    <div class="customer-avatar mx-auto">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <h5><?php echo htmlspecialchars($customerData['first_name'] . ' ' . $customerData['last_name']); ?></h5>
                                    <p class="text-muted mb-0">@<?php echo htmlspecialchars($customerData['username']); ?></p>
                                </div>
                                
                                <hr>
                                
                                <div class="customer-info">
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo htmlspecialchars($customerData['email']); ?></span>
                                </div>
                                
                                <?php if (!empty($customerData['phone'])): ?>
                                <div class="customer-info">
                                    <i class="fas fa-phone"></i>
                                    <span><?php echo htmlspecialchars($customerData['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-sm btn-outline-primary mt-3 w-100" id="messageCustomerBtn">
                                    <i class="fas fa-comment me-1"></i> Message Customer
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Other Requests -->
                    <?php if (!empty($otherRequests)): ?>
                    <div class="card detail-card">
                        <div class="card-header">
                            <h5 class="mb-0">Other Requests by This Customer</h5>
                        </div>
                        <div class="card-body">
                            <?php foreach ($otherRequests as $request): ?>
                            <div class="other-request-card">
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars(ucfirst($request['device_type'])); ?> Repair</div>
                                    <div class="text-muted small"><?php echo formatDate($request['created_at']); ?></div>
                                </div>
                                <div>
                                    <span class="badge bg-warning">PENDING</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="py-4 bg-dark text-light mt-auto">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="../privacy.php" class="text-light me-3">Privacy Policy</a>
                    <a href="../terms.php" class="text-light me-3">Terms of Service</a>
                    <a href="../contact.php" class="text-light">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Theme and functionality scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize loading overlay
            const loadingOverlay = document.getElementById('loadingOverlay');
            
            // Theme Toggle functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.querySelector('html');
            
            // Check for saved theme preference or use device preference
            const savedTheme = localStorage.getItem('theme');
            
            if (savedTheme) {
                htmlElement.setAttribute('data-bs-theme', savedTheme);
                updateIcon(savedTheme);
            } else {
                // Use device preference if no saved preference
                const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const initialTheme = prefersDarkMode ? 'dark' : 'light';
                htmlElement.setAttribute('data-bs-theme', initialTheme);
                updateIcon(initialTheme);
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                
                updateIcon(newTheme);
            });
            
            function updateIcon(theme) {
                if (theme === 'dark') {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
            
            // Form submission handling
            const quoteForm = document.getElementById('quoteForm');
            if (quoteForm) {
                quoteForm.addEventListener('submit', function() {
                    // Show loading overlay
                    loadingOverlay.classList.add('show');
                });
            }
            
            // Handle decline confirmation
            const declineForm = document.getElementById('declineForm');
            const confirmDeclineBtn = document.getElementById('confirmDeclineBtn');
            
            if (confirmDeclineBtn && declineForm) {
                confirmDeclineBtn.addEventListener('click', function() {
                    // Hide modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('confirmDeclineModal'));
                    modal.hide();
                    
                    // Show loading overlay
                    loadingOverlay.classList.add('show');
                    
                    // Submit the form
                    declineForm.submit();
                });
            }
            
            // Message customer button functionality
            const messageCustomerBtn = document.getElementById('messageCustomerBtn');
            if (messageCustomerBtn) {
                messageCustomerBtn.addEventListener('click', function() {
                    // In a real implementation, this would open a messaging interface or redirect to one
                    alert('Messaging functionality will be implemented soon!');
                });
            }
        });
    </script>
</body>
</html>