<?php
// Turn off all error reporting for production
error_reporting(0);
ini_set('display_errors', 0);

session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not provider
if (!$loggedIn || $userRole !== 'provider') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Get request ID from URL
$requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;

if ($requestId <= 0) {
    header('Location: quotes.php?error=invalid_id');
    exit;
}

// Get provider profile information
$providerProfileImage = '../default.png';
$providerId = 0;
$providerData = []; // Initialize as empty array to prevent undefined variable errors

try {
    // Query to get provider user data
    $stmt = $pdo->prepare("SELECT u.*, p.id as provider_id, p.is_verified, p.specialties, p.experience 
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider'");
    $stmt->execute([$userId]);
    $providerDataResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($providerDataResult) {
        $providerData = $providerDataResult;
        $providerId = $providerData['provider_id'];
        $providerSpecialties = !empty($providerData['specialties']) ? explode(',', $providerData['specialties']) : [];
        
        // Set profile image path
        if (!empty($providerData['profile_image'])) {
            if (preg_match('/^https?:\/\//', $providerData['profile_image'])) {
                $providerProfileImage = $providerData['profile_image'];
            } else {
                $imagePath = '../profile_images/' . basename($providerData['profile_image']);
                if (file_exists($imagePath)) {
                    $providerProfileImage = $imagePath;
                }
            }
        }
    } else {
        // Redirect to error page if user is not a provider
        header('Location: ../error.php?message=Provider%20data%20not%20found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    // Redirect to error page
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Fetch quote request details
try {
    $stmt = $pdo->prepare("
        SELECT qr.*, u.* 
        FROM quote_requests qr
        JOIN users u ON qr.customer_id = u.id
        JOIN quote_notifications qn ON qn.request_id = qr.id 
        WHERE qr.id = ? AND qn.recipient_id = ? AND qn.type = 'new_request'
        LIMIT 1
    ");
    $stmt->execute([$requestId, $userId]);
    $quoteRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quoteRequest) {
        // Quote request doesn't exist or not assigned to this provider
        header('Location: quotes.php?error=access_denied');
        exit;
    }
    
    // Check if this device type is within provider's specialties
    if (!empty($providerSpecialties) && !in_array($quoteRequest['device_type'], $providerSpecialties)) {
        // Quote request is for a device type not in provider's specialties
        header('Location: quotes.php?error=device_not_supported');
        exit;
    }
    
    // Fetch the existing quote for editing
    $stmt = $pdo->prepare("
        SELECT * 
        FROM quotes 
        WHERE request_id = ? AND technician_id = ?
        LIMIT 1
    ");
    $stmt->execute([$requestId, $userId]);
    $existingQuote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if provider has a quote to edit
    if (!$existingQuote) {
        // No quote found to edit, redirect to create quote page
        header('Location: quote-detail.php?id=' . $requestId);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching quote data: " . $e->getMessage());
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Process form submission
$updateSuccess = false;
$updateError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_quote'])) {
    $price = isset($_POST['price']) ? filter_var($_POST['price'], FILTER_VALIDATE_FLOAT) : 0;
    $estimatedTime = isset($_POST['estimated_time']) ? trim($_POST['estimated_time']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $warranty = isset($_POST['warranty']) ? trim($_POST['warranty']) : 'Standard warranty';
    $partsNeeded = isset($_POST['parts_needed']) ? trim($_POST['parts_needed']) : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    // Validate inputs
    if ($price <= 0) {
        $updateError = 'Please enter a valid price.';
    } elseif (empty($estimatedTime)) {
        $updateError = 'Please enter an estimated repair time.';
    } elseif (empty($description)) {
        $updateError = 'Please enter a repair description.';
    } else {
        try {
            // Update the quote
            $stmt = $pdo->prepare("
                UPDATE quotes 
                SET price = ?, estimated_time = ?, description = ?, warranty = ?, parts_needed = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND technician_id = ?
            ");
            $stmt->execute([$price, $estimatedTime, $description, $warranty, $partsNeeded, $notes, $existingQuote['id'], $userId]);
            
            // Add notification for customer if necessary
            if ($quoteRequest['status'] !== 'quoted') {
                $stmt = $pdo->prepare("
                    INSERT INTO quote_notifications (recipient_id, request_id, type, message)
                    VALUES (?, ?, 'quote_updated', 'Your quote has been updated')
                ");
                $stmt->execute([$quoteRequest['customer_id'], $requestId]);
            }
            
            $updateSuccess = true;
            
            // Refresh the quote data
            $stmt = $pdo->prepare("
                SELECT * 
                FROM quotes 
                WHERE request_id = ? AND technician_id = ?
                LIMIT 1
            ");
            $stmt->execute([$requestId, $userId]);
            $existingQuote = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Database error updating quote: " . $e->getMessage());
            $updateError = 'Database error. Please try again.';
        }
    }
}

// Helper functions for displaying data
function formatDeviceCondition($condition) {
    switch ($condition) {
        case 'excellent': return 'Excellent';
        case 'good': return 'Good';
        case 'fair': return 'Fair';
        case 'poor': return 'Poor';
        default: return ucfirst($condition);
    }
}

function formatUrgency($urgency) {
    switch ($urgency) {
        case 'high': return 'High Priority';
        case 'medium': return 'Medium Priority';
        case 'low': return 'Low Priority';
        default: return ucfirst($urgency);
    }
}

function getDeviceIcon($deviceType) {
    switch ($deviceType) {
        case 'smartphone': return 'fa-mobile-alt';
        case 'laptop': return 'fa-laptop';
        case 'tablet': return 'fa-tablet-alt';
        case 'desktop': return 'fa-desktop';
        case 'gaming': return 'fa-gamepad';
        case 'tv': return 'fa-tv';
        default: return 'fa-mobile-alt';
    }
}

function timeElapsedString($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Quote - Provider Dashboard - FixItNow</title>
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
            --sidebar-bg: #303238;         /* Sidebar background */
            --sidebar-hover: #3e4148;      /* Sidebar hover */
        }
        
        /* Dark Theme Variables - Enhanced for better vibrancy */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #ffffff;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #18181b;           /* Slightly less dark background */
            --card-bg: #242429;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #242429;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
            display: flex;
            min-height: 100vh;
            flex-direction: column;
        }
        
        .main-container {
            display: flex;
            flex: 1;
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
        
        /* Currency Icon */
        .currency-icon {
            vertical-align: middle;
            margin-right: 3px;
            margin-top: -3px;
        }
        
        /* Sidebar Styles */
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            flex-shrink: 0;
            box-shadow: 0.25rem 0 1rem var(--shadow-color);
            transition: all 0.3s ease;
            z-index: 999;
            position: fixed;
            height: 100%;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 76px;
            }
            
            .sidebar .nav-text {
                display: none;
            }
            
            .sidebar .nav-link {
                justify-content: center;
            }
            
            .content-area {
                margin-left: 76px;
            }
        }
        
        .sidebar-header {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .sidebar-nav {
            padding: 1rem 0;
        }
        
        .sidebar-nav .nav-link {
            color: var(--footer-text);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .sidebar-nav .nav-link:hover {
            color: var(--header-text);
            background-color: var(--sidebar-hover);
        }
        
        .sidebar-nav .nav-link.active {
            color: var(--header-text);
            background: linear-gradient(90deg, var(--primary-color) 0%, transparent 100%);
        }
        
        .sidebar-nav .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 4px;
            background-color: var(--accent-color);
        }
        
        .sidebar-nav .nav-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        /* Content Area */
        .content-area {
            flex: 1;
            padding: 2rem;
            margin-left: 250px;
            transition: all 0.3s ease;
        }
        
        .page-title {
            font-weight: 700;
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        
        .page-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -8px;
            width: 60px;
            height: 3px;
            background-color: var(--primary-color);
        }
        
        /* Card Styles */
        .card {
            border-radius: 0.75rem;
            border: none;
            background-color: var(--card-bg);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .card-footer {
            background-color: rgba(0, 0, 0, 0.05);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.quoted {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.accepted {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.completed {
            background-color: rgba(13, 202, 240, 0.2);
            color: #0dcaf0;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        /* Device type icons */
        .device-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
        }
        
        .device-icon.smartphone {
            background-color: #007bff;
        }
        
        .device-icon.laptop {
            background-color: #6f42c1;
        }
        
        .device-icon.tablet {
            background-color: #17a2b8;
        }
        
        .device-icon.desktop {
            background-color: #20c997;
        }
        
        .device-icon.gaming {
            background-color: #e83e8c;
        }
        
        .device-icon.tv {
            background-color: #fd7e14;
        }
        
        /* Urgency indicators */
        .urgency-indicator {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .urgency-indicator.high {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .urgency-indicator.medium {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .urgency-indicator.low {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        /* Info rows */
        .info-row {
            display: flex;
            margin-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        
        .info-label {
            flex: 0 0 150px;
            font-weight: 600;
        }
        
        .info-value {
            flex: 1;
        }
        
        /* Alert styles */
        .alert {
            border-radius: 0.5rem;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }
        
        /* Quote form */
        .quote-form {
            margin-top: 1.5rem;
        }
        
        .quote-form .form-label {
            font-weight: 600;
        }
        
        .quote-form .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
        }
        
        .quote-form .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
        }
        
        /* Price unit display */
        .price-input-group {
            position: relative;
        }
        
        .price-input-group .form-control {
            padding-left: 3rem;
        }
        
        .currency-symbol {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            display: flex;
            align-items: center;
            padding: 0 1rem;
            font-weight: 600;
            background-color: rgba(0, 0, 0, 0.05);
            border-top-left-radius: 0.5rem;
            border-bottom-left-radius: 0.5rem;
            border-right: 1px solid var(--input-border);
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .btn-submit {
            background-color: var(--accent-color);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-submit:hover {
            background-color: var(--accent-light);
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .btn-cancel {
            background-color: transparent;
            color: var(--text-color);
            border: 1px solid var(--border-color);
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-cancel:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        /* Request summary box */
        .request-summary {
            background-color: rgba(0, 0, 0, 0.03);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .request-summary h5 {
            margin-bottom: 1rem;
            font-weight: 600;
        }
        
        .device-summary {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .customer-summary {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }
        
        .issue-summary {
            background-color: rgba(0, 0, 0, 0.05);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 1rem;
            font-size: 0.9rem;
            max-height: 100px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="dashboard.php" class="text-decoration-none d-flex align-items-center">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <span class="ms-3 text-white badge bg-primary">Provider Portal</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($providerProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo isset($providerData['first_name']) ? htmlspecialchars($providerData['first_name']) : 'Provider'; ?></span>
                            <?php if(isset($providerData['is_verified']) && $providerData['is_verified'] == 1): ?>
                                <i class="fas fa-tools text-primary ms-1" title="Verified Provider"></i>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="services.php"><i class="fas fa-cogs me-2"></i> My Services</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <div class="main-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">
                            <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="schedule.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">My Schedule</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">My Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="earnings.php">
                            <span class="nav-icon"><i class="fas fa-wallet"></i></span>
                            <span class="nav-text">Earnings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <div class="container-fluid px-0">
                <!-- Back link -->
                <div class="mb-4">
                    <a href="quote-detail.php?id=<?php echo $requestId; ?>" class="text-decoration-none d-inline-flex align-items-center">
                        <i class="fas fa-arrow-left me-2"></i> Back to Quote Details
                    </a>
                </div>
                
                <!-- Page Title and Status -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2 class="page-title">Edit Quote</h2>
                        <p class="text-muted mb-0">
                            Request #<?php echo $requestId; ?> - Quote originally submitted <?php echo timeElapsedString($existingQuote['created_at']); ?>
                        </p>
                    </div>
                    <div>
                        <?php 
                        $statusClass = 'pending';
                        if ($quoteRequest['status'] === 'quoted') $statusClass = 'quoted';
                        elseif ($quoteRequest['status'] === 'accepted') $statusClass = 'accepted';
                        elseif ($quoteRequest['status'] === 'completed') $statusClass = 'completed';
                        elseif ($quoteRequest['status'] === 'cancelled') $statusClass = 'cancelled';
                        ?>
                        <span class="status-badge <?php echo $statusClass; ?>">
                            <?php echo ucfirst(htmlspecialchars($quoteRequest['status'])); ?>
                        </span>
                    </div>
                </div>
                
                <?php if($updateSuccess): ?>
                <div class="alert alert-success" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    Your quote has been updated successfully.
                </div>
                <?php elseif(!empty($updateError)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo htmlspecialchars($updateError); ?>
                </div>
                <?php endif; ?>
                
                <!-- Request Summary -->
                <div class="request-summary">
                    <h5>Request Summary</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="device-summary">
                                <div class="device-icon <?php echo htmlspecialchars($quoteRequest['device_type']); ?>" style="margin-right: 1rem;">
                                    <i class="fas <?php echo getDeviceIcon($quoteRequest['device_type']); ?>"></i>
                                </div>
                                <div>
                                    <div class="fw-bold"><?php echo ucfirst(htmlspecialchars($quoteRequest['device_type'])); ?></div>
                                    <?php if (!empty($quoteRequest['device_brand']) && $quoteRequest['device_brand'] !== 'Not specified'): ?>
                                        <?php if (!empty($quoteRequest['device_model']) && $quoteRequest['device_model'] !== 'Not specified'): ?>
                                            <div class="text-muted"><?php echo htmlspecialchars($quoteRequest['device_brand'] . ' ' . $quoteRequest['device_model']); ?></div>
                                        <?php else: ?>
                                            <div class="text-muted"><?php echo htmlspecialchars($quoteRequest['device_brand']); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="customer-summary">
                                <div class="customer-avatar">
                                    <?php 
                                    $initials = strtoupper(substr($quoteRequest['first_name'], 0, 1) . substr($quoteRequest['last_name'], 0, 1));
                                    echo $initials;
                                    ?>
                                </div>
                                <div>
                                    <div class="fw-bold"><?php echo htmlspecialchars($quoteRequest['first_name'] . ' ' . $quoteRequest['last_name']); ?></div>
                                    <div class="text-muted"><?php echo htmlspecialchars($quoteRequest['email']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="issue-summary mt-3">
                        <?php echo nl2br(htmlspecialchars($quoteRequest['issue_description'])); ?>
                    </div>
                </div>
                
                <!-- Edit Quote Form -->
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-edit me-2"></i>Edit Your Quote
                    </div>
                    <div class="card-body">
                        <form class="quote-form" method="post" action="">
                            <div class="mb-3">
                                <label for="price" class="form-label">السعر (ريال)</label>
                                <div class="price-input-group">
                                    <span class="currency-symbol">
                                        <img src="sar/sar.png" alt="ريال" width="16" height="16" style="margin-right: 4px;">
                                    </span>
                                    <input type="number" step="0.01" min="0" class="form-control" id="price" name="price" value="<?php echo htmlspecialchars($existingQuote['price']); ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="estimated_time" class="form-label">Estimated Time</label>
                                <input type="text" class="form-control" id="estimated_time" name="estimated_time" 
                                       value="<?php echo htmlspecialchars($existingQuote['estimated_time']); ?>"
                                       placeholder="e.g. 2-3 hours, 1-2 days" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="warranty" class="form-label">Warranty</label>
                                <select class="form-select" id="warranty" name="warranty">
                                    <option value="Standard warranty" <?php echo $existingQuote['warranty'] === 'Standard warranty' ? 'selected' : ''; ?>>Standard warranty</option>
                                    <option value="30 days warranty" <?php echo $existingQuote['warranty'] === '30 days warranty' ? 'selected' : ''; ?>>30 days warranty</option>
                                    <option value="60 days warranty" <?php echo $existingQuote['warranty'] === '60 days warranty' ? 'selected' : ''; ?>>60 days warranty</option>
                                    <option value="90 days warranty" <?php echo $existingQuote['warranty'] === '90 days warranty' ? 'selected' : ''; ?>>90 days warranty</option>
                                    <option value="6 months warranty" <?php echo $existingQuote['warranty'] === '6 months warranty' ? 'selected' : ''; ?>>6 months warranty</option>
                                    <option value="1 year warranty" <?php echo $existingQuote['warranty'] === '1 year warranty' ? 'selected' : ''; ?>>1 year warranty</option>
                                    <option value="No warranty" <?php echo $existingQuote['warranty'] === 'No warranty' ? 'selected' : ''; ?>>No warranty</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="parts_needed" class="form-label">Parts Needed (Optional)</label>
                                <textarea class="form-control" id="parts_needed" name="parts_needed" rows="2"><?php echo htmlspecialchars($existingQuote['parts_needed']); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Repair Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4" required><?php echo htmlspecialchars($existingQuote['description']); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="notes" class="form-label">Additional Notes (Optional)</label>
                                <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo htmlspecialchars($existingQuote['notes']); ?></textarea>
                            </div>
                            
                            <div class="action-buttons">
                                <button type="submit" name="update_quote" class="btn btn-submit">
                                    <i class="fas fa-save me-2"></i>Update Quote
                                </button>
                                <a href="quote-detail.php?id=<?php echo $requestId; ?>" class="btn btn-cancel">
                                    <i class="fas fa-times me-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Theme toggle functionality
            const themeToggleBtn = document.getElementById('themeToggle');
            const htmlElement = document.documentElement;
            const themeIcon = document.getElementById('themeIcon');
            
            // Function to set theme
            function setTheme(isDark) {
                if (isDark) {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                }
            }
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                setTheme(savedTheme === 'dark');
            } else {
                // Default to dark theme
                setTheme(true);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
        });
    </script>
</body>
</html>