<?php
session_start();
$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a customer
if (!$loggedIn || $userRole !== 'customer') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../conn.php';

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Get user information
$userData = null;
$profileImage = '../images/default.png';
$successMessage = '';
$errorMessage = '';

try {
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path safely
    if ($userData && !empty($userData['profile_image'])) {
        $profileImage = $userData['profile_image'];
        // Check if it's an external URL
        if (preg_match('/^https?:\/\//', $profileImage)) {
            // External URLs are safe to use as-is
        } else {
            // Local file paths need secure handling
            $profileImage = str_replace('../', '', $profileImage); // Remove any traversal attempts
            $profileImage = '../' . ltrim($profileImage, '/');
            
            // Verify path validity
            if (!file_exists($profileImage)) {
                $profileImage = '../images/default.png';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $profileImage = '../images/default.png';
}

// Get recent notifications
$notifications = [];
try {
    $notifQuery = "
        SELECT id, message, device_type, created_at 
        FROM notifications
        WHERE customer_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching notifications: " . $e->getMessage());
}

// Get repair ID from URL
$repairId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (empty($repairId)) {
    header("Location: my-repairs.php?error=missing_id");
    exit();
}

// Process repair cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_repair'])) {
    $reason = isset($_POST['cancel_reason']) ? clean_input($_POST['cancel_reason']) : '';
    
    if (empty($reason)) {
        $errorMessage = "Please provide a reason for cancellation.";
    } else {
        try {
            // First check if repair belongs to user and is in a cancellable state
            $checkQuery = "
                SELECT id, status 
                FROM repairs 
                WHERE id = ? AND customer_id = ? 
                AND status IN ('pending', 'scheduled', 'awaiting_parts')
            ";
            $stmt = $pdo->prepare($checkQuery);
            $stmt->execute([$repairId, $userId]);
            $repair = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$repair) {
                $errorMessage = "This repair cannot be cancelled or does not belong to you.";
            } else {
                // Update repair status
                $updateQuery = "
                    UPDATE repairs 
                    SET status = 'cancelled', 
                        cancellation_reason = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ";
                $stmt = $pdo->prepare($updateQuery);
                $stmt->execute([$reason, $repairId]);
                
                // Add a note to repair history
                $historyQuery = "
                    INSERT INTO repair_history (repair_id, status, notes, created_by, created_at)
                    VALUES (?, 'cancelled', ?, ?, NOW())
                ";
                $stmt = $pdo->prepare($historyQuery);
                $stmt->execute([$repairId, "Cancelled by customer. Reason: $reason", $userId]);
                
                $successMessage = "Repair has been successfully cancelled.";
            }
        } catch (PDOException $e) {
            error_log("Database error cancelling repair: " . $e->getMessage());
            $errorMessage = "An error occurred while processing your request. Please try again later.";
        }
    }
}

// Get repair details
$repair = null;
$repairHistory = [];
$repairImages = [];
$isValidRepair = false;
$deviceInfo = null;
$technician = null;
$status = '';

try {
    // Check if the repairs table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'repairs'");
    $tableExists = $checkTable->rowCount() > 0;
    
    if (!$tableExists) {
        // Create sample data for demonstration
        $repair = [
            'id' => $repairId,
            'customer_id' => $userId,
            'device_id' => 1,
            'device_name' => 'Sample Device',
            'device_type' => 'Smartphone',
            'device_brand' => 'SampleBrand',
            'device_model' => 'Model X',
            'serial_number' => 'SN123456789',
            'issue_description' => 'Screen is cracked and touch functionality is not working properly. Device also has battery issues and shuts down unexpectedly.',
            'diagnosis' => 'Screen assembly needs replacement. Battery capacity is at 65% and should be replaced for optimal performance.',
            'status' => 'in_progress',
            'estimated_cost' => 150.00,
            'actual_cost' => null,
            'estimated_completion' => date('Y-m-d', strtotime('+5 days')),
            'completion_date' => null,
            'technician_id' => 5,
            'technician_name' => 'John Technician',
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
        ];
        
        // Sample repair history
        $repairHistory = [
            [
                'id' => 1,
                'repair_id' => $repairId,
                'status' => 'pending',
                'notes' => 'Repair request submitted.',
                'created_by' => $userId,
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))
            ],
            [
                'id' => 2,
                'repair_id' => $repairId,
                'status' => 'scheduled',
                'notes' => 'Device scheduled for intake examination.',
                'created_by' => 5,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ],
            [
                'id' => 3,
                'repair_id' => $repairId,
                'status' => 'in_progress',
                'notes' => 'Diagnosis complete. Screen and battery replacement required. Parts ordered.',
                'created_by' => 5,
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ]
        ];
        
        $isValidRepair = true;
        $status = $repair['status'];
    } else {
        // Check if this repair belongs to current user
        $repairQuery = "
            SELECT r.*, 
                  COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'Unknown') as technician_name
            FROM repairs r
            LEFT JOIN users u ON r.technician_id = u.id
            WHERE r.id = ? AND r.customer_id = ?
        ";
        $stmt = $pdo->prepare($repairQuery);
        $stmt->execute([$repairId, $userId]);
        $repair = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($repair) {
            $isValidRepair = true;
            $status = $repair['status'];
            
            // Check if devices table exists
            $checkDevicesTable = $pdo->query("SHOW TABLES LIKE 'devices'");
            $devicesTableExists = $checkDevicesTable->rowCount() > 0;
            
            if ($devicesTableExists && isset($repair['device_id']) && !empty($repair['device_id'])) {
                // Get device details
                $deviceQuery = "SELECT * FROM devices WHERE id = ?";
                $stmt = $pdo->prepare($deviceQuery);
                $stmt->execute([$repair['device_id']]);
                $deviceInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Check if repair_history table exists
            $checkHistoryTable = $pdo->query("SHOW TABLES LIKE 'repair_history'");
            $historyTableExists = $checkHistoryTable->rowCount() > 0;
            
            if ($historyTableExists) {
                // Get repair history
                $historyQuery = "
                    SELECT rh.*, 
                           COALESCE(u.name, CONCAT(u.first_name, ' ', u.last_name), u.username, 'System') as created_by_name
                    FROM repair_history rh
                    LEFT JOIN users u ON rh.created_by = u.id
                    WHERE rh.repair_id = ?
                    ORDER BY rh.created_at ASC
                ";
                $stmt = $pdo->prepare($historyQuery);
                $stmt->execute([$repairId]);
                $repairHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            // Check if repair_images table exists
            $checkImagesTable = $pdo->query("SHOW TABLES LIKE 'repair_images'");
            $imagesTableExists = $checkImagesTable->rowCount() > 0;
            
            if ($imagesTableExists) {
                // Get repair images
                $imagesQuery = "SELECT * FROM repair_images WHERE repair_id = ? ORDER BY created_at ASC";
                $stmt = $pdo->prepare($imagesQuery);
                $stmt->execute([$repairId]);
                $repairImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching repair details: " . $e->getMessage());
    $errorMessage = "Failed to load repair details. Please try again later.";
}

// Function to get human-readable repair status
function getStatusLabel($status) {
    $statusLabels = [
        'pending' => '<span class="badge bg-warning text-dark">Pending</span>',
        'scheduled' => '<span class="badge bg-info">Scheduled</span>',
        'in_progress' => '<span class="badge bg-primary">In Progress</span>',
        'awaiting_parts' => '<span class="badge bg-secondary">Awaiting Parts</span>',
        'completed' => '<span class="badge bg-success">Completed</span>',
        'cancelled' => '<span class="badge bg-danger">Cancelled</span>',
        'on_hold' => '<span class="badge bg-dark">On Hold</span>'
    ];
    
    return isset($statusLabels[$status]) ? $statusLabels[$status] : '<span class="badge bg-light text-dark">Unknown</span>';
}

// Function to format cost
function formatCost($cost) {
    if (is_null($cost) || empty($cost)) {
        return 'Not available';
    }
    return '$' . number_format((float)$cost, 2);
}

// Function to format date
function formatDate($date) {
    if (empty($date)) {
        return 'Not set';
    }
    return date('M d, Y', strtotime($date));
}

// Function to get device icon
function getDeviceIcon($deviceType) {
    $deviceType = strtolower($deviceType ?? '');
    $icon = 'fa-mobile-alt'; // Default icon
    
    if (strpos($deviceType, 'laptop') !== false) {
        $icon = 'fa-laptop';
    } elseif (strpos($deviceType, 'desktop') !== false || strpos($deviceType, 'computer') !== false) {
        $icon = 'fa-desktop';
    } elseif (strpos($deviceType, 'tablet') !== false) {
        $icon = 'fa-tablet-alt';
    } elseif (strpos($deviceType, 'watch') !== false) {
        $icon = 'fa-clock';
    } elseif (strpos($deviceType, 'tv') !== false) {
        $icon = 'fa-tv';
    } elseif (strpos($deviceType, 'game') !== false || strpos($deviceType, 'console') !== false) {
        $icon = 'fa-gamepad';
    } elseif (strpos($deviceType, 'printer') !== false) {
        $icon = 'fa-print';
    } elseif (strpos($deviceType, 'camera') !== false) {
        $icon = 'fa-camera';
    } elseif (strpos($deviceType, 'speaker') !== false || strpos($deviceType, 'audio') !== false) {
        $icon = 'fa-volume-up';
    }
    
    return $icon;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Repair Details - FixItNow</title>
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
            --sidebar-bg: #f8f9fa;         /* Sidebar background */
            --sidebar-active: #e9ecef;     /* Sidebar active item */
            --danger-color: #dc3545;       /* Danger color */
            --danger-hover: #bb2d3b;       /* Danger hover color */
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
            --sidebar-bg: #181818;         /* Sidebar background */
            --sidebar-active: #2c2c2c;     /* Sidebar active item */
            --danger-color: #dc3545;       /* Danger color */
            --danger-hover: #bb2d3b;       /* Danger hover color */
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
        
        /* Dashboard Specific Styles */
        .dashboard-wrapper {
            display: flex;
            min-height: calc(100vh - 76px); /* Header height */
        }
        
        .sidebar {
            width: 250px;
            background-color: var(--sidebar-bg);
            padding: 1.5rem 1rem;
            border-right: 1px solid var(--border-color);
            transition: background-color 0.3s ease;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin-bottom: 0.5rem;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            text-decoration: none;
            color: var(--text-color);
            transition: all 0.2s ease;
        }
        
        .sidebar-menu a:hover {
            background-color: var(--sidebar-active);
        }
        
        .sidebar-menu a.active {
            background-color: var(--primary-color);
            color: var(--header-text);
        }
        
        .sidebar-menu i {
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }
        
        .dashboard-content {
            flex: 1;
            padding: 2rem;
            overflow-y: auto;
        }
        
        /* Content Card Styles */
        .content-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            overflow: hidden;
            box-shadow: 0 0.5rem 1.5rem var(--shadow-color);
            transition: all 0.3s ease;
            margin-bottom: 2rem;
        }
        
        .content-card .card-header {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        
        .content-card .card-body {
            padding: 2rem;
        }
        
        /* Repair Header */
        .repair-header {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .repair-icon {
            width: 64px;
            height: 64px;
            border-radius: 1rem;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1.5rem;
            font-size: 2rem;
        }
        
        .repair-title h3 {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .repair-status {
            margin-left: auto;
        }
        
        /* Info Section */
        .info-section {
            margin-bottom: 2.5rem;
        }
        
        .info-section h4 {
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
        }
        
        .info-section h4 i {
            margin-right: 0.75rem;
            color: var(--primary-color);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1.5rem;
        }
        
        .info-item {
            margin-bottom: 1rem;
        }
        
        .info-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-weight: 500;
        }
        
        /* Diagnosis Section */
        .diagnosis-section {
            background-color: var(--primary-light);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .tech-note {
            background-color: var(--accent-light);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        /* Timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
            margin-bottom: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: var(--border-color);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 1.5rem;
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 0.35rem;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: var(--primary-color);
            border: 2px solid var(--bg-color);
            z-index: 1;
        }
        
        .timeline-date {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .timeline-content {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: space-between;
        }
        
        .timeline-message {
            white-space: pre-line;
        }
        
        .timeline-user {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.5rem;
            text-align: right;
        }
        
        /* Images Gallery */
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .gallery-item {
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .gallery-item img {
            width: 100%;
            height: 150px;
            object-fit: cover;
        }
        
        /* Form Styles */
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            transition: all 0.2s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        /* Button Styles */
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-outline-primary {
            color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Modal Styles */
        .modal-content {
            background-color: var(--modal-bg);
            border-radius: 1rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 1rem 3rem var(--shadow-color);
        }
        
        .modal-header {
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        
        .modal-body {
            padding: 2rem;
        }
        
        .modal-footer {
            border-top: 1px solid var(--border-color);
            padding: 1.5rem;
        }
        
        /* Loading spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        
        .loading-spinner.show {
            display: flex;
        }
        
        /* Image Modal */
        .img-modal-content {
            max-width: 90vw;
            max-height: 90vh;
            border-radius: 0.5rem;
            overflow: hidden;
        }
        
        /* Action Buttons */
        .action-buttons {
            margin-top: 2rem;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }
        
        /* Not Found */
        .not-found {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .not-found-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        .not-found-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .not-found-message {
            color: var(--text-muted);
            margin-bottom: 2rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
                padding: 1rem;
            }
            
            .dashboard-wrapper {
                flex-direction: column;
            }
            
            .sidebar-menu {
                display: flex;
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .sidebar-menu li {
                margin-right: 0.5rem;
                margin-bottom: 0.5rem;
            }
            
            .repair-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .repair-status {
                margin-left: 0;
                margin-top: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .content-card .card-body {
                padding: 1.5rem;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .action-buttons .btn {
                width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .content-card .card-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="text-decoration-none">
                    <div class="logo-text">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                </a>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Notifications -->
                    <div class="dropdown me-3">
                        <button class="btn btn-dark position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if(count($notifications) > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo count($notifications); ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
                            <li><h6 class="dropdown-header">Notifications</h6></li>
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted">No new notifications</div></li>
                            <?php else: ?>
                                <?php foreach($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item" href="notifications.php">
                                        <div class="d-flex w-100 justify-content-between">
                                            <h6 class="mb-1"><?php echo htmlspecialchars($notification['message'] ?? 'New notification'); ?></h6>
                                            <small class="text-muted"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                        </div>
                                        <small class="text-muted"><?php echo htmlspecialchars($notification['device_type'] ?? ''); ?></small>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-center" href="notifications.php">View all notifications</a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    
                    <!-- Theme Toggle -->
                    <button class="theme-toggle-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon theme-icon-dark d-none"></i>
                        <i class="fas fa-sun theme-icon-light"></i>
                    </button>
                    
                    <!-- User Menu -->
                    <div class="dropdown">
                        <button class="btn btn-dark d-flex align-items-center" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32">
                            <span class="d-none d-md-inline">
                                <?php 
                                // Display either name or username, depending on what's available
                                if (isset($userData['name']) && !empty($userData['name'])) {
                                    echo htmlspecialchars($userData['name']);
                                } elseif (isset($userData['username']) && !empty($userData['username'])) {
                                    echo htmlspecialchars($userData['username']);
                                } elseif (isset($userData['first_name']) && !empty($userData['first_name'])) {
                                    echo htmlspecialchars($userData['first_name'] . ' ' . ($userData['last_name'] ?? ''));
                                } else {
                                    echo 'User';
                                }
                                ?>
                            </span>
                            <i class="fas fa-chevron-down ms-2 small"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Dashboard Layout -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar">
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tachometer-alt"></i> Dashboard
                    </a>
                </li>
                <li>
                    <a href="quotes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'quotes.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="my-repairs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-repairs.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tools"></i> My Repairs
                    </a>
                </li>
                <li>
                    <a href="new-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'new-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i> New Request
                    </a>
                </li>
                <li>
                    <a href="history.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'history.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> History
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> My Profile
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Back Button -->
            <div class="mb-4">
                <a href="my-repairs.php" class="btn btn-outline-primary">
                    <i class="fas fa-arrow-left me-2"></i> Back to My Repairs
                </a>
            </div>
            
            <!-- Error/Success Messages -->
            <?php if(!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $errorMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $successMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if(!$isValidRepair): ?>
                <!-- Repair Not Found -->
                <div class="content-card">
                    <div class="card-body">
                        <div class="not-found">
                            <div class="not-found-icon">
                                <i class="fas fa-search"></i>
                            </div>
                            <h3 class="not-found-title">Repair Not Found</h3>
                            <p class="not-found-message">
                                The repair you're looking for doesn't exist or you don't have permission to view it.
                            </p>
                            <a href="my-repairs.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-2"></i> Back to My Repairs
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Repair Header -->
                <div class="content-card">
                    <div class="card-body">
                        <div class="repair-header">
                            <div class="repair-icon">
                                <i class="fas <?php echo getDeviceIcon($repair['device_type'] ?? ''); ?>"></i>
                            </div>
                            <div class="repair-title">
                                <h3><?php echo htmlspecialchars($repair['device_name'] ?? 'Unknown Device'); ?></h3>
                                <div class="text-muted">Repair #<?php echo $repairId; ?></div>
                            </div>
                            <div class="repair-status">
                                <?php echo getStatusLabel($status); ?>
                            </div>
                        </div>
                        
                        <!-- Device Information -->
                        <div class="info-section">
                            <h4><i class="fas fa-info-circle"></i> Device Information</h4>
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Device Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['device_name'] ?? $deviceInfo['name'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Device Type</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['device_type'] ?? $deviceInfo['type'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Brand</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['device_brand'] ?? $deviceInfo['brand'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Model</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['device_model'] ?? $deviceInfo['model'] ?? 'Not specified'); ?></div>
                                </div>
                                <?php if(!empty($repair['serial_number']) || !empty($deviceInfo['serial_number'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Serial Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['serial_number'] ?? $deviceInfo['serial_number'] ?? ''); ?></div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Repair Information -->
                        <div class="info-section">
                            <h4><i class="fas fa-tools"></i> Repair Details</h4>
                            
                            <div class="mb-4">
                                <div class="info-label">Issue Description</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($repair['issue_description'] ?? 'No description provided')); ?></div>
                            </div>
                            
                            <?php if (!empty($repair['diagnosis'])): ?>
                            <div class="diagnosis-section mb-4">
                                <div class="info-label">Diagnosis</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($repair['diagnosis'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($repair['technician_notes'])): ?>
                            <div class="tech-note mb-4">
                                <div class="info-label">Technician Notes</div>
                                <div class="info-value"><?php echo nl2br(htmlspecialchars($repair['technician_notes'])); ?></div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="info-grid">
                                <div class="info-item">
                                    <div class="info-label">Status</div>
                                    <div class="info-value"><?php echo getStatusLabel($status); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Technician</div>
                                    <div class="info-value"><?php echo htmlspecialchars($repair['technician_name'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Estimated Cost</div>
                                    <div class="info-value"><?php echo formatCost($repair['estimated_cost'] ?? null); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Final Cost</div>
                                    <div class="info-value"><?php echo formatCost($repair['actual_cost'] ?? null); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Date Submitted</div>
                                    <div class="info-value"><?php echo formatDate($repair['created_at'] ?? ''); ?></div>
                                </div>
                                <div class="info-item">
                                    <div class="info-label">Estimated Completion</div>
                                    <div class="info-value"><?php echo formatDate($repair['estimated_completion'] ?? ''); ?></div>
                                </div>
                                <?php if (!empty($repair['completion_date'])): ?>
                                <div class="info-item">
                                    <div class="info-label">Date Completed</div>
                                    <div class="info-value"><?php echo formatDate($repair['completion_date']); ?></div>
                                </div>
                                <?php endif; ?>
                                <div class="info-item">
                                    <div class="info-label">Last Updated</div>
                                    <div class="info-value"><?php echo formatDate($repair['updated_at'] ?? ''); ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if(!empty($repairImages)): ?>
                        <!-- Device Images -->
                        <div class="info-section">
                            <h4><i class="fas fa-images"></i> Device Images</h4>
                            <div class="image-gallery">
                                <?php foreach($repairImages as $image): ?>
                                <div class="gallery-item" data-bs-toggle="modal" data-bs-target="#imageModal" data-src="<?php echo htmlspecialchars($image['image_path']); ?>">
                                    <img src="<?php echo htmlspecialchars($image['image_path']); ?>" alt="Device image">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if(!empty($repairHistory)): ?>
                        <!-- Repair History -->
                        <div class="info-section">
                            <h4><i class="fas fa-history"></i> Repair Timeline</h4>
                            <div class="timeline">
                                <?php foreach($repairHistory as $history): ?>
                                <div class="timeline-item">
                                    <div class="timeline-date"><?php echo date('F j, Y, g:i a', strtotime($history['created_at'])); ?></div>
                                    <div class="timeline-content">
                                        <div class="timeline-title">
                                            <span>Status changed to: <?php echo getStatusLabel($history['status']); ?></span>
                                        </div>
                                        <?php if(!empty($history['notes'])): ?>
                                        <div class="timeline-message"><?php echo nl2br(htmlspecialchars($history['notes'])); ?></div>
                                        <?php endif; ?>
                                        <div class="timeline-user">
                                            <span>By: <?php echo htmlspecialchars($history['created_by_name'] ?? 'System'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <a href="my-repairs.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left me-2"></i> Back to My Repairs
                            </a>
                            
                            <?php if (in_array($status, ['pending', 'scheduled', 'awaiting_parts'])): ?>
                            <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#cancelModal">
                                <i class="fas fa-times me-2"></i> Cancel Repair
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Cancel Repair Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelModalLabel">Cancel Repair Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="repair-details.php?id=<?php echo $repairId; ?>" method="post">
                    <div class="modal-body">
                        <p>Are you sure you want to cancel this repair request?</p>
                        <div class="mb-3">
                            <label for="cancel_reason" class="form-label">Reason for cancellation</label>
                            <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" required></textarea>
                            <div class="form-text">Please provide a brief explanation for cancelling this repair request.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="cancel_repair" class="btn btn-danger">Cancel Repair</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Image Modal -->
    <div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content img-modal-content">
                <img src="" class="img-fluid" id="modalImage">
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="py-4 bg-dark text-light">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
            <div class="mt-2">
                <a href="../terms.php" class="text-muted me-3">Terms of Service</a>
                <a href="../privacy.php" class="text-muted me-3">Privacy Policy</a>
                <a href="../contact.php" class="text-muted">Contact Us</a>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme toggler
        const themeToggle = document.getElementById('themeToggle');
        const htmlElement = document.documentElement;
        const darkIcon = document.querySelector('.theme-icon-dark');
        const lightIcon = document.querySelector('.theme-icon-light');
        
        // Check for saved theme preference or use the system preference
        const storedTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        
        // Set initial theme
        if (storedTheme === 'dark') {
            htmlElement.setAttribute('data-bs-theme', 'dark');
            darkIcon.classList.remove('d-none');
            lightIcon.classList.add('d-none');
        } else {
            htmlElement.setAttribute('data-bs-theme', 'light');
            lightIcon.classList.remove('d-none');
            darkIcon.classList.add('d-none');
        }
        
        // Toggle theme on button click
        themeToggle.addEventListener('click', () => {
            const currentTheme = htmlElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            htmlElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            if (newTheme === 'dark') {
                darkIcon.classList.remove('d-none');
                lightIcon.classList.add('d-none');
            } else {
                lightIcon.classList.remove('d-none');
                darkIcon.classList.add('d-none');
            }
        });
        
        // Image Modal
        const imageModal = document.getElementById('imageModal');
        if (imageModal) {
            imageModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const imageSrc = button.getAttribute('data-src');
                const modalImage = document.getElementById('modalImage');
                
                if (modalImage) {
                    modalImage.src = imageSrc;
                }
            });
        }
        
        // Loading spinner
        document.addEventListener('DOMContentLoaded', function() {
            const loadingSpinner = document.getElementById('loadingSpinner');
            
            // Hide spinner when page is loaded
            loadingSpinner.classList.remove('show');
            
            // Show spinner on form submissions
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    loadingSpinner.classList.add('show');
                });
            });
            
            // Show spinner when navigating away
            document.querySelectorAll('a:not([download]):not([data-bs-toggle])').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Don't show for same-page links or links with external targets
                    if (this.getAttribute('href').startsWith('#') || this.getAttribute('target') === '_blank') {
                        return;
                    }
                    
                    loadingSpinner.classList.add('show');
                });
            });
        });
    </script>
</body>
</html>