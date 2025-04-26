<?php
// Uncomment for debugging only
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// Performance optimizations
ini_set('memory_limit', '256M');
set_time_limit(30); // 30 seconds max execution time

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

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
    $userQuery = "SELECT * FROM users WHERE id = ? LIMIT 1";
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
            
            // Verify path validity and file existence
            $allowedPaths = ['uploads/profile_images/', 'images/'];
            $isAllowedPath = false;
            
            foreach ($allowedPaths as $path) {
                if (strpos($profileImage, '../' . $path) === 0) {
                    $isAllowedPath = true;
                    break;
                }
            }
            
            if (!$isAllowedPath || !file_exists($profileImage)) {
                $profileImage = '../images/default.png';
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching user data: " . $e->getMessage());
    $profileImage = '../images/default.png';
}

// Get recent notifications - with limit and pagination
$notifications = [];
$notificationCount = 0;

try {
    // First check if notifications table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'notifications'");
    if ($checkTable && $checkTable->rowCount() > 0) {
        // Get count of notifications with timeout protection
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE customer_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $stmt->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 second timeout
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $notificationCount = $result['count'] ?? 0;
        
        // Get recent notifications
        $stmt = $pdo->prepare("SELECT id, message, device_type, created_at FROM notifications 
                               WHERE customer_id = ? AND status = 'pending'
                               ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error fetching notifications: " . $e->getMessage());
}

// Process repair cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_repair'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security validation failed. Please try again.";
    } else {
        $repairId = isset($_POST['repair_id']) ? intval($_POST['repair_id']) : 0;
        $reason = isset($_POST['cancel_reason']) ? clean_input($_POST['cancel_reason']) : '';
        
        if (empty($repairId)) {
            $errorMessage = "Invalid repair selection.";
        } else if (empty($reason)) {
            $errorMessage = "Please provide a reason for cancellation.";
        } else {
            try {
                // Check if repairs table exists
                $checkTable = $pdo->query("SHOW TABLES LIKE 'repairs'");
                if ($checkTable && $checkTable->rowCount() > 0) {
                    // First check if repair belongs to user and is in a cancellable state
                    $stmt = $pdo->prepare("SELECT id, status FROM repairs 
                                         WHERE id = ? AND customer_id = ? 
                                         AND status IN ('pending', 'scheduled', 'awaiting_parts') LIMIT 1");
                    $stmt->execute([$repairId, $userId]);
                    $repair = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$repair) {
                        $errorMessage = "This repair cannot be cancelled or does not belong to you.";
                    } else {
                        // Begin transaction
                        $pdo->beginTransaction();
                        
                        try {
                            // Update repair status
                            $stmt = $pdo->prepare("UPDATE repairs 
                                                 SET status = 'cancelled', 
                                                     cancellation_reason = ?,
                                                     updated_at = NOW()
                                                 WHERE id = ?");
                            $stmt->execute([$reason, $repairId]);
                            
                            // Check if repair_history table exists before inserting
                            $checkHistoryTable = $pdo->query("SHOW TABLES LIKE 'repair_history'");
                            if ($checkHistoryTable && $checkHistoryTable->rowCount() > 0) {
                                // Add a note to repair history
                                $stmt = $pdo->prepare("INSERT INTO repair_history 
                                                     (repair_id, status, notes, created_by, created_at)
                                                     VALUES (?, 'cancelled', ?, ?, NOW())");
                                $stmt->execute([$repairId, "Cancelled by customer. Reason: $reason", $userId]);
                            }
                            
                            // Commit transaction
                            $pdo->commit();
                            $successMessage = "Repair has been successfully cancelled.";
                            
                            // Log the cancellation
                            error_log("Repair #{$repairId} cancelled by user #{$userId}. Reason: {$reason}");
                        } catch (Exception $e) {
                            // Rollback transaction on error
                            $pdo->rollBack();
                            throw $e;
                        }
                    }
                } else {
                    // Create repairs table if it doesn't exist
                    try {
                        // Check if the SQL file exists before trying to read it
                        $sqlFilePath = '../sql/create_repairs_table.sql';
                        if (file_exists($sqlFilePath)) {
                            $createTableQuery = file_get_contents($sqlFilePath);
                            $pdo->exec($createTableQuery);
                            $errorMessage = "Repair system has been initialized. Please try again.";
                        } else {
                            $errorMessage = "Repair system configuration files not found. Please contact support.";
                        }
                    } catch (Exception $e) {
                        error_log("Error creating repairs table: " . $e->getMessage());
                        $errorMessage = "Failed to initialize repair system. Please contact support.";
                    }
                }
            } catch (PDOException $e) {
                error_log("Database error cancelling repair: " . $e->getMessage());
                $errorMessage = "An error occurred while processing your request. Please try again later.";
            }
        }
    }
}

// Pagination setup
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 10; // Number of repairs per page
$offset = ($page - 1) * $perPage;

// Get repairs with filters
$status = isset($_GET['status']) ? clean_input($_GET['status']) : '';
$search = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$dateFrom = isset($_GET['date_from']) ? clean_input($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? clean_input($_GET['date_to']) : '';

// Check if the repairs table exists before querying
$repairs = [];
$totalRepairs = 0;
$totalPages = 1;

try {
    // First check if the repairs table exists
    $checkTable = $pdo->query("SHOW TABLES LIKE 'repairs'");
    $tableExists = ($checkTable && $checkTable->rowCount() > 0);
    
    if (!$tableExists) {
        // Create sample data for demonstration if table doesn't exist
        $repairs = [
            [
                'id' => 1,
                'device_name' => 'Demo Phone',
                'device_type' => 'Mobile Phone',
                'issue_description' => 'Screen cracked and not responding to touch',
                'status' => 'pending',
                'technician_name' => 'Not assigned',
                'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
                'updated_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
            ]
        ];
        $totalRepairs = count($repairs);
        $totalPages = 1;
    } else {
        // Build base query with optimized joins
        $baseQuery = "
            FROM repairs r
            LEFT JOIN users u ON r.technician_id = u.id
            LEFT JOIN devices d ON r.device_id = d.id
            WHERE r.customer_id = ?
        ";
        
        $queryParams = [$userId];

        // Add filters
        $filterQuery = "";
        
        if (!empty($status)) {
            $filterQuery .= " AND r.status = ?";
            $queryParams[] = $status;
        }

        if (!empty($search)) {
            $filterQuery .= " AND (
                COALESCE(d.name, r.device_name, '') LIKE ? OR 
                COALESCE(d.type, r.device_type, '') LIKE ? OR 
                r.issue_description LIKE ?
            )";
            $searchTerm = "%$search%";
            $queryParams[] = $searchTerm;
            $queryParams[] = $searchTerm;
            $queryParams[] = $searchTerm;
        }

        if (!empty($dateFrom)) {
            $filterQuery .= " AND r.created_at >= ?";
            $queryParams[] = $dateFrom . " 00:00:00";
        }

        if (!empty($dateTo)) {
            $filterQuery .= " AND r.created_at <= ?";
            $queryParams[] = $dateTo . " 23:59:59";
        }

        // Get total count for pagination with a timeout limit
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total " . $baseQuery . $filterQuery);
            $stmt->setAttribute(PDO::ATTR_TIMEOUT, 5); // 5 second timeout
            $stmt->execute($queryParams);
            $totalResult = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalRepairs = $totalResult['total'] ?? 0;
            $totalPages = max(1, ceil($totalRepairs / $perPage));
        } catch (PDOException $e) {
            error_log("Error getting repair count: " . $e->getMessage());
            // Default values if count query fails
            $totalRepairs = 0;
            $totalPages = 1;
        }
        
        // Only execute main query if we have repairs
        if ($totalRepairs > 0) {
            // Build full query with pagination
            $mainQuery = "
                SELECT r.*, 
                       COALESCE(d.name, r.device_name, 'Unknown') as device_name,
                       COALESCE(d.type, r.device_type, 'Unknown') as device_type,
                       CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as technician_name
                " . $baseQuery . $filterQuery . "
                ORDER BY r.created_at DESC 
                LIMIT ?, ?
            ";
            
            $mainParams = $queryParams;
            $mainParams[] = $offset;
            $mainParams[] = $perPage;
            
            // Execute the query with a timeout
            try {
                $stmt = $pdo->prepare($mainQuery);
                $stmt->setAttribute(PDO::ATTR_TIMEOUT, 10); // 10 second timeout
                $stmt->execute($mainParams);
                $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Error fetching repairs: " . $e->getMessage());
                // Fallback to an empty array
                $repairs = [];
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching repairs: " . $e->getMessage());
    
    // Create sample data for demonstration in case of error
    $repairs = [
        [
            'id' => 1,
            'device_name' => 'Demo Phone',
            'device_type' => 'Mobile Phone',
            'issue_description' => 'Screen cracked and not responding to touch',
            'status' => 'pending',
            'technician_name' => 'Not assigned',
            'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
            'updated_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ]
    ];
    $totalRepairs = count($repairs);
    $totalPages = 1;
}

// Function to get human-readable repair status with enhanced styling
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

// Determine user's preferred language
$userLanguage = $userData['language'] ?? 'en';

// Load language file if it exists (for multilingual support)
$translations = [];
$langFile = "../lang/{$userLanguage}.php";
if (file_exists($langFile)) {
    include_once($langFile);
} else {
   // include_once("../lang/en.php"); // Default to English
}

// Function to translate text
function __($key, $default = '') {
    global $translations;
    return isset($translations[$key]) ? $translations[$key] : ($default ?: $key);
}

// Create English language file if it doesn't exist
if (!file_exists("../lang/en.php")) {
    try {
        if (!is_dir("../lang")) {
            mkdir("../lang", 0755, true);
        }
        
        $englishTranslations = [
            'dashboard' => 'Dashboard',
            'my_repairs' => 'My Repairs',
            'my_quotes' => 'My Quotes',
            'my_profile' => 'My Profile',
            'new_request' => 'New Request',
            'new_repair_request' => 'New Repair Request',
            'history' => 'History',
            'notifications' => 'Notifications',
            'settings' => 'Settings',
            'logout' => 'Logout',
            'view_details' => 'View Details',
            'toggle_view' => 'Toggle View',
            'cancel_repair' => 'Cancel Repair',
            'cancel' => 'Cancel',
            'close' => 'Close',
            'device' => 'Device',
            'issue' => 'Issue',
            'status' => 'Status',
            'technician' => 'Technician',
            'created' => 'Created',
            'last_update' => 'Last Update',
            'actions' => 'Actions',
            'search' => 'Search',
            'search_placeholder' => 'Device name, type or issue...',
            'from_date' => 'From Date',
            'to_date' => 'To Date',
            'all_statuses' => 'All Statuses',
            'pending' => 'Pending',
            'scheduled' => 'Scheduled',
            'in_progress' => 'In Progress',
            'awaiting_parts' => 'Awaiting Parts',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'on_hold' => 'On Hold',
            'no_repairs_found' => 'No repairs found',
            'no_repairs_match_filters' => 'No repairs match your filter criteria. Try adjusting your filters or',
            'view_all_repairs' => 'view all repairs',
            'no_repairs_yet' => 'You don\'t have any repair requests yet. Start by creating a new repair request.',
            'cancel_repair_request' => 'Cancel Repair Request',
            'cancel_confirm' => 'Are you sure you want to cancel the repair request for',
            'cancel_reason' => 'Reason for cancellation',
            'cancel_reason_help' => 'Please provide a brief explanation for cancelling this repair request.',
            'no_new_notifications' => 'No new notifications',
            'view_all_notifications' => 'View all notifications',
            'all_rights_reserved' => 'All rights reserved.'
        ];
        
        $content = "<?php\n// English language file\n\$translations = " . var_export($englishTranslations, true) . ";\n";
        file_put_contents("../lang/en.php", $content);
    } catch (Exception $e) {
        error_log("Error creating language file: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $userLanguage; ?>" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo __('my_repairs', 'My Repairs'); ?> - FixItNow</title>
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
        
        /* Card Styles */
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
        
        /* Filter Form Styles */
        .filter-form {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
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
        
        /* Table Styles */
        .repairs-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .repairs-table th, .repairs-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .repairs-table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            color: var(--text-muted);
        }
        
        .repairs-table tbody tr {
            transition: all 0.2s ease;
        }
        
        .repairs-table tbody tr:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .repairs-table .device-info {
            display: flex;
            align-items: center;
        }
        
        .repairs-table .device-icon {
            width: 40px;
            height: 40px;
            border-radius: 0.5rem;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }
        
        .repairs-table .device-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .repairs-table .device-type {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .repairs-table .repair-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .repairs-table .repair-actions button {
            padding: 0.5rem;
            border-radius: 0.5rem;
            border: none;
            background-color: transparent;
            color: var(--text-color);
            transition: all 0.2s ease;
        }
        
        .repairs-table .repair-actions button:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
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
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .empty-state-message {
            color: var(--text-muted);
            margin-bottom: 2rem;
            max-width: 500px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Pagination */
        .pagination {
            margin-top: 2rem;
            justify-content: center;
        }
        
        .pagination .page-item .page-link {
            color: var(--text-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item.disabled .page-link {
            color: var(--text-muted);
        }
        
        /* Enhanced filter form */
        .filter-button {
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
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
            
            .repairs-table {
                display: block;
                overflow-x: auto;
            }
            
            /* Card view for repairs on mobile */
            .repairs-card-view .repairs-item {
                margin-bottom: 1rem;
                border: 1px solid var(--border-color);
                border-radius: 0.5rem;
                padding: 1rem;
            }
            
            .repairs-card-view .device-info {
                margin-bottom: 0.5rem;
            }
            
            .repairs-card-view .detail-row {
                display: flex;
                justify-content: space-between;
                margin-bottom: 0.5rem;
                padding-bottom: 0.5rem;
                border-bottom: 1px solid var(--border-color);
            }
            
            .repairs-card-view .detail-row:last-child {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
            }
            
            .repairs-card-view .detail-label {
                font-weight: 600;
                color: var(--text-muted);
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1.5rem;
            }
            
            .content-card .card-body {
                padding: 1.5rem;
            }
            
            .filter-form .row {
                flex-direction: column;
            }
            
            .filter-form .col-md-3, 
            .filter-form .col-md-4,
            .filter-form .col-md-2 {
                width: 100%;
                margin-bottom: 1rem;
            }
            
            .pagination .page-link {
                padding: 0.4rem 0.6rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .content-card .card-body {
                padding: 1rem;
            }
            
            .mobile-hidden {
                display: none;
            }
            
            .repairs-table th, 
            .repairs-table td {
                padding: 0.75rem 0.5rem;
            }
            
            .pagination .page-item:first-child,
            .pagination .page-item:last-child {
                display: none;
            }
        }
        
        /* RTL Support for Arabic */
        html[lang="ar"] {
            direction: rtl;
        }
        
        html[lang="ar"] .sidebar-menu i {
            margin-right: 0;
            margin-left: 0.75rem;
        }
        
        html[lang="ar"] .repairs-table .device-icon {
            margin-right: 0;
            margin-left: 1rem;
        }
        
        /* Print styles */
        @media print {
            .site-header, .sidebar, .filter-form, .btn, .pagination, footer {
                display: none !important;
            }
            
            .dashboard-wrapper {
                display: block;
            }
            
            .dashboard-content {
                width: 100%;
                padding: 0;
            }
            
            .content-card {
                box-shadow: none;
                border: none;
            }
            
            .repairs-table th, .repairs-table td {
                padding: 0.5rem;
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
                            <li><h6 class="dropdown-header"><?php echo __('notifications', 'Notifications'); ?></h6></li>
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted"><?php echo __('no_new_notifications', 'No new notifications'); ?></div></li>
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
                                <li><a class="dropdown-item text-center" href="notifications.php"><?php echo __('view_all_notifications', 'View all notifications'); ?></a></li>
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
                            <img src="<?php echo htmlspecialchars($profileImage); ?>" alt="Profile" class="rounded-circle me-2" width="32" height="32" onerror="this.src='../images/default.png';">
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
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> <?php echo __('dashboard', 'Dashboard'); ?></a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> <?php echo __('my_profile', 'My Profile'); ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> <?php echo __('logout', 'Logout'); ?></a></li>
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
                        <i class="fas fa-tachometer-alt"></i> <?php echo __('dashboard', 'Dashboard'); ?>
                    </a>
                </li>
                <li>
                    <a href="quotes.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'quotes.php' ? 'active' : ''; ?>">
                        <i class="fas fa-file-invoice-dollar"></i> <?php echo __('my_quotes', 'My Quotes'); ?>
                    </a>
                </li>
                <li>
                    <a href="my-repairs.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'my-repairs.php' ? 'active' : ''; ?>">
                        <i class="fas fa-tools"></i> <?php echo __('my_repairs', 'My Repairs'); ?>
                    </a>
                </li>
                <li>
                    <a href="new-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'new-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-plus-circle"></i> <?php echo __('new_request', 'New Request'); ?>
                    </a>
                </li>
                <li>
                    <a href="history.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'history.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i> <?php echo __('history', 'History'); ?>
                    </a>
                </li>
                <li>
                    <a href="profile.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'profile.php' ? 'active' : ''; ?>">
                        <i class="fas fa-user"></i> <?php echo __('my_profile', 'My Profile'); ?>
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'notifications.php' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i> <?php echo __('notifications', 'Notifications'); ?>
                        <?php if($notificationCount > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $notificationCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="../logout.php">
                        <i class="fas fa-sign-out-alt"></i> <?php echo __('logout', 'Logout'); ?>
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Main Content -->
        <div class="dashboard-content">
            <!-- Page Header -->
            <div class="d-flex align-items-center justify-content-between mb-4">
                <h2 class="fw-bold mb-0"><?php echo __('my_repairs', 'My Repairs'); ?></h2>
                <div>
                    <button class="btn btn-outline-primary me-2 d-none d-md-inline-block" id="toggleViewBtn">
                        <i class="fas fa-list me-2"></i> <?php echo __('toggle_view', 'Toggle View'); ?>
                    </button>
                    <a href="new-request.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i> <?php echo __('new_repair_request', 'New Repair Request'); ?>
                    </a>
                </div>
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
            
            <!-- Filters -->
            <div class="filter-form">
                <form action="my-repairs.php" method="get" id="filterForm">
                    <div class="row align-items-end">
                        <div class="col-md-3 mb-3 mb-md-0">
                            <label for="status" class="form-label"><?php echo __('status', 'Status'); ?></label>
                            <select class="form-select" id="status" name="status">
                                <option value=""><?php echo __('all_statuses', 'All Statuses'); ?></option>
                                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>><?php echo __('pending', 'Pending'); ?></option>
                                <option value="scheduled" <?php echo $status === 'scheduled' ? 'selected' : ''; ?>><?php echo __('scheduled', 'Scheduled'); ?></option>
                                <option value="in_progress" <?php echo $status === 'in_progress' ? 'selected' : ''; ?>><?php echo __('in_progress', 'In Progress'); ?></option>
                                <option value="awaiting_parts" <?php echo $status === 'awaiting_parts' ? 'selected' : ''; ?>><?php echo __('awaiting_parts', 'Awaiting Parts'); ?></option>
                                <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>><?php echo __('completed', 'Completed'); ?></option>
                                <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>><?php echo __('cancelled', 'Cancelled'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <label for="search" class="form-label"><?php echo __('search', 'Search'); ?></label>
                            <input type="text" class="form-control" id="search" name="search" placeholder="<?php echo __('search_placeholder', 'Device name, type or issue...'); ?>" value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2 mb-3 mb-md-0">
                            <label for="date_from" class="form-label"><?php echo __('from_date', 'From Date'); ?></label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>
                        <div class="col-md-2 mb-3 mb-md-0">
                            <label for="date_to" class="form-label"><?php echo __('to_date', 'To Date'); ?></label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>
                        <div class="col-md-1 text-end">
                            <button type="submit" class="btn btn-primary w-100 filter-button">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <!-- Hidden pagination field -->
                    <input type="hidden" name="page" id="pageInput" value="<?php echo $page; ?>">
                </form>
            </div>
            
            <!-- Repairs List -->
            <div class="content-card">
                <div class="card-body">
                    <?php if (empty($repairs)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                            <h3 class="empty-state-title"><?php echo __('no_repairs_found', 'No repairs found'); ?></h3>
                            <p class="empty-state-message">
                                <?php if (!empty($search) || !empty($status) || !empty($dateFrom) || !empty($dateTo)): ?>
                                    <?php echo __('no_repairs_match_filters', 'No repairs match your filter criteria. Try adjusting your filters or'); ?> <a href="my-repairs.php"><?php echo __('view_all_repairs', 'view all repairs'); ?></a>.
                                <?php else: ?>
                                    <?php echo __('no_repairs_yet', 'You don\'t have any repair requests yet. Start by creating a new repair request.'); ?>
                                <?php endif; ?>
                            </p>
                            <a href="new-request.php" class="btn btn-primary">
                                <i class="fas fa-plus me-2"></i> <?php echo __('new_repair_request', 'New Repair Request'); ?>
                            </a>
                        </div>
                    <?php else: ?>
                        <!-- Table View (default) -->
                        <div class="table-responsive" id="tableView">
                            <table class="repairs-table">
                                <thead>
                                    <tr>
                                        <th><?php echo __('device', 'Device'); ?></th>
                                        <th><?php echo __('issue', 'Issue'); ?></th>
                                        <th><?php echo __('status', 'Status'); ?></th>
                                        <th><?php echo __('technician', 'Technician'); ?></th>
                                        <th><?php echo __('created', 'Created'); ?></th>
                                        <th><?php echo __('last_update', 'Last Update'); ?></th>
                                        <th><?php echo __('actions', 'Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($repairs as $repair): ?>
                                    <tr>
                                        <td>
                                            <div class="device-info">
                                                <div class="device-icon">
                                                    <?php
                                                    $deviceType = strtolower($repair['device_type'] ?? '');
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
                                                    ?>
                                                    <i class="fas <?php echo $icon; ?>"></i>
                                                </div>
                                                <div>
                                                    <div class="device-name"><?php echo htmlspecialchars($repair['device_name'] ?? 'Unknown Device'); ?></div>
                                                    <div class="device-type"><?php echo htmlspecialchars($repair['device_type'] ?? 'Unknown Type'); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php 
                                            $issueDescription = $repair['issue_description'] ?? 'No description provided';
                                            // Trim if too long
                                            if (strlen($issueDescription) > 80) {
                                                $issueDescription = substr($issueDescription, 0, 77) . '...';
                                            }
                                            echo htmlspecialchars($issueDescription);
                                            ?>
                                        </td>
                                        <td>
                                            <?php echo getStatusLabel($repair['status'] ?? 'unknown'); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $technician = $repair['technician_name'] ?? 'Not assigned';
                                            if (trim($technician) === ' ' || empty(trim($technician))) {
                                                $technician = 'Not assigned';
                                            }
                                            echo htmlspecialchars($technician);
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $createdAt = $repair['created_at'] ?? '';
                                            if (!empty($createdAt)) {
                                                echo date('M d, Y', strtotime($createdAt));
                                            } else {
                                                echo 'Unknown';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $updatedAt = $repair['updated_at'] ?? '';
                                            if (!empty($updatedAt)) {
                                                echo date('M d, Y', strtotime($updatedAt));
                                            } else {
                                                echo 'No updates';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <div class="repair-actions">
                                                <a href="repair-details.php?id=<?php echo $repair['id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php echo __('view_details', 'View Details'); ?>">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <?php if (isset($repair['status']) && in_array($repair['status'], ['pending', 'scheduled', 'awaiting_parts'])): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger" title="<?php echo __('cancel_repair', 'Cancel Repair'); ?>" 
                                                        data-bs-toggle="modal" data-bs-target="#cancelModal" 
                                                        data-repair-id="<?php echo $repair['id']; ?>"
                                                        data-device-name="<?php echo htmlspecialchars($repair['device_name'] ?? 'this device'); ?>">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Card View (for mobile) -->
                        <div class="repairs-card-view d-none" id="cardView">
                            <?php foreach ($repairs as $repair): ?>
                            <div class="repairs-item">
                                <div class="device-info">
                                    <div class="d-flex align-items-center mb-3">
                                        <div class="device-icon">
                                            <?php
                                            $deviceType = strtolower($repair['device_type'] ?? '');
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
                                            ?>
                                            <i class="fas <?php echo $icon; ?>"></i>
                                        </div>
                                        <div>
                                            <div class="device-name"><?php echo htmlspecialchars($repair['device_name'] ?? 'Unknown Device'); ?></div>
                                            <div class="device-type"><?php echo htmlspecialchars($repair['device_type'] ?? 'Unknown Type'); ?></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label"><?php echo __('issue', 'Issue'); ?>:</div>
                                    <div class="detail-value">
                                        <?php 
                                        $issueDescription = $repair['issue_description'] ?? 'No description provided';
                                        // Trim if too long
                                        if (strlen($issueDescription) > 60) {
                                            $issueDescription = substr($issueDescription, 0, 57) . '...';
                                        }
                                        echo htmlspecialchars($issueDescription);
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label"><?php echo __('status', 'Status'); ?>:</div>
                                    <div class="detail-value">
                                        <?php echo getStatusLabel($repair['status'] ?? 'unknown'); ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label"><?php echo __('technician', 'Technician'); ?>:</div>
                                    <div class="detail-value">
                                        <?php 
                                        $technician = $repair['technician_name'] ?? 'Not assigned';
                                        if (trim($technician) === ' ' || empty(trim($technician))) {
                                            $technician = 'Not assigned';
                                        }
                                        echo htmlspecialchars($technician);
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label"><?php echo __('created', 'Created'); ?>:</div>
                                    <div class="detail-value">
                                        <?php 
                                        $createdAt = $repair['created_at'] ?? '';
                                        if (!empty($createdAt)) {
                                            echo date('M d, Y', strtotime($createdAt));
                                        } else {
                                            echo 'Unknown';
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="detail-row">
                                    <div class="detail-label"><?php echo __('last_update', 'Last Update'); ?>:</div>
                                    <div class="detail-value">
                                        <?php 
                                        $updatedAt = $repair['updated_at'] ?? '';
                                        if (!empty($updatedAt)) {
                                            echo date('M d, Y', strtotime($updatedAt));
                                        } else {
                                            echo 'No updates';
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="mt-3 d-flex justify-content-end">
                                    <a href="repair-details.php?id=<?php echo $repair['id']; ?>" class="btn btn-sm btn-outline-primary me-2">
                                        <i class="fas fa-eye me-1"></i> <?php echo __('view_details', 'View Details'); ?>
                                    </a>
                                    
                                    <?php if (isset($repair['status']) && in_array($repair['status'], ['pending', 'scheduled', 'awaiting_parts'])): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                            data-bs-toggle="modal" data-bs-target="#cancelModal" 
                                            data-repair-id="<?php echo $repair['id']; ?>"
                                            data-device-name="<?php echo htmlspecialchars($repair['device_name'] ?? 'this device'); ?>">
                                        <i class="fas fa-times me-1"></i> <?php echo __('cancel', 'Cancel'); ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <nav aria-label="Page navigation">
                            <ul class="pagination">
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="return changePage(1)" aria-label="First">
                                        <span aria-hidden="true">&laquo;&laquo;</span>
                                    </a>
                                </li>
                                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="return changePage(<?php echo $page - 1; ?>)" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                
                                <?php
                                // Display a reasonable number of page links
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                
                                // Ensure we always show at least 5 pages if available
                                if ($endPage - $startPage < 4) {
                                    if ($startPage == 1) {
                                        $endPage = min($totalPages, 5);
                                    } elseif ($endPage == $totalPages) {
                                        $startPage = max(1, $totalPages - 4);
                                    }
                                }
                                
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="#" onclick="return changePage(<?php echo $i; ?>)"><?php echo $i; ?></a>
                                </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="return changePage(<?php echo $page + 1; ?>)" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="#" onclick="return changePage(<?php echo $totalPages; ?>)" aria-label="Last">
                                        <span aria-hidden="true">&raquo;&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cancel Repair Modal -->
    <div class="modal fade" id="cancelModal" tabindex="-1" aria-labelledby="cancelModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelModalLabel"><?php echo __('cancel_repair_request', 'Cancel Repair Request'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="my-repairs.php" method="post">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="repair_id" id="cancelRepairId">
                        <p><?php echo __('cancel_confirm', 'Are you sure you want to cancel the repair request for'); ?> <span id="cancelDeviceName">this device</span>?</p>
                        <div class="mb-3">
                            <label for="cancel_reason" class="form-label"><?php echo __('cancel_reason', 'Reason for cancellation'); ?></label>
                            <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" required></textarea>
                            <div class="form-text"><?php echo __('cancel_reason_help', 'Please provide a brief explanation for cancelling this repair request.'); ?></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('close', 'Close'); ?></button>
                        <button type="submit" name="cancel_repair" class="btn btn-danger"><?php echo __('cancel_repair', 'Cancel Repair'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="py-4 bg-dark text-light">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. <?php echo __('all_rights_reserved', 'All rights reserved.'); ?></p>
            <div class="mt-2">
                <a href="../terms.php" class="text-muted me-3"><?php echo __('terms_of_service', 'Terms of Service'); ?></a>
                <a href="../privacy.php" class="text-muted me-3"><?php echo __('privacy_policy', 'Privacy Policy'); ?></a>
                <a href="../contact.php" class="text-muted"><?php echo __('contact_us', 'Contact Us'); ?></a>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Theme toggler
        document.addEventListener('DOMContentLoaded', function() {
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
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    try {
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
                    } catch (error) {
                        console.error('Theme toggle error:', error);
                    }
                });
            }
            
            // Cancel modal
            const cancelModal = document.getElementById('cancelModal');
            if (cancelModal) {
                cancelModal.addEventListener('show.bs.modal', function (event) {
                    try {
                        const button = event.relatedTarget;
                        if (!button) return;
                        
                        const repairId = button.getAttribute('data-repair-id');
                        const deviceName = button.getAttribute('data-device-name');
                        
                        const repairIdInput = document.getElementById('cancelRepairId');
                        const deviceNameSpan = document.getElementById('cancelDeviceName');
                        
                        if (repairIdInput) repairIdInput.value = repairId || '';
                        if (deviceNameSpan) deviceNameSpan.textContent = deviceName || 'this device';
                    } catch (error) {
                        console.error('Modal error:', error);
                    }
                });
            }
            
            // Toggle view (table/card)
            const toggleViewBtn = document.getElementById('toggleViewBtn');
            const tableView = document.getElementById('tableView');
            const cardView = document.getElementById('cardView');
            
            // Check if elements exist
            if (!toggleViewBtn || !tableView || !cardView) {
                return; // Exit if any element is missing
            }
            
            // Check for saved view preference
            const storedView = localStorage.getItem('repairsView') || 'table';
            
            // Set initial view
            if (storedView === 'card' && cardView && tableView) {
                tableView.classList.add('d-none');
                cardView.classList.remove('d-none');
            }
            
            // Toggle view on button click
            toggleViewBtn.addEventListener('click', function() {
                try {
                    if (!tableView || !cardView) return;
                    
                    tableView.classList.toggle('d-none');
                    cardView.classList.toggle('d-none');
                    
                    const currentView = tableView.classList.contains('d-none') ? 'card' : 'table';
                    localStorage.setItem('repairsView', currentView);
                } catch (error) {
                    console.error('Toggle view error:', error);
                }
            });
            
            // Switch to card view on small screens
            function adjustView() {
                try {
                    if (window.innerWidth < 768 && storedView !== 'card') {
                        if (tableView) tableView.classList.add('d-none');
                        if (cardView) cardView.classList.remove('d-none');
                    } else if (window.innerWidth >= 768 && storedView === 'table') {
                        if (tableView) tableView.classList.remove('d-none');
                        if (cardView) cardView.classList.add('d-none');
                    }
                } catch (error) {
                    console.error('Adjust view error:', error);
                }
            }
            
            // Call on page load and resize
            adjustView();
            window.addEventListener('resize', adjustView);
            
            // Loading spinner
            const loadingSpinner = document.getElementById('loadingSpinner');
            if (loadingSpinner) {
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
            }
        });
        
        // Pagination function with error handling
        function changePage(page) {
            try {
                const pageInput = document.getElementById('pageInput');
                if (pageInput) {
                    pageInput.value = page;
                }
                
                const filterForm = document.getElementById('filterForm');
                if (filterForm) {
                    filterForm.submit();
                }
            } catch (error) {
                console.error('Pagination error:', error);
            }
            return false; // Prevent default link behavior
        }
    </script>
</body>
</html>