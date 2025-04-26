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
    $bookingData = [];
    $bookingHistory = [];
    $bookingMessages = [];
    $provider = [];
    $service = [];
    $notificationCount = 0;
    $errorMessage = '';
    $successMessage = '';
    $userInitials = 'CN'; // Default initials
    $bookingId = 0;
    $canCancel = false;
    $canReview = false;
    $hasReviewed = false;
    $reviewData = [];

    // Get booking ID from URL with validation
    if (isset($_GET['id']) && is_numeric($_GET['id'])) {
        $bookingId = (int)$_GET['id'];
    } else {
        // Redirect if no valid booking ID
        $_SESSION['error_message'] = "Invalid booking ID. Please select a valid booking.";
        header('Location: bookings.php');
        exit;
    }

    // Process form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle message submissions
        if (isset($_POST['action']) && $_POST['action'] === 'send_message') {
            $message = trim($_POST['message'] ?? '');
            $receiverId = isset($_POST['receiver_id']) ? (int)$_POST['receiver_id'] : 0;
            
            if (!empty($message) && $receiverId > 0) {
                try {
                    // Insert the message
                    $msgStmt = $conn->prepare("
                        INSERT INTO messages (sender_id, receiver_id, booking_id, message, is_read, created_at)
                        VALUES (?, ?, ?, ?, 0, NOW())
                    ");
                    $msgStmt->bind_param("iiis", $userId, $receiverId, $bookingId, $message);
                    
                    if ($msgStmt->execute()) {
                        $successMessage = "Message sent successfully.";
                    } else {
                        $errorMessage = "Failed to send message. Please try again.";
                    }
                    $msgStmt->close();
                } catch (Exception $e) {
                    error_log("Error sending message: " . $e->getMessage());
                    $errorMessage = "An error occurred while sending the message.";
                }
            } else {
                $errorMessage = "Message cannot be empty.";
            }
        }
        
        // Handle cancel booking
        elseif (isset($_POST['action']) && $_POST['action'] === 'cancel_booking') {
            $cancellationReason = trim($_POST['cancel_reason'] ?? '');
            
            try {
                // First check if booking status is 'pending'
                $checkStmt = $conn->prepare("
                    SELECT status FROM bookings 
                    WHERE id = ? AND customer_id = ? AND status = 'pending'
                ");
                $checkStmt->bind_param("ii", $bookingId, $userId);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkStmt->close();
                
                if ($checkResult->num_rows > 0) {
                    // Update booking status
                    $updateStmt = $conn->prepare("
                        UPDATE bookings 
                        SET status = 'cancelled' 
                        WHERE id = ? AND customer_id = ?
                    ");
                    $updateStmt->bind_param("ii", $bookingId, $userId);
                    
                    if ($updateStmt->execute()) {
                        // Add to booking history
                        $historyStmt = $conn->prepare("
                            INSERT INTO booking_status_history 
                            (booking_id, status, created_by, user_id, notes)
                            VALUES (?, 'cancelled', 'customer', ?, ?)
                        ");
                        $historyStmt->bind_param("iis", $bookingId, $userId, $cancellationReason);
                        $historyStmt->execute();
                        $historyStmt->close();
                        
                        $successMessage = "Booking cancelled successfully.";
                        
                        // Update booking status in the current data for display
                        $bookingData['status'] = 'cancelled';
                        $canCancel = false;
                        
                        // Refresh booking history
                        $historyQuery = "
                            SELECT bsh.*, 
                                CASE 
                                    WHEN bsh.created_by = 'customer' THEN CONCAT('You (', cu.first_name, ' ', cu.last_name, ')')
                                    WHEN bsh.created_by = 'provider' THEN CONCAT('Provider (', pu.first_name, ' ', pu.last_name, ')')
                                    WHEN bsh.created_by = 'admin' THEN CONCAT('Admin (', au.first_name, ' ', au.last_name, ')')
                                    ELSE bsh.created_by
                                END as actor_name
                            FROM booking_status_history bsh
                            LEFT JOIN users cu ON bsh.user_id = cu.id AND bsh.created_by = 'customer'
                            LEFT JOIN users pu ON bsh.user_id = pu.id AND bsh.created_by = 'provider'
                            LEFT JOIN users au ON bsh.user_id = au.id AND bsh.created_by = 'admin'
                            WHERE bsh.booking_id = ?
                            ORDER BY bsh.created_at DESC
                        ";
                        
                        $bookingHistory = [];
                        $historyStmt = $conn->prepare($historyQuery);
                        $historyStmt->bind_param("i", $bookingId);
                        $historyStmt->execute();
                        $historyResult = $historyStmt->get_result();
                        
                        while ($row = $historyResult->fetch_assoc()) {
                            $bookingHistory[] = $row;
                        }
                        $historyStmt->close();
                    } else {
                        $errorMessage = "Failed to cancel booking. Please try again.";
                    }
                    $updateStmt->close();
                } else {
                    $errorMessage = "This booking cannot be cancelled. It may have already been processed.";
                }
            } catch (Exception $e) {
                error_log("Error cancelling booking: " . $e->getMessage());
                $errorMessage = "An error occurred while cancelling the booking.";
            }
        }
        
        // Handle review submission
        elseif (isset($_POST['action']) && $_POST['action'] === 'submit_review') {
            $rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
            $comment = trim($_POST['comment'] ?? '');
            
            if ($rating < 1 || $rating > 5) {
                $errorMessage = "Please provide a valid rating (1-5).";
            } else {
                try {
                    // Check if booking is completed and not already reviewed
                    $checkStmt = $conn->prepare("
                        SELECT b.status 
                        FROM bookings b
                        LEFT JOIN reviews r ON b.id = r.booking_id AND r.customer_id = ?
                        WHERE b.id = ? AND b.customer_id = ? AND b.status = 'completed' AND r.id IS NULL
                    ");
                    $checkStmt->bind_param("iii", $userId, $bookingId, $userId);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    $checkStmt->close();
                    
                    if ($checkResult->num_rows > 0) {
                        // Insert review
                        $reviewStmt = $conn->prepare("
                            INSERT INTO reviews 
                            (booking_id, customer_id, provider_id, rating, comment, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $reviewStmt->bind_param("iiids", $bookingId, $userId, $bookingData['provider_id'], $rating, $comment);
                        
                        if ($reviewStmt->execute()) {
                            $successMessage = "Review submitted successfully. Thank you for your feedback!";
                            
                            // Update local variables to show the review
                            $hasReviewed = true;
                            $reviewData = [
                                'rating' => $rating,
                                'comment' => $comment,
                                'created_at' => date('Y-m-d H:i:s')
                            ];
                        } else {
                            $errorMessage = "Failed to submit review. Please try again.";
                        }
                        $reviewStmt->close();
                    } else {
                        $errorMessage = "This booking cannot be reviewed or has already been reviewed.";
                    }
                } catch (Exception $e) {
                    error_log("Error submitting review: " . $e->getMessage());
                    $errorMessage = "An error occurred while submitting the review.";
                }
            }
        }
    }

    try {
        // Common query parts for reusability
        $userQueryBase = "SELECT * FROM users WHERE id = ? AND role = ?";
        $bookingQueryBase = "
            SELECT b.*, 
                p.id as provider_id,
                p.specialties as provider_specialties,
                p.experience as provider_experience,
                p.bio as provider_bio,
                p.location as provider_location,
                p.hourly_rate as provider_rate,
                p.is_verified as provider_verified,
                u.id as technician_user_id,
                u.first_name as provider_first_name, 
                u.last_name as provider_last_name,
                u.phone as provider_phone,
                u.email as provider_email,
                u.profile_image as provider_image,
                s.id as service_id,
                s.name as service_name,
                s.description as service_description,
                s.price as service_price,
                s.duration as service_duration,
                s.category as service_category
            FROM bookings b
            LEFT JOIN providers p ON b.provider_id = p.id
            LEFT JOIN users u ON p.user_id = u.id
            LEFT JOIN services s ON b.service_id = s.id
            WHERE b.id = ?
        ";

        // Query to get customer user data
        $stmt = $conn->prepare($userQueryBase);
        $role = 'customer';
        $stmt->bind_param("is", $userId, $role);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        $stmt->close();
        
        if (!$userData) {
            // If customer data doesn't exist, log them out
            session_unset();
            session_destroy();
            header('Location: ../login.php?error=invalid_session');
            exit;
        }
        
        // Set profile image path with proper validation
        $userProfileImage = processProfileImage($userData['profile_image'] ?? null);
        
        // Get user initials for avatar
        $userInitials = generateInitials($userData['first_name'] ?? '', $userData['last_name'] ?? '');
        
        // Query to get booking details with service and provider information
        $bookingStmt = $conn->prepare($bookingQueryBase . " AND b.customer_id = ?");
        $bookingStmt->bind_param("ii", $bookingId, $userId);
        $bookingStmt->execute();
        $bookingResult = $bookingStmt->get_result();
        
        if ($bookingResult->num_rows === 0) {
            // Booking not found or not belonging to this customer
            $_SESSION['error_message'] = "Booking not found or you don't have permission to view it.";
            header('Location: bookings.php');
            exit;
        }
        
        $bookingData = $bookingResult->fetch_assoc();
        $bookingStmt->close();
        
        // Extract provider and service data for easier access
        $provider = extractProviderData($bookingData);
        $service = extractServiceData($bookingData);
        
        // Set provider initials if no profile image
        $providerInitials = generateInitials($provider['first_name'] ?? '', $provider['last_name'] ?? '');
        
        // Check if booking can be cancelled (only pending bookings can be cancelled)
        $canCancel = ($bookingData['status'] === 'pending');
        
        // Check if booking can be reviewed (only completed bookings that haven't been reviewed yet)
        $canReview = ($bookingData['status'] === 'completed');
        
        // Check if user has already reviewed this booking
        $reviewQuery = "
            SELECT * FROM reviews 
            WHERE booking_id = ? AND customer_id = ? AND provider_id = ?
        ";
        
        $reviewStmt = $conn->prepare($reviewQuery);
        $reviewStmt->bind_param("iii", $bookingId, $userId, $bookingData['provider_id']);
        $reviewStmt->execute();
        $reviewResult = $reviewStmt->get_result();
        
        $hasReviewed = ($reviewResult->num_rows > 0);
        $reviewData = $hasReviewed ? $reviewResult->fetch_assoc() : [];
        $reviewStmt->close();
        
        // Get booking status history with a more efficient join
        $historyQuery = "
            SELECT bsh.*, 
                CASE 
                    WHEN bsh.created_by = 'customer' THEN CONCAT('You (', cu.first_name, ' ', cu.last_name, ')')
                    WHEN bsh.created_by = 'provider' THEN CONCAT('Provider (', pu.first_name, ' ', pu.last_name, ')')
                    WHEN bsh.created_by = 'admin' THEN CONCAT('Admin (', au.first_name, ' ', au.last_name, ')')
                    ELSE bsh.created_by
                END as actor_name
            FROM booking_status_history bsh
            LEFT JOIN users cu ON bsh.user_id = cu.id AND bsh.created_by = 'customer'
            LEFT JOIN users pu ON bsh.user_id = pu.id AND bsh.created_by = 'provider'
            LEFT JOIN users au ON bsh.user_id = au.id AND bsh.created_by = 'admin'
            WHERE bsh.booking_id = ?
            ORDER BY bsh.created_at DESC
        ";
        
        $historyStmt = $conn->prepare($historyQuery);
        $historyStmt->bind_param("i", $bookingId);
        $historyStmt->execute();
        $historyResult = $historyStmt->get_result();
        
        $bookingHistory = [];
        while ($row = $historyResult->fetch_assoc()) {
            $bookingHistory[] = $row;
        }
        $historyStmt->close();
        
        // Get messages related to this booking with a single efficient query
        $messagesQuery = "
            SELECT m.*, 
                s.first_name as sender_first_name,
                s.last_name as sender_last_name,
                r.first_name as receiver_first_name,
                r.last_name as receiver_last_name
            FROM messages m
            JOIN users s ON m.sender_id = s.id
            JOIN users r ON m.receiver_id = r.id
            WHERE m.booking_id = ? 
            ORDER BY m.created_at ASC
        ";
        
        $messagesStmt = $conn->prepare($messagesQuery);
        $messagesStmt->bind_param("i", $bookingId);
        $messagesStmt->execute();
        $messagesResult = $messagesStmt->get_result();
        
        $bookingMessages = [];
        while ($row = $messagesResult->fetch_assoc()) {
            $bookingMessages[] = $row;
        }
        $messagesStmt->close();
        
        // Get unread notifications count in one query
        $notificationData = getNotificationData($conn, $userId);
        $notificationCount = $notificationData['count'];
        $recentNotifications = $notificationData['recent'];
        
    } catch (Exception $e) {
        error_log("Database error in booking-details.php: " . $e->getMessage());
        $errorMessage = "An error occurred while fetching booking details. Please try again later.";
    }

    // Function to safely format dates
    function formatDate($dateString, $format = 'M d, Y') {
        if (empty($dateString)) return 'N/A';
        
        try {
            $date = new DateTime($dateString);
            return $date->format($format);
        } catch (Exception $e) {
            return 'Invalid Date';
        }
    }

    // Function to safely format times
    function formatTime($timeString, $format = 'h:i A') {
        if (empty($timeString)) return 'N/A';
        
        try {
            $date = new DateTime($timeString);
            return $date->format($format);
        } catch (Exception $e) {
            return 'Invalid Time';
        }
    }

    // Function to safely format prices with the currency image
    function formatPrice($price, $currencyImgPath = '../sar/sar.png') {
        if (empty($price) || !is_numeric($price)) return 'Not set';
        
        // Use the image path for currency display
        $currencyImg = '<img src="' . htmlspecialchars($currencyImgPath, ENT_QUOTES, 'UTF-8') . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
        
        return $currencyImg . ' ' . number_format((float)$price, 2);
    }

    // Function to get readable time from timestamp
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

    // Function to get booking status badge HTML
    function getStatusBadge($status) {
        $statusClass = '';
        $statusIcon = '';
        
        switch ($status) {
            case 'pending':
                $statusClass = 'warning';
                $statusIcon = 'clock';
                break;
            case 'confirmed':
                $statusClass = 'primary';
                $statusIcon = 'check-circle';
                break;
            case 'completed':
                $statusClass = 'success';
                $statusIcon = 'check-double';
                break;
            case 'cancelled':
                $statusClass = 'danger';
                $statusIcon = 'times-circle';
                break;
            default:
                $statusClass = 'secondary';
                $statusIcon = 'circle';
        }
        
        return '<span class="status-badge ' . $status . '">
                <i class="fas fa-' . $statusIcon . ' me-1"></i>' . 
                ucfirst($status) . 
                '</span>';
    }

    // Function to process and validate profile images
    function processProfileImage($imagePath, $defaultImage = '../default.png') {
        if (empty($imagePath)) {
            return $defaultImage;
        }
        
        if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
            // URL-based image with validation
            return $imagePath;
        } else {
            // File-based image with protection against directory traversal
            $imageFile = basename($imagePath);
            $imagePath = '../profile_images/' . $imageFile;
            if (file_exists($imagePath) && is_file($imagePath)) {
                return $imagePath;
            }
        }
        
        return $defaultImage;
    }

    // Function to generate initials from names
    function generateInitials($firstName = '', $lastName = '') {
        if (!empty($firstName) && !empty($lastName)) {
            return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
        } elseif (!empty($firstName)) {
            return strtoupper(substr($firstName, 0, 2));
        } elseif (!empty($lastName)) {
            return strtoupper(substr($lastName, 0, 2));
        }
        return 'NA';
    }

    // Function to extract provider data from booking data
    function extractProviderData($bookingData) {
        return [
            'id' => $bookingData['provider_id'] ?? null,
            'user_id' => $bookingData['technician_user_id'] ?? null,
            'first_name' => $bookingData['provider_first_name'] ?? '',
            'last_name' => $bookingData['provider_last_name'] ?? '',
            'phone' => $bookingData['provider_phone'] ?? '',
            'email' => $bookingData['provider_email'] ?? '',
            'image' => $bookingData['provider_image'] ?? '',
            'specialties' => $bookingData['provider_specialties'] ?? '',
            'experience' => $bookingData['provider_experience'] ?? '',
            'bio' => $bookingData['provider_bio'] ?? '',
            'location' => $bookingData['provider_location'] ?? '',
            'hourly_rate' => $bookingData['provider_rate'] ?? 0,
            'is_verified' => $bookingData['provider_verified'] ?? 0
        ];
    }

    // Function to extract service data from booking data
    function extractServiceData($bookingData) {
        return [
            'id' => $bookingData['service_id'] ?? null,
            'name' => $bookingData['service_name'] ?? '',
            'description' => $bookingData['service_description'] ?? '',
            'price' => $bookingData['service_price'] ?? 0,
            'duration' => $bookingData['service_duration'] ?? 0,
            'category' => $bookingData['service_category'] ?? ''
        ];
    }

    // Function to get notification data (count and recent notifications)
    function getNotificationData($conn, $userId) {
        $data = [
            'count' => 0,
            'recent' => []
        ];
        
        try {
            // Get unread notifications count
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM quote_notifications
                WHERE recipient_id = ? AND is_read = 0
            ");
            $countStmt->bind_param("i", $userId);
            $countStmt->execute();
            $data['count'] = $countStmt->get_result()->fetch_assoc()['count'];
            $countStmt->close();
            
            // Get recent notifications
            $recentStmt = $conn->prepare("
                SELECT * FROM quote_notifications 
                WHERE recipient_id = ? 
                ORDER BY created_at DESC 
                LIMIT 3
            ");
            $recentStmt->bind_param("i", $userId);
            $recentStmt->execute();
            $result = $recentStmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $data['recent'][] = $row;
            }
            $recentStmt->close();
        } catch (Exception $e) {
            error_log("Error fetching notification data: " . $e->getMessage());
        }
        
        return $data;
    }

    // Function to safely output text
    function safeText($text, $default = '') {
        return !empty($text) ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : $default;
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
    <meta name="description" content="View your booking details with FixItNow">
    <meta name="robots" content="noindex, nofollow">
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
        
        .header-icon-btn:active {
            transform: translateY(0);
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
        
        /* Back button */
        .back-button {
            display: inline-flex;
            align-items: center;
            margin-right: 1rem;
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .back-button:hover {
            color: var(--primary-color);
            transform: translateX(-3px);
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
            background-color: rgba(0, 0, 0, 0.03);
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            font-weight: 600;
        }
        
        .card-footer {
            background-color: rgba(0, 0, 0, 0.03);
            border-top: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
        }
        
        /* Booking details specific styles */
        .booking-header {
            background-color: var(--primary-light);
            color: var(--primary-color);
            padding: 1.5rem;
            border-radius: 0.75rem 0.75rem 0 0;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .booking-id {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .booking-date {
            display: flex;
            align-items: center;
            font-weight: 600;
            background-color: rgba(255, 255, 255, 0.5);
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
        }
        
        .booking-date i {
            margin-right: 0.5rem;
        }
        
        /* Status badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .status-badge.pending {
            background-color: rgba(var(--bs-warning-rgb), 0.15);
            color: var(--warning-color);
        }
        
        .status-badge.confirmed {
            background-color: rgba(var(--bs-primary-rgb), 0.15);
            color: var(--primary-color);
        }
        
        .status-badge.completed {
            background-color: rgba(var(--bs-success-rgb), 0.15);
            color: var(--success-color);
        }
        
        .status-badge.cancelled {
            background-color: rgba(var(--bs-danger-rgb), 0.15);
            color: var(--danger-color);
        }
        
        /* Service details */
        .service-details {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .service-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        
        .service-description {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .service-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
        }
        
        .meta-item {
            display: flex;
            flex-direction: column;
        }
        
        .meta-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .meta-value {
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        /* Provider details */
        .provider-details {
            padding: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .provider-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            overflow: hidden;
            border: 3px solid var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-light);
            color: var(--primary-color);
            font-size: 2rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        
        .provider-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .provider-info {
            flex: 1;
            min-width: 250px;
        }
        
        .provider-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .provider-contact {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        
        .provider-bio {
            color: var(--text-muted);
            margin-top: 0.75rem;
        }
        
        .contact-item {
            display: flex;
            align-items: center;
        }
        
        .contact-item i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        /* Notes section */
        .booking-notes {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .notes-content {
            background-color: rgba(0, 0, 0, 0.03);
            padding: 1rem;
            border-radius: 0.5rem;
            margin-top: 0.75rem;
        }
        
        /* Payment info */
        .payment-info {
            padding: 1.5rem;
        }
        
        .payment-status {
            display: inline-flex;
            align-items: center;
            padding: 0.4rem 1rem;
            border-radius: 2rem;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .payment-status.unpaid {
            background-color: rgba(var(--bs-warning-rgb), 0.15);
            color: var(--warning-color);
        }
        
        .payment-status.paid {
            background-color: rgba(var(--bs-success-rgb), 0.15);
            color: var(--success-color);
        }
        
        .payment-status.refunded {
            background-color: rgba(var(--bs-secondary-rgb), 0.15);
            color: var(--text-muted);
        }
        
        .payment-details {
            display: flex;
            flex-wrap: wrap;
            gap: 2rem;
        }
        
        .total-amount {
            font-size: 1.25rem;
            font-weight: 700;
            display: flex;
            align-items: center;
        }
        
        .total-amount img {
            margin-right: 0.5rem;
        }
        
        /* Actions section */
        .booking-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            justify-content: flex-end;
        }
        
        /* History timeline */
        .history-section {
            padding: 1.5rem;
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
            margin-top: 1.5rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 9px;
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
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2rem;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--primary-color);
            z-index: 1;
        }
        
        .timeline-content {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            padding: 1rem;
            box-shadow: 0 2px 5px var(--shadow-color);
        }
        
        .timeline-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .timeline-title {
            font-weight: 600;
        }
        
        .timeline-date {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .timeline-body {
            color: var(--text-muted);
        }
        
        /* Messages section */
        .messages-section {
            padding: 1.5rem;
        }
        
        .messages-container {
            max-height: 400px;
            overflow-y: auto;
            margin: 1.5rem 0;
            padding-right: 0.5rem;
        }
        
        .message {
            display: flex;
            margin-bottom: 1.5rem;
        }
        
        .message.outgoing {
            justify-content: flex-end;
        }
        
        .message-content {
            max-width: 70%;
            padding: 1rem;
            border-radius: 1rem;
            position: relative;
        }
        
        .message.incoming .message-content {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-top-left-radius: 0;
        }
        
        .message.outgoing .message-content {
            background-color: var(--primary-light);
            color: var(--primary-color);
            border-top-right-radius: 0;
        }
        
        .message-sender {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .message-text {
            margin-bottom: 0.25rem;
        }
        
        .message-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: right;
        }
        
        .message-form {
            display: flex;
            gap: 0.75rem;
        }
        
        .message-form .form-control {
            border-radius: 2rem;
            padding-left: 1.25rem;
        }
        
        /* Review section */
        .review-section {
            padding: 1.5rem;
        }
        
        .review-form {
            margin-top: 1rem;
        }
        
        .rating-stars {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .rating-stars .form-check {
            margin: 0;
        }
        
        .star-label {
            font-size: 1.5rem;
            color: var(--text-muted);
            cursor: pointer;
            transition: color 0.2s ease;
        }
        
        .star-input:checked ~ .star-label {
            color: var(--warning-color);
        }
        
        .review-card {
            border: 1px solid var(--border-color);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            align-items: center;
        }
        
        .review-stars {
            color: var(--warning-color);
        }
        
        .review-date {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .service-meta {
                grid-template-columns: 1fr;
            }
            
            .provider-details {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .provider-info {
                width: 100%;
            }
            
            .provider-contact {
                justify-content: center;
            }
            
            .message-content {
                max-width: 85%;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header" style="background-color: #212529; padding: 12px 0;">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="../index.php" class="logo-text text-decoration-none">
                    <span style="color: #fff; font-weight: 900; font-size: 1.5rem;">
                        <i class="fas fa-tools me-2" aria-hidden="true"></i>FIX<span style="color: #4cd963;">IT</span>NOW
                    </span>
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="main-nav d-none d-lg-flex">
                    <a href="../index.php" class="nav-button text-decoration-none">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="../find-technician.php" class="nav-button text-decoration-none">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="../services.php" class="nav-button text-decoration-none">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="../how-it-works.php" class="nav-button text-decoration-none">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Right Side Controls -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="header-icon-btn d-lg-none me-3" id="mobileMenuToggle" style="background: transparent; border: none;">
                        <i class="fas fa-bars text-white"></i>
                    </button>
                    
                    <!-- Dark Mode Toggle -->
                    <button type="button" class="header-icon-btn me-3" id="themeToggle" aria-label="Toggle theme" style="background: transparent; border: none;">
                        <i class="fas fa-moon text-white" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle rounded-circle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false" 
                               style="width: 36px; height: 36px; background-color: #7952b3; color: white; border: none; display: flex; align-items: center; justify-content: center; font-weight: bold;">
                            <?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?>
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
        <div class="mobile-menu d-lg-none" id="mobileMenu" style="display: none; position: absolute; top: 60px; left: 0; width: 100%; background-color: #212529; z-index: 1000;">
            <div class="mobile-menu-inner p-3">
                <a href="../index.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="../find-technician.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="../services.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="../how-it-works.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
                <div class="border-top border-secondary my-2"></div>
                <a href="dashboard.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                </a>
                <a href="bookings.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-calendar-check me-2"></i> My Bookings
                </a>
                <a href="profile.php" class="d-block py-2 px-3 text-white text-decoration-none">
                    <i class="fas fa-user me-2"></i> My Profile
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
                        <a class="nav-link active" href="bookings.php">
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
                        <a class="nav-link" href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="messages.php">
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

        <!-- Main Content Area -->
        <div class="content-area">
            <div class="d-flex align-items-center mb-4">
                <a href="bookings.php" class="back-button">
                    <i class="fas fa-chevron-left me-1"></i> Back to Bookings
                </a>
                <h1 class="page-title mb-0">Booking Details</h1>
            </div>
            
            <?php if (!empty($successMessage)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($errorMessage)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Booking Details Card -->
            <div class="card">
                <!-- Booking Header -->
                <div class="booking-header">
                    <div class="booking-id">
                        Booking #<?php echo htmlspecialchars($bookingData['id'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="booking-date">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                        <?php echo formatDate($bookingData['booking_date']); ?> at <?php echo formatTime($bookingData['booking_time']); ?>
                    </div>
                    <?php echo getStatusBadge($bookingData['status']); ?>
                </div>
                
                <!-- Service Details -->
                <div class="service-details">
                    <h3 class="service-name">
                        <?php echo safeText($service['name'] ?? 'Service Booking'); ?>
                    </h3>
                    <p class="service-description">
                        <?php echo safeText($service['description'] ?? 'No description available'); ?>
                    </p>
                    <div class="service-meta">
                        <div class="meta-item">
                            <div class="meta-label">
                                <i class="fas fa-tag me-1" aria-hidden="true"></i> Price
                            </div>
                            <div class="meta-value">
                                <?php echo formatPrice($bookingData['total_price'] ?? 0); ?>
                            </div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">
                                <i class="fas fa-clock me-1" aria-hidden="true"></i> Duration
                            </div>
                            <div class="meta-value">
                                <?php 
                                $duration = $service['duration'] ?? 0;
                                if ($duration > 0) {
                                    echo htmlspecialchars("{$duration} minutes", ENT_QUOTES, 'UTF-8');
                                } else {
                                    echo 'Duration not specified';
                                }
                                ?>
                            </div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">
                                <i class="fas fa-th-large me-1" aria-hidden="true"></i> Category
                            </div>
                            <div class="meta-value">
                                <?php echo safeText(ucfirst($service['category'] ?? 'General'), 'General'); ?>
                            </div>
                        </div>
                        <div class="meta-item">
                            <div class="meta-label">
                                <i class="fas fa-calendar-check me-1" aria-hidden="true"></i> Booking Date
                            </div>
                            <div class="meta-value">
                                <?php echo formatDate($bookingData['created_at']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Provider Details -->
                <div class="provider-details">
                    <?php if (!empty($provider['image'])): ?>
                        <div class="provider-avatar">
                            <img src="<?php echo htmlspecialchars('../provider_images/' . basename($provider['image']), ENT_QUOTES, 'UTF-8'); ?>" alt="Provider">
                        </div>
                    <?php else: ?>
                        <div class="provider-avatar">
                            <?php echo htmlspecialchars($providerInitials, ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="provider-info">
                        <h4 class="provider-name">
                            <?php echo htmlspecialchars(($provider['first_name'] ?? '') . ' ' . ($provider['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                        </h4>
                        <div class="provider-contact">
                            <div class="contact-item">
                                <i class="fas fa-phone" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($provider['phone'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-envelope" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($provider['email'] ?? 'Not provided', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="contact-item">
                                <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($provider['location'] ?? 'Not specified', ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <div class="provider-bio">
                            <?php 
                            if (!empty($provider['bio'])) {
                                echo htmlspecialchars($provider['bio'], ENT_QUOTES, 'UTF-8');
                            } else {
                                echo 'No provider bio available.';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <!-- Notes Section (if any) -->
                <?php if (!empty($bookingData['notes'])): ?>
                <div class="booking-notes">
                    <h4 class="section-title">Booking Notes</h4>
                    <div class="notes-content">
                        <?php echo htmlspecialchars($bookingData['notes'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Payment Information -->
                <div class="payment-info">
                    <h4 class="section-title mb-3">Payment Information</h4>
                    
                    <div class="payment-status <?php echo htmlspecialchars($bookingData['payment_status'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php if ($bookingData['payment_status'] === 'paid'): ?>
                            <i class="fas fa-check-circle me-1" aria-hidden="true"></i>
                        <?php elseif ($bookingData['payment_status'] === 'refunded'): ?>
                            <i class="fas fa-undo me-1" aria-hidden="true"></i>
                        <?php else: ?>
                            <i class="fas fa-clock me-1" aria-hidden="true"></i>
                        <?php endif; ?>
                        Payment <?php echo ucfirst(htmlspecialchars($bookingData['payment_status'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                    
                    <div class="payment-details">
                        <div class="total-amount">
                            <?php echo formatPrice($bookingData['total_price'] ?? 0); ?>
                        </div>
                        
                        <?php if ($bookingData['payment_status'] === 'unpaid'): ?>
                            <a href="payment.php?booking=<?php echo (int)$bookingData['id']; ?>" class="btn btn-primary">
                                <i class="fas fa-credit-card me-2" aria-hidden="true"></i>Pay Now
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Booking Actions -->
                <div class="booking-actions">
                    <?php if ($canCancel): ?>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelBookingModal">
                        <i class="fas fa-times-circle me-2" aria-hidden="true"></i>Cancel Booking
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($bookingData['status'] === 'confirmed'): ?>
                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#rescheduleModal">
                        <i class="fas fa-calendar-alt me-2" aria-hidden="true"></i>Reschedule
                    </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#messageModal">
                        <i class="fas fa-envelope me-2" aria-hidden="true"></i>Message Provider
                    </button>
                </div>
            </div>
            
            <!-- Status History Section -->
            <?php if (!empty($bookingHistory)): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Booking History</h3>
                </div>
                <div class="history-section">
                    <div class="timeline">
                        <?php foreach ($bookingHistory as $history): ?>
                        <div class="timeline-item">
                            <div class="timeline-content">
                                <div class="timeline-header">
                                    <div class="timeline-title">
                                        <?php echo htmlspecialchars($history['actor_name'], ENT_QUOTES, 'UTF-8'); ?> changed status to 
                                        <span class="fw-bold"><?php echo ucfirst(htmlspecialchars($history['status'], ENT_QUOTES, 'UTF-8')); ?></span>
                                    </div>
                                    <div class="timeline-date">
                                        <?php echo formatDate($history['created_at'], 'M d, Y h:i A'); ?>
                                    </div>
                                </div>
                                <?php if (!empty($history['notes'])): ?>
                                <div class="timeline-body">
                                    <?php echo htmlspecialchars($history['notes'], ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Messages Section -->
            <?php if (!empty($bookingMessages)): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">Messages</h3>
                </div>
                <div class="messages-section">
                    <div class="messages-container">
                        <?php foreach ($bookingMessages as $message): ?>
                            <?php $isOutgoing = $message['sender_id'] === $userId; ?>
                            <div class="message <?php echo $isOutgoing ? 'outgoing' : 'incoming'; ?>">
                                <div class="message-content">
                                    <div class="message-sender">
                                        <?php if ($isOutgoing): ?>
                                            You
                                        <?php else: ?>
                                            <?php echo htmlspecialchars(($message['sender_first_name'] ?? '') . ' ' . ($message['sender_last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="message-text">
                                        <?php echo htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                    <div class="message-time">
                                        <?php echo formatDate($message['created_at'], 'M d, Y h:i A'); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <form method="POST" class="message-form" id="messageForm">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="booking_id" value="<?php echo (int)$bookingData['id']; ?>">
                        <input type="hidden" name="receiver_id" value="<?php echo (int)$provider['user_id']; ?>">
                        <div class="input-group">
                            <input type="text" name="message" class="form-control" placeholder="Type your message..." required>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Send
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Review Section - Show if booking is completed -->
            <?php if ($canReview): ?>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title mb-0">
                        <?php echo $hasReviewed ? 'Your Review' : 'Leave a Review'; ?>
                    </h3>
                </div>
                <div class="review-section">
                    <?php if ($hasReviewed): ?>
                        <!-- Display existing review -->
                        <div class="review-card">
                            <div class="review-header">
                                <div class="review-stars">
                                    <?php 
                                    $rating = (float)$reviewData['rating'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        if ($i <= $rating) {
                                            echo '<i class="fas fa-star"></i>';
                                        } elseif ($i - 0.5 <= $rating) {
                                            echo '<i class="fas fa-star-half-alt"></i>';
                                        } else {
                                            echo '<i class="far fa-star"></i>';
                                        }
                                    }
                                    ?>
                                    <span class="ms-2"><?php echo $rating; ?>/5</span>
                                </div>
                                <div class="review-date">
                                    Submitted on <?php echo formatDate($reviewData['created_at']); ?>
                                </div>
                            </div>
                            <div class="review-content">
                                <?php if (!empty($reviewData['comment'])): ?>
                                    <?php echo htmlspecialchars($reviewData['comment'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php else: ?>
                                    <em>No additional comments provided.</em>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Review form -->
                        <p>Share your experience with the service provider. Your feedback helps others make informed decisions.</p>
                        
                        <form method="POST" class="review-form" id="reviewForm">
                            <input type="hidden" name="action" value="submit_review">
                            
                            <div class="mb-3">
                                <label class="form-label">Your Rating</label>
                                <div class="rating-stars">
                                    <?php for ($i = 5; $i >= 1; $i--): ?>
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input star-input visually-hidden" type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $i === 5 ? 'checked' : ''; ?>>
                                        <label class="form-check-label star-label" for="star<?php echo $i; ?>">
                                            <i class="fas fa-star"></i>
                                        </label>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="review-comment" class="form-label">Your Comments (Optional)</label>
                                <textarea class="form-control" id="review-comment" name="comment" rows="4" placeholder="Tell us about your experience..."></textarea>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2" aria-hidden="true"></i>Submit Review
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer py-3">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">© 2023 FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="../about.php" class="me-3 text-decoration-none footer-link">About</a>
                    <a href="../contact.php" class="me-3 text-decoration-none footer-link">Contact</a>
                    <a href="../privacy.php" class="me-3 text-decoration-none footer-link">Privacy Policy</a>
                    <a href="../terms.php" class="text-decoration-none footer-link">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Cancel Booking Modal -->
    <div class="modal fade" id="cancelBookingModal" tabindex="-1" aria-labelledby="cancelBookingModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cancelBookingModalLabel">Cancel Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="cancelForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="cancel_booking">
                        <p>Are you sure you want to cancel this booking?</p>
                        <p class="text-danger"><strong>Note:</strong> This action cannot be undone.</p>
                        <div class="mb-3">
                            <label for="cancel_reason" class="form-label">Reason for cancellation (optional):</label>
                            <textarea class="form-control" id="cancel_reason" name="cancel_reason" rows="3" placeholder="Please let us know why you're cancelling..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-danger">Yes, Cancel Booking</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Reschedule Modal -->
    <div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rescheduleModalLabel">Reschedule Booking</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This feature will be available soon. For now, please contact the provider directly to reschedule your booking.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#messageModal">Message Provider</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Message Modal -->
    <div class="modal fade" id="messageModal" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="messageModalLabel">Message Provider</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="modalMessageForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="receiver_id" value="<?php echo (int)$provider['user_id']; ?>">
                        <div class="mb-3">
                            <label for="modal_message" class="form-label">Your Message:</label>
                            <textarea class="form-control" id="modal_message" name="message" rows="4" required placeholder="Type your message here..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Send Message</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    if (mobileMenu.style.display === 'none' || mobileMenu.style.display === '') {
                        mobileMenu.style.display = 'block';
                        mobileMenuToggle.innerHTML = '<i class="fas fa-times text-white"></i>';
                    } else {
                        mobileMenu.style.display = 'none';
                        mobileMenuToggle.innerHTML = '<i class="fas fa-bars text-white"></i>';
                    }
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenu.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        if (mobileMenu.style.display === 'block') {
                            mobileMenu.style.display = 'none';
                            mobileMenuToggle.innerHTML = '<i class="fas fa-bars text-white"></i>';
                        }
                    }
                });
            }
            
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
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme) {
                setTheme(savedTheme === 'dark');
            } else {
                // Default to light theme for customer side
                setTheme(false);
            }
            
            // Toggle theme when button is clicked
            themeToggleBtn.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                setTheme(currentTheme !== 'dark');
            });
            
            // Star rating handling
            const starInputs = document.querySelectorAll('.star-input');
            const starLabels = document.querySelectorAll('.star-label');
            
            starLabels.forEach(function(label, index) {
                label.addEventListener('mouseover', function() {
                    // Highlight current star and all stars before it
                    for (let i = 0; i <= index; i++) {
                        starLabels[i].style.color = 'var(--warning-color)';
                    }
                    // Remove highlight from all stars after it
                    for (let i = index + 1; i < starLabels.length; i++) {
                        starLabels[i].style.color = 'var(--text-muted)';
                    }
                });
                
                label.addEventListener('mouseout', function() {
                    // Reset all stars
                    starLabels.forEach(function(label) {
                        label.style.color = '';
                    });
                    
                    // Apply highlight to checked stars
                    starInputs.forEach(function(input, i) {
                        if (input.checked) {
                            for (let j = 0; j <= i; j++) {
                                starLabels[j].style.color = 'var(--warning-color)';
                            }
                        }
                    });
                });
            });
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Scroll to the bottom of the messages container
            const messagesContainer = document.querySelector('.messages-container');
            if (messagesContainer) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        });
    </script>
</body>
</html>