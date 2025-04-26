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

// Get the admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    $adminProfileImage = getUserProfileImage($userId);
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
} catch (Exception $e) {
    error_log("General error fetching admin data: " . $e->getMessage());
}

// Check if technician ID is provided
$technicianId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$technicianId) {
    // Redirect if no ID provided
    header('Location: technicians.php');
    exit;
}

// Process actions
$actionMessage = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process technician verification
    if (isset($_POST['action']) && $_POST['action'] === 'verifyTechnician') {
        try {
            $verified = clean_input($_POST['verified']); // '0' or '1'
            
            // Update provider verification status
            $stmt = $pdo->prepare("UPDATE providers SET is_verified = ? WHERE id = ?");
            $stmt->execute([$verified, $technicianId]);
            
            $actionMessage = "Technician verification status updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating verification status: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process repair price update
    if (isset($_POST['action']) && $_POST['action'] === 'updatePrice') {
        try {
            $price = clean_input($_POST['price']); 
            
            // Update provider price (using hourly_rate field)
            $stmt = $pdo->prepare("UPDATE providers SET hourly_rate = ? WHERE id = ?");
            $stmt->execute([$price, $technicianId]);
            
            $actionMessage = "Repair price updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating repair price: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
    
    // Process specialties update
    if (isset($_POST['action']) && $_POST['action'] === 'updateSpecialties') {
        try {
            $specialties = isset($_POST['specialties']) ? implode(',', $_POST['specialties']) : '';
            
            // Update provider specialties
            $stmt = $pdo->prepare("UPDATE providers SET specialties = ? WHERE id = ?");
            $stmt->execute([$specialties, $technicianId]);
            
            $actionMessage = "Technician specialties updated successfully.";
            $actionType = "success";
        } catch (PDOException $e) {
            $actionMessage = "Error updating specialties: " . $e->getMessage();
            $actionType = "danger";
            error_log($actionMessage);
        }
    }
}

// Get technician details
$technicianData = null;
$userData = null;

try {
    // Query to get technician data with user information
    $stmt = $pdo->prepare("
        SELECT p.*, 
               u.id as user_id, 
               u.username, 
               u.email, 
               u.first_name, 
               u.last_name, 
               u.phone, 
               u.status as user_status, 
               u.created_at as joined_date,
               u.profile_image
        FROM providers p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$technicianId]);
    $technicianData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$technicianData) {
        // Redirect if technician not found
        header('Location: technicians.php');
        exit;
    }
    
    // Set user data for convenience
    $userData = [
        'id' => $technicianData['user_id'],
        'username' => $technicianData['username'],
        'email' => $technicianData['email'],
        'first_name' => $technicianData['first_name'],
        'last_name' => $technicianData['last_name'],
        'phone' => $technicianData['phone'],
        'status' => $technicianData['user_status'],
        'created_at' => $technicianData['joined_date'],
        'profile_image' => $technicianData['profile_image']
    ];
    
    // Get technician profile image
    $technicianProfileImage = getUserProfileImage($technicianData['user_id']);
} catch (PDOException $e) {
    error_log("Database error fetching technician data: " . $e->getMessage());
    // Redirect to technicians page with error
    header('Location: technicians.php?error=db');
    exit;
}

// Get technician statistics
$statsData = [
    'bookings_count' => 0,
    'completed_bookings' => 0,
    'cancelled_bookings' => 0,
    'avg_rating' => 0,
    'reviews_count' => 0,
    'services_count' => 0,
    'total_earned' => 0
];

try {
    // Get bookings count
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN payment_status = 'paid' AND total_price IS NOT NULL THEN total_price ELSE 0 END) as total_earned
        FROM bookings 
        WHERE provider_id = ?
    ");
    $stmt->execute([$technicianId]);
    $bookingsStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($bookingsStats) {
        $statsData['bookings_count'] = (int)$bookingsStats['total_bookings'];
        $statsData['completed_bookings'] = (int)$bookingsStats['completed'];
        $statsData['cancelled_bookings'] = (int)$bookingsStats['cancelled'];
        $statsData['total_earned'] = (float)$bookingsStats['total_earned'];
    }
    
    // Get reviews stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as reviews_count,
            AVG(rating) as avg_rating
        FROM reviews 
        WHERE provider_id = ?
    ");
    $stmt->execute([$technicianId]);
    $reviewsStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reviewsStats) {
        $statsData['reviews_count'] = (int)$reviewsStats['reviews_count'];
        $statsData['avg_rating'] = $reviewsStats['avg_rating'] ? (float)$reviewsStats['avg_rating'] : 0;
    }
    
    // Get services count
    $stmt = $pdo->prepare("SELECT COUNT(*) as services_count FROM services WHERE provider_id = ?");
    $stmt->execute([$technicianId]);
    $servicesStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($servicesStats) {
        $statsData['services_count'] = (int)$servicesStats['services_count'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching technician statistics: " . $e->getMessage());
}

// Get recent bookings
$recentBookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, 
               u.first_name as customer_first_name, 
               u.last_name as customer_last_name,
               s.name as service_name
        FROM bookings b
        JOIN users u ON b.customer_id = u.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE b.provider_id = ?
        ORDER BY b.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$technicianId]);
    $recentBookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching recent bookings: " . $e->getMessage());
}

