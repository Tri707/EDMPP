<?php
session_start();

// Add CSRF protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$loggedIn = isset($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// Redirect if not logged in or not a provider
if (!$loggedIn || $userRole !== 'provider') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
if (!isset($pdo)) {
    include '../conn.php';
}

// Function to clean input data
function clean_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

// Function to prevent XSS when outputting data
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Initialize arrays and variables
$userData = null;
$providerData = null;
$profileImage = '../images/default.png';
$notifications = [];
$totalNotifications = 0;
$errors = [];
$success = '';
$providerSpecialties = [];

// Pagination variables
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$offset = ($page - 1) * $limit;

// Validate pagination parameters
if ($page < 1) $page = 1;
if ($limit < 5) $limit = 5;
if ($limit > 100) $limit = 100;

// Filter variables
$status = isset($_GET['status']) ? clean_input($_GET['status']) : 'all';
$type = isset($_GET['type']) ? clean_input($_GET['type']) : 'all';
$searchTerm = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'newest';
$readFilter = isset($_GET['read']) ? clean_input($_GET['read']) : 'all';

// Database error handler
function handleDatabaseError($e, $operation) {
    error_log("Database error in {$operation}: " . $e->getMessage());
    return false;
}

// Load user and provider data
try {
    // Get user data
    $userQuery = "SELECT * FROM users WHERE id = ?";
    $stmt = $pdo->prepare($userQuery);
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get provider data
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$userId]);
    $providerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path with proper security checks
    if ($userData && !empty($userData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $userData['profile_image'])) {
            // External URL
            $profileImage = $userData['profile_image'];
        } else {
            // Local file - safely handle path
            $profileImage = '../profile_images/' . basename($userData['profile_image']);
        }
    }
    
    // Get provider specialties
    if ($providerData && isset($providerData['specialties'])) {
        $providerSpecialties = explode(',', $providerData['specialties']);
    }
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching user/provider data');
}

// Get provider ID
$providerId = $providerData['id'] ?? 0;
if (!$providerId) {
    $errors[] = "Provider profile not found. Please complete your profile setup.";
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Security validation failed. Please try again.";
    } else {
        // Handle mark as read/unread action
        if (isset($_POST['action']) && $_POST['action'] === 'mark_read') {
            $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            $markAs = isset($_POST['mark_as']) ? (int)$_POST['mark_as'] : 1; // 1 = read, 0 = unread
            
            try {
                $updateQuery = "UPDATE notifications SET is_read = ? WHERE id = ? AND provider_id = ?";
                $stmt = $pdo->prepare($updateQuery);
                $stmt->execute([$markAs, $notificationId, $providerId]);
                
                if ($stmt->rowCount() > 0) {
                    $success = $markAs ? "Notification marked as read." : "Notification marked as unread.";
                } else {
                    $errors[] = "Failed to update notification status.";
                }
            } catch (PDOException $e) {
                handleDatabaseError($e, 'updating notification read status');
                $errors[] = "Database error occurred while updating notification.";
            }
        }
        
        // Handle mark all as read action
        if (isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
            try {
                $updateQuery = "UPDATE notifications SET is_read = 1 WHERE provider_id = ? AND is_read = 0";
                $stmt = $pdo->prepare($updateQuery);
                $stmt->execute([$providerId]);
                
                if ($stmt->rowCount() > 0) {
                    $success = "All notifications marked as read.";
                } else {
                    $errors[] = "No unread notifications to mark as read.";
                }
            } catch (PDOException $e) {
                handleDatabaseError($e, 'marking all notifications as read');
                $errors[] = "Database error occurred while updating notifications.";
            }
        }
        
        // Handle delete notification action
        if (isset($_POST['action']) && $_POST['action'] === 'delete') {
            $notificationId = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
            
            try {
                $deleteQuery = "DELETE FROM notifications WHERE id = ? AND provider_id = ?";
                $stmt = $pdo->prepare($deleteQuery);
                $stmt->execute([$notificationId, $providerId]);
                
                if ($stmt->rowCount() > 0) {
                    $success = "Notification deleted successfully.";
                } else {
                    $errors[] = "Failed to delete notification.";
                }
            } catch (PDOException $e) {
                handleDatabaseError($e, 'deleting notification');
                $errors[] = "Database error occurred while deleting notification.";
            }
        }
        
        // Handle delete all read notifications action
        if (isset($_POST['action']) && $_POST['action'] === 'delete_all_read') {
            try {
                $deleteQuery = "DELETE FROM notifications WHERE provider_id = ? AND is_read = 1";
                $stmt = $pdo->prepare($deleteQuery);
                $stmt->execute([$providerId]);
                
                if ($stmt->rowCount() > 0) {
                    $success = "All read notifications deleted successfully.";
                } else {
                    $errors[] = "No read notifications to delete.";
                }
            } catch (PDOException $e) {
                handleDatabaseError($e, 'deleting all read notifications');
                $errors[] = "Database error occurred while deleting notifications.";
            }
        }
    }
}

