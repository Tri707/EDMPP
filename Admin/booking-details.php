<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not admin
if (!$loggedIn || $userRole !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Check if booking ID is provided
$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$bookingId) {
    // Redirect if no ID provided
    header('Location: bookings.php');
    exit;
}

// Process actions
$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update booking status
    if (isset($_POST['action']) && $_POST['action'] === 'updateStatus') {
        try {
            $newStatus = clean_input($_POST['status']);
            $notes = isset($_POST['notes']) ? clean_input($_POST['notes']) : '';
            
            // Validate status values
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception("Invalid status value.");
            }
            
            // Update booking status
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$newStatus, $bookingId]);
            
            // Add note if provided
            if (!empty($notes)) {
                $timestamp = date('Y-m-d H:i:s');
                $noteEntry = "[{$timestamp}] Status changed to {$newStatus}: {$notes}";
                
                // Fixed query - corrected CONCAT syntax
                $stmt = $pdo->prepare("
                    UPDATE bookings 
                    SET notes = CONCAT(COALESCE(notes, ''), ?, ?)
                    WHERE id = ?
                ");
                
                $stmt->execute([PHP_EOL, $noteEntry, $bookingId]);
            }
            
            $actionMessage = "Booking status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Database error: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = $e->getMessage();
            $actionType = "warning";
        }
    }
    
    // Update payment status
    if (isset($_POST['action']) && $_POST['action'] === 'updatePayment') {
        try {
            $newPaymentStatus = clean_input($_POST['payment_status']);
            
            // Validate payment status values
            $validPaymentStatuses = ['unpaid', 'paid', 'refunded'];
            if (!in_array($newPaymentStatus, $validPaymentStatuses)) {
                throw new Exception("Invalid payment status value.");
            }
            
            // Update payment status
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET payment_status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([$newPaymentStatus, $bookingId]);
            
            $actionMessage = "Payment status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Database error: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = $e->getMessage();
            $actionType = "warning";
        }
    }
    
    // Add note to booking
    if (isset($_POST['action']) && $_POST['action'] === 'addNote') {
        try {
            $note = clean_input($_POST['note']);
            
            if (empty($note)) {
                throw new Exception("Note cannot be empty.");
            }
            
            // Add note - Fixed query with corrected CONCAT syntax
            $timestamp = date('Y-m-d H:i:s');
            $noteEntry = "[{$timestamp}] Admin note: {$note}";
            
            $stmt = $pdo->prepare("
                UPDATE bookings 
                SET notes = CONCAT(COALESCE(notes, ''), ?, ?),
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([PHP_EOL, $noteEntry, $bookingId]);
            
            $actionMessage = "Note added successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Database error: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        } catch (Exception $e) {
            $actionMessage = $e->getMessage();
            $actionType = "warning";
        }
    }
}

// Get booking details
$booking = null;

