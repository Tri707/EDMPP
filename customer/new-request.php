<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a customer
if (!$loggedIn || $userRole !== 'customer') {
    header("Location: ../login.php");
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

// Get user information
$userData = null;
$profileImage = '../images/default.png';

try {
    // Get user data
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
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
                $profileImage = '../images/default.png';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $profileImage = '../images/default.png';
}

// Get recent notifications
$notifications = [];
try {
    $notifQuery = "
        SELECT id, message, device_type, created_at 
        FROM notifications
        WHERE customer_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching notifications: " . $e->getMessage());
}

// Process form submission
$success = false;
$error = '';
$formData = [
    'device_type' => '',
    'device_brand' => '',
    'device_model' => '',
    'device_condition' => '',
    'issue_description' => '',
    'urgency' => 'medium',
    'location' => '',
    'service_type' => 'repair',
    'additional_info' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize inputs
    $formData['device_type'] = isset($_POST['device_type']) ? clean_input($_POST['device_type']) : '';
    $formData['issue_description'] = isset($_POST['issue_description']) ? clean_input($_POST['issue_description']) : '';
    $formData['device_brand'] = isset($_POST['device_brand']) ? clean_input($_POST['device_brand']) : '';
    $formData['device_model'] = isset($_POST['device_model']) ? clean_input($_POST['device_model']) : '';
    $formData['device_condition'] = isset($_POST['device_condition']) ? clean_input($_POST['device_condition']) : '';
    $formData['urgency'] = isset($_POST['urgency']) ? clean_input($_POST['urgency']) : 'medium';
    $formData['location'] = isset($_POST['location']) ? clean_input($_POST['location']) : '';
    $formData['service_type'] = isset($_POST['service_type']) ? clean_input($_POST['service_type']) : 'repair';
    $formData['additional_info'] = isset($_POST['additional_info']) ? clean_input($_POST['additional_info']) : '';
    
    // Validate required fields
    if (empty($formData['device_type'])) {
        $error = "Please select a device type";
    } elseif (empty($formData['issue_description'])) {
        $error = "Please describe the issue with your device";
    } elseif (!in_array($formData['device_type'], ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'])) {
        $error = "Invalid device type selected";
    } else {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // Insert the quote request with all available fields
            $query = "
                INSERT INTO quote_requests (
                    customer_id, device_type, device_brand, device_model, 
                    device_condition, issue_description, urgency, location, 
                    service_type, additional_info, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW()
                )
            ";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $userId, 
                $formData['device_type'], 
                $formData['device_brand'], 
                $formData['device_model'],
                $formData['device_condition'], 
                $formData['issue_description'], 
                $formData['urgency'], 
                $formData['location'],
                $formData['service_type'], 
                $formData['additional_info']
            ]);
            
            $quoteRequestId = $pdo->lastInsertId();
            
            // Create notifications for providers
            $notifQuery = "
                INSERT INTO notifications (
                    provider_id, customer_id, device_type, message, 
                    issue_description, type, reference_id, status, created_at
                )
                SELECT 
                    p.id, ?, ?, ?, ?, 'quote_request', ?, 'pending', NOW()
                FROM providers p
                WHERE EXISTS (
                    SELECT 1 FROM users u 
                    WHERE u.id = p.user_id 
                    AND u.role = 'provider'
                    AND FIND_IN_SET(?, p.specialties) > 0
                )
            ";
            
            $stmt = $pdo->prepare($notifQuery);
            $stmt->execute([
                $userId, 
                $formData['device_type'], 
                "New quote request for " . $formData['device_type'] . " repair", 
                $formData['issue_description'],
                $quoteRequestId,
                $formData['device_type']
            ]);
            
            // Log the creation
            $logQuery = "
                INSERT INTO notification_logs (
                    action, error_message
                ) VALUES (
                    'create_quote_request', ?
                )
            ";
            $stmt = $pdo->prepare($logQuery);
            $stmt->execute(["Created quote request #$quoteRequestId by customer #$userId"]);
            
            $pdo->commit();
            $success = true;
            
            // Reset form data after successful submission
            $formData = [
                'device_type' => '',
                'device_brand' => '',
                'device_model' => '',
                'device_condition' => '',
                'issue_description' => '',
                'urgency' => 'medium',
                'location' => '',
                'service_type' => 'repair',
                'additional_info' => ''
            ];
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Database error creating quote request: " . $e->getMessage());
            $error = "Failed to create quote request. Please try again later.";
        }
    }
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
    <title>New Quote Request - FixItNow</title>
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
        
        .dashboard-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease;
        }
        
        /* Form Card */
        .form-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.5rem 1.5rem var(--shadow-color);
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .form-card .card-header {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        
        .form-card .card-body {
            padding: 2rem;
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
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        .form-text {
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        /* Device type selection cards */
        .device-type-card {
            border: 2px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .device-type-card i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
            transition: all 0.2s ease;
        }
        
        .device-type-card-title {
            margin-bottom: 0;
            font-weight: 500;
        }
        
        .device-type-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
            box-shadow: 0 5px 15px var(--shadow-color);
        }
        
        .device-type-card:hover i {
            color: var(--primary-color);
        }
        
        .device-type-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(var(--bs-primary-rgb), 0.1);
        }
        
        .device-type-card.selected i {
            color: var(--primary-color);
        }
        
        /* Success page */
        .success-page {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .success-icon {
            font-size: 5rem;
            color: var(--accent-color);
            margin-bottom: 1.5rem;
            animation: scale-up 0.5s ease-out;
        }
        
        @keyframes scale-up {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            70% {
                transform: scale(1.2);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
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
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .form-card .card-body {
                padding: 1.5rem;
            }
            
            .device-type-row {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .form-card .card-body {
                padding: 1rem;
            }
            
            .device-type-row {
                grid-template-columns: repeat(2, 1fr);
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
                    <!-- Notifications -->
                    <div class="dropdown me-3">
                        <button class="btn btn-dark position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if(count($notifications) > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo count($notifications); ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                            <li><h6 class="dropdown-header">Notifications</h6></li>
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted">No new notifications</div></li>
                            <?php else: ?>
                                <?php foreach($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item" href="notifications.php">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($notification['message'] ?? 'New notification'); ?></h6>
                                            <small class="text-muted"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['device_type'] ?? ''); ?></small>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="notifications.php">View all notifications</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <!-- Theme Toggle -->
                    <button class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon theme-icon-dark d-none"></i>
                        <i class="fas fa-sun theme-icon-light"></i>
                    </button>
                    
                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="btn btn-dark d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline">
                                <?php 
                                // Display either name or username, depending on what's available
                                if (isset($userData['first_name']) && !empty($userData['first_name'])) {
                                    echo htmlspecialchars($userData['first_name'] . ' ' . ($userData['last_name'] ?? ''));
                                } elseif (isset($userData['username']) && !empty($userData['username'])) {
                                    echo htmlspecialchars($userData['username']);
                                } else {
                                    echo 'User';
                                }
                                ?>
                            </span>
                            <i class="fas fa-chevron-down ms-2 small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
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
                    <a href="my-quotes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-quotes.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="new-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'new-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i> New Request
                    </a>
                </li>
                <li>
                    <a href="history.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'history.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> History
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo count($notifications); ?></span>
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
            <?php if ($success): ?>
            <!-- Success Page -->
            <div class="success-page">
                <div class="success-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <h2 class="mb-4">Quote Request Submitted Successfully!</h2>
                <p class="lead mb-4">Our technicians will review your request and provide quotes shortly.</p>
                <div class="d-grid gap-3 d-md-flex justify-content-md-center">
                    <a href="my-quotes.php" class="btn btn-primary btn-lg me-md-2">
                        <i class="fas fa-file-invoice-dollar me-2"></i> View My Quotes
                    </a>
                    <a href="new-request.php" class="btn btn-outline-primary btn-lg">
                        <i class="fas fa-plus-circle me-2"></i> Create Another Request
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h2 class="fw-bold mb-0">Create New Quote Request</h2>
            </div>
            
            <?php if(!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Request Form -->
            <div class="form-card">
                <div class="card-header">
                    <h4 class="mb-0">
                        <i class="fas fa-file-invoice-dollar me-2"></i> New Repair Quote
                    </h4>
                    <p class="text-muted mb-0">Fill in the details below to receive quotes from qualified technicians</p>
                </div>
                <div class="card-body">
                    <form action="new-request.php" method="post" id="quoteForm">
                        <!-- Step 1: Device Type Selection -->
                        <div class="mb-4">
                            <h5>1. Select your device type <span class="text-danger">*</span></h5>
                            <div class="row g-3 mt-2 device-type-row">
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'smartphone' ? 'selected' : ''; ?>" for="deviceTypeSmartphone">
                                        <input type="radio" name="device_type" id="deviceTypeSmartphone" value="smartphone" class="d-none" required <?php echo $formData['device_type'] === 'smartphone' ? 'checked' : ''; ?>>
                                        <i class="fas fa-mobile-alt"></i>
                                        <div class="device-type-card-title">Smartphone</div>
                                    </label>
                                </div>
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'laptop' ? 'selected' : ''; ?>" for="deviceTypeLaptop">
                                        <input type="radio" name="device_type" id="deviceTypeLaptop" value="laptop" class="d-none" required <?php echo $formData['device_type'] === 'laptop' ? 'checked' : ''; ?>>
                                        <i class="fas fa-laptop"></i>
                                        <div class="device-type-card-title">Laptop</div>
                                    </label>
                                </div>
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'tablet' ? 'selected' : ''; ?>" for="deviceTypeTablet">
                                        <input type="radio" name="device_type" id="deviceTypeTablet" value="tablet" class="d-none" required <?php echo $formData['device_type'] === 'tablet' ? 'checked' : ''; ?>>
                                        <i class="fas fa-tablet-alt"></i>
                                        <div class="device-type-card-title">Tablet</div>
                                    </label>
                                </div>
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'desktop' ? 'selected' : ''; ?>" for="deviceTypeDesktop">
                                        <input type="radio" name="device_type" id="deviceTypeDesktop" value="desktop" class="d-none" required <?php echo $formData['device_type'] === 'desktop' ? 'checked' : ''; ?>>
                                        <i class="fas fa-desktop"></i>
                                        <div class="device-type-card-title">Desktop</div>
                                    </label>
                                </div>
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'gaming' ? 'selected' : ''; ?>" for="deviceTypeGaming">
                                        <input type="radio" name="device_type" id="deviceTypeGaming" value="gaming" class="d-none" required <?php echo $formData['device_type'] === 'gaming' ? 'checked' : ''; ?>>
                                        <i class="fas fa-gamepad"></i>
                                        <div class="device-type-card-title">Gaming</div>
                                    </label>
                                </div>
                                <div class="col-md-2 col-sm-4 col-6">
                                    <label class="device-type-card <?php echo $formData['device_type'] === 'tv' ? 'selected' : ''; ?>" for="deviceTypeTV">
                                        <input type="radio" name="device_type" id="deviceTypeTV" value="tv" class="d-none" required <?php echo $formData['device_type'] === 'tv' ? 'checked' : ''; ?>>
                                        <i class="fas fa-tv"></i>
                                        <div class="device-type-card-title">TV</div>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Step 2: Device Details -->
                        <div class="mb-4">
                            <h5>2. Device Details</h5>
                            <div class="row mt-3">
                                <div class="col-md-6 mb-3">
                                    <label for="deviceBrand" class="form-label">Brand</label>
                                    <input type="text" class="form-control" id="deviceBrand" name="device_brand" placeholder="e.g., Apple, Samsung, Dell" value="<?php echo htmlspecialchars($formData['device_brand']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="deviceModel" class="form-label">Model</label>
                                    <input type="text" class="form-control" id="deviceModel" name="device_model" placeholder="e.g., iPhone 12, Galaxy S21" value="<?php echo htmlspecialchars($formData['device_model']); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="deviceCondition" class="form-label">Device Condition</label>
                                    <select class="form-select" id="deviceCondition" name="device_condition">
                                        <option value="" <?php echo empty($formData['device_condition']) ? 'selected' : ''; ?> disabled>Select condition</option>
                                        <option value="excellent" <?php echo $formData['device_condition'] === 'excellent' ? 'selected' : ''; ?>>Excellent - Like new</option>
                                        <option value="good" <?php echo $formData['device_condition'] === 'good' ? 'selected' : ''; ?>>Good - Minor wear and tear</option>
                                        <option value="fair" <?php echo $formData['device_condition'] === 'fair' ? 'selected' : ''; ?>>Fair - Noticeable wear</option>
                                        <option value="poor" <?php echo $formData['device_condition'] === 'poor' ? 'selected' : ''; ?>>Poor - Significant damage</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Step 3: Issue Description -->
                        <div class="mb-4">
                            <h5>3. Issue Description</h5>
                            <div class="mt-3">
                                <label for="issueDescription" class="form-label">Describe the issue with your device <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="issueDescription" name="issue_description" rows="4" required placeholder="Provide as much detail as possible about the problem..."><?php echo htmlspecialchars($formData['issue_description']); ?></textarea>
                                <div class="form-text">Be specific about the symptoms, when it started, and any troubleshooting you've already tried.</div>
                            </div>
                        </div>
                        
                        <hr class="my-4">
                        
                        <!-- Step 4: Additional Information -->
                        <div class="mb-4">
                            <h5>4. Additional Information</h5>
                            <div class="row mt-3">
                                <div class="col-md-4 mb-3">
                                    <label for="urgency" class="form-label">Urgency</label>
                                    <select class="form-select" id="urgency" name="urgency">
                                        <option value="low" <?php echo $formData['urgency'] === 'low' ? 'selected' : ''; ?>>Low - Not urgent</option>
                                        <option value="medium" <?php echo $formData['urgency'] === 'medium' || empty($formData['urgency']) ? 'selected' : ''; ?>>Medium - Standard priority</option>
                                        <option value="high" <?php echo $formData['urgency'] === 'high' ? 'selected' : ''; ?>>High - Urgent</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="location" class="form-label">Your Location</label>
                                    <input type="text" class="form-control" id="location" name="location" placeholder="City or area" value="<?php echo htmlspecialchars($formData['location']); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="serviceType" class="form-label">Service Type</label>
                                    <select class="form-select" id="serviceType" name="service_type">
                                        <option value="repair" <?php echo $formData['service_type'] === 'repair' || empty($formData['service_type']) ? 'selected' : ''; ?>>Repair</option>
                                        <option value="diagnostic" <?php echo $formData['service_type'] === 'diagnostic' ? 'selected' : ''; ?>>Diagnostic Only</option>
                                        <option value="upgrade" <?php echo $formData['service_type'] === 'upgrade' ? 'selected' : ''; ?>>Upgrade/Modification</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="additionalInfo" class="form-label">Additional Information</label>
                                    <textarea class="form-control" id="additionalInfo" name="additional_info" rows="3" placeholder="Any other details you'd like to share..."><?php echo htmlspecialchars($formData['additional_info']); ?></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Submit Button -->
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-4">
                            <button type="submit" class="btn btn-submit">
                                <i class="fas fa-paper-plane me-2"></i> Submit Quote Request
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
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
        document.addEventListener('DOMContentLoaded', function() {
            // Hide spinner when page is loaded
            const loadingSpinner = document.getElementById('loadingSpinner');
            if (loadingSpinner) {
                loadingSpinner.classList.remove('show');
            }
            
            // Theme toggler
            const themeToggle = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const darkIcon = document.querySelector('.theme-icon-dark');
            const lightIcon = document.querySelector('.theme-icon-light');
            
            // Get current theme from attribute
            const currentTheme = htmlElement.getAttribute('data-bs-theme') || 'light';
            
            // Update icons based on current theme
            if (currentTheme === 'dark') {
                darkIcon.classList.remove('d-none');
                lightIcon.classList.add('d-none');
            } else {
                lightIcon.classList.remove('d-none');
                darkIcon.classList.add('d-none');
            }
            
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = htmlElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    htmlElement.setAttribute('data-bs-theme', newTheme);
                    localStorage.setItem('theme', newTheme);
                    
                    // Also set as cookie for server-side detection
                    document.cookie = `theme=${newTheme}; path=/; max-age=31536000`; // 1 year
                    
                    if (newTheme === 'dark') {
                        darkIcon.classList.remove('d-none');
                        lightIcon.classList.add('d-none');
                    } else {
                        lightIcon.classList.remove('d-none');
                        darkIcon.classList.add('d-none');
                    }
                });
            }
            
            // Device type selection
            const deviceTypeInputs = document.querySelectorAll('input[name="device_type"]');
            if (deviceTypeInputs.length > 0) {
                deviceTypeInputs.forEach(radio => {
                    radio.addEventListener('change', function() {
                        // Remove selected class from all cards
                        document.querySelectorAll('.device-type-card').forEach(card => {
                            card.classList.remove('selected');
                        });
                        
                        // Add selected class to checked radio's parent card
                        if (this.checked) {
                            this.closest('.device-type-card').classList.add('selected');
                        }
                    });
                });
            }
            
            // Form submission with loading spinner
            const quoteForm = document.getElementById('quoteForm');
            if (quoteForm) {
                quoteForm.addEventListener('submit', function(e) {
                    // Basic client-side validation
                    const deviceType = document.querySelector('input[name="device_type"]:checked');
                    const issueDescription = document.getElementById('issueDescription');
                    
                    let isValid = true;
                    let errorMessage = '';
                    
                    if (!deviceType) {
                        isValid = false;
                        errorMessage = 'Please select a device type';
                    } else if (!issueDescription.value.trim()) {
                        isValid = false;
                        errorMessage = 'Please describe the issue with your device';
                    }
                    
                    if (!isValid) {
                        e.preventDefault();
                        alert(errorMessage);
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