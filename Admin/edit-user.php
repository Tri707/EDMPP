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
include '../conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to get user profile image URL
function getUserProfileImage($userId, $defaultImage = '../default.png') {
    try {
        global $pdo;
        
        // Check if we have a valid database connection
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            // Include database connection if not already included
            include '../conn.php';
        }
        
        // Query to get user profile image
        $stmt = $pdo->prepare("
            SELECT profile_image 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If user has a profile image, validate and return it
        if ($result && !empty($result['profile_image'])) {
            // Check if it's an external URL
            if (preg_match('/^https?:\/\//', $result['profile_image'])) {
                return $result['profile_image'];
            }
            
            // Construct local path
            $imagePath = '../profile_images/' . basename($result['profile_image']);
            
            // Validate if file exists
            if (file_exists($imagePath)) {
                return $imagePath;
            }
        }
        
        // Return default image if no valid profile image found
        return $defaultImage;
    } catch (PDOException $e) {
        // Log error
        error_log("Database error getting profile image: " . $e->getMessage());
        return $defaultImage;
    } catch (Exception $e) {
        // Log general error
        error_log("Error getting profile image: " . $e->getMessage());
        return $defaultImage;
    }
}

// Get admin user information
$userData = null;
$profileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    $profileImage = getUserProfileImage($userId);
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
} catch (Exception $e) {
    error_log("General error fetching admin data: " . $e->getMessage());
}

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$editUserId = (int)$_GET['id'];

// Get the user to edit
$editUser = null;
$providerDetails = null;
$actionMessage = '';
$actionType = '';

try {
    // Get user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$editUserId]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$editUser) {
        // User not found
        header('Location: users.php');
        exit;
    }
    
    // Get provider details if user is a provider
    if ($editUser['role'] === 'provider') {
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE user_id = ?");
        $stmt->execute([$editUserId]);
        $providerDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error fetching user details: " . $e->getMessage());
    $actionMessage = "Error loading user details. Please try again.";
    $actionType = "danger";
} catch (Exception $e) {
    error_log("General error fetching user details: " . $e->getMessage());
    $actionMessage = "An unexpected error occurred. Please try again.";
    $actionType = "danger";
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    try {
        // Begin transaction
        $pdo->beginTransaction();
        
        // Clean and extract user data
        $first_name = clean_input($_POST['first_name']);
        $last_name = clean_input($_POST['last_name']);
        $email = clean_input($_POST['email']);
        $phone = clean_input($_POST['phone']);
        $role = clean_input($_POST['role']);
        $status = clean_input($_POST['status']);
        
        // Check if email already exists with another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $editUserId]);
        if ($stmt->rowCount() > 0) {
            throw new Exception("Email address is already in use by another user.");
        }
        
        // Check if changing the role of admin user to non-admin
        if ($editUserId == $userId && $role != 'admin' && $editUser['role'] == 'admin') {
            throw new Exception("You cannot change your own admin role.");
        }
        
        // Check if changing the status of admin user to inactive
        if ($editUserId == $userId && $status == 'inactive' && $editUser['role'] == 'admin') {
            throw new Exception("You cannot deactivate your own admin account.");
        }
        
        // Process profile image upload if provided
        $profile_image = $editUser['profile_image'];
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
            $file_name = $_FILES['profile_image']['name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $file_size = $_FILES['profile_image']['size'];
            
            // Validate file extension and size
            if (!in_array($file_ext, $allowed_extensions)) {
                throw new Exception("Invalid file format. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }
            
            if ($file_size > 5242880) { // 5MB limit
                throw new Exception("File size exceeds the maximum limit (5MB).");
            }
            
            // Generate unique file name
            $new_file_name = uniqid() . '.' . $file_ext;
            $upload_path = '../profile_images/' . $new_file_name;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_path)) {
                throw new Exception("Failed to upload profile image.");
            }
            
            // Delete old image file if it exists and is not the default
            if (!empty($profile_image) && file_exists('../profile_images/' . basename($profile_image)) && basename($profile_image) != 'default.png') {
                @unlink('../profile_images/' . basename($profile_image));
            }
            
            $profile_image = $new_file_name;
        }
        
        // Update user data
        $stmt = $pdo->prepare("
            UPDATE users 
            SET first_name = ?, last_name = ?, email = ?, phone = ?, profile_image = ?, role = ?, status = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$first_name, $last_name, $email, $phone, $profile_image, $role, $status, $editUserId]);
        
        // Handle provider-specific data if user is a provider or being changed to a provider
        if ($role === 'provider') {
            // Get specialties from checkboxes
            $specialties = isset($_POST['specialties']) ? $_POST['specialties'] : '';
            
            // Check if provider record exists
            $stmt = $pdo->prepare("SELECT id FROM providers WHERE user_id = ?");
            $stmt->execute([$editUserId]);
            $providerExists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($providerExists) {
                // Update existing provider data
                $stmt = $pdo->prepare("
                    UPDATE providers 
                    SET specialties = ?, experience = ?, location = ?, bio = ?, is_verified = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $specialties,
                    $_POST['experience'] ?? '',
                    $_POST['location'] ?? '',
                    $_POST['bio'] ?? '',
                    isset($_POST['is_verified']) ? 1 : 0,
                    $editUserId
                ]);
            } else {
                // Create new provider record
                $stmt = $pdo->prepare("
                    INSERT INTO providers (user_id, specialties, experience, location, bio, is_verified, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $editUserId,
                    $specialties,
                    $_POST['experience'] ?? '',
                    $_POST['location'] ?? '',
                    $_POST['bio'] ?? '',
                    isset($_POST['is_verified']) ? 1 : 0
                ]);
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        // Refresh user data after update
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$editUserId]);
        $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($editUser['role'] === 'provider') {
            $stmt = $pdo->prepare("SELECT * FROM providers WHERE user_id = ?");
            $stmt->execute([$editUserId]);
            $providerDetails = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $actionMessage = "User details have been updated successfully.";
        $actionType = "success";
    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $actionMessage = "Database error: " . $e->getMessage();
        $actionType = "danger";
        error_log($actionMessage);
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        $actionMessage = $e->getMessage();
        $actionType = "danger";
        error_log("Error updating user: " . $e->getMessage());
    }
}