// Get new notification count (for sidebar badge)
$unreadCount = 0;
try {
    // Base query for unread notifications
    $countQuery = "
        SELECT COUNT(*) as count 
        FROM notifications 
        WHERE provider_id = ? AND is_read = 0 AND status = 'pending'
    ";
    
    // Add specialty filtering if specialties exist
    if (!empty($providerSpecialties)) {
        $specialtyConditions = [];
        foreach ($providerSpecialties as $specialty) {
            $specialtyConditions[] = "device_type = ?";
        }
        if (!empty($specialtyConditions)) {
            $countQuery .= " AND (" . implode(" OR ", $specialtyConditions) . ")";
        }
    }
    
    $stmt = $pdo->prepare($countQuery);
    
    // Add provider ID as first parameter
    $countParams = [$providerId];
    
    // Add specialty parameters if needed
    if (!empty($providerSpecialties)) {
        $countParams = array_merge($countParams, $providerSpecialties);
    }
    
    $stmt->execute($countParams);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $unreadCount = $result['count'] ?? 0;
} catch (PDOException $e) {
    handleDatabaseError($e, 'counting unread notifications');
}

// Build query conditions based on filters
$conditions = ["provider_id = ?"]; // Always filter by provider ID
$params = [$providerId];

// Define status condition
if ($status !== 'all') {
    $conditions[] = "status = ?";
    $params[] = $status;
}

// Define type condition
if ($type !== 'all') {
    $conditions[] = "type = ?";
    $params[] = $type;
}

// Define read/unread condition
if ($readFilter !== 'all') {
    $readStatus = ($readFilter === 'read') ? 1 : 0;
    $conditions[] = "is_read = ?";
    $params[] = $readStatus;
}

// Define search condition
if (!empty($searchTerm)) {
    $conditions[] = "(message LIKE ? OR details LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params = array_merge($params, [$searchParam, $searchParam]);
}

// Add specialty filter - only show notifications matching provider specialties
if (!empty($providerSpecialties)) {
    $specialtyConditions = [];
    foreach ($providerSpecialties as $specialty) {
        $specialtyConditions[] = "device_type = ?";
    }
    if (!empty($specialtyConditions)) {
        $conditions[] = "(" . implode(" OR ", $specialtyConditions) . ")";
        $params = array_merge($params, $providerSpecialties);
    }
}

// Combine conditions
$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Define sorting
$orderClause = "ORDER BY created_at DESC"; // Default sorting (newest)

if ($sortBy === 'oldest') {
    $orderClause = "ORDER BY created_at ASC";
} elseif ($sortBy === 'unread_first') {
    $orderClause = "ORDER BY is_read ASC, created_at DESC";
} elseif ($sortBy === 'type') {
    $orderClause = "ORDER BY type ASC, created_at DESC";
}

// Get total count for pagination
try {
    // Use the same conditions for counting
    $countQuery = "
        SELECT COUNT(*) as total
        FROM notifications
        $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalNotifications = $result['total'] ?? 0;
} catch (PDOException $e) {
    handleDatabaseError($e, 'counting notifications');
}

// Calculate pagination values
$totalPages = ceil($totalNotifications / $limit);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Get notifications with pagination
try {
    // Add pagination to the query
    $notificationsQuery = "
        SELECT *
        FROM notifications
        $whereClause
        $orderClause
        LIMIT ?, ?
    ";
    
    $stmt = $pdo->prepare($notificationsQuery);
    $params[] = $offset;
    $params[] = $limit;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching notifications');
    $errors[] = "Failed to retrieve notifications. Please try again later.";
}

