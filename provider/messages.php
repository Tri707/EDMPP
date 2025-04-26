<?php
/**
 * Provider Messages - FixItNow Platform
 * 
 * This file allows providers to view and respond to customer messages.
 */

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
require_once '../conn.php';

// Verify that database connection is established
if (!isset($pdo) || $pdo === null) {
    error_log("Database connection not established. Check conn.php file.");
    header('Location: ../error.php?message=Database%20connection%20failed');
    exit;
}

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Initialize variables with default values
$providerId = 0;
$providerProfileImage = '../default.png'; // Set default image path
$providerData = [];
$conversations = [];
$messages = [];
$currentCustomer = null;
$isNewConversation = isset($_GET['new']) && $_GET['new'] == 1;
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
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

// Get recipient information if specified
$recipientId = isset($_GET['recipient_id']) ? (int)$_GET['recipient_id'] : 0;

// Fetch all unique conversations (customers the provider has messaged with)
try {
    $query = "SELECT DISTINCT
                CASE 
                    WHEN m.sender_id = ? THEN m.receiver_id 
                    ELSE m.sender_id 
                END AS customer_id,
                u.first_name,
                u.last_name,
                u.profile_image,
                u.email,
                (
                    SELECT created_at 
                    FROM messages 
                    WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as last_message_time,
                (
                    SELECT message 
                    FROM messages 
                    WHERE (sender_id = ? AND receiver_id = u.id) OR (sender_id = u.id AND receiver_id = ?) 
                    ORDER BY created_at DESC 
                    LIMIT 1
                ) as last_message,
                (
                    SELECT COUNT(*) 
                    FROM messages 
                    WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0
                ) as unread_count
              FROM messages m
              JOIN users u ON (u.id = m.sender_id OR u.id = m.receiver_id) AND u.id != ?
              WHERE m.sender_id = ? OR m.receiver_id = ?";
    
    // Add search filter if provided
    if (!empty($searchQuery)) {
        $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    }
    
    $query .= " ORDER BY last_message_time DESC";
    
    $stmt = $pdo->prepare($query);
    
    if (!empty($searchQuery)) {
        $searchParam = "%$searchQuery%";
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $searchParam, $searchParam, $searchParam]);
    } else {
        $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    }
    
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Database error fetching conversations: " . $e->getMessage());
    // Continue without conversations data
}

// If a recipient is selected, fetch their details
if ($recipientId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, profile_image 
                             FROM users 
                             WHERE id = ? AND role = 'customer'");
        $stmt->execute([$recipientId]);
        $currentCustomer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If customer exists, fetch messages between provider and customer
        if ($currentCustomer) {
            $stmt = $pdo->prepare("SELECT m.*, 
                                  CASE WHEN m.sender_id = ? THEN 'sent' ELSE 'received' END as message_type,
                                  u.first_name, u.last_name, u.profile_image
                               FROM messages m
                               JOIN users u ON m.sender_id = u.id
                               WHERE (m.sender_id = ? AND m.receiver_id = ?) 
                                  OR (m.sender_id = ? AND m.receiver_id = ?)
                               ORDER BY m.created_at ASC");
            $stmt->execute([$userId, $userId, $recipientId, $recipientId, $userId]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as read
            $updateStmt = $pdo->prepare("UPDATE messages SET is_read = 1 
                                       WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
            $updateStmt->execute([$recipientId, $userId]);
            
            // If this customer wasn't already in conversations (new conversation), add them
            $foundInConversations = false;
            foreach ($conversations as $conv) {
                if ($conv['customer_id'] == $recipientId) {
                    $foundInConversations = true;
                    break;
                }
            }
            
            if (!$foundInConversations && $isNewConversation) {
                $newConversation = [
                    'customer_id' => $recipientId,
                    'first_name' => $currentCustomer['first_name'],
                    'last_name' => $currentCustomer['last_name'],
                    'profile_image' => $currentCustomer['profile_image'],
                    'email' => $currentCustomer['email'],
                    'last_message_time' => null,
                    'last_message' => null,
                    'unread_count' => 0
                ];
                
                // Add to the beginning of the array
                array_unshift($conversations, $newConversation);
            }
        } else {
            $recipientId = 0; // Reset if customer not found or not a customer
        }
    } catch (PDOException $e) {
        error_log("Database error fetching customer or messages: " . $e->getMessage());
        // Continue without messages data
    }
}

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $message = 'Security validation failed. Please try again.';
        $alertType = 'danger';
    } else {
        $receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
        $messageText = isset($_POST['message']) ? trim($_POST['message']) : '';
        $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
        $quoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;
        
        // Validate inputs
        if ($receiverId <= 0) {
            $message = 'Invalid recipient.';
            $alertType = 'danger';
        } elseif (empty($messageText)) {
            $message = 'Message cannot be empty.';
            $alertType = 'danger';
        } else {
            try {
                // Check if receiver exists and is a customer
                $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'customer'");
                $stmt->execute([$receiverId]);
                if (!$stmt->fetch()) {
                    throw new Exception('Invalid recipient.');
                }
                
                // Insert message
                $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, booking_id, quote_id, message) 
                                     VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$userId, $receiverId, $bookingId ?: null, $quoteId ?: null, $messageText]);
                
                // Redirect to avoid form resubmission
                header("Location: messages.php?recipient_id=$receiverId");
                exit;
                
            } catch (Exception $e) {
                $message = 'Error sending message: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}

// Helper functions
function formatDateTime($date) {
    $timestamp = strtotime($date);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 172800) { // Less than 2 days
        return 'Yesterday at ' . date('g:i A', $timestamp);
    } elseif ($diff < 604800) { // Less than a week
        return date('l', $timestamp) . ' at ' . date('g:i A', $timestamp);
    } else {
        return date('M j, Y', $timestamp) . ' at ' . date('g:i A', $timestamp);
    }
}

