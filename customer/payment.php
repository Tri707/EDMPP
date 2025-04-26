<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 3600); // Session expires after 1 hour of inactivity
session_start();

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Authentication check
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

// Redirect if not customer
if (!$loggedIn || $userRole !== 'customer' || $userId <= 0) {
    header('Location: ../login.php?redirect=customer/payment');
    exit;
}

// Database connection using MySQLi
require_once 'conn.php';

// Set proper character set
if (!$conn->set_charset("utf8mb4")) {
    error_log("Error loading character set utf8mb4: " . $conn->error);
}

// Initialize variables
$userData = [];
$userProfileImage = '../default.png';
$userInitials = 'CN'; // Default initials
$errorMessage = '';
$successMessage = '';
$notificationCount = 0;
$paymentInfo = [];
$quoteInfo = [];
$requestInfo = [];
$showPaymentForm = true;
$paymentSuccess = false;

// Check if payment session data exists
if (!isset($_SESSION['pending_payment_id']) || !isset($_SESSION['payment_amount']) || 
    !isset($_SESSION['payment_ref']) || !isset($_SESSION['quote_id']) || 
    !isset($_SESSION['request_id']) || !isset($_SESSION['payment_token'])) {
    
    $errorMessage = "Invalid payment session. Please return to quotes page and try again.";
    $showPaymentForm = false;
}

// Verify payment token
if ($showPaymentForm && !verifyPaymentToken($_SESSION['pending_payment_id'], $_SESSION['payment_amount'], $_SESSION['payment_token'])) {
    $errorMessage = "Invalid payment session token. Please return to quotes page and try again.";
    $showPaymentForm = false;
}

// Function to verify payment token
function verifyPaymentToken($paymentId, $amount, $token) {
    if (empty($token) || empty($paymentId) || empty($amount)) {
        return false;
    }
    
    // Note: In a real implementation, you would have a more secure way to verify tokens
    // For now, we'll just check if the token exists
    return true;
}

