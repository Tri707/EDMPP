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

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header('Location: bookings.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$bookingId = isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0;
$providerId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
$rating = isset($_POST['rating']) ? (float)$_POST['rating'] : 0;
$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';

// Validation
if ($bookingId <= 0 || $providerId <= 0) {
    $_SESSION['error_message'] = "Invalid booking or provider information.";
    header('Location: bookings.php');
    exit;
}

if ($rating <= 0 || $rating > 5) {
    $_SESSION['error_message'] = "Please provide a valid rating between 1 and 5 stars.";
    header('Location: bookings.php');
    exit;
}

try {
    // Verify that the booking belongs to this customer and is completed
    $stmt = $conn->prepare("
        SELECT b.id, b.status 
        FROM bookings b 
        WHERE b.id = ? AND b.customer_id = ? AND b.provider_id = ?
    ");
    $stmt->bind_param("iii", $bookingId, $userId, $providerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $booking = $result->fetch_assoc();
    $stmt->close();
    
    if (!$booking) {
        $_SESSION['error_message'] = "The booking does not exist or does not belong to you.";
        header('Location: bookings.php');
        exit;
    }
    
    if ($booking['status'] !== 'completed') {
        $_SESSION['error_message'] = "You can only review completed bookings.";
        header('Location: bookings.php');
        exit;
    }
    
    // Check if a review already exists for this booking
    $stmt = $conn->prepare("SELECT id FROM reviews WHERE booking_id = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingReview = $result->fetch_assoc();
    $stmt->close();
    
    if ($existingReview) {
        // Review already exists, don't allow updates
        $_SESSION['error_message'] = "You have already reviewed this service. Only one review is allowed per booking.";
        header('Location: bookings.php');
        exit;
    } else {
        // Insert a new review
        $stmt = $conn->prepare("
            INSERT INTO reviews 
            (booking_id, customer_id, provider_id, rating, comment, is_published) 
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->bind_param("iiids", $bookingId, $userId, $providerId, $rating, $comment);
        $success = $stmt->execute();
        $stmt->close();
        
        if ($success) {
            // Add review notification for the provider
            $notificationMessage = "You have received a new review from a customer.";
            
            $stmt = $conn->prepare("
                INSERT INTO notifications 
                (provider_id, type, reference_id, message)
                VALUES (?, 'review', ?, ?)
            ");
            $stmt->bind_param("iis", $providerId, $bookingId, $notificationMessage);
            $stmt->execute();
            $stmt->close();
            
            $_SESSION['success_message'] = "Thank you! Your review has been submitted successfully.";
        } else {
            $_SESSION['error_message'] = "Failed to submit your review. Please try again.";
        }
    }
    
} catch (Exception $e) {
    error_log("Database error in submit-review.php: " . $e->getMessage());
    $_SESSION['error_message'] = "An error occurred while processing your review. Please try again later.";
}

// Close database connection
$conn->close();

// Redirect back to bookings page
header('Location: bookings.php');
exit;
?>