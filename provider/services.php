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

// Initialize variables with default values
$providerProfileImage = '../default.png';
$providerId = 0;
$providerData = [];
$services = [];
$specialties = [];
$message = '';
$messageType = '';

// Get provider profile information
try {
    // Query to get provider user data with proper join
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider' AND u.status = 'active'");
    $stmt->execute([$userId]);
    $providerDataResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($providerDataResult) {
        $providerData = $providerDataResult;
        $providerId = (int)$providerData['provider_id'];
        $specialties = !empty($providerData['specialties']) ? explode(',', $providerData['specialties']) : [];
        
        // Set profile image path with enhanced security checks
        if (!empty($providerData['profile_image'])) {
            if (preg_match('/^https?:\/\//i', $providerData['profile_image'])) {
                // External URL - validate if needed
                $providerProfileImage = filter_var($providerData['profile_image'], FILTER_SANITIZE_URL);
            } else {
                // Local file - validate path and existence
                $imagePath = '../profile_images/' . basename($providerData['profile_image']);
                if (file_exists($imagePath) && is_readable($imagePath)) {
                    $providerProfileImage = $imagePath;
                }
            }
        }
    } else {
        // Redirect to error page if user is not a provider
        error_log("Provider data not found for user ID: $userId");
        header('Location: ../error.php?message=Provider%20data%20not%20found');
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Only use categories from provider's specialties
$serviceCategories = $specialties;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        // Add new service
        if ($_POST['action'] === 'add_service') {
            try {
                // Validate input
                $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_SPECIAL_CHARS);
                $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
                $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS);
                $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
                $duration = filter_input(INPUT_POST, 'duration', FILTER_VALIDATE_INT);
                
                // Additional validation - check if category is in provider's specialties
                if (!in_array($category, $serviceCategories)) {
                    throw new Exception("You can only add services for your specialized categories");
                }
                
                if (empty($name) || empty($description) || $price <= 0 || $duration <= 0) {
                    throw new Exception("Please fill all fields with valid values");
                }
                
                // Insert into database
                $stmt = $pdo->prepare("INSERT INTO services 
                    (provider_id, category, name, description, price, duration) 
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$providerId, $category, $name, $description, $price, $duration]);
                
                $message = "Service added successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = "danger";
            }
        }
        // Edit service
        else if ($_POST['action'] === 'edit_service' && isset($_POST['service_id'])) {
            try {
                $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
                $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_SPECIAL_CHARS);
                $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS);
                $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS);
                $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
                $duration = filter_input(INPUT_POST, 'duration', FILTER_VALIDATE_INT);
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                // Additional validation - check if category is in provider's specialties
                if (!in_array($category, $serviceCategories)) {
                    throw new Exception("You can only update services for your specialized categories");
                }
                
                if (empty($name) || empty($description) || $price <= 0 || $duration <= 0) {
                    throw new Exception("Please fill all fields with valid values");
                }
                
                // Check if the service belongs to this provider
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE id = ? AND provider_id = ?");
                $checkStmt->execute([$serviceId, $providerId]);
                if ((int)$checkStmt->fetchColumn() === 0) {
                    throw new Exception("You can only edit your own services");
                }
                
                // Update service
                $stmt = $pdo->prepare("UPDATE services 
                    SET category = ?, name = ?, description = ?, price = ?, duration = ?, is_active = ? 
                    WHERE id = ? AND provider_id = ?");
                $stmt->execute([$category, $name, $description, $price, $duration, $isActive, $serviceId, $providerId]);
                
                $message = "Service updated successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = "danger";
            }
        }
        // Toggle service status
        else if ($_POST['action'] === 'toggle_service' && isset($_POST['service_id'])) {
            try {
                $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
                $isActive = isset($_POST['is_active']) && $_POST['is_active'] === 'true' ? 1 : 0; // Use the exact status from JS
                
                // Check if the service belongs to this provider
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE id = ? AND provider_id = ?");
                $checkStmt->execute([$serviceId, $providerId]);
                if ((int)$checkStmt->fetchColumn() === 0) {
                    throw new Exception("You can only toggle your own services");
                }
                
                // Update service status
                $stmt = $pdo->prepare("UPDATE services SET is_active = ? WHERE id = ? AND provider_id = ?");
                $stmt->execute([$isActive, $serviceId, $providerId]);
                
                $message = $isActive ? "Service activated successfully!" : "Service deactivated successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = "danger";
            }
        }
        // Delete service (soft delete by setting deleted_by_provider to 1)
        else if ($_POST['action'] === 'delete_service' && isset($_POST['service_id'])) {
            try {
                $serviceId = filter_input(INPUT_POST, 'service_id', FILTER_VALIDATE_INT);
                
                // Check if the service belongs to this provider
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM services WHERE id = ? AND provider_id = ?");
                $checkStmt->execute([$serviceId, $providerId]);
                if ((int)$checkStmt->fetchColumn() === 0) {
                    throw new Exception("You can only delete your own services");
                }
                
                // Check if service has any bookings before deletion
                $checkBookingStmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE service_id = ?");
                $checkBookingStmt->execute([$serviceId]);
                $hasBookings = (int)$checkBookingStmt->fetchColumn() > 0;
                
                // Soft delete by updating flag (keep record for existing bookings)
                $stmt = $pdo->prepare("UPDATE services SET deleted_by_provider = 1 WHERE id = ? AND provider_id = ?");
                $stmt->execute([$serviceId, $providerId]);
                
                // Log the deletion
                $logStmt = $pdo->prepare("INSERT INTO service_deletion_logs 
                    (service_id, provider_id, had_bookings) VALUES (?, ?, ?)");
                $logStmt->execute([$serviceId, $providerId, $hasBookings ? 1 : 0]);
                
                $message = "Service removed successfully!";
                $messageType = "success";
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = "danger";
            }
        }
    }
}

