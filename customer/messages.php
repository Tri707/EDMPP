<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Authentication check
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Redirect if not customer
if (!$loggedIn || $userRole !== 'customer' || $userId <= 0) {
    header('Location: ../login.php?redirect=customer');
    exit;
}

// Database connection using MySQLi
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$userData = [];
$userProfileImage = '../default.png';
$notifications = [];
$notificationCount = 0;
$errorMessage = '';
$successMessage = '';
$userInitials = 'CN'; // Default initials
$conversations = [];
$selectedConversation = null;
$messages = [];
$conversationUser = null;

// Check for flash messages from previous redirects
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle sending message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';
    $quoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;
    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
    
    if (!empty($message) && $receiverId > 0) {
        try {
            // Insert message
            $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, quote_id, booking_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("iisii", $userId, $receiverId, $message, $quoteId, $bookingId);
            
            if ($stmt->execute()) {
                $successMessage = "Message sent successfully";
                
                // Redirect to avoid form resubmission
                header('Location: messages.php?conversation=' . $receiverId . '&success=1');
                exit;
            } else {
                $errorMessage = "Failed to send message";
            }
        } catch (Exception $e) {
            $errorMessage = "An error occurred while sending the message";
            error_log("Error sending message: " . $e->getMessage());
        }
    } else {
        $errorMessage = "Please enter a message";
    }
}

