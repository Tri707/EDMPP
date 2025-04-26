<?php
session_start();

// Check if logged in and is provider
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header("Location: ../auth.php");
    exit();
}

// Include database connection
require_once '../conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to safely output text
function e($text) {
    if ($text === null) return '';
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Get user/provider information
$userData = null;
$providerData = null;
$profileImage = '../default.png';

try {
    // Get user data
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get provider data
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$userId]);
    $providerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerData) {
        // Redirect to complete profile if provider record doesn't exist
        header("Location: profile.php?action=setup");
        exit();
    }
    
    // Set profile image path safely
    if ($userData && !empty($userData['profile_image'])) {
        // Check if it's an external URL
        if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
            $profileImage = $userData['profile_image'];
        } else {
            // Local file - safely handle path
            $safeFilename = basename($userData['profile_image']); // Extract only the filename
            $possiblePath = '../profile_images/' . $safeFilename;
            
            if (file_exists($possiblePath)) {
                $profileImage = $possiblePath;
            } else {
                $profileImage = '../default.png';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
}

// Default values
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
$requestData = null;
$customerData = null;
$existingQuote = null;
$success = false;
$error = '';

// Check if request exists and matches provider specialties
try {
    if ($requestId > 0) {
        // Get request data with customer info
        $requestQuery = "
            SELECT r.*, u.first_name, u.last_name, u.email, u.phone
            FROM quote_requests r
            JOIN users u ON r.customer_id = u.id
            WHERE r.id = ? AND r.status = 'pending'
        ";
        $stmt = $pdo->prepare($requestQuery);
        $stmt->execute([$requestId]);
        $requestData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if request exists
        if (!$requestData) {
            $error = "Quote request not found or already processed.";
        } else {
            // Get customer data
            $customerData = [
                'id' => $requestData['customer_id'],
                'name' => $requestData['first_name'] . ' ' . $requestData['last_name'],
                'email' => $requestData['email'],
                'phone' => $requestData['phone']
            ];
            
            // Check if provider has already created a quote for this request
            $quoteQuery = "
                SELECT * 
                FROM quotes 
                WHERE request_id = ? AND technician_id = ?
            ";
            $stmt = $pdo->prepare($quoteQuery);
            $stmt->execute([$requestId, $userId]);
            $existingQuote = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verify provider specialties match the device type
            if ($providerData && !empty($providerData['specialties'])) {
                $specialties = explode(',', $providerData['specialties']);
                if (!in_array($requestData['device_type'], $specialties)) {
                    $error = "This request is for a {$requestData['device_type']} repair, which is not in your specialties.";
                }
            } else {
                $error = "Please add specialties to your profile before creating quotes.";
            }
        }
    } else {
        $error = "Invalid request ID.";
    }
} catch (PDOException $e) {
    error_log("Database error fetching request data: " . $e->getMessage());
    $error = "An error occurred while retrieving request information.";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_quote') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Security validation failed. Please try again.";
    } else {
        // Validate inputs
        $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
        $estimatedTime = isset($_POST['estimated_time']) ? clean_input($_POST['estimated_time']) : '';
        $description = isset($_POST['description']) ? clean_input($_POST['description']) : '';
        $warranty = isset($_POST['warranty']) ? clean_input($_POST['warranty']) : 'Standard warranty';
        
        if ($requestId <= 0) {
            $error = "Invalid request ID.";
        } elseif ($price <= 0) {
            $error = "Please enter a valid price.";
        } elseif (empty($estimatedTime)) {
            $error = "Please provide an estimated completion time.";
        } elseif (empty($description)) {
            $error = "Please provide a description of the repair.";
        } else {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Get provider ID
                $providerId = $providerData['id'];
                
                if ($existingQuote) {
                    // Update existing quote
                    $updateQuery = "
                        UPDATE quotes
                        SET price = ?, estimated_time = ?, description = ?, 
                            warranty = ?, provider_id = ?, updated_at = NOW()
                        WHERE id = ?
                    ";
                    $stmt = $pdo->prepare($updateQuery);
                    $stmt->execute([
                        $price, 
                        $estimatedTime, 
                        $description, 
                        $warranty, 
                        $providerId, 
                        $existingQuote['id']
                    ]);
                    
                    $quoteId = $existingQuote['id'];
                    $actionType = 'updated';
                } else {
                    // Create new quote
                    $insertQuery = "
                        INSERT INTO quotes (
                            request_id, technician_id, provider_id, price, 
                            estimated_time, description, warranty, status, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, 'pending', NOW()
                        )
                    ";
                    $stmt = $pdo->prepare($insertQuery);
                    $stmt->execute([
                        $requestId, 
                        $userId, 
                        $providerId, 
                        $price, 
                        $estimatedTime, 
                        $description, 
                        $warranty
                    ]);
                    
                    $quoteId = $pdo->lastInsertId();
                    $actionType = 'created';
                    
                    // Update request status
                    $updateRequestQuery = "
                        UPDATE quote_requests
                        SET status = 'quoted', updated_at = NOW()
                        WHERE id = ?
                    ";
                    $stmt = $pdo->prepare($updateRequestQuery);
                    $stmt->execute([$requestId]);
                }
                
                // Create notification for customer
                if (isset($customerData['id'])) {
                    // Check if quote_notifications table exists
                    $notifQuery = "
                        INSERT INTO quote_notifications (
                            recipient_id, request_id, type, message, created_at
                        ) VALUES (
                            ?, ?, ?, ?, NOW()
                        )
                    ";
                    $stmt = $pdo->prepare($notifQuery);
                    $stmt->execute([
                        $customerData['id'],
                        $requestId,
                        'new_quote',
                        "A technician has {$actionType} a quote for your repair request."
                    ]);
                }
                
                // Commit transaction
                $pdo->commit();
                $success = true;
            } catch (PDOException $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                error_log("Database error creating quote: " . $e->getMessage());
                $error = "An error occurred while processing your quote. Please try again.";
            }
        }
    }
}

