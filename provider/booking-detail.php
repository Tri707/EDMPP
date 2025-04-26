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
require_once 'conn.php';

// Verify that database connection is established
if (!isset($pdo) || $pdo === null) {
    error_log("Database connection not established. Check conn.php file.");
    header('Location: ../error.php?message=Database%20connection%20failed');
    exit;
}

// CORRECTED: Only allow forward status progression
function isStatusChangeAllowed($currentStatus, $newStatus) {
    // Define status progression levels
    $statusLevels = [
        'pending' => 1,
        'confirmed' => 2,
        'completed' => 3,
        'cancelled' => 0 // Special case that can be set from any status
    ];
    
    // Get levels for current and new status
    $currentLevel = $statusLevels[$currentStatus] ?? 0;
    $newLevel = $statusLevels[$newStatus] ?? 0;
    
    // Allow if:
    // 1. Moving to the next status in progression (higher level)
    // 2. Changing to cancelled status (special case)
    return ($newLevel > $currentLevel) || ($newStatus === 'cancelled');
}

// Define status level for progression visualization
function getStatusLevel($status) {
    $statusLevels = [
        'pending' => 1,
        'confirmed' => 2,
        'completed' => 3,
        'cancelled' => 0 // Special case
    ];
    
    return $statusLevels[$status] ?? 0;
}

// Initialize variables with default values
$providerProfileImage = '../default.png'; // Set default image path
$providerId = 0;
$providerData = [];
$bookingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$bookingData = [];
$customerData = [];
$serviceData = [];
$statusHistory = [];
$messages = [];
$message = '';
$alertType = '';

