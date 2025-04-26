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

// Check if user ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$viewUserId = (int)$_GET['id'];

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

// Process actions
$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process user status changes
    if (isset($_POST['action']) && $_POST['action'] === 'updateUserStatus') {
        try {
            $newStatus = clean_input($_POST['status']); // 'active' or 'inactive'
            
            // Never allow deactivating your own account as admin
            if ($viewUserId == $userId && $newStatus === 'inactive') {
                $actionMessage = "You cannot deactivate your own admin account.";
                $actionType = "danger";
            } else {
                // Update user status
                $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $viewUserId]);
                
                $actionMessage = "User status has been updated successfully.";
                $actionType = "success";
            }
        } catch (PDOException $e) {
            $actionMessage = "Error updating user status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process user role changes
    if (isset($_POST['action']) && $_POST['action'] === 'updateUserRole') {
        try {
            $newRole = clean_input($_POST['role']); // 'admin', 'provider', or 'customer'
            
            // Never allow changing your own role as admin
            if ($viewUserId == $userId) {
                $actionMessage = "You cannot change your own admin role.";
                $actionType = "danger";
            } else {
                // Get current user role
                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$viewUserId]);
                $currentRole = $stmt->fetchColumn();
                
                // Begin transaction
                $pdo->beginTransaction();
                
                // Update user role
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$newRole, $viewUserId]);
                
                // If changing to provider from another role, create provider record
                if ($newRole === 'provider' && $currentRole !== 'provider') {
                    // Check if provider record already exists
                    $stmt = $pdo->prepare("SELECT id FROM providers WHERE user_id = ?");
                    $stmt->execute([$viewUserId]);
                    if (!$stmt->fetch()) {
                        // Insert new provider record
                        $stmt = $pdo->prepare("INSERT INTO providers (user_id, created_at) VALUES (?, NOW())");
                        $stmt->execute([$viewUserId]);
                    }
                }
                
                // Commit transaction
                $pdo->commit();
                
                $actionMessage = "User role has been updated to " . ucfirst($newRole) . " successfully.";
                $actionType = "success";
            }
        } catch (PDOException $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            $actionMessage = "Error updating user role: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process technician verification
    if (isset($_POST['action']) && $_POST['action'] === 'verifyTechnician') {
        try {
            $isVerified = (int)$_POST['is_verified']; // 1 or 0
            $providerId = (int)$_POST['provider_id']; 
            
            // Update provider verification status
            $stmt = $pdo->prepare("UPDATE providers SET is_verified = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$isVerified, $providerId, $viewUserId]);
            
            if ($isVerified) {
                $actionMessage = "Technician has been verified successfully.";
            } else {
                $actionMessage = "Technician verification has been removed.";
            }
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating verification status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
}

// Get user details
$viewUser = null;
$providerDetails = null;
$userSettings = null;
$userStats = [
    'bookings' => 0,
    'quotes' => 0,
    'reviews' => 0,
    'messages' => 0
];

try {
    // Get user data with status check
    $stmt = $pdo->prepare("
        SELECT * FROM users WHERE id = ?
    ");
    $stmt->execute([$viewUserId]);
    $viewUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$viewUser) {
        // User not found
        header('Location: users.php');
        exit;
    }
    
    // Get user profile image
    $viewUserProfileImage = getUserProfileImage($viewUserId);
    
    // If user is a provider, get provider details
    if ($viewUser['role'] === 'provider') {
        $stmt = $pdo->prepare("
            SELECT * FROM providers WHERE user_id = ?
        ");
        $stmt->execute([$viewUserId]);
        $providerDetails = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Get user settings if exists
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM user_settings WHERE user_id = ?
        ");
        $stmt->execute([$viewUserId]);
        $userSettings = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Table might not exist, continue without settings
        error_log("Error fetching user settings: " . $e->getMessage());
    }
    
    // Get user statistics
    // Bookings count
    if ($viewUser['role'] === 'customer') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings WHERE customer_id = ?
        ");
        $stmt->execute([$viewUserId]);
        $userStats['bookings'] = $stmt->fetchColumn();
        
        // Quotes count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM quote_requests WHERE customer_id = ?
        ");
        $stmt->execute([$viewUserId]);
        $userStats['quotes'] = $stmt->fetchColumn();
        
        // Reviews count (if customer)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reviews WHERE customer_id = ?
        ");
        $stmt->execute([$viewUserId]);
        $userStats['reviews'] = $stmt->fetchColumn();
    } elseif ($viewUser['role'] === 'provider' && isset($providerDetails['id'])) {
        // Provider bookings
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings WHERE provider_id = ?
        ");
        $stmt->execute([$providerDetails['id']]);
        $userStats['bookings'] = $stmt->fetchColumn();
        
        // Provider reviews count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM reviews WHERE provider_id = ?
        ");
        $stmt->execute([$providerDetails['id']]);
        $userStats['reviews'] = $stmt->fetchColumn();
    }
    
    // Messages count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages WHERE sender_id = ? OR receiver_id = ?
    ");
    $stmt->execute([$viewUserId, $viewUserId]);
    $userStats['messages'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database error fetching user details: " . $e->getMessage());
    $errorMessage = "Error loading user details. Please try again.";
} catch (Exception $e) {
    error_log("General error fetching user details: " . $e->getMessage());
    $errorMessage = "An unexpected error occurred. Please try again.";
}

// Get recent activities/logs
$recentBookings = [];
$recentQuotes = [];
$recentReviews = [];

try {
    // Get recent bookings
    if ($viewUser['role'] === 'customer') {
        $stmt = $pdo->prepare("
            SELECT b.*, p.id as provider_id, u.first_name, u.last_name, s.name as service_name
            FROM bookings b
            JOIN providers p ON b.provider_id = p.id
            JOIN users u ON p.user_id = u.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.customer_id = ?
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$viewUserId]);
        $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent quotes
        $stmt = $pdo->prepare("
            SELECT qr.*, COUNT(q.id) as quote_count
            FROM quote_requests qr
            LEFT JOIN quotes q ON qr.id = q.request_id
            WHERE qr.customer_id = ?
            GROUP BY qr.id
            ORDER BY qr.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$viewUserId]);
        $recentQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($viewUser['role'] === 'provider' && isset($providerDetails['id'])) {
        // Get provider bookings
        $stmt = $pdo->prepare("
            SELECT b.*, u.first_name, u.last_name, s.name as service_name
            FROM bookings b
            JOIN users u ON b.customer_id = u.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.provider_id = ?
            ORDER BY b.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$providerDetails['id']]);
        $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get recent reviews
    if ($viewUser['role'] === 'customer') {
        $stmt = $pdo->prepare("
            SELECT r.*, u.first_name, u.last_name
            FROM reviews r
            JOIN providers p ON r.provider_id = p.id
            JOIN users u ON p.user_id = u.id
            WHERE r.customer_id = ?
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$viewUserId]);
        $recentReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($viewUser['role'] === 'provider' && isset($providerDetails['id'])) {
        $stmt = $pdo->prepare("
            SELECT r.*, u.first_name, u.last_name
            FROM reviews r
            JOIN users u ON r.customer_id = u.id
            WHERE r.provider_id = ?
            ORDER BY r.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$providerDetails['id']]);
        $recentReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error fetching user activity: " . $e->getMessage());
} catch (Exception $e) {
    error_log("General error fetching user activity: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Details - FixItNow Admin</title>
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
        
        /* User profile */
        .profile-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-hover) 100%);
            padding: 2rem;
            color: white;
            text-align: center;
            position: relative;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 3px solid white;
            overflow: hidden;
            margin: 0 auto 1rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.2);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-role-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        
        .bg-admin {
            background-color: #dc3545;
            color: white;
        }
        
        .bg-technician {
            background-color: #007bff;
            color: white;
        }
        
        .bg-customer {
            background-color: #17a2b8;
            color: white;
        }
        
        .verification-badge {
            position: absolute;
            bottom: 1rem;
            right: 1rem;
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: rgba(255, 255, 255, 0.9);
        }
        
        .verification-badge.verified {
            color: #198754;
        }
        
        .verification-badge.unverified {
            color: #dc3545;
        }
        
        .profile-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .profile-item:last-child {
            border-bottom: none;
        }
        
        .profile-item-label {
            color: var(--text-muted);
            margin-bottom: 0.25rem;
            font-size: 0.875rem;
        }
        
        .profile-item-value {
            font-weight: 500;
        }
        
        /* Status Indicators */
        .status-active {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        .status-inactive {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
        }
        
        /* Stats Cards */
        .stat-card {
            border-radius: 1rem;
            border: none;
            background-color: var(--card-bg);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            height: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        .stat-content h4 {
            margin-bottom: 0.25rem;
            font-weight: 700;
        }
        
        .stat-content p {
            color: var(--text-muted);
            margin-bottom: 0;
        }
        
        .bg-purple-soft {
            background-color: rgba(166, 135, 255, 0.2);
            color: var(--primary-color);
        }
        
        .bg-green-soft {
            background-color: rgba(76, 217, 99, 0.2);
            color: var(--accent-color);
        }
        
        .bg-blue-soft {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .bg-orange-soft {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        /* Data Tables */
        .data-table {
            background-color: var(--card-bg);
            border-radius: 1rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: box-shadow 0.3s ease;
            margin-top: 1.5rem;
        }
        
        .data-table:hover {
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .data-table .table {
            margin-bottom: 0;
        }
        
        .data-table .table th {
            border-top: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.03em;
        }
        
        .data-table .table td {
            vertical-align: middle;
        }
        
        .data-table-header {
            background-color: rgba(0,0,0,0.05);
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        /* Status Badges */
        .badge.bg-warning-soft {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .badge.bg-success-soft {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .badge.bg-danger-soft {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        .badge.bg-info-soft {
            background-color: rgba(13, 202, 240, 0.2);
            color: #0dcaf0;
        }
        
        /* User profile */
        .user-profile-small {
            display: flex;
            align-items: center;
        }
        
        .user-avatar-small {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .user-avatar-small img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        /* Modal styles */
        .modal-content {
            background-color: var(--modal-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.5rem 1.5rem var(--shadow-color);
        }
        
        .modal-header {
            border-bottom-color: var(--border-color);
        }
        
        .modal-footer {
            border-top-color: var(--border-color);
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
        
        /* Rating stars */
        .rating-stars {
            color: #ffc107; /* Yellow star color stays constant */
        }
        
        /* Empty message */
        .empty-message {
            text-align: center;
            padding: 2rem 1rem;
            color: var(--text-muted);
        }
        
        .empty-message i {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.6;
        }
        
        .empty-message p {
            margin-bottom: 0;
        }
        
        /* Settings toggle */
        .settings-toggle {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .settings-toggle .form-check {
            min-height: auto;
            margin-bottom: 0;
        }
        
        .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
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
            <?php if (isset($errorMessage)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $errorMessage; ?>
            </div>
            <?php elseif (!empty($actionMessage)): ?>
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
                    <li class="breadcrumb-item active" aria-current="page">User Details</li>
                </ol>
            </nav>
            
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="page-title">User Profile</h1>
                <div>
                    <a href="edit-user.php?id=<?php echo $viewUserId; ?>" class="btn btn-primary me-2">
                        <i class="fas fa-edit me-2"></i> Edit User
                    </a>
                    <a href="users.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i> Back to Users
                    </a>
                </div>
            </div>
            
            <div class="row g-4">
                <!-- Profile Card -->
                <div class="col-lg-4">
                    <div class="profile-card mb-4">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <img src="<?php echo htmlspecialchars($viewUserProfileImage); ?>" alt="User Profile">
                            </div>
                            <h3 class="mb-1"><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></h3>
                            <p class="mb-0">@<?php echo htmlspecialchars($viewUser['username']); ?></p>
                            
                            <?php 
                            $roleBadgeClass = '';
                            switch ($viewUser['role']) {
                                case 'admin':
                                    $roleBadgeClass = 'bg-admin';
                                    break;
                                case 'provider':
                                    $roleBadgeClass = 'bg-technician';
                                    break;
                                default:
                                    $roleBadgeClass = 'bg-customer';
                            }
                            ?>
                            <div class="profile-role-badge <?php echo $roleBadgeClass; ?>">
                                <?php echo ucfirst($viewUser['role']); ?>
                            </div>
                            
                            <?php if ($viewUser['role'] === 'provider' && isset($providerDetails['is_verified'])): ?>
                                <?php if ($providerDetails['is_verified']): ?>
                                <div class="verification-badge verified">
                                    <i class="fas fa-check-circle me-1"></i> Verified Technician
                                </div>
                                <?php else: ?>
                                <div class="verification-badge unverified">
                                    <i class="fas fa-exclamation-circle me-1"></i> Unverified Technician
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="profile-content">
                            <div class="profile-item">
                                <div class="profile-item-label">Account Status</div>
                                <div class="profile-item-value">
                                    <?php if ($viewUser['status'] === 'inactive'): ?>
                                        <span class="status-inactive">
                                            <i class="fas fa-times-circle me-1"></i> Inactive
                                        </span>
                                    <?php else: ?>
                                        <span class="status-active">
                                            <i class="fas fa-check-circle me-1"></i> Active
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Contact Information</div>
                                <div class="profile-item-value">
                                    <div><i class="fas fa-envelope me-2"></i> <?php echo htmlspecialchars($viewUser['email']); ?></div>
                                    <div><i class="fas fa-phone me-2"></i> <?php echo htmlspecialchars($viewUser['phone']); ?></div>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Member Since</div>
                                <div class="profile-item-value">
                                    <i class="fas fa-calendar-alt me-2"></i> <?php echo date('F j, Y', strtotime($viewUser['created_at'])); ?>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Last Updated</div>
                                <div class="profile-item-value">
                                    <i class="fas fa-clock me-2"></i> <?php echo date('F j, Y g:i A', strtotime($viewUser['updated_at'])); ?>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">User ID</div>
                                <div class="profile-item-value">
                                    <code>#<?php echo $viewUser['id']; ?></code>
                                </div>
                            </div>
                            
                            <?php if ($viewUser['role'] === 'provider' && isset($providerDetails)): ?>
                            <div class="profile-item">
                                <div class="profile-item-label">Provider ID</div>
                                <div class="profile-item-value">
                                    <code>#<?php echo $providerDetails['id']; ?></code>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Specialties</div>
                                <div class="profile-item-value">
                                    <?php 
                                    if (isset($providerDetails['specialties']) && !empty($providerDetails['specialties'])) {
                                        $specialties = explode(',', $providerDetails['specialties']);
                                        foreach ($specialties as $specialty) {
                                            $specialty = trim($specialty);
                                            if (!empty($specialty)) {
                                                echo '<span class="badge bg-info-soft me-1 mb-1">' . ucfirst($specialty) . '</span>';
                                            }
                                        }
                                    } else {
                                        echo '<span class="text-muted">No specialties specified</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Experience</div>
                                <div class="profile-item-value">
                                    <?php 
                                    if (isset($providerDetails['experience']) && !empty($providerDetails['experience'])) {
                                        echo htmlspecialchars($providerDetails['experience']);
                                    } else {
                                        echo '<span class="text-muted">Not specified</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <div class="profile-item">
                                <div class="profile-item-label">Hourly Rate</div>
                                <div class="profile-item-value">
                                    <?php 
                                    if (isset($providerDetails['hourly_rate']) && $providerDetails['hourly_rate'] > 0) {
                                        echo '$' . number_format($providerDetails['hourly_rate'], 2) . '/hour';
                                    } else {
                                        echo '<span class="text-muted">Not specified</span>';
                                    }
                                    ?>
                                </div>
                            </div>
                            <?php if (isset($providerDetails['bio']) && !empty($providerDetails['bio'])): ?>
                            <div class="profile-item">
                                <div class="profile-item-label">Bio</div>
                                <div class="profile-item-value">
                                    <?php echo nl2br(htmlspecialchars($providerDetails['bio'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Admin Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i> Admin Actions</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="edit-user.php?id=<?php echo $viewUserId; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-edit me-2"></i> Edit User Profile
                                </a>
                                
                                <!-- Change Status -->
                                <button class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#changeStatusModal">
                                    <i class="fas fa-toggle-on me-2"></i> Change Account Status
                                </button>
                                
                                <!-- Change Role -->
                                <button class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#changeRoleModal">
                                    <i class="fas fa-user-tag me-2"></i> Change User Role
                                </button>
                                
                                <?php if ($viewUser['role'] === 'provider' && isset($providerDetails)): ?>
                                <!-- Technician Actions -->
                                <button class="btn btn-outline-<?php echo $providerDetails['is_verified'] ? 'danger' : 'success'; ?>" data-bs-toggle="modal" data-bs-target="#verifyTechnicianModal">
                                    <i class="fas fa-<?php echo $providerDetails['is_verified'] ? 'times' : 'check'; ?>-circle me-2"></i>
                                    <?php echo $providerDetails['is_verified'] ? 'Remove Verification' : 'Verify Technician'; ?>
                                </button>
                                
                                <a href="technician-details.php?id=<?php echo $providerDetails['id']; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-id-badge me-2"></i> View Technician Profile
                                </a>
                                <?php endif; ?>
                                
                                <a href="reset-password.php?id=<?php echo $viewUserId; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-key me-2"></i> Reset Password
                                </a>
                                
                                <a href="login-history.php?id=<?php echo $viewUserId; ?>" class="btn btn-outline-secondary">
                                    <i class="fas fa-history me-2"></i> Login History
                                </a>
                                
                                <!-- Delete User -->
                                <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteUserModal">
                                    <i class="fas fa-trash-alt me-2"></i> Delete User
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Settings Card (if user_settings table exists) -->
                    <?php if ($userSettings): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-cog me-2"></i> User Settings</h5>
                        </div>
                        <div class="card-body">
                            <div class="settings-toggle mb-3">
                                <div>Email Notifications</div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="email_notifications" <?php echo $userSettings['email_notifications'] ? 'checked' : ''; ?> disabled>
                                </div>
                            </div>
                            <div class="settings-toggle mb-3">
                                <div>SMS Notifications</div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="sms_notifications" <?php echo $userSettings['sms_notifications'] ? 'checked' : ''; ?> disabled>
                                </div>
                            </div>
                            <div class="settings-toggle mb-3">
                                <div>Push Notifications</div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="push_notifications" <?php echo $userSettings['push_notifications'] ? 'checked' : ''; ?> disabled>
                                </div>
                            </div>
                            <div class="settings-toggle mb-3">
                                <div>Marketing Emails</div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="marketing_emails" <?php echo $userSettings['marketing_emails'] ? 'checked' : ''; ?> disabled>
                                </div>
                            </div>
                            <div class="settings-toggle mb-3">
                                <div>Dark Mode</div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="dark_mode" <?php echo $userSettings['dark_mode'] ? 'checked' : ''; ?> disabled>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <div class="mb-2">
                                    <small class="text-muted">Preferred Language</small>
                                    <div><?php echo strtoupper($userSettings['language']); ?></div>
                                </div>
                                <div class="mb-2">
                                    <small class="text-muted">Timezone</small>
                                    <div><?php echo $userSettings['timezone']; ?></div>
                                </div>
                                <div>
                                    <small class="text-muted">Currency</small>
                                    <div><?php echo strtoupper($userSettings['currency']); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Activity & Stats Column -->
                <div class="col-lg-8">
                    <!-- User Stats -->
                    <div class="row g-4 mb-4">
                        <div class="col-md-6 col-xl-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center p-3">
                                    <div class="stat-icon bg-purple-soft me-3">
                                        <i class="fas fa-calendar-check"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h4><?php echo number_format($userStats['bookings']); ?></h4>
                                        <p>Bookings</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 col-xl-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center p-3">
                                    <div class="stat-icon bg-green-soft me-3">
                                        <i class="fas fa-clipboard-list"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h4><?php echo number_format($userStats['quotes']); ?></h4>
                                        <p>Quote Requests</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 col-xl-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center p-3">
                                    <div class="stat-icon bg-blue-soft me-3">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h4><?php echo number_format($userStats['reviews']); ?></h4>
                                        <p>Reviews</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6 col-xl-3">
                            <div class="card stat-card">
                                <div class="card-body d-flex align-items-center p-3">
                                    <div class="stat-icon bg-orange-soft me-3">
                                        <i class="fas fa-comments"></i>
                                    </div>
                                    <div class="stat-content">
                                        <h4><?php echo number_format($userStats['messages']); ?></h4>
                                        <p>Messages</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Bookings -->
                    <div class="data-table">
                        <div class="data-table-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Bookings</h5>
                            <?php if ($viewUser['role'] === 'customer'): ?>
                            <a href="customer-bookings.php?id=<?php echo $viewUserId; ?>" class="btn btn-sm btn-outline-primary">View All</a>
                            <?php elseif ($viewUser['role'] === 'provider' && isset($providerDetails)): ?>
                            <a href="provider-bookings.php?id=<?php echo $providerDetails['id']; ?>" class="btn btn-sm btn-outline-primary">View All</a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (empty($recentBookings)): ?>
                        <div class="empty-message">
                            <i class="fas fa-calendar-times"></i>
                            <p>No bookings found for this user.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <?php if ($viewUser['role'] === 'customer'): ?>
                                        <th>Technician</th>
                                        <?php else: ?>
                                        <th>Customer</th>
                                        <?php endif; ?>
                                        <th>Service</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentBookings as $booking): ?>
                                    <tr>
                                        <td>#<?php echo $booking['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?>
                                        </td>
                                        <td>
                                            <?php echo isset($booking['service_name']) ? htmlspecialchars($booking['service_name']) : 'N/A'; ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?>
                                            <small class="d-block text-muted">
                                                <?php echo date('g:i A', strtotime($booking['booking_time'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = '';
                                            switch ($booking['status']) {
                                                case 'pending':
                                                    $statusClass = 'bg-warning';
                                                    break;
                                                case 'confirmed':
                                                    $statusClass = 'bg-info';
                                                    break;
                                                case 'completed':
                                                    $statusClass = 'bg-success';
                                                    break;
                                                case 'cancelled':
                                                    $statusClass = 'bg-danger';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            $<?php echo number_format($booking['total_price'], 2); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Quote Requests (for customers) -->
                    <?php if ($viewUser['role'] === 'customer'): ?>
                    <div class="data-table">
                        <div class="data-table-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Quote Requests</h5>
                            <a href="customer-quotes.php?id=<?php echo $viewUserId; ?>" class="btn btn-sm btn-outline-primary">View All</a>
                        </div>
                        
                        <?php if (empty($recentQuotes)): ?>
                        <div class="empty-message">
                            <i class="fas fa-clipboard"></i>
                            <p>No quote requests found for this user.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Device</th>
                                        <th>Issue</th>
                                        <th>Status</th>
                                        <th>Quotes</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentQuotes as $quote): ?>
                                    <tr>
                                        <td>#<?php echo $quote['id']; ?></td>
                                        <td>
                                            <span class="badge bg-info-soft">
                                                <?php echo ucfirst($quote['device_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo mb_strimwidth(htmlspecialchars($quote['issue_description']), 0, 50, '...'); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $statusClass = '';
                                            switch ($quote['status']) {
                                                case 'pending':
                                                    $statusClass = 'bg-warning';
                                                    break;
                                                case 'quoted':
                                                    $statusClass = 'bg-info';
                                                    break;
                                                case 'accepted':
                                                    $statusClass = 'bg-success';
                                                    break;
                                                case 'completed':
                                                    $statusClass = 'bg-success';
                                                    break;
                                                case 'cancelled':
                                                    $statusClass = 'bg-danger';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst($quote['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo $quote['quote_count']; ?> quote(s)
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($quote['created_at'])); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Recent Reviews -->
                    <div class="data-table">
                        <div class="data-table-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Recent Reviews</h5>
                            <?php if ($viewUser['role'] === 'customer'): ?>
                            <a href="customer-reviews.php?id=<?php echo $viewUserId; ?>" class="btn btn-sm btn-outline-primary">View All</a>
                            <?php elseif ($viewUser['role'] === 'provider' && isset($providerDetails)): ?>
                            <a href="provider-reviews.php?id=<?php echo $providerDetails['id']; ?>" class="btn btn-sm btn-outline-primary">View All</a>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (empty($recentReviews)): ?>
                        <div class="empty-message">
                            <i class="fas fa-star"></i>
                            <p>No reviews found for this user.</p>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <?php if ($viewUser['role'] === 'customer'): ?>
                                        <th>Technician</th>
                                        <?php else: ?>
                                        <th>Customer</th>
                                        <?php endif; ?>
                                        <th>Rating</th>
                                        <th>Comment</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentReviews as $review): ?>
                                    <tr>
                                        <td>#<?php echo $review['id']; ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?>
                                        </td>
                                        <td>
                                            <div class="rating-stars">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <?php if ($i <= floor($review['rating'])): ?>
                                                    <i class="fas fa-star"></i>
                                                    <?php elseif ($i - $review['rating'] < 1): ?>
                                                    <i class="fas fa-star-half-alt"></i>
                                                    <?php else: ?>
                                                    <i class="far fa-star"></i>
                                                    <?php endif; ?>
                                                <?php endfor; ?>
                                                <span class="ms-2"><?php echo $review['rating']; ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo mb_strimwidth(htmlspecialchars($review['comment']), 0, 50, '...'); ?>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Change Status Modal -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1" aria-labelledby="changeStatusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changeStatusModalLabel">Change User Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="user-details.php?id=<?php echo $viewUserId; ?>">
                    <div class="modal-body">
                        <p>Change status for user <strong><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></strong>:</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Status</label>
                            <select name="status" class="form-select" required>
                                <option value="active" <?php echo $viewUser['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $viewUser['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> Inactive users cannot log in or use the platform.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="updateUserStatus">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Change Role Modal -->
    <div class="modal fade" id="changeRoleModal" tabindex="-1" aria-labelledby="changeRoleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="changeRoleModalLabel">Change User Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="user-details.php?id=<?php echo $viewUserId; ?>">
                    <div class="modal-body">
                        <p>Change role for user <strong><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></strong>:</p>
                        
                        <div class="mb-3">
                            <label class="form-label">Select Role</label>
                            <select name="role" class="form-select" required>
                                <option value="customer" <?php echo $viewUser['role'] === 'customer' ? 'selected' : ''; ?>>Customer</option>
                                <option value="provider" <?php echo $viewUser['role'] === 'provider' ? 'selected' : ''; ?>>Technician</option>
                                <option value="admin" <?php echo $viewUser['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> Changing a user's role may affect their account functionality.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="updateUserRole">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Verify Technician Modal -->
    <?php if ($viewUser['role'] === 'provider' && isset($providerDetails)): ?>
    <div class="modal fade" id="verifyTechnicianModal" tabindex="-1" aria-labelledby="verifyTechnicianModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="verifyTechnicianModalLabel">
                        <?php echo $providerDetails['is_verified'] ? 'Remove Verification' : 'Verify Technician'; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="user-details.php?id=<?php echo $viewUserId; ?>">
                    <div class="modal-body">
                        <?php if ($providerDetails['is_verified']): ?>
                        <p>Are you sure you want to remove verification status from <strong><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></strong>?</p>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i> Removing verification will affect their visibility and trustworthiness on the platform.
                        </div>
                        <?php else: ?>
                        <p>Are you sure you want to verify <strong><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></strong> as a trusted technician?</p>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> Verified technicians appear more prominently in search results and gain user trust.
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="verifyTechnician">
                        <input type="hidden" name="provider_id" value="<?php echo $providerDetails['id']; ?>">
                        <input type="hidden" name="is_verified" value="<?php echo $providerDetails['is_verified'] ? '0' : '1'; ?>">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-<?php echo $providerDetails['is_verified'] ? 'danger' : 'success'; ?>">
                            <?php echo $providerDetails['is_verified'] ? 'Remove Verification' : 'Verify Technician'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Delete User Modal -->
    <div class="modal fade" id="deleteUserModal" tabindex="-1" aria-labelledby="deleteUserModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteUserModalLabel">Confirm User Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to permanently delete the user <strong><?php echo htmlspecialchars($viewUser['first_name'] . ' ' . $viewUser['last_name']); ?></strong>?</p>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i> This action cannot be undone. All user data, including bookings, quotes, reviews, and messages will be permanently deleted.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="users.php">
                        <input type="hidden" name="action" value="deleteUser">
                        <input type="hidden" name="user_id" value="<?php echo $viewUserId; ?>">
                        <button type="submit" class="btn btn-danger">Delete User</button>
                    </form>
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
        });
    </script>
</body>
</html>