<?php
/**
 * Quote Request Details - FixItNow Platform
 * 
 * This file displays detailed information about a specific quote request
 * and allows providers to submit or update their quotes.
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
$requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$providerProfileImage = '../default.png'; // Set default image path
$providerData = [];
$quoteRequest = [];
$customerData = [];
$quoteData = null;
$media = [];
$message = '';
$alertType = '';
$specialties = [];

// Validate request ID
if ($requestId <= 0) {
    error_log("Invalid quote request ID: $requestId");
    header('Location: quotes.php?error=invalid_request');
    exit;
}

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

        // Parse provider specialties
        if (!empty($providerData['specialties'])) {
            $specialties = explode(',', $providerData['specialties']);
            // Sanitize each specialty
            $specialties = array_map('trim', $specialties);
            $specialties = array_filter($specialties); // Remove empty values
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

// Fetch quote request details
try {
    $stmt = $pdo->prepare("SELECT 
                           qr.*,
                           u.first_name,
                           u.last_name,
                           u.email,
                           u.phone,
                           u.profile_image
                        FROM quote_requests qr
                        JOIN users u ON qr.customer_id = u.id
                        WHERE qr.id = ?");
    $stmt->execute([$requestId]);
    $quoteRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quoteRequest) {
        error_log("Quote request not found: $requestId");
        header('Location: quotes.php?error=request_not_found');
        exit;
    }
    
    // Check if device type is within provider's specialties
    if (!empty($specialties) && !in_array($quoteRequest['device_type'], $specialties)) {
        error_log("Device type not in provider specialties: {$quoteRequest['device_type']}");
        header('Location: quotes.php?error=specialty_mismatch');
        exit;
    }
    
    // Extract customer data
    $customerData = [
        'id' => $quoteRequest['customer_id'],
        'first_name' => $quoteRequest['first_name'],
        'last_name' => $quoteRequest['last_name'],
        'email' => $quoteRequest['email'],
        'phone' => $quoteRequest['phone'],
        'profile_image' => $quoteRequest['profile_image']
    ];
    
    // Clean up customer profile image from quote request result
    unset($quoteRequest['first_name']);
    unset($quoteRequest['last_name']);
    unset($quoteRequest['email']);
    unset($quoteRequest['phone']);
    unset($quoteRequest['profile_image']);
    
} catch (PDOException $e) {
    error_log("Database error fetching quote request: " . $e->getMessage());
    header('Location: ../error.php?message=Database%20error');
    exit;
}

// Check if quote already exists for this request from this provider
try {
    $stmt = $pdo->prepare("SELECT * FROM quotes WHERE request_id = ? AND provider_id = ?");
    $stmt->execute([$requestId, $providerId]);
    $quoteData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching quote data: " . $e->getMessage());
    // Continue without quote data
}

// Fetch media attachments
try {
    $stmt = $pdo->prepare("SELECT * FROM quote_request_media WHERE request_id = ? ORDER BY id ASC");
    $stmt->execute([$requestId]);
    $media = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching media: " . $e->getMessage());
    // Continue without media data
}

// Process form submissions - submit new quote or update quote status
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed");
        $message = 'Security validation failed. Please try again.';
        $alertType = 'danger';
    } else {
        // Handle quote submission - only for new quotes
        if (isset($_POST['action']) && $_POST['action'] === 'submit_quote' && !$quoteData) {
            try {
                // Validate inputs
                $price = isset($_POST['price']) ? (float)$_POST['price'] : 0;
                if ($price <= 0) {
                    throw new Exception('Price must be greater than zero.');
                }
                
                $estimated_time = isset($_POST['estimated_time']) ? trim($_POST['estimated_time']) : '';
                if (empty($estimated_time)) {
                    throw new Exception('Estimated time is required.');
                }
                $estimated_time = htmlspecialchars($estimated_time);
                
                $description = isset($_POST['description']) ? trim($_POST['description']) : '';
                if (empty($description)) {
                    throw new Exception('Description is required.');
                }
                $description = htmlspecialchars($description);
                
                $warranty = isset($_POST['warranty']) ? trim($_POST['warranty']) : '';
                if (empty($warranty)) {
                    throw new Exception('Warranty selection is required.');
                }
                $warranty = htmlspecialchars($warranty);
                
                $parts_needed = isset($_POST['parts_needed']) ? trim($_POST['parts_needed']) : '';
                $parts_needed = htmlspecialchars($parts_needed);
                
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
                $notes = htmlspecialchars($notes);
                
                // Insert new quote
                $insertStmt = $pdo->prepare("INSERT INTO quotes (
                                            request_id, 
                                            provider_id,
                                            technician_id, 
                                            price, 
                                            estimated_time, 
                                            description,
                                            warranty,
                                            parts_needed,
                                            notes,
                                            status
                                          ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                $insertStmt->execute([$requestId, $providerId, $userId, $price, $estimated_time, $description, $warranty, $parts_needed, $notes]);
                
                // Update quote_requests table to 'quoted' status
                $updateRequestStmt = $pdo->prepare("UPDATE quote_requests SET status = 'quoted' WHERE id = ?");
                $updateRequestStmt->execute([$requestId]);
                
                $message = 'Quote has been submitted successfully!';
                
                // Refresh quote data
                $stmt = $pdo->prepare("SELECT * FROM quotes WHERE request_id = ? AND provider_id = ?");
                $stmt->execute([$requestId, $providerId]);
                $quoteData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $alertType = 'success';
                
            } catch (Exception $e) {
                error_log("Error submitting quote: " . $e->getMessage());
                $message = 'An error occurred: ' . $e->getMessage();
                $alertType = 'danger';
            }
        } elseif (isset($_POST['action']) && $_POST['action'] === 'submit_quote' && $quoteData) {
            // If someone tries to update an existing quote (should not happen via normal UI)
            $message = 'Quote has already been submitted and cannot be modified.';
            $alertType = 'warning';
        } elseif (isset($_POST['action']) && $_POST['action'] === 'update_quote_status' && $quoteData) {
            // Handle quote status update (only allowed for accepted quotes)
            try {
                if ($quoteData['status'] !== 'accepted') {
                    throw new Exception('Only accepted quotes can be updated to completed or cancelled status.');
                }
                
                $newStatus = isset($_POST['status']) ? trim($_POST['status']) : '';
                if (!in_array($newStatus, ['completed', 'cancelled'])) {
                    throw new Exception('Invalid status. Only "completed" or "cancelled" are allowed.');
                }
                
                // Update the quote status
                $updateStmt = $pdo->prepare("UPDATE quotes SET 
                                            status = ?,
                                            updated_at = CURRENT_TIMESTAMP
                                          WHERE id = ? AND provider_id = ? AND status = 'accepted'");
                $updateStmt->execute([$newStatus, $quoteData['id'], $providerId]);
                
                // Also update the quote request status
                $updateRequestStmt = $pdo->prepare("UPDATE quote_requests SET status = ? WHERE id = ?");
                $updateRequestStmt->execute([$newStatus, $requestId]);
                
                // Set appropriate message
                if ($newStatus === 'completed') {
                    $message = 'The repair has been marked as completed successfully!';
                } else {
                    $message = 'The repair has been cancelled.';
                }
                
                // Refresh quote data
                $stmt = $pdo->prepare("SELECT * FROM quotes WHERE request_id = ? AND provider_id = ?");
                $stmt->execute([$requestId, $providerId]);
                $quoteData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $alertType = ($newStatus === 'completed') ? 'success' : 'warning';
                
            } catch (Exception $e) {
                error_log("Error updating quote status: " . $e->getMessage());
                $message = 'An error occurred: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}

// Helper functions
function formatDateTime($date) {
    return date('D, M j, Y - g:i A', strtotime($date));
}

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'pending':
            return 'warning';
        case 'quoted':
            return 'info';
        case 'accepted':
            return 'primary';
        case 'completed':
            return 'success';
        case 'cancelled':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Function to get device icon
function getDeviceIcon($deviceType) {
    switch ($deviceType) {
        case 'smartphone':
            return '<i class="fas fa-mobile-alt"></i>';
        case 'laptop':
            return '<i class="fas fa-laptop"></i>';
        case 'tablet':
            return '<i class="fas fa-tablet-alt"></i>';
        case 'desktop':
            return '<i class="fas fa-desktop"></i>';
        case 'gaming':
            return '<i class="fas fa-gamepad"></i>';
        case 'tv':
            return '<i class="fas fa-tv"></i>';
        default:
            return '<i class="fas fa-microchip"></i>';
    }
}

// Function to get quote status display name
function getQuoteStatusName($status) {
    switch ($status) {
        case 'pending':
            return 'Pending';
        case 'quoted':
            return 'Quote Submitted';
        case 'accepted':
            return 'Quote Accepted';
        case 'rejected':
            return 'Quote Rejected';
        case 'completed':
            return 'Repair Completed';
        case 'cancelled':
            return 'Cancelled';
        default:
            return 'Unknown';
    }
}

// Function to display customer profile image
function getCustomerProfileImage($customerData) {
    $defaultImage = '../default.png';
    
    if (empty($customerData['profile_image'])) {
        return $defaultImage;
    }
    
    if (preg_match('/^https?:\/\//i', $customerData['profile_image'])) {
        // External URL
        return $customerData['profile_image'];
    } else {
        // Local file
        $imagePath = '../profile_images/' . basename($customerData['profile_image']);
        if (file_exists($imagePath) && is_readable($imagePath)) {
            return $imagePath;
        }
        return $defaultImage;
    }
}

// Function to get customer initials for avatar
function getCustomerInitials($customerData) {
    $initials = '';
    if (!empty($customerData['first_name'])) {
        $initials .= strtoupper(substr($customerData['first_name'], 0, 1));
    }
    if (!empty($customerData['last_name'])) {
        $initials .= strtoupper(substr($customerData['last_name'], 0, 1));
    }
    return $initials ?: 'C';
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Request Details - FixItNow</title>
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
        
        /* Status badges */
        .status-badge {
            font-size: 0.8rem;
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
        }
        
        /* Device Badge */
        .device-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
            font-size: 0.8rem;
            display: inline-flex;
            align-items: center;
        }
        
        .device-badge i {
            margin-right: 5px;
        }
        
        /* Customer Info */
        .customer-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            overflow: hidden;
            margin-right: 1rem;
        }
        
        .customer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-text {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: 700;
            margin-right: 1rem;
        }
        
        /* Quote form */
        .form-control, .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        .form-control:focus, .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(166, 135, 255, 0.25);
        }
        
        /* Media gallery */
        .media-gallery {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .media-item {
            flex: 0 0 calc(33.333% - 1rem);
            max-width: calc(33.333% - 1rem);
            border-radius: 0.5rem;
            overflow: hidden;
            box-shadow: 0 0.25rem 0.5rem var(--shadow-color);
            transition: transform 0.2s ease;
        }
        
        .media-item:hover {
            transform: scale(1.03);
        }
        
        .media-item img {
            width: 100%;
            height: auto;
            object-fit: cover;
        }
        
        @media (max-width: 992px) {
            .media-item {
                flex: 0 0 calc(50% - 1rem);
                max-width: calc(50% - 1rem);
            }
        }
        
        @media (max-width: 576px) {
            .media-item {
                flex: 0 0 100%;
                max-width: 100%;
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
        
        /* Info item styles */
        .info-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .info-list li {
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border-color);
            display: flex;
        }
        
        .info-list li:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            width: 30%;
            color: var(--text-muted);
        }
        
        .info-value {
            width: 70%;
        }
        
        /* Price display */
        .price-display {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .currency-icon {
            vertical-align: middle;
            margin-right: 2px;
            height: 20px;
        }
        
        /* Timeline styles */
        .timeline {
            position: relative;
            margin: 0;
            padding: 0;
            list-style: none;
        }
        
        .timeline:before {
            content: '';
            position: absolute;
            top: 0;
            bottom: 0;
            left: 18px;
            width: 2px;
            background: var(--border-color);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding-left: 2.5rem;
        }
        
        .timeline-icon {
            position: absolute;
            left: 0;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: var(--primary-light);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }
        
        .timeline-content {
            padding: 1rem;
            background-color: rgba(0,0,0,0.05);
            border-radius: 0.5rem;
        }
        
        .timeline-date {
            display: block;
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }

        /* RTL support for Arabic */
        [dir="rtl"] .sidebar-nav .nav-icon {
            margin-right: 0;
            margin-left: 1rem;
        }
        
        [dir="rtl"] .info-label {
            text-align: right;
        }
        
        [dir="rtl"] .device-badge i,
        [dir="rtl"] .card-header i {
            margin-right: 0;
            margin-left: 5px;
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
                        <a class="nav-link" href="messages.php">
                            <span class="nav-icon"><i class="fas fa-comments"></i></span>
                            <span class="nav-text">Messages</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="quotes.php">
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
                    <h2 class="page-title">Quote Request Details</h2>
                    <p class="text-muted">Review customer request and submit or update your repair quote</p>
                </div>
                <div>
                    <a href="quotes.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Quotes
                    </a>
                    <a href="messages.php?new=1&recipient_id=<?php echo $customerData['id']; ?>" class="btn btn-primary ms-2">
                        <i class="fas fa-comments me-1"></i> Message Customer
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
            
            <div class="row">
                <!-- Left Column: Request Details -->
                <div class="col-lg-8">
                    <!-- Quote Request Details Card -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="fas fa-file-invoice-dollar me-2"></i>
                                Request Details
                            </h5>
                            <div>
                                <span class="badge bg-<?php echo getStatusBadgeClass($quoteRequest['status']); ?> status-badge">
                                    <?php echo getQuoteStatusName($quoteRequest['status']); ?>
                                </span>
                                
                                <span class="device-badge bg-primary bg-opacity-10 text-primary ms-2">
                                    <?php echo getDeviceIcon($quoteRequest['device_type']); ?>
                                    <?php echo ucfirst(htmlspecialchars($quoteRequest['device_type'])); ?>
                                </span>
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Basic Request Info -->
                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <ul class="info-list">
                                        <li>
                                            <div class="info-label">Request ID</div>
                                            <div class="info-value">#<?php echo $quoteRequest['id']; ?></div>
                                        </li>
                                        <li>
                                            <div class="info-label">Date Submitted</div>
                                            <div class="info-value"><?php echo formatDateTime($quoteRequest['created_at']); ?></div>
                                        </li>
                                        <li>
                                            <div class="info-label">Device Type</div>
                                            <div class="info-value"><?php echo ucfirst(htmlspecialchars($quoteRequest['device_type'])); ?></div>
                                        </li>
                                        <li>
                                            <div class="info-label">Device Brand</div>
                                            <div class="info-value"><?php echo htmlspecialchars($quoteRequest['device_brand'] ?: 'Not specified'); ?></div>
                                        </li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <ul class="info-list">
                                        <li>
                                            <div class="info-label">Device Model</div>
                                            <div class="info-value"><?php echo htmlspecialchars($quoteRequest['device_model'] ?: 'Not specified'); ?></div>
                                        </li>
                                        <li>
                                            <div class="info-label">Service Type</div>
                                            <div class="info-value"><?php echo ucfirst(htmlspecialchars($quoteRequest['service_type'] ?: 'Repair')); ?></div>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                            
                            <!-- Issue Description -->
                            <div class="mb-4">
                                <h6 class="fw-bold">Issue Description</h6>
                                <div class="p-3 bg-opacity-10 bg-primary rounded">
                                    <?php echo nl2br(htmlspecialchars($quoteRequest['issue_description'])); ?>
                                </div>
                            </div>
                            
                            <!-- Additional Information (if available) -->
                            <?php if (!empty($quoteRequest['additional_info'])): ?>
                            <div class="mb-4">
                                <h6 class="fw-bold">Additional Information</h6>
                                <div class="p-3 bg-opacity-10 bg-secondary rounded">
                                    <?php echo nl2br(htmlspecialchars($quoteRequest['additional_info'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Media Attachments -->
                            <?php if (!empty($media)): ?>
                            <div>
                                <h6 class="fw-bold">Attached Media (<?php echo count($media); ?>)</h6>
                                <div class="media-gallery">
                                    <?php foreach ($media as $item): ?>
                                        <?php if (strpos($item['file_type'], 'image/') === 0): ?>
                                            <div class="media-item">
                                                <a href="<?php echo htmlspecialchars('../' . $item['file_path']); ?>" target="_blank">
                                                    <img src="<?php echo htmlspecialchars('../' . $item['file_path']); ?>" alt="Request Media">
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <div class="media-item p-3 text-center">
                                                <a href="<?php echo htmlspecialchars('../' . $item['file_path']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-file me-1"></i> 
                                                    Download <?php echo pathinfo($item['original_name'], PATHINFO_EXTENSION); ?> File
                                                </a>
                                                <div class="small text-muted mt-2"><?php echo htmlspecialchars($item['original_name']); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quote Form Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-<?php echo $quoteData ? 'file-alt' : 'edit'; ?> me-2"></i>
                                <?php echo $quoteData ? 'Quote Details' : 'Submit Your Quote'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if(!$quoteData): ?>
                            <form action="quote-detail.php?id=<?php echo $requestId; ?>" method="post" id="quoteForm">
                                <input type="hidden" name="action" value="submit_quote">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label for="price" class="form-label">Quote Price (SAR) <span class="text-danger">*</span></label>
                                        <div class="input-group">
                                            <span class="input-group-text"><img src="../sar/sar.png" alt="" class="currency-icon"></span>
                                            <input type="number" class="form-control" id="price" name="price" min="0.01" step="0.01" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="estimated_time" class="form-label">Estimated Time <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="estimated_time" name="estimated_time" 
                                               placeholder="e.g., 2-3 days, 1 hour, etc." required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="description" class="form-label">Quote Description <span class="text-danger">*</span></label>
                                    <textarea class="form-control" id="description" name="description" rows="4" required></textarea>
                                    <div class="form-text">Provide a detailed description of the repair process, what's included, etc.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="warranty" class="form-label">Warranty <span class="text-danger">*</span></label>
                                    <select class="form-select" id="warranty" name="warranty" required>
                                        <option value="" disabled selected>Select warranty period</option>
                                        <option value="No warranty">No warranty</option>
                                        <option value="30 days">30 days</option>
                                        <option value="90 days">90 days</option>
                                        <option value="6 months">6 months</option>
                                        <option value="1 year">1 year</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="parts_needed" class="form-label">Parts Needed</label>
                                    <textarea class="form-control" id="parts_needed" name="parts_needed" rows="2"></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                                </div>
                                
                                <div class="d-flex justify-content-between">
                                    <a href="quotes.php" class="btn btn-outline-secondary">Cancel</a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-paper-plane me-1"></i>
                                        Submit Quote
                                    </button>
                                </div>
                            </form>
                            <?php else: ?>
                            <!-- Read-only display of quote details -->
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Quote Price (SAR)</label>
                                        <div class="p-2 bg-opacity-10 bg-primary rounded">
                                            <img src="../sar/sar.png" alt="" class="currency-icon">
                                            <?php echo number_format((float)$quoteData['price'], 2); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Estimated Time</label>
                                        <div class="p-2 bg-opacity-10 bg-primary rounded">
                                            <?php echo htmlspecialchars($quoteData['estimated_time']); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Quote Description</label>
                                <div class="p-2 bg-opacity-10 bg-primary rounded">
                                    <?php echo nl2br(htmlspecialchars($quoteData['description'])); ?>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Warranty</label>
                                <div class="p-2 bg-opacity-10 bg-primary rounded">
                                    <?php echo htmlspecialchars($quoteData['warranty']); ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($quoteData['parts_needed'])): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Parts Needed</label>
                                <div class="p-2 bg-opacity-10 bg-primary rounded">
                                    <?php echo nl2br(htmlspecialchars($quoteData['parts_needed'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($quoteData['notes'])): ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Additional Notes</label>
                                <div class="p-2 bg-opacity-10 bg-primary rounded">
                                    <?php echo nl2br(htmlspecialchars($quoteData['notes'])); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Once a quote is submitted, its details cannot be modified. This ensures transparency and reliability for our customers.
                            </div>
                            
                            <div class="d-flex justify-content-between">
                                <a href="quotes.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Back to Quotes
                                </a>
                                
                                <?php if(isset($quoteData['status']) && $quoteData['status'] === 'accepted'): ?>
                                <div>
                                    <form action="quote-detail.php?id=<?php echo $requestId; ?>" method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to mark this repair as completed?');">
                                        <input type="hidden" name="action" value="update_quote_status">
                                        <input type="hidden" name="status" value="completed">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <button type="submit" class="btn btn-success">
                                            <i class="fas fa-check-circle me-1"></i> Mark as Completed
                                        </button>
                                    </form>
                                    
                                    <form action="quote-detail.php?id=<?php echo $requestId; ?>" method="post" class="d-inline ms-2" onsubmit="return confirm('Are you sure you want to cancel this repair? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="update_quote_status">
                                        <input type="hidden" name="status" value="cancelled">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <button type="submit" class="btn btn-danger">
                                            <i class="fas fa-times-circle me-1"></i> Cancel Repair
                                        </button>
                                    </form>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column: Customer Info and Quotation Summary -->
                <div class="col-lg-4">
                    <!-- Customer Information Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-user me-2"></i>
                                Customer Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-4">
                                <?php if (!empty($customerData['profile_image'])): ?>
                                    <div class="customer-avatar">
                                        <img src="<?php echo htmlspecialchars(getCustomerProfileImage($customerData)); ?>" alt="Customer">
                                    </div>
                                <?php else: ?>
                                    <div class="avatar-text">
                                        <?php echo getCustomerInitials($customerData); ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($customerData['first_name'] . ' ' . $customerData['last_name']); ?></h5>
                                    <a href="messages.php?new=1&recipient_id=<?php echo $customerData['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-comments me-1"></i> Send Message
                                    </a>
                                </div>
                            </div>
                            
                            <ul class="info-list">
                                <li>
                                    <div class="info-label">Customer ID</div>
                                    <div class="info-value">#<?php echo $customerData['id']; ?></div>
                                </li>
                                <li>
                                    <div class="info-label">Email</div>
                                    <div class="info-value"><?php echo htmlspecialchars($customerData['email']); ?></div>
                                </li>
                                <li>
                                    <div class="info-label">Phone</div>
                                    <div class="info-value"><?php echo htmlspecialchars($customerData['phone']); ?></div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Quote Status Card (shows if quote exists) -->
                    <?php if ($quoteData): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-clipboard-check me-2"></i>
                                Your Quote
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="text-center mb-3">
                                <div class="price-display">
                                    <img src="../sar/sar.png" alt="" class="currency-icon">
                                    <?php echo number_format((float)$quoteData['price'], 2); ?>
                                </div>
                                <div class="text-muted">Estimated Time: <?php echo htmlspecialchars($quoteData['estimated_time']); ?></div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="d-flex justify-content-center">
                                    <span class="badge bg-<?php echo getStatusBadgeClass($quoteData['status']); ?> py-2 px-3 fs-6">
                                        <?php echo getQuoteStatusName($quoteData['status']); ?>
                                    </span>
                                </div>
                            </div>
                            
                            <ul class="info-list">
                                <li>
                                    <div class="info-label">Quote ID</div>
                                    <div class="info-value">#<?php echo $quoteData['id']; ?></div>
                                </li>
                                <li>
                                    <div class="info-label">Created</div>
                                    <div class="info-value"><?php echo formatDateTime($quoteData['created_at']); ?></div>
                                </li>
                                <?php if($quoteData['created_at'] != $quoteData['updated_at']): ?>
                                <li>
                                    <div class="info-label">Last Updated</div>
                                    <div class="info-value"><?php echo formatDateTime($quoteData['updated_at']); ?></div>
                                </li>
                                <?php endif; ?>
                                <li>
                                    <div class="info-label">Warranty</div>
                                    <div class="info-value"><?php echo htmlspecialchars($quoteData['warranty'] ?: 'No warranty'); ?></div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bolt me-2"></i>
                                Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="messages.php?new=1&recipient_id=<?php echo $customerData['id']; ?>" class="btn btn-outline-primary">
                                    <i class="fas fa-comments me-1"></i> Message Customer
                                </a>
                                <?php if ($quoteData && $quoteData['status'] === 'accepted'): ?>
                                <a href="bookings.php" class="btn btn-outline-success">
                                    <i class="fas fa-calendar-check me-1"></i> View Bookings
                                </a>
                                <?php endif; ?>
                                <a href="quotes.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Back to All Quotes
                                </a>
                            </div>
                        </div>
                    </div>
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
            
            // Form submission handling - show loading overlay
            document.getElementById('quoteForm').addEventListener('submit', function(event) {
                const price = document.getElementById('price').value;
                const estimatedTime = document.getElementById('estimated_time').value;
                const description = document.getElementById('description').value;
                
                if (!price || parseFloat(price) <= 0 || !estimatedTime || !description) {
                    event.preventDefault();
                    alert('Please fill in all required fields correctly.');
                    return false;
                }
                
                // Show loading overlay
                document.getElementById('loadingOverlay').style.display = 'flex';
                return true;
            });
            
            // Image preview functionality for the media gallery
            const mediaItems = document.querySelectorAll('.media-item a');
            
            // Check if any media items are present before initializing
            if (mediaItems.length > 0) {
                mediaItems.forEach(item => {
                    item.addEventListener('click', function(e) {
                        // Only handle clicks on image items
                        if (item.querySelector('img')) {
                            e.preventDefault();
                            
                            // Create modal for image preview
                            const modal = document.createElement('div');
                            modal.classList.add('modal', 'fade');
                            modal.id = 'imagePreviewModal';
                            modal.setAttribute('tabindex', '-1');
                            modal.setAttribute('aria-hidden', 'true');
                            
                            // Modal content
                            modal.innerHTML = `
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content bg-transparent border-0">
                                        <div class="modal-header border-0">
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body text-center p-0">
                                            <img src="${item.href}" class="img-fluid rounded" alt="Image Preview">
                                        </div>
                                    </div>
                                </div>
                            `;
                            
                            // Append modal to body
                            document.body.appendChild(modal);
                            
                            // Initialize and show modal
                            const imageModal = new bootstrap.Modal(modal);
                            imageModal.show();
                            
                            // Remove modal from DOM when hidden
                            modal.addEventListener('hidden.bs.modal', function() {
                                modal.remove();
                            });
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>