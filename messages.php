<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isCustomer = $loggedIn && $userRole === 'customer';
$isProvider = $loggedIn && $userRole === 'provider';

// Redirect if not logged in
if (!$loggedIn) {
    $_SESSION['error_message'] = 'You must be logged in to access messages.';
    header('Location: login.php?redirect=messages.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$contacts = [];
$messages = [];
$activeContact = null;
$errorMessage = '';
$successMessage = '';
$providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0; // For starting a new conversation
$bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0; // For booking-related messages
$quoteId = isset($_GET['quote_id']) ? (int)$_GET['quote_id'] : 0; // For quote-related messages
$activeContactId = isset($_GET['contact']) ? (int)$_GET['contact'] : 0; // For viewing a specific conversation

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Get user details
$userInfo = [];
if ($userId > 0) {
    try {
        $userQuery = "SELECT id, first_name, last_name, profile_image FROM users WHERE id = ?";
        $stmt = $conn->prepare($userQuery);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $userInfo = $result->fetch_assoc();
        }
    } catch (Exception $e) {
        error_log("Error fetching user details: " . $e->getMessage());
    }
}

// Get contact list (people the user has conversations with)
try {
    if ($isCustomer) {
        // For customers, get all providers they've messaged with
        $contactsQuery = "
            SELECT DISTINCT 
                p.id as provider_id,
                u.id as user_id,
                u.first_name,
                u.last_name,
                u.profile_image,
                p.is_verified,
                (SELECT MAX(created_at) FROM messages 
                 WHERE (sender_id = ? AND receiver_id = u.id) 
                    OR (sender_id = u.id AND receiver_id = ?)
                ) as last_message_time,
                (SELECT COUNT(*) FROM messages 
                 WHERE receiver_id = ? AND sender_id = u.id AND is_read = 0
                ) as unread_count
            FROM messages m
            JOIN users u ON (
                (m.sender_id = ? AND m.receiver_id = u.id) OR 
                (m.sender_id = u.id AND m.receiver_id = ?)
            )
            JOIN providers p ON u.id = p.user_id
            WHERE u.role = 'provider'
            GROUP BY u.id
            ORDER BY last_message_time DESC
        ";
        
        $stmt = $conn->prepare($contactsQuery);
        $stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $userId);
    } else {
        // For providers, get all customers they've messaged with
        $contactsQuery = "
            SELECT DISTINCT 
                u.id as user_id,
                u.first_name,
                u.last_name,
                u.profile_image,
                (SELECT MAX(created_at) FROM messages 
                 WHERE (sender_id = ? AND receiver_id = u.id) 
                    OR (sender_id = u.id AND receiver_id = ?)
                ) as last_message_time,
                (SELECT COUNT(*) FROM messages 
                 WHERE receiver_id = ? AND sender_id = u.id AND is_read = 0
                ) as unread_count
            FROM messages m
            JOIN users u ON (
                (m.sender_id = ? AND m.receiver_id = u.id) OR 
                (m.sender_id = u.id AND m.receiver_id = ?)
            )
            WHERE u.role = 'customer'
            GROUP BY u.id
            ORDER BY last_message_time DESC
        ";
        
        $stmt = $conn->prepare($contactsQuery);
        $stmt->bind_param('iiiii', $userId, $userId, $userId, $userId, $userId);
    }
    
    $stmt->execute();
    $contactsResult = $stmt->get_result();
    
    if ($contactsResult && $contactsResult->num_rows > 0) {
        while ($row = $contactsResult->fetch_assoc()) {
            $contacts[] = $row;
            
            // Set active contact if specified in URL or if this is the first contact
            if (($isCustomer && $row['user_id'] == $activeContactId) || 
                (!$isCustomer && $row['user_id'] == $activeContactId) ||
                (empty($activeContact) && empty($activeContactId))) {
                $activeContact = $row;
            }
        }
    }
    
    // If a specific provider was requested but not in contacts, get their info
    if ($isCustomer && $providerId > 0 && empty($activeContact)) {
        $providerQuery = "
            SELECT 
                p.id as provider_id,
                u.id as user_id,
                u.first_name,
                u.last_name,
                u.profile_image,
                p.is_verified,
                0 as unread_count
            FROM providers p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = ? AND u.status = 'active'
        ";
        
        $stmt = $conn->prepare($providerQuery);
        $stmt->bind_param('i', $providerId);
        $stmt->execute();
        $providerResult = $stmt->get_result();
        
        if ($providerResult && $providerResult->num_rows > 0) {
            $activeContact = $providerResult->fetch_assoc();
        }
    }
} catch (Exception $e) {
    error_log("Error fetching contacts: " . $e->getMessage());
    $errorMessage = "An error occurred while loading your contacts.";
}

