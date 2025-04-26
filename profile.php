<?php
session_start();

// Check if user is logged in, redirect to login page if not
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Include database connection
include 'conn.php';

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$message = '';
$messageType = '';

// Get user information
$userQuery = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($userQuery);
$stmt->bind_param("i", $userId);
$stmt->execute();
$userResult = $stmt->get_result();

if ($userResult->num_rows == 0) {
    // If user not found, log out and redirect
    session_destroy();
    header("Location: login.php?error=invalid_user");
    exit;
}

$userData = $userResult->fetch_assoc();

// Handle additional information based on user role
$providerData = null;
$reviewsData = [];
$bookingsData = [];

if ($userRole == 'provider') {
    // Get provider data
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $conn->prepare($providerQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $providerResult = $stmt->get_result();
    
    if ($providerResult->num_rows > 0) {
        $providerData = $providerResult->fetch_assoc();
        
        // Get reviews for provider
        $reviewsQuery = "SELECT r.*, u.first_name, u.last_name, u.profile_image 
                        FROM reviews r 
                        JOIN users u ON r.customer_id = u.id 
                        WHERE r.provider_id = ? 
                        ORDER BY r.created_at DESC";
        $stmt = $conn->prepare($reviewsQuery);
        $stmt->bind_param("i", $providerData['id']);
        $stmt->execute();
        $reviewsResult = $stmt->get_result();
        
        while ($review = $reviewsResult->fetch_assoc()) {
            $reviewsData[] = $review;
        }
    }
} elseif ($userRole == 'customer') {
    // Get bookings for customer
    $bookingsQuery = "SELECT b.*, u.first_name, u.last_name, p.hourly_rate, p.specialties 
                     FROM bookings b 
                     JOIN providers p ON b.provider_id = p.id 
                     JOIN users u ON p.user_id = u.id 
                     WHERE b.customer_id = ? 
                     ORDER BY b.booking_date DESC";
    $stmt = $conn->prepare($bookingsQuery);
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $bookingsResult = $stmt->get_result();
    
    while ($booking = $bookingsResult->fetch_assoc()) {
        $bookingsData[] = $booking;
    }
}

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_profile'])) {
    // Validate input data
    $firstName = trim($conn->real_escape_string($_POST['first_name']));
    $lastName = trim($conn->real_escape_string($_POST['last_name']));
    $email = trim($conn->real_escape_string($_POST['email']));
    $phone = trim($conn->real_escape_string($_POST['phone']));
    $address = trim($conn->real_escape_string($_POST['address']));
    $bio = isset($_POST['bio']) ? trim($conn->real_escape_string($_POST['bio'])) : '';
    
    // Handle profile image upload
    $profileImage = $userData['profile_image']; // Keep current image as default
    
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Validate file extension
        if (in_array($fileExt, $allowed)) {
            // Create unique filename
            $newFilename = 'user_' . $userId . '_' . time() . '.' . $fileExt;
            $uploadPath = 'images/profiles/' . $newFilename;
            
            // Ensure directory exists
            if (!file_exists('images/profiles/')) {
                mkdir('images/profiles/', 0777, true);
            }
            
            // Move uploaded file
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)) {
                $profileImage = $uploadPath;
            }
        }
    }
    
    // Update user data in users table
    $updateUserQuery = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, profile_image = ? WHERE id = ?";
    $stmt = $conn->prepare($updateUserQuery);
    $stmt->bind_param("sssssi", $firstName, $lastName, $email, $phone, $profileImage, $userId);
    
    if ($stmt->execute()) {
        // Update additional data based on user role
        if ($userRole == 'provider' && isset($_POST['hourly_rate']) && isset($_POST['experience'])) {
            // Fix: Handle specialties properly - check if it's an array first
            $specialtiesArray = isset($_POST['specialties']) ? (is_array($_POST['specialties']) ? $_POST['specialties'] : [$_POST['specialties']]) : [];
            $specialties = implode(',', array_map(function($item) use ($conn) {
                return trim($conn->real_escape_string($item));
            }, $specialtiesArray));
            
            $hourlyRate = (float) $_POST['hourly_rate'];
            $experience = trim($conn->real_escape_string($_POST['experience']));
            $location = trim($conn->real_escape_string($_POST['location']));
            
            $updateProviderQuery = "UPDATE providers SET specialties = ?, hourly_rate = ?, experience = ?, location = ?, bio = ? WHERE user_id = ?";
            $stmt = $conn->prepare($updateProviderQuery);
            $stmt->bind_param("sdsssi", $specialties, $hourlyRate, $experience, $location, $bio, $userId);
            $stmt->execute();
        }
        
        $message = "Profile updated successfully!";
        $messageType = "success";
        
        // Refresh user data after update
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $userResult = $stmt->get_result();
        $userData = $userResult->fetch_assoc();
        
        // Reload provider data if needed
        if ($userRole == 'provider') {
            $stmt = $conn->prepare($providerQuery);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $providerResult = $stmt->get_result();
            $providerData = $providerResult->fetch_assoc();
        }
    } else {
        $message = "Error updating profile: " . $stmt->error;
        $messageType = "danger";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'];
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validate new password matches confirmation
    if ($newPassword != $confirmPassword) {
        $message = "New password and confirmation do not match!";
        $messageType = "danger";
    } else {
        // Verify current password
        if (password_verify($currentPassword, $userData['password'])) {
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePasswordQuery = "UPDATE users SET password = ? WHERE id = ?";
            $stmt = $conn->prepare($updatePasswordQuery);
            $stmt->bind_param("si", $hashedPassword, $userId);
            
            if ($stmt->execute()) {
                $message = "Password changed successfully!";
                $messageType = "success";
            } else {
                $message = "Error changing password: " . $stmt->error;
                $messageType = "danger";
            }
        } else {
            $message = "Current password is incorrect!";
            $messageType = "danger";
        }
    }
}

