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
file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - Request: " . print_r($_GET, true) . "\n", FILE_APPEND);

try {
    // Validate CSRF token
    if (!isset($_GET['csrf_token']) || $_GET['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid security token');
    }

    // Authentication check
    $loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

    if (!$loggedIn || $userId <= 0) {
        throw new Exception('Authentication required');
    }

    // Log user info
    file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - User ID: $userId\n", FILE_APPEND);

    // Validate inputs
    $bookingId = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;

    if ($bookingId <= 0) {
        throw new Exception('Invalid booking ID');
    }

    // Database connection
    include 'conn.php';
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Log connection success
    file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - DB Connection successful\n", FILE_APPEND);

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
    file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - Booking check passed\n", FILE_APPEND);
    
    // Get messages for this booking
    $stmt = $conn->prepare("
        SELECT id, sender_id, receiver_id, booking_id, message, is_read, created_at
        FROM messages
        WHERE booking_id = ? AND (sender_id = ? OR receiver_id = ?)
        ORDER BY created_at ASC
    ");
    if (!$stmt) {
        throw new Exception('Prepare statement failed: ' . $conn->error);
    }
    
    $stmt->bind_param("iii", $bookingId, $userId, $userId);
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $messages[] = $row;
    }
    
    // Log message count
    file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - Found " . count($messages) . " messages\n", FILE_APPEND);
    
    // Update read status for messages sent to this user
    $updateStmt = $conn->prepare("
        UPDATE messages 
        SET is_read = 1
        WHERE booking_id = ? AND receiver_id = ? AND is_read = 0
    ");
    if ($updateStmt) {
        $updateStmt->bind_param("ii", $bookingId, $userId);
        $updateStmt->execute();
    }
    
    echo json_encode(['status' => 'success', 'messages' => $messages]);
    
} catch (Exception $e) {
    // Log the error
    file_put_contents('message_debug.log', date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

if (isset($conn)) {
    $conn->close();
}
?>