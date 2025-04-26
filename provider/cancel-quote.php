<?php
session_start();
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

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: my-quotes.php");
    exit();
}

// Get quote ID from POST data
$quoteId = isset($_POST['quote_id']) ? (int)$_POST['quote_id'] : 0;
if ($quoteId <= 0) {
    $_SESSION['error_message'] = 'Invalid quote ID.';
    header("Location: my-quotes.php");
    exit();
}

// Get provider information
$providerData = null;
try {
    // Get provider data
    $providerQuery = "SELECT * FROM providers WHERE user_id = ?";
    $stmt = $pdo->prepare($providerQuery);
    $stmt->execute([$userId]);
    $providerData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$providerData) {
        $_SESSION['error_message'] = 'Provider information not found.';
        header("Location: my-quotes.php");
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error fetching provider data: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while fetching provider data.';
    header("Location: my-quotes.php");
    exit();
}

// Process quote cancellation
try {
    // Start transaction
    $pdo->beginTransaction();
    
    // First check if the quote exists and belongs to this provider
    $quoteQuery = "
        SELECT q.*, qr.customer_id 
        FROM quotes q
        JOIN quote_requests qr ON q.request_id = qr.id
        WHERE q.id = ? AND q.technician_id = ?
    ";
    $stmt = $pdo->prepare($quoteQuery);
    $stmt->execute([$quoteId, $providerData['id']]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quote) {
        throw new Exception('Quote not found or does not belong to you.');
    }
    
    // Check if the quote status allows cancellation (must be pending)
    if ($quote['status'] !== 'pending') {
        throw new Exception('This quote cannot be cancelled because it is no longer pending.');
    }
    
    // Update the quote status
    $updateQuery = "UPDATE quotes SET status = 'rejected' WHERE id = ?";
    $stmt = $pdo->prepare($updateQuery);
    $stmt->execute([$quoteId]);
    
    // Update the quote request status to allow new quotes
    $updateRequestQuery = "
        UPDATE quote_requests 
        SET status = 'pending' 
        WHERE id = ?
    ";
    $stmt = $pdo->prepare($updateRequestQuery);
    $stmt->execute([$quote['request_id']]);
    
    // Create notification for customer
    $notifQuery = "
        INSERT INTO notifications (customer_id, provider_id, type, reference_id, message, status)
        VALUES (?, ?, 'quote_cancelled', ?, ?, 'pending')
    ";
    $stmt = $pdo->prepare($notifQuery);
    $stmt->execute([
        $quote['customer_id'], 
        $providerData['id'], 
        $quoteId, 
        'A provider has cancelled their quote for your repair request.'
    ]);
    
    // Log the action
    $logQuery = "
        INSERT INTO notification_logs (action, error_message)
        VALUES ('quote_cancelled', ?)
    ";
    $stmt = $pdo->prepare($logQuery);
    $stmt->execute(['Quote #' . $quoteId . ' cancelled by provider #' . $providerData['id']]);
    
    // Commit transaction
    $pdo->commit();
    
    // Set success message
    $_SESSION['success_message'] = 'Quote has been successfully cancelled.';
    
    // Redirect back to quotes page
    header("Location: my-quotes.php");
    exit();
    
} catch (Exception $e) {
    // Rollback transaction
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Error cancelling quote: " . $e->getMessage());
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: my-quotes.php");
    exit();
}
?>