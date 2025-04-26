<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Authentication check
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Redirect if not customer
if (!$loggedIn || $userRole !== 'customer' || $userId <= 0) {
    header('Location: ../login.php?redirect=customer');
    exit;
}

// Database connection using MySQLi
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$userData = [];
$userProfileImage = '../default.png';
$notificationCount = 0;
$recentNotifications = [];
$errorMessage = '';
$successMessage = '';
$userInitials = 'CN'; // Default initials
$passwordUpdateSuccess = false;
$phoneIsVerified = false;
$emailIsVerified = false;

// Check for flash messages from previous redirects
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    // Get form data
    $firstName = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $lastName = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    
    // Basic validation
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone)) {
        $errorMessage = "All fields are required. Please fill in all information.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = "Please enter a valid email address.";
    } else {
        try {
            // Check if email is already in use by another user
            $checkEmailStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $checkEmailStmt->bind_param("si", $email, $userId);
            $checkEmailStmt->execute();
            $emailResult = $checkEmailStmt->get_result();
            
            if ($emailResult->num_rows > 0) {
                $errorMessage = "Email address is already in use by another account.";
            } else {
                // Update user profile
                $updateStmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
                $updateStmt->bind_param("ssssi", $firstName, $lastName, $email, $phone, $userId);
                
                if ($updateStmt->execute()) {
                    $successMessage = "Your profile has been updated successfully.";
                    
                    // Update session variables
                    $_SESSION['name'] = $firstName . ' ' . $lastName;
                } else {
                    $errorMessage = "Failed to update your profile. Please try again.";
                }
                
                $updateStmt->close();
            }
            
            $checkEmailStmt->close();
        } catch (Exception $e) {
            error_log("Profile update error: " . $e->getMessage());
            $errorMessage = "An error occurred while updating your profile. Please try again later.";
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
    $newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirmPassword = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    
    // Basic validation
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $errorMessage = "All password fields are required.";
    } elseif ($newPassword !== $confirmPassword) {
        $errorMessage = "New password and confirmation do not match.";
    } elseif (strlen($newPassword) < 8) {
        $errorMessage = "New password must be at least 8 characters long.";
    } else {
        try {
            // Get current password from database
            $pwdStmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $pwdStmt->bind_param("i", $userId);
            $pwdStmt->execute();
            $pwdResult = $pwdStmt->get_result();
            $userData = $pwdResult->fetch_assoc();
            $pwdStmt->close();
            
            if ($userData && password_verify($currentPassword, $userData['password'])) {
                // Hash the new password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update the password
                $updatePwdStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $updatePwdStmt->bind_param("si", $hashedPassword, $userId);
                
                if ($updatePwdStmt->execute()) {
                    $successMessage = "Your password has been changed successfully.";
                    $passwordUpdateSuccess = true;
                } else {
                    $errorMessage = "Failed to update your password. Please try again.";
                }
                
                $updatePwdStmt->close();
            } else {
                $errorMessage = "Current password is incorrect.";
            }
        } catch (Exception $e) {
            error_log("Password update error: " . $e->getMessage());
            $errorMessage = "An error occurred while changing your password. Please try again later.";
        }
    }
}

// Handle profile image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_image') {
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['profile_image'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        $fileType = mime_content_type($file['tmp_name']);
        
        if (!in_array($fileType, $allowedTypes)) {
            $errorMessage = "Only JPEG, PNG, and GIF images are allowed.";
        } elseif ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $errorMessage = "File size must be less than 5MB.";
        } else {
            // Generate unique filename
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid() . '.' . $extension;
            $targetPath = '../profile_images/' . $filename;
            
            // Check if profile_images directory exists, create if not
            if (!is_dir('../profile_images')) {
                mkdir('../profile_images', 0755, true);
            }
            
            // Move the uploaded file
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                try {
                    // Update user profile image in database
                    $updateImgStmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $updateImgStmt->bind_param("si", $filename, $userId);
                    
                    if ($updateImgStmt->execute()) {
                        $successMessage = "Profile image updated successfully.";
                        $userProfileImage = $targetPath; // Update current image path
                    } else {
                        $errorMessage = "Failed to update profile image in database.";
                    }
                    
                    $updateImgStmt->close();
                } catch (Exception $e) {
                    error_log("Profile image update error: " . $e->getMessage());
                    $errorMessage = "An error occurred while updating your profile image.";
                }
            } else {
                $errorMessage = "Failed to upload the image. Please try again.";
            }
        }
    } else {
        $errorMessage = "No image selected or upload error occurred.";
    }
}