// Process profile image
$profileImage = 'images/default.png'; // Default image

if (!empty($userData['profile_image'])) {
    // Check if image starts with http:// or https://
    if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
        $profileImage = $userData['profile_image'];
    } else {
        // If relative path, ensure it's properly formed
        $profileImage = ltrim($userData['profile_image'], '/');
        
        // Check if path contains "images/"
        if (strpos($profileImage, 'images/') === false) {
            $profileImage = 'images/' . $profileImage;
        }
    }
}

// Convert specialties to array (for providers)
$specialtiesArray = [];
if ($userRole == 'provider' && isset($providerData['specialties'])) {
    $specialtiesArray = array_map('trim', explode(',', $providerData['specialties']));
}

// Available service categories
$serviceCategories = [
    'smartphone' => 'Smartphone Repair',
    'laptop' => 'Laptop Repair',
    'tablet' => 'Tablet Repair',
    'computer' => 'Computer Repair',
    'console' => 'Game Console Repair',
    'tv' => 'TV/Monitor Repair'
];

// Comprehensive list of Saudi cities in alphabetical order
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

// Experience levels
$experienceLevels = [
    '0-1' => 'Less than 1 year',
    '1-3' => '1-3 years',
    '3-5' => '3-5 years',
    '5-10' => '5-10 years',
    '10+' => 'More than 10 years'
];