// Handle sending a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
    $messageText = isset($_POST['message_text']) ? trim($_POST['message_text']) : '';
    $msgBookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : null;
    $msgQuoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : null;
    
    if ($receiverId <= 0) {
        $errorMessage = "Invalid recipient.";
    } elseif (empty($messageText)) {
        $errorMessage = "Message cannot be empty.";
    } else {
        try {
            // Verify the receiver exists and is either a provider or customer
            $receiverQuery = "SELECT id, role FROM users WHERE id = ? AND status = 'active'";
            $stmt = $conn->prepare($receiverQuery);
            $stmt->bind_param('i', $receiverId);
            $stmt->execute();
            $receiverResult = $stmt->get_result();
            
            if ($receiverResult && $receiverResult->num_rows > 0) {
                $receiver = $receiverResult->fetch_assoc();
                
                // Check that customer is messaging provider or vice versa
                if (($isCustomer && $receiver['role'] === 'provider') || 
                    ($isProvider && $receiver['role'] === 'customer')) {
                    
                    // If it's a provider, get their provider_id
                    $currentProviderId = null;
                    if ($isProvider) {
                        $providerIdQuery = "SELECT id FROM providers WHERE user_id = ?";
                        $stmt = $conn->prepare($providerIdQuery);
                        $stmt->bind_param('i', $userId);
                        $stmt->execute();
                        $providerResult = $stmt->get_result();
                        
                        if ($providerResult && $providerResult->num_rows > 0) {
                            $providerRow = $providerResult->fetch_assoc();
                            $currentProviderId = $providerRow['id'];
                        } else {
                            throw new Exception("Provider record not found.");
                        }
                    }
                    
                    // Begin transaction
                    $conn->begin_transaction();
                    
                    // Insert the message
                    $insertQuery = "
                        INSERT INTO messages (sender_id, receiver_id, booking_id, quote_id, message, is_read)
                        VALUES (?, ?, ?, ?, ?, 0)
                    ";
                    
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param('iiiis', $userId, $receiverId, $msgBookingId, $msgQuoteId, $messageText);
                    $stmt->execute();
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    // Redirect to prevent form resubmission
                    header("Location: messages.php?contact=" . $receiverId);
                    exit;
                } else {
                    $errorMessage = "You can only message providers or customers.";
                }
            } else {
                $errorMessage = "The recipient does not exist or is inactive.";
            }
        } catch (Exception $e) {
            // Roll back transaction on error
            if ($conn->inTransaction()) {
                $conn->rollback();
            }
            
            error_log("Error sending message: " . $e->getMessage());
            $errorMessage = "An error occurred while sending your message.";
        }
    }
}

