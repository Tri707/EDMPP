<?php
// Initialize session and verify authentication
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Security: Redirect if not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header('Location: ../login.php');
    exit;
}

// Include database connection
include 'conn.php';

// Initialize variables
$success_message = '';
$error_message = '';
$provider_data = [];
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
$experience_options = ['0-1', '1-3', '3-5', '5-10', '10+'];
$specialties_options = ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'];

// Default profile image
$providerProfileImage = '../default.png';

// Process password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        // Get current password and new passwords
        $current_password = trim($_POST['current_password']);
        $new_password = trim($_POST['new_password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        // Basic validation
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            throw new Exception("All password fields are required.");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords do not match.");
        }
        
        if (strlen($new_password) < 8) {
            throw new Exception("Password must be at least 8 characters long.");
        }
        
        // Verify current password
        $checkPasswordStmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $checkPasswordStmt->execute([$userId]);
        $hashedPassword = $checkPasswordStmt->fetchColumn();
        
        if (!password_verify($current_password, $hashedPassword)) {
            throw new Exception("Current password is incorrect.");
        }
        
        // Hash new password
        $newHashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Update password
        $updatePasswordStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $updatePasswordStmt->execute([$newHashedPassword, $userId]);
        
        $success_message = "Password changed successfully!";
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Process form submission for profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        // Start transaction
        $pdo->beginTransaction();
        
        // Validate and sanitize user inputs - using proper sanitization methods
        $first_name = trim(filter_input(INPUT_POST, 'first_name'));
        $last_name = trim(filter_input(INPUT_POST, 'last_name'));
        $phone = trim(filter_input(INPUT_POST, 'phone'));
        $email = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $location = trim(filter_input(INPUT_POST, 'location'));
        $bio = trim(filter_input(INPUT_POST, 'bio'));
        $education = trim(filter_input(INPUT_POST, 'education'));
        $experience = trim(filter_input(INPUT_POST, 'experience'));
        
        // NOTE: We don't modify specialties here as they should only be managed by admins
        // Get current specialties from database to ensure they're not changed
        $getSpecialtiesStmt = $pdo->prepare("SELECT specialties FROM providers WHERE user_id = ?");
        $getSpecialtiesStmt->execute([$userId]);
        $currentSpecialtiesResult = $getSpecialtiesStmt->fetch(PDO::FETCH_ASSOC);
        $specialties_str = $currentSpecialtiesResult['specialties'];
        
        // Basic validation
        if (empty($first_name) || empty($last_name) || empty($phone) || empty($email)) {
            throw new Exception("All required fields must be filled.");
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Please enter a valid email address.");
        }
        
        // Check if email already exists for another user
        $checkEmailStmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $checkEmailStmt->execute([$email, $userId]);
        if ($checkEmailStmt->rowCount() > 0) {
            throw new Exception("Email is already used by another account.");
        }
        
        // Handle profile image upload
        $profile_image = null;
        if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            $filename = $_FILES['profile_image']['name'];
            $tmp = $_FILES['profile_image']['tmp_name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (!in_array($ext, $allowed)) {
                throw new Exception("Only image files are allowed (jpg, jpeg, png, gif).");
            }
            
            // Generate unique filename
            $new_filename = uniqid() . '.' . $ext;
            $upload_path = '../profile_images/' . $new_filename;
            
            if (!move_uploaded_file($tmp, $upload_path)) {
                throw new Exception("Failed to upload image. Please try again.");
            }
            
            $profile_image = $new_filename;
        }
        
        // Update user information - use proper html decoding for database insertion
        $updateUserQuery = "UPDATE users SET 
                            first_name = ?, 
                            last_name = ?, 
                            phone = ?, 
                            email = ?";
        
        $params = [
            htmlspecialchars_decode($first_name), 
            htmlspecialchars_decode($last_name), 
            htmlspecialchars_decode($phone), 
            $email
        ];
        
        if ($profile_image) {
            $updateUserQuery .= ", profile_image = ?";
            $params[] = $profile_image;
        }
        
        $updateUserQuery .= " WHERE id = ?";
        $params[] = $userId;
        
        $updateUserStmt = $pdo->prepare($updateUserQuery);
        $updateUserStmt->execute($params);
        
        // Update provider-specific information
        $updateProviderQuery = "UPDATE providers SET 
                                location = ?, 
                                bio = ?, 
                                specialties = ?,
                                experience = ?,
                                education = ?
                                WHERE user_id = ?";
        
        $updateProviderStmt = $pdo->prepare($updateProviderQuery);
        $updateProviderStmt->execute([
            htmlspecialchars_decode($location), 
            htmlspecialchars_decode($bio), 
            $specialties_str, 
            htmlspecialchars_decode($experience), 
            htmlspecialchars_decode($education), 
            $userId
        ]);
        
        // Commit transaction
        $pdo->commit();
        
        $success_message = "Profile updated successfully!";
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $pdo->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch provider data with user information
try {
    $stmt = $pdo->prepare("SELECT u.*, p.id as provider_id, p.specialties, p.experience, p.bio, p.location, p.education, p.is_verified
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider'");
    $stmt->execute([$userId]);
    $provider_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider_data) {
        // Provider data not found
        header('Location: ../error.php?message=Provider%20profile%20not%20found');
        exit;
    }
    
    // Set profile image path
    if (!empty($provider_data['profile_image'])) {
        $imagePath = '../profile_images/' . basename($provider_data['profile_image']);
        if (file_exists($imagePath) && is_readable($imagePath)) {
            $providerProfileImage = $imagePath;
        }
    }
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

// Get provider stats
$stats = [
    'total_bookings' => 0,
    'completed_bookings' => 0,
    'total_earnings' => 0,
    'avg_rating' => 0,
    'total_reviews' => 0
];

try {
    // Get total and completed bookings
    $bookingsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            COALESCE(SUM(total_price), 0) as earnings
        FROM bookings 
        WHERE provider_id = ?
    ");
    $bookingsStmt->execute([$provider_data['provider_id']]);
    $bookingsStats = $bookingsStmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total_bookings'] = (int)$bookingsStats['total'];
    $stats['completed_bookings'] = (int)$bookingsStats['completed'];
    $stats['total_earnings'] = (float)$bookingsStats['earnings'];
    
    // Get reviews stats
    $reviewsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            COALESCE(AVG(rating), 0) as avg_rating
        FROM reviews
        WHERE provider_id = ?
    ");
    $reviewsStmt->execute([$provider_data['provider_id']]);
    $reviewsStats = $reviewsStmt->fetch(PDO::FETCH_ASSOC);
    
    $stats['total_reviews'] = (int)$reviewsStats['total'];
    $stats['avg_rating'] = (float)$reviewsStats['avg_rating'];
    
} catch (PDOException $e) {
    // Silent error - just use default values
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - FixItNow Provider Portal</title>
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
        
        /* Account information styles */
        .account-info-section {
            border-left: 4px solid var(--primary-color);
            padding-left: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .account-info-label {
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .account-info-value {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .password-strength-meter {
            height: 4px;
            background-color: #e9ecef;
            margin-top: 6px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .password-strength-meter div {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .password-feedback {
            font-size: 0.8rem;
            margin-top: 6px;
        }
        
        /* Profile specific styles */
        .profile-header {
            background-color: var(--primary-light);
            padding: 2rem;
            border-radius: 0.75rem;
            position: relative;
            margin-bottom: 2rem;
        }
        
        .profile-image-container {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .profile-image {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--card-bg);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .profile-image-upload {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 40px;
            height: 40px;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
        }
        
        .verification-badge {
            display: inline-flex;
            align-items: center;
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .verification-badge.pending {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .profile-stat {
            text-align: center;
            padding: 1rem;
            background-color: rgba(var(--primary-color-rgb), 0.05);
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .profile-stat:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .profile-stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: var(--primary-color);
        }
        
        .profile-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .profile-stat-label {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .rating-stars {
            color: #ffc107;
            font-size: 0.9rem;
        }
        
        /* Form Styles */
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
            border-color: var(--primary-color);
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Specialty badges */
        .specialty-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: default;
        }
        
        .specialty-badge.active {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
        
        .specialty-badge.inactive {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--text-muted);
            border: 1px dashed var(--border-color);
            opacity: 0.7;
        }
        
        /* Added: Better mobile responsiveness */
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .profile-image {
                width: 100px;
                height: 100px;
            }
            
            .profile-stat-value {
                font-size: 1.2rem;
            }
            
            .specialty-badge {
                padding: 0.5rem 0.75rem;
                font-size: 0.8rem;
            }
        }

        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--bg-color);
            opacity: 0.7;
            z-index: 9999;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .spinner-container {
            text-align: center;
        }

        .spinner-border {
            width: 3rem;
            height: 3rem;
        }
        
        /* Custom file input */
        .custom-file-input-container {
            position: relative;
        }
        
        .custom-file-input {
            opacity: 0;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        
        .custom-file-button {
            display: inline-block;
            padding: 0.75rem 1.25rem;
            background-color: var(--primary-color);
            color: white;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .custom-file-button:hover {
            background-color: var(--primary-hover);
        }
        
        .custom-file-name {
            margin-left: 1rem;
            font-size: 0.9rem;
        }
    </style>
</head>

<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading profile...</p>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="text-decoration-none d-flex align-items-center">
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
                            <img src="<?php echo htmlspecialchars($providerProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo isset($provider_data['first_name']) ? htmlspecialchars($provider_data['first_name'], ENT_QUOTES, 'UTF-8') : 'Provider'; ?></span>
                            <?php if(isset($provider_data['is_verified']) && $provider_data['is_verified'] == 1): ?>
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
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
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
                        <a class="nav-link active" href="profile.php">
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
            <!-- Page Title and Alert Messages -->
            <div class="row mb-4">
                <div class="col-12">
                    <h2 class="page-title">My Profile</h2>
                    <p class="text-muted">
                        Manage your profile information and showcase your expertise to potential customers.
                    </p>
                    
                    <?php if($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>
                    
                    <div class="alert alert-primary alert-dismissible fade show" role="alert">
                        <div class="d-flex">
                            <div class="me-3">
                                <i class="fas fa-lightbulb fa-2x"></i>
                            </div>
                            <div>
                                <h5 class="alert-heading">Profile Tip</h5>
                                <p class="mb-0">A complete profile with a professional photo and detailed bio can increase customer trust and booking rates by up to 30%.</p>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                </div>
            </div>
            
            <!-- Profile Overview -->
            <div class="row mb-4">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body p-4">
                            <div class="row align-items-center">
                                <div class="col-lg-2 col-md-3 text-center">
                                    <div class="profile-image-container">
                                        <img src="<?php echo htmlspecialchars($providerProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="profile-image">
                                    </div>
                                </div>
                                <div class="col-lg-10 col-md-9">
                                    <div class="d-flex align-items-center mb-3">
                                        <h3 class="mb-0">
                                            <?php echo isset($provider_data['first_name']) && isset($provider_data['last_name']) ? 
                                            htmlspecialchars($provider_data['first_name'] . ' ' . $provider_data['last_name'], ENT_QUOTES, 'UTF-8') : 'Provider'; ?>
                                        </h3>
                                        <?php if(isset($provider_data['is_verified']) && $provider_data['is_verified'] == 1): ?>
                                            <div class="verification-badge ms-3">
                                                <i class="fas fa-tools me-2"></i> Verified Provider
                                            </div>
                                        <?php else: ?>
                                            <div class="verification-badge pending ms-3">
                                                <i class="fas fa-clock me-2"></i> Verification Pending
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <p class="mb-1">
                                                <i class="fas fa-envelope text-muted me-2"></i>
                                                <?php echo isset($provider_data['email']) ? htmlspecialchars($provider_data['email'], ENT_QUOTES, 'UTF-8') : 'Email not set'; ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-phone text-muted me-2"></i>
                                                <?php echo isset($provider_data['phone']) ? htmlspecialchars($provider_data['phone'], ENT_QUOTES, 'UTF-8') : 'Phone not set'; ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="mb-1">
                                                <i class="fas fa-map-marker-alt text-muted me-2"></i>
                                                <?php echo isset($provider_data['location']) && !empty($provider_data['location']) ? 
                                                htmlspecialchars($provider_data['location'], ENT_QUOTES, 'UTF-8') : 'Location not set'; ?>
                                            </p>
                                            <p class="mb-1">
                                                <i class="fas fa-briefcase text-muted me-2"></i>
                                                Experience: <?php echo isset($provider_data['experience']) && !empty($provider_data['experience']) ? 
                                                htmlspecialchars($provider_data['experience'], ENT_QUOTES, 'UTF-8') . ' years' : 'Not specified'; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <h6 class="text-muted mb-2">Specialties:</h6>
                                        <div>
                                            <?php 
                                                $specialties = isset($provider_data['specialties']) ? explode(',', $provider_data['specialties']) : [];
                                                if (!empty($specialties)):
                                                    foreach ($specialties as $specialty):
                                            ?>
                                                <span class="badge bg-primary me-2 mb-2 py-2 px-3">
                                                    <?php 
                                                        $icon = '';
                                                        switch ($specialty) {
                                                            case 'smartphone': $icon = 'fa-mobile-alt'; break;
                                                            case 'laptop': $icon = 'fa-laptop'; break;
                                                            case 'tablet': $icon = 'fa-tablet-alt'; break;
                                                            case 'desktop': $icon = 'fa-desktop'; break;
                                                            case 'gaming': $icon = 'fa-gamepad'; break;
                                                            case 'tv': $icon = 'fa-tv'; break;
                                                            default: $icon = 'fa-cog';
                                                        }
                                                    ?>
                                                    <i class="fas <?php echo $icon; ?> me-1"></i>
                                                    <?php echo ucfirst(htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8')); ?>
                                                </span>
                                            <?php 
                                                    endforeach;
                                                else:
                                            ?>
                                                <span class="text-muted">No specialties specified</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <h6 class="text-muted mb-2">Bio:</h6>
                                        <p>
                                            <?php echo isset($provider_data['bio']) && !empty($provider_data['bio']) ? 
                                            nl2br(htmlspecialchars($provider_data['bio'], ENT_QUOTES, 'UTF-8')) : 'No bio provided'; ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Stats Overview -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="profile-stat h-100">
                        <div class="profile-stat-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <div class="profile-stat-value"><?php echo number_format($stats['total_bookings']); ?></div>
                        <div class="profile-stat-label">Total Bookings</div>
                        <div class="small text-success mt-2">
                            <i class="fas fa-check-circle me-1"></i>
                            <?php echo number_format($stats['completed_bookings']); ?> Completed
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="profile-stat h-100">
                        <div class="profile-stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                        <div class="profile-stat-value">
                            <?php echo number_format($stats['avg_rating'], 1); ?>
                            <div class="rating-stars d-inline-block ms-2">
                                <?php
                                    $rating = $stats['avg_rating'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= floor($rating)) {
                                            echo '<i class="fas fa-star"></i>';
                                        } elseif ($i - 0.5 <= $rating) {
                                            echo '<i class="fas fa-star-half-alt"></i>';
                                        } else {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    }
                                ?>
                            </div>
                        </div>
                        <div class="profile-stat-label">Average Rating</div>
                        <div class="small text-muted mt-2">
                            Based on <?php echo number_format($stats['total_reviews']); ?> reviews
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="profile-stat h-100">
                        <div class="profile-stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <div class="profile-stat-value">
                            <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                            <?php echo number_format($stats['total_earnings'], 0); ?>
                        </div>
                        <div class="profile-stat-label">Total Earnings</div>
                        <div class="small text-muted mt-2">
                            <i class="fas fa-calculator me-1"></i>
                            <?php 
                                $avgBookingValue = $stats['total_bookings'] > 0 ? 
                                    $stats['total_earnings'] / $stats['total_bookings'] : 0;
                                echo 'Avg. <img src="../admin/sar/sar.png" alt="" width="10" height="10"> ' . 
                                    number_format($avgBookingValue, 0) . ' per booking';
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Edit Profile Form -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Account Information</h5>
                        </div>
                        <div class="card-body">
                            <form action="profile.php" method="post">
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h6 class="mb-3">Login Information</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Username</label>
                                            <input type="text" class="form-control" value="<?php echo isset($provider_data['username']) ? htmlspecialchars($provider_data['username'], ENT_QUOTES, 'UTF-8') : ''; ?>" disabled>
                                            <div class="form-text">Your username cannot be changed</div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="current_password" class="form-label">Current Password</label>
                                            <input type="password" class="form-control" id="current_password" name="current_password">
                                        </div>
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">New Password</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password">
                                            <div class="password-strength-meter mt-2">
                                                <div id="password-strength-meter" class=""></div>
                                            </div>
                                            <div id="password-strength-text" class="password-feedback text-muted"></div>
                                        </div>
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <h6 class="mb-3">Account Status</h6>
                                        <div class="mb-3">
                                            <label class="form-label">Account Status</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" value="<?php echo (isset($provider_data['status']) && $provider_data['status'] == 'active') ? 'Active' : 'Inactive'; ?>" disabled>
                                                <span class="input-group-text <?php echo (isset($provider_data['status']) && $provider_data['status'] == 'active') ? 'bg-success text-white' : 'bg-danger text-white'; ?>">
                                                    <i class="fas <?php echo (isset($provider_data['status']) && $provider_data['status'] == 'active') ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Provider Verification</label>
                                            <div class="input-group">
                                                <input type="text" class="form-control" value="<?php echo (isset($provider_data['is_verified']) && $provider_data['is_verified'] == 1) ? 'Verified' : 'Pending Verification'; ?>" disabled>
                                                <span class="input-group-text <?php echo (isset($provider_data['is_verified']) && $provider_data['is_verified'] == 1) ? 'bg-primary text-white' : 'bg-warning text-dark'; ?>">
                                                    <i class="fas <?php echo (isset($provider_data['is_verified']) && $provider_data['is_verified'] == 1) ? 'fa-shield-alt' : 'fa-clock'; ?>"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Account Created</label>
                                            <input type="text" class="form-control" value="<?php echo isset($provider_data['created_at']) ? date('F j, Y', strtotime($provider_data['created_at'])) : ''; ?>" disabled>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Last Updated</label>
                                            <input type="text" class="form-control" value="<?php echo isset($provider_data['updated_at']) ? date('F j, Y', strtotime($provider_data['updated_at'])) : ''; ?>" disabled>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <button type="submit" name="change_password" class="btn btn-primary">
                                        <i class="fas fa-key me-2"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Edit Profile</h5>
                        </div>
                        <div class="card-body">
                            <form action="profile.php" method="post" enctype="multipart/form-data">
                                <div class="row mb-4">
                                    <div class="col-lg-3 col-md-4">
                                        <div class="mb-3">
                                            <label class="form-label">Profile Image</label>
                                            <div class="text-center">
                                                <div class="mb-3">
                                                    <img src="<?php echo htmlspecialchars($providerProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="rounded-circle" style="width: 120px; height: 120px; object-fit: cover;">
                                                </div>
                                                <div class="custom-file-input-container">
                                                    <div class="custom-file-button">
                                                        <i class="fas fa-camera me-2"></i> Change Photo
                                                    </div>
                                                    <input type="file" name="profile_image" class="custom-file-input" id="profileImageInput">
                                                </div>
                                                <div class="custom-file-name mt-2" id="fileNameDisplay"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-9 col-md-8">
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="first_name" class="form-label">First Name *</label>
                                                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo isset($provider_data['first_name']) ? htmlspecialchars($provider_data['first_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="last_name" class="form-label">Last Name *</label>
                                                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo isset($provider_data['last_name']) ? htmlspecialchars($provider_data['last_name'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="email" class="form-label">Email *</label>
                                                <input type="email" class="form-control" id="email" name="email" value="<?php echo isset($provider_data['email']) ? htmlspecialchars($provider_data['email'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="phone" class="form-label">Phone *</label>
                                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo isset($provider_data['phone']) ? htmlspecialchars($provider_data['phone'], ENT_QUOTES, 'UTF-8') : ''; ?>" required>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="location" class="form-label">Location</label>
                                                <select class="form-select" id="location" name="location">
                                                    <option value="">Select your location</option>
                                                    <?php foreach ($locations as $location): ?>
                                                        <option value="<?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($provider_data['location']) && $provider_data['location'] == $location) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($location, ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="experience" class="form-label">Experience (years)</label>
                                                <select class="form-select" id="experience" name="experience">
                                                    <option value="">Select your experience</option>
                                                    <?php foreach ($experience_options as $exp): ?>
                                                        <option value="<?php echo htmlspecialchars($exp, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (isset($provider_data['experience']) && $provider_data['experience'] == $exp) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($exp, ENT_QUOTES, 'UTF-8'); ?> years
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="form-label">Specialties</label>
                                    <div class="alert alert-info mb-3">
                                        <i class="fas fa-info-circle me-2"></i> Specialties can only be modified by administrators. Please contact support if you need to update your specialties.
                                    </div>
                                    <div class="row">
                                        <?php 
                                            $current_specialties = isset($provider_data['specialties']) ? 
                                                explode(',', $provider_data['specialties']) : [];
                                                
                                            foreach ($specialties_options as $specialty):
                                                $is_selected = in_array($specialty, $current_specialties);
                                                $icon = '';
                                                switch ($specialty) {
                                                    case 'smartphone': $icon = 'fa-mobile-alt'; break;
                                                    case 'laptop': $icon = 'fa-laptop'; break;
                                                    case 'tablet': $icon = 'fa-tablet-alt'; break;
                                                    case 'desktop': $icon = 'fa-desktop'; break;
                                                    case 'gaming': $icon = 'fa-gamepad'; break;
                                                    case 'tv': $icon = 'fa-tv'; break;
                                                    default: $icon = 'fa-cog';
                                                }
                                        ?>
                                        <div class="col-lg-2 col-md-3 col-sm-4 col-6 mb-3">
                                            <div class="specialty-badge <?php echo $is_selected ? 'active' : 'inactive'; ?>">
                                                <i class="fas <?php echo $icon; ?> me-1"></i>
                                                <?php echo ucfirst(htmlspecialchars($specialty, ENT_QUOTES, 'UTF-8')); ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="education" class="form-label">Education / Certifications</label>
                                    <textarea class="form-control" id="education" name="education" rows="2"><?php echo isset($provider_data['education']) ? htmlspecialchars($provider_data['education'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="bio" class="form-label">Bio / About Me</label>
                                    <textarea class="form-control" id="bio" name="bio" rows="4" placeholder="Tell customers about yourself, your experience, and why they should choose you..."><?php echo isset($provider_data['bio']) ? htmlspecialchars($provider_data['bio'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                                </div>
                                
                                <div class="text-end">
                                    <button type="submit" name="update_profile" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading overlay when page is loaded
            document.getElementById('loadingOverlay').style.display = 'none';
            
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
            
            // Add smooth animations for specialty badges
            document.querySelectorAll('.specialty-badge').forEach(badge => {
                badge.addEventListener('mouseenter', function() {
                    if (this.classList.contains('active')) {
                        this.style.transform = 'translateY(-3px)';
                        this.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.15)';
                    }
                });
                
                badge.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                    this.style.boxShadow = 'none';
                });
            });
            
            // File upload display
            const profileImageInput = document.getElementById('profileImageInput');
            const fileNameDisplay = document.getElementById('fileNameDisplay');
            
            profileImageInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    const fileName = this.files[0].name;
                    fileNameDisplay.textContent = fileName;
                    
                    // Show image preview
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const profileImages = document.querySelectorAll('img[alt="Profile"]');
                        profileImages.forEach(img => {
                            img.src = e.target.result;
                        });
                    }
                    reader.readAsDataURL(this.files[0]);
                } else {
                    fileNameDisplay.textContent = '';
                }
            });
            
            // Password strength meter
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const strengthMeter = document.getElementById('password-strength-meter');
            const strengthText = document.getElementById('password-strength-text');
            
            if (newPasswordInput) {
                newPasswordInput.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;
                    let feedbackText = '';
                    let meterClass = '';
                    
                    if (password.length > 0) {
                        // Calculate password strength
                        if (password.length >= 8) strength += 1;
                        if (password.match(/[a-z]/)) strength += 1;
                        if (password.match(/[A-Z]/)) strength += 1;
                        if (password.match(/[0-9]/)) strength += 1;
                        if (password.match(/[^a-zA-Z0-9]/)) strength += 1;
                        
                        // Update feedback
                        switch (strength) {
                            case 1:
                                feedbackText = 'Very weak';
                                meterClass = 'bg-danger';
                                strengthMeter.style.width = '20%';
                                break;
                            case 2:
                                feedbackText = 'Weak';
                                meterClass = 'bg-warning';
                                strengthMeter.style.width = '40%';
                                break;
                            case 3:
                                feedbackText = 'Fair';
                                meterClass = 'bg-info';
                                strengthMeter.style.width = '60%';
                                break;
                            case 4:
                                feedbackText = 'Good';
                                meterClass = 'bg-primary';
                                strengthMeter.style.width = '80%';
                                break;
                            case 5:
                                feedbackText = 'Strong';
                                meterClass = 'bg-success';
                                strengthMeter.style.width = '100%';
                                break;
                        }
                    } else {
                        feedbackText = '';
                        meterClass = '';
                        strengthMeter.style.width = '0%';
                    }
                    
                    // Update UI
                    strengthMeter.className = meterClass;
                    strengthText.textContent = feedbackText;
                });
                
                // Check if passwords match
                confirmPasswordInput.addEventListener('input', function() {
                    const confirmValue = this.value;
                    const passwordValue = newPasswordInput.value;
                    
                    if (confirmValue && passwordValue) {
                        if (confirmValue === passwordValue) {
                            this.classList.remove('is-invalid');
                            this.classList.add('is-valid');
                        } else {
                            this.classList.remove('is-valid');
                            this.classList.add('is-invalid');
                        }
                    } else {
                        this.classList.remove('is-valid', 'is-invalid');
                    }
                });
            }
        });
    </script>
</body>
</html>