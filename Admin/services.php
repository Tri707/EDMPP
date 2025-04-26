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

// Get admin profile information
$adminProfileImage = '../default.png';

try {
    // Query to get admin user data
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$userId]);
    $adminData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set profile image path
    if (!empty($adminData['profile_image'])) {
        if (preg_match('/^https?:\/\//', $adminData['profile_image'])) {
            $adminProfileImage = $adminData['profile_image'];
        } else {
            $imagePath = '../profile_images/' . basename($adminData['profile_image']);
            if (file_exists($imagePath)) {
                $adminProfileImage = $imagePath;
            }
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching admin data: " . $e->getMessage());
}

// Process AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        // Add new service
        if ($_POST['action'] === 'add_service') {
            $providerId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
            $category = isset($_POST['category']) ? trim($_POST['category']) : '';
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
            $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 60;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate input
            if (!$providerId) {
                throw new Exception('Provider is required');
            }
            
            if (empty($name)) {
                throw new Exception('Service name is required');
            }
            
            if (empty($category)) {
                throw new Exception('Category is required');
            }
            
            if ($price <= 0) {
                throw new Exception('Price must be greater than zero');
            }
            
            if ($duration <= 0) {
                throw new Exception('Duration must be greater than zero');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Insert new service
                $stmt = $pdo->prepare("
                    INSERT INTO services (
                        provider_id, category, name, description, 
                        price, duration, is_active, deleted_by_provider
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, 0
                    )
                ");
                
                $stmt->execute([
                    $providerId, $category, $name, $description, 
                    $price, $duration, $isActive
                ]);
                
                $serviceId = $pdo->lastInsertId();
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Service added successfully',
                    'service_id' => $serviceId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        // Update service
        elseif ($_POST['action'] === 'update_service') {
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            $providerId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
            $category = isset($_POST['category']) ? trim($_POST['category']) : '';
            $name = isset($_POST['name']) ? trim($_POST['name']) : '';
            $description = isset($_POST['description']) ? trim($_POST['description']) : '';
            $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
            $duration = isset($_POST['duration']) ? (int)$_POST['duration'] : 60;
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            // Validate input
            if (!$serviceId) {
                throw new Exception('Invalid service ID');
            }
            
            if (!$providerId) {
                throw new Exception('Provider is required');
            }
            
            if (empty($name)) {
                throw new Exception('Service name is required');
            }
            
            if (empty($category)) {
                throw new Exception('Category is required');
            }
            
            if ($price <= 0) {
                throw new Exception('Price must be greater than zero');
            }
            
            if ($duration <= 0) {
                throw new Exception('Duration must be greater than zero');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // If service is being reactivated, clear the deleted_by_provider flag
                $deletedFlagUpdate = "";
                if ($isActive == 1) {
                    $deletedFlagUpdate = ", deleted_by_provider = 0";
                }
                
                // Update service
                $stmt = $pdo->prepare("
                    UPDATE services 
                    SET provider_id = ?, category = ?, name = ?, description = ?,
                        price = ?, duration = ?, is_active = ? $deletedFlagUpdate, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $providerId, $category, $name, $description, 
                    $price, $duration, $isActive, $serviceId
                ]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Service not found or no changes made');
                }
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Service updated successfully',
                    'service_id' => $serviceId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        // Delete service
        elseif ($_POST['action'] === 'delete_service') {
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            
            if (!$serviceId) {
                throw new Exception('Invalid service ID');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Check if service is being used in any bookings
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM bookings WHERE service_id = ?
                ");
                $stmt->execute([$serviceId]);
                $bookingCount = $stmt->fetchColumn();
                
                if ($bookingCount > 0) {
                    throw new Exception('Cannot delete service that is used in bookings. Consider deactivating it instead.');
                }
                
                // Delete service
                $stmt = $pdo->prepare("
                    DELETE FROM services WHERE id = ?
                ");
                $stmt->execute([$serviceId]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Service not found');
                }
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Service deleted successfully',
                    'service_id' => $serviceId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        // Toggle service active status
        elseif ($_POST['action'] === 'toggle_service_status') {
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            $isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
            
            if (!$serviceId) {
                throw new Exception('Invalid service ID');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Update service status
                $deletedFlagUpdate = "";
                if ($isActive == 1) {
                    $deletedFlagUpdate = ", deleted_by_provider = 0"; // Clear deleted flag when reactivating
                }
                
                // Update service status
                $stmt = $pdo->prepare("
                    UPDATE services 
                    SET is_active = ? $deletedFlagUpdate, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([$isActive, $serviceId]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Service not found');
                }
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Service status updated successfully',
                    'service_id' => $serviceId,
                    'is_active' => $isActive
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        // Mark service as deleted by provider
        elseif ($_POST['action'] === 'restore_service') {
            $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
            
            if (!$serviceId) {
                throw new Exception('Invalid service ID');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            try {
                // Update service - mark as active and clear the deleted flag
                $stmt = $pdo->prepare("
                    UPDATE services 
                    SET is_active = 1, deleted_by_provider = 0, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $stmt->execute([$serviceId]);
                
                if ($stmt->rowCount() === 0) {
                    throw new Exception('Service not found');
                }
                
                // Commit transaction
                $pdo->commit();
                
                echo json_encode([
                    'status' => 'success', 
                    'message' => 'Service restored successfully',
                    'service_id' => $serviceId
                ]);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $pdo->rollBack();
                throw new Exception('Database error: ' . $e->getMessage());
            }
        }
        
        else {
            throw new Exception('Invalid action');
        }
        
    } catch (Exception $e) {
        error_log("Service action error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Get filter values
$categoryFilter = isset($_GET['category']) ? $_GET['category'] : '';
$providerFilter = isset($_GET['provider']) ? (int)$_GET['provider'] : 0;
$statusFilter = isset($_GET['status']) ? $_GET['status'] : '';
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Build query
$query = "
    SELECT s.*, 
           u.first_name as provider_first_name, 
           u.last_name as provider_last_name
    FROM services s
    JOIN providers p ON s.provider_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";

$countQuery = "
    SELECT COUNT(*) 
    FROM services s
    JOIN providers p ON s.provider_id = p.id
    JOIN users u ON p.user_id = u.id
    WHERE 1=1
";

$queryParams = [];

// Add filters
if (!empty($searchQuery)) {
    $query .= " AND (
        s.name LIKE ? OR 
        s.description LIKE ? OR
        s.category LIKE ? OR
        u.first_name LIKE ? OR
        u.last_name LIKE ? OR
        CONCAT(u.first_name, ' ', u.last_name) LIKE ?
    )";
    $countQuery .= " AND (
        s.name LIKE ? OR 
        s.description LIKE ? OR
        s.category LIKE ? OR
        u.first_name LIKE ? OR
        u.last_name LIKE ? OR
        CONCAT(u.first_name, ' ', u.last_name) LIKE ?
    )";
    
    $searchParam = "%{$searchQuery}%";
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
    $queryParams[] = $searchParam;
}

if (!empty($categoryFilter)) {
    $query .= " AND s.category = ?";
    $countQuery .= " AND s.category = ?";
    $queryParams[] = $categoryFilter;
}

if ($providerFilter > 0) {
    $query .= " AND s.provider_id = ?";
    $countQuery .= " AND s.provider_id = ?";
    $queryParams[] = $providerFilter;
}

if ($statusFilter === 'active') {
    $query .= " AND s.is_active = 1";
    $countQuery .= " AND s.is_active = 1";
} elseif ($statusFilter === 'inactive') {
    $query .= " AND s.is_active = 0 AND (s.deleted_by_provider = 0 OR s.deleted_by_provider IS NULL)";
    $countQuery .= " AND s.is_active = 0 AND (s.deleted_by_provider = 0 OR s.deleted_by_provider IS NULL)";
} elseif ($statusFilter === 'deleted') {
    $query .= " AND s.deleted_by_provider = 1";
    $countQuery .= " AND s.deleted_by_provider = 1";
}

// Add sorting and pagination
$query .= " ORDER BY s.created_at DESC LIMIT {$perPage} OFFSET {$offset}";

// First, alter the services table to add the deleted_by_provider flag if it doesn't exist
try {
    // Check if column exists
    $checkColumnQuery = "SHOW COLUMNS FROM services LIKE 'deleted_by_provider'";
    $stmt = $pdo->query($checkColumnQuery);
    
    if ($stmt->rowCount() == 0) {
        // Column doesn't exist, add it
        $alterTableQuery = "ALTER TABLE services ADD COLUMN deleted_by_provider TINYINT(1) DEFAULT 0";
        $pdo->exec($alterTableQuery);
    }
} catch (PDOException $e) {
    error_log("Error checking/creating deleted_by_provider column: " . $e->getMessage());
}

// Get services
$services = [];
$totalServices = 0;

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($queryParams);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($queryParams);
    $totalServices = $countStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database error fetching services: " . $e->getMessage());
}

// Calculate pagination values
$totalPages = ceil($totalServices / $perPage);
$prevPage = ($page > 1) ? $page - 1 : null;
$nextPage = ($page < $totalPages) ? $page + 1 : null;

// Build pagination URL
function buildPaginationUrl($page, $currentParams = []) {
    $params = $_GET;
    $params['page'] = $page;
    
    if (!empty($currentParams)) {
        $params = array_merge($params, $currentParams);
    }
    
    return '?' . http_build_query($params);
}

// Get service categories
$categories = [];

try {
    $stmt = $pdo->query("
        SELECT DISTINCT category
        FROM services
        ORDER BY category
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Database error fetching categories: " . $e->getMessage());
}

// Get providers for dropdown
$providers = [];

try {
    $stmt = $pdo->query("
        SELECT p.id, u.first_name, u.last_name, u.email
        FROM providers p
        JOIN users u ON p.user_id = u.id
        WHERE u.status = 'active'
        ORDER BY u.first_name, u.last_name
    ");
    $providers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching providers: " . $e->getMessage());
}

// Get service statistics
$stats = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'deleted' => 0
];

try {
    // Total services
    $stmt = $pdo->query("SELECT COUNT(*) FROM services");
    $stats['total'] = $stmt->fetchColumn();
    
    // Active services
    $stmt = $pdo->query("SELECT COUNT(*) FROM services WHERE is_active = 1");
    $stats['active'] = $stmt->fetchColumn();
    
    // Inactive services
    $stmt = $pdo->query("SELECT COUNT(*) FROM services WHERE is_active = 0 AND (deleted_by_provider = 0 OR deleted_by_provider IS NULL)");
    $stats['inactive'] = $stmt->fetchColumn();
    
    // Deleted services
    $stmt = $pdo->query("SELECT COUNT(*) FROM services WHERE deleted_by_provider = 1");
    $stats['deleted'] = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database error fetching service stats: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services - FixItNow Admin</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Toastr for notifications -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
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
            --danger-color: #dc3545;       /* Danger/red color */
            --danger-light: #f8d7da;       /* Light danger background */
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
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --danger-light: #482930;       /* Darker danger background for dark mode */
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
        
        /* Stat Cards */
        .stat-card {
            border-radius: 0.75rem;
            background-color: var(--card-bg);
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            border-left: 4px solid transparent;
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .stat-card.primary {
            border-left-color: var(--primary-color);
        }
        
        .stat-card.success {
            border-left-color: var(--accent-color);
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        
        .stat-card.danger {
            border-left-color: var(--danger-color);
        }
        
        .stat-card.info {
            border-left-color: #0dcaf0;
        }
        
        .stat-card .stat-icon {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.5rem;
            opacity: 0.2;
        }
        
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .stat-label {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.5px;
        }
        
        /* Status badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
            text-transform: capitalize;
        }
        
        .status-badge.active {
            background-color: rgba(25, 135, 84, 0.2);
            color: #4cd963;
        }
        
        .status-badge.inactive {
            background-color: rgba(108, 117, 125, 0.2);
            color: #6c757d;
        }
        
        .status-badge.deleted {
            background-color: rgba(220, 53, 69, 0.2);
            color: var(--danger-color);
        }
        
        /* Table Styles */
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            padding: 1rem;
            vertical-align: middle;
            border-bottom-width: 1px;
        }
        
        .table td {
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr {
            transition: all 0.3s ease;
        }
        
        .table tbody tr:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.05);
        }
        
        .service-row-inactive {
            opacity: 0.6;
        }
        
        .service-row-deleted {
            position: relative;
            background-color: var(--danger-light);
        }
        
        .service-row-deleted::before {
            content: 'Deleted by Provider';
            position: absolute;
            top: 0.25rem;
            right: 0.5rem;
            font-size: 0.7rem;
            color: var(--danger-color);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            opacity: 0.7;
        }
        
        /* User profile in table */
        .user-profile {
            display: flex;
            align-items: center;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            line-height: 1.2;
        }
        
        .user-email {
            font-size: 0.8125rem;
            color: var(--text-muted);
        }
        
        /* Filter card */
        .filter-card {
            background-color: var(--card-bg);
            border-radius: 0.75rem;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
            border-top: 4px solid var(--primary-color);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .filter-card .form-control,
        .filter-card .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .filter-card .form-control:focus,
        .filter-card .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
        }
        
        /* Pagination */
        .pagination {
            margin-bottom: 0;
        }
        
        .pagination .page-link {
            border-radius: 0.5rem;
            margin: 0 0.2rem;
            border: none;
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 0.5rem 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .pagination .page-link:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .pagination .page-item.disabled .page-link {
            background-color: transparent;
            color: var(--text-muted);
        }
        
        /* Action buttons */
        .action-btn {
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            margin-right: 0.25rem;
        }
        
        .action-btn:hover {
            background-color: var(--primary-color);
            color: #fff;
        }
        
        .action-btn.danger {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        .action-btn.danger:hover {
            background-color: var(--bs-danger);
            color: #fff;
        }
        
        .action-btn.success {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        .action-btn.success:hover {
            background-color: var(--bs-success);
            color: #fff;
        }
        
        .action-btn.warning {
            background-color: rgba(var(--bs-warning-rgb), 0.1);
            color: var(--bs-warning);
        }
        
        .action-btn.warning:hover {
            background-color: var(--bs-warning);
            color: #fff;
        }
        
        .action-btn.info {
            background-color: rgba(var(--bs-info-rgb), 0.1);
            color: var(--bs-info);
        }
        
        .action-btn.info:hover {
            background-color: var(--bs-info);
            color: #fff;
        }
        
        /* Form controls */
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
        
        /* Price display */
        .price-display {
            display: flex;
            align-items: center;
            font-weight: 600;
        }
        
        .price-currency {
            height: 16px;
            margin-right: 0.25rem;
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner-container {
            background-color: var(--card-bg);
            padding: 2rem;
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
            text-align: center;
        }
        
        .spinner-border {
            width: 3rem;
            height: 3rem;
            margin-bottom: 1rem;
        }
        
        /* Service category badge */
        .category-badge {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
            padding: 0.25rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 500;
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
                        <a class="nav-link active" href="services.php">
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
            <h1 class="page-title">Services Management</h1>
            
            <!-- Stats Row -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="stat-card primary">
                        <div class="stat-icon">
                            <i class="fas fa-cogs"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['total']; ?></div>
                        <div class="stat-label">Total Services</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['active']; ?></div>
                        <div class="stat-label">Active Services</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card warning">
                        <div class="stat-icon">
                            <i class="fas fa-pause-circle"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['inactive']; ?></div>
                        <div class="stat-label">Inactive Services</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card danger">
                        <div class="stat-icon">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <div class="stat-value"><?php echo $stats['deleted']; ?></div>
                        <div class="stat-label">Deleted Services</div>
                    </div>
                </div>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-card">
                <form method="get" action="services.php" id="filter-form">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" placeholder="Search services..." value="<?php echo htmlspecialchars($searchQuery); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="category" class="form-label">Category</label>
                                <select class="form-select" id="category" name="category">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($category)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-0">
                                <label for="provider" class="form-label">Provider</label>
                                <select class="form-select" id="provider" name="provider">
                                    <option value="">All Providers</option>
                                    <?php foreach ($providers as $provider): ?>
                                    <option value="<?php echo $provider['id']; ?>" <?php echo $providerFilter === (int)$provider['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($provider['first_name'] . ' ' . $provider['last_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-0">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php echo $statusFilter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $statusFilter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="deleted" <?php echo $statusFilter === 'deleted' ? 'selected' : ''; ?>>Deleted by Provider</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="d-flex gap-2 w-100">
                                <button type="submit" class="btn btn-primary flex-grow-1">Filter</button>
                                <button type="button" id="reset-filter" class="btn btn-outline-secondary">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Services Table Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Services List</h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                        <i class="fas fa-plus me-2"></i>Add Service
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Category</th>
                                    <th>Provider</th>
                                    <th>Duration</th>
                                    <th>Price</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($services)): ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">No services found</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($services as $service): ?>
                                <?php 
                                    $isDeleted = isset($service['deleted_by_provider']) && $service['deleted_by_provider'] == 1;
                                    $rowClass = $isDeleted ? 'service-row-deleted' : ($service['is_active'] ? '' : 'service-row-inactive');
                                ?>
                                <tr id="service-row-<?php echo $service['id']; ?>" class="<?php echo $rowClass; ?>">
                                    <td>#<?php echo $service['id']; ?></td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <div class="fw-bold"><?php echo htmlspecialchars($service['name']); ?></div>
                                            <div class="text-muted small">
                                                <?php 
                                                $desc = htmlspecialchars($service['description']);
                                                echo strlen($desc) > 50 ? substr($desc, 0, 50) . '...' : $desc; 
                                                ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="category-badge">
                                            <?php echo htmlspecialchars(ucfirst($service['category'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-name">
                                                <?php echo htmlspecialchars($service['provider_first_name'] . ' ' . $service['provider_last_name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo $service['duration']; ?> min</td>
                                    <td>
                                        <div class="price-display">
                                            <img src="sar/sar.png" alt="SAR" class="price-currency">
                                            <?php echo number_format($service['price'], 2); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($isDeleted): ?>
                                        <span class="status-badge deleted">Deleted by Provider</span>
                                        <?php else: ?>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input status-toggle" type="checkbox" role="switch" 
                                                id="status-toggle-<?php echo $service['id']; ?>" 
                                                data-service-id="<?php echo $service['id']; ?>" 
                                                <?php echo $service['is_active'] ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="status-toggle-<?php echo $service['id']; ?>">
                                                <span class="status-badge <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>" id="status-badge-<?php echo $service['id']; ?>">
                                                    <?php echo $service['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </label>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex">
                                            <?php if ($isDeleted): ?>
                                            <button class="action-btn success restore-service-btn" 
                                                data-service-id="<?php echo $service['id']; ?>"
                                                data-service-name="<?php echo htmlspecialchars($service['name']); ?>"
                                                title="Restore Service">
                                                <i class="fas fa-undo-alt"></i>
                                            </button>
                                            <?php else: ?>
                                            <button class="action-btn edit-service-btn" 
                                                data-service-id="<?php echo $service['id']; ?>"
                                                data-service-name="<?php echo htmlspecialchars($service['name']); ?>"
                                                data-service-category="<?php echo htmlspecialchars($service['category']); ?>"
                                                data-service-provider="<?php echo $service['provider_id']; ?>"
                                                data-service-description="<?php echo htmlspecialchars($service['description']); ?>"
                                                data-service-price="<?php echo $service['price']; ?>"
                                                data-service-duration="<?php echo $service['duration']; ?>"
                                                data-service-active="<?php echo $service['is_active']; ?>"
                                                title="Edit Service">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button class="action-btn danger delete-service-btn" 
                                                data-service-id="<?php echo $service['id']; ?>"
                                                data-service-name="<?php echo htmlspecialchars($service['name']); ?>"
                                                title="Delete Service">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Pagination Footer -->
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <div class="text-muted small">
                        Showing <?php echo min(($page - 1) * $perPage + 1, $totalServices); ?> to <?php echo min($page * $perPage, $totalServices); ?> of <?php echo $totalServices; ?> services
                    </div>
                    
                    <?php if ($totalPages > 1): ?>
                    <nav aria-label="Page navigation">
                        <ul class="pagination mb-0">
                            <?php if ($prevPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($prevPage); ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&laquo;</span>
                            </li>
                            <?php endif; ?>
                            
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++):
                            ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo buildPaginationUrl($i); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            
                            <?php if ($nextPage): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo buildPaginationUrl($nextPage); ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                            <?php else: ?>
                            <li class="page-item disabled">
                                <span class="page-link">&raquo;</span>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Service Modal -->
    <div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addServiceModalLabel">Add New Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="addServiceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_service">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add-name" class="form-label">Service Name</label>
                                    <input type="text" class="form-control" id="add-name" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add-category" class="form-label">Category</label>
                                    <input type="text" class="form-control" id="add-category" name="category" list="category-list" required>
                                    <datalist id="category-list">
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add-provider" class="form-label">Provider</label>
                            <select class="form-select" id="add-provider" name="provider_id" required>
                                <option value="">Select Provider</option>
                                <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider['id']; ?>">
                                    <?php echo htmlspecialchars($provider['first_name'] . ' ' . $provider['last_name'] . ' (' . $provider['email'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add-description" class="form-label">Description</label>
                            <textarea class="form-control" id="add-description" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add-price" class="form-label">Price (SAR)</label>
                                    <input type="number" class="form-control" id="add-price" name="price" min="0.01" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add-duration" class="form-label">Duration (minutes)</label>
                                    <input type="number" class="form-control" id="add-duration" name="duration" min="1" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="add-is-active" name="is_active" checked>
                                <label class="form-check-label" for="add-is-active">Active</label>
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
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editServiceModalLabel">Edit Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="editServiceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_service">
                        <input type="hidden" name="service_id" id="edit-service-id">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit-name" class="form-label">Service Name</label>
                                    <input type="text" class="form-control" id="edit-name" name="name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit-category" class="form-label">Category</label>
                                    <input type="text" class="form-control" id="edit-category" name="category" list="edit-category-list" required>
                                    <datalist id="edit-category-list">
                                        <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category); ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit-provider" class="form-label">Provider</label>
                            <select class="form-select" id="edit-provider" name="provider_id" required>
                                <option value="">Select Provider</option>
                                <?php foreach ($providers as $provider): ?>
                                <option value="<?php echo $provider['id']; ?>">
                                    <?php echo htmlspecialchars($provider['first_name'] . ' ' . $provider['last_name'] . ' (' . $provider['email'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit-description" class="form-label">Description</label>
                            <textarea class="form-control" id="edit-description" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit-price" class="form-label">Price (SAR)</label>
                                    <input type="number" class="form-control" id="edit-price" name="price" min="0.01" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit-duration" class="form-label">Duration (minutes)</label>
                                    <input type="number" class="form-control" id="edit-duration" name="duration" min="1" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" id="edit-is-active" name="is_active">
                                <label class="form-check-label" for="edit-is-active">Active</label>
                            </div>
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
                <form id="deleteServiceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="service_id" id="delete-service-id">
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Are you sure you want to delete the service "<span id="delete-service-name"></span>"?
                            <br>
                            This action cannot be undone. Services used in bookings cannot be deleted.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Restore Service Modal -->
    <div class="modal fade" id="restoreServiceModal" tabindex="-1" aria-labelledby="restoreServiceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="restoreServiceModalLabel">Restore Service</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="restoreServiceForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="restore_service">
                        <input type="hidden" name="service_id" id="restore-service-id">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Are you sure you want to restore the service "<span id="restore-service-name"></span>"?
                            <br>
                            This will make the service active and visible to customers again.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Restore Service</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Loading Spinner -->
    <div id="loading-spinner" class="spinner-overlay" style="display: none;">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <div>Processing your request...</div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Toastr JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    
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
            
            // Toastr configuration
            toastr.options = {
                closeButton: true,
                progressBar: true,
                positionClass: "toast-top-right",
                timeOut: 5000
            };
            
            // Reset filter button
            document.getElementById('reset-filter').addEventListener('click', function() {
                window.location.href = 'services.php';
            });
            
            // Edit Service Modal Setup
            const editButtons = document.querySelectorAll('.edit-service-btn');
            const editModal = new bootstrap.Modal(document.getElementById('editServiceModal'));
            
            editButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const serviceId = this.getAttribute('data-service-id');
                    const serviceName = this.getAttribute('data-service-name');
                    const serviceCategory = this.getAttribute('data-service-category');
                    const serviceProvider = this.getAttribute('data-service-provider');
                    const serviceDescription = this.getAttribute('data-service-description');
                    const servicePrice = this.getAttribute('data-service-price');
                    const serviceDuration = this.getAttribute('data-service-duration');
                    const serviceActive = this.getAttribute('data-service-active') === '1';
                    
                    document.getElementById('edit-service-id').value = serviceId;
                    document.getElementById('edit-name').value = serviceName;
                    document.getElementById('edit-category').value = serviceCategory;
                    document.getElementById('edit-provider').value = serviceProvider;
                    document.getElementById('edit-description').value = serviceDescription;
                    document.getElementById('edit-price').value = servicePrice;
                    document.getElementById('edit-duration').value = serviceDuration;
                    document.getElementById('edit-is-active').checked = serviceActive;
                    
                    editModal.show();
                });
            });
            
            // Delete Service Modal Setup
            const deleteButtons = document.querySelectorAll('.delete-service-btn');
            const deleteModal = new bootstrap.Modal(document.getElementById('deleteServiceModal'));
            
            deleteButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const serviceId = this.getAttribute('data-service-id');
                    const serviceName = this.getAttribute('data-service-name');
                    
                    document.getElementById('delete-service-id').value = serviceId;
                    document.getElementById('delete-service-name').textContent = serviceName;
                    
                    deleteModal.show();
                });
            });
            
            // Restore Service Modal Setup
            const restoreButtons = document.querySelectorAll('.restore-service-btn');
            const restoreModal = new bootstrap.Modal(document.getElementById('restoreServiceModal'));
            
            restoreButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const serviceId = this.getAttribute('data-service-id');
                    const serviceName = this.getAttribute('data-service-name');
                    
                    document.getElementById('restore-service-id').value = serviceId;
                    document.getElementById('restore-service-name').textContent = serviceName;
                    
                    restoreModal.show();
                });
            });
            
            // Status Toggle Functionality
            const statusToggles = document.querySelectorAll('.status-toggle');
            
            statusToggles.forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const serviceId = this.getAttribute('data-service-id');
                    const isActive = this.checked ? 1 : 0;
                    
                    // Show loading spinner
                    document.getElementById('loading-spinner').style.display = 'flex';
                    
                    // Send AJAX request to update status
                    const formData = new FormData();
                    formData.append('action', 'toggle_service_status');
                    formData.append('service_id', serviceId);
                    formData.append('is_active', isActive);
                    
                    fetch('services.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Hide loading spinner
                        document.getElementById('loading-spinner').style.display = 'none';
                        
                        if (data.status === 'success') {
                            // Update UI
                            const statusBadge = document.getElementById(`status-badge-${serviceId}`);
                            const serviceRow = document.getElementById(`service-row-${serviceId}`);
                            
                            if (isActive) {
                                statusBadge.className = 'status-badge active';
                                statusBadge.textContent = 'Active';
                                serviceRow.classList.remove('service-row-inactive');
                            } else {
                                statusBadge.className = 'status-badge inactive';
                                statusBadge.textContent = 'Inactive';
                                serviceRow.classList.add('service-row-inactive');
                            }
                            
                            // Show success message
                            toastr.success('Service status updated successfully');
                        } else {
                            // Reset toggle to previous state if there was an error
                            this.checked = !isActive;
                            
                            // Show error message
                            toastr.error(data.message || 'An error occurred while updating service status');
                        }
                    })
                    .catch(error => {
                        // Hide loading spinner
                        document.getElementById('loading-spinner').style.display = 'none';
                        
                        // Reset toggle to previous state
                        this.checked = !isActive;
                        
                        // Show error message
                        toastr.error('An error occurred while communicating with the server');
                        console.error('Error:', error);
                    });
                });
            });
            
            // Add Service Form Submission
            const addServiceForm = document.getElementById('addServiceForm');
            const addModal = new bootstrap.Modal(document.getElementById('addServiceModal'));
            
            addServiceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Get form data
                const formData = new FormData(this);
                
                // Send AJAX request
                fetch('services.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    if (data.status === 'success') {
                        // Show success message
                        toastr.success('Service added successfully');
                        
                        // Hide modal and reset form
                        addModal.hide();
                        this.reset();
                        
                        // Reload page after a short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while adding the service');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server');
                    console.error('Error:', error);
                });
            });
            
            // Edit Service Form Submission
            const editServiceForm = document.getElementById('editServiceForm');
            
            editServiceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Get form data
                const formData = new FormData(this);
                
                // Send AJAX request
                fetch('services.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    if (data.status === 'success') {
                        // Show success message
                        toastr.success('Service updated successfully');
                        
                        // Hide modal
                        editModal.hide();
                        
                        // Reload page after a short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while updating the service');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server');
                    console.error('Error:', error);
                });
            });
            
            // Delete Service Form Submission
            const deleteServiceForm = document.getElementById('deleteServiceForm');
            
            deleteServiceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Get form data
                const formData = new FormData(this);
                
                // Send AJAX request
                fetch('services.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    if (data.status === 'success') {
                        // Show success message
                        toastr.success('Service deleted successfully');
                        
                        // Hide modal
                        deleteModal.hide();
                        
                        // Remove service row from table
                        const serviceId = document.getElementById('delete-service-id').value;
                        const serviceRow = document.getElementById(`service-row-${serviceId}`);
                        
                        if (serviceRow) {
                            serviceRow.remove();
                        }
                        
                        // Reload page after a short delay if no services left
                        if (document.querySelectorAll('tbody tr').length <= 1) {
                            setTimeout(() => {
                                window.location.reload();
                            }, 1000);
                        }
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while deleting the service');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server');
                    console.error('Error:', error);
                });
            });
            
            // Restore Service Form Submission
            const restoreServiceForm = document.getElementById('restoreServiceForm');
            
            restoreServiceForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Show loading spinner
                document.getElementById('loading-spinner').style.display = 'flex';
                
                // Get form data
                const formData = new FormData(this);
                
                // Send AJAX request
                fetch('services.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    if (data.status === 'success') {
                        // Show success message
                        toastr.success('Service restored successfully');
                        
                        // Hide modal
                        restoreModal.hide();
                        
                        // Reload page after a short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        // Show error message
                        toastr.error(data.message || 'An error occurred while restoring the service');
                    }
                })
                .catch(error => {
                    // Hide loading spinner
                    document.getElementById('loading-spinner').style.display = 'none';
                    
                    // Show error message
                    toastr.error('An error occurred while communicating with the server');
                    console.error('Error:', error);
                });
            });
        });
    </script>
</body>
</html>