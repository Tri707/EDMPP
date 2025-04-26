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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settingsType = isset($_POST['settings_type']) ? $_POST['settings_type'] : '';
    
    try {
        // General Settings
        if ($settingsType === 'general') {
            // Process general settings (would be stored in a settings table)
            $siteName = isset($_POST['site_name']) ? trim($_POST['site_name']) : '';
            $siteDescription = isset($_POST['site_description']) ? trim($_POST['site_description']) : '';
            $contactEmail = isset($_POST['contact_email']) ? trim($_POST['contact_email']) : '';
            $contactPhone = isset($_POST['contact_phone']) ? trim($_POST['contact_phone']) : '';
            $defaultLanguage = isset($_POST['default_language']) ? trim($_POST['default_language']) : 'ar';
            $defaultCurrency = isset($_POST['default_currency']) ? trim($_POST['default_currency']) : 'SAR';
            
            // Here you would update these settings in your database
            // For now, we'll just simulate success
            //$successMessage = "General settings updated successfully!";
        }
        
        // Notification Settings
        else if ($settingsType === 'notifications') {
            $emailNotifications = isset($_POST['email_notifications']) ? 1 : 0;
            $smsNotifications = isset($_POST['sms_notifications']) ? 1 : 0;
            $pushNotifications = isset($_POST['push_notifications']) ? 1 : 0;
            $bookingNotifications = isset($_POST['booking_notifications']) ? 1 : 0;
            $quoteNotifications = isset($_POST['quote_notifications']) ? 1 : 0;
            $reviewNotifications = isset($_POST['review_notifications']) ? 1 : 0;
            
            // Here you would update these settings in your database
            // For now, we'll just simulate success
            //$successMessage = "Notification settings updated successfully!";
        }
        
        // Security Settings
        else if ($settingsType === 'security') {
            $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
            $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            
            // Basic validation
            if (!empty($newPassword)) {
                if ($newPassword !== $confirmPassword) {
                    $errorMessage = "New passwords do not match";
                } else if (strlen($newPassword) < 8) {
                    $errorMessage = "Password must be at least 8 characters";
                } else {
                    // Verify current password
                    if (password_verify($currentPassword, $adminData['password'])) {
                        // Hash new password
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        
                        // Update password in database
                        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmt->execute([$hashedPassword, $userId]);
                        
                        $successMessage = "Password updated successfully!";
                    } else {
                        $errorMessage = "Current password is incorrect";
                    }
                }
            }
        }
        
        // Account Settings
        else if ($settingsType === 'account') {
            $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
            $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            
            // Basic validation
            if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
                $errorMessage = "All fields are required";
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errorMessage = "Invalid email format";
            } else {
                // Check if email is already taken by another user
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $userId]);
                if ($stmt->rowCount() > 0) {
                    $errorMessage = "Email is already in use by another account";
                } else {
                    // Update user data
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
                    $stmt->execute([$firstName, $lastName, $email, $phone, $userId]);
                    
                    // Handle profile image upload
                    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                        $uploadDir = '../profile_images/';
                        $fileName = basename($_FILES['profile_image']['name']);
                        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        
                        // Only allow image files
                        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
                        if (in_array($fileExt, $allowedExtensions)) {
                            $newFileName = uniqid('profile_') . '.' . $fileExt;
                            $targetPath = $uploadDir . $newFileName;
                            
                            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $targetPath)) {
                                // Update profile image in database
                                $stmt = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                                $stmt->execute([$newFileName, $userId]);
                                
                                // Update session data
                                $adminData['profile_image'] = $newFileName;
                                $adminProfileImage = $targetPath;
                            } else {
                                $errorMessage = "Failed to upload profile image";
                            }
                        } else {
                            $errorMessage = "Only JPG, JPEG, PNG, and GIF files are allowed";
                        }
                    }
                    
                    // Refresh admin data after update
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $successMessage = "Account information updated successfully!";
                }
            }
        }
        
        // System Settings
        else if ($settingsType === 'system') {
            $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $debugMode = isset($_POST['debug_mode']) ? 1 : 0;
            $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;
            $emailVerification = isset($_POST['email_verification']) ? 1 : 0;
            $maxFileSize = isset($_POST['max_file_size']) ? intval($_POST['max_file_size']) : 2;
            
            // Here you would update these settings in your database
            // For now, we'll just simulate success
            $successMessage = "System settings updated successfully!";
        }
        
    } catch (PDOException $e) {
        $errorMessage = "Database error: " . $e->getMessage();
        error_log("Settings update error: " . $e->getMessage());
    }
}

// Default settings values
$generalSettings = [
    'site_name' => 'FixItNow',
    'site_description' => 'Technical repair services platform',
    'contact_email' => 'support@fixitnow.com',
    'contact_phone' => '+966500000000',
    'default_language' => 'ar',
    'default_currency' => 'SAR'
];

$notificationSettings = [
    'email_notifications' => 1,
    'sms_notifications' => 1,
    'push_notifications' => 1,
    'booking_notifications' => 1,
    'quote_notifications' => 1,
    'review_notifications' => 1
];

