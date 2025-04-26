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
    $_SESSION['error_message'] = "Debe iniciar sesión como cliente para aceptar cotizaciones.";
    header('Location: ../login.php?redirect=customer');
    exit;
}

// Check for required parameters
if (!isset($_GET['id']) || empty($_GET['id']) || !isset($_GET['request_id']) || empty($_GET['request_id'])) {
    $_SESSION['error_message'] = "Parámetros inválidos.";
    header('Location: quotes.php');
    exit;
}

// Get and validate parameters
$quoteId = (int)$_GET['id'];
$requestId = (int)$_GET['request_id'];

if ($quoteId <= 0 || $requestId <= 0) {
    $_SESSION['error_message'] = "ID de cotización o solicitud inválido.";
    header('Location: quotes.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

try {
    // Start transaction
    $conn->begin_transaction();
    
    // Verify the request belongs to the current user
    $verifyRequestStmt = $conn->prepare("
        SELECT * FROM quote_requests 
        WHERE id = ? AND customer_id = ? AND status = 'quoted'
    ");
    
    if (!$verifyRequestStmt) {
        throw new Exception("Error preparando la consulta de verificación: " . $conn->error);
    }
    
    $verifyRequestStmt->bind_param("ii", $requestId, $userId);
    
    if (!$verifyRequestStmt->execute()) {
        throw new Exception("Error ejecutando la consulta de verificación: " . $verifyRequestStmt->error);
    }
    
    $requestResult = $verifyRequestStmt->get_result();
    $request = $requestResult->fetch_assoc();
    $verifyRequestStmt->close();
    
    if (!$request) {
        throw new Exception("No se encontró la solicitud o no tienes permiso para aceptar esta cotización.");
    }
    
    // Verify the quote exists and is pending
    $verifyQuoteStmt = $conn->prepare("
        SELECT * FROM quotes 
        WHERE id = ? AND request_id = ? AND status = 'pending'
    ");
    
    if (!$verifyQuoteStmt) {
        throw new Exception("Error preparando la consulta de verificación de cotización: " . $conn->error);
    }
    
    $verifyQuoteStmt->bind_param("ii", $quoteId, $requestId);
    
    if (!$verifyQuoteStmt->execute()) {
        throw new Exception("Error ejecutando la consulta de verificación de cotización: " . $verifyQuoteStmt->error);
    }
    
    $quoteResult = $verifyQuoteStmt->get_result();
    $quote = $quoteResult->fetch_assoc();
    $verifyQuoteStmt->close();
    
    if (!$quote) {
        throw new Exception("La cotización no existe o ya ha sido procesada.");
    }
    
    // Update the selected quote to accepted
    $acceptQuoteStmt = $conn->prepare("
        UPDATE quotes 
        SET status = 'accepted', updated_at = NOW() 
        WHERE id = ?
    ");
    
    if (!$acceptQuoteStmt) {
        throw new Exception("Error preparando la actualización de cotización: " . $conn->error);
    }
    
    $acceptQuoteStmt->bind_param("i", $quoteId);
    
    if (!$acceptQuoteStmt->execute()) {
        throw new Exception("Error al aceptar la cotización: " . $acceptQuoteStmt->error);
    }
    
    $acceptQuoteStmt->close();
    
    // Reject all other quotes for this request
    $rejectOthersStmt = $conn->prepare("
        UPDATE quotes 
        SET status = 'rejected', updated_at = NOW() 
        WHERE request_id = ? AND id != ? AND status = 'pending'
    ");
    
    if (!$rejectOthersStmt) {
        throw new Exception("Error preparando el rechazo de otras cotizaciones: " . $conn->error);
    }
    
    $rejectOthersStmt->bind_param("ii", $requestId, $quoteId);
    
    if (!$rejectOthersStmt->execute()) {
        throw new Exception("Error al rechazar otras cotizaciones: " . $rejectOthersStmt->error);
    }
    
    $rejectOthersStmt->close();
    
    // Update the request status to accepted
    $updateRequestStmt = $conn->prepare("
        UPDATE quote_requests 
        SET status = 'accepted', updated_at = NOW() 
        WHERE id = ?
    ");
    
    if (!$updateRequestStmt) {
        throw new Exception("Error preparando la actualización de solicitud: " . $conn->error);
    }
    
    $updateRequestStmt->bind_param("i", $requestId);
    
    if (!$updateRequestStmt->execute()) {
        throw new Exception("Error al actualizar el estado de la solicitud: " . $updateRequestStmt->error);
    }
    
    $updateRequestStmt->close();
    
    // Create notification for the provider
    if (isset($quote['provider_id']) && !empty($quote['provider_id'])) {
        $createNotifStmt = $conn->prepare("
            INSERT INTO notifications (
                provider_id, type, reference_id, message, status
            ) VALUES (
                ?, 'quote_accepted', ?, 'تم قبول عرض السعر الخاص بك', 'pending'
            )
        ");
        
        if ($createNotifStmt) {
            $createNotifStmt->bind_param("ii", $quote['provider_id'], $quoteId);
            $createNotifStmt->execute();
            $createNotifStmt->close();
        }
    }
    
    // Log status change in quote_status_history if the table exists
    $tableExistsQuery = "SHOW TABLES LIKE 'quote_status_history'";
    $tableExists = $conn->query($tableExistsQuery)->num_rows > 0;
    
    if ($tableExists) {
        $historyStmt = $conn->prepare("
            INSERT INTO quote_status_history (
                request_id, quote_id, status, notes, created_by, user_id
            ) VALUES (
                ?, ?, 'accepted', 'Quote accepted by customer', 'customer', ?
            )
        ");
        
        if ($historyStmt) {
            $historyStmt->bind_param("iii", $requestId, $quoteId, $userId);
            $historyStmt->execute();
            $historyStmt->close();
        }
    }
    
    // Commit transaction if everything is successful
    $conn->commit();
    
    $_SESSION['success_message'] = "Has aceptado correctamente la cotización. Puedes contactar al técnico para coordinar los detalles.";
    header("Location: quote-details.php?id=" . $requestId);
    exit;
    
} catch (Exception $e) {
    // Rollback transaction if an error occurs
    $conn->rollback();
    
    error_log("Error en accept-quote.php: " . $e->getMessage());
    $_SESSION['error_message'] = "Error: " . $e->getMessage();
    header("Location: quote-details.php?id=" . $requestId);
    exit;
} finally {
    // Close connection
    $conn->close();
}
?>