function getProfileImage($userData) {
    $defaultImage = '../default.png';
    
    if (empty($userData['profile_image'])) {
        return $defaultImage;
    }
    
    if (preg_match('/^https?:\/\//i', $userData['profile_image'])) {
        // External URL
        return $userData['profile_image'];
    } else {
        // Local file
        $imagePath = '../profile_images/' . basename($userData['profile_image']);
        if (file_exists($imagePath) && is_readable($imagePath)) {
            return $imagePath;
        }
        return $defaultImage;
    }
}

function getInitials($userData) {
    $initials = '';
    if (!empty($userData['first_name'])) {
        $initials .= strtoupper(substr($userData['first_name'], 0, 1));
    }
    if (!empty($userData['last_name'])) {
        $initials .= strtoupper(substr($userData['last_name'], 0, 1));
    }
    return $initials ?: '?';
}

// Determine if to show the empty state
$showEmptyState = empty($conversations) && empty($currentCustomer);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages - FixItNow</title>
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
        
        /* Messages UI */
        .messages-container {
            height: calc(100vh - 280px);
            min-height: 400px;
            display: flex;
            border-radius: 0.75rem;
            overflow: hidden;
            background-color: var(--card-bg);
            box-shadow: 0 0.25rem 0.75rem var(--shadow-color);
        }
        
        .conversation-list {
            width: 320px;
            border-right: 1px solid var(--border-color);
            overflow-y: auto;
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .conversation-header {
            padding: 1rem;
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
        }
        
        .conversation-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            transition: all 0.2s ease;
            cursor: pointer;
            display: flex;
            align-items: center;
        }
        
        .conversation-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .conversation-item.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .conversation-item.active .text-muted {
            color: rgba(255, 255, 255, 0.7) !important;
        }
        
        .conversation-item.unread {
            background-color: rgba(var(--primary-color-rgb), 0.1);
        }
        
        .avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-text {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            font-weight: 600;
            margin-right: 0.75rem;
            flex-shrink: 0;
        }
        
        .conversation-info {
            flex: 1;
            min-width: 0; /* Needed for text-overflow to work */
        }
        
        .conversation-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-preview {
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-left: 0.5rem;
        }
        
        .conversation-time {
            font-size: 0.75rem;
            margin-bottom: 0.25rem;
        }
        
        .conversation-badge {
            font-size: 0.7rem;
            font-weight: 600;
            background-color: var(--accent-color);
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 0.25rem;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header {
            padding: 1rem;
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .chat-messages {
            flex: 1;
            padding: 1rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        .message-group {
            margin-bottom: 1rem;
            max-width: 80%;
        }
        
        .message-group.sent {
            align-self: flex-end;
        }
        
        .message-group.received {
            align-self: flex-start;
        }
        
        .message-bubble {
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            position: relative;
            margin-bottom: 0.25rem;
        }
        
        .message-group.sent .message-bubble {
            background-color: var(--primary-color);
            color: white;
            border-bottom-right-radius: 0.25rem;
        }
        
        .message-group.received .message-bubble {
            background-color: rgba(0, 0, 0, 0.05);
            border-bottom-left-radius: 0.25rem;
        }
        
        .message-time {
            font-size: 0.7rem;
            text-align: right;
            margin-top: 0.25rem;
            opacity: 0.7;
        }
        
        .message-sender {
            font-size: 0.8rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .chat-input {
            padding: 1rem;
            background-color: rgba(0, 0, 0, 0.05);
            border-top: 1px solid var(--border-color);
        }
        
        .chat-input-form {
            display: flex;
        }
        
        .chat-input-form .form-control {
            border-top-right-radius: 0;
            border-bottom-right-radius: 0;
        }
        
        .chat-input-form .btn {
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        
        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 2rem;
            text-align: center;
            color: var(--text-muted);
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            opacity: 0.6;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 992px) {
            .messages-container {
                flex-direction: column;
                height: auto;
            }
            
            .conversation-list {
                width: 100%;
                height: 300px;
                border-right: none;
                border-bottom: 1px solid var(--border-color);
            }
            
            .chat-area {
                height: 500px;
            }
        }
        
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .messages-container {
                margin-left: -1rem;
                margin-right: -1rem;
                border-radius: 0;
            }
        }
        
        /* Loading spinner */
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
        
        /* Scroll to bottom button */
        .scroll-btn {
            position: absolute;
            bottom: 70px;
            right: 20px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 0.25rem 0.5rem rgba(0,0,0,0.2);
            z-index: 100;
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        
        .scroll-btn.visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during AJAX calls) -->
    <div class="loading-overlay" id="loadingOverlay" style="display: none;">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Processing...</p>
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
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="nav-text">Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
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
                    <h2 class="page-title">Messages</h2>
                    <p class="text-muted">Communicate with your customers</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Dashboard
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
            
            <!-- Search bar and filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form action="messages.php" method="get" class="row g-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="Search by customer name, email..." 
                                       value="<?php echo htmlspecialchars($searchQuery); ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                        <?php if ($recipientId > 0): ?>
                            <input type="hidden" name="recipient_id" value="<?php echo $recipientId; ?>">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- Messages Interface -->
            <div class="messages-container">
                <!-- Conversation List -->
                <div class="conversation-list">
                    <div class="conversation-header">
                        <h6 class="mb-2">Conversations</h6>
                        <div class="small text-muted">
                            <?php echo count($conversations); ?> conversations
                        </div>
                    </div>
                    
                    <?php if (empty($conversations)): ?>
                        <div class="p-3 text-center text-muted">
                            <i class="fas fa-inbox fa-2x mb-3"></i>
                            <p>No conversations yet</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conversation): ?>
                            <?php 
                            $isActive = $recipientId == $conversation['customer_id'];
                            $hasUnread = $conversation['unread_count'] > 0;
                            ?>
                            <div class="conversation-item <?php echo $isActive ? 'active' : ''; ?> <?php echo $hasUnread && !$isActive ? 'unread' : ''; ?>"
                                 onclick="window.location.href='messages.php?recipient_id=<?php echo $conversation['customer_id']; ?>'">
                                
                                <?php if (!empty($conversation['profile_image'])): ?>
                                    <div class="avatar">
                                        <img src="<?php echo htmlspecialchars(getProfileImage($conversation)); ?>" alt="Customer">
                                    </div>
                                <?php else: ?>
                                    <div class="avatar-text">
                                        <?php echo getInitials($conversation); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="conversation-info">
                                    <div class="conversation-name">
                                        <?php echo htmlspecialchars($conversation['first_name'] . ' ' . $conversation['last_name']); ?>
                                    </div>
                                    <div class="conversation-preview text-muted">
                                        <?php 
                                        if (!empty($conversation['last_message'])) {
                                            echo htmlspecialchars(mb_substr($conversation['last_message'], 0, 30)) . 
                                                (mb_strlen($conversation['last_message']) > 30 ? '...' : '');
                                        } else {
                                            echo 'No messages yet';
                                        }
                                        ?>
                                    </div>
                                </div>
                                
                                <div class="conversation-meta">
                                    <div class="conversation-time text-muted">
                                        <?php 
                                        if (!empty($conversation['last_message_time'])) {
                                            echo formatDateTime($conversation['last_message_time']);
                                        }
                                        ?>
                                    </div>
                                    
                                    <?php if ($hasUnread): ?>
                                        <div class="conversation-badge">
                                            <?php echo $conversation['unread_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- Chat Area -->
                <div class="chat-area">
                    <?php if (!$currentCustomer): ?>
                        <!-- Empty state when no chat is selected -->
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class="fas fa-comments"></i>
                            </div>
                            <h4>Select a conversation</h4>
                            <p>Choose a customer from the list to view your conversation history</p>
                        </div>
                    <?php else: ?>
                        <!-- Chat Header -->
                        <div class="chat-header">
                            <?php if (!empty($currentCustomer['profile_image'])): ?>
                                <div class="avatar">
                                    <img src="<?php echo htmlspecialchars(getProfileImage($currentCustomer)); ?>" alt="Customer">
                                </div>
                            <?php else: ?>
                                <div class="avatar-text">
                                    <?php echo getInitials($currentCustomer); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div>
                                <h5 class="mb-0">
                                    <?php echo htmlspecialchars($currentCustomer['first_name'] . ' ' . $currentCustomer['last_name']); ?>
                                </h5>
                                <div class="small text-muted">
                                    <?php echo htmlspecialchars($currentCustomer['email']); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Chat Messages -->
                        <div class="chat-messages" id="chatMessages">
                            <?php if (empty($messages)): ?>
                                <div class="text-center text-muted my-5">
                                    <i class="fas fa-comments fa-3x mb-3"></i>
                                    <p>No messages yet</p>
                                    <p>Start the conversation by sending a message below</p>
                                </div>
                            <?php else: ?>
                                <?php 
                                $currentSenderId = null;
                                $messageGroup = [];
                                
                                // Group consecutive messages from the same sender
                                foreach ($messages as $index => $msg) {
                                    if ($currentSenderId !== $msg['sender_id']) {
                                        // If we have messages in the group, display them
                                        if (!empty($messageGroup)) {
                                            $groupType = $messageGroup[0]['message_type'];
                                            ?>
                                            <div class="message-group <?php echo $groupType; ?>">
                                                <?php if ($groupType === 'received'): ?>
                                                    <div class="message-sender">
                                                        <?php echo htmlspecialchars($messageGroup[0]['first_name'] . ' ' . $messageGroup[0]['last_name']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php foreach ($messageGroup as $groupMsg): ?>
                                                    <div class="message-bubble">
                                                        <?php echo nl2br(htmlspecialchars($groupMsg['message'])); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                
                                                <div class="message-time">
                                                    <?php echo formatDateTime($messageGroup[count($messageGroup) - 1]['created_at']); ?>
                                                </div>
                                            </div>
                                            <?php
                                        }
                                        
                                        // Start a new group
                                        $currentSenderId = $msg['sender_id'];
                                        $messageGroup = [$msg];
                                    } else {
                                        // Add to current group
                                        $messageGroup[] = $msg;
                                    }
                                    
                                    // If this is the last message, display the group
                                    if ($index === count($messages) - 1 && !empty($messageGroup)) {
                                        $groupType = $messageGroup[0]['message_type'];
                                        ?>
                                        <div class="message-group <?php echo $groupType; ?>">
                                            <?php if ($groupType === 'received'): ?>
                                                <div class="message-sender">
                                                    <?php echo htmlspecialchars($messageGroup[0]['first_name'] . ' ' . $messageGroup[0]['last_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php foreach ($messageGroup as $groupMsg): ?>
                                                <div class="message-bubble">
                                                    <?php echo nl2br(htmlspecialchars($groupMsg['message'])); ?>
                                                </div>
                                            <?php endforeach; ?>
                                            
                                            <div class="message-time">
                                                <?php echo formatDateTime($messageGroup[count($messageGroup) - 1]['created_at']); ?>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                }
                                ?>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Scroll to bottom button -->
                        <button class="scroll-btn" id="scrollBtn" title="Scroll to bottom">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        
                        <!-- Chat Input -->
                        <div class="chat-input">
                            <form action="messages.php?recipient_id=<?php echo $recipientId; ?>" method="post" class="chat-input-form" id="messageForm">
                                <input type="hidden" name="action" value="send_message">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="receiver_id" value="<?php echo $recipientId; ?>">
                                
                                <div class="input-group">
                                    <textarea class="form-control" name="message" id="messageInput" placeholder="Type your message..." rows="1" required></textarea>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>
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
            
            // Chat functionality
            const chatMessages = document.getElementById('chatMessages');
            const messageInput = document.getElementById('messageInput');
            const scrollBtn = document.getElementById('scrollBtn');
            const messageForm = document.getElementById('messageForm');
            
            // Scroll messages to bottom on load
            if (chatMessages) {
                scrollToBottom();
                
                // Show scroll button when scrolled up
                chatMessages.addEventListener('scroll', function() {
                    const isScrolledUp = chatMessages.scrollTop < (chatMessages.scrollHeight - chatMessages.clientHeight - 100);
                    if (isScrolledUp) {
                        scrollBtn.classList.add('visible');
                    } else {
                        scrollBtn.classList.remove('visible');
                    }
                });
                
                // Scroll to bottom when button clicked
                if (scrollBtn) {
                    scrollBtn.addEventListener('click', scrollToBottom);
                }
            }
            
            // Auto-resize text area
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 150) + 'px';
                });
                
                // Focus on message input field
                messageInput.focus();
            }
            
            // Form submission - show loading overlay
            if (messageForm) {
                messageForm.addEventListener('submit', function(event) {
                    const message = messageInput.value.trim();
                    
                    if (!message) {
                        event.preventDefault();
                        return false;
                    }
                    
                    // Show loading overlay
                    document.getElementById('loadingOverlay').style.display = 'flex';
                    return true;
                });
            }
            
            function scrollToBottom() {
                if (chatMessages) {
                    chatMessages.scrollTop = chatMessages.scrollHeight;
                    if (scrollBtn) {
                        scrollBtn.classList.remove('visible');
                    }
                }
            }
        });
    </script>
</body>
</html>