// Get recent reviews
$recentReviews = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               u.first_name as customer_first_name, 
               u.last_name as customer_last_name
        FROM reviews r
        JOIN users u ON r.customer_id = u.id
        WHERE r.provider_id = ?
        ORDER BY r.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$technicianId]);
    $recentReviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching recent reviews: " . $e->getMessage());
}

// Get services
$services = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM services 
        WHERE provider_id = ? 
        ORDER BY name ASC
    ");
    $stmt->execute([$technicianId]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching services: " . $e->getMessage());
}

// Get availability slots
$availabilitySlots = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM schedule_slots 
        WHERE provider_id = ? AND date >= CURDATE()
        ORDER BY date ASC, start_time ASC
        LIMIT 10
    ");
    $stmt->execute([$technicianId]);
    $availabilitySlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching availability slots: " . $e->getMessage());
}

// Get all specialties for the form
$allSpecialties = [
    'smartphone' => 'Smartphones',
    'laptop' => 'Laptops',
    'tablet' => 'Tablets',
    'desktop' => 'Desktop Computers',
    'tv' => 'TVs & Displays',
    'gaming' => 'Gaming Consoles',
    'printer' => 'Printers',
    'network' => 'Network Equipment',
    'camera' => 'Cameras',
    'appliance' => 'Home Appliances'
];