// Function to get notification type icon and class
function getNotificationTypeInfo($type) {
    switch ($type) {
        case 'quote_request':
            return [
                'icon' => 'file-invoice-dollar',
                'class' => 'success',
                'label' => 'Quote Request'
            ];
        case 'booking':
            return [
                'icon' => 'calendar-check',
                'class' => 'info',
                'label' => 'Booking'
            ];
        case 'message':
            return [
                'icon' => 'envelope',
                'class' => 'warning',
                'label' => 'Message'
            ];
        case 'review':
            return [
                'icon' => 'star',
                'class' => 'danger',
                'label' => 'Review'
            ];
        case 'repair_update':
            return [
                'icon' => 'tools',
                'class' => 'primary',
                'label' => 'Repair Update'
            ];
        case 'payment':
            return [
                'icon' => 'money-bill-wave',
                'class' => 'success',
                'label' => 'Payment'
            ];
        default:
            return [
                'icon' => 'bell',
                'class' => 'secondary',
                'label' => 'Notification'
            ];
    }
}

// Function to format date
function formatDate($dateString) {
    $date = new DateTime($dateString);
    $now = new DateTime();
    $diff = $now->diff($date);
    
    if ($diff->days == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return "Just now";
            }
            return $diff->i . " min ago";
        }
        return $diff->h . " hour" . ($diff->h > 1 ? "s" : "") . " ago";
    } elseif ($diff->days == 1) {
        return "Yesterday at " . $date->format('g:i A');
    } elseif ($diff->days < 7) {
        return $diff->days . " day" . ($diff->days > 1 ? "s" : "") . " ago";
    } else {
        return $date->format('M j, Y g:i A');
    }
}

// Function to truncate text
function truncateText($text, $length = 100, $append = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $append;
}

// Function to get status label
function getStatusLabel($status) {
    switch ($status) {
        case 'pending':
            return '<span class="badge bg-warning text-dark">Pending</span>';
        case 'sent':
            return '<span class="badge bg-success">Sent</span>';
        case 'failed':
            return '<span class="badge bg-danger">Failed</span>';
        default:
            return '<span class="badge bg-secondary">Unknown</span>';
    }
}