// Get provider's services with booking counts
try {
    if ($providerId) {
        $stmt = $pdo->prepare("SELECT s.*, 
                                (SELECT COUNT(*) FROM bookings WHERE service_id = s.id) as booking_count 
                              FROM services s
                              WHERE s.provider_id = ? AND s.deleted_by_provider = 0 
                              ORDER BY s.category, s.name");
        $stmt->execute([$providerId]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error fetching services: " . $e->getMessage());
    // Default values already set
}

// Get unread messages count
try {
    $unreadMessagesQuery = "
        SELECT COUNT(*) as count
        FROM messages
        WHERE receiver_id = ? AND is_read = 0
    ";
    
    $unreadMessagesStmt = $pdo->prepare($unreadMessagesQuery);
    $unreadMessagesStmt->execute([$userId]);
    $unreadMessagesCount = (int)$unreadMessagesStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Database error fetching unread messages count: " . $e->getMessage());
    $unreadMessagesCount = 0;
}

// Count services by category
$servicesByCategory = [];
foreach ($serviceCategories as $category) {
    $servicesByCategory[$category] = 0;
}

// Count active services by category
$activeServicesByCategory = [];
foreach ($serviceCategories as $category) {
    $activeServicesByCategory[$category] = 0;
}

foreach ($services as $service) {
    $category = $service['category'];
    if (isset($servicesByCategory[$category])) {
        $servicesByCategory[$category]++;
        if ($service['is_active']) {
            $activeServicesByCategory[$category]++;
        }
    }
}

// Prepare data for category chart
$categoryChartData = [];
foreach ($servicesByCategory as $category => $count) {
    if ($count > 0) {
        $categoryChartData[] = [
            'category' => ucfirst($category),
            'count' => $count
        ];
    }
}
$categoryChartJson = json_encode($categoryChartData, JSON_NUMERIC_CHECK);

// Get total bookings and earnings from services
try {
    $statsStmt = $pdo->prepare("
        SELECT COUNT(*) as total_bookings, COALESCE(SUM(total_price), 0) as total_earnings
        FROM bookings b
        JOIN services s ON b.service_id = s.id
        WHERE s.provider_id = ? AND b.status != 'cancelled'
    ");
    $statsStmt->execute([$providerId]);
    $serviceStats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $totalBookings = (int)$serviceStats['total_bookings'];
    $totalEarnings = (float)$serviceStats['total_earnings'];
} catch (PDOException $e) {
    error_log("Database error fetching service stats: " . $e->getMessage());
    $totalBookings = 0;
    $totalEarnings = 0;
}

// Get top performing services
try {
    $topServicesStmt = $pdo->prepare("
        SELECT s.id, s.name, s.category, COUNT(b.id) as booking_count, SUM(b.total_price) as earnings
        FROM services s
        LEFT JOIN bookings b ON s.id = b.service_id
        WHERE s.provider_id = ? AND s.deleted_by_provider = 0
        GROUP BY s.id
        ORDER BY booking_count DESC, earnings DESC
        LIMIT 3
    ");
    $topServicesStmt->execute([$providerId]);
    $topServices = $topServicesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching top services: " . $e->getMessage());
    $topServices = [];
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Services - FixItNow</title>
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
            --primary-color-rgba: rgba(121, 82, 179, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(55, 178, 77, 0.05);   /* Green with alpha */
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
            --primary-color-rgba: rgba(166, 135, 255, 0.05); /* Purple with alpha */
            --accent-color-rgba: rgba(76, 217, 99, 0.05);    /* Green with alpha */
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
        
        /* Service Card */
        .service-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-left: 4px solid var(--primary-color);
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .service-card.inactive {
            opacity: 0.7;
            border-left: 4px solid var(--text-muted);
        }
        
        .service-category {
            font-size: 0.85rem;
            padding: 0.35rem 0.65rem;
            border-radius: 50rem;
            display: inline-block;
            margin-bottom: 0.5rem;
        }
        
        .service-category.smartphone {
            background-color: rgba(13, 110, 253, 0.1);
            color: #0d6efd;
        }
        
        .service-category.laptop {
            background-color: rgba(111, 66, 193, 0.1);
            color: #6f42c1;
        }
        
        .service-category.tablet {
            background-color: rgba(253, 126, 20, 0.1);
            color: #fd7e14;
        }
        
        .service-category.desktop {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .service-category.gaming {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .service-category.tv {
            background-color: rgba(13, 202, 240, 0.1);
            color: #0dcaf0;
        }
        
        .service-price {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--accent-color);
        }
        
        .service-duration {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .service-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .service-booking-count {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Category Stats */
        .category-stats {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 2rem;
        }
        
        .category-stat {
            flex: 1;
            min-width: 120px;
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1rem;
            text-align: center;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease;
        }
        
        .category-stat:hover {
            transform: translateY(-5px);
        }
        
        .category-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .category-count {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .category-name {
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        /* Active/Inactive badge */
        .status-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .status-badge.active {
            background-color: rgba(25, 135, 84, 0.1);
            color: #198754;
        }
        
        .status-badge.inactive {
            background-color: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }
        
        /* Chart container */
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
            margin-bottom: 1.5rem;
        }
        
        /* Summary Stats */
        .summary-stat {
            border-left: 3px solid var(--primary-color);
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
            background-color: var(--primary-color-rgba);
            border-radius: 0.5rem;
        }
        
        .summary-stat h4 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .summary-stat.earnings {
            border-color: var(--accent-color);
            background-color: var(--accent-color-rgba);
        }
        
        .summary-stat.earnings h4 {
            color: var(--accent-color);
        }
        
        /* Search Filter */
        .search-filter {
            margin-bottom: 1.5rem;
        }
        
        /* Top Services */
        .top-service {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background-color: var(--primary-color-rgba);
            margin-bottom: 0.75rem;
            transition: transform 0.3s ease;
        }
        
        .top-service:hover {
            transform: translateX(5px);
        }
        
        .top-service-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
        }
        
        .top-service-content {
            flex: 1;
        }
        
        .top-service-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .top-service-stats {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .top-service-rank {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--primary-color);
            padding: 0 0.5rem;
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
        
        /* Empty state */
        .empty-state {
            padding: 3rem;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        /* Improved mobile responsiveness */
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .category-stats {
                justify-content: center;
            }
            
            .category-stat {
                min-width: 100px;
            }
        }
        
        /* Switch styles */
        .form-switch .form-check-input {
            width: 3em;
            height: 1.5em;
        }
        
        .form-switch .form-check-input:checked {
            background-color: var(--accent-color);
            border-color: var(--accent-color);
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading services...</p>
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
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                            <?php if($unreadMessagesCount > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-2"><?php echo $unreadMessagesCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="services.php">
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
            <!-- Page Header -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="page-title">Manage Services</h2>
                    <p class="text-muted">
                        Create and manage repair services for your specialized devices.
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <?php if (count($specialties) > 0): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="fas fa-plus me-2"></i>Add New Service
                    </button>
                    <?php else: ?>
                    <a href="profile.php" class="btn btn-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>Add Specialties First
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Alert Message -->
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (count($specialties) === 0): ?>
            <!-- No Specialties Warning -->
            <div class="alert alert-warning" role="alert">
                <h4 class="alert-heading"><i class="fas fa-exclamation-triangle me-2"></i>No Specialties Added</h4>
                <p>You need to add device specialties to your profile before you can create services.</p>
                <hr>
                <p class="mb-0">Please <a href="profile.php" class="alert-link">update your profile</a> to add your areas of expertise.</p>
            </div>
            <?php else: ?>
                
            <!-- Services Summary -->
            <div class="row g-4 mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Services Overview</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-sm-6">
                                    <div class="summary-stat">
                                        <h4><?php echo count($services); ?></h4>
                                        <div class="text-muted">Total Services</div>
                                    </div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="summary-stat earnings">
                                        <h4>
                                            <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                            <?php echo number_format($totalEarnings, 0); ?>
                                        </h4>
                                        <div class="text-muted">Total Earnings</div>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if (!empty($topServices)): ?>
                            <h6 class="mt-4 mb-3">Top Performing Services</h6>
                            <div class="top-services">
                                <?php foreach($topServices as $index => $service): ?>
                                <div class="top-service">
                                    <div class="top-service-icon">
                                        <?php 
                                        $icon = 'fas fa-cog';
                                        switch ($service['category']) {
                                            case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                            case 'laptop': $icon = 'fas fa-laptop'; break;
                                            case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                            case 'desktop': $icon = 'fas fa-desktop'; break;
                                            case 'gaming': $icon = 'fas fa-gamepad'; break;
                                            case 'tv': $icon = 'fas fa-tv'; break;
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?>"></i>
                                    </div>
                                    <div class="top-service-content">
                                        <div class="top-service-title"><?php echo htmlspecialchars($service['name']); ?></div>
                                        <div class="top-service-stats">
                                            <span class="me-2"><i class="fas fa-calendar-check me-1"></i><?php echo $service['booking_count']; ?> bookings</span>
                                            <?php if($service['earnings']): ?>
                                            <span><i class="fas fa-money-bill-wave me-1"></i><?php echo number_format($service['earnings'], 0); ?> SAR</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="top-service-rank">#<?php echo $index + 1; ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Services by Category</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($categoryChartData) > 0): ?>
                            <div class="chart-container">
                                <canvas id="categoryChart"></canvas>
                            </div>
                            <?php endif; ?>
                            
                            <div class="category-stats mt-3">
                                <?php foreach ($serviceCategories as $category): ?>
                                <div class="category-stat">
                                    <div class="category-icon">
                                        <?php 
                                        $icon = 'fas fa-cog';
                                        switch ($category) {
                                            case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                            case 'laptop': $icon = 'fas fa-laptop'; break;
                                            case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                            case 'desktop': $icon = 'fas fa-desktop'; break;
                                            case 'gaming': $icon = 'fas fa-gamepad'; break;
                                            case 'tv': $icon = 'fas fa-tv'; break;
                                        }
                                        ?>
                                        <i class="<?php echo $icon; ?> <?php echo $servicesByCategory[$category] > 0 ? 'text-primary' : 'text-muted'; ?>"></i>
                                    </div>
                                    <div class="category-count"><?php echo $servicesByCategory[$category]; ?></div>
                                    <div class="category-name"><?php echo ucfirst($category); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <?php if (count($services) > 0): ?>
            <!-- Search and filter tools -->
            <div class="search-filter">
                <div class="card mb-3">
                    <div class="card-body p-3">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="serviceSearch" placeholder="Search services...">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" id="categoryFilter">
                                    <option value="all">All Categories</option>
                                    <?php foreach ($serviceCategories as $category): ?>
                                    <option value="<?php echo $category; ?>"><?php echo ucfirst($category); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="statusFilter">
                                    <option value="all">All Status</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Service Listings -->
            <div class="row g-4" id="servicesContainer">
                <?php if (empty($services)): ?>
                <div class="col-12">
                    <div class="card">
                        <div class="card-body empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-cogs"></i>
                            </div>
                            <h4>No Services Added Yet</h4>
                            <p class="text-muted mb-4">You haven't added any repair services to your profile.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                                <i class="fas fa-plus me-2"></i>Add Your First Service
                            </button>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach ($services as $service): ?>
                    <div class="col-lg-6 col-xl-4 service-item" 
                         data-category="<?php echo htmlspecialchars($service['category']); ?>"
                         data-status="<?php echo $service['is_active'] ? 'active' : 'inactive'; ?>">
                        <div class="card service-card h-100 <?php echo $service['is_active'] ? '' : 'inactive'; ?>">
                            <div class="card-body">
                                <span class="status-badge <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                                
                                <div class="service-category <?php echo htmlspecialchars($service['category']); ?>">
                                    <?php 
                                    $icon = 'fas fa-cog';
                                    switch ($service['category']) {
                                        case 'smartphone': $icon = 'fas fa-mobile-alt'; break;
                                        case 'laptop': $icon = 'fas fa-laptop'; break;
                                        case 'tablet': $icon = 'fas fa-tablet-alt'; break;
                                        case 'desktop': $icon = 'fas fa-desktop'; break;
                                        case 'gaming': $icon = 'fas fa-gamepad'; break;
                                        case 'tv': $icon = 'fas fa-tv'; break;
                                    }
                                    ?>
                                    <i class="<?php echo $icon; ?> me-1"></i>
                                    <?php echo ucfirst(htmlspecialchars($service['category'])); ?>
                                </div>
                                <h5 class="mb-3"><?php echo htmlspecialchars($service['name']); ?></h5>
                                <p class="mb-4"><?php echo htmlspecialchars($service['description']); ?></p>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="service-price">
                                        <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="16" height="16">
                                        <?php echo number_format((float)$service['price'], 0); ?>
                                    </div>
                                    <div class="service-duration">
                                        <i class="far fa-clock me-1"></i>
                                        <?php echo htmlspecialchars($service['duration']); ?> min
                                    </div>
                                </div>
                                
                                <?php if(isset($service['booking_count']) && $service['booking_count'] > 0): ?>
                                <div class="service-booking-count mb-3">
                                    <i class="fas fa-calendar-check me-1"></i>
                                    <?php echo $service['booking_count']; ?> bookings received for this service
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" 
                                              id="statusSwitch<?php echo $service['id']; ?>" 
                                              <?php echo $service['is_active'] ? 'checked' : ''; ?>
                                              onchange="toggleServiceStatus(<?php echo $service['id']; ?>, this.checked)">
                                        <label class="form-check-label" for="statusSwitch<?php echo $service['id']; ?>">
                                            <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </label>
                                    </div>
                                    <div class="service-actions">
                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                data-bs-toggle="modal" data-bs-target="#editServiceModal" 
                                                data-service-id="<?php echo $service['id']; ?>"
                                                data-service-category="<?php echo htmlspecialchars($service['category']); ?>"
                                                data-service-name="<?php echo htmlspecialchars($service['name']); ?>"
                                                data-service-description="<?php echo htmlspecialchars($service['description']); ?>"
                                                data-service-price="<?php echo (float)$service['price']; ?>"
                                                data-service-duration="<?php echo (int)$service['duration']; ?>"
                                                data-service-active="<?php echo (int)$service['is_active']; ?>">
                                            <i class="fas fa-edit me-1"></i> Edit
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                data-bs-toggle="modal" data-bs-target="#deleteServiceModal" 
                                                data-service-id="<?php echo $service['id']; ?>"
                                                data-service-name="<?php echo htmlspecialchars($service['name']); ?>">
                                            <i class="fas fa-trash-alt me-1"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- No Results Message (initially hidden) -->
            <div id="noResults" class="card text-center p-4 d-none">
                <div class="card-body">
                    <i class="fas fa-search fa-3x mb-3 text-muted"></i>
                    <h5>No matching services found</h5>
                    <p class="text-muted">Try changing your search criteria or add a new service.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addServiceModalLabel">Add New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_service">
                        
                        <div class="mb-3">
                            <label for="category" class="form-label">Service Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <?php foreach ($serviceCategories as $category): ?>
                                <option value="<?php echo $category; ?>">
                                    <?php echo ucfirst($category); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select the device category this service applies to.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Service Name</label>
                            <input type="text" class="form-control" id="name" name="name" required 
                                   placeholder="e.g. Screen Replacement, Battery Repair">
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" required
                                     placeholder="Describe what this service includes..."></textarea>
                            <div class="form-text">
                                <i class="fas fa-lightbulb me-1 text-warning"></i>
                                Tip: Be detailed about what the service includes, parts used, and warranty.
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="price" class="form-label">Price (SAR)</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <img src="../admin/sar/sar.png" alt="" width="12" height="12">
                                    </span>
                                    <input type="number" class="form-control" id="price" name="price" min="0" step="0.01" required
                                           placeholder="0.00">
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="duration" class="form-label">Duration (minutes)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="duration" name="duration" min="15" step="15" required
                                           placeholder="60">
                                    <span class="input-group-text">min</span>
                                </div>
                                <div class="form-text">Typical time to complete this service</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Service Modal -->
    <div class="modal fade" id="editServiceModal" tabindex="-1" aria-labelledby="editServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editServiceModalLabel">Edit Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_service">
                        <input type="hidden" name="service_id" id="edit_service_id">
                        
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Service Category</label>
                            <select class="form-select" id="edit_category" name="category" required>
                                <?php foreach ($serviceCategories as $category): ?>
                                <option value="<?php echo $category; ?>"><?php echo ucfirst($category); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Service Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                            <div class="form-text">
                                <i class="fas fa-lightbulb me-1 text-warning"></i>
                                Tip: Clear descriptions help customers understand what they're booking.
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_price" class="form-label">Price (SAR)</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <img src="../admin/sar/sar.png" alt="" width="12" height="12">
                                    </span>
                                    <input type="number" class="form-control" id="edit_price" name="price" min="0" step="0.01" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="edit_duration" class="form-label">Duration (minutes)</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="edit_duration" name="duration" min="15" step="15" required>
                                    <span class="input-group-text">min</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">Service Active</label>
                            <div class="form-text">Inactive services won't appear to customers</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Service Confirmation Modal -->
    <div class="modal fade" id="deleteServiceModal" tabindex="-1" aria-labelledby="deleteServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteServiceModalLabel">Confirm Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete the service "<span id="delete_service_name"></span>"?</p>
                    <p class="text-danger mb-0"><i class="fas fa-exclamation-triangle me-2"></i>This action cannot be undone and will hide this service from all customers.</p>
                    <div class="alert alert-warning mt-3">
                        <small>
                            <strong>Note:</strong> If this service has any existing bookings, the service will be hidden from new customers but will remain accessible to customers who have already booked it.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="service_id" id="delete_service_id">
                        <button type="submit" class="btn btn-danger">Delete Service</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
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
            
            // Set up edit service modal
            const editServiceModal = document.getElementById('editServiceModal');
            if (editServiceModal) {
                editServiceModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    
                    // Extract service data from button attributes
                    const serviceId = button.getAttribute('data-service-id');
                    const serviceCategory = button.getAttribute('data-service-category');
                    const serviceName = button.getAttribute('data-service-name');
                    const serviceDescription = button.getAttribute('data-service-description');
                    const servicePrice = button.getAttribute('data-service-price');
                    const serviceDuration = button.getAttribute('data-service-duration');
                    const serviceActive = button.getAttribute('data-service-active') === '1';
                    
                    // Set the form values
                    document.getElementById('edit_service_id').value = serviceId;
                    document.getElementById('edit_category').value = serviceCategory;
                    document.getElementById('edit_name').value = serviceName;
                    document.getElementById('edit_description').value = serviceDescription;
                    document.getElementById('edit_price').value = servicePrice;
                    document.getElementById('edit_duration').value = serviceDuration;
                    document.getElementById('edit_is_active').checked = serviceActive;
                });
            }
            
            // Set up delete service modal
            const deleteServiceModal = document.getElementById('deleteServiceModal');
            if (deleteServiceModal) {
                deleteServiceModal.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    
                    // Extract service data from button attributes
                    const serviceId = button.getAttribute('data-service-id');
                    const serviceName = button.getAttribute('data-service-name');
                    
                    // Set the modal values
                    document.getElementById('delete_service_id').value = serviceId;
                    document.getElementById('delete_service_name').textContent = serviceName;
                });
            }
            
            // Auto-hide alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert.alert-success, .alert.alert-danger');
                alerts.forEach(function(alert) {
                    const bsAlert = bootstrap.Alert.getInstance(alert);
                    if (bsAlert) {
                        bsAlert.close();
                    }
                });
            }, 5000);
            
            // Category Chart with better colors
            const categoryChartCanvas = document.getElementById('categoryChart');
            if (categoryChartCanvas) {
                const ctx = categoryChartCanvas.getContext('2d');
                
                // Get the data from PHP
                const categoryData = <?php echo !empty($categoryChartJson) ? $categoryChartJson : '[]'; ?>;
                
                if (categoryData.length > 0) {
                    // Prepare labels and data
                    const labels = categoryData.map(item => item.category);
                    const data = categoryData.map(item => item.count);
                    
                    // Define nice colors for categories
                    const backgroundColors = [
                        'rgba(13, 110, 253, 0.6)',    // blue
                        'rgba(111, 66, 193, 0.6)',    // purple
                        'rgba(253, 126, 20, 0.6)',    // orange
                        'rgba(25, 135, 84, 0.6)',     // green
                        'rgba(220, 53, 69, 0.6)',     // red
                        'rgba(13, 202, 240, 0.6)'     // cyan
                    ];
                    
                    const categoryChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: backgroundColors.slice(0, data.length),
                                borderWidth: 2,
                                borderColor: 'rgba(255, 255, 255, 0.2)'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        boxWidth: 12,
                                        padding: 15
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((value / total) * 100);
                                            return `${context.label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%',
                            animation: {
                                animateScale: true,
                                animateRotate: true
                            }
                        }
                    });
                }
            }
            
            // Search and filter functionality
            const serviceSearch = document.getElementById('serviceSearch');
            const categoryFilter = document.getElementById('categoryFilter');
            const statusFilter = document.getElementById('statusFilter');
            const serviceItems = document.querySelectorAll('.service-item');
            const noResults = document.getElementById('noResults');
            
            function filterServices() {
                if (!serviceSearch || !categoryFilter || !statusFilter || !serviceItems || !noResults) {
                    return;
                }
                
                const searchText = serviceSearch.value.toLowerCase();
                const categoryValue = categoryFilter.value;
                const statusValue = statusFilter.value;
                
                let visibleCount = 0;
                
                serviceItems.forEach(item => {
                    const serviceName = item.querySelector('h5').textContent.toLowerCase();
                    const serviceDesc = item.querySelector('p').textContent.toLowerCase();
                    const serviceCategory = item.getAttribute('data-category');
                    const serviceStatus = item.getAttribute('data-status');
                    
                    const matchesSearch = searchText === '' || 
                                        serviceName.includes(searchText) || 
                                        serviceDesc.includes(searchText);
                    
                    const matchesCategory = categoryValue === 'all' || serviceCategory === categoryValue;
                    
                    const matchesStatus = statusValue === 'all' || serviceStatus === statusValue;
                    
                    const isVisible = matchesSearch && matchesCategory && matchesStatus;
                    
                    item.style.display = isVisible ? '' : 'none';
                    
                    if (isVisible) visibleCount++;
                });
                
                // Show/hide no results message
                if (noResults) {
                    noResults.classList.toggle('d-none', visibleCount > 0);
                }
            }
            
            if (serviceSearch) {
                serviceSearch.addEventListener('input', filterServices);
            }
            
            if (categoryFilter) {
                categoryFilter.addEventListener('change', filterServices);
            }
            
            if (statusFilter) {
                statusFilter.addEventListener('change', filterServices);
            }
            
            // Toggle service status
            window.toggleServiceStatus = function(serviceId, isActive) {
                // Show loading overlay
                document.getElementById('loadingOverlay').style.display = 'flex';
                
                // Create and submit form to toggle status
                const form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                
                const actionInput = document.createElement('input');
                actionInput.name = 'action';
                actionInput.value = 'toggle_service';
                
                const serviceIdInput = document.createElement('input');
                serviceIdInput.name = 'service_id';
                serviceIdInput.value = serviceId;
                
                const statusInput = document.createElement('input');
                statusInput.name = 'is_active';
                statusInput.value = isActive.toString(); // Convert boolean to string 'true'/'false'
                
                form.appendChild(actionInput);
                form.appendChild(serviceIdInput);
                form.appendChild(statusInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    </script>
</body>
</html>