try {
    // Query to get detailed booking information
    $stmt = $pdo->prepare("
        SELECT b.*,
               c.id as customer_id,
               c.first_name as customer_first_name,
               c.last_name as customer_last_name,
               c.email as customer_email,
               c.phone as customer_phone,
               c.profile_image as customer_profile_image,
               p.id as provider_id,
               p.specialties as provider_specialties,
               p.hourly_rate as provider_rate,
               t.id as technician_user_id,
               t.first_name as technician_first_name,
               t.last_name as technician_last_name,
               t.email as technician_email,
               t.phone as technician_phone,
               t.profile_image as technician_profile_image,
               s.id as service_id,
               s.name as service_name,
               s.category as service_category,
               s.description as service_description,
               s.duration as service_duration
        FROM bookings b
        JOIN users c ON b.customer_id = c.id
        JOIN providers p ON b.provider_id = p.id
        JOIN users t ON p.user_id = t.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE b.id = ?
    ");
    
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        // Redirect if booking not found
        header('Location: bookings.php?error=notfound');
        exit;
    }
    
    // Format customer profile image
    $customerProfileImage = '../default.png';
    if (!empty($booking['customer_profile_image'])) {
        if (preg_match('/^https?:\/\//', $booking['customer_profile_image'])) {
            $customerProfileImage = $booking['customer_profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($booking['customer_profile_image']);
            if (file_exists($imagePath)) {
                $customerProfileImage = $imagePath;
            }
        }
    }
    
    // Format technician profile image
    $technicianProfileImage = '../default.png';
    if (!empty($booking['technician_profile_image'])) {
        if (preg_match('/^https?:\/\//', $booking['technician_profile_image'])) {
            $technicianProfileImage = $booking['technician_profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($booking['technician_profile_image']);
            if (file_exists($imagePath)) {
                $technicianProfileImage = $imagePath;
            }
        }
    }
    
    // Process notes for display
    $notes = [];
    if (!empty($booking['notes'])) {
        $notesLines = explode("\n", $booking['notes']);
        foreach ($notesLines as $line) {
            if (!empty(trim($line))) {
                $notes[] = trim($line);
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching booking data: " . $e->getMessage());
    header('Location: bookings.php?error=db');
    exit;
}

// Get recent messages for this booking
$messages = [];
try {
    $stmt = $pdo->prepare("
        SELECT m.*,
               u.first_name, u.last_name, u.role, u.profile_image
        FROM messages m
        JOIN users u ON m.sender_id = u.id
        WHERE m.booking_id = ?
        ORDER BY m.created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute([$bookingId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reverse for chronological order
    $messages = array_reverse($messages);
} catch (PDOException $e) {
    error_log("Database error fetching messages: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details - FixItNow Admin</title>
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
        
        /* Sidebar Styles */
        .sidebar {
            width: 280px;
            background-color: var(--sidebar-bg);
            flex-shrink: 0;
            box-shadow: 0.25rem 0 1rem var(--shadow-color);
            transition: all 0.3s ease;
            z-index: 999;
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
        
        /* Booking Header */
        .booking-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        
        .booking-id {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .booking-date {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.confirmed {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        .status-badge.unpaid {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.paid {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.refunded {
            background-color: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        /* Profile sections */
        .profile-section {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .profile-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-info {
            flex: 1;
        }
        
        .profile-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .profile-meta {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .profile-meta div {
            margin-bottom: 0.15rem;
        }
        
        .profile-meta i {
            width: 18px;
            text-align: center;
            margin-right: 0.5rem;
        }
        
        /* Info list */
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-list li {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .info-list li:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .info-value {
            font-weight: 500;
            text-align: right;
        }
        
        /* Service info */
        .service-card {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-left: 4px solid var(--primary-color);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .service-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .service-meta {
            display: flex;
            justify-content: space-between;
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .service-description {
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        /* Notes section */
        .notes-timeline {
            list-style: none;
            padding-left: 1.5rem;
            position: relative;
        }
        
        .notes-timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0.35rem;
            width: 2px;
            background-color: var(--border-color);
        }
        
        .note-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .note-item::before {
            content: '';
            position: absolute;
            left: -1.5rem;
            top: 0.3rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background-color: var(--primary-color);
        }
        
        .note-timestamp {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .note-content {
            background-color: rgba(var(--bs-light-rgb), 0.05);
            padding: 0.75rem;
            border-radius: 0.5rem;
            border-left: 3px solid var(--primary-color);
        }
        
        /* Messages */
        .message-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .message-item {
            display: flex;
            margin-bottom: 1.5rem;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
            flex-shrink: 0;
        }
        
        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .message-bubble {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            max-width: 80%;
            position: relative;
        }
        
        .message-bubble::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 15px;
            width: 0;
            height: 0;
            border-top: 6px solid transparent;
            border-bottom: 6px solid transparent;
            border-right: 8px solid rgba(var(--bs-primary-rgb), 0.1);
        }
        
        .message-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .message-text {
            font-size: 0.9rem;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            text-align: right;
        }
        
        /* Form controls */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
        }
        
        /* Price display */
        .price-display {
            display: flex;
            align-items: center;
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .price-currency {
            height: 20px;
            margin-right: 0.25rem;
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
                    <span class="ms-3 text-white badge bg-danger">Admin Panel</span>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <?php if($loggedIn && isset($adminData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($adminData['first_name']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i> Settings</a></li>
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
                        <a class="nav-link" href="users.php">
                            <span class="nav-icon"><i class="fas fa-users"></i></span>
                            <span class="nav-text">Users</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="technicians.php">
                            <span class="nav-icon"><i class="fas fa-user-cog"></i></span>
                            <span class="nav-text">Technicians</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
                            <span class="nav-icon"><i class="fas fa-cogs"></i></span>
                            <span class="nav-text">Services</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star"></i></span>
                            <span class="nav-text">Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">
                            <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                            <span class="nav-text">Reports</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog"></i></span>
                            <span class="nav-text">Settings</span>
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
            <!-- Back button and page actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="bookings.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Bookings
                </a>
                
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#updateStatusModal">
                        <i class="fas fa-exchange-alt me-2"></i>Update Status
                    </button>
                    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#updatePaymentModal">
                        <i class="fas fa-money-bill-wave me-2"></i>Update Payment
                    </button>
                    <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                        <i class="fas fa-comment-dots me-2"></i>Add Note
                    </button>
                </div>
            </div>
            
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Booking Header -->
            <div class="booking-header">
                <div>
                    <div class="booking-id">Booking #<?php echo $booking['id']; ?></div>
                    <div class="booking-date">Created on <?php echo date('F j, Y', strtotime($booking['created_at'])); ?> at <?php echo date('h:i A', strtotime($booking['created_at'])); ?></div>
                </div>
                <div class="d-flex gap-2">
                    <div class="status-badge <?php echo $booking['status']; ?>">
                        <i class="fas fa-circle me-2"></i>
                        <?php echo ucfirst($booking['status']); ?>
                    </div>
                    <div class="status-badge <?php echo $booking['payment_status']; ?>">
                        <i class="fas fa-money-bill me-2"></i>
                        <?php echo ucfirst($booking['payment_status']); ?>
                    </div>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Left Column -->
                <div class="col-lg-8">
                    <!-- Booking Details Card -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Booking Details</h5>
                            <div class="price-display">
                                <img src="../sar/sar.png" alt="SAR" class="price-currency">
                                <?php echo number_format($booking['total_price'], 2); ?>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-3">Customer</h6>
                                    <div class="profile-section">
                                        <div class="profile-avatar">
                                            <img src="<?php echo $customerProfileImage; ?>" alt="Customer">
                                        </div>
                                        <div class="profile-info">
                                            <div class="profile-name"><?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?></div>
                                            <div class="profile-meta">
                                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($booking['customer_email']); ?></div>
                                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($booking['customer_phone']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="text-muted mb-3">Technician</h6>
                                    <div class="profile-section">
                                        <div class="profile-avatar">
                                            <img src="<?php echo $technicianProfileImage; ?>" alt="Technician">
                                        </div>
                                        <div class="profile-info">
                                            <div class="profile-name"><?php echo htmlspecialchars($booking['technician_first_name'] . ' ' . $booking['technician_last_name']); ?></div>
                                            <div class="profile-meta">
                                                <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($booking['technician_email']); ?></div>
                                                <div><i class="fas fa-phone"></i> <?php echo htmlspecialchars($booking['technician_phone']); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <hr class="my-4">
                            
                            <!-- Service Details -->
                            <?php if (!empty($booking['service_id'])): ?>
                            <h6 class="text-muted mb-3">Service</h6>
                            <div class="service-card">
                                <div class="service-name"><?php echo htmlspecialchars($booking['service_name']); ?></div>
                                <div class="service-meta">
                                    <div><i class="fas fa-tag me-1"></i> <?php echo htmlspecialchars(ucfirst($booking['service_category'])); ?></div>
                                    <div><i class="fas fa-clock me-1"></i> <?php echo $booking['service_duration']; ?> min</div>
                                </div>
                                <div class="service-description">
                                    <?php echo htmlspecialchars($booking['service_description']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Booking Info List -->
                            <h6 class="text-muted mb-3">Appointment Details</h6>
                            <ul class="info-list">
                                <li>
                                    <div class="info-label">Date</div>
                                    <div class="info-value"><?php echo date('l, F j, Y', strtotime($booking['booking_date'])); ?></div>
                                </li>
                                <li>
                                    <div class="info-label">Time</div>
                                    <div class="info-value"><?php echo date('h:i A', strtotime($booking['booking_time'])); ?></div>
                                </li>
                                <li>
                                    <div class="info-label">Total Price</div>
                                    <div class="info-value">
                                        <img src="../sar/sar.png" alt="SAR" height="16" style="margin-right: 4px; vertical-align: text-bottom;">
                                        <?php echo number_format($booking['total_price'], 2); ?>
                                    </div>
                                </li>
                                <li>
                                    <div class="info-label">Payment Status</div>
                                    <div class="info-value">
                                        <span class="status-badge <?php echo $booking['payment_status']; ?>">
                                            <?php echo ucfirst($booking['payment_status']); ?>
                                        </span>
                                    </div>
                                </li>
                                <li>
                                    <div class="info-label">Booking Status</div>
                                    <div class="info-value">
                                        <span class="status-badge <?php echo $booking['status']; ?>">
                                            <?php echo ucfirst($booking['status']); ?>
                                        </span>
                                    </div>
                                </li>
                                <li>
                                    <div class="info-label">Last Updated</div>
                                    <div class="info-value"><?php echo date('F j, Y h:i A', strtotime($booking['updated_at'])); ?></div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Messages Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Messages</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($messages)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-comments fa-3x mb-3"></i>
                                <p>No messages found for this booking.</p>
                            </div>
                            <?php else: ?>
                            <ul class="message-list">
                                <?php foreach ($messages as $message): ?>
                                <li class="message-item">
                                    <div class="message-avatar">
                                        <?php 
                                        $messageAvatar = '../default.png';
                                        if (!empty($message['profile_image'])) {
                                            if (preg_match('/^https?:\/\//', $message['profile_image'])) {
                                                $messageAvatar = $message['profile_image'];
                                            } else {
                                                $imagePath = '../profile_images/' . basename($message['profile_image']);
                                                if (file_exists($imagePath)) {
                                                    $messageAvatar = $imagePath;
                                                }
                                            }
                                        }
                                        ?>
                                        <img src="<?php echo $messageAvatar; ?>" alt="User">
                                    </div>
                                    <div class="message-bubble">
                                        <div class="message-name">
                                            <?php echo htmlspecialchars($message['first_name'] . ' ' . $message['last_name']); ?>
                                            <span class="badge bg-<?php echo $message['role'] === 'admin' ? 'danger' : ($message['role'] === 'provider' ? 'primary' : 'info'); ?> ms-2">
                                                <?php echo ucfirst($message['role']); ?>
                                            </span>
                                        </div>
                                        <div class="message-text">
                                            <?php echo htmlspecialchars($message['message']); ?>
                                        </div>
                                        <div class="message-time">
                                            <?php echo date('M j, Y h:i A', strtotime($message['created_at'])); ?>
                                        </div>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="col-lg-4">
                    <!-- Status Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Quick Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($booking['status'] === 'pending'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="updateStatus">
                                    <input type="hidden" name="status" value="confirmed">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="fas fa-check-circle me-2"></i>Confirm Booking
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($booking['status'] === 'confirmed'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="updateStatus">
                                    <input type="hidden" name="status" value="completed">
                                    <button type="submit" class="btn btn-success w-100">
                                        <i class="fas fa-check-double me-2"></i>Mark as Completed
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($booking['status'] === 'pending' || $booking['status'] === 'confirmed'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="updateStatus">
                                    <input type="hidden" name="status" value="cancelled">
                                    <button type="submit" class="btn btn-danger w-100">
                                        <i class="fas fa-times-circle me-2"></i>Cancel Booking
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($booking['payment_status'] === 'unpaid'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="updatePayment">
                                    <input type="hidden" name="payment_status" value="paid">
                                    <button type="submit" class="btn btn-outline-success w-100">
                                        <i class="fas fa-money-bill-wave me-2"></i>Mark as Paid
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($booking['payment_status'] === 'paid'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="updatePayment">
                                    <input type="hidden" name="payment_status" value="refunded">
                                    <button type="submit" class="btn btn-outline-warning w-100">
                                        <i class="fas fa-undo me-2"></i>Mark as Refunded
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <button class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                                    <i class="fas fa-comment-dots me-2"></i>Add Note
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Notes Card -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Notes & History</h5>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addNoteModal">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($notes)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-sticky-note fa-3x mb-3"></i>
                                <p>No notes found for this booking.</p>
                            </div>
                            <?php else: ?>
                            <ul class="notes-timeline">
                                <?php foreach ($notes as $note): 
                                    // Extract timestamp and content from note
                                    if (preg_match('/\[(.*?)\](.*)/s', $note, $matches)) {
                                        $timestamp = $matches[1];
                                        $content = trim($matches[2]);
                                    } else {
                                        $timestamp = '';
                                        $content = $note;
                                    }
                                ?>
                                <li class="note-item">
                                    <?php if ($timestamp): ?>
                                    <div class="note-timestamp"><?php echo $timestamp; ?></div>
                                    <?php endif; ?>
                                    <div class="note-content">
                                        <?php echo htmlspecialchars($content); ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal fade" id="updateStatusModal" tabindex="-1" aria-labelledby="updateStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateStatusModalLabel">Update Booking Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="updateStatus">
                        
                        <div class="mb-3">
                            <label for="status" class="form-label">Booking Status</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Notes (optional)</label>
                            <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Add a note about this status change"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Update Payment Modal -->
    <div class="modal fade" id="updatePaymentModal" tabindex="-1" aria-labelledby="updatePaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updatePaymentModalLabel">Update Payment Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="updatePayment">
                        
                        <div class="mb-3">
                            <label for="payment_status" class="form-label">Payment Status</label>
                            <select class="form-select" id="payment_status" name="payment_status" required>
                                <option value="unpaid" <?php echo $booking['payment_status'] === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                                <option value="paid" <?php echo $booking['payment_status'] === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="refunded" <?php echo $booking['payment_status'] === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Payment</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Note Modal -->
    <div class="modal fade" id="addNoteModal" tabindex="-1" aria-labelledby="addNoteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addNoteModalLabel">Add Note</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="addNote">
                        
                        <div class="mb-3">
                            <label for="note" class="form-label">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="4" required placeholder="Add your note about this booking"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Note</button>
                    </div>
                </form>
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
            
            // Check for saved theme preference or prefer-color-scheme
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