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
$profileImage = '../default.png';
$notifications = [];
$myQuotes = [];
$totalQuotes = 0;
$errors = [];
$success = '';

// Pagination variables
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Validate pagination parameters
if ($page < 1) $page = 1;
if ($limit < 5) $limit = 5;
if ($limit > 50) $limit = 50;

// Filter variables
$status = isset($_GET['status']) ? clean_input($_GET['status']) : 'all';
$deviceType = isset($_GET['device_type']) ? clean_input($_GET['device_type']) : '';
$searchTerm = isset($_GET['search']) ? clean_input($_GET['search']) : '';
$sortBy = isset($_GET['sort']) ? clean_input($_GET['sort']) : 'newest';
$dateRange = isset($_GET['date_range']) ? clean_input($_GET['date_range']) : '';

// Validate status
$validStatuses = ['all', 'pending', 'accepted', 'rejected', 'completed'];
if (!in_array($status, $validStatuses)) {
    $status = 'all';
}

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
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching user/provider data');
}

// Get provider ID
$providerId = $providerData['id'] ?? 0;
if (!$providerId) {
    $errors[] = "Provider profile not found. Please complete your profile setup.";
}

// Get notifications
try {
    $notifQuery = "
        SELECT * FROM notifications
        WHERE provider_id = ? AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([$providerId]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching notifications');
}

// Handle quote actions (delete, update, etc.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Security validation failed. Please try again.";
    } else {
        $quoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : 0;
        $action = clean_input($_POST['action']);

        try {
            // Check if quote exists and belongs to this provider
            $checkQuery = "SELECT * FROM quotes WHERE id = ? AND provider_id = ?";
            $stmt = $pdo->prepare($checkQuery);
            $stmt->execute([$quoteId, $providerId]);
            $quote = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$quote) {
                $errors[] = "Quote not found or you don't have permission to modify it.";
            } else {
                if ($action === 'delete' && $quote['status'] === 'pending') {
                    // Delete the quote
                    $deleteQuery = "DELETE FROM quotes WHERE id = ?";
                    $stmt = $pdo->prepare($deleteQuery);
                    $stmt->execute([$quoteId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $success = "Quote successfully deleted.";
                        
                        // Update the quote request status if needed
                        $updateRequestQuery = "
                            UPDATE quote_requests 
                            SET status = 'pending' 
                            WHERE id = ? AND 
                                  status = 'quoted' AND 
                                  NOT EXISTS (SELECT 1 FROM quotes WHERE request_id = quote_requests.id)
                        ";
                        $stmt = $pdo->prepare($updateRequestQuery);
                        $stmt->execute([$quote['request_id']]);
                        
                        // Redirect to prevent form resubmission
                        header("Location: my-quotes.php?status={$status}&device_type={$deviceType}&search={$searchTerm}&sort={$sortBy}&page={$page}&success=1");
                        exit();
                    } else {
                        $errors[] = "Failed to delete quote.";
                    }
                } elseif ($action === 'withdraw' && $quote['status'] === 'pending') {
                    // Withdraw the quote (similar to delete but mark as withdrawn instead)
                    $withdrawQuery = "UPDATE quotes SET status = 'rejected', updated_at = NOW() WHERE id = ?";
                    $stmt = $pdo->prepare($withdrawQuery);
                    $stmt->execute([$quoteId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $success = "Quote successfully withdrawn.";
                        
                        // Create notification for customer
                        $notifyQuery = "
                            INSERT INTO notifications (
                                customer_id, type, reference_id, message, status
                            ) SELECT 
                                qr.customer_id, 'quote_withdrawn', ?, 'A provider has withdrawn their quote for your request', 'pending'
                            FROM quote_requests qr
                            JOIN quotes q ON qr.id = q.request_id
                            WHERE q.id = ?
                        ";
                        $stmt = $pdo->prepare($notifyQuery);
                        $stmt->execute([$quoteId, $quoteId]);
                        
                        // Redirect to prevent form resubmission
                        header("Location: my-quotes.php?status={$status}&device_type={$deviceType}&search={$searchTerm}&sort={$sortBy}&page={$page}&success=2");
                        exit();
                    } else {
                        $errors[] = "Failed to withdraw quote.";
                    }
                } else {
                    $errors[] = "Invalid action or quote status. You can only delete or withdraw pending quotes.";
                }
            }
        } catch (PDOException $e) {
            handleDatabaseError($e, 'processing quote action');
            $errors[] = "Database error occurred while processing your request.";
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    if ($_GET['success'] == 1) {
        $success = "Quote successfully deleted.";
    } elseif ($_GET['success'] == 2) {
        $success = "Quote successfully withdrawn.";
    }
}

// Build query conditions based on filters
$conditions = ["q.provider_id = ?"]; // Filter by provider ID
$params = [$providerId];

// Define status condition
if ($status !== 'all') {
    $conditions[] = "q.status = ?";
    $params[] = $status;
}

// Define device type condition
if (!empty($deviceType)) {
    $conditions[] = "qr.device_type = ?";
    $params[] = $deviceType;
}

// Define search condition
if (!empty($searchTerm)) {
    $conditions[] = "(qr.issue_description LIKE ? OR u.username LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR qr.device_brand LIKE ? OR qr.device_model LIKE ? OR q.description LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

// Define date range condition
if (!empty($dateRange)) {
    switch ($dateRange) {
        case 'today':
            $conditions[] = "DATE(q.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $conditions[] = "DATE(q.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last_7_days':
            $conditions[] = "q.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'last_30_days':
            $conditions[] = "q.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'this_month':
            $conditions[] = "MONTH(q.created_at) = MONTH(CURDATE()) AND YEAR(q.created_at) = YEAR(CURDATE())";
            break;
        case 'last_month':
            $conditions[] = "
                (MONTH(q.created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) 
                AND YEAR(q.created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)))
            ";
            break;
    }
}

// Combine conditions
$whereClause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Define sorting
$orderClause = "ORDER BY q.created_at DESC"; // Default sorting (newest)

if ($sortBy === 'oldest') {
    $orderClause = "ORDER BY q.created_at ASC";
} elseif ($sortBy === 'price_high') {
    $orderClause = "ORDER BY q.price DESC";
} elseif ($sortBy === 'price_low') {
    $orderClause = "ORDER BY q.price ASC";
} elseif ($sortBy === 'updated') {
    $orderClause = "ORDER BY q.updated_at DESC";
}

// Get total count for pagination
try {
    $countQuery = "
        SELECT COUNT(*) as total
        FROM quotes q
        JOIN quote_requests qr ON q.request_id = qr.id
        JOIN users u ON qr.customer_id = u.id
        $whereClause
    ";
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalQuotes = $result['total'] ?? 0;
} catch (PDOException $e) {
    handleDatabaseError($e, 'counting quotes');
}

// Calculate pagination values
$totalPages = ceil($totalQuotes / $limit);
if ($page > $totalPages && $totalPages > 0) $page = $totalPages;
$offset = ($page - 1) * $limit;

// Get quotes with pagination
try {
    $quotesQuery = "
        SELECT q.*, 
               qr.device_type,
               qr.device_brand,
               qr.device_model,
               qr.issue_description,
               qr.urgency,
               qr.status as request_status,
               u.username as customer_name,
               u.first_name,
               u.last_name,
               u.email as customer_email,
               u.phone as customer_phone,
               (SELECT COUNT(*) FROM quotes WHERE request_id = qr.id) as quote_count
        FROM quotes q
        JOIN quote_requests qr ON q.request_id = qr.id
        JOIN users u ON qr.customer_id = u.id
        $whereClause
        $orderClause
        LIMIT ?, ?
    ";
    
    $stmt = $pdo->prepare($quotesQuery);
    $params[] = $offset;
    $params[] = $limit;
    $stmt->execute($params);
    $myQuotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    handleDatabaseError($e, 'fetching quotes');
    $errors[] = "Failed to retrieve quotes. Please try again later.";
}

// Function to get device type icon
function getDeviceIcon($deviceType) {
    switch ($deviceType) {
        case 'smartphone':
            return '<i class="fas fa-mobile-alt"></i>';
        case 'laptop':
            return '<i class="fas fa-laptop"></i>';
        case 'tablet':
            return '<i class="fas fa-tablet-alt"></i>';
        case 'desktop':
            return '<i class="fas fa-desktop"></i>';
        case 'gaming':
            return '<i class="fas fa-gamepad"></i>';
        case 'tv':
            return '<i class="fas fa-tv"></i>';
        default:
            return '<i class="fas fa-microchip"></i>';
    }
}

// Function to get urgency label and class
function getUrgencyLabel($urgency) {
    switch ($urgency) {
        case 'high':
            return '<span class="badge bg-danger">High</span>';
        case 'medium':
            return '<span class="badge bg-warning text-dark">Medium</span>';
        case 'low':
            return '<span class="badge bg-info text-dark">Low</span>';
        default:
            return '<span class="badge bg-secondary">Standard</span>';
    }
}

// Function to get status label and class
function getStatusLabel($status) {
    switch ($status) {
        case 'pending':
            return '<span class="badge bg-warning text-dark">Pending</span>';
        case 'accepted':
            return '<span class="badge bg-success">Accepted</span>';
        case 'rejected':
            return '<span class="badge bg-danger">Rejected</span>';
        case 'completed':
            return '<span class="badge bg-primary">Completed</span>';
        default:
            return '<span class="badge bg-secondary">Unknown</span>';
    }
}

// Function to format currency
function formatCurrency($amount) {
    return number_format($amount, 2);
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
    <meta name="description" content="Manage your submitted quotes for repair requests - FixItNow Provider Portal">
    <title>My Quotes - FixItNow Provider</title>
    
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
        
        .header-nav a.nav-link .badge {
            position: relative;
            top: -2px;
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
        
        /* Enhanced Notifications */
        .notification-dropdown {
            width: 320px;
            max-height: 480px;
            overflow-y: auto;
            padding: 0;
            border: none;
            border-radius: 0.75rem;
        }
        
        .notification-item {
            transition: background-color var(--transition-speed) ease;
            border-left: 3px solid transparent;
        }
        
        .notification-item:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-left-color: var(--primary-color);
        }
        
        .notification-icon {
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
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
        
        /* Quote Card Styles */
        .quote-card {
            background-color: var(--card-bg);
            border-radius: 1rem;
            border: none;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
            transition: transform var(--transition-speed) ease, box-shadow var(--transition-speed) ease;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        
        .quote-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .quote-card .card-header {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: none;
            padding: 1rem;
        }
        
        .quote-card .device-icon {
            width: 48px;
            height: 48px;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .quote-card .quote-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-color);
        }
        
        .quote-card .quote-price-label {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .quote-card .customer-info {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .quote-card .customer-info .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 1.25rem;
        }
        
        /* Timeline styles */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 7px;
            width: 2px;
            background-color: var(--primary-light);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .timeline-item:last-child {
            margin-bottom: 0;
        }
        
        .timeline-marker {
            position: absolute;
            top: 0;
            left: -2rem;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: var(--primary-color);
            transform: translateX(0);
        }
        
        /* Detail list styles */
        .detail-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .detail-list li {
            display: flex;
            margin-bottom: 0.75rem;
        }
        
        .detail-list .detail-label {
            min-width: 120px;
            color: var(--text-muted);
            font-weight: 500;
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
        
        /* Quick action button */
        .quick-action-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            box-shadow: 0 0.25rem 1rem var(--shadow-color);
            transition: all var(--transition-speed) ease;
            z-index: 100;
        }
        
        .quick-action-btn:hover {
            transform: scale(1.1);
            background-color: var(--primary-hover);
            color: white;
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
        
        /* Quote Detail Section */
        .quote-details {
            background-color: var(--bg-color);
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        
        .quote-details p {
            margin-bottom: 0.5rem;
        }
        
        /* Warranty Badge */
        .warranty-badge {
            display: inline-flex;
            align-items: center;
            background-color: var(--accent-light);
            color: var(--accent-color);
            border-radius: 20px;
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .warranty-badge i {
            margin-right: 0.5rem;
        }
        
        /* SAR Currency Icon */
        .sar-icon {
            height: 20px;
            width: auto;
            vertical-align: -0.1em;
            margin-right: 0.25rem;
        }
        
        /* Modal styles for quote details */
        .modal-quote-details {
            max-width: 900px;
        }
        
        .modal-quote-details .modal-body {
            max-height: 80vh;
            overflow-y: auto;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .sidebar {
                display: none;
            }
            
            .dashboard-content {
                padding: 1.5rem 1rem;
            }
            
            .quote-card .card-header {
                flex-direction: column !important;
                align-items: flex-start !important;
            }
            
            .quote-card .device-info {
                margin-bottom: 1rem;
            }
            
            .quote-price-container {
                margin-top: 1rem;
                width: 100%;
                text-align: left !important;
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
        }
        
        @media (max-width: 576px) {
            .dashboard-content {
                padding: 1rem;
            }
            
            .quote-card {
                margin-bottom: 1rem;
            }
            
            .quote-card .customer-info {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .quote-card .customer-info .customer-avatar {
                margin-bottom: 0.5rem;
            }
            
            .quote-card .action-buttons {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .quote-card .action-buttons .btn {
                width: 100%;
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
                            <i class="fas fa-file-invoice-dollar me-1" aria-hidden="true"></i> Quote Requests
                        </a>
                        <a class="nav-link active" href="my-quotes.php" aria-current="page">
                            <i class="fas fa-comment-dollar me-1" aria-hidden="true"></i> My Quotes
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
                    
                    <!-- Notifications -->
                    <div class="dropdown">
                        <button class="btn btn-icon position-relative notification-badge" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                            <i class="fas fa-bell" aria-hidden="true"></i>
                            <?php if(count($notifications) > 0): ?>
                            <span class="badge bg-danger rounded-pill position-absolute top-0 end-0 translate-middle" aria-label="<?php echo count($notifications); ?> unread notifications"><?php echo count($notifications); ?></span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end notification-dropdown shadow-lg" aria-labelledby="notificationsDropdown">
                            <li><h6 class="dropdown-header d-flex justify-content-between align-items-center">
                                Notifications
                                <?php if(count($notifications) > 0): ?>
                                <a href="mark-all-read.php" class="text-decoration-none text-muted small">
                                    <i class="fas fa-check-double" aria-hidden="true"></i> Mark all read
                                </a>
                                <?php endif; ?>
                            </h6></li>
                            
                            <?php if(empty($notifications)): ?>
                                <li><div class="dropdown-item text-muted d-flex align-items-center py-3">
                                    <div class="text-center w-100">
                                        <i class="fas fa-bell-slash fa-2x mb-2 text-muted" aria-hidden="true"></i>
                                        <p class="mb-0">No new notifications</p>
                                    </div>
                                </div></li>
                            <?php else: ?>
                                <?php foreach($notifications as $notification): ?>
                                <li>
                                    <a class="dropdown-item notification-item p-3 border-bottom" href="notification-details.php?id=<?php echo $notification['id']; ?>">
                                        <div class="d-flex">
                                            <?php 
                                            // Determine icon based on notification type
                                            $icon = 'bell';
                                            $iconClass = 'primary';
                                            if ($notification['type'] == 'quote_request') {
                                                $icon = 'file-invoice-dollar';
                                                $iconClass = 'success';
                                            } elseif ($notification['type'] == 'booking') {
                                                $icon = 'calendar-check';
                                                $iconClass = 'info';
                                            } elseif ($notification['type'] == 'message') {
                                                $icon = 'envelope';
                                                $iconClass = 'warning';
                                            } elseif ($notification['type'] == 'review') {
                                                $icon = 'star';
                                                $iconClass = 'danger';
                                            }
                                            ?>
                                            <div class="flex-shrink-0 notification-icon bg-<?php echo $iconClass; ?>-light text-<?php echo $iconClass; ?> rounded-circle p-2 me-3">
                                                <i class="fas fa-<?php echo $icon; ?>" aria-hidden="true"></i>
                                            </div>
                                            <div class="flex-grow-1 notification-content">
                                                <div class="d-flex w-100 justify-content-between mb-1">
                                                    <strong><?php echo e(truncateText($notification['message'], 40)); ?></strong>
                                                    <small class="text-muted ms-2"><?php echo date('M d', strtotime($notification['created_at'])); ?></small>
                                                </div>
                                                <?php if(!empty($notification['details'])): ?>
                                                <p class="text-muted small mb-0">
                                                    <?php echo e(truncateText($notification['details'], 60)); ?>
                                                </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                                <li><hr class="dropdown-divider m-0"></li>
                                <li><a class="dropdown-item text-center p-2" href="notifications.php">View All Notifications</a></li>
                            <?php endif; ?>
                        </ul>
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
                    <a href="my-quotes.php" class="list-group-item list-group-item-action py-3 active">
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
                    <a href="notifications.php" class="list-group-item list-group-item-action py-3">
                        <i class="fas fa-bell me-2" aria-hidden="true"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger rounded-pill"><?php echo count($notifications); ?></span>
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
                    <a href="my-quotes.php" class="active">
                        <i class="fas fa-comment-dollar"></i> My Quotes
                    </a>
                </li>
                <li>
                   
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
                    <a href="notifications.php">
                        <i class="fas fa-bell"></i> Notifications
                        <?php if(count($notifications) > 0): ?>
                        <span class="badge bg-danger ms-auto"><?php echo count($notifications); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="profile.php">
                        <i class="fas fa-user-cog"></i> Profile Settings
                    </a>
                </li>
                <li>
                    <a href="logout.php">
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
                        <h1 class="mb-1">My Quotes</h1>
                        <p class="text-muted mb-0">Manage and track quotes you've sent to customers</p>
                    </div>
                    <a href="create-quote.php" class="btn btn-primary d-none d-md-block">
                        <i class="fas fa-plus me-2"></i> Create New Quote
                    </a>
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
                
                <!-- Quote Statistics -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="me-3 bg-primary-light text-primary rounded-3 p-2">
                                        <i class="fas fa-file-invoice-dollar fa-fw"></i>
                                    </div>
                                    <h6 class="card-title mb-0">Total Quotes</h6>
                                </div>
                                <h3 class="mb-0"><?php echo $totalQuotes; ?></h3>
                                <div class="text-muted small">All time</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="me-3 bg-warning-light text-warning rounded-3 p-2">
                                        <i class="fas fa-clock fa-fw"></i>
                                    </div>
                                    <h6 class="card-title mb-0">Pending</h6>
                                </div>
                                <h3 class="mb-0">
                                    <?php
                                    $pendingCount = 0;
                                    foreach($myQuotes as $quote) {
                                        if($quote['status'] === 'pending') {
                                            $pendingCount++;
                                        }
                                    }
                                    echo $pendingCount;
                                    ?>
                                </h3>
                                <div class="text-muted small">Awaiting customer response</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="me-3 bg-success-light text-success rounded-3 p-2">
                                        <i class="fas fa-check-circle fa-fw"></i>
                                    </div>
                                    <h6 class="card-title mb-0">Accepted</h6>
                                </div>
                                <h3 class="mb-0">
                                    <?php
                                    $acceptedCount = 0;
                                    foreach($myQuotes as $quote) {
                                        if($quote['status'] === 'accepted') {
                                            $acceptedCount++;
                                        }
                                    }
                                    echo $acceptedCount;
                                    ?>
                                </h3>
                                <div class="text-muted small">Quotes turned into repairs</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-3 col-md-6 mb-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex align-items-center mb-3">
                                    <div class="me-3 bg-danger-light text-danger rounded-3 p-2">
                                        <i class="fas fa-times-circle fa-fw"></i>
                                    </div>
                                    <h6 class="card-title mb-0">Rejected</h6>
                                </div>
                                <h3 class="mb-0">
                                    <?php
                                    $rejectedCount = 0;
                                    foreach($myQuotes as $quote) {
                                        if($quote['status'] === 'rejected') {
                                            $rejectedCount++;
                                        }
                                    }
                                    echo $rejectedCount;
                                    ?>
                                </h3>
                                <div class="text-muted small">Not accepted by customer</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filters Card -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form action="my-quotes.php" method="GET" class="mb-0">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label for="status" class="form-label">Status</label>
                                    <select name="status" id="status" class="form-select">
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="accepted" <?php echo $status === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="device_type" class="form-label">Device Type</label>
                                    <select name="device_type" id="device_type" class="form-select">
                                        <option value="" <?php echo empty($deviceType) ? 'selected' : ''; ?>>All Devices</option>
                                        <option value="smartphone" <?php echo $deviceType === 'smartphone' ? 'selected' : ''; ?>>Smartphone</option>
                                        <option value="laptop" <?php echo $deviceType === 'laptop' ? 'selected' : ''; ?>>Laptop</option>
                                        <option value="tablet" <?php echo $deviceType === 'tablet' ? 'selected' : ''; ?>>Tablet</option>
                                        <option value="desktop" <?php echo $deviceType === 'desktop' ? 'selected' : ''; ?>>Desktop</option>
                                        <option value="gaming" <?php echo $deviceType === 'gaming' ? 'selected' : ''; ?>>Gaming Console</option>
                                        <option value="tv" <?php echo $deviceType === 'tv' ? 'selected' : ''; ?>>TV</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="date_range" class="form-label">Date Range</label>
                                    <select name="date_range" id="date_range" class="form-select">
                                        <option value="" <?php echo empty($dateRange) ? 'selected' : ''; ?>>All Time</option>
                                        <option value="today" <?php echo $dateRange === 'today' ? 'selected' : ''; ?>>Today</option>
                                        <option value="yesterday" <?php echo $dateRange === 'yesterday' ? 'selected' : ''; ?>>Yesterday</option>
                                        <option value="last_7_days" <?php echo $dateRange === 'last_7_days' ? 'selected' : ''; ?>>Last 7 Days</option>
                                        <option value="last_30_days" <?php echo $dateRange === 'last_30_days' ? 'selected' : ''; ?>>Last 30 Days</option>
                                        <option value="this_month" <?php echo $dateRange === 'this_month' ? 'selected' : ''; ?>>This Month</option>
                                        <option value="last_month" <?php echo $dateRange === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="sort" class="form-label">Sort By</label>
                                    <select name="sort" id="sort" class="form-select">
                                        <option value="newest" <?php echo $sortBy === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                                        <option value="oldest" <?php echo $sortBy === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
                                        <option value="price_high" <?php echo $sortBy === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                                        <option value="price_low" <?php echo $sortBy === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                                        <option value="updated" <?php echo $sortBy === 'updated' ? 'selected' : ''; ?>>Recently Updated</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <label for="search" class="form-label">Search</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="search" name="search" placeholder="Search quotes, descriptions, customers..." value="<?php echo e($searchTerm); ?>">
                                        <button class="btn btn-outline-primary" type="submit">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6 d-flex align-items-end justify-content-end mt-3 mt-md-0">
                                    <button type="submit" class="btn btn-primary me-2">
                                        <i class="fas fa-filter me-1"></i> Apply Filters
                                    </button>
                                    <a href="my-quotes.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-1"></i> Clear Filters
                                    </a>
                                </div>
                            </div>
                            
                            <!-- Active Filters -->
                            <?php if($status !== 'all' || !empty($deviceType) || !empty($searchTerm) || !empty($dateRange) || $sortBy !== 'newest'): ?>
                            <div class="mt-3 pt-3 border-top">
                                <div class="d-flex align-items-center">
                                    <strong class="me-2">Active Filters:</strong>
                                    <div>
                                        <?php if($status !== 'all'): ?>
                                        <div class="filter-pill">
                                            Status: <?php echo ucfirst($status); ?>
                                            <a href="<?php echo '?status=all' . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove status filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($deviceType)): ?>
                                        <div class="filter-pill">
                                            Device: <?php echo ucfirst($deviceType); ?>
                                            <a href="<?php echo '?status=' . $status . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove device type filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($dateRange)): ?>
                                        <div class="filter-pill">
                                            Date: <?php echo ucfirst(str_replace('_', ' ', $dateRange)); ?>
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove date range filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($searchTerm)): ?>
                                        <div class="filter-pill">
                                            Search: "<?php echo e($searchTerm); ?>"
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . ($sortBy !== 'newest' ? '&sort=' . $sortBy : ''); ?>" class="remove-filter" aria-label="Remove search filter">
                                                <i class="fas fa-times-circle"></i>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if($sortBy !== 'newest'): ?>
                                        <div class="filter-pill">
                                            Sort: <?php echo ucfirst(str_replace('_', ' ', $sortBy)); ?>
                                            <a href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : ''); ?>" class="remove-filter" aria-label="Remove sort filter">
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
                        <strong><?php echo $totalQuotes; ?></strong> quote<?php echo $totalQuotes !== 1 ? 's' : ''; ?> found
                        <?php if($status !== 'all'): ?>
                        with status <strong><?php echo ucfirst($status); ?></strong>
                        <?php endif; ?>
                        <?php if(!empty($deviceType)): ?>
                        for <strong><?php echo ucfirst($deviceType); ?></strong> devices
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-flex align-items-center">
                        <label for="limit" class="form-label mb-0 me-2">Show:</label>
                        <select id="limit" name="limit" class="form-select form-select-sm" style="width: auto;" onchange="window.location.href='?status=<?php echo $status; ?>&device_type=<?php echo $deviceType; ?>&search=<?php echo urlencode($searchTerm); ?>&date_range=<?php echo $dateRange; ?>&sort=<?php echo $sortBy; ?>&page=1&limit='+this.value">
                            <option value="10" <?php echo $limit === 10 ? 'selected' : ''; ?>>10</option>
                            <option value="25" <?php echo $limit === 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                        </select>
                    </div>
                </div>
                
                <!-- Quotes List -->
                <?php if(empty($myQuotes)): ?>
                <div class="card mb-4">
                    <div class="card-body empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <h3>No Quotes Found</h3>
                        <p class="text-muted mb-3">
                            <?php if(!empty($searchTerm) || !empty($deviceType) || $status !== 'all' || !empty($dateRange)): ?>
                            No quotes match your current filter criteria. Try adjusting your filters.
                            <?php else: ?>
                            You haven't created any quotes yet. Start by creating a new quote for a repair request.
                            <?php endif; ?>
                        </p>
                        <?php if(!empty($searchTerm) || !empty($deviceType) || $status !== 'all' || !empty($dateRange)): ?>
                        <a href="my-quotes.php" class="btn btn-primary">Clear Filters</a>
                        <?php else: ?>
                        <a href="quote-requests.php" class="btn btn-primary">Browse Quote Requests</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                    <?php foreach($myQuotes as $quote): ?>
                    <div class="card quote-card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <div class="device-icon me-3">
                                    <?php echo getDeviceIcon($quote['device_type']); ?>
                                </div>
                                <div>
                                    <h5 class="mb-0"><?php echo ucfirst($quote['device_type']); ?> Repair</h5>
                                    <div class="text-muted small">Quote #<?php echo $quote['id']; ?> • <?php echo formatDate($quote['created_at']); ?></div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="text-end quote-price-container">
                                    <div class="quote-price-label">Quoted Price</div>
                                    <div class="quote-price">
                                        <img src="../sar/sar.png" alt="SAR" class="sar-icon">
                                        <?php echo formatCurrency($quote['price']); ?>
                                    </div>
                                </div>
                                <div class="ms-3"><?php echo getStatusLabel($quote['status']); ?></div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="customer-info mb-3">
                                        <div class="customer-avatar">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div>
                                            <h6 class="mb-0"><?php echo e($quote['first_name'] . ' ' . $quote['last_name']); ?></h6>
                                            <div class="text-muted small">
                                                <a href="mailto:<?php echo e($quote['customer_email']); ?>" class="text-decoration-none">
                                                    <i class="fas fa-envelope me-1"></i> <?php echo e($quote['customer_email']); ?>
                                                </a>
                                                <?php if(!empty($quote['customer_phone'])): ?>
                                                <span class="mx-1">•</span>
                                                <a href="tel:<?php echo e($quote['customer_phone']); ?>" class="text-decoration-none">
                                                    <i class="fas fa-phone me-1"></i> <?php echo e($quote['customer_phone']); ?>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <h6>Original Request</h6>
                                    <p class="mb-3"><?php echo e(truncateText($quote['issue_description'], 200)); ?></p>
                                    
                                    <?php if($quote['quote_count'] > 1): ?>
                                    <div class="alert alert-info d-flex align-items-center small" role="alert">
                                        <i class="fas fa-info-circle me-2"></i>
                                        <div>
                                            This request has <?php echo $quote['quote_count'] - 1; ?> other quote<?php echo $quote['quote_count'] - 1 > 1 ? 's' : ''; ?> from other providers.
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <div class="quote-details">
                                        <h6>Your Quote Details</h6>
                                        <ul class="detail-list mb-3">
                                            <li>
                                                <span class="detail-label">Price:</span>
                                                <span class="fw-semibold text-success">
                                                    <img src="../sar/sar.png" alt="SAR" class="sar-icon">
                                                    <?php echo formatCurrency($quote['price']); ?>
                                                </span>
                                            </li>
                                            <li>
                                                <span class="detail-label">Estimated Time:</span>
                                                <span><?php echo e($quote['estimated_time']); ?></span>
                                            </li>
                                            <li>
                                                <span class="detail-label">Warranty:</span>
                                                <span class="warranty-badge">
                                                    <i class="fas fa-shield-alt"></i>
                                                    <?php echo e($quote['warranty'] ?: 'Standard warranty'); ?>
                                                </span>
                                            </li>
                                            <li>
                                                <span class="detail-label">Sent:</span>
                                                <span><?php echo date('M d, Y g:i A', strtotime($quote['created_at'])); ?></span>
                                            </li>
                                            <?php if($quote['created_at'] != $quote['updated_at']): ?>
                                            <li>
                                                <span class="detail-label">Last Updated:</span>
                                                <span><?php echo date('M d, Y g:i A', strtotime($quote['updated_at'])); ?></span>
                                            </li>
                                            <?php endif; ?>
                                            <?php if($quote['status'] === 'accepted' || $quote['status'] === 'rejected'): ?>
                                            <li>
                                                <span class="detail-label">Response:</span>
                                                <span><?php echo $quote['status'] === 'accepted' ? '<span class="text-success">Accepted</span>' : '<span class="text-danger">Rejected</span>'; ?></span>
                                            </li>
                                            <?php endif; ?>
                                        </ul>
                                        
                                        <h6>Description</h6>
                                        <p><?php echo e(truncateText($quote['description'], 150)); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="text-muted">Quote ID: #<?php echo $quote['id']; ?></span>
                                </div>
                                <div class="action-buttons d-flex gap-2">
                                    <?php if($quote['status'] === 'pending'): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="withdraw">
                                        <input type="hidden" name="quote_id" value="<?php echo $quote['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Are you sure you want to withdraw this quote? This action cannot be undone.')">
                                            <i class="fas fa-undo me-1"></i> Withdraw
                                        </button>
                                    </form>
                                    
                                    <a href="edit-quote.php?id=<?php echo $quote['id']; ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-edit me-1"></i> Edit Quote
                                    </a>
                                    <?php elseif($quote['status'] === 'accepted'): ?>
                                    <a href="repair-details.php?quote_id=<?php echo $quote['id']; ?>" class="btn btn-sm btn-success">
                                        <i class="fas fa-tools me-1"></i> View Repair
                                    </a>
                                    <?php endif; ?>
                                    
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#quoteDetailsModal<?php echo $quote['id']; ?>">
                                        <i class="fas fa-info-circle me-1"></i> Full Details
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quote Details Modal -->
                    <div class="modal fade" id="quoteDetailsModal<?php echo $quote['id']; ?>" tabindex="-1" aria-labelledby="quoteDetailsModalLabel<?php echo $quote['id']; ?>" aria-hidden="true">
                        <div class="modal-dialog modal-lg modal-quote-details">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="quoteDetailsModalLabel<?php echo $quote['id']; ?>">
                                        Quote #<?php echo $quote['id']; ?> Details
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="card mb-3">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Customer Information</h6>
                                                </div>
                                                <div class="card-body">
                                                    <ul class="detail-list">
                                                        <li>
                                                            <span class="detail-label">Name:</span>
                                                            <span><?php echo e($quote['first_name'] . ' ' . $quote['last_name']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Email:</span>
                                                            <span><?php echo e($quote['customer_email']); ?></span>
                                                        </li>
                                                        <?php if(!empty($quote['customer_phone'])): ?>
                                                        <li>
                                                            <span class="detail-label">Phone:</span>
                                                            <span><?php echo e($quote['customer_phone']); ?></span>
                                                        </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <div class="card mb-3">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Device Information</h6>
                                                </div>
                                                <div class="card-body">
                                                    <ul class="detail-list">
                                                        <li>
                                                            <span class="detail-label">Type:</span>
                                                            <span><?php echo ucfirst($quote['device_type']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Brand:</span>
                                                            <span><?php echo e($quote['device_brand']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Model:</span>
                                                            <span><?php echo e($quote['device_model']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Urgency:</span>
                                                            <span><?php echo getUrgencyLabel($quote['urgency']); ?></span>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <div class="card">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Request Details</h6>
                                                </div>
                                                <div class="card-body">
                                                    <div>
                                                        <h6>Issue Description</h6>
                                                        <p><?php echo e($quote['issue_description']); ?></p>
                                                    </div>
                                                    
                                                    <div class="mt-3">
                                                        <h6>Request Status</h6>
                                                        <p>
                                                            <?php echo getStatusLabel($quote['request_status']); ?>
                                                            <span class="text-muted ms-2">(<?php echo $quote['quote_count']; ?> total quotes)</span>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="col-md-6">
                                            <div class="card mb-3">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Quote Information</h6>
                                                </div>
                                                <div class="card-body">
                                                    <ul class="detail-list">
                                                        <li>
                                                            <span class="detail-label">Status:</span>
                                                            <span><?php echo getStatusLabel($quote['status']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Price:</span>
                                                            <span class="fw-semibold text-success">
                                                                <img src="../sar/sar.png" alt="SAR" class="sar-icon">
                                                                <?php echo formatCurrency($quote['price']); ?>
                                                            </span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Time Estimate:</span>
                                                            <span><?php echo e($quote['estimated_time']); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Warranty:</span>
                                                            <span class="warranty-badge">
                                                                <i class="fas fa-shield-alt"></i>
                                                                <?php echo e($quote['warranty'] ?: 'Standard warranty'); ?>
                                                            </span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Created:</span>
                                                            <span><?php echo date('M d, Y g:i A', strtotime($quote['created_at'])); ?></span>
                                                        </li>
                                                        <li>
                                                            <span class="detail-label">Last Updated:</span>
                                                            <span><?php echo date('M d, Y g:i A', strtotime($quote['updated_at'])); ?></span>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                            
                                            <div class="card">
                                                <div class="card-header">
                                                    <h6 class="mb-0">Quote Details</h6>
                                                </div>
                                                <div class="card-body">
                                                    <div>
                                                        <h6>Description</h6>
                                                        <p><?php echo e($quote['description']); ?></p>
                                                    </div>
                                                    
                                                    <?php if(!empty($quote['parts_needed'])): ?>
                                                    <div class="mt-3">
                                                        <h6>Parts Needed</h6>
                                                        <p><?php echo e($quote['parts_needed']); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if(!empty($quote['notes'])): ?>
                                                    <div class="mt-3">
                                                        <h6>Additional Notes</h6>
                                                        <p><?php echo e($quote['notes']); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <?php if($quote['status'] === 'pending'): ?>
                                    <div class="me-auto">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="action" value="withdraw">
                                            <input type="hidden" name="quote_id" value="<?php echo $quote['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to withdraw this quote? This action cannot be undone.')">
                                                <i class="fas fa-undo me-1"></i> Withdraw Quote
                                            </button>
                                        </form>
                                    </div>
                                    <a href="edit-quote.php?id=<?php echo $quote['id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-edit me-1"></i> Edit Quote
                                    </a>
                                    <?php elseif($quote['status'] === 'accepted'): ?>
                                    <a href="repair-details.php?quote_id=<?php echo $quote['id']; ?>" class="btn btn-success">
                                        <i class="fas fa-tools me-1"></i> View Repair
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if($totalPages > 1): ?>
                <nav aria-label="Quotes pagination">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . '&sort=' . $sortBy . '&page=' . ($page - 1) . '&limit=' . $limit; ?>" aria-label="Previous">
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
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . '&sort=' . $sortBy . '&page=1&limit=' . $limit; ?>">1</a>
                        </li>
                        <?php if($startPage > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <?php endif; ?>
                        
                        <?php for($i = $startPage; $i <= $endPage; $i++): ?>
                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . '&sort=' . $sortBy . '&page=' . $i . '&limit=' . $limit; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php if($endPage < $totalPages): ?>
                        <?php if($endPage < $totalPages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . '&sort=' . $sortBy . '&page=' . $totalPages . '&limit=' . $limit; ?>"><?php echo $totalPages; ?></a>
                        </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo '?status=' . $status . (!empty($deviceType) ? '&device_type=' . $deviceType : '') . (!empty($searchTerm) ? '&search=' . urlencode($searchTerm) : '') . (!empty($dateRange) ? '&date_range=' . $dateRange : '') . '&sort=' . $sortBy . '&page=' . ($page + 1) . '&limit=' . $limit; ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Quick Action Floating Button (Mobile Only) -->
    <a href="create-quote.php" class="quick-action-btn d-md-none">
        <i class="fas fa-plus"></i>
    </a>

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
    
    <!-- Initialize tooltips and other functionality -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap tooltips
            const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Theme toggler functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            
            if (themeToggle) {
                themeToggle.addEventListener('click', function() {
                    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    // Update HTML attribute
                    document.documentElement.setAttribute('data-bs-theme', newTheme);
                    
                    // Update icon
                    themeIcon.classList.remove(currentTheme === 'dark' ? 'fa-moon' : 'fa-sun');
                    themeIcon.classList.add(newTheme === 'dark' ? 'fa-sun' : 'fa-moon');
                    
                    // Save preference to cookie
                    document.cookie = `theme=${newTheme}; path=/; max-age=31536000`; // 1 year
                });
                
                // Update icon on initial load based on current theme
                const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                if (currentTheme === 'dark') {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                }
            }
            
            // Add confirmation for withdrawal actions
            const withdrawForms = document.querySelectorAll('form[method="POST"]');
            withdrawForms.forEach(form => {
                if (form.querySelector('input[name="action"][value="withdraw"]')) {
                    form.addEventListener('submit', function(e) {
                        if (!confirm('Are you sure you want to withdraw this quote? This action cannot be undone.')) {
                            e.preventDefault();
                        }
                    });
                }
            });
            
            // Initialize modals with dynamic content
            const quoteDetailsModals = document.querySelectorAll('[id^="quoteDetailsModal"]');
            quoteDetailsModals.forEach(modal => {
                const modalInstance = new bootstrap.Modal(modal);
                
                // Optional: Add callback for when modal is shown
                modal.addEventListener('shown.bs.modal', function (event) {
                    // You can add code here if needed
                });
            });
        });
    </script>
</body>
</html>