// Get provider profile information
try {
    // Get provider user data
    $stmt = $pdo->prepare("SELECT 
                            u.*, 
                            p.id as provider_id, 
                            p.is_verified, 
                            p.specialties, 
                            p.experience 
                         FROM users u 
                         JOIN providers p ON u.id = p.user_id 
                         WHERE u.id = ? AND u.role = 'provider' AND u.status = 'active'");
    $stmt->execute([$userId]);
    $providerDataResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($providerDataResult) {
        $providerData = $providerDataResult;
        $providerId = (int)$providerData['provider_id'];
        
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
                } else {
                    $providerProfileImage = '../default.png'; // Use default if file doesn't exist
                }
            }
        } else {
            $providerProfileImage = '../default.png'; // Use default if no image specified
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

// Get booking details
try {
    // Check if a valid booking ID was provided
    if ($bookingId <= 0) {
        header('Location: bookings.php');
        exit;
    }
    
    // Get booking details
    $stmt = $pdo->prepare("SELECT 
                            b.*,
                            s.name as service_name,
                            s.description as service_description,
                            s.duration
                         FROM bookings b 
                         LEFT JOIN services s ON b.service_id = s.id
                         WHERE b.id = ? AND b.provider_id = ?");
    $stmt->execute([$bookingId, $providerId]);
    $bookingData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bookingData) {
        // Booking not found or doesn't belong to this provider
        header('Location: bookings.php?error=booking_not_found');
        exit;
    }
    
    // Get customer information
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer'");
    $stmt->execute([$bookingData['customer_id']]);
    $customerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customerData) {
        // Customer not found
        $customerData = [
            'first_name' => 'Unknown',
            'last_name' => 'Customer',
            'email' => 'N/A',
            'phone' => 'N/A',
            'profile_image' => ''
        ];
    }
    
    // Get booking status history
    $stmt = $pdo->prepare("SELECT 
                            h.*,
                            CASE 
                                WHEN h.created_by = 'provider' THEN CONCAT(p.first_name, ' ', p.last_name, ' (Provider)')
                                WHEN h.created_by = 'customer' THEN CONCAT(c.first_name, ' ', c.last_name, ' (Customer)')
                                WHEN h.created_by = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name, ' (Admin)')
                                ELSE h.created_by
                            END as created_by_name
                         FROM booking_status_history h
                         LEFT JOIN users p ON h.user_id = p.id AND h.created_by = 'provider'
                         LEFT JOIN users c ON h.user_id = c.id AND h.created_by = 'customer'
                         LEFT JOIN users a ON h.user_id = a.id AND h.created_by = 'admin'
                         WHERE h.booking_id = ?
                         ORDER BY h.created_at DESC");
    $stmt->execute([$bookingId]);
    $statusHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get messages related to this booking
    $stmt = $pdo->prepare("SELECT 
                            m.*,
                            CASE 
                                WHEN m.sender_id = ? THEN 'provider'
                                ELSE 'customer'
                            END as sender_type,
                            s.first_name as sender_first_name,
                            s.last_name as sender_last_name,
                            s.profile_image as sender_profile_image
                         FROM messages m
                         JOIN users s ON m.sender_id = s.id
                         WHERE m.booking_id = ? AND (m.sender_id = ? OR m.receiver_id = ?)
                         ORDER BY m.created_at ASC");
    $stmt->execute([$userId, $bookingId, $userId, $userId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Mark all unread messages as read
    $stmt = $pdo->prepare("UPDATE messages 
                          SET is_read = 1 
                          WHERE booking_id = ? AND receiver_id = ? AND is_read = 0");
    $stmt->execute([$bookingId, $userId]);
    
} catch (PDOException $e) {
    error_log("Database error fetching booking details: " . $e->getMessage());
    header('Location: bookings.php?error=database_error');
    exit;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle booking status update
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        try {
            $newStatus = $_POST['new_status'];
            $statusNote = $_POST['status_note'] ?? '';
            
            // Validate status
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid status value.');
            }
            
            // CORRECTED: Check if status change is allowed
            if (!isStatusChangeAllowed($bookingData['status'], $newStatus)) {
                throw new Exception('Status cannot be changed backward in the workflow.');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update booking status
            $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ? AND provider_id = ?");
            $updateStmt->execute([$newStatus, $bookingId, $providerId]);
            
            if ($updateStmt->rowCount() > 0) {
                // Add status history record
                $historyStmt = $pdo->prepare("INSERT INTO booking_status_history 
                                             (booking_id, status, created_by, user_id, notes) 
                                             VALUES (?, ?, 'provider', ?, ?)");
                $historyStmt->execute([$bookingId, $newStatus, $userId, $statusNote]);
                
                // Commit transaction
                $pdo->commit();
                
                // Refresh booking data to show updated status
                $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ?");
                $stmt->execute([$bookingId]);
                $bookingData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Refresh status history
                $stmt = $pdo->prepare("SELECT 
                                        h.*,
                                        CASE 
                                            WHEN h.created_by = 'provider' THEN CONCAT(p.first_name, ' ', p.last_name, ' (Provider)')
                                            WHEN h.created_by = 'customer' THEN CONCAT(c.first_name, ' ', c.last_name, ' (Customer)')
                                            WHEN h.created_by = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name, ' (Admin)')
                                            ELSE h.created_by
                                        END as created_by_name
                                     FROM booking_status_history h
                                     LEFT JOIN users p ON h.user_id = p.id AND h.created_by = 'provider'
                                     LEFT JOIN users c ON h.user_id = c.id AND h.created_by = 'customer'
                                     LEFT JOIN users a ON h.user_id = a.id AND h.created_by = 'admin'
                                     WHERE h.booking_id = ?
                                     ORDER BY h.created_at DESC");
                $stmt->execute([$bookingId]);
                $statusHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $message = 'Booking status has been updated successfully!';
                $alertType = 'success';
            } else {
                // Rollback transaction
                $pdo->rollBack();
                
                $message = 'Booking not found or you do not have permission to update it.';
                $alertType = 'warning';
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Error updating booking status: " . $e->getMessage());
            $message = 'An error occurred: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }
    
    // Handle sending a message
    if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
        try {
            $messageText = trim($_POST['message_text'] ?? '');
            
            if (empty($messageText)) {
                throw new Exception('Message cannot be empty.');
            }
            
            // Insert the message
            $stmt = $pdo->prepare("INSERT INTO messages 
                                  (sender_id, receiver_id, booking_id, message) 
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $customerData['id'], $bookingId, $messageText]);
            
            // Refresh messages list
            $stmt = $pdo->prepare("SELECT 
                                    m.*,
                                    CASE 
                                        WHEN m.sender_id = ? THEN 'provider'
                                        ELSE 'customer'
                                    END as sender_type,
                                    s.first_name as sender_first_name,
                                    s.last_name as sender_last_name,
                                    s.profile_image as sender_profile_image
                                 FROM messages m
                                 JOIN users s ON m.sender_id = s.id
                                 WHERE m.booking_id = ? AND (m.sender_id = ? OR m.receiver_id = ?)
                                 ORDER BY m.created_at ASC");
            $stmt->execute([$userId, $bookingId, $userId, $userId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $message = 'Message sent successfully!';
            $alertType = 'success';
        } catch (Exception $e) {
            error_log("Error sending message: " . $e->getMessage());
            $message = 'An error occurred: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }
    
    // REMOVED: Handle updating booking notes functionality has been removed
}

// Helper function to format date and time
function formatDateTime($date, $time = null) {
    if ($time) {
        return date('D, M j, Y', strtotime($date)) . ' at ' . date('g:i A', strtotime($time));
    }
    return date('D, M j, Y g:i A', strtotime($date));
}

// Get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending':
            return 'warning';
        case 'confirmed':
            return 'primary';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Get status badge HTML
function getStatusBadge($status) {
    $class = getStatusBadgeClass($status);
    return '<span class="badge bg-' . $class . '">' . ucfirst($status) . '</span>';
}

// Calculate time ago for messages
function timeAgo($timestamp) {
    $datetime = new DateTime($timestamp);
    $now = new DateTime();
    $interval = $now->diff($datetime);
    
    if ($interval->y > 0) {
        return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
    } elseif ($interval->m > 0) {
        return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
    } elseif ($interval->d > 0) {
        return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
    } elseif ($interval->h > 0) {
        return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
    } elseif ($interval->i > 0) {
        return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
    } else {
        return 'just now';
    }
}



?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Details - FixItNow</title>
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
            --chat-bubble-provider: #0d6efd; /* Provider chat bubble */
            --chat-bubble-customer: #6c757d; /* Customer chat bubble */
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
            --chat-bubble-provider: #375a9e; /* Darker provider chat bubble for dark mode */
            --chat-bubble-customer: #4a4f55; /* Darker customer chat bubble for dark mode */
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
        
        /* Booking detail cards */
        .detail-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .detail-value {
            font-weight: 600;
        }
        
        /* Status timeline */
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
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
        
        .timeline-marker {
            position: absolute;
            left: -1.5rem;
            top: 0.25rem;
            width: 1rem;
            height: 1rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            z-index: 1;
        }
        
        .timeline-content {
            padding-left: 0.5rem;
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .timeline-info {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Chat styles */
        .chat-container {
            display: flex;
            flex-direction: column;
            height: 400px;
            overflow-y: auto;
            padding: 1rem;
        }
        
        .chat-message {
            display: flex;
            margin-bottom: 1rem;
            max-width: 80%;
        }
        
        .chat-message.provider {
            align-self: flex-end;
            flex-direction: row-reverse;
        }
        
        .chat-message.customer {
            align-self: flex-start;
        }
        
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin: 0 0.5rem;
            overflow: hidden;
        }
        
        .chat-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .chat-bubble {
            padding: 0.75rem 1rem;
            border-radius: 1rem;
        }
        
        .chat-message.provider .chat-bubble {
            background-color: var(--chat-bubble-provider);
            color: white;
            border-top-right-radius: 0.25rem;
        }
        
        .chat-message.customer .chat-bubble {
            background-color: var(--chat-bubble-customer);
            color: white;
            border-top-left-radius: 0.25rem;
        }
        
        .chat-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
            text-align: right;
        }
        
        .chat-form {
            display: flex;
            margin-top: 1rem;
        }
        
        .chat-input {
            flex: 1;
            border-radius: 1.5rem;
            padding: 0.75rem 1.25rem;
            background-color: var(--input-bg);
            border: 1px solid var(--input-border);
            color: var(--text-color);
        }
        
        .chat-send-btn {
            margin-left: 0.5rem;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Form controls for dark theme */
        [data-bs-theme="dark"] .form-control, 
        [data-bs-theme="dark"] .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        [data-bs-theme="dark"] .form-control:focus, 
        [data-bs-theme="dark"] .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
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
        
        /* Customer avatar */
        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .customer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-text {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        /* Status progression help text */
        .status-progression-help {
            font-size: 0.85rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 0.5rem;
            background-color: rgba(var(--bs-info-rgb), 0.1);
            border-left: 4px solid var(--bs-info);
        }
        
        /* Locked status styles */
        .status-option-container label.opacity-50 {
            cursor: not-allowed;
        }
        
        .status-option-container .fa-lock {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Price display */
        .price-display {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-color);
        }
        
        .currency-icon {
            vertical-align: middle;
            margin-right: 3px;
            margin-top: -3px;
        }

        /* Status progression visualizer */
        .status-progress-container {
            position: relative;
            margin-bottom: 1.5rem;
            padding: 0 0.5rem;
        }
        
        .status-progress-bar {
            height: 4px;
            background-color: var(--border-color);
            position: relative;
            margin: 1.5rem 0;
            z-index: 1;
        }
        
        .status-progress-fill {
            position: absolute;
            height: 100%;
            background: linear-gradient(to right, #ffc107, #0d6efd, #198754);
            transition: width 0.5s ease;
        }
        
        .status-step {
            position: absolute;
            top: -12px;
            transform: translateX(-50%);
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background-color: var(--card-bg);
            border: 2px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 0.75rem;
            color: var(--text-muted);
            z-index: 2;
            transition: all 0.3s ease;
        }
        
        .status-step.active {
            border-color: var(--primary-color);
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 0 0 4px rgba(var(--bs-primary-rgb), 0.25);
        }
        
        .status-step.completed {
            border-color: var(--accent-color);
            background-color: var(--accent-color);
            color: white;
        }
        
        .status-step.cancelled {
            border-color: var(--bs-danger);
            background-color: var(--bs-danger);
            color: white;
        }
        
        .status-step-label {
            position: absolute;
            top: 30px;
            transform: translateX(-50%);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-align: center;
            width: 80px;
        }
        
        .status-step.active .status-step-label,
        .status-step.completed .status-step-label,
        .status-step.cancelled .status-step-label {
            color: var(--text-color);
        }
        
        /* Custom radio buttons for status selection */
        .status-selection-container {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-top: 2rem;
        }
        
        .status-option {
            position: relative;
            overflow: hidden;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .status-option:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }
        
        .status-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .status-option-label {
            display: flex;
            align-items: center;
            padding: 1rem;
            cursor: pointer;
            border: 2px solid var(--border-color);
            border-radius: 0.5rem;
            transition: all 0.2s ease;
        }
        
        .status-option input[type="radio"]:checked + .status-option-label {
            border-width: 2px;
        }
        
        /* Status-specific styles */
        .status-option.pending input[type="radio"]:checked + .status-option-label {
            border-color: #ffc107;
            background-color: rgba(255, 193, 7, 0.15);
        }
        
        .status-option.confirmed input[type="radio"]:checked + .status-option-label {
            border-color: #0d6efd;
            background-color: rgba(13, 110, 253, 0.15);
        }
        
        .status-option.completed input[type="radio"]:checked + .status-option-label {
            border-color: #198754;
            background-color: rgba(25, 135, 84, 0.15);
        }
        
        .status-option.cancelled input[type="radio"]:checked + .status-option-label {
            border-color: #dc3545;
            background-color: rgba(220, 53, 69, 0.15);
        }
        
        /* Dark mode adjustments */
        [data-bs-theme="dark"] .status-option.pending input[type="radio"]:checked + .status-option-label {
            background-color: rgba(255, 193, 7, 0.25);
            border-color: #ffda6a;
        }
        
        [data-bs-theme="dark"] .status-option.confirmed input[type="radio"]:checked + .status-option-label {
            background-color: rgba(13, 110, 253, 0.25);
            border-color: #6ea8fe;
        }
        
        [data-bs-theme="dark"] .status-option.completed input[type="radio"]:checked + .status-option-label {
            background-color: rgba(25, 135, 84, 0.25);
            border-color: #75b798;
        }
        
        [data-bs-theme="dark"] .status-option.cancelled input[type="radio"]:checked + .status-option-label {
            background-color: rgba(220, 53, 69, 0.25);
            border-color: #ea868f;
        }
        
        .status-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            margin-right: 1rem;
            transition: all 0.2s ease;
        }
        
        .status-option.pending .status-icon {
            background-color: rgba(255, 193, 7, 0.25);
            color: #ffc107;
        }
        
        .status-option.confirmed .status-icon {
            background-color: rgba(13, 110, 253, 0.25);
            color: #0d6efd;
        }
        
        .status-option.completed .status-icon {
            background-color: rgba(25, 135, 84, 0.25);
            color: #198754;
        }
        
        .status-option.cancelled .status-icon {
            background-color: rgba(220, 53, 69, 0.25);
            color: #dc3545;
        }
        
        .status-option-content {
            flex: 1;
        }
        
        .status-option-actions {
            display: flex;
            align-items: center;
        }
        
        .status-lock-icon {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-left: 0.5rem;
        }
        
        /* Submit button pulse animation */
        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(var(--bs-primary-rgb), 0.7);
            }
            70% {
                box-shadow: 0 0 0 10px rgba(var(--bs-primary-rgb), 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(var(--bs-primary-rgb), 0);
            }
        }
        
        .btn-status-update {
            animation: pulse 2s infinite;
            position: relative;
            overflow: hidden;
        }
        
        .btn-status-update::before {
            content: "";
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                transparent,
                rgba(255, 255, 255, 0.2),
                transparent
            );
            animation: shine 2s infinite;
        }
        /* Notes section styling */
        .notes-container {
            position: relative;
            border-radius: 0.75rem;
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        
        .notes-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1rem;
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            border-bottom: 1px solid var(--border-color);
        }
        
        .notes-title {
            display: flex;
            align-items: center;
            font-weight: 600;
            margin: 0;
        }
        
        .notes-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .notes-content {
            padding: 1rem;
            min-height: 100px;
            max-height: 250px;
            overflow-y: auto;
            background-color: var(--card-bg);
            line-height: 1.6;
        }
        
        .notes-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            min-height: 120px;
            color: var(--text-muted);
        }
        
        .notes-placeholder i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.5;
        }
        
        .notes-badge {
            display: inline-flex;
            align-items: center;
            background-color: rgba(var(--bs-info-rgb), 0.15);
            color: var(--bs-info);
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            margin-left: 0.75rem;
        }
        
        .notes-copy-button {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 0.25rem;
            border-radius: 0.25rem;
            transition: all 0.2s ease;
        }
        
        .notes-copy-button:hover {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
        }
        
        .notes-copy-tooltip {
            position: absolute;
            top: 0.75rem;
            right: 2.5rem;
            background-color: var(--bg-color);
            color: var(--text-color);
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            transform: translateY(-5px);
            pointer-events: none;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        
        .notes-copy-tooltip.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* Enhanced scrollbar for notes */
        .notes-content::-webkit-scrollbar {
            width: 8px;
        }
        
        .notes-content::-webkit-scrollbar-track {
            background: rgba(var(--bs-dark-rgb), 0.05);
            border-radius: 10px;
        }
        
        .notes-content::-webkit-scrollbar-thumb {
            background: rgba(var(--bs-primary-rgb), 0.2);
            border-radius: 10px;
        }
        
        .notes-content::-webkit-scrollbar-thumb:hover {
            background: rgba(var(--bs-primary-rgb), 0.4);
        }
        
        /* Text highlight */
        .notes-content p {
            position: relative;
            padding-left: 0.5rem;
        }
        
        .notes-content p:before {
            content: '';
            position: absolute;
            left: 0;
            top: 0.25rem;
            bottom: 0.25rem;
            width: 3px;
            background-color: var(--primary-color);
            opacity: 0.5;
            border-radius: 3px;
        }
        @keyframes shine {
            100% {
                left: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading booking details...</p>
        </div>
    </div>

    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="dashboard.php" class="text-decoration-none d-flex align-items-center">
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
                        <a class="nav-link active" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-file-invoice-dollar"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">
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
            <!-- Page Title and Actions -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="page-title">Booking Details</h2>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
                            <li class="breadcrumb-item"><a href="bookings.php">Bookings</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Booking #<?php echo $bookingId; ?></li>
                        </ol>
                    </nav>
                </div>
                <div>
                    <a href="bookings.php" class="btn btn-outline-secondary me-2">
                        <i class="fas fa-arrow-left me-1"></i> Back to Bookings
                    </a>
                </div>
            </div>
            
            <!-- Display alert message if set -->
            <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $alertType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Booking Overview -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Booking Information</h5>
                            <span class="status-badge badge bg-<?php echo getStatusBadgeClass($bookingData['status']); ?>">
                                <?php echo ucfirst($bookingData['status']); ?>
                            </span>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="detail-label">Booking Date & Time</div>
                                        <div class="detail-value">
                                            <i class="far fa-calendar-alt me-1"></i>
                                            <?php echo formatDateTime($bookingData['booking_date'], $bookingData['booking_time']); ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="detail-label">Service</div>
                                        <div class="detail-value">
                                            <i class="fas fa-cogs me-1"></i>
                                            <?php echo isset($bookingData['service_name']) ? htmlspecialchars($bookingData['service_name']) : 'General Service'; ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="detail-label">Duration</div>
                                        <div class="detail-value">
                                            <i class="far fa-clock me-1"></i>
                                            <?php echo isset($bookingData['duration']) ? $bookingData['duration'] . ' minutes' : '60 minutes (default)'; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <div class="detail-label">Price</div>
                                        <div class="price-display">
                                            <img src="../admin/sar/sar.png" alt="" class="currency-icon" width="18" height="18">
                                            <?php echo number_format((float)$bookingData['total_price'], 2); ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="detail-label">Payment Status</div>
                                        <div class="detail-value">
                                            <?php if ($bookingData['payment_status'] === 'paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php elseif ($bookingData['payment_status'] === 'refunded'): ?>
                                                <span class="badge bg-info">Refunded</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="detail-label">Created On</div>
                                        <div class="detail-value">
                                            <i class="far fa-calendar-plus me-1"></i>
                                            <?php echo formatDateTime($bookingData['created_at']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <!-- Notes Section - CORRECTED: Removed edit functionality -->
                            <div class="notes-container">
                                <div class="notes-header">
                                    <h6 class="notes-title">
                                        <i class="fas fa-sticky-note me-2"></i>
                                        Customer Instructions
                                    </h6>
                                    <div class="notes-actions">
                                        <button type="button" class="notes-copy-button" id="copyNotesBtn" title="Copy notes to clipboard">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <div class="notes-copy-tooltip" id="copyTooltip">Copied!</div>
                                    </div>
                                </div>
                                
                                <div class="notes-content" id="notesContent">
                                    <?php if (!empty($bookingData['notes'])): ?>
                                        <?php 
                                        // Split by line breaks and wrap each in a paragraph
                                        $noteLines = explode("\n", htmlspecialchars($bookingData['notes']));
                                        foreach ($noteLines as $line):
                                            if (trim($line) !== ''): 
                                        ?>
                                            <p><?php echo $line; ?></p>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    <?php else: ?>
                                        <div class="notes-placeholder">
                                            <i class="fas fa-comment-slash"></i>
                                            <p class="mb-0">No notes available for this booking</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-user me-2"></i>Customer Information</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-4">
                                <div class="customer-avatar me-3">
                                    <?php if (!empty($customerData['profile_image'])): ?>
                                        <?php 
                                        // Properly handle the image path with security checks
                                        if (preg_match('/^https?:\/\//i', $customerData['profile_image'])) {
                                            // External URL - validate
                                            $customerImagePath = filter_var($customerData['profile_image'], FILTER_SANITIZE_URL);
                                        } else {
                                            // Local file - validate path and existence
                                            $customerImagePath = '../profile_images/' . basename($customerData['profile_image']);
                                            if (!file_exists($customerImagePath) || !is_readable($customerImagePath)) {
                                                $customerImagePath = '../default.png'; // Use default if file doesn't exist
                                            }
                                        }
                                        ?>
                                        <img src="<?php echo htmlspecialchars($customerImagePath); ?>" alt="Customer">
                                    <?php else: ?>
                                        <div class="avatar-text">
                                            <?php echo strtoupper(substr($customerData['first_name'], 0, 1) . substr($customerData['last_name'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($customerData['first_name'] . ' ' . $customerData['last_name']); ?></h5>
                                    <p class="mb-0 text-muted">Customer</p>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="detail-label">Email</div>
                                <div class="detail-value">
                                    <i class="fas fa-envelope me-1"></i>
                                    <a href="mailto:<?php echo htmlspecialchars($customerData['email']); ?>"><?php echo htmlspecialchars($customerData['email']); ?></a>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value">
                                    <i class="fas fa-phone-alt me-1"></i>
                                    <a href="tel:<?php echo htmlspecialchars($customerData['phone']); ?>"><?php echo htmlspecialchars($customerData['phone']); ?></a>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <!-- Quick Actions -->
                            <h6 class="mb-3">Actions</h6>
                            
                            <!-- CORRECTED: Updated the help text to explain the forward progression -->
                            <div class="status-progression-help mb-3">
                                <i class="fas fa-info-circle me-1"></i> 
                                <strong>Note:</strong> You can only update the booking status to move forward in the workflow (e.g., pending → confirmed → completed) or cancel it.
                            </div>
                            
                            <form method="post" class="mb-3">
                                <input type="hidden" name="action" value="update_status">
                                <div>
                                    <label class="form-label fw-bold mb-3">Update Status</label>
                                    
                                    <!-- Status Progress Visualization -->
                                    <div class="status-progress-container">
                                        <div class="status-progress-bar">
                                            <?php
                                            // Calculate progress width based on current status
                                            $progressWidth = 0;
                                            if ($bookingData['status'] === 'pending') $progressWidth = 0;
                                            else if ($bookingData['status'] === 'confirmed') $progressWidth = 50;
                                            else if ($bookingData['status'] === 'completed') $progressWidth = 100;
                                            else if ($bookingData['status'] === 'cancelled') $progressWidth = 33; // Special case
                                            ?>
                                            <div class="status-progress-fill" style="width: <?php echo $progressWidth; ?>%"></div>
                                        </div>
                                        
                                        <!-- Pending Step -->
                                        <div class="status-step <?php echo $bookingData['status'] === 'pending' ? 'active' : ($bookingData['status'] === 'confirmed' || $bookingData['status'] === 'completed' ? 'completed' : ''); ?>" style="left: 0%;">
                                            <i class="fas <?php echo $bookingData['status'] === 'pending' ? 'fa-clock' : 'fa-check'; ?>"></i>
                                            <div class="status-step-label">Pending</div>
                                        </div>
                                        
                                        <!-- Confirmed Step -->
                                        <div class="status-step <?php echo $bookingData['status'] === 'confirmed' ? 'active' : ($bookingData['status'] === 'completed' ? 'completed' : ''); ?>" style="left: 50%;">
                                            <i class="fas <?php echo $bookingData['status'] === 'confirmed' ? 'fa-check-circle' : ($bookingData['status'] === 'completed' ? 'fa-check' : 'fa-clock'); ?>"></i>
                                            <div class="status-step-label">Confirmed</div>
                                        </div>
                                        
                                        <!-- Completed Step -->
                                        <div class="status-step <?php echo $bookingData['status'] === 'completed' ? 'active' : ''; ?>" style="left: 100%;">
                                            <i class="fas <?php echo $bookingData['status'] === 'completed' ? 'fa-check-double' : 'fa-flag'; ?>"></i>
                                            <div class="status-step-label">Completed</div>
                                        </div>
                                        
                                        <!-- Cancelled Status (shown separately if active) -->
                                        <?php if ($bookingData['status'] === 'cancelled'): ?>
                                        <div class="status-step cancelled" style="left: 33%;">
                                            <i class="fas fa-times"></i>
                                            <div class="status-step-label">Cancelled</div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- CORRECTED: Disable status options that cannot be selected based on the current status -->
                                    <div class="status-selection-container">
                                        <!-- Pending Status - Can only be selected if current status is pending -->
                                        <div class="status-option pending">
                                            <input type="radio" name="new_status" id="status-pending" value="pending" 
                                                <?php echo $bookingData['status'] === 'pending' ? 'checked' : ''; ?>
                                                <?php echo ($bookingData['status'] !== 'pending') ? 'disabled' : ''; ?>>
                                            <label class="status-option-label <?php echo ($bookingData['status'] !== 'pending') ? 'opacity-50' : ''; ?>" for="status-pending">
                                                <div class="status-icon">
                                                    <i class="fas fa-clock"></i>
                                                </div>
                                                <div class="status-option-content">
                                                    <div class="fw-bold">Pending</div>
                                                    <small class="text-muted">Booking is awaiting confirmation</small>
                                                </div>
                                                <?php if ($bookingData['status'] !== 'pending'): ?>
                                                <div class="status-option-actions">
                                                    <i class="fas fa-lock status-lock-icon" title="Cannot change to this status"></i>
                                                </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        
                                        <!-- Confirmed Status - Can be selected if current status is pending -->
                                        <div class="status-option confirmed">
                                            <input type="radio" name="new_status" id="status-confirmed" value="confirmed" 
                                                <?php echo $bookingData['status'] === 'confirmed' ? 'checked' : ''; ?>
                                                <?php echo ($bookingData['status'] !== 'pending' && $bookingData['status'] !== 'confirmed') ? 'disabled' : ''; ?>>
                                            <label class="status-option-label <?php echo ($bookingData['status'] !== 'pending' && $bookingData['status'] !== 'confirmed') ? 'opacity-50' : ''; ?>" for="status-confirmed">
                                                <div class="status-icon">
                                                    <i class="fas fa-check-circle"></i>
                                                </div>
                                                <div class="status-option-content">
                                                    <div class="fw-bold">Confirmed</div>
                                                    <small class="text-muted">Service appointment confirmed</small>
                                                </div>
                                                <?php if ($bookingData['status'] !== 'pending' && $bookingData['status'] !== 'confirmed'): ?>
                                                <div class="status-option-actions">
                                                    <i class="fas fa-lock status-lock-icon" title="Cannot change to this status"></i>
                                                </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        
                                        <!-- Completed Status - Can be selected if current status is confirmed or completed -->
                                        <div class="status-option completed">
                                            <input type="radio" name="new_status" id="status-completed" value="completed" 
                                                <?php echo $bookingData['status'] === 'completed' ? 'checked' : ''; ?>
                                                <?php echo ($bookingData['status'] !== 'confirmed' && $bookingData['status'] !== 'completed') ? 'disabled' : ''; ?>>
                                            <label class="status-option-label <?php echo ($bookingData['status'] !== 'confirmed' && $bookingData['status'] !== 'completed') ? 'opacity-50' : ''; ?>" for="status-completed">
                                                <div class="status-icon">
                                                    <i class="fas fa-check-double"></i>
                                                </div>
                                                <div class="status-option-content">
                                                    <div class="fw-bold">Completed</div>
                                                    <small class="text-muted">Service has been successfully delivered</small>
                                                </div>
                                                <?php if ($bookingData['status'] !== 'confirmed' && $bookingData['status'] !== 'completed'): ?>
                                                <div class="status-option-actions">
                                                    <i class="fas fa-lock status-lock-icon" title="Cannot change to this status"></i>
                                                </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        
                                        <!-- Cancelled Status - Can be selected from any status except cancelled -->
                                        <div class="status-option cancelled">
                                            <input type="radio" name="new_status" id="status-cancelled" value="cancelled" 
                                                <?php echo $bookingData['status'] === 'cancelled' ? 'checked' : ''; ?>
                                                <?php echo $bookingData['status'] === 'cancelled' ? 'disabled' : ''; ?>>
                                            <label class="status-option-label <?php echo $bookingData['status'] === 'cancelled' ? 'opacity-50' : ''; ?>" for="status-cancelled">
                                                <div class="status-icon">
                                                    <i class="fas fa-times-circle"></i>
                                                </div>
                                                <div class="status-option-content">
                                                    <div class="fw-bold">Cancelled</div>
                                                    <small class="text-muted">Booking has been cancelled</small>
                                                </div>
                                                <?php if ($bookingData['status'] === 'cancelled'): ?>
                                                <div class="status-option-actions">
                                                    <i class="fas fa-lock status-lock-icon" title="Cannot change to this status"></i>
                                                </div>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="status_note" class="form-label">Status Note (optional)</label>
                                    <textarea class="form-control" id="status_note" name="status_note" rows="2" placeholder="Add note about status change..."></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100 d-flex justify-content-center align-items-center btn-status-update">
                                    <i class="fas fa-sync-alt me-2"></i> Update Status
                                </button>
                                
                                <div class="form-text text-center mt-2">
                                    <i class="fas fa-info-circle me-1"></i> 
                                    Updates are immediately visible to the customer
                                </div>
                            </form>
                            <div class="d-grid gap-2">
                                <a href="tel:<?php echo htmlspecialchars($customerData['phone']); ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-phone-alt me-1"></i> Call Customer
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Booking History and Communication -->
            <div class="row">
                <!-- Status History -->
                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Booking Status History</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($statusHistory)): ?>
                                <div class="text-center py-4">
                                    <div class="text-muted mb-3">
                                        <i class="fas fa-history fa-3x"></i>
                                    </div>
                                    <p>No status history available for this booking.</p>
                                </div>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($statusHistory as $history): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-marker"></div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">
                                                    Status changed to <span class="badge bg-<?php echo getStatusBadgeClass($history['status']); ?>"><?php echo ucfirst($history['status']); ?></span>
                                                </div>
                                                <div class="timeline-info">
                                                    <i class="far fa-clock me-1"></i>
                                                    <?php echo formatDateTime($history['created_at']); ?>
                                                </div>
                                                <div class="timeline-info">
                                                    <i class="far fa-user me-1"></i>
                                                    By: <?php echo htmlspecialchars($history['created_by_name'] ?? $history['created_by']); ?>
                                                </div>
                                                <?php if (!empty($history['notes'])): ?>
                                                    <div class="timeline-note mt-2">
                                                        <i class="far fa-sticky-note me-1"></i>
                                                        Note: <?php echo htmlspecialchars($history['notes']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Customer Communication -->
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-comments me-2"></i>Customer Communication</h5>
                        </div>
                        <div class="card-body">
                            <div class="chat-container" id="chatContainer">
                                <?php if (empty($messages)): ?>
                                    <div class="text-center py-4">
                                        <div class="text-muted mb-3">
                                            <i class="fas fa-comments fa-3x"></i>
                                        </div>
                                        <p>No messages yet. Start the conversation with your customer.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($messages as $msg): ?>
                                        <div class="chat-message <?php echo $msg['sender_type']; ?>">
                                            <div class="chat-avatar">
                                                <?php if (!empty($msg['sender_profile_image'])): ?>
                                                    <?php 
                                                    // Check if the image is a full URL
                                                    if (preg_match('/^https?:\/\//i', $msg['sender_profile_image'])) {
                                                        $imagePath = htmlspecialchars($msg['sender_profile_image']);
                                                    } else {
                                                        // For local files, check if file exists
                                                        $imagePath = '../profile_images/' . basename($msg['sender_profile_image']);
                                                        if (!file_exists($imagePath) || !is_readable($imagePath)) {
                                                            $imagePath = '../default.png'; // Use default if file doesn't exist
                                                        }
                                                    }
                                                    ?>
                                                    <img src="<?php echo $imagePath; ?>" alt="Sender">
                                                <?php else: ?>
                                                    <img src="../default.png" alt="Default Avatar" class="img-fluid">
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="chat-bubble">
                                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                                </div>
                                                <div class="chat-time"><?php echo timeAgo($msg['created_at']); ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <form method="post" class="chat-form" id="messageForm">
                                <input type="hidden" name="action" value="send_message">
                                <textarea class="form-control chat-input" id="message_text" name="message_text" placeholder="Type your message..." rows="1" required></textarea>
                                <button type="submit" class="btn btn-primary chat-send-btn">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
            
            // Scroll chat to bottom
            const chatContainer = document.getElementById('chatContainer');
            if (chatContainer) {
                chatContainer.scrollTop = chatContainer.scrollHeight;
            }
            
            // Auto resize textarea as you type
            const messageInput = document.getElementById('message_text');
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                    
                    // Reset height if empty
                    if (this.value === '') {
                        this.style.height = 'auto';
                    }
                });
            }
            
            // Status update form submission
            const statusForm = document.querySelector('form[action="update_status"]');
            if (statusForm) {
                const cancelledOption = document.getElementById('status-cancelled');
                
                statusForm.addEventListener('submit', function(e) {
                    // Confirmation for cancellation
                    if (cancelledOption && cancelledOption.checked) {
                        if (!confirm('Are you sure you want to cancel this booking? This action cannot be undone.')) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    // Show loading overlay on valid submission
                    document.getElementById('loadingOverlay').style.display = 'flex';
                });
                
                // Status option selection indicator
                const statusOptions = document.querySelectorAll('.status-option input[type="radio"]');
                statusOptions.forEach(option => {
                    option.addEventListener('change', function() {
                        // Remove any active classes
                        document.querySelectorAll('.status-option').forEach(opt => {
                            opt.classList.remove('active');
                        });
                        
                        // Add active class to selected option's parent
                        if (this.checked) {
                            this.closest('.status-option').classList.add('active');
                        }
                    });
                    
                    // Set initial active class
                    if (option.checked) {
                        option.closest('.status-option').classList.add('active');
                    }
                });
            }
            
            // Message form submission
            const messageForm = document.getElementById('messageForm');
            if (messageForm) {
                messageForm.addEventListener('submit', function() {
                    // Show loading overlay
                    document.getElementById('loadingOverlay').style.display = 'flex';
                });
            }

            // Copy notes to clipboard
            const copyNotesBtn = document.getElementById('copyNotesBtn');
            const copyTooltip = document.getElementById('copyTooltip');
            const notesContent = document.getElementById('notesContent');
            
            if (copyNotesBtn && copyTooltip && notesContent) {
                copyNotesBtn.addEventListener('click', function() {
                    // Get text content
                    const notesText = notesContent.innerText;
                    
                    if (notesText.trim() === 'No notes available for this booking') {
                        // No notes to copy
                        copyTooltip.textContent = 'No notes to copy';
                        copyTooltip.classList.add('show');
                        
                        setTimeout(() => {
                            copyTooltip.classList.remove('show');
                        }, 2000);
                        return;
                    }
                    
                    // Copy to clipboard
                    navigator.clipboard.writeText(notesText).then(function() {
                        copyTooltip.textContent = 'Copied!';
                        copyTooltip.classList.add('show');
                        
                        setTimeout(() => {
                            copyTooltip.classList.remove('show');
                        }, 2000);
                    }, function(err) {
                        copyTooltip.textContent = 'Error copying';
                        copyTooltip.classList.add('show');
                        
                        setTimeout(() => {
                            copyTooltip.classList.remove('show');
                        }, 2000);
                        console.error('Could not copy text: ', err);
                    });
                });
            }

            // REMOVED: Notes editing functionality has been removed
        });
    </script>
</body>
</html>