try {
    // Query to get customer user data
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    
    if (!$userData) {
        // If customer data doesn't exist, log them out
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=invalid_session');
        exit;
    }
    
    // Set profile image path with proper validation
    if (!empty($userData['profile_image'])) {
        if (filter_var($userData['profile_image'], FILTER_VALIDATE_URL)) {
            $userProfileImage = $userData['profile_image'];
        } else {
            $imageFile = basename($userData['profile_image']);
            $imagePath = '../profile_images/' . $imageFile;
            if (file_exists($imagePath) && is_file($imagePath)) {
                $userProfileImage = $imagePath;
            }
        }
    }
    
    // Get user initials for avatar
    if (!empty($userData['first_name']) && !empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1));
    } elseif (!empty($userData['first_name'])) {
        $userInitials = strtoupper(substr($userData['first_name'], 0, 2));
    } elseif (!empty($userData['last_name'])) {
        $userInitials = strtoupper(substr($userData['last_name'], 0, 2));
    }
    
    // Get unread notifications for the customer and count
    $notifQuery = "
        SELECT * FROM quote_notifications
        WHERE recipient_id = ?
        AND is_read = 0
        ORDER BY created_at DESC
        LIMIT 5
    ";
    
    $notifStmt = $conn->prepare($notifQuery);
    $notifStmt->bind_param("i", $userId);
    $notifStmt->execute();
    $notifResult = $notifStmt->get_result();
    $notifications = [];
    
    while ($row = $notifResult->fetch_assoc()) {
        $notifications[] = $row;
    }
    $notificationCount = count($notifications);
    
    // Get recent notifications for dropdown (including read ones)
    $recentNotifQuery = "
        SELECT * FROM quote_notifications 
        WHERE recipient_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ";
    
    $recentNotifStmt = $conn->prepare($recentNotifQuery);
    $recentNotifStmt->bind_param("i", $userId);
    $recentNotifStmt->execute();
    $recentNotifResult = $recentNotifStmt->get_result();
    $recentNotifications = [];
    
    while ($row = $recentNotifResult->fetch_assoc()) {
        $recentNotifications[] = $row;
    }
    
    // Get list of conversations for this customer
    $conversationsQuery = "
        SELECT 
            CASE 
                WHEN m.sender_id = ? THEN m.receiver_id
                ELSE m.sender_id
            END as conversation_with_id,
            MAX(m.created_at) as last_message_time,
            COUNT(CASE WHEN m.is_read = 0 AND m.receiver_id = ? THEN 1 END) as unread_count
        FROM 
            messages m
        WHERE 
            m.sender_id = ? OR m.receiver_id = ?
        GROUP BY 
            conversation_with_id
        ORDER BY 
            last_message_time DESC
    ";
    
    $conversationsStmt = $conn->prepare($conversationsQuery);
    $conversationsStmt->bind_param("iiii", $userId, $userId, $userId, $userId);
    $conversationsStmt->execute();
    $conversationsResult = $conversationsStmt->get_result();
    
    // Get user details for each conversation
    while ($conv = $conversationsResult->fetch_assoc()) {
        $conversationWithId = $conv['conversation_with_id'];
        
        // Get user details
        $userStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->bind_param("i", $conversationWithId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $user = $userResult->fetch_assoc();
        
        if ($user) {
            // Get provider data if user is a provider
            $providerData = null;
            if ($user['role'] === 'provider') {
                $providerStmt = $conn->prepare("SELECT * FROM providers WHERE user_id = ?");
                $providerStmt->bind_param("i", $conversationWithId);
                $providerStmt->execute();
                $providerResult = $providerStmt->get_result();
                $providerData = $providerResult->fetch_assoc();
            }
            
            // Get quote data if exists
            $quoteId = null;
            $bookingId = null;
            $contextQuery = "
                SELECT DISTINCT quote_id, booking_id 
                FROM messages 
                WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
                AND (quote_id IS NOT NULL OR booking_id IS NOT NULL)
                LIMIT 1
            ";
            $contextStmt = $conn->prepare($contextQuery);
            $contextStmt->bind_param("iiii", $userId, $conversationWithId, $conversationWithId, $userId);
            $contextStmt->execute();
            $contextResult = $contextStmt->get_result();
            
            if ($context = $contextResult->fetch_assoc()) {
                $quoteId = $context['quote_id'];
                $bookingId = $context['booking_id'];
            }
            
            // Get the most recent message
            $lastMessageQuery = "
                SELECT message, created_at
                FROM messages
                WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                ORDER BY created_at DESC
                LIMIT 1
            ";
            $lastMessageStmt = $conn->prepare($lastMessageQuery);
            $lastMessageStmt->bind_param("iiii", $userId, $conversationWithId, $conversationWithId, $userId);
            $lastMessageStmt->execute();
            $lastMessageResult = $lastMessageStmt->get_result();
            $lastMessage = $lastMessageResult->fetch_assoc();
            
            // Add to conversations array
            $conversations[] = [
                'user_id' => $conversationWithId,
                'user' => $user,
                'provider' => $providerData,
                'unread_count' => $conv['unread_count'],
                'last_message_time' => $conv['last_message_time'],
                'last_message' => $lastMessage['message'] ?? '',
                'quote_id' => $quoteId,
                'booking_id' => $bookingId
            ];
        }
    }
    
    // Get the selected conversation if any
    if (isset($_GET['conversation']) && is_numeric($_GET['conversation'])) {
        $selectedConversation = (int)$_GET['conversation'];
        
        // Get user details for the conversation partner
        $userStmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $userStmt->bind_param("i", $selectedConversation);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $conversationUser = $userResult->fetch_assoc();
        
        if ($conversationUser) {
            // Get provider data if user is a provider
            $providerData = null;
            if ($conversationUser['role'] === 'provider') {
                $providerStmt = $conn->prepare("SELECT * FROM providers WHERE user_id = ?");
                $providerStmt->bind_param("i", $selectedConversation);
                $providerStmt->execute();
                $providerResult = $providerStmt->get_result();
                $providerData = $providerResult->fetch_assoc();
                $conversationUser['provider'] = $providerData;
            }
            
            // Get messages for this conversation
            $messagesQuery = "
                SELECT m.*, 
                       CASE WHEN m.sender_id = ? THEN 1 ELSE 0 END as is_sent_by_me
                FROM messages m
                WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.created_at ASC
            ";
            
            $messagesStmt = $conn->prepare($messagesQuery);
            $messagesStmt->bind_param("iiiii", $userId, $userId, $selectedConversation, $selectedConversation, $userId);
            $messagesStmt->execute();
            $messagesResult = $messagesStmt->get_result();
            
            while ($message = $messagesResult->fetch_assoc()) {
                $messages[] = $message;
                
                // Mark messages as read if they were sent to me
                if ($message['receiver_id'] == $userId && !$message['is_read']) {
                    $updateStmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE id = ?");
                    $updateStmt->bind_param("i", $message['id']);
                    $updateStmt->execute();
                }
            }
            
            // Get context information (quote or booking)
            $quoteInfo = null;
            $bookingInfo = null;
            
            // Find if there's a quote for this conversation
            $quoteQuery = "
                SELECT DISTINCT m.quote_id
                FROM messages m
                WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                AND m.quote_id IS NOT NULL
                LIMIT 1
            ";
            
            $quoteStmt = $conn->prepare($quoteQuery);
            $quoteStmt->bind_param("iiii", $userId, $selectedConversation, $selectedConversation, $userId);
            $quoteStmt->execute();
            $quoteResult = $quoteStmt->get_result();
            
            if ($quoteRow = $quoteResult->fetch_assoc()) {
                $quoteId = $quoteRow['quote_id'];
                
                $quoteDetailsQuery = "
                    SELECT q.*, qr.device_type, qr.issue_description
                    FROM quotes q
                    JOIN quote_requests qr ON q.request_id = qr.id
                    WHERE q.id = ?
                ";
                
                $quoteDetailsStmt = $conn->prepare($quoteDetailsQuery);
                $quoteDetailsStmt->bind_param("i", $quoteId);
                $quoteDetailsStmt->execute();
                $quoteDetailsResult = $quoteDetailsStmt->get_result();
                
                if ($quoteInfo = $quoteDetailsResult->fetch_assoc()) {
                    // Set quote context for form submission
                    $conversationUser['quote_id'] = $quoteId;
                }
            }
            
            // Find if there's a booking for this conversation
            $bookingQuery = "
                SELECT DISTINCT m.booking_id
                FROM messages m
                WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                AND m.booking_id IS NOT NULL
                LIMIT 1
            ";
            
            $bookingStmt = $conn->prepare($bookingQuery);
            $bookingStmt->bind_param("iiii", $userId, $selectedConversation, $selectedConversation, $userId);
            $bookingStmt->execute();
            $bookingResult = $bookingStmt->get_result();
            
            if ($bookingRow = $bookingResult->fetch_assoc()) {
                $bookingId = $bookingRow['booking_id'];
                
                $bookingDetailsQuery = "
                    SELECT b.*, s.name as service_name
                    FROM bookings b
                    LEFT JOIN services s ON b.service_id = s.id
                    WHERE b.id = ?
                ";
                
                $bookingDetailsStmt = $conn->prepare($bookingDetailsQuery);
                $bookingDetailsStmt->bind_param("i", $bookingId);
                $bookingDetailsStmt->execute();
                $bookingDetailsResult = $bookingDetailsStmt->get_result();
                
                if ($bookingInfo = $bookingDetailsResult->fetch_assoc()) {
                    // Set booking context for form submission
                    $conversationUser['booking_id'] = $bookingId;
                }
            }
            
            $conversationUser['quote_info'] = $quoteInfo;
            $conversationUser['booking_info'] = $bookingInfo;
        }
    }
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching your data. Please try again later.";
}