// Get list of specialties for dropdown
$specialtiesList = [
    'smartphone' => 'Smartphone Repair',
    'laptop' => 'Laptop Repair',
    'tablet' => 'Tablet Repair',
    'desktop' => 'Computer Repair',
    'gaming' => 'Game Console Repair',
    'tv' => 'TV/Monitor Repair'
];

// Get experience options
$experienceOptions = [
    '<1' => 'Less than 1 year',
    '1-3' => '1-3 years',
    '3-5' => '3-5 years',
    '5-10' => '5-10 years',
    '>10' => 'More than 10 years'
];

// Saudi Arabia cities
$locations = [
    'Abha', 'Abu Arish', 'Ad Darb', 'Ad Dilam', 'Afif', 'Al Bahah', 'Al Battaliyah',
    'Al Bukayriyah', 'Al Hawtah', 'Al Hofuf', 'Al Jawf', 'Al Jubail', 'Al Kharj',
    'Al Khobar', 'Al Lith', 'Al Majma\'ah', 'Al Mithnab', 'Al Mubarraz', 'Al Muzahmiyah',
    'Al Namas', 'Al Omran', 'Al Qaṭif', 'Al Qurayyat', 'Al Qunfudhah', 'Al Rass',
    'Al Ula', 'Al Wajh', 'Al Zulfi', 'Arar', 'Ar Ranyah', 'As Sulayyil', 'Ash Shafa',
    'At Tuwal', 'Baljurashi', 'Badr', 'Bisha', 'Buraidah', 'Dammam', 'Dawadmi', 'Dhahran',
    'Diriyah', 'Dumat Al Jandal', 'Farrasan', 'Hafar Al-Batin', 'Hail', 'Hotat Bani Tamim',
    'Jazan', 'Jeddah', 'Jubail', 'Khafji', 'Khamis Mushait', 'Khaybar', 'Khobar',
    'Mecca', 'Medina', 'Najran', 'Qatif', 'Rabigh', 'Ras Tanura', 'Riyadh', 'Sakaka',
    'Samtah', 'Sayhat', 'Sharurah', 'Shaqra', 'Tabouk', 'Taif', 'Tarut', 'Thadiq',
    'Thuwal', 'Tumayr', 'Turabah', 'Umluj', 'Unaizah', 'Yanbu'
];
sort($locations); // Sort cities alphabetically
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - FixItNow Admin</title>
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
        
        /* Form card */
        .form-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .form-card .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }
        
        .form-card .card-body {
            padding: 1.5rem;
        }
        
        .form-card .card-footer {
            background-color: transparent;
            border-top: 1px solid var(--border-color);
            padding: 1.25rem 1.5rem;
        }
        
        /* Form Styles */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(151, 117, 250, 0.25);
        }
        
        /* Button adjustments */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-success {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        
        .btn-success:hover {
            background-color: #2ea043;
            border-color: #2ea043;
        }
        
        /* User profile image preview */
        .profile-image-preview {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            overflow: hidden;
            margin: 0 auto 1rem;
            border: 3px solid var(--primary-color);
            position: relative;
        }
        
        .profile-image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            cursor: pointer;
        }
        
        .image-overlay:hover {
            opacity: 1;
        }
        
        .image-overlay i {
            color: white;
            font-size: 1.5rem;
        }
        
        /* Custom Checkbox/Toggle */
        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
        
        /* Help text */
        .form-text {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }
        
        /* Required field indicator */
        .required-field::after {
            content: '*';
            color: #dc3545;
            margin-left: 4px;
        }

        /* Enhanced specialties checkboxes */
        .specialty-checkbox {
            width: 1.2em;
            height: 1.2em;
            cursor: pointer;
        }

        .specialty-checkbox:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .specialties-container .form-check {
            margin-bottom: 0.5rem;
            padding: 0.5rem;
            border-radius: 0.375rem;
            transition: background-color 0.3s ease;
        }

        .specialties-container .form-check:hover {
            background-color: rgba(166, 135, 255, 0.1);
        }

        .specialties-container .form-check-label {
            cursor: pointer;
            font-weight: 500;
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
                    <?php if($loggedIn && isset($userData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($userData['first_name']); ?></span>
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
                        <a class="nav-link active" href="users.php">
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
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Breadcrumb Navigation -->
            <nav aria-label="breadcrumb" class="mb-4">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="users.php">Users</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Edit User</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="page-title">Edit User</h1>
                <div>
                    <a href="user-details.php?id=<?php echo $editUserId; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i> Back to User Details
                    </a>
                </div>
            </div>
            
            <!-- Edit User Form -->
            <form action="edit-user.php?id=<?php echo $editUserId; ?>" method="post" enctype="multipart/form-data">
                <!-- Basic Information -->
                <div class="form-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-edit me-2"></i> Basic Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-12 text-center">
                                <div class="profile-image-preview">
                                    <img src="<?php echo getUserProfileImage($editUserId); ?>" alt="Profile Image" id="previewImage">
                                    <label for="profile_image" class="image-overlay">
                                        <i class="fas fa-camera"></i>
                                    </label>
                                </div>
                                <div class="mb-3">
                                    <input type="file" class="form-control d-none" id="profile_image" name="profile_image" accept="image/*">
                                    <label for="profile_image" class="btn btn-sm btn-outline-primary">Change Profile Picture</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label required-field">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($editUser['first_name']); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="last_name" class="form-label required-field">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($editUser['last_name']); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="email" class="form-label required-field">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($editUser['email']); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="phone" class="form-label required-field">Phone Number</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($editUser['phone']); ?>" required>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="role" class="form-label required-field">User Role</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="customer" <?php echo $editUser['role'] === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                    <option value="provider" <?php echo $editUser['role'] === 'provider' ? 'selected' : ''; ?>>Technician</option>
                                    <option value="admin" <?php echo $editUser['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                </select>
                                <?php if ($editUserId == $userId && $editUser['role'] == 'admin'): ?>
                                <div class="form-text text-warning">Note: You cannot change your own admin role.</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="status" class="form-label required-field">Account Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="active" <?php echo $editUser['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $editUser['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <?php if ($editUserId == $userId && $editUser['role'] == 'admin'): ?>
                                <div class="form-text text-warning">Note: You cannot deactivate your own admin account.</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="username" class="form-label">Username</label>
                                <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($editUser['username']); ?>" disabled>
                                <div class="form-text">Username cannot be changed.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="created_at" class="form-label">Member Since</label>
                                <input type="text" class="form-control" id="created_at" value="<?php echo date('F j, Y', strtotime($editUser['created_at'])); ?>" disabled>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Provider Information (shown only for technicians) -->
                <div class="form-card mb-4" id="providerSection" style="<?php echo $editUser['role'] !== 'provider' ? 'display: none;' : ''; ?>">
                    <div class="card-header bg-primary bg-gradient text-white">
                        <h5 class="mb-0"><i class="fas fa-tools me-2"></i> Technician Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-4">
                            <!-- Specialties - Enhanced with Checkboxes -->
                            <div class="col-md-6">
                                <label class="form-label fw-bold mb-2">
                                    <i class="fas fa-certificate me-1 text-primary"></i> Specialties
                                </label>
                                <div class="specialties-container border rounded p-3 bg-light shadow-sm">
                                    <div class="row g-2">
                                        <?php foreach ($specialtiesList as $key => $value): ?>
                                        <div class="col-md-6">
                                            <div class="form-check">
                                                <input class="form-check-input specialty-checkbox" type="checkbox" 
                                                       id="specialty_<?php echo $key; ?>" 
                                                       name="specialty_items[]" 
                                                       value="<?php echo $key; ?>"
                                                       <?php echo isset($providerDetails['specialties']) && strpos($providerDetails['specialties'], $key) !== false ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="specialty_<?php echo $key; ?>">
                                                    <?php echo $value; ?>
                                                </label>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="form-text mt-2"><i class="fas fa-info-circle me-1"></i> Select all applicable repair specialties.</div>
                            </div>
                            
                            <!-- Experience -->
                            <div class="col-md-6">
                                <label for="experience" class="form-label fw-bold mb-2">
                                    <i class="fas fa-business-time me-1 text-primary"></i> Experience
                                </label>
                                <select class="form-select form-select-lg shadow-sm" id="experience" name="experience">
                                    <option value="">Select Experience</option>
                                    <?php foreach ($experienceOptions as $key => $value): ?>
                                    <option value="<?php echo $key; ?>" <?php echo isset($providerDetails['experience']) && $providerDetails['experience'] === $key ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Location - Enhanced with Saudi Cities Dropdown -->
                            <div class="col-md-6">
                                <label for="location" class="form-label fw-bold mb-2">
                                    <i class="fas fa-map-marker-alt me-1 text-primary"></i> Location
                                </label>
                                <select class="form-select form-select-lg shadow-sm" id="location" name="location">
                                    <option value="">Select City</option>
                                    <?php foreach ($locations as $city): ?>
                                    <option value="<?php echo $city; ?>" <?php echo isset($providerDetails['location']) && $providerDetails['location'] === $city ? 'selected' : ''; ?>><?php echo $city; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Bio -->
                            <div class="col-md-12">
                                <label for="bio" class="form-label fw-bold mb-2">
                                    <i class="fas fa-id-card me-1 text-primary"></i> Bio
                                </label>
                                <textarea class="form-control shadow-sm" id="bio" name="bio" rows="5" placeholder="Provide a brief description about your skills and experience..."><?php echo isset($providerDetails['bio']) ? htmlspecialchars($providerDetails['bio']) : ''; ?></textarea>
                                <div class="form-text mt-2"><i class="fas fa-info-circle me-1"></i> Describe your expertise, experience, and the services you provide.</div>
                            </div>
                            
                            <!-- Verification Status -->
                            <div class="col-md-12">
                                <div class="form-check form-switch p-3 bg-light rounded border shadow-sm">
                                    <input class="form-check-input" type="checkbox" id="is_verified" name="is_verified" <?php echo isset($providerDetails['is_verified']) && $providerDetails['is_verified'] ? 'checked' : ''; ?> style="width: 3em; height: 1.5em;">
                                    <label class="form-check-label fw-bold ms-2" for="is_verified">
                                        <i class="fas fa-check-circle text-success me-1"></i> Verified Technician
                                    </label>
                                    <div class="form-text mt-2">Verified technicians appear more prominently in search results and gain user trust.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Form Submission -->
                <div class="form-card">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 d-flex justify-content-between">
                                <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
                                <button type="submit" class="btn btn-primary" name="update_user">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
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
            
            // Image preview functionality
            const profileImage = document.getElementById('profile_image');
            const previewImage = document.getElementById('previewImage');
            
            profileImage.addEventListener('change', function() {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewImage.src = e.target.result;
                    }
                    reader.readAsDataURL(file);
                }
            });
            
            // Toggle provider section based on role selection
            const roleSelect = document.getElementById('role');
            const providerSection = document.getElementById('providerSection');
            
            roleSelect.addEventListener('change', function() {
                if (this.value === 'provider') {
                    providerSection.style.display = 'block';
                } else {
                    providerSection.style.display = 'none';
                }
            });
            
            // Handle specialties checkboxes for form submission
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                // Get all checked specialty checkboxes
                const checkedSpecialties = Array.from(document.querySelectorAll('.specialty-checkbox:checked'))
                    .map(checkbox => checkbox.value);
                
                // Create a hidden input for specialties string
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'specialties';
                hiddenInput.value = checkedSpecialties.join(',');
                
                // Add to form
                form.appendChild(hiddenInput);
            });
        });
    </script>
</body>
</html>