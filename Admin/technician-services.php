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
        // Check if it's an external URL
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            // Construct local path
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            
            // Validate if file exists
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
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
    // Add service action
    if (isset($_POST['action']) && $_POST['action'] === 'addService') {
        try {
            $category = clean_input($_POST['category']);
            $name = clean_input($_POST['name']);
            $description = clean_input($_POST['description']);
            $price = (float)clean_input($_POST['price']);
            $duration = (int)clean_input($_POST['duration']);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate inputs
            if (empty($name) || empty($category) || $price <= 0 || $duration <= 0) {
                throw new Exception("All fields are required. Price and duration must be greater than zero.");
            }
            
            // Insert new service
            $stmt = $pdo->prepare("
                INSERT INTO services 
                (provider_id, category, name, description, price, duration, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $technicianId,
                $category,
                $name,
                $description,
                $price,
                $duration,
                $isActive
            ]);
            
            $actionMessage = "Service added successfully.";
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
    
    // Edit service action
    if (isset($_POST['action']) && $_POST['action'] === 'editService') {
        try {
            $serviceId = (int)clean_input($_POST['service_id']);
            $category = clean_input($_POST['category']);
            $name = clean_input($_POST['name']);
            $description = clean_input($_POST['description']);
            $price = (float)clean_input($_POST['price']);
            $duration = (int)clean_input($_POST['duration']);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate inputs
            if (empty($name) || empty($category) || $price <= 0 || $duration <= 0) {
                throw new Exception("All fields are required. Price and duration must be greater than zero.");
            }
            
            // Verify the service belongs to this technician
            $stmt = $pdo->prepare("SELECT id FROM services WHERE id = ? AND provider_id = ?");
            $stmt->execute([$serviceId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid service ID.");
            }
            
            // Update service
            $stmt = $pdo->prepare("
                UPDATE services 
                SET category = ?, name = ?, description = ?, price = ?, duration = ?, is_active = ?, updated_at = NOW()
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([
                $category,
                $name,
                $description,
                $price,
                $duration,
                $isActive,
                $serviceId,
                $technicianId
            ]);
            
            $actionMessage = "Service updated successfully.";
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
    
    // Toggle service status action
    if (isset($_POST['action']) && $_POST['action'] === 'toggleServiceStatus') {
        try {
            $serviceId = (int)clean_input($_POST['service_id']);
            $newStatus = (int)clean_input($_POST['status']); // 0 or 1
            
            // Verify the service belongs to this technician
            $stmt = $pdo->prepare("SELECT id FROM services WHERE id = ? AND provider_id = ?");
            $stmt->execute([$serviceId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid service ID.");
            }
            
            // Update service status
            $stmt = $pdo->prepare("
                UPDATE services 
                SET is_active = ?, updated_at = NOW()
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([
                $newStatus,
                $serviceId,
                $technicianId
            ]);
            
            $actionMessage = "Service status updated successfully.";
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
    
    // Delete service action
    if (isset($_POST['action']) && $_POST['action'] === 'deleteService') {
        try {
            $serviceId = (int)clean_input($_POST['service_id']);
            
            // Verify the service belongs to this technician
            $stmt = $pdo->prepare("SELECT id FROM services WHERE id = ? AND provider_id = ?");
            $stmt->execute([$serviceId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid service ID.");
            }
            
            // Check if service has existing bookings
            try {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as booking_count 
                    FROM bookings 
                    WHERE service_id = ?
                ");
                $stmt->execute([$serviceId]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && $result['booking_count'] > 0) {
                    throw new Exception("Cannot delete a service with existing bookings. Consider deactivating it instead.");
                }
            } catch (PDOException $e) {
                // If the query fails (e.g., due to missing column), proceed with deletion
                if (stripos($e->getMessage(), "service_id") === false) {
                    // Only rethrow if it's not related to service_id column
                    throw $e;
                }
            }
            
            // Delete service
            $stmt = $pdo->prepare("DELETE FROM services WHERE id = ? AND provider_id = ?");
            $stmt->execute([$serviceId, $technicianId]);
            
            $actionMessage = "Service deleted successfully.";
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

// Get technician information
$technicianData = null;

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
    
    // Get technician profile image
    $technicianProfileImage = '../default.png';
    if (!empty($technicianData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $technicianData['profile_image'])) {
            $technicianProfileImage = $technicianData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($technicianData['profile_image']);
            if (file_exists($imagePath)) {
                $technicianProfileImage = $imagePath;
            }
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching technician data: " . $e->getMessage());
    header('Location: technicians.php?error=db');
    exit;
}

// Get technician services
$services = [];
$categoryFilter = isset($_GET['category']) ? clean_input($_GET['category']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'name';
$sortDir = isset($_GET['dir']) ? (clean_input($_GET['dir']) === 'asc' ? 'ASC' : 'DESC') : 'ASC';
$statusFilter = isset($_GET['status']) ? clean_input($_GET['status']) : '';

// Validate sort column to prevent SQL injection
$allowedSortColumns = ['name', 'category', 'price', 'duration', 'is_active', 'created_at'];
if (!in_array($sortBy, $allowedSortColumns)) {
    $sortBy = 'name'; // Default sort
}

try {
    $query = "
        SELECT s.*, 
               (SELECT COUNT(*) FROM bookings WHERE service_id = s.id) as booking_count
        FROM services s
        WHERE s.provider_id = ?
    ";
    $params = [$technicianId];
    
    // Add category filter
    if (!empty($categoryFilter)) {
        $query .= " AND s.category = ?";
        $params[] = $categoryFilter;
    }
    
    // Add status filter
    if ($statusFilter !== '') {
        $query .= " AND s.is_active = ?";
        $params[] = $statusFilter;
    }
    
    // Add sort
    $query .= " ORDER BY s.{$sortBy} {$sortDir}";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Try/catch in case the query fails due to missing column
    try {
        foreach ($services as &$service) {
            $bookingQuery = $pdo->prepare("SELECT COUNT(*) as count FROM bookings WHERE service_id = ?");
            $bookingQuery->execute([$service['id']]);
            $bookingResult = $bookingQuery->fetch(PDO::FETCH_ASSOC);
            $service['booking_count'] = $bookingResult ? $bookingResult['count'] : 0;
        }
    } catch (PDOException $e) {
        // Ignore errors with bookings table or service_id column
        if (stripos($e->getMessage(), "service_id") === false) {
            throw $e;
        }
    }
    
} catch (PDOException $e) {
    error_log("Database error fetching services: " . $e->getMessage());
    $services = [];
}

// Get unique categories
$categories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM services ORDER BY category ASC");
    $categoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($categoryRows as $row) {
        $categories[] = $row['category'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching categories: " . $e->getMessage());
}

// Predefined categories if no existing ones
if (empty($categories)) {
    $categories = ['Repair', 'Installation', 'Maintenance', 'Consultation', 'Other'];
}

// Get service stats
$serviceStats = [
    'total' => count($services),
    'active' => 0,
    'inactive' => 0,
    'total_bookings' => 0
];

foreach ($services as $service) {
    if ($service['is_active'] == 1) {
        $serviceStats['active']++;
    } else {
        $serviceStats['inactive']++;
    }
    $serviceStats['total_bookings'] += isset($service['booking_count']) ? $service['booking_count'] : 0;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Services - FixItNow Admin</title>
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
        
        /* Technician info */
        .technician-info {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .technician-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .technician-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .technician-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .technician-meta {
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        /* Status styles */
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
        
        /* Category labels */
        .category-badge {
            display: inline-block;
            padding: 0.35rem 0.7rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--bs-primary);
        }
        
        /* Filter area */
        .filter-area {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* Service cards */
        .service-card {
            display: flex;
            flex-direction: column;
            height: 100%;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .service-card .card-body {
            flex: 1;
        }
        
        .service-name {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .service-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .service-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1rem;
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .service-meta-item {
            display: flex;
            align-items: center;
        }
        
        .service-meta-item i {
            margin-right: 0.5rem;
        }
        
        /* Form Controls */
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
        
        /* Service list table */
        .service-table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.03em;
        }
        
        .service-table .service-title {
            font-weight: 600;
        }
        
        .service-table .service-description {
            font-size: 0.875rem;
            color: var(--text-muted);
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .empty-state-message {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .empty-state-description {
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Stats cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            text-align: center;
            padding: 1.5rem;
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
        
        /* Filter pills */
        .filter-pills .nav-link {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            margin-right: 0.5rem;
            color: var(--text-color);
            font-size: 0.9rem;
            background-color: var(--card-bg);
        }
        
        .filter-pills .nav-link.active {
            background-color: var(--primary-color);
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
                <a href="technician-details.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Technician Details
                </a>
                
                <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                    <i class="fas fa-plus me-2"></i>Add Service
                </a>
            </div>
            
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Technician Info -->
            <div class="technician-info mb-4">
                <div class="technician-avatar">
                    <img src="<?php echo htmlspecialchars($technicianProfileImage); ?>" alt="<?php echo htmlspecialchars($technicianData['first_name']); ?>">
                </div>
                <div>
                    <div class="technician-name">
                        <?php echo htmlspecialchars($technicianData['first_name'] . ' ' . $technicianData['last_name']); ?>
                    </div>
                    <div class="technician-meta">
                        <div class="me-3">
                            <i class="fas fa-cog text-primary me-1"></i>
                            <?php echo $serviceStats['total']; ?> Services
                        </div>
                        <div>
                            <i class="fas fa-bookmark text-warning me-1"></i>
                            <?php echo $serviceStats['total_bookings']; ?> Bookings
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Services Stats -->
            <div class="stats-grid">
                <div class="card stat-card">
                    <div class="text-primary mb-3">
                        <i class="fas fa-cogs fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $serviceStats['total']; ?></div>
                    <div class="stat-label">Total Services</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-success mb-3">
                        <i class="fas fa-check-circle fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $serviceStats['active']; ?></div>
                    <div class="stat-label">Active Services</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-danger mb-3">
                        <i class="fas fa-times-circle fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $serviceStats['inactive']; ?></div>
                    <div class="stat-label">Inactive Services</div>
                </div>
                
                <div class="card stat-card">
                    <div class="text-info mb-3">
                        <i class="fas fa-calendar-check fa-2x"></i>
                    </div>
                    <div class="stat-value"><?php echo $serviceStats['total_bookings']; ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
            </div>
            
            <!-- Filter Pills -->
            <div class="filter-pills mb-4">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link <?php echo empty($categoryFilter) && $statusFilter === '' ? 'active' : ''; ?>" href="technician-services.php?id=<?php echo $technicianId; ?>">
                            <i class="fas fa-list me-2"></i>All Services
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === '1' ? 'active' : ''; ?>" href="technician-services.php?id=<?php echo $technicianId; ?>&status=1">
                            <i class="fas fa-check-circle me-2"></i>Active
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $statusFilter === '0' ? 'active' : ''; ?>" href="technician-services.php?id=<?php echo $technicianId; ?>&status=0">
                            <i class="fas fa-times-circle me-2"></i>Inactive
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Filter Area -->
            <div class="filter-area mb-4">
                <form action="technician-services.php" method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="id" value="<?php echo $technicianId; ?>">
                    
                    <?php if ($statusFilter !== ''): ?>
                    <input type="hidden" name="status" value="<?php echo $statusFilter; ?>">
                    <?php endif; ?>
                    
                    <div class="col-md-4">
                        <label for="category" class="form-label">Filter by Category</label>
                        <select class="form-select" id="category" name="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($category)); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-4">
                        <label for="sort" class="form-label">Sort By</label>
                        <select class="form-select" id="sort" name="sort" onchange="this.form.submit()">
                            <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name</option>
                            <option value="price" <?php echo $sortBy === 'price' ? 'selected' : ''; ?>>Price</option>
                            <option value="duration" <?php echo $sortBy === 'duration' ? 'selected' : ''; ?>>Duration</option>
                            <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Date Added</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label for="dir" class="form-label">Order</label>
                        <select class="form-select" id="dir" name="dir" onchange="this.form.submit()">
                            <option value="asc" <?php echo $sortDir === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                            <option value="desc" <?php echo $sortDir === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <a href="technician-services.php?id=<?php echo $technicianId; ?>" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-redo me-2"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Services Table -->
            <?php if (empty($services)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-cogs"></i>
                </div>
                <h3 class="empty-state-message">No services found</h3>
                <p class="empty-state-description">
                    <?php if (!empty($categoryFilter)): ?>
                    No services found in the "<?php echo htmlspecialchars(ucfirst($categoryFilter)); ?>" category.
                    <?php elseif ($statusFilter === '1'): ?>
                    No active services found for this technician.
                    <?php elseif ($statusFilter === '0'): ?>
                    No inactive services found for this technician.
                    <?php else: ?>
                    This technician doesn't have any services yet. Add a service to allow customers to book appointments.
                    <?php endif; ?>
                </p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                    <i class="fas fa-plus me-2"></i>Add Service
                </button>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title m-0">Services</h5>
                    <a href="#" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="fas fa-plus me-1"></i>Add Service
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table service-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Duration</th>
                                <th>Status</th>
                                <th>Bookings</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($services as $service): ?>
                            <tr>
                                <td>
                                    <div class="service-title"><?php echo htmlspecialchars($service['name']); ?></div>
                                    <div class="service-description"><?php echo htmlspecialchars($service['description']); ?></div>
                                </td>
                                <td>
                                    <span class="category-badge">
                                        <?php echo htmlspecialchars(ucfirst($service['category'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong>
                                        <img src="../sar/sar.png" alt="SAR" style="height:16px; margin-right:4px; vertical-align:text-bottom;">
                                        <?php echo number_format($service['price'], 2); ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php echo $service['duration']; ?> min
                                </td>
                                <td>
                                    <?php if ($service['is_active'] == 1): ?>
                                    <span class="status-badge active">
                                        <i class="fas fa-check-circle me-1"></i>Active
                                    </span>
                                    <?php else: ?>
                                    <span class="status-badge inactive">
                                        <i class="fas fa-times-circle me-1"></i>Inactive
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo isset($service['booking_count']) ? $service['booking_count'] : '0'; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editServiceModal" 
                                            data-service-id="<?php echo $service['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($service['name']); ?>"
                                            data-category="<?php echo htmlspecialchars($service['category']); ?>"
                                            data-description="<?php echo htmlspecialchars($service['description']); ?>"
                                            data-price="<?php echo $service['price']; ?>"
                                            data-duration="<?php echo $service['duration']; ?>"
                                            data-is-active="<?php echo $service['is_active']; ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <!-- Toggle status -->
                                        <?php if ($service['is_active'] == 1): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="toggleServiceStatus">
                                            <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                            <input type="hidden" name="status" value="0">
                                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Deactivate">
                                                <i class="fas fa-eye-slash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="toggleServiceStatus">
                                            <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                            <input type="hidden" name="status" value="1">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Activate">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        
                                        <!-- Delete button -->
                                        <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteServiceModal" 
                                            data-service-id="<?php echo $service['id']; ?>"
                                            data-service-name="<?php echo htmlspecialchars($service['name']); ?>"
                                            data-bookings="<?php echo isset($service['booking_count']) ? $service['booking_count'] : '0'; ?>">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="addService">
                        
                        <div class="mb-3">
                            <label for="name" class="form-label">Service Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="category" class="form-label">Category</label>
                            <select class="form-select" id="category" name="category" required>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo htmlspecialchars(ucfirst($category)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="price" class="form-label">Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <img src="../sar/sar.png" alt="SAR" height="18">
                                    </span>
                                    <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="duration" class="form-label">Duration (minutes)</label>
                                <input type="number" class="form-control" id="duration" name="duration" min="1" required>
                            </div>
                        </div>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                            <label class="form-check-label" for="is_active">Service is active</label>
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
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="editService">
                        <input type="hidden" name="service_id" id="edit_service_id">
                        
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Service Name</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_category" class="form-label">Category</label>
                            <select class="form-select" id="edit_category" name="category" required>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>">
                                    <?php echo htmlspecialchars(ucfirst($category)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_price" class="form-label">Price</label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <img src="../sar/sar.png" alt="SAR" height="18">
                                    </span>
                                    <input type="number" class="form-control" id="edit_price" name="price" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <label for="edit_duration" class="form-label">Duration (minutes)</label>
                                <input type="number" class="form-control" id="edit_duration" name="duration" min="1" required>
                            </div>
                        </div>
                        
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">Service is active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Service Modal -->
    <div class="modal fade" id="deleteServiceModal" tabindex="-1" aria-labelledby="deleteServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteServiceModalLabel">Delete Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this service?</p>
                    <p><strong>Service:</strong> <span id="delete_service_name"></span></p>
                    
                    <div id="delete_warning" class="alert alert-warning d-none">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        This service has active bookings. It's recommended to deactivate it instead of deleting.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post">
                        <input type="hidden" name="action" value="deleteService">
                        <input type="hidden" name="service_id" id="delete_service_id">
                        <button type="submit" class="btn btn-danger">Delete Service</button>
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
            
            // Handle edit service modal
            const editServiceModal = document.getElementById('editServiceModal');
            if (editServiceModal) {
                editServiceModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const serviceId = button.getAttribute('data-service-id');
                    const name = button.getAttribute('data-name');
                    const category = button.getAttribute('data-category');
                    const description = button.getAttribute('data-description');
                    const price = button.getAttribute('data-price');
                    const duration = button.getAttribute('data-duration');
                    const isActive = button.getAttribute('data-is-active') === '1';
                    
                    document.getElementById('edit_service_id').value = serviceId;
                    document.getElementById('edit_name').value = name;
                    document.getElementById('edit_category').value = category;
                    document.getElementById('edit_description').value = description;
                    document.getElementById('edit_price').value = price;
                    document.getElementById('edit_duration').value = duration;
                    document.getElementById('edit_is_active').checked = isActive;
                });
            }
            
            // Handle delete service modal
            const deleteServiceModal = document.getElementById('deleteServiceModal');
            if (deleteServiceModal) {
                deleteServiceModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const serviceId = button.getAttribute('data-service-id');
                    const serviceName = button.getAttribute('data-service-name');
                    const bookings = parseInt(button.getAttribute('data-bookings'), 10);
                    
                    document.getElementById('delete_service_id').value = serviceId;
                    document.getElementById('delete_service_name').textContent = serviceName;
                    
                    // Show warning if service has bookings
                    if (bookings > 0) {
                        document.getElementById('delete_warning').classList.remove('d-none');
                    } else {
                        document.getElementById('delete_warning').classList.add('d-none');
                    }
                });
            }
        });
    </script>
</body>
</html>