// Create CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Determine theme preference
$theme = 'light';
if (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') {
    $theme = 'dark';
} elseif (isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) && 
          $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark') {
    $theme = 'dark';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $existingQuote ? 'Edit Quote' : 'Create Quote'; ?> - FixItNow Provider</title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    
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
            
            /* Animation speeds */
            --transition-speed: 0.3s;
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
            transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            transition: background-color var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }
        
        .site-header .container {
            max-width: 1400px;
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            transition: color var(--transition-speed) ease;
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            transition: color var(--transition-speed) ease;
        }
        
        /* Theme Toggle Button */
        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            border: none;
            padding: 0;
            position: relative;
        }
        
        .btn-icon:hover, .btn-icon:focus {
            background-color: rgba(255, 255, 255, 0.2);
            color: var(--header-text);
        }
        
        /* Dashboard Layout */
        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 76px); /* Header height */
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            padding: 1.5rem 1rem;
            border-right: 1px solid var(--border-color);
            transition: background-color var(--transition-speed) ease, transform var(--transition-speed) ease;
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
            transition: all var(--transition-speed) ease;
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
        .request-card, .form-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }
        
        .request-card .card-header, .form-card .card-header {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        .request-card .card-body, .form-card .card-body {
            padding: 1.5rem;
        }
        
        .request-card .card-footer, .form-card .card-footer {
            background-color: var(--card-bg);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        /* Device Icon */
        .device-icon {
            width: 50px;
            height: 50px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        /* Customer Info Card */
        .customer-info {
            background-color: var(--primary-light);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        /* Form control styling */
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: all var(--transition-speed) ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        /* Success message */
        .success-message {
            text-align: center;
            padding: 2rem;
        }
        
        .success-icon {
            font-size: 4rem;
            color: var(--accent-color);
            margin-bottom: 1rem;
        }
        
        /* Submit button */
        .btn-submit {
            background: linear-gradient(45deg, var(--primary-color), var(--accent-color));
            border: none;
            color: white;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(var(--bs-primary-rgb), 0.3);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(var(--bs-primary-rgb), 0.4);
        }
        
        /* Price input with currency */
        .price-input-group {
            position: relative;
        }
        
        .price-input-group .form-control {
            padding-left: 2.5rem;
        }
        
        .price-input-group .currency-symbol {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            width: 40px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-light);
            border-right: 1px solid var(--border-color);
            border-top-left-radius: 0.5rem;
            border-bottom-left-radius: 0.5rem;
            pointer-events: none;
            z-index: 10;
        }
        
        .price-input-group .currency-img {
            height: 24px;
            width: auto;
            display: block;
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
                display: none;
            }
            
            .dashboard-content {
                padding: 1.5rem 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem 1rem;
            }
            
            .form-card .card-body {
                padding: 1.5rem 1rem;
            }
            
            .customer-info {
                margin-top: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem 0.75rem;
            }
            
            .form-card .card-body {
                padding: 1rem 0.75rem;
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
            <div class="row align-items-center">
                <div class="col-auto d-flex align-items-center">
                    <!-- Logo -->
                    <a href="../index.php" class="text-decoration-none d-flex align-items-center" aria-label="FixItNow Home">
                        <div class="logo-text">
                            <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                        </div>
                    </a>
                </div>
                
                <!-- Right Side Controls -->
                <div class="col-auto ms-auto d-flex align-items-center gap-2">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="btn-icon" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-sun" id="themeIcon" aria-hidden="true"></i>
                    </button>
                    
                    <!-- User Profile -->
                    <?php if($loggedIn && isset($userData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn d-flex align-items-center gap-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="position-relative">
                                <img src="<?php echo e($profileImage); ?>" alt="" class="rounded-circle" width="36" height="36">
                                <span class="position-absolute bottom-0 end-0 bg-success rounded-circle p-1 border border-white" title="Online" aria-hidden="true"></span>
                            </div>
                            <div class="d-none d-md-block text-start">
                                <div class="text-nowrap fw-semibold"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                                <div class="text-muted small text-nowrap"><?php echo e($userData['role']); ?></div>
                            </div>
                            <i class="fas fa-chevron-down small ms-1 d-none d-md-inline" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item py-2" href="dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Dashboard</a></li>
                            <li><a class="dropdown-item py-2" href="profile.php"><i class="fas fa-user-cog me-2" aria-hidden="true"></i> Edit Profile</a></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item py-2" href="../logout.php"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div>
                        <a href="../login.php" class="btn btn-outline-light me-2">Login</a>
                        <a href="../signup.php" class="btn btn-success">Sign Up</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Dashboard Layout -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar d-none d-lg-block">
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="quote-requests.php">
                        <i class="fas fa-file-invoice-dollar"></i> Quote Requests
                    </a>
                </li>
                <li>
                    <a href="my-quotes.php">
                        <i class="fas fa-comment-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="my-repairs.php">
                        <i class="fas fa-tools"></i> My Repairs
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
                        <i class="fas fa-star"></i> Reviews
                    </a>
                </li>
                <li>
                    <a href="profile.php">
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
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <!-- Success Message -->
            <div class="card form-card">
                <div class="card-body success-message">
                    <div class="success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h2 class="mb-4">Quote Successfully <?php echo $existingQuote ? 'Updated' : 'Created'; ?>!</h2>
                    <p class="lead mb-4">Your quote has been sent to the customer. They will be notified to review it.</p>
                    <div class="d-grid gap-3 d-md-flex justify-content-md-center">
                        <a href="my-quotes.php" class="btn btn-primary btn-lg me-md-2">
                            <i class="fas fa-file-invoice-dollar me-2"></i> View My Quotes
                        </a>
                        <a href="quote-requests.php" class="btn btn-outline-primary btn-lg">
                            <i class="fas fa-list me-2"></i> Back to Quote Requests
                        </a>
                    </div>
                </div>
            </div>
            <?php elseif ($requestData): ?>
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h2 class="fw-bold mb-0"><?php echo $existingQuote ? 'Edit Quote' : 'Create Quote'; ?></h2>
                <a href="quote-requests.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Quote Requests
                </a>
            </div>
            
            <!-- Request Details Card -->
            <div class="card request-card mb-4">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i> Request Information
                        </h5>
                        <span class="badge bg-primary">Request #<?php echo $requestData['id']; ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center mb-3">
                                <?php
                                // Get device icon
                                $deviceIcon = 'fas fa-tools';
                                switch ($requestData['device_type']) {
                                    case 'smartphone':
                                        $deviceIcon = 'fas fa-mobile-alt';
                                        break;
                                    case 'laptop':
                                        $deviceIcon = 'fas fa-laptop';
                                        break;
                                    case 'desktop':
                                        $deviceIcon = 'fas fa-desktop';
                                        break;
                                    case 'tablet':
                                        $deviceIcon = 'fas fa-tablet-alt';
                                        break;
                                    case 'gaming':
                                        $deviceIcon = 'fas fa-gamepad';
                                        break;
                                    case 'tv':
                                        $deviceIcon = 'fas fa-tv';
                                        break;
                                }
                                ?>
                                <div class="device-icon me-3">
                                    <i class="<?php echo $deviceIcon; ?>"></i>
                                </div>
                                <div>
                                    <h5 class="mb-0 text-capitalize"><?php echo e($requestData['device_type']); ?> Repair</h5>
                                    <div class="text-muted">
                                        <?php if (!empty($requestData['device_brand']) && $requestData['device_brand'] !== 'Not specified'): ?>
                                            Brand: <?php echo e($requestData['device_brand']); ?>
                                            <?php if (!empty($requestData['device_model']) && $requestData['device_model'] !== 'Not specified'): ?>
                                                - Model: <?php echo e($requestData['device_model']); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <h6>Issue Description:</h6>
                            <p class="mb-4"><?php echo e($requestData['issue_description']); ?></p>
                            
                            <?php if (!empty($requestData['additional_info'])): ?>
                            <h6>Additional Information:</h6>
                            <p class="mb-4"><?php echo e($requestData['additional_info']); ?></p>
                            <?php endif; ?>
                            
                            <div class="row mb-4">
                                <div class="col-md-4 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-clock me-2 text-muted"></i>
                                        <div>
                                            <div class="small text-muted">Urgency</div>
                                            <div class="text-capitalize"><?php echo e($requestData['urgency']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($requestData['location']) && $requestData['location'] !== 'Not specified'): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-map-marker-alt me-2 text-muted"></i>
                                        <div>
                                            <div class="small text-muted">Location</div>
                                            <div><?php echo e($requestData['location']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="col-md-4 mb-2">
                                    <div class="d-flex align-items-center">
                                        <i class="far fa-calendar-alt me-2 text-muted"></i>
                                        <div>
                                            <div class="small text-muted">Request Date</div>
                                            <div><?php echo date('M j, Y', strtotime($requestData['created_at'])); ?></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="customer-info">
                                <h6><i class="fas fa-user me-2"></i>Customer Information</h6>
                                <p class="mb-1"><strong><?php echo e($customerData['name']); ?></strong></p>
                                <p class="mb-1"><i class="fas fa-envelope me-1 small"></i> <?php echo e($customerData['email']); ?></p>
                                <p class="mb-0"><i class="fas fa-phone me-1 small"></i> <?php echo e($customerData['phone']); ?></p>
                            </div>
                            
                            <div class="d-grid mt-3">
                                <a href="customer-details.php?id=<?php echo $customerData['id']; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-user me-2"></i> View Customer Profile
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quote Form Card -->
            <div class="card form-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-comment-dollar me-2"></i> <?php echo $existingQuote ? 'Edit Your Quote' : 'Create a Quote'; ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form id="quoteForm" action="create-quote.php?request_id=<?php echo $requestId; ?>" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="submit_quote">
                        
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Quote Amount <span class="text-danger">*</span></label>
                                <div class="price-input-group">
                                    <span class="currency-symbol"><img src="../sar/sar.png" alt="SAR" class="currency-img"></span>
                                    <input type="number" class="form-control" id="price" name="price" min="0.01" step="0.01" value="<?php echo $existingQuote ? $existingQuote['price'] : ''; ?>" style="padding-left: 50px;" required>
                                </div>
                                <div class="form-text">Enter the total amount you'll charge for this repair</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="estimated_time" class="form-label">Estimated Completion Time <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="estimated_time" name="estimated_time" placeholder="e.g., 2-3 business days" value="<?php echo $existingQuote ? e($existingQuote['estimated_time']) : ''; ?>" required>
                                <div class="form-text">How long will it take to complete the repair?</div>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="description" class="form-label">Description / Repair Details <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="description" name="description" rows="5" required placeholder="Provide details about the repair process, parts needed, etc."><?php echo $existingQuote ? e($existingQuote['description']) : ''; ?></textarea>
                            <div class="form-text">Explain what needs to be done, parts required, and any other relevant information</div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="warranty" class="form-label">Warranty</label>
                            <input type="text" class="form-control" id="warranty" name="warranty" placeholder="e.g., 30-day parts and labor" value="<?php echo $existingQuote ? e($existingQuote['warranty']) : 'Standard warranty'; ?>">
                            <div class="form-text">What warranty do you offer for this repair?</div>
                        </div>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <a href="quote-requests.php" class="btn btn-outline-secondary me-md-2">
                                Cancel
                            </a>
                            <button type="submit" class="btn btn-submit">
                                <i class="fas fa-paper-plane me-2"></i> <?php echo $existingQuote ? 'Update Quote' : 'Submit Quote'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
            <div class="card form-card">
                <div class="card-body text-center py-5">
                    <div class="mb-4">
                        <i class="fas fa-exclamation-circle text-warning" style="font-size: 4rem;"></i>
                    </div>
                    <h3 class="mb-3">Quote Request Not Found</h3>
                    <p class="lead mb-4">The quote request you're looking for doesn't exist or you don't have permission to view it.</p>
                    <a href="quote-requests.php" class="btn btn-primary">
                        <i class="fas fa-list me-2"></i> View Available Quote Requests
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="py-4 bg-dark text-light mt-auto">
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
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading spinner when page is loaded
            const loadingSpinner = document.getElementById('loadingSpinner');
            if (loadingSpinner) {
                loadingSpinner.classList.remove('show');
            }
            
            // Theme toggle functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme') || htmlElement.getAttribute('data-bs-theme') || 'light';
            
            // Set initial theme icon
            updateThemeIcon(savedTheme);
            
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = htmlElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    htmlElement.setAttribute('data-bs-theme', newTheme);
                    localStorage.setItem('theme', newTheme);
                    
                    // Also set as cookie for server-side detection
                    document.cookie = `theme=${newTheme}; path=/; max-age=31536000`; // 1 year
                    
                    updateThemeIcon(newTheme);
                });
            }
            
            function updateThemeIcon(theme) {
                if (themeIcon) {
                    if (theme === 'dark') {
                        themeIcon.classList.remove('fa-moon');
                        themeIcon.classList.add('fa-sun');
                    } else {
                        themeIcon.classList.remove('fa-sun');
                        themeIcon.classList.add('fa-moon');
                    }
                }
            }
            
            // Form submission with loading spinner
            const quoteForm = document.getElementById('quoteForm');
            if (quoteForm) {
                quoteForm.addEventListener('submit', function(e) {
                    const price = document.getElementById('price').value;
                    const estimatedTime = document.getElementById('estimated_time').value;
                    const description = document.getElementById('description').value;
                    
                    // Basic validation
                    if (!price || parseFloat(price) <= 0) {
                        e.preventDefault();
                        alert('Please enter a valid price.');
                        return false;
                    }
                    
                    if (!estimatedTime.trim()) {
                        e.preventDefault();
                        alert('Please enter an estimated completion time.');
                        return false;
                    }
                    
                    if (!description.trim()) {
                        e.preventDefault();
                        alert('Please provide a repair description.');
                        return false;
                    }
                    
                    // Show loading spinner
                    if (loadingSpinner) {
                        loadingSpinner.classList.add('show');
                    }
                });
            }
        });
    </script>
</body>
</html>