// Get technician's current specialties as array
$currentSpecialties = !empty($technicianData['specialties']) ? explode(',', $technicianData['specialties']) : [];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Details - FixItNow Admin</title>
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
            border-radius: 1rem;
            border: none;
            background-color: var(--card-bg);
            transition: box-shadow 0.3s ease;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
            border-radius: 1rem 1rem 0 0 !important;
        }
        
        .card-footer {
            background-color: rgba(0, 0, 0, 0.05);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            border-radius: 0 0 1rem 1rem !important;
        }
        
        /* Profile section */
        .profile-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 2rem 1rem;
            text-align: center;
            position: relative;
        }
        
        .profile-avatar {
            width: 130px;
            height: 130px;
            border-radius: 50%;
            overflow: hidden;
            margin-bottom: 1.5rem;
            border: 4px solid var(--card-bg);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .verification-badge {
            position: absolute;
            top: 30px;
            right: 20px;
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 0.8rem;
            border-radius: 2rem;
            font-size: 0.8rem;
            font-weight: 500;
            box-shadow: 0 2px 4px var(--shadow-color);
        }
        
        .verification-badge.verified {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .verification-badge.unverified {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        .profile-name {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .profile-username {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .profile-contact {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
        }
        
        .contact-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background-color: rgba(167, 135, 255, 0.1);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .stat-item {
            background-color: var(--card-bg);
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform 0.3s ease;
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Info list styles */
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-list li {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        }
        
        /* Specialty pills */
        .specialty-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin: 1rem 0;
        }
        
        .specialty-pill {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            background-color: rgba(167, 135, 255, 0.15);
            color: var(--primary-color);
            border-radius: 1rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
        }
        
        .data-table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table td {
            padding: 0.75rem 1rem;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.7rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-badge.active {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.inactive {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
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
        
        /* Star rating */
        .star-rating {
            color: #ffc107; /* Bootstrap warning color for stars */
            font-size: 1rem;
        }
        
        /* Actions section */
        .actions-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 12px;
            width: 2px;
            background-color: var(--border-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .timeline-icon {
            position: absolute;
            top: 0;
            left: -2rem;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            z-index: 1;
        }
        
        .timeline-content {
            background-color: var(--card-bg);
            border-radius: 1rem;
            padding: 1rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .timeline-date {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
        }
        
        /* Form Controls */
        .form-control, .form-select {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--input-border);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 0.25rem rgba(167, 135, 255, 0.25);
            border-color: var(--primary-color);
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Availability slots */
        .availability-slot {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            background-color: rgba(167, 135, 255, 0.1);
        }
        
        .slot-date {
            font-weight: 600;
        }
        
        .slot-time {
            color: var(--text-muted);
        }
        
        .slot-capacity {
            margin-left: auto;
            font-size: 0.875rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
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
                        <a class="nav-link active" href="technicians.php">
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
            <!-- Back button and page actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <a href="technicians.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Technicians
                </a>
                
                <div class="actions-toolbar">
                    <a href="edit-technician.php?id=<?php echo $technicianId; ?>" class="btn btn-primary">
                        <i class="fas fa-edit me-2"></i>Edit Profile
                    </a>
                    
                    <a href="technician-services.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-cogs me-2"></i>Manage Services
                    </a>
                    
                    <a href="technician-bookings.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-primary">
                        <i class="fas fa-calendar-check me-2"></i>View Bookings
                    </a>
                    
                    <a href="user-details.php?id=<?php echo $technicianData['user_id']; ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-user me-2"></i>User Profile
                    </a>
                </div>
            </div>
            
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <div class="row g-4">
                <!-- Left Column - Profile Information -->
                <div class="col-lg-4">
                    <!-- Profile Card -->
                    <div class="card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <img src="<?php echo htmlspecialchars($technicianProfileImage); ?>" alt="<?php echo htmlspecialchars($technicianData['first_name']); ?>">
                            </div>
                            
                            <!-- Verification Badge -->
                            <?php if ($technicianData['is_verified'] == 1): ?>
                            <div class="verification-badge verified">
                                <i class="fas fa-check-circle me-2"></i>Verified
                            </div>
                            <?php else: ?>
                            <div class="verification-badge unverified">
                                <i class="fas fa-times-circle me-2"></i>Unverified
                            </div>
                            <?php endif; ?>
                            
                            <h3 class="profile-name"><?php echo htmlspecialchars($technicianData['first_name'] . ' ' . $technicianData['last_name']); ?></h3>
                            <div class="profile-username">@<?php echo htmlspecialchars($technicianData['username']); ?></div>
                            
                            <!-- Account Status -->
                            <?php if ($userData['status'] === 'active'): ?>
                            <span class="status-badge active mb-3">
                                <i class="fas fa-check-circle me-1"></i>Active Account
                            </span>
                            <?php else: ?>
                            <span class="status-badge inactive mb-3">
                                <i class="fas fa-times-circle me-1"></i>Inactive Account
                            </span>
                            <?php endif; ?>
                            
                            <!-- Profile Actions -->
                            <div class="d-flex gap-2 mb-3">
                                <?php if ($technicianData['is_verified'] == 0): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="verifyTechnician">
                                    <input type="hidden" name="verified" value="1">
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check-circle me-2"></i>Verify
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="verifyTechnician">
                                    <input type="hidden" name="verified" value="0">
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-times-circle me-2"></i>Unverify
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updatePriceModal">
                                    <i class="fas fa-tools me-2"></i>Update Price
                                </button>
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="profile-contact">
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-envelope"></i>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Email</div>
                                        <div><?php echo htmlspecialchars($technicianData['email']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="contact-item">
                                    <div class="contact-icon">
                                        <i class="fas fa-phone"></i>
                                    </div>
                                    <div>
                                        <div class="small text-muted">Phone</div>
                                        <div><?php echo htmlspecialchars($technicianData['phone']); ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3 text-muted">No ratings yet</div>
                        </div>
                        
                        <div class="card-body">
                            <h5 class="card-title mb-3">Technician Information</h5>
                            
                            <ul class="info-list">
                                <li>
                                    <span class="info-label">Technician ID</span>
                                    <span class="info-value">#<?php echo $technicianId; ?></span>
                                </li>
                                <li>
                                    <span class="info-label">Location</span>
                                    <span class="info-value">
                                        <?php echo !empty($technicianData['location']) ? htmlspecialchars($technicianData['location']) : 'Not specified'; ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="info-label">Experience</span>
                                    <span class="info-value">
                                        <?php echo !empty($technicianData['experience']) ? htmlspecialchars($technicianData['experience']) . ' Years' : 'Not specified'; ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="info-label">Repair Price</span>
                                    <span class="info-value">
                                        <?php if ($technicianData['hourly_rate'] > 0): ?>
                                        <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                        <?php echo number_format($technicianData['hourly_rate'], 2); ?>
                                        <?php else: ?>
                                        Not set
                                        <?php endif; ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="info-label">Joined</span>
                                    <span class="info-value">
                                        <?php echo date('F j, Y', strtotime($technicianData['joined_date'])); ?>
                                    </span>
                                </li>
                                <li>
                                    <span class="info-label">Response Time</span>
                                    <span class="info-value">
                                        <?php echo !empty($technicianData['response_time']) ? htmlspecialchars($technicianData['response_time']) : 'Not available'; ?>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        
                        <!-- Specialties Section -->
                        <div class="card-body border-top">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="card-title mb-0">Specialties</h5>
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#specialtiesModal">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                            </div>
                            
                            <?php if (!empty($currentSpecialties)): ?>
                            <div class="specialty-pills">
                                <?php foreach ($currentSpecialties as $specialty): ?>
                                    <?php if (trim($specialty) != ''): ?>
                                    <span class="specialty-pill">
                                        <?php 
                                        $specialtyName = trim($specialty);
                                        echo isset($allSpecialties[$specialtyName]) ? $allSpecialties[$specialtyName] : ucfirst($specialtyName);
                                        ?>
                                    </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-muted">No specialties specified</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Bio Section -->
                        <div class="card-body border-top">
                            <h5 class="card-title mb-3">Bio</h5>
                            
                            <?php if (!empty($technicianData['bio'])): ?>
                            <p><?php echo nl2br(htmlspecialchars($technicianData['bio'])); ?></p>
                            <?php else: ?>
                            <p class="text-muted">No bio available</p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Education Section (if available) -->
                        <?php if (!empty($technicianData['education'])): ?>
                        <div class="card-body border-top">
                            <h5 class="card-title mb-3">Education</h5>
                            <p><?php echo nl2br(htmlspecialchars($technicianData['education'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Availability Card (if any slots available) -->
                    <?php if (!empty($availabilitySlots)): ?>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Availability Slots</h5>
                            <a href="technician-availability.php?id=<?php echo $technicianId; ?>" class="btn btn-sm btn-outline-primary">
                                Manage
                            </a>
                        </div>
                        <div class="card-body">
                            <?php foreach ($availabilitySlots as $slot): ?>
                            <div class="availability-slot">
                                <div>
                                    <div class="slot-date"><?php echo date('M d, Y', strtotime($slot['date'])); ?></div>
                                    <div class="slot-time">
                                        <?php echo date('h:i A', strtotime($slot['start_time'])); ?> - 
                                        <?php echo date('h:i A', strtotime($slot['end_time'])); ?>
                                    </div>
                                </div>
                                <div class="slot-capacity">
                                    <i class="fas fa-users me-1"></i> Max: <?php echo $slot['max_appointments']; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="card-footer">
                            <a href="technician-availability.php?id=<?php echo $technicianId; ?>" class="btn btn-sm btn-link text-decoration-none">
                                View all availability slots
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Right Column - Stats and Activity -->
                <div class="col-lg-8">
                    <!-- Statistics Cards -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Performance Overview</h5>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <div class="text-primary mb-3">
                                        <i class="fas fa-calendar-check fa-2x"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($statsData['bookings_count']); ?></div>
                                    <div class="stat-label">Total Bookings</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="text-success mb-3">
                                        <i class="fas fa-check-circle fa-2x"></i>
                                    </div>
                                    <div class="stat-value"><?php echo number_format($statsData['completed_bookings']); ?></div>
                                    <div class="stat-label">Completed</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="text-warning mb-3">
                                        <i class="fas fa-star fa-2x"></i>
                                    </div>
                                    <div class="stat-value">
                                        <?php echo $statsData['avg_rating'] > 0 ? number_format($statsData['avg_rating'], 1) : 'N/A'; ?>
                                    </div>
                                    <div class="stat-label">Avg. Rating</div>
                                </div>
                                
                                <div class="stat-item">
                                    <div class="text-info mb-3">
                                        <i class="fas fa-money-bill-wave fa-2x"></i>
                                    </div>
                                    <div class="stat-value">
                                        <img src="../sar/sar.png" alt="SAR" style="height:18px; margin-right:4px; vertical-align:text-bottom;">
                                        <?php echo number_format($statsData['total_earned'], 2); ?>
                                    </div>
                                    <div class="stat-label">Total Earned</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Services Card -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Services (<?php echo count($services); ?>)</h5>
                            <a href="technician-services.php?id=<?php echo $technicianId; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus me-1"></i> Add Service
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($services)): ?>
                            <div class="text-center py-4">
                                <div class="text-center">
                                    <i class="fas fa-cogs fa-3x text-muted mb-3"></i>
                                    <div class="d-flex justify-content-center">
                                        <img src="../images/gears.png" alt="No services" style="width: 80px; opacity: 0.5;">
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table table">
                                    <thead>
                                        <tr>
                                            <th>Service</th>
                                            <th>Category</th>
                                            <th>Price</th>
                                            <th>Duration</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($services as $service): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-medium"><?php echo htmlspecialchars($service['name']); ?></div>
                                                <div class="small text-muted text-truncate" style="max-width: 200px;">
                                                    <?php echo htmlspecialchars($service['description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo ucfirst(htmlspecialchars($service['category'])); ?>
                                            </td>
                                            <td>
                                                <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                                <?php echo number_format($service['price'], 2); ?>
                                            </td>
                                            <td>
                                                <?php echo $service['duration']; ?> min
                                            </td>
                                            <td>
                                                <?php if ($service['is_active'] == 1): ?>
                                                <span class="status-badge active">Active</span>
                                                <?php else: ?>
                                                <span class="status-badge inactive">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Bookings -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Recent Bookings</h5>
                            <a href="technician-bookings.php?id=<?php echo $technicianId; ?>" class="btn btn-sm btn-outline-primary">
                                View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentBookings)): ?>
                            <div class="text-center py-4">
                                <div class="mb-3">
                                    <i class="fas fa-calendar-day fa-3x text-muted"></i>
                                </div>
                                <p class="mb-0">No bookings yet</p>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table table">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Customer</th>
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
                                                <?php echo htmlspecialchars($booking['customer_first_name'] . ' ' . $booking['customer_last_name']); ?>
                                            </td>
                                            <td>
                                                <?php echo !empty($booking['service_name']) ? htmlspecialchars($booking['service_name']) : 'Custom Service'; ?>
                                            </td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($booking['booking_date'])); ?><br>
                                                <span class="small text-muted">
                                                    <?php echo date('h:i A', strtotime($booking['booking_time'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $booking['status']; ?>">
                                                    <?php echo ucfirst($booking['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                                <?php echo number_format($booking['total_price'], 2); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Reviews -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Recent Reviews</h5>
                            <a href="technician-reviews.php?id=<?php echo $technicianId; ?>" class="btn btn-sm btn-outline-primary">
                                View All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recentReviews)): ?>
                            <div class="text-center py-4">
                                <div class="mb-3">
                                    <i class="fas fa-comment-alt fa-3x text-muted"></i>
                                </div>
                                <p class="mb-0">No reviews yet</p>
                            </div>
                            <?php else: ?>
                            <div class="timeline">
                                <?php foreach ($recentReviews as $review): ?>
                                <div class="timeline-item">
                                    <div class="timeline-icon">
                                        <i class="fas fa-star"></i>
                                    </div>
                                    <div class="timeline-content">
                                        <div class="d-flex justify-content-between">
                                            <div class="timeline-title">
                                                <?php echo htmlspecialchars($review['customer_first_name'] . ' ' . $review['customer_last_name']); ?>
                                            </div>
                                            <div class="star-rating">
                                                <?php
                                                $rating = $review['rating'];
                                                for ($i = 1; $i <= 5; $i++) {
                                                    if ($i <= $rating) {
                                                        echo '<i class="fas fa-star"></i>';
                                                    } elseif ($i - 0.5 == $rating) {
                                                        echo '<i class="fas fa-star-half-alt"></i>';
                                                    } else {
                                                        echo '<i class="far fa-star"></i>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                        </div>
                                        <div class="timeline-date">
                                            <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                        </div>
                                        <p><?php echo !empty($review['comment']) ? htmlspecialchars($review['comment']) : '<em class="text-muted">No comment provided</em>'; ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Update Price Modal -->
    <div class="modal fade" id="updatePriceModal" tabindex="-1" aria-labelledby="updatePriceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updatePriceModalLabel">Update Repair Price</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="updatePrice">
                        
                        <div class="mb-3">
                            <label for="price" class="form-label">Repair Price</label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <img src="../sar/sar.png" alt="SAR" height="18">
                                </span>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" value="<?php echo number_format($technicianData['hourly_rate'] ?: 0, 2, '.', ''); ?>" required>
                            </div>
                            <div class="form-text">Set the complete repair price for this technician's services.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Price</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Specialties Modal -->
    <div class="modal fade" id="specialtiesModal" tabindex="-1" aria-labelledby="specialtiesModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="specialtiesModalLabel">Update Specialties</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="updateSpecialties">
                        
                        <div class="mb-3">
                            <label class="form-label">Select Specialties</label>
                            <div class="row">
                                <?php foreach ($allSpecialties as $key => $label): ?>
                                <div class="col-md-6">
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="checkbox" name="specialties[]" value="<?php echo $key; ?>" id="specialty-<?php echo $key; ?>" <?php echo in_array($key, $currentSpecialties) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="specialty-<?php echo $key; ?>">
                                            <?php echo $label; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Specialties</button>
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