// Function to format dates
function formatDate($dateString, $format = 'M d, Y') {
    if (empty($dateString)) return 'N/A';
    
    try {
        $date = new DateTime($dateString);
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// Function to format times
function formatTime($timeString, $format = 'h:i A') {
    if (empty($timeString)) return 'N/A';
    
    try {
        $date = new DateTime($timeString);
        return $date->format($format);
    } catch (Exception $e) {
        return 'Invalid Time';
    }
}

// Function to calculate time ago
function timeAgo($timestamp) {
    if (empty($timestamp)) return 'N/A';
    
    try {
        $time = new DateTime($timestamp);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $time->getTimestamp();
        
        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return formatDate($timestamp);
        }
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}

// Function to format message time for display in chat
function formatMessageTime($timestamp) {
    if (empty($timestamp)) return '';
    
    try {
        $date = new DateTime($timestamp);
        $now = new DateTime();
        
        if ($date->format('Y-m-d') === $now->format('Y-m-d')) {
            // Today, show only time
            return $date->format('h:i A');
        } elseif ($date->format('Y-m-d') === $now->modify('-1 day')->format('Y-m-d')) {
            // Yesterday
            return 'Yesterday ' . $date->format('h:i A');
        } else {
            // Other days
            return $date->format('M d, h:i A');
        }
    } catch (Exception $e) {
        return '';
    }
}

// Function to get user initials from name
function getUserInitials($firstName, $lastName) {
    if (!empty($firstName) && !empty($lastName)) {
        return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
    } elseif (!empty($firstName)) {
        return strtoupper(substr($firstName, 0, 2));
    } elseif (!empty($lastName)) {
        return strtoupper(substr($lastName, 0, 2));
    } else {
        return 'UN';
    }
}

// Function to format price with currency
function formatPrice($price, $currencyImgPath = '../sar/sar.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    $currencyImg = '<img src="' . htmlspecialchars($currencyImgPath, ENT_QUOTES, 'UTF-8') . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}

// Get device type name for display
function getDeviceTypeName($type) {
    $types = [
        'smartphone' => 'Smartphone',
        'laptop' => 'Laptop',
        'tablet' => 'Tablet',
        'desktop' => 'Desktop Computer',
        'gaming' => 'Gaming Console',
        'tv' => 'Television/Smart TV'
    ];
    
    return isset($types[$type]) ? $types[$type] : ucfirst($type);
}
?>

<!DOCTYPE html>
<html lang="en" dir="ltr">
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
            --bg-color: #f8f9fa;           /* Light background */
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
            --warning-color: #ffc107;      /* Warning/yellow color */
            --success-color: #28a745;      /* Success/green color */
            --chat-bg: #f5f5f5;            /* Chat background */
            --chat-sent: #dcf8c6;          /* Sent message background */
            --chat-received: #ffffff;      /* Received message background */
        }
        
        /* Dark Theme Variables */
        [data-bs-theme="dark"] {
            --primary-color: #a687ff;      /* More vibrant purple */
            --primary-hover: #9775fa;      /* Brighter purple hover */
            --primary-light: #473a6b;      /* Less dark purple for better contrast */
            --accent-color: #4cd963;       /* More vibrant green */
            --accent-light: #2a7d3f;       /* Brighter green light */
            --text-color: #f8f9fa;         /* Brighter white text */
            --text-muted: #c5cfd8;         /* Less muted text */
            --bg-color: #212529;           /* Slightly less dark background */
            --card-bg: #2c3034;            /* Less dark card background */
            --header-bg: #151518;          /* Slightly adjusted header */
            --header-text: #ffffff;        /* Pure white header text */
            --footer-bg: #151518;          /* Matching footer background */
            --footer-text: #c5cfd8;        /* Brighter footer text */
            --border-color: #3d4349;       /* More visible border */
            --input-bg: #323237;           /* Slightly lighter input background */
            --input-border: #5a5a66;       /* More visible input border */
            --modal-bg: #2c3034;           /* Matching modal background */
            --shadow-color: rgba(0, 0, 0, 0.35); /* Slightly stronger shadow */
            --sidebar-bg: #1c1c20;         /* Darker sidebar background */
            --sidebar-hover: #27272c;      /* Darker sidebar hover */
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --danger-light: #482930;       /* Darker danger background for dark mode */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
            --chat-bg: #1e1e24;            /* Chat background for dark mode */
            --chat-sent: #4a5861;          /* Sent message background for dark mode */
            --chat-received: #2c3034;      /* Received message background for dark mode */
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
        
        /* Enhanced Header Styles */
        .site-header {
            background-color: #212529;
            padding: 0.75rem 0;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: #fff;
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: transform 0.2s;
        }
        
        .logo-text:hover {
            transform: scale(1.02);
            color: #fff;
        }
        
        .logo-text .highlight {
            color: #4cd963;
            font-weight: 900;
        }
        
        /* Main Navigation Styles */
        .main-nav {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .nav-button {
            display: flex;
            align-items: center;
            padding: 0.6rem 1.1rem;
            border-radius: 0.375rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }
        
        .nav-button.active {
            background-color: #7952b3;
            color: white;
            box-shadow: 0 2px 8px rgba(121, 82, 179, 0.4);
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
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
            margin-left: 280px;
            transition: all 0.3s ease;
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
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
        
        /* Header Icon Buttons */
        .header-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .header-icon-btn:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
            transform: translateY(-1px);
        }
        
        /* Notification Badge */
        .notification-badge {
            position: absolute;
            top: -3px;
            right: -3px;
            background-color: #dc3545;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(220, 53, 69, 0.4);
        }
        
        /* Notification Dropdown */
        .notification-dropdown {
            width: 320px;
            padding: 0.5rem 0;
            max-height: 400px;
            overflow-y: auto;
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
        }
        
        .notification-item {
            display: flex;
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            flex-shrink: 0;
            font-size: 1rem;
        }
        
        .notification-icon.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        
        .notification-icon.primary {
            background-color: rgba(121, 82, 179, 0.1);
            color: #7952b3;
        }
        
        .notification-content {
            flex: 1;
        }
        
        /* User Dropdown */
        .user-dropdown-toggle {
            display: flex;
            align-items: center;
            padding: 0.4rem 0.6rem;
            border-radius: 0.375rem;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.95);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .user-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            background-color: #7952b3;
            color: white;
            border-radius: 50%;
            font-size: 0.85rem;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(121, 82, 179, 0.3);
        }
        
        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.05);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 71px;
            left: 0;
            width: 100%;
            background-color: #212529;
            z-index: 999;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            overflow-y: auto;
            max-height: calc(100vh - 71px);
        }
        
        .mobile-menu.show {
            display: block;
        }
        
        .mobile-menu-inner {
            padding: 1rem 0;
        }
        
        .mobile-menu-item {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
        }
        
        .mobile-menu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
            color: #fff;
            border-left-color: rgba(255, 255, 255, 0.2);
        }
        
        .mobile-menu-item.active {
            background-color: rgba(121, 82, 179, 0.1);
            color: #fff;
            border-left-color: #7952b3;
        }
        
        .mobile-menu-item i {
            width: 24px;
            margin-right: 0.75rem;
            text-align: center;
        }
        
        .mobile-menu-divider {
            height: 1px;
            background-color: rgba(255, 255, 255, 0.1);
            margin: 0.5rem 1.5rem;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }
        
        .empty-state-icon {
            font-size: 3.5rem;
            color: var(--text-muted);
            opacity: 0.3;
            margin-bottom: 1.5rem;
        }
        
        .empty-state-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        
        .empty-state-text {
            color: var(--text-muted);
            max-width: 400px;
            margin: 0 auto 1.5rem;
        }
        
        /* Conversation list styles */
        .conversation-list {
            overflow-y: auto;
            max-height: calc(100vh - 200px);
            border-right: 1px solid var(--border-color);
        }
        
        .conversation-item {
            display: flex;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.2s;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .conversation-item.active {
            background-color: var(--primary-light);
        }
        
        .conversation-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .conversation-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            margin-right: 1rem;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            position: relative;
            flex-shrink: 0;
        }
        
        .conversation-avatar.provider {
            background-color: var(--accent-color);
        }
        
        .conversation-unread {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--danger-color);
            color: white;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid white;
        }
        
        .conversation-details {
            flex: 1;
            min-width: 0; /* For text truncation to work */
        }
        
        .conversation-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .conversation-preview {
            font-size: 0.875rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 0.25rem;
        }
        
        .conversation-time {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        /* Message Thread Styles */
        .message-thread {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 250px);
        }
        
        .message-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            background-color: var(--card-bg);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        
        .contact-info {
            display: flex;
            align-items: center;
        }
        
        .contact-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-right: 1rem;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        .contact-avatar.provider {
            background-color: var(--accent-color);
        }
        
        .contact-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .contact-status {
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .message-container {
            flex: 1;
            overflow-y: auto;
            padding: 1rem;
            background-color: var(--chat-bg);
        }
        
        .message-bubble {
            max-width: 75%;
            padding: 0.75rem 1rem;
            border-radius: 1rem;
            margin-bottom: 1rem;
            position: relative;
            word-wrap: break-word;
        }
        
        .message-sent {
            background-color: var(--chat-sent);
            color: var(--text-color);
            margin-left: auto;
            border-bottom-right-radius: 0.25rem;
        }
        
        .message-received {
            background-color: var(--chat-received);
            color: var(--text-color);
            margin-right: auto;
            border-bottom-left-radius: 0.25rem;
        }
        
        .message-time {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-align: right;
            margin-top: 0.25rem;
        }
        
        .message-input-container {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            background-color: var(--card-bg);
            position: sticky;
            bottom: 0;
        }
        
        .message-input-group {
            display: flex;
            align-items: center;
        }
        
        .message-input {
            flex: 1;
            border-radius: 1.5rem;
            padding: 0.5rem 1rem;
            border: 1px solid var(--border-color);
            resize: none;
            max-height: 100px;
            background-color: var(--input-bg);
            color: var(--text-color);
        }
        
        .message-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(121, 82, 179, 0.25);
        }
        
        .message-send-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            margin-left: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-color);
            color: white;
            border: none;
            flex-shrink: 0;
            transition: all 0.2s;
        }
        
        .message-send-btn:hover {
            background-color: var(--primary-hover);
            transform: scale(1.05);
        }
        
        .message-send-btn:active {
            transform: scale(0.95);
        }
        
        /* Context Card Styles */
        .context-card {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 0.5rem;
            background-color: rgba(var(--bs-primary-rgb), 0.05);
            border-left: 3px solid var(--primary-color);
        }
        
        .context-card-title {
            font-weight: 600;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
        }
        
        .context-card-content {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        /* No Conversation Selected State */
        .no-conversation-selected {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100%;
            text-align: center;
            color: var(--text-muted);
            padding: 2rem;
        }
        
        .no-conversation-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
        
        .no-conversation-text {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .no-conversation-subtext {
            max-width: 300px;
            margin-bottom: 1.5rem;
        }
        
        /* Date Separator */
        .date-separator {
            text-align: center;
            margin: 1rem 0;
            position: relative;
        }
        
        .date-separator:before {
            content: '';
            position: absolute;
            left: 0;
            right: 0;
            top: 50%;
            height: 1px;
            background-color: var(--border-color);
            z-index: 1;
        }
        
        .date-text {
            display: inline-block;
            padding: 0 1rem;
            background-color: var(--chat-bg);
            position: relative;
            z-index: 2;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        /* Device Type Badge */
        .device-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            background-color: var(--primary-light);
            color: var(--primary-color);
            margin-right: 0.5rem;
        }
        
        .device-badge i {
            margin-right: 0.25rem;
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-badge.pending {
            background-color: rgba(var(--bs-warning-rgb), 0.1);
            color: var(--bs-warning);
        }
        
        .status-badge.confirmed {
            background-color: rgba(var(--bs-primary-rgb), 0.1);
            color: var(--primary-color);
        }
        
        .status-badge.completed {
            background-color: rgba(var(--bs-success-rgb), 0.1);
            color: var(--bs-success);
        }
        
        .status-badge.cancelled {
            background-color: rgba(var(--bs-danger-rgb), 0.1);
            color: var(--bs-danger);
        }
        
        /* Media Queries */
        @media (max-width: 992px) {
            .conversation-list-container {
                display: none;
            }
            
            .message-thread-container {
                flex: 1;
            }
            
            .back-to-list {
                display: block !important;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="logo-text">
                    <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="main-nav d-none d-lg-flex">
                    <a href="../index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="../find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="../services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="../how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="mobile-menu-toggle d-lg-none me-3" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="header-icon-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></div>
                            <i class="fas fa-chevron-down fa-xs ms-2"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-tachometer-alt me-2"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="bookings.php"><i class="fas fa-calendar-check me-2"></i> My Bookings</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="mobile-menu d-lg-none" id="mobileMenu">
            <div class="mobile-menu-inner">
                <a href="../index.php" class="mobile-menu-item">
                    <i class="fas fa-home"></i> Home
                </a>
                <a href="../find-technician.php" class="mobile-menu-item">
                    <i class="fas fa-search"></i> Find Technician
                </a>
                <a href="../services.php" class="mobile-menu-item">
                    <i class="fas fa-cogs"></i> Services
                </a>
                <a href="../how-it-works.php" class="mobile-menu-item">
                    <i class="fas fa-info-circle"></i> How It Works
                </a>
                <div class="mobile-menu-divider"></div>
                <a href="dashboard.php" class="mobile-menu-item">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="bookings.php" class="mobile-menu-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="profile.php" class="mobile-menu-item">
                    <i class="fas fa-user"></i> My Profile
                </a>
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
                            <span class="nav-icon"><i class="fas fa-tachometer-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Dashboard</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
                            <span class="nav-text">My Bookings</span>
                        </a>
                    </li>  
                    <li class="nav-item">
                        <a class="nav-link" href="quotes.php">
                            <span class="nav-icon"><i class="fas fa-clipboard-list" aria-hidden="true"></i></span>
                            <span class="nav-text">Quote Requests</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="reviews.php">
                            <span class="nav-icon"><i class="fas fa-star" aria-hidden="true"></i></span>
                            <span class="nav-text">My Reviews</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link " href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="profile.php">
                            <span class="nav-icon"><i class="fas fa-user" aria-hidden="true"></i></span>
                            <span class="nav-text">My Profile</span>
                        </a>
                    </li>
                    <li class="nav-item mt-4">
                        <a class="nav-link text-danger" href="../logout.php">
                            <span class="nav-icon"><i class="fas fa-sign-out-alt" aria-hidden="true"></i></span>
                            <span class="nav-text">Logout</span>
                        </a>
                    </li>
                </ul>
            </div>
        </aside>
        
        <!-- Content Area -->
        <div class="content-area">
            <div class="container-fluid px-4">
                <!-- Page title -->
                <h1 class="page-title">Messages</h1>
                
                <!-- Alert messages -->
                <?php if (!empty($errorMessage)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($errorMessage); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($successMessage) || isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i> <?php echo !empty($successMessage) ? htmlspecialchars($successMessage) : 'Message sent successfully'; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Messages Interface -->
                <div class="card">
                    <div class="card-body p-0">
                        <div class="row g-0">
                            <!-- Conversation List (Left side) -->
                            <div class="col-lg-4 col-md-5 conversation-list-container">
                                <div class="conversation-list">
                                    <?php if (count($conversations) > 0): ?>
                                        <?php foreach ($conversations as $conv): ?>
                                            <a href="messages.php?conversation=<?php echo $conv['user_id']; ?>" class="conversation-item <?php echo ($selectedConversation == $conv['user_id']) ? 'active' : ''; ?>">
                                                <div class="conversation-avatar <?php echo ($conv['user']['role'] === 'provider') ? 'provider' : ''; ?>">
                                                    <?php echo getUserInitials($conv['user']['first_name'], $conv['user']['last_name']); ?>
                                                    <?php if ($conv['unread_count'] > 0): ?>
                                                        <span class="conversation-unread"><?php echo $conv['unread_count']; ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="conversation-details">
                                                    <div class="conversation-name">
                                                        <?php echo htmlspecialchars($conv['user']['first_name'] . ' ' . $conv['user']['last_name']); ?>
                                                    </div>
                                                    <div class="conversation-preview">
                                                        <?php echo htmlspecialchars(mb_substr($conv['last_message'], 0, 50) . (mb_strlen($conv['last_message']) > 50 ? '...' : '')); ?>
                                                    </div>
                                                    <div class="conversation-time">
                                                        <?php echo timeAgo($conv['last_message_time']); ?>
                                                    </div>
                                                </div>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="p-4 text-center text-muted">
                                            <div class="mb-3">
                                                <i class="fas fa-comment-slash fa-3x opacity-25"></i>
                                            </div>
                                            <h5>No messages yet</h5>
                                            <p class="small">You haven't started any conversations yet. Messages from technicians will appear here.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Message Thread (Right side) -->
                            <div class="col-lg-8 col-md-7 message-thread-container">
                                <?php if ($selectedConversation && $conversationUser): ?>
                                    <div class="message-thread">
                                        <!-- Message Thread Header -->
                                        <div class="message-header">
                                            <div class="d-flex justify-content-between">
                                                <div class="contact-info">
                                                    <button class="btn btn-sm btn-outline-secondary me-2 d-none back-to-list">
                                                        <i class="fas fa-arrow-left"></i>
                                                    </button>
                                                    <div class="contact-avatar <?php echo ($conversationUser['role'] === 'provider') ? 'provider' : ''; ?>">
                                                        <?php echo getUserInitials($conversationUser['first_name'], $conversationUser['last_name']); ?>
                                                    </div>
                                                    <div>
                                                        <div class="contact-name">
                                                            <?php echo htmlspecialchars($conversationUser['first_name'] . ' ' . $conversationUser['last_name']); ?>
                                                        </div>
                                                        <div class="contact-status">
                                                            <?php if ($conversationUser['role'] === 'provider'): ?>
                                                                <span class="badge bg-success">Technician</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-info">Customer</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <?php if ($conversationUser['quote_info'] || $conversationUser['booking_info']): ?>
                                                <div class="context-card mt-3">
                                                    <?php if ($conversationUser['quote_info']): ?>
                                                        <div class="context-card-title">
                                                            <i class="fas fa-file-invoice-dollar me-1"></i> Quote for 
                                                            <span class="device-badge">
                                                                <i class="fas fa-<?php 
                                                                switch($conversationUser['quote_info']['device_type']) {
                                                                    case 'smartphone': echo 'mobile-alt'; break;
                                                                    case 'laptop': echo 'laptop'; break;
                                                                    case 'tablet': echo 'tablet-alt'; break;
                                                                    case 'desktop': echo 'desktop'; break;
                                                                    case 'gaming': echo 'gamepad'; break;
                                                                    case 'tv': echo 'tv'; break;
                                                                    default: echo 'cogs';
                                                                }
                                                                ?>"></i>
                                                                <?php echo getDeviceTypeName($conversationUser['quote_info']['device_type']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="context-card-content">
                                                            <div><strong>Issue:</strong> <?php echo htmlspecialchars(mb_substr($conversationUser['quote_info']['issue_description'], 0, 100) . (mb_strlen($conversationUser['quote_info']['issue_description']) > 100 ? '...' : '')); ?></div>
                                                            <div><strong>Price:</strong> <?php echo formatPrice($conversationUser['quote_info']['price']); ?></div>
                                                            <div><strong>Status:</strong> <?php echo ucfirst($conversationUser['quote_info']['status']); ?></div>
                                                        </div>
                                                    <?php elseif ($conversationUser['booking_info']): ?>
                                                        <div class="context-card-title">
                                                            <i class="fas fa-calendar-check me-1"></i> Booking #<?php echo $conversationUser['booking_info']['id']; ?>
                                                        </div>
                                                        <div class="context-card-content">
                                                            <div><strong>Service:</strong> <?php echo htmlspecialchars($conversationUser['booking_info']['service_name'] ?? 'N/A'); ?></div>
                                                            <div><strong>Date:</strong> <?php echo formatDate($conversationUser['booking_info']['booking_date']); ?> at <?php echo formatTime($conversationUser['booking_info']['booking_time']); ?></div>
                                                            <div><strong>Status:</strong> <?php echo ucfirst($conversationUser['booking_info']['status']); ?></div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Message Container -->
                                        <div class="message-container" id="messageContainer">
                                            <?php 
                                            $current_date = '';
                                            foreach ($messages as $msg): 
                                                // Check if we need to add a date separator
                                                $message_date = date('Y-m-d', strtotime($msg['created_at']));
                                                if ($message_date != $current_date):
                                                    $current_date = $message_date;
                                                    $date_text = '';
                                                    
                                                    $today = date('Y-m-d');
                                                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                                                    
                                                    if ($message_date === $today) {
                                                        $date_text = 'Today';
                                                    } elseif ($message_date === $yesterday) {
                                                        $date_text = 'Yesterday';
                                                    } else {
                                                        $date_text = date('F j, Y', strtotime($message_date));
                                                    }
                                            ?>
                                                <div class="date-separator">
                                                    <span class="date-text"><?php echo $date_text; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            
                                                <div class="message-bubble <?php echo ($msg['is_sent_by_me']) ? 'message-sent' : 'message-received'; ?>">
                                                    <?php echo nl2br(htmlspecialchars($msg['message'])); ?>
                                                    <div class="message-time">
                                                        <?php echo formatMessageTime($msg['created_at']); ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        
                                        <!-- Message Input Form -->
                                        <div class="message-input-container">
                                            <form action="messages.php?conversation=<?php echo $selectedConversation; ?>" method="post">
                                                <div class="message-input-group">
                                                    <textarea name="message" class="message-input" placeholder="Type a message..." required></textarea>
                                                    <input type="hidden" name="receiver_id" value="<?php echo $selectedConversation; ?>">
                                                    <?php if (isset($conversationUser['quote_id'])): ?>
                                                        <input type="hidden" name="quote_id" value="<?php echo $conversationUser['quote_id']; ?>">
                                                    <?php endif; ?>
                                                    <?php if (isset($conversationUser['booking_id'])): ?>
                                                        <input type="hidden" name="booking_id" value="<?php echo $conversationUser['booking_id']; ?>">
                                                    <?php endif; ?>
                                                    <input type="hidden" name="send_message" value="1">
                                                    <button type="submit" class="message-send-btn">
                                                        <i class="fas fa-paper-plane"></i>
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="no-conversation-selected">
                                        <div class="no-conversation-icon">
                                            <i class="fas fa-comments"></i>
                                        </div>
                                        <div class="no-conversation-text">No conversation selected</div>
                                        <div class="no-conversation-subtext">
                                            Select a conversation from the list or start a new one from your quotes or bookings.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Custom JS -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Scroll to bottom of message container
            const messageContainer = document.getElementById('messageContainer');
            if (messageContainer) {
                messageContainer.scrollTop = messageContainer.scrollHeight;
            }
            
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('show');
                });
            }
            
            // Theme toggle functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            
            if (themeToggle && themeIcon) {
                // Check for saved theme preference or default to 'light'
                const savedTheme = localStorage.getItem('theme') || 'light';
                
                // Apply the saved theme
                document.documentElement.setAttribute('data-bs-theme', savedTheme);
                
                // Update icon based on current theme
                if (savedTheme === 'dark') {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                }
                
                // Handle theme toggle click
                themeToggle.addEventListener('click', function() {
                    const currentTheme = document.documentElement.getAttribute('data-bs-theme');
                    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                    
                    // Update theme
                    document.documentElement.setAttribute('data-bs-theme', newTheme);
                    
                    // Save preference
                    localStorage.setItem('theme', newTheme);
                    
                    // Update icon
                    if (newTheme === 'dark') {
                        themeIcon.classList.remove('fa-moon');
                        themeIcon.classList.add('fa-sun');
                    } else {
                        themeIcon.classList.remove('fa-sun');
                        themeIcon.classList.add('fa-moon');
                    }
                });
            }
            
            // Back to list button for mobile
            const backToListBtn = document.querySelector('.back-to-list');
            if (backToListBtn) {
                backToListBtn.addEventListener('click', function() {
                    document.querySelector('.conversation-list-container').style.display = 'block';
                    document.querySelector('.message-thread-container').style.display = 'none';
                });
            }
            
            // Auto-resize textarea
            const messageInput = document.querySelector('.message-input');
            if (messageInput) {
                messageInput.addEventListener('input', function() {
                    this.style.height = 'auto';
                    this.style.height = (this.scrollHeight) + 'px';
                });
            }
        });
    </script>
</body>
</html>