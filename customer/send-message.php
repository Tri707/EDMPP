<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Log the request for debugging
file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - Request: " . print_r($_POST, true) . "\n", FILE_APPEND);

try {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid security token');
    }

    // Authentication check
    $loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if (!$loggedIn || $userId <= 0) {
        throw new Exception('Authentication required');
    }

    // Log user info
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - User ID: $userId\n", FILE_APPEND);

    // Validate inputs
    $bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
    $providerId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
    $message = isset($_POST['message']) ? trim($_POST['message']) : '';

    if ($bookingId <= 0 || $providerId <= 0 || empty($message)) {
        throw new Exception('Invalid input parameters');
    }

    // Database connection
    include 'conn.php';
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Log connection success
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - DB Connection successful\n", FILE_APPEND);

    // Verify booking belongs to this user
    $checkStmt = $conn->prepare("SELECT id FROM bookings WHERE id = ? AND customer_id = ?");
    if (!$checkStmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    $checkStmt->bind_param("ii", $bookingId, $userId);
    if (!$checkStmt->execute()) {
        throw new Exception('Execute failed: ' . $checkStmt->error);
    }
    
    $result = $checkStmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Booking not found or access denied');
    }
    
    // Log booking check success
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - Booking check passed\n", FILE_APPEND);
    
    // Get provider's user_id from providers table
    $userStmt = $conn->prepare("SELECT user_id FROM providers WHERE id = ?");
    if (!$userStmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    $userStmt->bind_param("i", $providerId);
    if (!$userStmt->execute()) {
        throw new Exception('Execute failed: ' . $userStmt->error);
    }
    
    $userResult = $userStmt->get_result();
    $provider = $userResult->fetch_assoc();
    
    if (!$provider) {
        throw new Exception('Provider not found');
    }
    
    $providerUserId = $provider['user_id'];
    
    // Log provider info
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - Provider User ID: $providerUserId\n", FILE_APPEND);
    
    // Insert message
    $stmt = $conn->prepare("
        INSERT INTO messages (sender_id, receiver_id, booking_id, message, is_read) 
        VALUES (?, ?, ?, ?, 0)
    ");
    if (!$stmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    $stmt->bind_param("iiis", $userId, $providerUserId, $bookingId, $message);
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - Message inserted successfully\n", FILE_APPEND);
    
    echo json_encode(['status' => 'success', 'message' => 'Message sent successfully']);
    
} catch (Exception $e) {
    // Log the error
    file_put_contents('message_send_debug.log', date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>