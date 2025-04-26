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
    // Process add availability slot
    if (isset($_POST['action']) && $_POST['action'] === 'addSlot') {
        try {
            $date = clean_input($_POST['date']);
            $startTime = clean_input($_POST['start_time']);
            $endTime = clean_input($_POST['end_time']);
            $maxAppointments = (int)clean_input($_POST['max_appointments']);
            $description = isset($_POST['description']) ? clean_input($_POST['description']) : '';
            
            // Validate inputs
            if (empty($date) || empty($startTime) || empty($endTime) || $maxAppointments < 1) {
                throw new Exception("All fields are required and max appointments must be at least 1.");
            }
            
            // Check if start time is before end time
            $startDateTime = new DateTime($date . ' ' . $startTime);
            $endDateTime = new DateTime($date . ' ' . $endTime);
            
            if ($startDateTime >= $endDateTime) {
                throw new Exception("End time must be after start time.");
            }
            
            // Insert new slot
            $stmt = $pdo->prepare("
                INSERT INTO schedule_slots 
                (provider_id, date, start_time, end_time, max_appointments, description) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $technicianId,
                $date,
                $startTime,
                $endTime,
                $maxAppointments,
                $description
            ]);
            
            $actionMessage = "Availability slot added successfully.";
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
    
    // Process edit availability slot
    if (isset($_POST['action']) && $_POST['action'] === 'editSlot') {
        try {
            $slotId = (int)clean_input($_POST['slot_id']);
            $date = clean_input($_POST['date']);
            $startTime = clean_input($_POST['start_time']);
            $endTime = clean_input($_POST['end_time']);
            $maxAppointments = (int)clean_input($_POST['max_appointments']);
            $description = isset($_POST['description']) ? clean_input($_POST['description']) : '';
            
            // Validate inputs
            if (empty($date) || empty($startTime) || empty($endTime) || $maxAppointments < 1) {
                throw new Exception("All fields are required and max appointments must be at least 1.");
            }
            
            // Check if start time is before end time
            $startDateTime = new DateTime($date . ' ' . $startTime);
            $endDateTime = new DateTime($date . ' ' . $endTime);
            
            if ($startDateTime >= $endDateTime) {
                throw new Exception("End time must be after start time.");
            }
            
            // Update slot
            $stmt = $pdo->prepare("
                UPDATE schedule_slots 
                SET date = ?, start_time = ?, end_time = ?, max_appointments = ?, description = ?
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([
                $date,
                $startTime,
                $endTime,
                $maxAppointments,
                $description,
                $slotId,
                $technicianId
            ]);
            
            $actionMessage = "Availability slot updated successfully.";
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
    
    // Process delete availability slot
    if (isset($_POST['action']) && $_POST['action'] === 'deleteSlot') {
        try {
            $slotId = (int)clean_input($_POST['slot_id']);
            
            // Check if slot belongs to the technician
            $stmt = $pdo->prepare("
                SELECT id FROM schedule_slots 
                WHERE id = ? AND provider_id = ?
            ");
            $stmt->execute([$slotId, $technicianId]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid slot ID.");
            }
            
            // Delete the slot without checking for bookings
            // This avoids the schedule_slot_id error
            $stmt = $pdo->prepare("
                DELETE FROM schedule_slots 
                WHERE id = ? AND provider_id = ?
            ");
            
            $stmt->execute([$slotId, $technicianId]);
            
            if ($stmt->rowCount() > 0) {
                $actionMessage = "Availability slot deleted successfully.";
                $actionType = "success";
            } else {
                $actionMessage = "No availability slot was deleted.";
                $actionType = "warning";
            }
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
               u.status as user_status
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
} catch (PDOException $e) {
    error_log("Database error fetching technician data: " . $e->getMessage());
    header('Location: technicians.php?error=db');
    exit;
}

// Get availability slots
$availabilitySlots = [];
$filter = isset($_GET['filter']) ? clean_input($_GET['filter']) : 'upcoming';

try {
    $query = "
        SELECT s.*, 
               (SELECT COUNT(*) FROM bookings WHERE provider_id = s.provider_id AND booking_date = s.date 
                AND TIME(booking_time) BETWEEN s.start_time AND s.end_time) as current_bookings
        FROM schedule_slots s
        WHERE s.provider_id = ?
    ";
    
    // Add filter condition
    if ($filter === 'upcoming') {
        $query .= " AND s.date >= CURDATE()";
    } elseif ($filter === 'past') {
        $query .= " AND s.date < CURDATE()";
    }
    
    $query .= " ORDER BY s.date ASC, s.start_time ASC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$technicianId]);
    $availabilitySlots = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching availability slots: " . $e->getMessage());
    // Check if the error is about schedule_slot_id
    if (stripos($e->getMessage(), "schedule_slot_id") !== false) {
        $availabilitySlots = []; // Empty array if there's an error
    }
}

// Group slots by date for better display
$slotsByDate = [];
foreach ($availabilitySlots as $slot) {
    $slotsByDate[$slot['date']][] = $slot;
}

// Get active bookings count
$activeBookingsCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM bookings 
        WHERE provider_id = ? AND status IN ('pending', 'confirmed')
    ");
    
    $stmt->execute([$technicianId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        $activeBookingsCount = $result['count'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching active bookings count: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technician Availability - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Flatpickr for Date/Time Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        /* Date header style */
        .date-header {
            font-size: 1.1rem;
            font-weight: 600;
            margin: 1.5rem 0 1rem;
            color: var(--text-color);
            display: flex;
            align-items: center;
            padding-bottom: 0.5rem;
        }
        
        .date-header i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        /* Slot card styles */
        .slot-card {
            background-color: var(--card-bg);
            border-radius: 0.5rem;
            box-shadow: 0 0.125rem 0.25rem var(--shadow-color);
            padding: 1.25rem;
            margin-bottom: 1.25rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .slot-time {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .slot-meta {
            display: flex;
            margin-bottom: 0.75rem;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .slot-meta-item {
            margin-right: 1.5rem;
            display: flex;
            align-items: center;
        }
        
        .slot-meta-item i {
            margin-right: 0.5rem;
        }
        
        .slot-status {
            background-color: rgba(var(--bs-light-rgb), 0.1);
            padding: 0.5rem;
            border-radius: 0.25rem;
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .slot-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .status-pill {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        /* Add availability form */
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
        
        /* Filter buttons */
        .filter-tabs .nav-link {
            padding: 0.5rem 1rem;
            border-radius: 0.3rem;
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        .filter-tabs .nav-link.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .filter-tabs .nav-link:not(.active) {
            background-color: var(--card-bg);
            color: var(--text-color);
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
        
        .status-badge.full {
            background-color: rgba(220, 53, 69, 0.2);
            color: #fa5252;
        }
        
        .status-badge.available {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.limited {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
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
        
        /* Calendar icon for date headers */
        .date-header-icon {
            color: var(--primary-color);
            margin-right: 0.5rem;
        }
        
        /* Past date style */
        .past-date .date-header {
            color: var(--text-muted);
        }
        
        .past-date .slot-card {
            border-left-color: var(--text-muted);
            opacity: 0.7;
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
                
                <a href="#" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSlotModal">
                    <i class="fas fa-plus me-2"></i>Add Availability
                </a>
            </div>
            
            <?php if (!empty($actionMessage)): ?>
            <div class="alert alert-<?php echo $actionType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $actionMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php 
            // Check for database error with schedule_slot_id
            try {
                $test = $pdo->query("SELECT * FROM bookings WHERE provider_id = {$technicianId} LIMIT 1");
                $test->execute();
            } catch (PDOException $e) {
                if (stripos($e->getMessage(), "schedule_slot_id") !== false) {
                    echo '<div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                        Database error: SQLSTATE[42S22]: Column not found: 1054 Unknown column \'schedule_slot_id\' in \'where clause\'
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>';
                }
            }
            ?>
            
            <!-- Technician Info -->
            <div class="technician-info mb-4">
                <div class="technician-avatar">
                    <img src="<?php echo !empty($technicianData['profile_image']) ? '../profile_images/' . basename($technicianData['profile_image']) : '../default.png'; ?>" alt="<?php echo htmlspecialchars($technicianData['first_name']); ?>">
                </div>
                <div>
                    <div class="technician-name">
                        <?php echo htmlspecialchars($technicianData['first_name'] . ' ' . $technicianData['last_name']); ?>
                    </div>
                    <div class="technician-meta">
                        <div class="me-3">
                            <i class="fas fa-calendar-check me-1 text-primary"></i>
                            <?php echo count($availabilitySlots); ?> Availability Slots
                        </div>
                        <div>
                            <i class="fas fa-bookmark me-1 text-warning"></i>
                            <?php echo $activeBookingsCount; ?> Active Bookings
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Tabs -->
            <div class="filter-tabs mb-4">
                <ul class="nav nav-pills">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'upcoming' ? 'active' : ''; ?>" href="technician-availability.php?id=<?php echo $technicianId; ?>&filter=upcoming">
                            <i class="fas fa-calendar-alt me-2"></i>Upcoming
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'past' ? 'active' : ''; ?>" href="technician-availability.php?id=<?php echo $technicianId; ?>&filter=past">
                            <i class="fas fa-history me-2"></i>Past
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="technician-availability.php?id=<?php echo $technicianId; ?>&filter=all">
                            <i class="fas fa-list me-2"></i>All
                        </a>
                    </li>
                </ul>
            </div>
            
            <!-- Availability Slots -->
            <?php if (empty($slotsByDate)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <h3 class="empty-state-message">No availability slots found</h3>
                <p class="empty-state-description">
                    <?php if ($filter === 'upcoming'): ?>
                    This technician doesn't have any upcoming availability slots. Add one to allow customers to book appointments.
                    <?php elseif ($filter === 'past'): ?>
                    No past availability slots found for this technician.
                    <?php else: ?>
                    No availability slots found for this technician.
                    <?php endif; ?>
                </p>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSlotModal">
                    <i class="fas fa-plus me-2"></i>Add Availability
                </button>
            </div>
            <?php else: ?>
                <?php 
                // Get today's date for comparison
                $today = new DateTime();
                $today->setTime(0, 0, 0);
                
                foreach ($slotsByDate as $date => $slots): 
                    $dateObj = new DateTime($date);
                    $isPast = $dateObj < $today;
                    $dateClass = $isPast ? 'past-date' : '';
                    $dayName = date('l', strtotime($date));
                    $monthDay = date('F j', strtotime($date));
                    $year = date('Y', strtotime($date));
                ?>
                <div class="<?php echo $dateClass; ?>">
                    <div class="date-header">
                        <i class="fas fa-calendar-day"></i>
                        <?php echo "{$dayName}, {$monthDay}, {$year}"; ?>
                    </div>
                    
                    <?php foreach ($slots as $slot): 
                        // Calculate slots available
                        $slotsBooked = (int)$slot['current_bookings'];
                        $slotsTotal = (int)$slot['max_appointments'];
                        $slotsAvailable = max(0, $slotsTotal - $slotsBooked);
                        
                        // Format times in 12-hour format
                        $startTime = date('h:i A', strtotime($slot['start_time']));
                        $endTime = date('h:i A', strtotime($slot['end_time']));
                        
                        // Calculate duration in hours
                        $start = new DateTime($slot['start_time']);
                        $end = new DateTime($slot['end_time']);
                        $interval = $start->diff($end);
                        $hours = $interval->h + ($interval->days * 24);
                        if ($interval->i > 0) {
                            $hours = $hours . ".5";
                        }
                    ?>
                    <div class="slot-card">
                        <div class="slot-time"><?php echo "{$startTime} - {$endTime}"; ?></div>
                        
                        <div class="slot-meta">
                            <div class="slot-meta-item">
                                <i class="fas fa-clock"></i> <?php echo $hours; ?> hours
                            </div>
                            <div class="slot-meta-item">
                                <i class="fas fa-users"></i> Max: <?php echo $slotsTotal; ?> bookings
                            </div>
                        </div>
                        
                        <div class="slot-status">
                            <div>
                                <i class="fas fa-book me-1"></i> 
                                <?php echo "{$slotsBooked} / {$slotsTotal} booked"; ?>
                            </div>
                            <div class="status-pill">
                                Available
                            </div>
                        </div>
                        
                        <div class="slot-actions">
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editSlotModal" 
                                data-slot-id="<?php echo $slot['id']; ?>"
                                data-date="<?php echo $slot['date']; ?>"
                                data-start-time="<?php echo $slot['start_time']; ?>"
                                data-end-time="<?php echo $slot['end_time']; ?>"
                                data-max-appointments="<?php echo $slot['max_appointments']; ?>"
                                data-description="<?php echo htmlspecialchars($slot['description'] ?? ''); ?>">
                                <i class="fas fa-edit me-1"></i>Edit
                            </button>
                            
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteSlotModal" 
                                data-slot-id="<?php echo $slot['id']; ?>"
                                data-date="<?php echo $slot['date']; ?>"
                                data-time="<?php echo $startTime . ' - ' . $endTime; ?>"
                                data-start-time="<?php echo $slot['start_time']; ?>"
                                data-end-time="<?php echo $slot['end_time']; ?>"
                                data-bookings="<?php echo $slotsBooked; ?>">
                                <i class="fas fa-trash-alt me-1"></i>Delete
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Slot Modal -->
    <div class="modal fade" id="addSlotModal" tabindex="-1" aria-labelledby="addSlotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addSlotModalLabel">Add Availability Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="addSlot">
                        
                        <div class="mb-3">
                            <label for="date" class="form-label">Date</label>
                            <input type="text" class="form-control datepicker" id="date" name="date" placeholder="Select date" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="start_time" class="form-label">Start Time</label>
                                <input type="text" class="form-control timepicker" id="start_time" name="start_time" placeholder="Start time" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_time" class="form-label">End Time</label>
                                <input type="text" class="form-control timepicker" id="end_time" name="end_time" placeholder="End time" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="max_appointments" class="form-label">Maximum Appointments</label>
                            <input type="number" class="form-control" id="max_appointments" name="max_appointments" min="1" value="1" required>
                            <div class="form-text">Maximum number of bookings allowed in this time slot.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="description" name="description" rows="3" placeholder="Add any notes about this availability slot"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Slot</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Slot Modal -->
    <div class="modal fade" id="editSlotModal" tabindex="-1" aria-labelledby="editSlotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editSlotModalLabel">Edit Availability Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="editSlot">
                        <input type="hidden" name="slot_id" id="edit_slot_id">
                        
                        <div class="mb-3">
                            <label for="edit_date" class="form-label">Date</label>
                            <input type="text" class="form-control datepicker" id="edit_date" name="date" placeholder="Select date" required>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_start_time" class="form-label">Start Time</label>
                                <input type="text" class="form-control timepicker" id="edit_start_time" name="start_time" placeholder="Start time" required>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_end_time" class="form-label">End Time</label>
                                <input type="text" class="form-control timepicker" id="edit_end_time" name="end_time" placeholder="End time" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_max_appointments" class="form-label">Maximum Appointments</label>
                            <input type="number" class="form-control" id="edit_max_appointments" name="max_appointments" min="1" required>
                            <div class="form-text">Maximum number of bookings allowed in this time slot.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_description" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="3" placeholder="Add any notes about this availability slot"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Slot</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete Slot Modal -->
    <div class="modal fade" id="deleteSlotModal" tabindex="-1" aria-labelledby="deleteSlotModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteSlotModalLabel">Delete Availability Slot</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete this availability slot?</p>
                    <div class="alert alert-info">
                        <strong>Date:</strong> <span id="delete_date"></span><br>
                        <strong>Time:</strong> <span id="delete_time"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="post">
                        <input type="hidden" name="action" value="deleteSlot">
                        <input type="hidden" name="slot_id" id="delete_slot_id">
                        <input type="hidden" name="date" id="delete_slot_date">
                        <input type="hidden" name="start_time" id="delete_slot_start_time">
                        <input type="hidden" name="end_time" id="delete_slot_end_time">
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr for Date/Time Picker -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
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
            
            // Initialize Flatpickr for date picker
            flatpickr(".datepicker", {
                dateFormat: "Y-m-d",
                minDate: "today",
                altInput: true,
                altFormat: "F j, Y"
            });
            
            // Initialize Flatpickr for time picker
            flatpickr(".timepicker", {
                enableTime: true,
                noCalendar: true,
                dateFormat: "H:i:S",
                time_24hr: true,
                minuteIncrement: 15
            });
            
            // Handle edit slot modal
            const editSlotModal = document.getElementById('editSlotModal');
            if (editSlotModal) {
                editSlotModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const slotId = button.getAttribute('data-slot-id');
                    const date = button.getAttribute('data-date');
                    const startTime = button.getAttribute('data-start-time');
                    const endTime = button.getAttribute('data-end-time');
                    const maxAppointments = button.getAttribute('data-max-appointments');
                    const description = button.getAttribute('data-description');
                    
                    document.getElementById('edit_slot_id').value = slotId;
                    
                    // Re-initialize flatpickr instances with values
                    flatpickr("#edit_date", {
                        dateFormat: "Y-m-d",
                        minDate: "today",
                        altInput: true,
                        altFormat: "F j, Y",
                        defaultDate: date
                    });
                    
                    flatpickr("#edit_start_time", {
                        enableTime: true,
                        noCalendar: true,
                        dateFormat: "H:i:S",
                        time_24hr: true,
                        minuteIncrement: 15,
                        defaultDate: startTime
                    });
                    
                    flatpickr("#edit_end_time", {
                        enableTime: true,
                        noCalendar: true,
                        dateFormat: "H:i:S",
                        time_24hr: true,
                        minuteIncrement: 15,
                        defaultDate: endTime
                    });
                    
                    document.getElementById('edit_max_appointments').value = maxAppointments;
                    document.getElementById('edit_description').value = description || '';
                });
            }
            
            // Handle delete slot modal
            const deleteSlotModal = document.getElementById('deleteSlotModal');
            if (deleteSlotModal) {
                deleteSlotModal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    
                    const slotId = button.getAttribute('data-slot-id');
                    const date = button.getAttribute('data-date');
                    const time = button.getAttribute('data-time');
                    const startTime = button.getAttribute('data-start-time');
                    const endTime = button.getAttribute('data-end-time');
                    
                    document.getElementById('delete_slot_id').value = slotId;
                    document.getElementById('delete_date').textContent = date;
                    document.getElementById('delete_time').textContent = time;
                    
                    // Add date and time values for direct deletion
                    if (document.getElementById('delete_slot_date')) {
                        document.getElementById('delete_slot_date').value = date;
                    }
                    if (document.getElementById('delete_slot_start_time')) {
                        document.getElementById('delete_slot_start_time').value = startTime || '';
                    }
                    if (document.getElementById('delete_slot_end_time')) {
                        document.getElementById('delete_slot_end_time').value = endTime || '';
                    }
                });
            }
        });
    </script>
</body>
</html>