// Process payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Security validation failed. Please try again.";
    } else {
        $paymentId = isset($_SESSION['pending_payment_id']) ? (int)$_SESSION['pending_payment_id'] : 0;
        $quoteId = isset($_SESSION['quote_id']) ? (int)$_SESSION['quote_id'] : 0;
        $requestId = isset($_SESSION['request_id']) ? (int)$_SESSION['request_id'] : 0;
        $amount = isset($_SESSION['payment_amount']) ? (float)$_SESSION['payment_amount'] : 0;
        $paymentRef = isset($_SESSION['payment_ref']) ? $_SESSION['payment_ref'] : '';
        
        if ($paymentId <= 0 || $quoteId <= 0 || $requestId <= 0 || $amount <= 0 || empty($paymentRef)) {
            $errorMessage = "Invalid payment information. Please try again.";
        } else {
            try {
                // Set payment method to credit card only
                $paymentMethod = 'credit_card';
                
                // Validate credit card details
                // Validate required fields
                $requiredFields = ['card_number', 'card_holder', 'expiry_date', 'cvv'];
                $missingFields = [];
                
                foreach ($requiredFields as $field) {
                    if (empty($_POST[$field])) {
                        $missingFields[] = str_replace('_', ' ', $field);
                    }
                }
                
                if (!empty($missingFields)) {
                    $errorMessage = "Please fill in all required fields: " . implode(', ', $missingFields);
                    // We'll exit early so the transaction doesn't proceed
                    throw new Exception("Missing required fields: " . implode(', ', $missingFields));
                }
                
                // Sanitize and validate input data
                $cardNumber = preg_replace('/\D/', '', filter_input(INPUT_POST, 'card_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
                $cardHolder = filter_input(INPUT_POST, 'card_holder', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $expiryDate = filter_input(INPUT_POST, 'expiry_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $cvv = filter_input(INPUT_POST, 'cvv', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                
                // Additional validation
                if (strlen($cardNumber) < 13 || strlen($cardNumber) > 19) {
                    throw new Exception("Invalid card number format.");
                }
                
                if (!preg_match('/^\d{3,4}$/', $cvv)) {
                    throw new Exception("Invalid CVV format.");
                }
                
                // Begin transaction
                $conn->begin_transaction();
                
                // Update payment status to processing
                $updatePaymentStmt = $conn->prepare("UPDATE payments SET status = 'processing', payment_method = ?, updated_at = NOW() WHERE id = ? AND customer_id = ? AND status = 'pending'");
                $updatePaymentStmt->bind_param("sii", $paymentMethod, $paymentId, $userId);
                $updatePaymentStmt->execute();
                
                if ($updatePaymentStmt->affected_rows > 0) {
                    // Get the provider and technician IDs
                    $providerStmt = $conn->prepare("SELECT technician_id, provider_id FROM quotes WHERE id = ?");
                    $providerStmt->bind_param("i", $quoteId);
                    $providerStmt->execute();
                    $providerResult = $providerStmt->get_result();
                    $providerData = $providerResult->fetch_assoc();
                    $providerStmt->close();
                    
                    if ($providerData) {
                        // Add notifications
                        // For technician
                        $techNotifStmt = $conn->prepare("INSERT INTO quote_notifications (recipient_id, request_id, type, message) VALUES (?, ?, 'payment_processing', 'Customer has initiated payment for quote #" . $quoteId . "')");
                        $techNotifStmt->bind_param("ii", $providerData['technician_id'], $requestId);
                        $techNotifStmt->execute();
                        $techNotifStmt->close();
                        
                        // For admin
                        $adminStmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
                        $adminStmt->execute();
                        $adminResult = $adminStmt->get_result();
                        
                        while ($admin = $adminResult->fetch_assoc()) {
                            $adminId = $admin['id'];
                            $notifType = 'payment_processing';
                            $notifMessage = "Payment processing for quote #$quoteId - $paymentRef";
                            
                            $adminNotifStmt = $conn->prepare("INSERT INTO quote_notifications (recipient_id, request_id, type, message) VALUES (?, ?, ?, ?)");
                            $adminNotifStmt->bind_param("iiss", $adminId, $requestId, $notifType, $notifMessage);
                            $adminNotifStmt->execute();
                            $adminNotifStmt->close();
                        }
                        
                        $adminStmt->close();
                    }
                    
                    // Simulate a successful payment for credit card
                    $status = 'paid';
                    $transactionDetails = 'TRANS-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 12));
                    
                    // Update payment status
                    $finalizePaymentStmt = $conn->prepare("UPDATE payments SET status = ?, transaction_details = ?, updated_at = NOW() WHERE id = ? AND customer_id = ?");
                    $finalizePaymentStmt->bind_param("ssii", $status, $transactionDetails, $paymentId, $userId);
                    $finalizePaymentStmt->execute();
                    $finalizePaymentStmt->close();
                    
                    // Update repair status if payment was successful
                    if ($status === 'paid') {
                        try {
                            // Check if repairs table has quote_id column
                            $checkColumnStmt = $conn->prepare("
                                SELECT column_name 
                                FROM information_schema.columns 
                                WHERE table_name = 'repairs' 
                                AND column_name = 'quote_id'
                            ");
                            $checkColumnStmt->execute();
                            $hasQuoteIdColumn = $checkColumnStmt->get_result()->num_rows > 0;
                            $checkColumnStmt->close();
                            
                            if ($hasQuoteIdColumn) {
                                // Check if a repair record already exists with quote_id
                                $repairStmt = $conn->prepare("SELECT id FROM repairs WHERE quote_id = ?");
                                $repairStmt->bind_param("i", $quoteId);
                                $repairStmt->execute();
                                $repairResult = $repairStmt->get_result();
                                
                                if ($repairResult->num_rows > 0) {
                                    $repairData = $repairResult->fetch_assoc();
                                    $updateRepairStmt = $conn->prepare("UPDATE repairs SET status = 'in_progress', updated_at = NOW() WHERE id = ?");
                                    $updateRepairStmt->bind_param("i", $repairData['id']);
                                    $updateRepairStmt->execute();
                                    $updateRepairStmt->close();
                                } else {
                                    // Create new repair record with quote_id
                                    createRepairRecord($conn, $userId, $quoteId, true);
                                }
                                $repairStmt->close();
                            } else {
                                // Quote_id column doesn't exist, try to find repair by other fields
                                $altRepairStmt = $conn->prepare("
                                    SELECT r.id FROM repairs r 
                                    JOIN quotes q ON r.technician_id = q.technician_id
                                    WHERE q.id = ? AND r.customer_id = ?
                                ");
                                $altRepairStmt->bind_param("ii", $quoteId, $userId);
                                $altRepairStmt->execute();
                                $altRepairResult = $altRepairStmt->get_result();
                                
                                if ($altRepairResult->num_rows > 0) {
                                    $repairData = $altRepairResult->fetch_assoc();
                                    $updateRepairStmt = $conn->prepare("UPDATE repairs SET status = 'in_progress', updated_at = NOW() WHERE id = ?");
                                    $updateRepairStmt->bind_param("i", $repairData['id']);
                                    $updateRepairStmt->execute();
                                    $updateRepairStmt->close();
                                } else {
                                    // Create new repair record without quote_id
                                    createRepairRecord($conn, $userId, $quoteId, false);
                                }
                                $altRepairStmt->close();
                            }
                        } catch (Exception $e) {
                            // If any error occurs with repairs, log it but don't stop the payment process
                            error_log("Error processing repair after payment: " . $e->getMessage());
                            
                            // Try a fallback method to create repair
                            try {
                                createRepairRecord($conn, $userId, $quoteId, false);
                            } catch (Exception $fallbackEx) {
                                error_log("Fallback repair creation failed: " . $fallbackEx->getMessage());
                            }
                        }
                    }
                    
                    $successMessage = "Payment submitted successfully! " . 
                        ($status === 'paid' ? 
                            "Your repair has been scheduled. You will be notified when the technician begins work on your device." : 
                            "Our team will verify your bank transfer and update your repair status once confirmed.");
                    $paymentSuccess = true;
                    $showPaymentForm = false;
                    
                    // Clear payment session data
                    unset($_SESSION['pending_payment_id']);
                    unset($_SESSION['payment_amount']);
                    unset($_SESSION['payment_ref']);
                    unset($_SESSION['quote_id']);
                    unset($_SESSION['request_id']);
                    unset($_SESSION['payment_token']);
                } else {
                    $errorMessage = "Payment record not found or already processed.";
                }
                
                $updatePaymentStmt->close();
                
                // Commit transaction
                $conn->commit();
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                error_log("Error processing payment: " . $e->getMessage());
                $errorMessage = "An error occurred while processing your payment: " . $e->getMessage();
            }
        }
    }
}

// Function to create a repair record
function createRepairRecord($conn, $userId, $quoteId, $hasQuoteIdColumn) {
    $quoteDetailsStmt = $conn->prepare("
        SELECT q.technician_id, q.provider_id, qr.device_type, qr.issue_description 
        FROM quotes q
        JOIN quote_requests qr ON q.request_id = qr.id
        WHERE q.id = ?
    ");
    $quoteDetailsStmt->bind_param("i", $quoteId);
    $quoteDetailsStmt->execute();
    $quoteDetailsResult = $quoteDetailsStmt->get_result();
    $quoteDetails = $quoteDetailsResult->fetch_assoc();
    $quoteDetailsStmt->close();
    
    if ($quoteDetails) {
        if ($hasQuoteIdColumn) {
            $createRepairStmt = $conn->prepare("
                INSERT INTO repairs (
                    customer_id, 
                    technician_id,
                    quote_id,
                    device_type,
                    issue_description,
                    status, 
                    created_at, 
                    updated_at
                ) VALUES (?, ?, ?, ?, ?, 'in_progress', NOW(), NOW())
            ");
            $createRepairStmt->bind_param("iiiss", $userId, $quoteDetails['technician_id'], $quoteId, $quoteDetails['device_type'], $quoteDetails['issue_description']);
        } else {
            $createRepairStmt = $conn->prepare("
                INSERT INTO repairs (
                    customer_id, 
                    technician_id,
                    device_type,
                    issue_description,
                    status, 
                    created_at, 
                    updated_at
                ) VALUES (?, ?, ?, ?, 'in_progress', NOW(), NOW())
            ");
            $createRepairStmt->bind_param("iiss", $userId, $quoteDetails['technician_id'], $quoteDetails['device_type'], $quoteDetails['issue_description']);
        }
        $createRepairStmt->execute();
        $createRepairStmt->close();
    }
}

// If payment form should be shown, fetch related information
if ($showPaymentForm) {
    try {
        // Get payment details
        $paymentId = (int)$_SESSION['pending_payment_id'];
        $quoteId = (int)$_SESSION['quote_id'];
        $requestId = (int)$_SESSION['request_id'];
        
        // Verify the payment belongs to this user
        $paymentStmt = $conn->prepare("
            SELECT p.* 
            FROM payments p 
            WHERE p.id = ? AND p.customer_id = ? AND p.status = 'pending'
        ");
        $paymentStmt->bind_param("ii", $paymentId, $userId);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        $paymentInfo = $paymentResult->fetch_assoc();
        $paymentStmt->close();
        
        if (!$paymentInfo) {
            $errorMessage = "Invalid payment information. The payment may have been processed already.";
            $showPaymentForm = false;
        } else {
            // Get quote details
            $quoteStmt = $conn->prepare("
                SELECT q.*, u.first_name, u.last_name 
                FROM quotes q
                JOIN users u ON q.technician_id = u.id
                WHERE q.id = ? AND q.status = 'accepted'
            ");
            $quoteStmt->bind_param("i", $quoteId);
            $quoteStmt->execute();
            $quoteResult = $quoteStmt->get_result();
            $quoteInfo = $quoteResult->fetch_assoc();
            $quoteStmt->close();
            
            // Get request details
            $requestStmt = $conn->prepare("
                SELECT * FROM quote_requests 
                WHERE id = ? AND customer_id = ?
            ");
            $requestStmt->bind_param("ii", $requestId, $userId);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            $requestInfo = $requestResult->fetch_assoc();
            $requestStmt->close();
            
            if (!$quoteInfo || !$requestInfo) {
                $errorMessage = "Could not retrieve quote or request information.";
                $showPaymentForm = false;
            }
        }
    } catch (Exception $e) {
        error_log("Error retrieving payment information: " . $e->getMessage());
        $errorMessage = "An error occurred while retrieving payment information.";
        $showPaymentForm = false;
    }
}

// Get customer user data
try {
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer' AND status = 'active'");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $userData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$userData) {
        // If customer data doesn't exist or account not active, log them out
        session_unset();
        session_destroy();
        header('Location: ../login.php?error=invalid_session');
        exit;
    }
    
    // Set profile image path with proper validation
    if (!empty($userData['profile_image'])) {
        if (filter_var($userData['profile_image'], FILTER_VALIDATE_URL)) {
            // URL-based image with validation
            $userProfileImage = $userData['profile_image'];
        } else {
            // File-based image with protection against directory traversal
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
    
    // Get notification count
    $notifCountStmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM quote_notifications 
        WHERE recipient_id = ? AND is_read = 0
    ");
    $notifCountStmt->bind_param("i", $userId);
    $notifCountStmt->execute();
    $notifCountResult = $notifCountStmt->get_result();
    $notifCount = $notifCountResult->fetch_assoc();
    $notificationCount = $notifCount['count'];
    $notifCountStmt->close();
    
    // Get recent notifications for dropdown
    $recentNotifStmt = $conn->prepare("
        SELECT * FROM quote_notifications 
        WHERE recipient_id = ? 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $recentNotifStmt->bind_param("i", $userId);
    $recentNotifStmt->execute();
    $recentNotifResult = $recentNotifStmt->get_result();
    $recentNotifications = [];
    
    while ($row = $recentNotifResult->fetch_assoc()) {
        $recentNotifications[] = $row;
    }
    $recentNotifStmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching your data. Please try again later.";
}

// Function to safely format prices with the currency image
function formatPrice($price, $currencyImgPath = 'sar/sar.png') {
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
            return date('M d, Y', strtotime($timestamp));
        }
    } catch (Exception $e) {
        error_log("Time ago calculation error: " . $e->getMessage());
        return 'Invalid Date';
    }
}

// Add security headers
header("X-XSS-Protection: 1; mode=block");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com 'unsafe-inline'; img-src 'self' data:; font-src 'self' https://cdnjs.cloudflare.com;");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Close database connection when done
$conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Customer payment for FixItNow service booking platform">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment - FixItNow</title>
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

        /* Apply dark theme styles to the body when dark theme is active */
        body.dark-theme {
            background-color: var(--bg-color);
            color: var(--text-color);
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
        
        .notification-icon.warning {
            background-color: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        
        .notification-text {
            font-size: 0.825rem;
            color: #6c757d;
        }
        
        .notification-time {
            font-size: 0.75rem;
            color: #adb5bd;
            margin-top: 0.25rem;
        }
        
        .view-all {
            font-weight: 500;
            padding: 0.5rem;
            color: #7952b3;
        }
        
        .view-all:hover {
            background-color: rgba(121, 82, 179, 0.05);
            color: #6941a0;
        }
        
        /* User Dropdown */
        .user-dropdown {
            position: relative;
        }
        
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
        
        .user-dropdown-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
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
        
        .dropdown-menu {
            border: none;
            border-radius: 0.5rem;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.15);
            padding: 0.5rem 0;
            min-width: 220px;
        }
        
        .dropdown-item {
            padding: 0.65rem 1.25rem;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: rgba(121, 82, 179, 0.05);
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            color: #6c757d;
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
        
        .mobile-menu-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        
        /* Mobile Menu */
        .mobile-menu {
            display: none;
            position: fixed;
            top: 71px; /* Height of header */
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
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
        
        /* Payment Form Specific Styles */
        .payment-details {
            padding: 1.5rem;
            background-color: #473a6b; /* Dark mode purple */
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            color: #ffffff;
        }
        
        .payment-details-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: #a687ff; /* Vibrant purple for dark mode */
            display: flex;
            align-items: center;
        }
        
        .payment-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px dashed rgba(255, 255, 255, 0.2);
        }
        
        .payment-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .payment-label {
            font-weight: 500;
        }
        
        .payment-value {
            font-weight: 600;
        }
        
        .payment-total {
            font-size: 1.2rem;
            color: #ffffff;
        }
        
        .payment-method-selector {
            margin-bottom: 1.5rem;
        }
        
        .payment-method-option {
            display: flex;
            align-items: center;
            padding: 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .payment-method-option:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .payment-method-option.selected {
            border-color: #a687ff;
            background-color: rgba(166, 135, 255, 0.15);
        }
        
        .payment-method-icon {
            margin-right: 1rem;
            font-size: 1.5rem;
            color: #a687ff;
        }
        
        .payment-method-info {
            flex: 1;
        }
        
        .payment-method-title {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .payment-method-description {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        /* Bank Transfer Instructions Styling */
        .bank-transfer-instructions {
            border-radius: 0.5rem;
            overflow: hidden;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(0, 0, 0, 0.1);
            background-color: #001e2b;
            color: #ffffff;
        }
        
        .instruction-header {
            background-color: #003b50;
            padding: 1rem;
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            color: #4cd3e3;
        }
        
        .instruction-content {
            padding: 1.5rem;
        }
        
        .instruction-content ul {
            list-style: none;
            padding-left: 0;
            margin-bottom: 1.5rem;
        }
        
        .instruction-content ul li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
        }
        
        .submit-payment-btn {
            background-color: #1e88e5;
            border-color: #1e88e5;
            font-weight: 600;
            padding: 1rem;
            font-size: 1.1rem;
            position: relative;
            z-index: 100;
            cursor: pointer;
        }
        
        .submit-payment-btn:hover {
            background-color: #1976d2;
            border-color: #1976d2;
        }
        
        /* Ensure button is fully clickable */
        #submitPaymentBtn {
            position: relative;
            z-index: 10;
            pointer-events: auto;
        }
        
        .back-to-quotes-btn {
            background-color: #424242;
            border-color: #424242;
            color: #ffffff;
            font-weight: 500;
        }
        
        .back-to-quotes-btn:hover {
            background-color: #313131;
            border-color: #313131;
            color: white;
        }
        
        .card-input-container {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .card-input-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }
        
        .expiry-cvv-container {
            display: flex;
            gap: 1rem;
        }
        
        .expiry-cvv-container .form-group {
            flex: 1;
        }
        
        .secure-payment-note {
            display: flex;
            align-items: center;
            padding: 1rem;
            background-color: rgba(76, 217, 100, 0.15);
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .secure-payment-icon {
            font-size: 1.5rem;
            color: #4cd963;
            margin-right: 1rem;
        }
        
        .secure-payment-text {
            flex: 1;
            font-size: 0.875rem;
        }
        
        /* Success Message */
        .payment-success {
            text-align: center;
            padding: 3rem 1.5rem;
        }
        
        .payment-success-icon {
            font-size: 5rem;
            color: var(--success-color);
            margin-bottom: 1.5rem;
        }
        
        .payment-success-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .payment-success-message {
            font-size: 1.1rem;
            color: var(--text-muted);
            max-width: 600px;
            margin: 0 auto 2rem;
        }
        
        /* Footer */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 1.5rem 0;
            margin-top: auto;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 1.5rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
        }
        
        .footer-links a:hover {
            color: #fff;
        }
        
        /* Currency icon styling */
        .currency-icon {
            display: inline-block;
            vertical-align: middle;
        }
        
        /* Credit Card Styling */
        .credit-card {
            position: relative;
            width: 100%;
            height: 200px;
            border-radius: 16px;
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            padding: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 20px rgba(121, 82, 179, 0.2);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .cc-brand {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .cc-brand-name {
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: 2px;
        }
        
        .cc-chip {
            width: 45px;
            height: 35px;
            background: linear-gradient(135deg, #ffdd00 0%, #ffd000 100%);
            border-radius: 6px;
            position: relative;
            overflow: hidden;
        }
        
        .cc-chip::before {
            content: '';
            position: absolute;
            left: 4px;
            top: 4px;
            right: 4px;
            bottom: 4px;
            background: linear-gradient(135deg, #ffea8a 0%, #ffe466 100%);
            border-radius: 3px;
        }
        
        .cc-chip::after {
            content: '';
            position: absolute;
            width: 30px;
            height: 2px;
            background: rgba(0, 0, 0, 0.2);
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
        }
        
        .cc-number {
            font-size: 1.4rem;
            font-family: 'Courier New', monospace;
            letter-spacing: 3px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
        }
        
        .cc-info {
            display: flex;
            justify-content: space-between;
        }
        
        .cc-holder {
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 1px;
        }
        
        .cc-holder-value {
            font-size: 1rem;
            margin-top: 3px;
        }
        
        .cc-expires {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
        }
        
        .cc-expires-title {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .cc-expires-value {
            font-size: 1rem;
            margin-top: 3px;
        }
        
        .cc-network {
            position: absolute;
            bottom: 20px;
            right: 20px;
            font-size: 2rem;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
        }
        
        /* Error message styling */
        .alert-danger {
            background-color: rgba(255, 75, 92, 0.15);
            border-color: rgba(255, 75, 92, 0.3);
            color: #ff4b5c;
            display: flex;
            align-items: center;
        }
        
        .alert-danger .btn-close {
            color: #ff4b5c;
        }
        
        /* Success message styling */
        .alert-success {
            background-color: rgba(61, 231, 120, 0.15);
            border-color: rgba(61, 231, 120, 0.3);
            color: #3de778;
            display: flex;
            align-items: center;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem 1rem;
            }
            
            .expiry-cvv-container {
                flex-direction: column;
                gap: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Improved Header -->
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
                    
                    <!-- Notifications -->
                    <div class="dropdown me-3">
                        <button class="header-icon-btn position-relative" type="button" id="notificationsDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificationCount > 0): ?>
                            <span class="notification-badge"><?php echo $notificationCount > 9 ? '9+' : $notificationCount; ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notification-dropdown" aria-labelledby="notificationsDropdown">
                            <h6 class="dropdown-header">Notifications</h6>
                            <?php if (empty($recentNotifications)): ?>
                            <div class="notification-item text-center text-muted py-3">
                                <i class="fas fa-check me-2"></i>No new notifications
                            </div>
                            <?php else: ?>
                                <?php foreach ($recentNotifications as $notification): ?>
                                <a href="notification.php?id=<?php echo htmlspecialchars($notification['id']); ?>" class="notification-item">
                                    <?php
                                        $iconClass = '';
                                        switch($notification['type']) {
                                            case 'new_quote':
                                                $iconClass = 'primary fa-file-invoice-dollar';
                                                break;
                                            case 'quote_accepted':
                                                $iconClass = 'success fa-check-circle';
                                                break;
                                            case 'quote_rejected':
                                                $iconClass = 'warning fa-times-circle';
                                                break;
                                            case 'repair_completed':
                                                $iconClass = 'success fa-tools';
                                                break;
                                            default:
                                                $iconClass = 'primary fa-bell';
                                        }
                                    ?>
                                    <div class="notification-icon <?php echo strpos($iconClass, 'success') !== false ? 'success' : (strpos($iconClass, 'warning') !== false ? 'warning' : 'primary'); ?>">
                                        <i class="fas <?php echo $iconClass; ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?php echo htmlspecialchars($notification['type']); ?></div>
                                        <div class="notification-text"><?php echo htmlspecialchars($notification['message']); ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notification['created_at']); ?></div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a href="notifications.php" class="dropdown-item text-center view-all">
                                View All Notifications
                            </a>
                        </div>
                    </div>
                    
                    <!-- Theme Toggle -->
                    <button class="header-icon-btn me-3" id="themeToggle">
                        <i class="fas fa-sun"></i>
                    </button>
                    
                    <!-- User Menu -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar"><?php echo $userInitials; ?></div>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li class="dropdown-item">Signed in as <strong><?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?></strong></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="dashboard.php"><i class="fas fa-columns"></i> Dashboard</a></li>
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user"></i> My Profile</a></li>
                            <li><a class="dropdown-item" href="quotes.php"><i class="fas fa-file-invoice-dollar"></i> My Quotes</a></li>
                            <li><a class="dropdown-item" href="repairs.php"><i class="fas fa-tools"></i> My Repairs</a></li>
                            <li><a class="dropdown-item" href="payments.php"><i class="fas fa-credit-card"></i> Payments</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="help.php"><i class="fas fa-question-circle"></i> Help & Support</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Mobile Menu (visible only on small screens) -->
    <div class="mobile-menu" id="mobileMenu">
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
                <i class="fas fa-columns"></i> Dashboard
            </a>
            <a href="profile.php" class="mobile-menu-item">
                <i class="fas fa-user"></i> My Profile
            </a>
            <a href="quotes.php" class="mobile-menu-item">
                <i class="fas fa-file-invoice-dollar"></i> My Quotes
            </a>
            <a href="repairs.php" class="mobile-menu-item">
                <i class="fas fa-tools"></i> My Repairs
            </a>
            <a href="payments.php" class="mobile-menu-item active">
                <i class="fas fa-credit-card"></i> Payments
            </a>
            <div class="mobile-menu-divider"></div>
            <a href="help.php" class="mobile-menu-item">
                <i class="fas fa-question-circle"></i> Help & Support
            </a>
            <a href="../logout.php" class="mobile-menu-item">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Content Area -->
    <div class="content-area">
        <h1 class="page-title">Payment</h1>
        
        <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($errorMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($successMessage); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <?php if ($paymentSuccess): ?>
                <!-- Payment Success -->
                <div class="card">
                    <div class="card-body">
                        <div class="payment-success">
                            <div class="payment-success-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <h2 class="payment-success-title">Payment Successful!</h2>
                            <p class="payment-success-message">
                                Your payment has been processed successfully. A confirmation email has been sent to your registered email address with all the details.
                            </p>
                            <div class="d-flex justify-content-center gap-3">
                                <a href="repairs.php" class="btn btn-primary">
                                    <i class="fas fa-tools me-2"></i> View My Repairs
                                </a>
                                <a href="dashboard.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-home me-2"></i> Go to Dashboard
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <?php elseif ($showPaymentForm): ?>
                <!-- Payment Form -->
                <div class="card">
                    <div class="card-header d-flex align-items-center">
                        <i class="fas fa-credit-card me-2"></i> Make Payment
                    </div>
                    <div class="card-body">
                        <div class="payment-details">
                            <h5 class="payment-details-title">
                                <i class="fas fa-file-invoice me-2"></i> Payment Details
                            </h5>
                            <div class="payment-item">
                                <span class="payment-label">Payment Reference:</span>
                                <span class="payment-value">PAY-<?php echo strtoupper(substr(md5($_SESSION['payment_ref']), 0, 8)) . '-' . date('His'); ?></span>
                            </div>
                            <div class="payment-item">
                                <span class="payment-label">Quote ID:</span>
                                <span class="payment-value">#<?php echo (int)$_SESSION['quote_id']; ?></span>
                            </div>
                            <div class="payment-item">
                                <span class="payment-label">Request ID:</span>
                                <span class="payment-value">#<?php echo (int)$_SESSION['request_id']; ?></span>
                            </div>
                            <?php if (isset($requestInfo['device_type'])): ?>
                            <div class="payment-item">
                                <span class="payment-label">Device:</span>
                                <span class="payment-value"><?php echo ucfirst(htmlspecialchars($requestInfo['device_type'])); ?>
                                <?php if (!empty($requestInfo['device_brand']) && $requestInfo['device_brand'] !== 'Not specified'): ?>
                                    (<?php echo htmlspecialchars($requestInfo['device_brand'] . ' ' . $requestInfo['device_model']); ?>)
                                <?php endif; ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php if (isset($quoteInfo['first_name'])): ?>
                            <div class="payment-item">
                                <span class="payment-label">Technician:</span>
                                <span class="payment-value"><?php echo htmlspecialchars($quoteInfo['first_name'] . ' ' . $quoteInfo['last_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="payment-item">
                                <span class="payment-label payment-total">Total Amount:</span>
                                <span class="payment-value payment-total">
                                    <img src="sar/sar.png" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;"> 
                                    <?php echo number_format((float)$_SESSION['payment_amount'], 2); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="secure-payment-note">
                            <div class="secure-payment-icon">
                                <i class="fas fa-lock"></i>
                            </div>
                            <div class="secure-payment-text">
                                <strong>Secure Payment:</strong> All payment information is encrypted and secure. We do not store your card details.
                            </div>
                        </div>
                        
                        <form action="payment.php" method="post" id="paymentForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="process_payment">
                            <!-- Add payment amount as hidden field -->
                            <input type="hidden" name="payment_amount" value="<?php echo $_SESSION['payment_amount']; ?>">
                            
                            <!-- Credit Card Form -->
                            <div id="creditCardForm">
                                <div class="credit-card mb-4">
                                    <div class="cc-brand">
                                        <div class="cc-brand-name">CREDIT CARD</div>
                                        <div class="cc-chip"></div>
                                    </div>
                                    <div class="cc-number" id="displayCardNumber">**** **** **** ****</div>
                                    <div class="cc-info">
                                        <div class="cc-holder">
                                            <div>Card Holder</div>
                                            <div class="cc-holder-value" id="displayCardHolder">YOUR NAME</div>
                                        </div>
                                        <div class="cc-expires">
                                            <div class="cc-expires-title">Expires</div>
                                            <div class="cc-expires-value" id="displayCardExpiry">MM/YY</div>
                                        </div>
                                    </div>
                                    <div class="cc-network">
                                        <i class="fab fa-cc-visa" id="cardTypeIcon"></i>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="cardNumber" class="form-label">Card Number</label>
                                    <div class="card-input-container">
                                        <input type="text" class="form-control" id="cardNumber" name="card_number" placeholder="1234 5678 9012 3456" maxlength="19">
                                        <div class="card-input-icon">
                                            <i class="fas fa-credit-card"></i>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="cardHolder" class="form-label">Card Holder Name</label>
                                    <input type="text" class="form-control" id="cardHolder" name="card_holder" placeholder="John Doe">
                                </div>
                                
                                <div class="expiry-cvv-container">
                                    <div class="mb-3">
                                        <label for="expiryDate" class="form-label">Expiration Date</label>
                                        <input type="text" class="form-control" id="expiryDate" name="expiry_date" placeholder="MM/YY" maxlength="5">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cvv" class="form-label">CVV</label>
                                        <div class="card-input-container">
                                            <input type="text" class="form-control" id="cvv" name="cvv" placeholder="123" maxlength="4">
                                            <div class="card-input-icon">
                                                <i class="fas fa-question-circle" data-bs-toggle="tooltip" data-bs-placement="top" title="3-4 digit security code on the back of your card"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bank Transfer form removed -->
                            
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn btn-primary btn-lg submit-payment-btn" id="submitPaymentBtn">
                                    <i class="fas fa-lock me-2"></i> Pay Now (<img src="sar/sar.png" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 2px; vertical-align: -2px;"> <?php echo $_SESSION['payment_amount']; ?> SAR)
                                </button>
                                <a href="quotes.php" class="btn btn-secondary back-to-quotes-btn">
                                    <i class="fas fa-arrow-left me-2"></i> Back to Quotes
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php else: ?>
                <!-- Error State -->
                <div class="card">
                    <div class="card-body">
                        <div class="text-center py-5">
                            <div class="mb-4">
                                <i class="fas fa-exclamation-circle text-danger" style="font-size: 5rem;"></i>
                            </div>
                            <h3 class="mb-3">Payment Session Invalid</h3>
                            <p class="mb-4">The payment session is invalid or has expired. Please return to the quotes page and try again.</p>
                            <a href="quotes.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-2"></i> Return to Quotes
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p>&copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <ul class="footer-links">
                        <li><a href="../terms.php">Terms of Service</a></li>
                        <li><a href="../privacy.php">Privacy Policy</a></li>
                        <li><a href="../contact.php">Contact Us</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        });
        
        // Dark mode toggle
        const themeToggleBtn = document.getElementById('themeToggle');
        const body = document.body;
        
        // Check for saved theme preference or use device preference
        const currentTheme = localStorage.getItem('theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        
        // Set initial theme
        if (currentTheme === 'dark') {
            body.setAttribute('data-bs-theme', 'dark');
            themeToggleBtn.innerHTML = '<i class="fas fa-sun"></i>';
        } else {
            body.setAttribute('data-bs-theme', 'light');
            themeToggleBtn.innerHTML = '<i class="fas fa-moon"></i>';
        }
        
        // Theme toggle button functionality
        themeToggleBtn.addEventListener('click', () => {
            if (body.getAttribute('data-bs-theme') === 'light') {
                body.setAttribute('data-bs-theme', 'dark');
                localStorage.setItem('theme', 'dark');
                themeToggleBtn.innerHTML = '<i class="fas fa-sun"></i>';
            } else {
                body.setAttribute('data-bs-theme', 'light');
                localStorage.setItem('theme', 'light');
                themeToggleBtn.innerHTML = '<i class="fas fa-moon"></i>';
            }
        });
        
        // Mobile menu toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mobileMenu = document.getElementById('mobileMenu');
        
        if (mobileMenuToggle) {
            mobileMenuToggle.addEventListener('click', () => {
                mobileMenu.classList.toggle('show');
            });
        }
        
        // Hide mobile menu on larger screens
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992 && mobileMenu && mobileMenu.classList.contains('show')) {
                mobileMenu.classList.remove('show');
            }
        });
        
        // Credit card is the only payment method
        const creditCardForm = document.getElementById('creditCardForm');
        
        // Credit card form interactive elements
        const cardNumberInput = document.getElementById('cardNumber');
        const cardHolderInput = document.getElementById('cardHolder');
        const expiryDateInput = document.getElementById('expiryDate');
        const cvvInput = document.getElementById('cvv');
        
        const displayCardNumber = document.getElementById('displayCardNumber');
        const displayCardHolder = document.getElementById('displayCardHolder');
        const displayCardExpiry = document.getElementById('displayCardExpiry');
        const cardTypeIcon = document.getElementById('cardTypeIcon');
        
        // Format card number with spaces
        if (cardNumberInput) {
            cardNumberInput.addEventListener('input', function(e) {
                // Remove all non-digits
                let value = this.value.replace(/\D/g, '');
                
                // Add spaces every 4 digits
                value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
                
                // Update input value
                this.value = value;
                
                // Update display
                if (value.length > 0) {
                    displayCardNumber.textContent = value;
                } else {
                    displayCardNumber.textContent = '**** **** **** ****';
                }
                
                // Detect card type based on first digits
                if (value.startsWith('4')) {
                    cardTypeIcon.className = 'fab fa-cc-visa';
                } else if (/^5[1-5]/.test(value)) {
                    cardTypeIcon.className = 'fab fa-cc-mastercard';
                } else if (/^3[47]/.test(value)) {
                    cardTypeIcon.className = 'fab fa-cc-amex';
                } else if (/^6(?:011|5)/.test(value)) {
                    cardTypeIcon.className = 'fab fa-cc-discover';
                } else {
                    cardTypeIcon.className = 'fab fa-credit-card';
                }
            });
        }
        
        // Update card holder display
        if (cardHolderInput) {
            cardHolderInput.addEventListener('input', function(e) {
                if (this.value.length > 0) {
                    displayCardHolder.textContent = this.value.toUpperCase();
                } else {
                    displayCardHolder.textContent = 'YOUR NAME';
                }
            });
        }
        
        // Format expiry date (MM/YY)
        if (expiryDateInput) {
            expiryDateInput.addEventListener('input', function(e) {
                // Remove all non-digits
                let value = this.value.replace(/\D/g, '');
                
                // Add slash after first 2 digits
                if (value.length > 2) {
                    value = value.substring(0, 2) + '/' + value.substring(2);
                }
                
                // Update input value
                this.value = value;
                
                // Update display
                if (value.length > 0) {
                    displayCardExpiry.textContent = value;
                } else {
                    displayCardExpiry.textContent = 'MM/YY';
                }
            });
        }
        
        // Allow only digits in CVV
        if (cvvInput) {
            cvvInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/\D/g, '');
            });
        }
        
        // Form validation
        const paymentForm = document.getElementById('paymentForm');
        
        if (paymentForm) {
            paymentForm.addEventListener('submit', function(e) {
                // Credit card is the only payment method
                // Validate card number (basic validation)
                const cardNumber = cardNumberInput.value.replace(/\s/g, '');
                if (cardNumber.length < 13 || cardNumber.length > 19) {
                    e.preventDefault();
                    alert('Please enter a valid card number');
                    cardNumberInput.focus();
                    return;
                }
                
                // Validate card holder name
                if (cardHolderInput.value.trim().length < 3) {
                    e.preventDefault();
                    alert('Please enter the cardholder name');
                    cardHolderInput.focus();
                    return;
                }
                
                // Validate expiry date format (MM/YY)
                if (!expiryDateInput.value.match(/^\d{2}\/\d{2}$/)) {
                    e.preventDefault();
                    alert('Please enter a valid expiry date (MM/YY)');
                    expiryDateInput.focus();
                    return;
                }
                
                // Validate CVV length
                if (cvvInput.value.length < 3 || cvvInput.value.length > 4) {
                    e.preventDefault();
                    alert('Please enter a valid CVV code');
                    cvvInput.focus();
                    return;
                }
                
                // Form will submit if all validations pass
            });
        }
    </script>
</body>
</html>