// Determine theme preference
$theme = 'light';
if (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') {
    $theme = 'dark';
} elseif (isset($_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME']) && 
         $_SERVER['HTTP_SEC_CH_PREFERS_COLOR_SCHEME'] === 'dark') {
    $theme = 'dark';
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?php echo $theme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage your notifications - FixItNow Provider Portal">
    <title>Notifications - FixItNow Provider</title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" as="style">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" as="script">
    
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
            
            /* Animation speeds */
            --transition-speed: 0.3s;
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
        }
        
        /* General Styles */
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,0.075);
            transition: background-color var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
        }
        
        .site-header .container {
            max-width: 1400px;
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            transition: color var(--transition-speed) ease;
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            transition: color var(--transition-speed) ease;
        }
        
        /* Improved Navigation Links */
        .header-nav {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .header-nav a.nav-link {
            color: var(--header-text);
            opacity: 0.85;
            font-weight: 500;
            padding: 0.5rem 1rem;
            transition: all var(--transition-speed) ease;
            border-radius: 0.5rem;
            position: relative;
            display: flex;
            align-items: center;
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
        
        /* Search Bar Styling */
        .search-container {
            max-width: 350px;
            width: 100%;
        }
        
        .search-container input {
            height: 40px;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--text-color);
            border: 1px solid transparent;
            transition: all var(--transition-speed) ease;
        }
        
        .search-container input:focus {
            background-color: var(--card-bg);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.15);
        }
        
        .search-container button {
            background: transparent;
            border: none;
        }
        
        /* Buttons & Icon Buttons */
        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all var(--transition-speed) ease;
            border: none;
            padding: 0;
            position: relative;
        }
        
        .btn-icon:hover, .btn-icon:focus {
            background-color: rgba(255, 255, 255, 0.2);
            color: var(--header-text);
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
            transition: background-color var(--transition-speed) ease, transform var(--transition-speed) ease;
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
            transition: all var(--transition-speed) ease;
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
        
        /* Filter Card Styles */
        .filter-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        /* Notification Item Styles */
        .notification-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .notification-item {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            position: relative;
        }
        
        .notification-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        /* Unread notification styling */
        .notification-item.unread {
            border-left: 4px solid var(--primary-color);
        }
        
        .notification-item.unread::before {
            content: '';
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            width: 10px;
            height: 10px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.25rem;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-time {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .notification-actions {
            margin-top: 1rem;
            display: flex;
            gap: 0.5rem;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
        }
        
        .empty-state-icon {
            font-size: 3rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        /* Status Badge Styles */
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        /* Filter Pills */
        .filter-pill {
            display: inline-flex;
            align-items: center;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 20px;
            padding: 0.35rem 0.75rem;
            margin-right: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .filter-pill .remove-filter {
            margin-left: 0.5rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity var(--transition-speed) ease;
        }
        
        .filter-pill .remove-filter:hover {
            opacity: 1;
        }
        
        /* Pagination Styles */
        .pagination .page-item .page-link {
            border-radius: 0.5rem;
            margin: 0 0.25rem;
            color: var(--primary-color);
            transition: all var(--transition-speed) ease;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        /* Type badge styles */
        .type-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }
        
        /* Custom Checkbox Styles */
        .form-check-input {
            cursor: pointer;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Mobile Offcanvas */
        .offcanvas {
            max-width: 320px;
            border-right: 1px solid var(--border-color);
            background-color: var(--card-bg);
        }
        
        .offcanvas-title {
            font-weight: 700;
            color: var(--accent-color);
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            
            .dashboard-content {
                padding: 1.5rem 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .filter-card .filter-body {
                flex-direction: column;
            }
            
            .filter-card .filter-item {
                margin-bottom: 1rem;
                width: 100%;
            }
            
            .notification-item {
                padding: 1rem;
            }
            
            .notification-actions {
                flex-wrap: wrap;
            }
            
            .notification-actions .btn {
                font-size: 0.875rem;
                padding: 0.25rem 0.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .notification-item {
                margin-bottom: 0.75rem;
            }
            
            .notification-item .d-flex {
                flex-direction: column;
            }
            
            .notification-icon {
                margin-right: 0;
                margin-bottom: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-auto d-flex align-items-center">
                    <!-- Logo with higher contrast -->
                    <a href="index.php" class="text-decoration-none d-flex align-items-center" aria-label="FixItNow Home">
                        <div class="logo-text">
                            <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                        </div>
                    </a>
                </div>
                
                <!-- Search Bar (New) -->
                <div class="col d-none d-lg-block mx-4">
                    <div class="search-container position-relative">
                        <form action="search.php" method="GET" class="d-flex">
                            <input type="text" name="q" class="form-control bg-light border-0 rounded-pill ps-4 pe-5" placeholder="Search for repairs, customers, parts..." aria-label="Search">
                            <button type="submit" class="btn position-absolute end-0 top-0 h-100 px-3" aria-label="Submit search">
                                <i class="fas fa-search text-muted" aria-hidden="true"></i>
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Desktop Navigation -->
                <div class="col-auto d-none d-lg-block me-auto">
                    <nav class="header-nav" aria-label="Main navigation">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1" aria-hidden="true"></i> Dashboard
                        </a>
                        <a class="nav-link" href="quote-requests.php">
                            <i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i> Quotes
                        </a>
                    </nav>
                </div>
                
                <!-- Right Side Controls -->
                <div class="col-auto d-flex align-items-center gap-2">
                    <!-- Quick Actions Button (New) -->
                    <div class="dropdown d-none d-md-block">
                        <button class="btn btn-primary rounded-pill px-3 py-1" type="button" id="quickActionsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bolt me-md-1" aria-hidden="true"></i>
                            <span class="d-none d-md-inline">Quick Actions</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="quickActionsDropdown">
                            <li><h6 class="dropdown-header">Common Tasks</h6></li>
                            <li><a class="dropdown-item" href="create-quote.php"><i class="fas fa-file-invoice me-2" aria-hidden="true"></i> Create Quote</a></li>
                            <li><a class="dropdown-item" href="schedule-repair.php"><i class="fas fa-calendar-plus me-2" aria-hidden="true"></i> Schedule Repair</a></li>
                            <li><a class="dropdown-item" href="update-repair.php"><i class="fas fa-wrench me-2" aria-hidden="true"></i> Update Repair Status</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="add-service.php"><i class="fas fa-plus-circle me-2" aria-hidden="true"></i> Add New Service</a></li>
                        </ul>
                    </div>
                    
                    <!-- Notifications Button (Active) -->
                    <div>
                        <a href="notifications.php" class="btn btn-icon position-relative notification-badge" aria-label="Notifications" aria-current="page">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                            <?php if($unreadCount > 0): ?>
                            <span class="badge bg-danger rounded-pill position-absolute top-0 end-0 translate-middle" aria-label="<?php echo $unreadCount; ?> unread notifications"><?php echo $unreadCount; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="btn btn-icon" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-sun" id="themeIcon" aria-hidden="true"></i>
                    </button>
                    
                    <!-- User Profile -->
                    <?php if($loggedIn && isset($userData['username'])): ?>
                    <div class="dropdown">
                        <button class="btn d-flex align-items-center gap-2" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="position-relative">
                                <img src="<?php echo e($profileImage); ?>" alt="" class="rounded-circle" width="36" height="36">
                                <span class="position-absolute bottom-0 end-0 bg-success rounded-circle p-1 border border-white" title="Online" aria-hidden="true"></span>
                            </div>
                            <div class="d-none d-md-block text-start">
                                <div class="text-nowrap fw-semibold"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                                <div class="text-muted small text-nowrap"><?php echo e($userData['role']); ?></div>
                            </div>
                            <i class="fas fa-chevron-down small ms-1 d-none d-md-inline" aria-hidden="true"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg" aria-labelledby="userDropdown">
                            <li>
                                <div class="dropdown-item d-flex align-items-center py-2 px-3">
                                    <div class="flex-shrink-0 me-2 d-md-none">
                                        <div class="fw-semibold"><?php echo e($userData['first_name'] . ' ' . $userData['last_name']); ?></div>
                                        <div class="text-muted small"><?php echo e($userData['email']); ?></div>
                                    </div>
                                </div>
                            </li>
                            <li><hr class="dropdown-divider d-md-none my-1"></li>
                            <?php if($userRole == 'admin'): ?>
                                <li><a class="dropdown-item py-2" href="admin/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Admin Dashboard</a></li>
                            <?php elseif($userRole == 'provider'): ?>
                                <li><a class="dropdown-item py-2" href="provider/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Provider Dashboard</a></li>
                            <?php else: ?>
                                <li><a class="dropdown-item py-2" href="customer/dashboard.php"><i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> My Account</a></li>
                            <?php endif; ?>
                            <li><a class="dropdown-item py-2" href="profile.php"><i class="fas fa-user-cog me-2" aria-hidden="true"></i> Edit Profile</a></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item py-2" href="help.php"><i class="fas fa-question-circle me-2" aria-hidden="true"></i> Help Center</a></li>
                            <li><a class="dropdown-item py-2 text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div>
                        <a href="login.php" class="btn btn-outline-light me-2">Login</a>
                        <a href="signup.php" class="btn btn-success">Sign Up</a>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Mobile Menu Toggle -->
                    <button class="navbar-toggler btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-expanded="false" aria-label="Toggle navigation">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mobile Navigation Offcanvas -->
        <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileNav" aria-labelledby="mobileNavLabel">
            <div class="offcanvas-header">
                <h5 class="offcanvas-title" id="mobileNavLabel">
                    <i class="fas fa-tools me-2" aria-hidden="true"></i>FixItNow
                </h5>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body p-0">
                <!-- Mobile Search -->
                <div class="p-3">
                    <form action="search.php" method="GET">
                        <div class="input-group">
                            <input type="text" name="q" class="form-control" placeholder="Search..." aria-label="Search">
                            <button class="btn btn-outline-secondary" type="submit" aria-label="Search"><i class="fas fa-search" aria-hidden="true"></i></button>
                        </div>
                    </form>
                </div>
                
                <!-- Mobile Menu -->
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-tachometer-alt me-2" aria-hidden="true"></i> Dashboard
                    </a>
                    <a href="quote-requests.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-file-invoice-dollar me-2" aria-hidden="true"></i> Quote Requests
                    </a>
                    <a href="my-quotes.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-comment-dollar me-2" aria-hidden="true"></i> My Quotes
                    </a>
                    <a href="schedule.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-calendar-alt me-2" aria-hidden="true"></i> My Schedule
                    </a>
                    <a href="earnings.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-money-bill-wave me-2" aria-hidden="true"></i> Earnings
                    </a>
                    <a href="reviews.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-star me-2" aria-hidden="true"></i> My Reviews
                    </a>
                    <a href="notifications.php" class="list-group-item list-group-item-action py-3 active">
                        <i class="fas fa-bell me-2" aria-hidden="true"></i> Notifications
                        <?php if($unreadCount > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                </div>
                
                <div class="p-3 mt-4 border-top">
                    <a href="profile.php" class="btn btn-outline-primary w-100 mb-2">
                        <i class="fas fa-user-cog me-2" aria-hidden="true"></i> Profile Settings
                    </a>
                    <a href="logout.php" class="btn btn-danger w-100">
                        <i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i> Logout
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Dashboard Content -->
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <div class="sidebar d-none d-lg-block">
            <h5 class="mb-3">Provider Dashboard</h5>
            <ul class="sidebar-menu">
                <li>
                    <a href="dashboard.php">
                        <i class="fas fa-tachometer-alt"></i> Overview
                    </a>
                </li>
                <li>
                    <a href="quote-requests.php">
                        <i class="fas fa-file-invoice-dollar"></i> Quote Requests
                    </a>
                </li>
                <li>
                    <a href="my-quotes.php">
                        <i class="fas fa-comment-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                    <a href="schedule.php">
                        <i class="fas fa-calendar-alt"></i> My Schedule
                    </a>
                </li>
                <li>
                    <a href="earnings.php">
                        <i class="fas fa-money-bill-wave"></i> Earnings
                    </a>
                </li>
                <li>
                    <a href="reviews.php">
                        <i class="fas fa-star"></i> My Reviews
                    </a>
                </li>
                <li>
                    <a href="notifications.php" class="active">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if($unreadCount > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="../profile.php">
                        <i class="fas fa-user-cog"></i> Profile Settings
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
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h1 class="mb-1">Notifications</h1>
                        <p class="text-muted mb-0">Manage your notifications and stay updated on important activities</p>
                    </div>
                    
                    <!-- Notification Actions -->
                    <div class="d-flex gap-2">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="mark_all_read">
                            <button type="submit" class="btn btn-outline-primary" <?php echo $unreadCount === 0 ? 'disabled' : ''; ?>>
                                <i class="fas fa-check-double me-2"></i> Mark All Read
                            </button>
                        </form>
                        
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="notificationActionDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-v"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationActionDropdown">
                                <li>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_all_read">
                                        <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Are you sure you want to delete all read notifications? This action cannot be undone.')">
                                            <i class="fas fa-trash-alt me-2"></i> Delete All Read
                                        </button>
                                    </form>
                                </li>
                                <li><a class="dropdown-item" href="notification-settings.php"><i class="fas fa-cog me-2"></i> Notification Settings</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <?php if(!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <ul class="mb-0">
                        <?php foreach($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <?php if(!empty($success)): ?>
                <div class="alert alert-success alert-dismissible fade show mb-4" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <!-- Display provider specialties -->
                <?php if(!empty($providerSpecialties)): ?>
               
                <?php elseif($providerId > 0): ?>
                <div class="alert alert-warning mb-4">
                    <h5><i class="fas fa-exclamation-triangle me-2"></i> No Specialties Set</h5>
                    <p class="mb-0">You currently have no specialties set in your profile. Please <a href="profile.php" class="alert-link">update your profile</a> to add specialties and receive relevant notifications.</p>
                </div>
                <?php endif; ?>
                
                <!-- Filters Card -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form action="notifications.php" method="GET" class="mb-0">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="type" class="form-label">Type</label>
                                    <select name="type" id="type" class="form-select">
                                        <option value="all" <?php echo $type === 'all' ? 'selected' : ''; ?>>All Types</option>
                                        <option value="quote_request" <?php echo $type === 'quote_request' ? 'selected' : ''; ?>>Quote Requests</option>
                                        <option value="booking" <?php echo $type === 'booking' ? 'selected' : ''; ?>>Bookings</option>
                                        <option value="message" <?php echo $type === 'message' ? 'selected' : ''; ?>>Messages</option>
                                        <option value="review" <?php echo $type === 'review' ? 'selected' : ''; ?>>Reviews</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                                        <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="read" class="form-label">Read Status</label>
                                    <select name="read" id="read" class="form-select">
                                        <option value="all" <?php echo $readFilter === 'all' ? 'selected' : ''; ?>>All</option>
                                        <option value="unread" <?php echo $readFilter === 'unread' ? 'selected' : ''; ?>>Unread</option>
                                        <option value="read" <?php echo $readFilter === 'read' ? 'selected' : ''; ?>>Read</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select name="sort" id="sort" class="form-select">
                                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="unread_first" <?php echo $sortBy === 'unread_first' ? 'selected' : ''; ?>>Unread First</option>
                                        <option value="type" <?php echo $sortBy === 'type' ? 'selected' : ''; ?>>By Type</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label for="search" class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search" name="search" placeholder="Search notifications..." value="<?php echo e($searchTerm); ?>">
                                        <button class="btn btn-outline-primary" type="submit">
                                            <i class="fas fa-search"></i> Search
                                        </button>
                                        <a href="notifications.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times"></i> Clear
                                        </a>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Active Filters -->
                            <?php if($type !== 'all' || $status !== 'all' || $readFilter !== 'all' || !empty($searchTerm) || $sortBy !== 'newest'): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="d-flex align-items-center flex-wrap">
                                    <strong class="me-2 mb-2">Active Filters:</strong>
                                    <div>
                                        <?php if($type !== 'all'): ?>
                                        <div class="filter-pill">
                                            Type: <?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                            <a href="<?php echo '?type=all&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove type filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($status !== 'all'): ?>
                                        <div class="filter-pill">
                                            Status: <?php echo ucfirst($status); ?>
                                            <a href="<?php echo '?type=' . $type . '&status=all&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove status filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($readFilter !== 'all'): ?>
                                        <div class="filter-pill">
                                            Read Status: <?php echo ucfirst($readFilter); ?>
                                            <a href="<?php echo '?type=' . $type . '&status=' . $status . '&read=all' . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove read status filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($searchTerm)): ?>
                                        <div class="filter-pill">
                                            Search: "<?php echo e($searchTerm); ?>"
                                            <a href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove search filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($sortBy !== 'newest'): ?>
                                        <div class="filter-pill">
                                            Sort: <?php echo ucfirst(str_replace('_', ' ', $sortBy)); ?>
                                            <a href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : ''); ?>" class="remove-filter" aria-label="Remove sort filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
                
                <!-- Results Summary -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="mb-0">
                        <strong><?php echo $totalNotifications; ?></strong> notification<?php echo $totalNotifications !== 1 ? 's' : ''; ?> found
                        <?php if($unreadCount > 0): ?>
                        <span class="badge bg-danger"><?php echo $unreadCount; ?> unread</span>
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-flex align-items-center">
                        <label for="limit" class="form-label mb-0 me-2">Show:</label>
                        <select id="limit" name="limit" class="form-select form-select-sm" style="width: auto;" onchange="window.location.href='?type=<?php echo $type; ?>&status=<?php echo $status; ?>&read=<?php echo $readFilter; ?>&search=<?php echo urlencode($searchTerm); ?>&sort=<?php echo $sortBy; ?>&page=1&limit='+this.value">
                            <option value="20" <?php echo $limit === 20 ? 'selected' : ''; ?>>20</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                </div>
                
                <!-- Notifications List -->
                <?php if(empty($notifications)): ?>
                <div class="card mb-4">
                    <div class="card-body empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-bell-slash"></i>
                        </div>
                        <h3>No Notifications Found</h3>
                        <p class="text-muted mb-3">
                            <?php if($type !== 'all' || $status !== 'all' || $readFilter !== 'all' || !empty($searchTerm)): ?>
                            No notifications match your current filter criteria. Try adjusting your filters.
                            <?php elseif(empty($providerSpecialties)): ?>
                            You need to add specialties to your profile to receive notifications. Please update your profile.
                            <?php else: ?>
                            You don't have any notifications for your specialties at the moment. Check back later.
                            <?php endif; ?>
                        </p>
                        <?php if($type !== 'all' || $status !== 'all' || $readFilter !== 'all' || !empty($searchTerm)): ?>
                        <a href="notifications.php" class="btn btn-primary">Clear Filters</a>
                        <?php elseif(empty($providerSpecialties)): ?>
                        <a href="profile.php" class="btn btn-primary">Update Profile</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <ul class="notification-list">
                    <?php foreach($notifications as $notification): ?>
                    <?php 
                        $typeInfo = getNotificationTypeInfo($notification['type']);
                        $isUnread = !$notification['is_read'];
                    ?>
                    <li class="notification-item <?php echo $isUnread ? 'unread' : ''; ?>">
                        <div class="d-flex">
                            <div class="flex-shrink-0">
                                <div class="notification-icon bg-<?php echo $typeInfo['class']; ?>-light text-<?php echo $typeInfo['class']; ?>">
                                    <i class="fas fa-<?php echo $typeInfo['icon']; ?>"></i>
                                </div>
                            </div>
                            <div class="notification-content">
                                <div class="d-flex flex-wrap align-items-start justify-content-between mb-1">
                                    <span class="type-badge bg-<?php echo $typeInfo['class']; ?>-light text-<?php echo $typeInfo['class']; ?>">
                                        <i class="fas fa-<?php echo $typeInfo['icon']; ?> me-1"></i> <?php echo $typeInfo['label']; ?>
                                    </span>
                                    <div class="ms-2">
                                        <?php echo getStatusLabel($notification['status']); ?>
                                    </div>
                                </div>
                                
                                <h5 class="mb-1"><?php echo e($notification['message']); ?></h5>
                                
                                <?php if(!empty($notification['details'])): ?>
                                <p class="mb-2"><?php echo e($notification['details']); ?></p>
                                <?php endif; ?>
                                
                                <div class="notification-time">
                                    <i class="far fa-clock me-1"></i> <?php echo formatDate($notification['created_at']); ?>
                                </div>
                                
                                <div class="notification-actions">
                                    <?php if($notification['type'] === 'quote_request'): ?>
                                    <a href="create-quote.php?request_id=<?php echo $notification['reference_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-file-invoice-dollar me-1"></i> Create Quote
                                    </a>
                                    <?php elseif($notification['type'] === 'booking'): ?>
                                    <a href="booking-details.php?id=<?php echo $notification['reference_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-calendar-check me-1"></i> View Booking
                                    </a>
                                    <?php elseif($notification['type'] === 'message'): ?>
                                    <a href="messages.php?id=<?php echo $notification['reference_id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-reply me-1"></i> Reply
                                    </a>
                                    <?php elseif($notification['type'] === 'review'): ?>
                                    <a href="reviews.php" class="btn btn-sm btn-primary">
                                        <i class="fas fa-star me-1"></i> View Review
                                    </a>
                                    <?php else: ?>
                                    <a href="notification-details.php?id=<?php echo $notification['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-info-circle me-1"></i> View Details
                                    </a>
                                    <?php endif; ?>
                                    
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <input type="hidden" name="mark_as" value="<?php echo $isUnread ? '1' : '0'; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?php echo $isUnread ? 'success' : 'secondary'; ?>">
                                            <i class="fas fa-<?php echo $isUnread ? 'check' : 'undo'; ?> me-1"></i> 
                                            <?php echo $isUnread ? 'Mark as read' : 'Mark as unread'; ?>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this notification?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash-alt me-1"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <nav aria-label="Notifications pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . ($page - 1) . '&limit=' . $limit; ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $startPage + 4);
                        if ($endPage - $startPage < 4 && $totalPages > 5) {
                            $startPage = max(1, $endPage - 4);
                        }
                        ?>
                        
                        <?php if($startPage > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=1&limit=' . $limit; ?>">1</a>
                        </li>
                        <?php if($startPage > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . $i . '&limit=' . $limit; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if($endPage < $totalPages): ?>
                        <?php if($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . $totalPages . '&limit=' . $limit; ?>"><?php echo $totalPages; ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?type=' . $type . '&status=' . $status . '&read=' . $readFilter . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . '&sort=' . $sortBy . '&page=' . ($page + 1) . '&limit=' . $limit; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="py-4 bg-dark text-light mt-auto">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="../privacy.php" class="text-light me-3">Privacy Policy</a>
                    <a href="../terms.php" class="text-light me-3">Terms of Service</a>
                    <a href="../contact.php" class="text-light">Contact Us</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Initialize tooltips and theme toggle -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Theme toggle functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.querySelector('html');
            
            // Check for saved theme preference or use device preference
            const savedTheme = localStorage.getItem('theme');
            
            if (savedTheme) {
                htmlElement.setAttribute('data-bs-theme', savedTheme);
                updateIcon(savedTheme);
            } else {
                // Use device preference if no saved preference
                const prefersDarkMode = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const initialTheme = prefersDarkMode ? 'dark' : 'light';
                htmlElement.setAttribute('data-bs-theme', initialTheme);
                updateIcon(initialTheme);
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                htmlElement.setAttribute('data-bs-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                
                // Also set as cookie for server-side detection
                document.cookie = `theme=${newTheme}; path=/; max-age=31536000`; // 1 year
                
                updateIcon(newTheme);
            });
            
            function updateIcon(theme) {
                if (theme === 'dark') {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                } else {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
        });
    </script>
</body>
</html>