try {
    // Query to get customer user data with proper validation
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$userData) {
        // If customer data doesn't exist, log them out
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=invalid_session');
        exit;
    }
    
    // Check if phone is verified
    $phoneIsVerified = !empty($userData['phone_verified']) && $userData['phone_verified'] == 1;
    
    // Check if email is verified
    $emailIsVerified = !empty($userData['email_verified']) && $userData['email_verified'] == 1;
    
    // Set profile image path with proper validation
    if (!empty($userData['profile_image'])) {
        if (filter_var($userData['profile_image'], FILTER_VALIDATE_URL)) {
            // URL-based image with validation
            $userProfileImage = $userData['profile_image'];
        } else {
            // File-based image with protection against directory traversal
            $imageFile = basename($userData['profile_image']);
            $imagePath = '../profile_images/' . $imageFile;
            if (file_exists($imagePath) && is_file($imagePath)) {
                $userProfileImage = $imagePath;
            }
        }
    }
    
    // Get user initials for avatar
    if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
    } elseif (!empty($userData['first_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
    } elseif (!empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
    }
    
    // Get unread notifications count for the customer
    $notifCountQuery = "
        SELECT COUNT(*) AS count FROM quote_notifications
        WHERE recipient_id = ?
        AND is_read = 0
    ";
    
    $notifCountStmt = $conn->prepare($notifCountQuery);
    $notifCountStmt->bind_param("i", $userId);
    $notifCountStmt->execute();
    $notifCountResult = $notifCountStmt->get_result();
    $notificationCount = $notifCountResult->fetch_assoc()['count'];
    $notifCountStmt->close();
    
    // Get recent notifications for dropdown
    $recentNotifQuery = "
        SELECT * FROM quote_notifications 
        WHERE recipient_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ";
    
    $recentNotifStmt = $conn->prepare($recentNotifQuery);
    $recentNotifStmt->bind_param("i", $userId);
    $recentNotifStmt->execute();
    $recentNotifResult = $recentNotifStmt->get_result();
    
    while ($row = $recentNotifResult->fetch_assoc()) {
        $recentNotifications[] = $row;
    }
    $recentNotifStmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching your data. Please try again later.";
}

// Function to get readable time from timestamp
function timeAgo($timestamp) {
    if (empty($timestamp)) return 'N/A';
    
    try {
        $time = new DateTime($timestamp);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $time->getTimestamp();
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return date('M d, Y', strtotime($timestamp));
        }
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// Function to safely format dates
function formatDate($dateString, $format = 'M d, Y') {
    if (empty($dateString)) return 'N/A';
    
    try {
        $date = new DateTime($dateString);
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Manage your profile - FixItNow Customer Dashboard">
    <meta name="robots" content="noindex, nofollow">
    <title>My Profile - FixItNow</title>
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
            --sidebar-bg: #303238;         /* Sidebar background */
            --sidebar-hover: #3e4148;      /* Sidebar hover */
            --danger-color: #dc3545;       /* Danger/red color */
            --danger-light: #f8d7da;       /* Light danger background */
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
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --danger-light: #482930;       /* Darker danger background for dark mode */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
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
        
        /* Enhanced Header Styles */
        .site-header {
            background-color: #212529;
            padding: 0.75rem 0;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: #fff;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .logo-text:hover {
            transform: scale(1.02);
            color: #fff;
        }
        
        .logo-text .highlight {
            color: #4cd963;
            font-weight: 900;
        }
        
        /* Main Navigation Styles */
        .main-nav {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
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
            color: #fff;
            transform: translateY(-1px);
        }
        
        .nav-button.active {
            background-color: #7952b3;
            color: white;
            box-shadow: 0 2px 8px rgba(121, 82, 179, 0.4);
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Header Icon Buttons */
        .header-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .header-icon-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }
        
        .header-icon-btn:active {
            transform: translateY(0);
        }
        
        /* Notification Badge */
        .notification-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(220, 53, 69, 0.4);
        }
        
        /* Notification Dropdown */
        .notification-dropdown {
            width: 320px;
            padding: 0.5rem 0;
            max-height: 400px;
            overflow-y: auto;
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
        }
        
        .notification-item {
            display: flex;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .notification-icon.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .notification-icon.primary {
            background-color: rgba(121, 82, 179, 0.1);
            color: #7952b3;
        }
        
        .notification-icon.warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .notification-text {
            font-size: 0.825rem;
            color: #6c757d;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #adb5bd;
            margin-top: 0.25rem;
        }
        
        .view-all {
            font-weight: 500;
            padding: 0.5rem;
            color: #7952b3;
        }
        
        .view-all:hover {
            background-color: rgba(121, 82, 179, 0.05);
            color: #6941a0;
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }
        
        .user-dropdown-toggle {
            display: flex;
            align-items: center;
            padding: 0.4rem 0.6rem;
            border-radius: 0.375rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.95);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .user-dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .user-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            background-color: #7952b3;
            color: white;
            border-radius: 50%;
            font-size: 0.85rem;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(121, 82, 179, 0.3);
        }
        
        .dropdown-menu {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
            padding: 0.5rem 0;
            min-width: 220px;
        }
        
        .dropdown-item {
            padding: 0.65rem 1.25rem;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: rgba(121, 82, 179, 0.05);
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            color: #6c757d;
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .mobile-menu-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        
        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 71px; /* Height of header */
            left: 0;
            width: 100%;
            background-color: #212529;
            z-index: 999;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            overflow-y: auto;
            max-height: calc(100vh - 71px);
        }
        
        .mobile-menu.show {
            display: block;
        }
        
        .mobile-menu-inner {
            padding: 1rem 0;
        }
        
        .mobile-menu-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        
        .mobile-menu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-left-color: rgba(255, 255, 255, 0.2);
        }
        
        .mobile-menu-item.active {
            background-color: rgba(121, 82, 179, 0.1);
            color: #fff;
            border-left-color: #7952b3;
        }
        
        .mobile-menu-item i {
            width: 24px;
            margin-right: 0.75rem;
            text-align: center;
        }
        
        .mobile-menu-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 0.5rem 1.5rem;
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
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
        
        /* Profile Styles */
        .profile-image-container {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 0 auto 1.5rem;
        }
        
        .profile-image {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid var(--primary-color);
            box-shadow: 0 5px 15px var(--shadow-color);
        }
        
        .profile-image-initials {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            font-weight: bold;
            border: 4px solid var(--primary-light);
        }
        
        .image-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px var(--shadow-color);
            transition: all 0.2s ease;
        }
        
        .image-upload-btn:hover {
            transform: scale(1.1);
        }
        
        .password-requirements {
            margin-top: 0.75rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .password-requirements ul {
            padding-left: 1.25rem;
            margin-bottom: 0;
        }
        
        .verification-status {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        
        .verification-status.verified {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
        }
        
        .verification-status.unverified {
            background-color: rgba(255, 193, 7, 0.1);
            color: var(--warning-color);
        }
        
        /* Input Styles */
        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }

        /* Password strength meter */
        .password-strength {
            margin-top: 10px;
            height: 6px;
            border-radius: 3px;
            background-color: #e9ecef;
            overflow: hidden;
        }
        
        .password-strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s, background-color 0.3s;
        }
        
        .password-strength-label {
            font-size: 0.75rem;
            margin-top: 5px;
        }
        
        .password-strength-weak {
            background-color: #dc3545;
            width: 25%;
        }
        
        .password-strength-fair {
            background-color: #ffc107;
            width: 50%;
        }
        
        .password-strength-good {
            background-color: #17a2b8;
            width: 75%;
        }
        
        .password-strength-strong {
            background-color: #28a745;
            width: 100%;
        }
        
        /* Footer */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 1.5rem 0;
            margin-top: auto;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 1.5rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: #fff;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .content-area {
                padding: 1.5rem;
            }
        }
        
        @media (max-width: 768px) {
            .profile-image-container {
                width: 120px;
                height: 120px;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Improved Header -->
    <header class="site-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="logo-text">
                    <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="main-nav d-none d-lg-flex">
                    <a href="../index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="../find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="../services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="../how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="mobile-menu-toggle d-lg-none me-3" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="header-icon-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></div>
                            <i class="fas fa-chevron-down fa-xs ms-2"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="bookings.php"><i class="fas fa-calendar-check me-2"></i> My Bookings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="mobile-menu d-lg-none" id="mobileMenu">
            <div class="mobile-menu-inner">
                <a href="../index.php" class="mobile-menu-item">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../find-technician.php" class="mobile-menu-item">
                    <i class="fas fa-search"></i> Find Technician
                </a>
                <a href="../services.php" class="mobile-menu-item">
                    <i class="fas fa-cogs"></i> Services
                </a>
                <a href="../how-it-works.php" class="mobile-menu-item">
                    <i class="fas fa-info-circle"></i> How It Works
                </a>
                <div class="mobile-menu-divider"></div>
                <a href="dashboard.php" class="mobile-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="bookings.php" class="mobile-menu-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="profile.php" class="mobile-menu-item active">
                    <i class="fas fa-user"></i> My Profile
                </a>
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
                            <span class="nav-icon"><i class="fas fa-tachometer-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
                            <span class="nav-text">My Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star" aria-hidden="true"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link " href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
                            <span class="nav-text">My Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>

        <!-- Main Content Area -->
        <div class="content-area">
            <div class="mb-4">
                <h1 class="page-title">My Profile</h1>
            </div>
            
            <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <div class="row">
                <!-- Profile Image Section -->
                <div class="col-lg-4 mb-4">
                    <div class="card text-center">
                        <div class="card-header">
                            Profile Picture
                        </div>
                        <div class="card-body">
                            <div class="profile-image-container">
                                <?php if (file_exists($userProfileImage) && is_file($userProfileImage)): ?>
                                <img src="<?php echo htmlspecialchars($userProfileImage, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile" class="profile-image">
                                <?php else: ?>
                                <div class="profile-image-initials">
                                    <?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php endif; ?>
                                <label for="profile-image-upload" class="image-upload-btn" title="Change profile picture">
                                    <i class="fas fa-camera"></i>
                                </label>
                            </div>
                            <h5 class="mb-1"><?php echo htmlspecialchars(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h5>
                            <p class="text-muted mb-3"><?php echo htmlspecialchars($userData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                            
                            <form method="POST" action="profile.php" enctype="multipart/form-data" id="image-upload-form">
                                <input type="hidden" name="action" value="upload_image">
                                <input type="file" id="profile-image-upload" name="profile_image" accept="image/jpeg,image/png,image/gif" class="d-none">
                            </form>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                                    <i class="fas fa-lock me-2" aria-hidden="true"></i>Change Password
                                </button>
                            </div>
                        </div>
                        <div class="card-footer text-muted">
                            Member since <?php echo formatDate($userData['created_at'] ?? ''); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Details Section -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            Personal Information
                        </div>
                        <div class="card-body">
                            <form method="POST" action="profile.php">
                                <input type="hidden" name="action" value="update_profile">
                                <div class="row mb-3">
                                    <div class="col-md-6 mb-3 mb-md-0">
                                        <label for="first_name" class="form-label">First Name</label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo htmlspecialchars($userData['first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="last_name" class="form-label">Last Name</label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo htmlspecialchars($userData['last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address
                                        <?php if ($emailIsVerified): ?>
                                        <span class="verification-status verified"><i class="fas fa-check-circle me-1"></i> Verified</span>
                                        <?php else: ?>
                                        <span class="verification-status unverified"><i class="fas fa-exclamation-circle me-1"></i> Unverified</span>
                                        <?php endif; ?>
                                    </label>
                                    <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($userData['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <?php if (!$emailIsVerified): ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="verifyEmailBtn">
                                            <i class="fas fa-envelope me-1" aria-hidden="true"></i> Verify Email
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Phone Number
                                        <?php if ($phoneIsVerified): ?>
                                        <span class="verification-status verified"><i class="fas fa-check-circle me-1"></i> Verified</span>
                                        <?php else: ?>
                                        <span class="verification-status unverified"><i class="fas fa-exclamation-circle me-1"></i> Unverified</span>
                                        <?php endif; ?>
                                    </label>
                                    <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($userData['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                                    <?php if (!$phoneIsVerified): ?>
                                    <div class="mt-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary" id="verifyPhoneBtn">
                                            <i class="fas fa-phone me-1" aria-hidden="true"></i> Verify Phone
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username</label>
                                    <input type="text" class="form-control" id="username" value="<?php echo htmlspecialchars($userData['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                                    <div class="form-text">Username cannot be changed.</div>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2" aria-hidden="true"></i> Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Account Information -->
                    <div class="card mt-4">
                        <div class="card-header">
                            Account Information
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <h6 class="fw-bold mb-2">Account Status</h6>
                                    <div>
                                        <span class="badge <?php echo ($userData['status'] ?? '') === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                                            <?php echo ucfirst(htmlspecialchars($userData['status'] ?? 'Unknown', ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <h6 class="fw-bold mb-2">Account Type</h6>
                                    <div>
                                        <span class="badge bg-primary">
                                            <?php echo ucfirst(htmlspecialchars($userData['role'] ?? 'Customer', ENT_QUOTES, 'UTF-8')); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <h6 class="fw-bold mb-2">Member Since</h6>
                                    <div><?php echo formatDate($userData['created_at'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold mb-2">Last Updated</h6>
                                    <div><?php echo formatDate($userData['updated_at'] ?? ''); ?></div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle me-2" aria-hidden="true"></i> Your personal information is kept secure and is only used to provide you with better service.
                            </div>
                            
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Change Password Modal -->
    <div class="modal fade" id="changePasswordModal" tabindex="-1" aria-labelledby="changePasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changePasswordModalLabel">Change Your Password</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" action="profile.php" id="passwordForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="change_password">
                        
                        <?php if ($passwordUpdateSuccess): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2" aria-hidden="true"></i> Your password has been updated successfully.
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="current_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="new_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                            <div class="password-strength">
                                <div class="password-strength-meter" id="passwordStrengthMeter"></div>
                            </div>
                            <div class="password-strength-label" id="passwordStrengthLabel"></div>
                            
                            <div class="password-requirements">
                                <p class="mb-1">Password must include:</p>
                                <ul>
                                    <li>At least 8 characters long</li>
                                    <li>Include at least one uppercase letter</li>
                                    <li>Include at least one number</li>
                                    <li>Include at least one special character</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                <button class="btn btn-outline-secondary toggle-password" type="button" data-target="confirm_password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="form-text text-danger d-none" id="passwordMismatch">Passwords do not match</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Change Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">© 2023 FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <ul class="footer-links d-flex flex-wrap justify-content-md-end">
                        <li><a href="../about.php">About</a></li>
                        <li><a href="../contact.php">Contact</a></li>
                        <li><a href="../privacy.php">Privacy Policy</a></li>
                        <li><a href="../terms.php">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('show');
                    const isOpen = mobileMenu.classList.contains('show');
                    mobileMenuToggle.innerHTML = isOpen ? 
                        '<i class="fas fa-times"></i>' : 
                        '<i class="fas fa-bars"></i>';
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenu.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        if (mobileMenu.classList.contains('show')) {
                            mobileMenu.classList.remove('show');
                            mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
                        }
                    }
                });
            }
            
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
                // Default to light theme for customer side
                setTheme(false);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-info)');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Profile image upload
            const profileImageUpload = document.getElementById('profile-image-upload');
            
            if (profileImageUpload) {
                profileImageUpload.addEventListener('change', function() {
                    if (this.files && this.files[0]) {
                        document.getElementById('image-upload-form').submit();
                    }
                });
            }
            
            // Password visibility toggle
            const togglePasswordButtons = document.querySelectorAll('.toggle-password');
            
            togglePasswordButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const passwordInput = document.getElementById(targetId);
                    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordInput.setAttribute('type', type);
                    
                    // Toggle eye icon
                    const icon = this.querySelector('i');
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                });
            });
            
            // Password strength meter
            const passwordInput = document.getElementById('new_password');
            const strengthMeter = document.getElementById('passwordStrengthMeter');
            const strengthLabel = document.getElementById('passwordStrengthLabel');
            
            if (passwordInput && strengthMeter && strengthLabel) {
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    let strength = 0;
                    
                    // Length check
                    if (password.length >= 8) strength += 1;
                    
                    // Character variety checks
                    if (password.match(/[A-Z]/)) strength += 1;
                    if (password.match(/[0-9]/)) strength += 1;
                    if (password.match(/[^A-Za-z0-9]/)) strength += 1;
                    
                    // Update meter and label
                    strengthMeter.className = 'password-strength-meter';
                    
                    if (password.length === 0) {
                        strengthMeter.style.width = '0';
                        strengthLabel.textContent = '';
                    } else if (strength < 2) {
                        strengthMeter.classList.add('password-strength-weak');
                        strengthLabel.textContent = 'Weak';
                        strengthLabel.style.color = '#dc3545';
                    } else if (strength === 2) {
                        strengthMeter.classList.add('password-strength-fair');
                        strengthLabel.textContent = 'Fair';
                        strengthLabel.style.color = '#ffc107';
                    } else if (strength === 3) {
                        strengthMeter.classList.add('password-strength-good');
                        strengthLabel.textContent = 'Good';
                        strengthLabel.style.color = '#17a2b8';
                    } else {
                        strengthMeter.classList.add('password-strength-strong');
                        strengthLabel.textContent = 'Strong';
                        strengthLabel.style.color = '#28a745';
                    }
                });
            }
            
            // Password confirmation match check
            const confirmPasswordInput = document.getElementById('confirm_password');
            const passwordMismatch = document.getElementById('passwordMismatch');
            
            if (confirmPasswordInput && passwordMismatch && passwordInput) {
                confirmPasswordInput.addEventListener('input', function() {
                    const password = passwordInput.value;
                    const confirmPassword = this.value;
                    
                    if (confirmPassword && password !== confirmPassword) {
                        passwordMismatch.classList.remove('d-none');
                    } else {
                        passwordMismatch.classList.add('d-none');
                    }
                });
                
                // Also check when main password changes
                passwordInput.addEventListener('input', function() {
                    const password = this.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (confirmPassword && password !== confirmPassword) {
                        passwordMismatch.classList.remove('d-none');
                    } else {
                        passwordMismatch.classList.add('d-none');
                    }
                });
            }
            
            // Form validation before submission
            const passwordForm = document.getElementById('passwordForm');
            
            if (passwordForm) {
                passwordForm.addEventListener('submit', function(event) {
                    const password = passwordInput.value;
                    const confirmPassword = confirmPasswordInput.value;
                    
                    if (password !== confirmPassword) {
                        event.preventDefault();
                        passwordMismatch.classList.remove('d-none');
                    }
                });
            }
            
            // Verification buttons - these would typically open modals or initiate verification flows
            const verifyEmailBtn = document.getElementById('verifyEmailBtn');
            const verifyPhoneBtn = document.getElementById('verifyPhoneBtn');
            
            if (verifyEmailBtn) {
                verifyEmailBtn.addEventListener('click', function() {
                    alert('Email verification feature would be implemented here. A verification email would be sent to your email address.');
                });
            }
            
            if (verifyPhoneBtn) {
                verifyPhoneBtn.addEventListener('click', function() {
                    alert('Phone verification feature would be implemented here. An SMS with a verification code would be sent to your phone number.');
                });
            }
        });
    </script>
</body>
</html>