// Get messages for active conversation
if ($activeContact) {
    try {
        $messagesQuery = "
            SELECT 
                m.*,
                CASE WHEN m.sender_id = ? THEN 'sent' ELSE 'received' END as message_type
            FROM messages m
            WHERE (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
        ";
        
        $stmt = $conn->prepare($messagesQuery);
        $contactUserId = $activeContact['user_id'];
        $stmt->bind_param('iiiii', $userId, $userId, $contactUserId, $contactUserId, $userId);
        $stmt->execute();
        $messagesResult = $stmt->get_result();
        
        if ($messagesResult) {
            while ($row = $messagesResult->fetch_assoc()) {
                $messages[] = $row;
            }
            
            // Mark unread messages as read
            if (!empty($messages)) {
                $updateQuery = "
                    UPDATE messages 
                    SET is_read = 1 
                    WHERE sender_id = ? AND receiver_id = ? AND is_read = 0
                ";
                
                $stmt = $conn->prepare($updateQuery);
                $stmt->bind_param('ii', $contactUserId, $userId);
                $stmt->execute();
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching messages: " . $e->getMessage());
        $errorMessage = "An error occurred while loading your messages.";
    }
}

// Check for flash messages
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Close database connection when done
$conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Chat with technicians and customers on FixItNow">
    <title>Messages - FixItNow</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            --header-bg: #1e1e24;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #1e1e24;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --modal-bg: #ffffff;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
            --danger-color: #dc3545;       /* Danger/red color */
            --warning-color: #ffc107;      /* Warning/yellow color */
            --success-color: #28a745;      /* Success/green color */
            
            /* Message-specific colors */
            --sent-message-bg: #7952b3;    /* Purple */
            --sent-message-text: #ffffff;  /* White */
            --received-message-bg: #f1f0f5; /* Light gray */
            --received-message-text: #212529; /* Dark text */
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
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
            
            /* Message-specific colors for dark mode */
            --sent-message-bg: #8a63d2;    /* Brighter purple */
            --sent-message-text: #ffffff;  /* White */
            --received-message-bg: #3a3a45; /* Dark gray */
            --received-message-text: #f8f9fa; /* White text */
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.6rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .logo-text:hover {
            transform: scale(1.03);
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            font-weight: 900;
        }
        
        /* Navigation Styles */
        .nav-button {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 8px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.25s ease;
            font-weight: 500;
            font-size: 0.95rem;
            margin: 0 5px;
            position: relative;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            transform: translateY(-2px);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 12px rgba(121, 82, 179, 0.3);
        }
        
        .nav-button.active:before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 15px;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #fff;
        }
        
        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            padding: 2rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .page-title-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('images/pattern.svg') repeat;
            opacity: 0.1;
            z-index: 1;
        }
        
        .page-title-content {
            position: relative;
            z-index: 2;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            letter-spacing: -0.5px;
        }
        
        .page-description {
            font-size: 1rem;
            opacity: 0.95;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
        }
        
        /* Button Styles */
        .btn {
            transition: all 0.3s ease;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Messages Section */
        .messages-section {
            flex: 1;
            padding: 2rem 0;
            display: flex;
            flex-direction: column;
        }
        
        .messages-container {
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            overflow: hidden;
            height: calc(100vh - 250px);
            min-height: 600px;
            display: flex;
            flex-direction: column;
        }
        
        /* Contacts List */
        .contacts-container {
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
            overflow: hidden;
            height: calc(100vh - 250px);
            min-height: 600px;
        }
        
        .contacts-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .contacts-title {
            font-weight: 700;
            font-size: 1.25rem;
            margin: 0;
        }
        
        .contacts-search {
            position: relative;
            margin-top: 1rem;
        }
        
        .contacts-search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border-radius: 8px;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text-color);
            font-size: 0.95rem;
        }
        
        .contacts-search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        
        .contacts-list {
            height: calc(100% - 140px);
            overflow-y: auto;
            padding: 0;
            margin: 0;
            list-style: none;
        }
        
        .contact-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .contact-item:hover {
            background-color: var(--primary-light);
        }
        
        .contact-item.active {
            background-color: var(--primary-light);
            border-left: 4px solid var(--primary-color);
        }
        
        .contact-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 2px solid var(--border-color);
        }
        
        .contact-info {
            flex: 1;
            min-width: 0; /* Ensures text truncation works */
        }
        
        .contact-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
        }
        
        .verified-badge {
            display: inline-flex;
            margin-left: 0.5rem;
            color: #0d6efd;
            font-size: 0.8rem;
        }
        
        .contact-last-message {
            font-size: 0.85rem;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .contact-meta {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            margin-left: 0.5rem;
        }
        
        .contact-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .contact-unread {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            border-radius: 10px;
            background-color: var(--primary-color);
            color: white;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0 0.35rem;
        }
        
        .no-contacts {
            padding: 2rem;
            text-align: center;
            color: var(--text-muted);
        }
        
        .no-contacts i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Chat Header */
        .chat-header {
            padding: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
        }
        
        .chat-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1rem;
            border: 2px solid var(--border-color);
        }
        
        .chat-contact-info {
            flex: 1;
            min-width: 0;
        }
        
        .chat-contact-name {
            font-weight: 600;
            margin-bottom: 0.1rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
        }
        
        .chat-header-actions {
            display: flex;
            align-items: center;
        }
        
        .chat-action-btn {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-light);
            color: var(--primary-color);
            margin-left: 0.5rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .chat-action-btn:hover {
            background-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        /* Chat Messages */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background-color: var(--bg-color);
        }
        
        .message {
            display: flex;
            max-width: 75%;
        }
        
        .message.sent {
            align-self: flex-end;
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        .message-content {
            padding: 0.75rem 1rem;
            border-radius: 12px;
            position: relative;
            word-break: break-word;
        }
        
        .message.sent .message-content {
            background-color: var(--sent-message-bg);
            color: var(--sent-message-text);
            border-bottom-right-radius: 0;
        }
        
        .message.received .message-content {
            background-color: var(--received-message-bg);
            color: var(--received-message-text);
            border-bottom-left-radius: 0;
        }
        
        .message-time {
            font-size: 0.7rem;
            opacity: 0.8;
            margin-top: 0.25rem;
            text-align: right;
        }
        
        .message-booking-info {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .message-booking-info a {
            font-weight: 600;
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .message-booking-info a:hover {
            text-decoration: underline;
        }
        
        .message-quote-info {
            background-color: var(--accent-light);
            color: var(--accent-color);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .message-quote-info a {
            font-weight: 600;
            color: var(--accent-color);
            text-decoration: none;
        }
        
        .message-quote-info a:hover {
            text-decoration: underline;
        }
        
        .message-date-divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.85rem;
        }
        
        .divider-line {
            flex: 1;
            height: 1px;
            background-color: var(--border-color);
        }
        
        .divider-text {
            padding: 0 1rem;
        }
        
        .no-messages {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--text-muted);
            text-align: center;
            padding: 2rem;
        }
        
        .no-messages i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Chat Input */
        .chat-input {
            padding: 1rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .chat-input-field {
            flex: 1;
            border: 1px solid var(--input-border);
            border-radius: 24px;
            padding: 0.75rem 1.25rem;
            font-size: 0.95rem;
            background-color: var(--input-bg);
            color: var(--text-color);
            resize: none;
            height: 42px;
            max-height: 120px;
            overflow-y: auto;
            transition: height 0.3s ease;
        }
        
        .chat-input-field:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .chat-send-btn {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.3s ease;
        }
        
        .chat-send-btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
        }
        
        .chat-send-btn:disabled {
            background-color: var(--text-muted);
            cursor: not-allowed;
            transform: none;
        }
        
        /* Mobile Adjustments */
        @media (max-width: 992px) {
            .contacts-container {
                margin-bottom: 1.5rem;
                height: 400px;
                min-height: auto;
            }
            
            .messages-container {
                height: calc(100vh - 700px);
                min-height: 500px;
            }
        }
        
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .message {
                max-width: 85%;
            }
        }
        
        /* Footer Styles */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 4rem 0 2rem;
            margin-top: auto;
        }
        
        .footer-logo {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: white;
            margin-bottom: 1rem;
            display: block;
        }
        
        .footer-logo .highlight {
            color: var(--accent-color);
        }
        
        .footer-about {
            margin-bottom: 2rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-title {
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-links a:hover {
            color: white;
            opacity: 1;
        }
        
        .footer-contact {
            margin-bottom: 0.85rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-contact i {
            margin-right: 0.5rem;
            width: 20px;
            text-align: center;
        }
        
        .social-icons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .social-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .social-icon:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
            color: white;
        }
        
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 1.5rem;
            margin-top: 3rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .copyright {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        /* Mobile View Toggle */
        .mobile-view-toggle {
            display: none;
            margin-bottom: 1rem;
        }
        
        @media (max-width: 991px) {
            .mobile-view-toggle {
                display: flex;
            }
            
            .contacts-section, .chat-section {
                display: block;
            }
            
            .hide-on-mobile {
                display: none;
            }
        }
        
        /* Show the toggle buttons on mobile */
        .view-toggle-btn {
            flex: 1;
            text-align: center;
            padding: 0.75rem;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .view-toggle-btn:first-child {
            border-top-left-radius: 8px;
            border-bottom-left-radius: 8px;
        }
        
        .view-toggle-btn:last-child {
            border-top-right-radius: 8px;
            border-bottom-right-radius: 8px;
        }
        
        .view-toggle-btn.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="logo-text">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="d-none d-lg-flex">
                    <a href="index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Authentication Buttons -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="btn btn-outline-light d-lg-none me-2" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <?php if ($loggedIn): ?>
                        <!-- User is logged in -->
                        <?php if ($userRole === 'customer'): ?>
                            <a href="customer/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'provider'): ?>
                            <a href="provider/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'admin'): ?>
                            <a href="admin/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Admin Panel
                            </a>
                        <?php endif; ?>
                        
                        <a href="logout.php" class="btn btn-outline-light ms-2">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    <?php else: ?>
                        <!-- User is not logged in -->
                        <a href="login.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="Register.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="container-fluid d-lg-none mt-3 d-none" id="mobileMenu">
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="find-technician.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
            </div>
        </div>
    </header>
    
    <!-- Page Title Section -->
    <section class="page-title-section">
        <div class="container text-center page-title-content">
            <h1 class="page-title">Messages</h1>
            <p class="page-description">Communicate with <?php echo $isCustomer ? 'technicians' : 'customers'; ?> about your repair services and quotes.</p>
        </div>
    </section>
    
    <!-- Messages Section -->
    <section class="messages-section">
        <div class="container">
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $errorMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $successMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <!-- Mobile View Toggle -->
            <div class="mobile-view-toggle">
                <div class="view-toggle-btn active" data-view="contacts">
                    <i class="fas fa-users me-2"></i> Contacts
                </div>
                <div class="view-toggle-btn" data-view="chat">
                    <i class="fas fa-comment me-2"></i> Chat
                </div>
            </div>
            
            <div class="row">
                <!-- Contacts Section -->
                <div class="col-lg-4 contacts-section">
                    <div class="contacts-container">
                        <div class="contacts-header">
                            <h2 class="contacts-title">Conversations</h2>
                        </div>
                        
                        <div class="contacts-search">
                            <i class="fas fa-search contacts-search-icon"></i>
                            <input type="text" class="contacts-search-input" placeholder="Search contacts..." id="contactSearch">
                        </div>
                        
                        <?php if (!empty($contacts)): ?>
                            <ul class="contacts-list">
                                <?php foreach ($contacts as $contact): ?>
                                    <?php
                                    $contactId = $contact['user_id'];
                                    $contactName = h($contact['first_name'] . ' ' . $contact['last_name']);
                                    $profileImage = !empty($contact['profile_image']) ? 'profile_images/' . h($contact['profile_image']) : 'profile_images/../default.png';
                                    $isActive = $activeContact && $activeContact['user_id'] == $contactId;
                                    $unreadCount = (int)$contact['unread_count'];
                                    
                                    // Format time
                                    $lastMessageTime = '';
                                    if (!empty($contact['last_message_time'])) {
                                        $messageTime = strtotime($contact['last_message_time']);
                                        $now = time();
                                        $diff = $now - $messageTime;
                                        
                                        if ($diff < 60) { // Less than a minute
                                            $lastMessageTime = 'Just now';
                                        } elseif ($diff < 3600) { // Less than an hour
                                            $mins = floor($diff / 60);
                                            $lastMessageTime = $mins . 'm ago';
                                        } elseif ($diff < 86400) { // Less than a day
                                            $hours = floor($diff / 3600);
                                            $lastMessageTime = $hours . 'h ago';
                                        } elseif ($diff < 604800) { // Less than a week
                                            $days = floor($diff / 86400);
                                            $lastMessageTime = $days . 'd ago';
                                        } else { // More than a week
                                            $lastMessageTime = date('M j', $messageTime);
                                        }
                                    }
                                    ?>
                                    <li>
                                        <a href="messages.php?contact=<?php echo $contactId; ?>" class="contact-item <?php echo $isActive ? 'active' : ''; ?>">
                                            <img src="<?php echo $profileImage; ?>" class="contact-avatar" alt="<?php echo $contactName; ?>">
                                            
                                            <div class="contact-info">
                                                <div class="contact-name">
                                                    <?php echo $contactName; ?>
                                                    <?php if ($isCustomer && isset($contact['is_verified']) && (int)$contact['is_verified'] === 1): ?>
                                                        <span class="verified-badge">
                                                            <i class="fas fa-check-circle"></i>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div class="contact-last-message">
                                                    <!-- Last message would be shown here -->
                                                    &nbsp;
                                                </div>
                                            </div>
                                            
                                            <div class="contact-meta">
                                                <?php if (!empty($lastMessageTime)): ?>
                                                    <div class="contact-time"><?php echo $lastMessageTime; ?></div>
                                                <?php endif; ?>
                                                
                                                <?php if ($unreadCount > 0): ?>
                                                    <div class="contact-unread"><?php echo $unreadCount; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="no-contacts">
                                <i class="fas fa-comments"></i>
                                <h3>No conversations yet</h3>
                                <?php if ($isCustomer): ?>
                                    <p>Find a technician and start a conversation.</p>
                                    <a href="find-technician.php" class="btn btn-primary">
                                        <i class="fas fa-search me-2"></i> Find Technician
                                    </a>
                                <?php else: ?>
                                    <p>You haven't received any messages yet.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Chat Section -->
                <div class="col-lg-8 chat-section">
                    <div class="messages-container">
                        <?php if ($activeContact): ?>
                            <!-- Chat Header -->
                            <div class="chat-header">
                                <?php
                                $contactName = h($activeContact['first_name'] . ' ' . $activeContact['last_name']);
                                $profileImage = !empty($activeContact['profile_image']) ? 'profile_images/' . h($activeContact['profile_image']) : 'profile_images/../default.png';
                                ?>
                                <img src="<?php echo $profileImage; ?>" class="chat-avatar" alt="<?php echo $contactName; ?>">
                                
                                <div class="chat-contact-info">
                                    <div class="chat-contact-name">
                                        <?php echo $contactName; ?>
                                        <?php if ($isCustomer && isset($activeContact['is_verified']) && (int)$activeContact['is_verified'] === 1): ?>
                                            <span class="verified-badge">
                                                <i class="fas fa-check-circle"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="chat-header-actions">
                                    <?php if ($isCustomer && isset($activeContact['provider_id'])): ?>
                                        <a href="technician-profile.php?id=<?php echo (int)$activeContact['provider_id']; ?>" class="chat-action-btn" title="View Profile">
                                            <i class="fas fa-user"></i>
                                        </a>
                                        
                                        <a href="book-service.php?provider_id=<?php echo (int)$activeContact['provider_id']; ?>" class="chat-action-btn" title="Book Service">
                                            <i class="fas fa-calendar-check"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Chat Messages -->
                            <div class="chat-messages" id="chatMessages">
                                <?php if (!empty($messages)): ?>
                                    <?php 
                                    $currentDate = null;
                                    foreach ($messages as $message): 
                                        $messageType = $message['message_type'];
                                        $messageTime = strtotime($message['created_at']);
                                        $messageDate = date('Y-m-d', $messageTime);
                                        
                                        // Add date divider if this is a new date
                                        if ($currentDate != $messageDate) {
                                            $displayDate = date('F j, Y', $messageTime);
                                            if ($messageDate == date('Y-m-d')) {
                                                $displayDate = 'Today';
                                            } elseif ($messageDate == date('Y-m-d', strtotime('-1 day'))) {
                                                $displayDate = 'Yesterday';
                                            }
                                            
                                            echo '<div class="message-date-divider">';
                                            echo '<div class="divider-line"></div>';
                                            echo '<div class="divider-text">' . $displayDate . '</div>';
                                            echo '<div class="divider-line"></div>';
                                            echo '</div>';
                                            
                                            $currentDate = $messageDate;
                                        }
                                    ?>
                                        <div class="message <?php echo $messageType; ?>">
                                            <div class="message-content">
                                                <?php 
                                                // Show booking info if this message is related to a booking
                                                if (!empty($message['booking_id'])): 
                                                    $bookingUrl = $isCustomer ? 'customer/bookings.php?id=' . (int)$message['booking_id'] : 'provider/bookings.php?id=' . (int)$message['booking_id'];
                                                ?>
                                                    
                                                <?php endif; ?>
                                                
                                                <?php 
                                                // Show quote info if this message is related to a quote
                                                if (!empty($message['quote_id'])): 
                                                    $quoteUrl = $isCustomer ? 'customer/quotes.php?id=' . (int)$message['quote_id'] : 'provider/quotes.php?id=' . (int)$message['quote_id'];
                                                ?>
                                                    <div class="message-quote-info">
                                                        <i class="fas fa-file-invoice me-1"></i>
                                                        Regarding quote: <a href="<?php echo $quoteUrl; ?>">#<?php echo (int)$message['quote_id']; ?></a>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <?php echo nl2br(h($message['message'])); ?>
                                                <div class="message-time">
                                                    <?php echo date('g:i A', $messageTime); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="no-messages">
                                        <i class="far fa-comment-dots"></i>
                                        <h3>No messages yet</h3>
                                        <p>Start the conversation by sending a message below.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Chat Input -->
                            <form action="messages.php" method="POST" class="chat-input">
                                <input type="hidden" name="receiver_id" value="<?php echo $activeContact['user_id']; ?>">
                                
                                <?php if ($bookingId): ?>
                                    <input type="hidden" name="booking_id" value="<?php echo (int)$bookingId; ?>">
                                <?php endif; ?>
                                
                                <?php if ($quoteId): ?>
                                    <input type="hidden" name="quote_id" value="<?php echo (int)$quoteId; ?>">
                                <?php endif; ?>
                                
                                <textarea class="chat-input-field" name="message_text" placeholder="Type a message..." id="messageInput"></textarea>
                                <button type="submit" name="send_message" class="chat-send-btn" id="sendBtn" disabled>
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </form>
                        <?php else: ?>
                            <!-- No Active Conversation State -->
                            <div class="no-messages">
                                <i class="far fa-comment-dots"></i>
                                <h3>Select a conversation</h3>
                                <p>Choose a conversation from the left to start messaging.</p>
                                
                                <?php if ($isCustomer && empty($contacts)): ?>
                                    <a href="find-technician.php" class="btn btn-primary mt-3">
                                        <i class="fas fa-search me-2"></i> Find Technician
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="footer-logo">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <p class="footer-about">
                        FixItNow is a platform connecting users with expert technicians for all kinds of device repairs. We make device repair easy, reliable, and accessible to everyone.
                    </p>
                    <div class="social-icons">
                        <a href="#" class="social-icon" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="find-technician.php">Find Technician</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="how-it-works.php">How It Works</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Customers</h4>
                    <ul class="footer-links">
                        <li><a href="register.php">Sign Up</a></li>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="book-service.php">Book a Service</a></li>
                        <li><a href="#quoteSection">Request a Quote</a></li>
                        <li><a href="faq.php">FAQ</a></li>
                        <li><a href="support.php">Support</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Technicians</h4>
                    <ul class="footer-links">
                        <li><a href="join-as-provider.php">Join as a Provider</a></li>
                        <li><a href="provider/login.php">Provider Login</a></li>
                        <li><a href="provider-terms.php">Provider Terms</a></li>
                        <li><a href="provider-resources.php">Resources</a></li>
                        <li><a href="provider-faq.php">Provider FAQ</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-12">
                    <h4 class="footer-title">Contact Us</h4>
                    <div class="footer-contact">
                        <i class="fas fa-map-marker-alt"></i> 123 Tech Street, Repair City
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-phone"></i> +1 (555) 123-4567
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-envelope"></i> support@fixitnow.com
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="copyright">
                    &copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.
                </div>
                <div>
                    <a href="privacy.php" class="me-3 text-white-50">Privacy Policy</a>
                    <a href="terms.php" class="text-white-50">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('d-none');
                    
                    // Change icon based on menu state
                    const icon = mobileMenuToggle.querySelector('i');
                    if (mobileMenu.classList.contains('d-none')) {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    } else {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-times');
                    }
                });
            }
            
            // Theme Toggle Functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                htmlElement.setAttribute('data-bs-theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                
                if (currentTheme === 'dark') {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                }
            });
            
            // Mobile View Toggle
            const viewToggleBtns = document.querySelectorAll('.view-toggle-btn');
            const contactsSection = document.querySelector('.contacts-section');
            const chatSection = document.querySelector('.chat-section');
            
            viewToggleBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const view = this.getAttribute('data-view');
                    
                    // Update button states
                    viewToggleBtns.forEach(function(b) {
                        b.classList.remove('active');
                    });
                    this.classList.add('active');
                    
                    // Show/hide sections
                    if (view === 'contacts') {
                        contactsSection.classList.remove('hide-on-mobile');
                        chatSection.classList.add('hide-on-mobile');
                    } else {
                        contactsSection.classList.add('hide-on-mobile');
                        chatSection.classList.remove('hide-on-mobile');
                    }
                });
            });
            
            // Chat functionality
            const messageInput = document.getElementById('messageInput');
            const sendBtn = document.getElementById('sendBtn');
            const chatMessages = document.getElementById('chatMessages');
            
            // Enable/disable send button based on input
            if (messageInput && sendBtn) {
                messageInput.addEventListener('input', function() {
                    sendBtn.disabled = this.value.trim() === '';
                    
                    // Auto-resize textarea
                    this.style.height = 'auto';
                    const newHeight = Math.min(this.scrollHeight, 120);
                    this.style.height = newHeight + 'px';
                });
                
                // Also handle Enter key to send message
                messageInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        if (this.value.trim() !== '') {
                            sendBtn.click();
                        }
                    }
                });
            }
            
            // Scroll to bottom of chat on load
            if (chatMessages) {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }
            
            // Contact search functionality
            const contactSearch = document.getElementById('contactSearch');
            const contactItems = document.querySelectorAll('.contact-item');
            
            if (contactSearch && contactItems.length) {
                contactSearch.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    
                    contactItems.forEach(function(item) {
                        const contactName = item.querySelector('.contact-name').textContent.toLowerCase();
                        
                        if (contactName.includes(searchTerm)) {
                            item.style.display = 'flex';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
            
            // Auto dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    if (typeof bootstrap !== 'undefined') {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    } else {
                        // Fallback if bootstrap JS isn't loaded
                        alert.style.display = 'none';
                    }
                });
            }, 5000);
        });
    </script>
</body>
</html>