// Page title
$page_title = "Profile | FixItNow";
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
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
        
        /* Main Content Styles */
        .main-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .profile-header {
            background-color: var(--primary-color);
            color: var(--header-text);
            padding: 3rem 2rem;
            border-radius: 1rem 1rem 0 0;
            position: relative;
            overflow: hidden;
        }
        
        .profile-header-pattern {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: url('data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI1IiBoZWlnaHQ9IjUiPgo8cmVjdCB3aWR0aD0iNSIgaGVpZ2h0PSI1IiBmaWxsPSIjZmZmIiBvcGFjaXR5PSIwLjA1Ii8+CjxwYXRoIGQ9Ik0wIDVMNSAwWk02IDRMNCA2Wk0tMSAxTDEgLTFaIiBzdHJva2U9IiNmZmYiIHN0cm9rZS13aWR0aD0iMSIgb3BhY2l0eT0iMC4xIi8+Cjwvc3ZnPg==');
            opacity: 0.2;
        }
        
        .profile-avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid var(--header-text);
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            background-color: var(--card-bg);
            z-index: 10;
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .edit-avatar-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            background-color: var(--accent-color);
            color: var(--header-text);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 2px solid var(--header-text);
            transition: all 0.3s ease;
            z-index: 20;
        }
        
        .edit-avatar-btn:hover {
            background-color: var(--primary-hover);
        }
        
        .profile-name {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 10;
        }
        
        .profile-info {
            margin-bottom: 1rem;
            font-size: 1.1rem;
            opacity: 0.9;
            position: relative;
            z-index: 10;
        }
        
        .profile-actions {
            margin-top: 1.5rem;
            position: relative;
            z-index: 10;
        }
        
        .profile-tabs {
            background-color: var(--card-bg);
            border-radius: 0 0 1rem 1rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: background-color 0.3s ease;
        }
        
        .nav-tabs {
            border-bottom: 1px solid var(--border-color);
            background-color: rgba(0, 0, 0, 0.05);
            padding: 0 1rem;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        
        .nav-tabs .nav-link {
            color: var(--text-color);
            font-weight: 600;
            padding: 1rem 1.5rem;
            border: none;
            border-bottom: 3px solid transparent;
            background-color: transparent;
            transition: all 0.2s ease;
        }
        
        .nav-tabs .nav-link:hover {
            border-color: rgba(0, 0, 0, 0.1);
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .nav-tabs .nav-link.active {
            color: var(--primary-color);
            border-color: var(--primary-color);
            background-color: transparent;
        }
        
        .tab-content {
            padding: 2rem;
        }
        
        /* Form Styles */
        .form-floating > .form-control {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
        
        .form-floating > .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
        }
        
        .form-floating > label {
            color: var(--text-muted);
        }
        
        .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-accent {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
            color: var(--header-text);
            transition: all 0.3s ease;
        }
        
        .btn-accent:hover {
            background-color: #2ea043;
            border-color: #2ea043;
            color: var(--header-text);
        }
        
        /* Reviews & Bookings */
        .review-card, .booking-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease, background-color 0.3s ease;
            border: 1px solid var(--border-color);
        }
        
        .review-card:hover, .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .review-header, .booking-header {
            display: flex;
            align-items: center;
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .review-avatar, .booking-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
            background-color: var(--input-bg);
        }
        
        .review-avatar img, .booking-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .review-user, .booking-provider {
            flex-grow: 1;
        }
        
        .review-user h4, .booking-provider h4 {
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
            font-weight: 600;
        }
        
        .review-date, .booking-date {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .review-rating {
            display: flex;
            align-items: center;
        }
        
        .review-stars {
            color: #ffc107;
            font-size: 1.1rem;
            margin-right: 0.5rem;
        }
        
        .booking-status {
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-confirmed {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .review-content, .booking-content {
            padding: 1.25rem;
        }
        
        .booking-details {
            margin-bottom: 1rem;
        }
        
        .booking-detail {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }
        
        .booking-detail i {
            width: 24px;
            color: var(--primary-color);
            margin-right: 0.5rem;
        }
        
        .booking-detail strong {
            color: var(--text-color);
            margin-right: 0.5rem;
        }
        
        .booking-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
        }
        
        /* Currency image styling */
        .currency-img {
            height: 18px;
            width: auto;
            margin-right: 4px;
            vertical-align: middle;
        }
        
        /* Responsive Styles */
        @media (max-width: 768px) {
            .profile-header {
                padding: 2rem 1rem;
                text-align: center;
            }
            
            .profile-avatar {
                margin-left: auto;
                margin-right: auto;
            }
            
            .nav-tabs .nav-link {
                padding: 0.75rem 1rem;
                font-size: 0.9rem;
            }
            
            .tab-content {
                padding: 1.5rem 1rem;
            }
        }
        
        /* Footer Styles */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 4rem 0 2rem;
            margin-top: 4rem;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        .footer-logo {
            font-size: 1.75rem;
            font-weight: 900;
            margin-bottom: 1.5rem;
            color: var(--header-text);
        }
        
        .footer-logo .highlight {
            color: var(--accent-color);
        }
        
        .footer-heading {
            color: var(--header-text);
            font-weight: 700;
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
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
        }
        
        .footer-links a:hover {
            color: var(--header-text);
        }
        
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 2rem;
            margin-top: 2rem;
        }
        
        .footer-bottom p {
            margin-bottom: 0;
        }
        
        /* Select2 custom styling */
        .select2-container--default .select2-selection--multiple {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-color: var(--primary-light);
        }
        
        .select2-dropdown {
            background-color: var(--card-bg);
            border-color: var(--input-border);
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: var(--primary-color);
        }
        
        .select2-container--default .select2-search--dropdown .select2-search__field {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
        }
        
        .select2-container--default .select2-search--inline .select2-search__field {
            color: var(--text-color);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="text-decoration-none">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                </a>
                
                <!-- Navigation -->
                <nav class="d-none d-md-flex header-nav">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                    <a class="nav-link" href="marketplace.php">
                        <i class="fas fa-search me-1"></i> Find Technician
                    </a>
                    <a class="nav-link" href="index.php#services">
                        <i class="fas fa-cog me-1"></i> Services
                    </a>
                    <a class="nav-link" href="index.php#how-it-works">
                        <i class="fas fa-info-circle me-1"></i> How It Works
                    </a>
                </nav>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-sun" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Action -->
                    <div class="dropdown">
                        <button class="btn btn-dark dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline"><?php echo htmlspecialchars($userData['username']); ?></span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <?php if($userRole == 'admin'): ?>
                                <li><a class="dropdown-item" href="admin/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Admin Dashboard</a></li>
                            <?php elseif($userRole == 'provider'): ?>
                                <li><a class="dropdown-item" href="provider/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Provider Dashboard</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item" href="customer/dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> My Account</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item active" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
                
                <!-- Mobile Toggle -->
                <button class="navbar-toggler d-md-none ms-2" type="button" data-bs-toggle="collapse" data-bs-target="#mobileNav">
                    <i class="fas fa-bars text-white"></i>
                </button>
            </div>
            
            <!-- Mobile Navigation -->
            <div class="collapse navbar-collapse mt-3 d-md-none" id="mobileNav">
                <nav class="header-nav d-flex flex-column">
                    <a class="nav-link" href="index.php">
                        <i class="fas fa-home me-1"></i> Home
                    </a>
                    <a class="nav-link" href="marketplace.php">
                        <i class="fas fa-search me-1"></i> Find Technician
                    </a>
                    <a class="nav-link" href="index.php#services">
                        <i class="fas fa-cog me-1"></i> Services
                    </a>
                    <a class="nav-link" href="index.php#how-it-works">
                        <i class="fas fa-info-circle me-1"></i> How It Works
                    </a>
                </nav>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-container">
        <?php if(!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show mb-4" role="alert">
            <?php echo $message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="profile-header-pattern"></div>
            <div class="text-center">
                <div class="profile-avatar mx-auto">
                    <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="<?php echo htmlspecialchars($userData['first_name']); ?>" onerror="this.src='images/default.png'">
                    <label for="profile_image_upload" class="edit-avatar-btn">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>
                <h1 class="profile-name"><?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?></h1>
                
                <div class="profile-info">
                    <?php if($userRole == 'provider' && isset($providerData)): ?>
                        <span class="badge bg-primary me-2"><?php echo ucfirst($userRole); ?></span>
                        <i class="fas fa-map-marker-alt me-1"></i> <?php echo htmlspecialchars($providerData['location'] ?? 'Not specified'); ?>
                    <?php elseif($userRole == 'customer'): ?>
                        <span class="badge bg-success me-2">Customer</span>
                    <?php elseif($userRole == 'admin'): ?>
                        <span class="badge bg-danger me-2">Administrator</span>
                    <?php endif; ?>
                </div>
                
                <p class="text-white opacity-75"><?php echo htmlspecialchars($userData['email']); ?> &bull; <?php echo htmlspecialchars($userData['phone'] ?? 'Phone number not available'); ?></p>
                
                <?php if($userRole == 'provider' && isset($providerData)): ?>
                <div class="profile-actions">
                    <a href="technician.php?id=<?php echo $userId; ?>" class="btn btn-outline-light">
                        <i class="fas fa-eye me-2"></i> View Public Profile
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Profile Tabs -->
        <div class="profile-tabs">
            <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="profile-tab" data-bs-toggle="tab" data-bs-target="#profile-pane" type="button" role="tab" aria-controls="profile-pane" aria-selected="true">
                        <i class="fas fa-user me-2"></i> Personal Info
                    </button>
                </li>
                
                <?php if($userRole == 'provider'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="reviews-tab" data-bs-toggle="tab" data-bs-target="#reviews-pane" type="button" role="tab" aria-controls="reviews-pane" aria-selected="false">
                        <i class="fas fa-star me-2"></i> Reviews <span class="badge rounded-pill bg-primary ms-1"><?php echo count($reviewsData); ?></span>
                    </button>
                </li>
                <?php elseif($userRole == 'customer'): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bookings-tab" data-bs-toggle="tab" data-bs-target="#bookings-pane" type="button" role="tab" aria-controls="bookings-pane" aria-selected="false">
                        <i class="fas fa-calendar-check me-2"></i> Bookings <span class="badge rounded-pill bg-primary ms-1"><?php echo count($bookingsData); ?></span>
                    </button>
                </li>
                <?php endif; ?>
                
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security-pane" type="button" role="tab" aria-controls="security-pane" aria-selected="false">
                        <i class="fas fa-lock me-2"></i> Security
                    </button>
                </li>
            </ul>
            
            <div class="tab-content" id="profileTabsContent">
                <!-- Profile Tab -->
                <div class="tab-pane fade show active" id="profile-pane" role="tabpanel" aria-labelledby="profile-tab" tabindex="0">
                    <form method="post" action="profile.php" enctype="multipart/form-data">
                        <input type="hidden" name="update_profile" value="1">
                        <!-- Hidden file input for profile image -->
                        <input type="file" name="profile_image" id="profile_image_upload" accept="image/*" style="display: none;" onchange="previewImage(this)">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="First Name" value="<?php echo htmlspecialchars($userData['first_name']); ?>" required>
                                    <label for="first_name">First Name</label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Last Name" value="<?php echo htmlspecialchars($userData['last_name']); ?>" required>
                                    <label for="last_name">Last Name</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-floating">
                                    <input type="email" class="form-control" id="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($userData['email']); ?>" required>
                                    <label for="email">Email</label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-floating">
                                    <input type="tel" class="form-control" id="phone" name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>">
                                    <label for="phone">Phone Number</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="address" name="address" placeholder="Address" value="<?php echo htmlspecialchars($userData['address'] ?? ''); ?>">
                                <label for="address">Address</label>
                            </div>
                        </div>
                        
                        <?php if($userRole == 'provider' && isset($providerData)): ?>
                        <hr class="my-4">
                        <h4 class="mb-3">Provider Information</h4>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="specialties" class="form-label">Specialties</label>
                                <select class="form-select" id="specialties" name="specialties[]" multiple>
                                    <?php foreach($serviceCategories as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo in_array($key, $specialtiesArray) ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Select repair specialties you offer</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="location" class="form-label">Location</label>
                                <select class="form-select" id="location" name="location">
                                    <option value="">Select city</option>
                                    <?php foreach($locations as $loc): ?>
                                        <option value="<?php echo $loc; ?>" <?php echo ($providerData['location'] ?? '') == $loc ? 'selected' : ''; ?>><?php echo $loc; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="hourly_rate" class="form-label">Hourly Rate</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <img src="sar/sar.png" alt="SAR" class="currency-img">
                                    </span>
                                    <input type="number" class="form-control" id="hourly_rate" name="hourly_rate" step="0.01" min="0" value="<?php echo htmlspecialchars($providerData['hourly_rate'] ?? '0.00'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="experience" class="form-label">Experience Level</label>
                                <select class="form-select" id="experience" name="experience">
                                    <?php foreach($experienceLevels as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo ($providerData['experience'] ?? '') == $key ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="bio" class="form-label">Bio</label>
                            <textarea class="form-control" id="bio" name="bio" rows="5" placeholder="Write a short bio about yourself..."><?php echo htmlspecialchars($userRole == 'provider' ? ($providerData['bio'] ?? '') : ''); ?></textarea>
                        </div>
                        
                        <div class="text-end mt-4">
                            <button type="reset" class="btn btn-outline-secondary me-2">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
                
                <?php if($userRole == 'provider'): ?>
                <!-- Reviews Tab -->
                <div class="tab-pane fade" id="reviews-pane" role="tabpanel" aria-labelledby="reviews-tab" tabindex="0">
                    <?php if(count($reviewsData) > 0): ?>
                        <h4 class="mb-4">Reviews (<?php echo count($reviewsData); ?>)</h4>
                        
                        <?php foreach($reviewsData as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <div class="review-avatar">
                                        <?php 
                                        $reviewerImage = 'images/default.png';
                                        if (!empty($review['profile_image'])) {
                                            if (preg_match('/^https?:\/\//', $review['profile_image'])) {
                                                $reviewerImage = $review['profile_image'];
                                            } else {
                                                $reviewerImage = ltrim($review['profile_image'], '/');
                                                if (strpos($reviewerImage, 'images/') === false) {
                                                    $reviewerImage = 'images/' . $reviewerImage;
                                                }
                                            }
                                        }
                                        ?>
                                        <img src="<?php echo htmlspecialchars($reviewerImage); ?>" alt="<?php echo htmlspecialchars($review['first_name']); ?>" onerror="this.src='images/default.png'">
                                    </div>
                                    <div class="review-user">
                                        <h4><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></h4>
                                        <div class="review-date">
                                            <i class="far fa-calendar-alt me-1"></i> <?php echo date('d M Y', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <div class="review-stars">
                                            <?php for($i=1; $i<=5; $i++): ?>
                                                <?php if($i <= $review['rating']): ?>
                                                    <i class="fas fa-star"></i>
                                                <?php else: ?>
                                                    <i class="far fa-star"></i>
                                                <?php endif; ?>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="badge bg-primary"><?php echo $review['rating']; ?>/5</span>
                                    </div>
                                </div>
                                <div class="review-content">
                                    <p><?php echo htmlspecialchars($review['comment']); ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-star-half-alt fa-4x text-muted mb-4"></i>
                            <h4>No reviews yet</h4>
                            <p class="text-muted">When customers review your services, they'll appear here.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php elseif($userRole == 'customer'): ?>
                <!-- Bookings Tab -->
                <div class="tab-pane fade" id="bookings-pane" role="tabpanel" aria-labelledby="bookings-tab" tabindex="0">
                    <?php if(count($bookingsData) > 0): ?>
                        <h4 class="mb-4">Bookings (<?php echo count($bookingsData); ?>)</h4>
                        
                        <?php foreach($bookingsData as $booking): ?>
                            <?php
                            // Define booking status and class
                            $statusClass = '';
                            $statusLabel = '';
                            
                            switch($booking['status']) {
                                case 'pending':
                                    $statusClass = 'status-pending';
                                    $statusLabel = 'Pending';
                                    break;
                                case 'confirmed':
                                    $statusClass = 'status-confirmed';
                                    $statusLabel = 'Confirmed';
                                    break;
                                case 'completed':
                                    $statusClass = 'status-completed';
                                    $statusLabel = 'Completed';
                                    break;
                                case 'cancelled':
                                    $statusClass = 'status-cancelled';
                                    $statusLabel = 'Cancelled';
                                    break;
                                default:
                                    $statusClass = 'status-pending';
                                    $statusLabel = 'Pending';
                            }
                            ?>
                            <div class="booking-card">
                                <div class="booking-header">
                                    <div class="booking-provider">
                                        <h4><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></h4>
                                        <div class="d-flex align-items-center flex-wrap">
                                            <div class="booking-date me-3">
                                                <i class="far fa-calendar-alt me-1"></i> <?php echo date('d M Y', strtotime($booking['booking_date'])); ?>
                                            </div>
                                            <div class="booking-time me-3">
                                                <i class="far fa-clock me-1"></i> <?php echo date('h:i A', strtotime($booking['booking_time'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="booking-status <?php echo $statusClass; ?>">
                                        <?php echo $statusLabel; ?>
                                    </div>
                                </div>
                                <div class="booking-content">
                                    <div class="booking-details">
                                        <div class="booking-detail">
                                            <i class="fas fa-tag"></i>
                                            <strong>Service:</strong> <?php echo htmlspecialchars($booking['service_type'] ?? 'Not specified'); ?>
                                        </div>
                                        <div class="booking-detail">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <strong>Location:</strong> <?php echo htmlspecialchars($booking['location'] ?? 'Not specified'); ?>
                                        </div>
                                        <div class="booking-detail">
                                            <i class="fas fa-money-bill-wave"></i>
                                            <strong>Estimated Cost:</strong> 
                                            <img src="sar/sar.png" alt="SAR" class="currency-img">
                                            <?php echo number_format($booking['total_price'] ?? 0, 2); ?>
                                        </div>
                                        <?php if(!empty($booking['notes'])): ?>
                                        <div class="booking-detail">
                                            <i class="fas fa-sticky-note"></i>
                                            <strong>Notes:</strong> <?php echo htmlspecialchars($booking['notes']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if($booking['status'] == 'pending' || $booking['status'] == 'confirmed'): ?>
                                    <div class="booking-actions">
                                        <?php if($booking['status'] == 'pending'): ?>
                                            <a href="edit_booking.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit me-1"></i> Edit
                                            </a>
                                        <?php endif; ?>
                                        <a href="cancel_booking.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-times-circle me-1"></i> Cancel
                                        </a>
                                    </div>
                                    <?php elseif($booking['status'] == 'completed'): ?>
                                        <?php 
                                        // Check if user has already reviewed this booking
                                        $reviewQuery = "SELECT id FROM reviews WHERE booking_id = ? AND customer_id = ?";
                                        $stmt = $conn->prepare($reviewQuery);
                                        $stmt->bind_param("ii", $booking['id'], $userId);
                                        $stmt->execute();
                                        $reviewResult = $stmt->get_result();
                                        $hasReviewed = $reviewResult->num_rows > 0;
                                        ?>
                                        
                                        <?php if(!$hasReviewed): ?>
                                        <div class="booking-actions">
                                            <a href="add_review.php?booking_id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-accent">
                                                <i class="fas fa-star me-1"></i> Add Review
                                            </a>
                                        </div>
                                        <?php else: ?>
                                        <div class="booking-actions">
                                            <span class="badge bg-success"><i class="fas fa-check me-1"></i> Reviewed</span>
                                        </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-calendar-alt fa-4x text-muted mb-4"></i>
                            <h4>No bookings</h4>
                            <p class="text-muted">You haven't made any bookings yet. <a href="marketplace.php">Find a technician</a> to book a repair appointment.</p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Security Tab -->
                <div class="tab-pane fade" id="security-pane" role="tabpanel" aria-labelledby="security-tab" tabindex="0">
                    <h4 class="mb-4">Change Password</h4>
                    <form method="post" action="profile.php">
                        <input type="hidden" name="change_password" value="1">
                        
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="password" class="form-control" id="current_password" name="current_password" placeholder="Current Password" required>
                                <label for="current_password">Current Password</label>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="password" class="form-control" id="new_password" name="new_password" placeholder="New Password" required>
                                <label for="new_password">New Password</label>
                                <div class="form-text">Password must be at least 8 characters and include letters and numbers.</div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm Password" required>
                                <label for="confirm_password">Confirm New Password</label>
                            </div>
                        </div>
                        
                        <div class="text-end mt-4">
                            <button type="reset" class="btn btn-outline-secondary me-2">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-2"></i> Change Password
                            </button>
                        </div>
                    </form>
                    
                    <hr class="my-5">
                    
                    <h4 class="mb-4">Security Settings</h4>
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="two_factor" checked>
                        <label class="form-check-label" for="two_factor">Enable Two-Factor Authentication</label>
                        <div class="form-text">A verification code will be sent to your phone when logging in</div>
                    </div>
                    
                    <div class="mb-3 form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="login_notification" checked>
                        <label class="form-check-label" for="login_notification">Login Notifications</label>
                        <div class="form-text">Receive a notification when your account is accessed from a new device</div>
                    </div>
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
                    <p>Connecting you with skilled technicians for all your device repair needs. Fast, reliable, and affordable electronic repairs.</p>
                    <div class="social-links mt-4">
                        <a href="#" class="me-3 text-white"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="me-3 text-white"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="me-3 text-white"><i class="fab fa-instagram"></i></a>
                        <a href="#" class="text-white"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Navigation</h5>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="marketplace.php">Find Technicians</a></li>
                        <li><a href="signup.php?type=provider">Become a Provider</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 col-6 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Services</h5>
                    <ul class="footer-links">
                        <li><a href="marketplace.php?category=smartphone">Smartphone Repair</a></li>
                        <li><a href="marketplace.php?category=laptop">Laptop Repair</a></li>
                        <li><a href="marketplace.php?category=tablet">Tablet Repair</a></li>
                        <li><a href="marketplace.php?category=computer">Computer Repair</a></li>
                        <li><a href="marketplace.php?category=tv">TV/Monitor Repair</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-4 col-md-4 mt-4 mt-lg-0">
                    <h5 class="footer-heading">Contact Us</h5>
                    <ul class="footer-links">
                        <li><i class="fas fa-map-marker-alt me-2"></i>QM4V+Q4M، طريق الإمام سعود بن عبدالعزيز بن محمد, الفرعي،، الرياض 12464</li>
                        <li><i class="fas fa-phone-alt me-2"></i>+966 508030783</li>
                        <li><i class="fas fa-envelope me-2"></i>444146230@tvtc.edu.sa</li>
                    </ul>
                    
                    <div class="mt-4">
                        <h5 class="footer-heading">Newsletter</h5>
                        <form class="mt-3">
                            <div class="input-group">
                                <input type="email" class="form-control" placeholder="Your email">
                                <button class="btn btn-success" type="submit">Subscribe</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="row">
                    <div class="col-md-6">
                        <p>&copy; 2025 FixItNow. All rights reserved.</p>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <a href="privacy.php" class="text-white me-3">Privacy Policy</a>
                        <a href="terms.php" class="text-white">Terms of Service</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- jQuery for Select2 -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Select2 for multi-select -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
            
            // Initialize Select2
            $(document).ready(function() {
                $('#specialties').select2({
                    placeholder: "Select specialties",
                    allowClear: true,
                    width: '100%',
                    theme: 'bootstrap-5',
                    dropdownParent: $('#specialties').parent()
                });
            });
        });
        
        // Preview profile image before upload
        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                
                reader.onload = function(e) {
                    // Update the avatar preview
                    document.querySelector('.profile-avatar img').src = e.target.result;
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Trigger file input when clicking the edit avatar button
        document.querySelector('.edit-avatar-btn').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('profile_image_upload').click();
        });
    </script>
</body>
</html>