$systemSettings = [
    'maintenance_mode' => 0,
    'debug_mode' => 0,
    'allow_registration' => 1,
    'email_verification' => 1,
    'max_file_size' => 2
];

// Get settings from database (would normally be fetched from a settings table)
// For now, we'll use the defaults

// Get current active tab (if any)
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'general';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - FixItNow Admin</title>
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
        
        /* Settings specific styles */
        .settings-tabs .nav-link {
            padding: 1rem 1.5rem;
            color: var(--text-color);
            border-radius: 0;
            border-left: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .settings-tabs .nav-link:hover {
            background-color: rgba(var(--primary-color-rgb), 0.1);
        }
        
        .settings-tabs .nav-link.active {
            color: var(--primary-color);
            background-color: rgba(var(--primary-color-rgb), 0.1);
            border-left: 3px solid var(--primary-color);
            font-weight: 600;
        }
        
        .settings-tabs .nav-link i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }
        
        /* Form control overrides */
        .form-control,
        .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .form-control:focus,
        .form-select:focus {
            background-color: var(--input-bg);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
            color: var(--text-color);
        }
        
        /* Switch control */
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
            cursor: pointer;
        }
        
        .form-switch .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        .form-switch .form-check-label {
            cursor: pointer;
            padding-left: 0.5rem;
        }
        
        /* Profile card styles */
        .profile-image-container {
            position: relative;
            width: 120px;
            height: 120px;
            margin: 0 auto 1.5rem;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--primary-color);
        }
        
        .profile-image-edit {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--primary-color);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .profile-image-edit:hover {
            background-color: var(--primary-hover);
            transform: scale(1.1);
        }
        
        /* Alert customization */
        .alert {
            border-radius: 0.5rem;
            border: none;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background-color: rgba(var(--accent-color-rgb), 0.2);
            color: var(--accent-color);
        }
        
        .alert-danger {
            background-color: rgba(var(--danger-color-rgb), 0.2);
            color: var(--danger-color);
        }
        
        /* Button customization */
        .btn-custom-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            padding: 0.5rem 1.5rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-custom-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            color: white;
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
                        <a class="nav-link" href="bookings.php">
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
                        <a class="nav-link active" href="settings.php">
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
            <h1 class="page-title">Settings</h1>
            
            <?php if(isset($successMessage)): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $successMessage; ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($errorMessage)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $errorMessage; ?>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-3 mb-4">
                    <div class="card">
                        <div class="card-body p-0">
                            <div class="list-group settings-tabs">
                                <a href="?tab=general" class="list-group-item list-group-item-action <?php echo $activeTab === 'general' ? 'active' : ''; ?>">
                                    <i class="fas fa-sliders-h"></i> General Settings
                                </a>
                                <a href="?tab=account" class="list-group-item list-group-item-action <?php echo $activeTab === 'account' ? 'active' : ''; ?>">
                                    <i class="fas fa-user-circle"></i> Account Settings
                                </a>
                                <a href="?tab=notifications" class="list-group-item list-group-item-action <?php echo $activeTab === 'notifications' ? 'active' : ''; ?>">
                                    <i class="fas fa-bell"></i> Notification Settings
                                </a>
                                <a href="?tab=security" class="list-group-item list-group-item-action <?php echo $activeTab === 'security' ? 'active' : ''; ?>">
                                    <i class="fas fa-shield-alt"></i> Security Settings
                                </a>
                                <a href="?tab=system" class="list-group-item list-group-item-action <?php echo $activeTab === 'system' ? 'active' : ''; ?>">
                                    <i class="fas fa-server"></i> System Settings
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-9">
                    <!-- General Settings -->
                    <?php if($activeTab === 'general'): ?>
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <i class="fas fa-sliders-h me-2"></i> General Settings (شكل فقط لايوجد وقت لبرمجتها)
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="settings_type" value="general">
                                
                                <div class="mb-3">
                                    <label for="site_name" class="form-label">Site Name</label>
                                    <input type="text" class="form-control" id="site_name" name="site_name" value="<?php echo htmlspecialchars($generalSettings['site_name']); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="site_description" class="form-label">Site Description</label>
                                    <textarea class="form-control" id="site_description" name="site_description" rows="3"><?php echo htmlspecialchars($generalSettings['site_description']); ?></textarea>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_email" class="form-label">Contact Email</label>
                                        <input type="email" class="form-control" id="contact_email" name="contact_email" value="<?php echo htmlspecialchars($generalSettings['contact_email']); ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_phone" class="form-label">Contact Phone</label>
                                        <input type="text" class="form-control" id="contact_phone" name="contact_phone" value="<?php echo htmlspecialchars($generalSettings['contact_phone']); ?>">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="default_language" class="form-label">Default Language</label>
                                        <select class="form-select" id="default_language" name="default_language">
                                            <option value="ar" <?php echo $generalSettings['default_language'] === 'ar' ? 'selected' : ''; ?>>Arabic</option>
                                            <option value="en" <?php echo $generalSettings['default_language'] === 'en' ? 'selected' : ''; ?>>English</option>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="default_currency" class="form-label">Default Currency</label>
                                        <select class="form-select" id="default_currency" name="default_currency">
                                            <option value="SAR" <?php echo $generalSettings['default_currency'] === 'SAR' ? 'selected' : ''; ?>>Saudi Riyal (SAR)</option>
                                            <option value="USD" <?php echo $generalSettings['default_currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar (USD)</option>
                                            <option value="EUR" <?php echo $generalSettings['default_currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (EUR)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Save General Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Account Settings -->
                    <?php if($activeTab === 'account'): ?>
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <i class="fas fa-user-circle me-2"></i> Account Settings
                        </div>
                        <div class="card-body">
                            <form method="post" action="" enctype="multipart/form-data">
                                <input type="hidden" name="settings_type" value="account">
                                
                                <div class="text-center mb-4">
                                    <div class="profile-image-container">
                                        <img src="<?php echo htmlspecialchars($adminProfileImage); ?>" alt="Profile" class="profile-image">
                                        <label for="profile_image" class="profile-image-edit">
                                            <i class="fas fa-camera"></i>
                                        </label>
                                        <input type="file" id="profile_image" name="profile_image" class="d-none" accept="image/*">
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($adminData['first_name']); ?>">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($adminData['last_name']); ?>">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address</label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($adminData['email']); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number</label>
                                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($adminData['phone']); ?>">
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Update Account
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Notification Settings -->
                    <?php if($activeTab === 'notifications'): ?>
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <i class="fas fa-bell me-2"></i> Notification Settings (شكل فقط لايوجد وقت لبرمجتها)
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="settings_type" value="notifications">
                                
                                <h5 class="mb-4">Notification Channels</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications" <?php echo $notificationSettings['email_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="email_notifications">Email Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications via email</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="sms_notifications" name="sms_notifications" <?php echo $notificationSettings['sms_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="sms_notifications">SMS Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications via SMS</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="push_notifications" name="push_notifications" <?php echo $notificationSettings['push_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="push_notifications">Push Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications in the browser and mobile app</div>
                                </div>
                                
                                <hr class="my-4">
                                
                                <h5 class="mb-4">Notification Types</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="booking_notifications" name="booking_notifications" <?php echo $notificationSettings['booking_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="booking_notifications">Booking Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications for new and updated bookings</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="quote_notifications" name="quote_notifications" <?php echo $notificationSettings['quote_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="quote_notifications">Quote Request Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications for new quote requests</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="review_notifications" name="review_notifications" <?php echo $notificationSettings['review_notifications'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="review_notifications">Review Notifications</label>
                                    </div>
                                    <div class="form-text ms-5">Receive notifications for new reviews</div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Save Notification Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Security Settings -->
                    <?php if($activeTab === 'security'): ?>
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <i class="fas fa-shield-alt me-2"></i> Security Settings
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="settings_type" value="security">
                                
                                <h5 class="mb-4">Change Password</h5>
                                
                                <div class="mb-3">
                                    <label for="current_password" class="form-label">Current Password</label>
                                    <input type="password" class="form-control" id="current_password" name="current_password">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password">
                                    <div class="form-text">Password must be at least 8 characters long</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- System Settings -->
                    <?php if($activeTab === 'system'): ?>
                    <div class="card">
                        <div class="card-header d-flex align-items-center">
                            <i class="fas fa-server me-2"></i> System Settings (شكل فقط لايوجد وقت لبرمجتها)
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <input type="hidden" name="settings_type" value="system">
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" <?php echo $systemSettings['maintenance_mode'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                                    </div>
                                    <div class="form-text ms-5">When enabled, the site will display a maintenance message to users</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" <?php echo $systemSettings['debug_mode'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="debug_mode">Debug Mode</label>
                                    </div>
                                    <div class="form-text ms-5">When enabled, detailed error messages will be displayed (not recommended for production)</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="allow_registration" name="allow_registration" <?php echo $systemSettings['allow_registration'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="allow_registration">Allow User Registration</label>
                                    </div>
                                    <div class="form-text ms-5">When enabled, users can register for new accounts</div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="email_verification" name="email_verification" <?php echo $systemSettings['email_verification'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="email_verification">Require Email Verification</label>
                                    </div>
                                    <div class="form-text ms-5">When enabled, new users must verify their email before accessing the site</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_file_size" class="form-label">Maximum File Upload Size (MB)</label>
                                    <input type="number" class="form-control" id="max_file_size" name="max_file_size" value="<?php echo $systemSettings['max_file_size']; ?>" min="1" max="10">
                                    <div class="form-text">Maximum file size users can upload (in megabytes)</div>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Save System Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php endif; ?>
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
            
            // Profile image preview
            const profileImage = document.getElementById('profile_image');
            if (profileImage) {
                profileImage.addEventListener('change', function(e) {
                    if (this.files && this.files[0]) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            document.querySelector('.profile-image').src = e.target.result;
                        }
                        reader.readAsDataURL(this.files[0]);
                    }
                });
            }
            
            // Auto-hide alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.classList.add('fade');
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>