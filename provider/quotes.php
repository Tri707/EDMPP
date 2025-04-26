<?php
/**
 * Provider Quote Requests - FixItNow Platform
 * 
 * This file displays all quote requests for a provider, with filtering
 * and sorting options.
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

// Initialize variables with default values
$providerProfileImage = '../default.png'; // Set default image path
$providerId = 0;
$providerData = [];
$quotes = [];
$message = '';
$alertType = '';
$total_quotes = 0;
$total_pages = 1;
$current_page = 1;
$specialties = [];
$total_all_quotes = 0;
$need_response_count = 0;
$quoted_count = 0;
$accepted_count = 0;

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Pagination settings
$items_per_page = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$current_page = max(1, $current_page); // Ensure page is at least 1

// Filtering options
$status_filter = isset($_GET['status']) ? htmlspecialchars($_GET['status']) : 'all';
$device_filter = isset($_GET['device']) ? htmlspecialchars($_GET['device']) : 'all';
$search_query = isset($_GET['search']) ? trim(htmlspecialchars($_GET['search'])) : '';

// Sorting options
$sort_by = isset($_GET['sort']) ? htmlspecialchars($_GET['sort']) : 'date_desc';

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

// Parse provider specialties
if (!empty($providerData['specialties'])) {
    $specialties = explode(',', $providerData['specialties']);
    // Sanitize each specialty
    $specialties = array_map('trim', $specialties);
    $specialties = array_filter($specialties); // Remove empty values
}

// Clean up orphaned notifications (notifications pointing to non-existent quote requests)
try {
    $cleanupStmt = $pdo->prepare("DELETE FROM notifications 
                                 WHERE type = 'quote_request' 
                                 AND reference_id NOT IN (SELECT id FROM quote_requests)");
    $cleanupStmt->execute();
    $cleaned = $cleanupStmt->rowCount();
    
    if ($cleaned > 0) {
        error_log("Cleaned up $cleaned orphaned quote request notifications");
    }
} catch (PDOException $e) {
    error_log("Error cleaning up orphaned notifications: " . $e->getMessage());
}

// Create notifications for the provider based on their specialties
try {
    if (!empty($specialties)) {
        // Delete notifications that don't match provider's specialties
        $deleteNonMatchingQuery = "DELETE FROM notifications
                                   WHERE provider_id = ?
                                   AND type = 'quote_request'
                                   AND reference_id IN (
                                      SELECT qr.id FROM quote_requests qr
                                      WHERE qr.device_type NOT IN (" . implode(',', array_fill(0, count($specialties), '?')) . ")
                                   )";
        
        $deleteParams = array_merge([$providerId], $specialties);
        $deleteStmt = $pdo->prepare($deleteNonMatchingQuery);
        $deleteStmt->execute($deleteParams);
        $deleted = $deleteStmt->rowCount();
        
        if ($deleted > 0) {
            error_log("Deleted $deleted notifications that don't match provider specialties for provider ID: $providerId");
        }
        
        // Get all quote requests that match provider specialties and don't have notifications yet
        $specialtyPlaceholders = implode(',', array_fill(0, count($specialties), '?'));
        
        $findMissingNotificationsQuery = "SELECT qr.id, qr.customer_id, qr.device_type, qr.issue_description
                                         FROM quote_requests qr
                                         WHERE qr.device_type IN ($specialtyPlaceholders)
                                         AND NOT EXISTS (
                                             SELECT 1 FROM notifications n 
                                             WHERE n.provider_id = ? 
                                             AND n.type = 'quote_request' 
                                             AND n.reference_id = qr.id
                                         )";
        
        $params = array_merge($specialties, [$providerId]);
        $stmt = $pdo->prepare($findMissingNotificationsQuery);
        $stmt->execute($params);
        $missingNotifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Insert missing notifications
        if (!empty($missingNotifications)) {
            $insertNotificationQuery = "INSERT INTO notifications (
                                            provider_id, 
                                            customer_id, 
                                            device_type, 
                                            issue_description, 
                                            type, 
                                            reference_id, 
                                            message, 
                                            status
                                        ) VALUES (?, ?, ?, ?, 'quote_request', ?, ?, 'pending')";
            
            $insertStmt = $pdo->prepare($insertNotificationQuery);
            
            foreach ($missingNotifications as $request) {
                $message = 'New quote request for ' . $request['device_type'] . ' repair';
                $insertStmt->execute([
                    $providerId,
                    $request['customer_id'],
                    $request['device_type'],
                    $request['issue_description'],
                    $request['id'],
                    $message
                ]);
            }
            
            error_log("Created " . count($missingNotifications) . " new notifications for provider ID: $providerId");
        }
    }
} catch (PDOException $e) {
    error_log("Error ensuring provider notifications: " . $e->getMessage());
}

// Get proper count of quote requests that match provider specialties
try {
    // Build the count query
    $countQuery = "SELECT COUNT(*) 
                  FROM notifications n 
                  INNER JOIN quote_requests qr ON n.reference_id = qr.id 
                  WHERE n.provider_id = ? AND n.type = 'quote_request'";
    
    $countParams = [$providerId];
    
    // Apply specialty filter
    if (!empty($specialties)) {
        $placeholders = implode(',', array_fill(0, count($specialties), '?'));
        $countQuery .= " AND qr.device_type IN ($placeholders)";
        $countParams = array_merge($countParams, $specialties);
    }
    
    $stmt = $pdo->prepare($countQuery);
    $stmt->execute($countParams);
    $total_all_quotes = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error counting quote requests: " . $e->getMessage());
    $total_all_quotes = 0;
}

// Get quote requests with filtering and pagination
try {
    // Build the base query - IMPORTANT: Only show requests matching provider specialties
    $query = "SELECT 
                n.*,
                u.first_name as customer_first_name,
                u.last_name as customer_last_name,
                u.profile_image as customer_profile_image,
                qr.id as request_id,
                qr.device_type as request_device_type,
                qr.created_at as request_date,
                q.id as quote_id,
                q.price as quote_price,
                q.estimated_time,
                q.status as quote_status
              FROM notifications n 
              LEFT JOIN users u ON n.customer_id = u.id
              INNER JOIN quote_requests qr ON n.reference_id = qr.id
              LEFT JOIN quotes q ON qr.id = q.request_id AND q.provider_id = ?
              WHERE n.provider_id = ? AND n.type = 'quote_request'";
    
    $params = [$providerId, $providerId];
    
    // Apply specialty filter - ONLY show requests matching provider's specialties
    if (!empty($specialties)) {
        $placeholders = implode(',', array_fill(0, count($specialties), '?'));
        $query .= " AND qr.device_type IN ($placeholders)";
        $params = array_merge($params, $specialties);
    }
    
    // Apply filters
    if ($status_filter !== 'all') {
        switch ($status_filter) {
            case 'pending':
                // No quote created yet
                $query .= " AND q.id IS NULL";
                break;
            case 'quoted':
                // Quote created but not accepted or rejected
                $query .= " AND q.id IS NOT NULL AND q.status = 'pending'";
                break;
            case 'accepted':
                $query .= " AND q.status = 'accepted'";
                break;
            case 'rejected':
                $query .= " AND q.status = 'rejected'";
                break;
            case 'completed':
                $query .= " AND q.status = 'completed'";
                break;
        }
    }
    
    if ($device_filter !== 'all') {
        $query .= " AND qr.device_type = ?";
        $params[] = $device_filter;
    }
    
    // Apply search
    if (!empty($search_query)) {
        $query .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR n.issue_description LIKE ?)";
        $search_param = "%" . $search_query . "%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    // Apply sorting (fixed to handle NULL values properly)
    switch ($sort_by) {
        case 'date_asc':
            $query .= " ORDER BY n.created_at ASC";
            break;
        case 'date_desc':
            $query .= " ORDER BY n.created_at DESC";
            break;
        case 'price_asc':
            $query .= " ORDER BY COALESCE(q.price, 0) ASC";
            break;
        case 'price_desc':
            $query .= " ORDER BY COALESCE(q.price, 0) DESC";
            break;
        case 'status':
            $query .= " ORDER BY CASE WHEN q.status IS NULL THEN 'pending' ELSE q.status END ASC";
            break;
        case 'customer':
            $query .= " ORDER BY u.first_name ASC, u.last_name ASC";
            break;
        default:
            $query .= " ORDER BY n.created_at DESC";
    }
    
    // Count total quotes for pagination
    $count_query = preg_replace('/SELECT\s+n\.\*,.*?FROM/i', 'SELECT COUNT(*) FROM', $query);
    $count_query = preg_replace('/ORDER BY.*$/i', '', $count_query);
    
    $stmt = $pdo->prepare($count_query);
    $stmt->execute($params);
    $total_quotes = $stmt->fetchColumn();
    
    // Calculate pagination
    $total_pages = max(1, ceil($total_quotes / max(1, $items_per_page))); // Avoid division by zero
    $current_page = min($current_page, max(1, $total_pages));
    $offset = ($current_page - 1) * $items_per_page;
    
    // Get paginated results
    $query .= " LIMIT ?, ?";
    $params[] = $offset;
    $params[] = $items_per_page;
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics for the dashboard cards
    // Get count of quotes needing response
    $needResponseStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n 
                                     INNER JOIN quote_requests qr ON n.reference_id = qr.id 
                                     LEFT JOIN quotes q ON qr.id = q.request_id AND q.provider_id = ? 
                                     WHERE n.provider_id = ? AND n.type = 'quote_request' AND q.id IS NULL" . 
                                     (!empty($specialties) ? 
                                     " AND qr.device_type IN (" . implode(',', array_fill(0, count($specialties), '?')) . ")" : ""));
    
    $needResponseParams = [$providerId, $providerId];
    if (!empty($specialties)) {
        $needResponseParams = array_merge($needResponseParams, $specialties);
    }
    $needResponseStmt->execute($needResponseParams);
    $need_response_count = $needResponseStmt->fetchColumn();
    
    // Get count of quoted requests
    $quotedStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n 
                              INNER JOIN quote_requests qr ON n.reference_id = qr.id 
                              INNER JOIN quotes q ON qr.id = q.request_id AND q.provider_id = ? 
                              WHERE n.provider_id = ? AND n.type = 'quote_request' AND q.status = 'pending'" . 
                              (!empty($specialties) ? 
                              " AND qr.device_type IN (" . implode(',', array_fill(0, count($specialties), '?')) . ")" : ""));
    
    $quotedParams = [$providerId, $providerId];
    if (!empty($specialties)) {
        $quotedParams = array_merge($quotedParams, $specialties);
    }
    $quotedStmt->execute($quotedParams);
    $quoted_count = $quotedStmt->fetchColumn();
    
    // Get count of accepted quotes
    $acceptedStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n 
                                INNER JOIN quote_requests qr ON n.reference_id = qr.id 
                                INNER JOIN quotes q ON qr.id = q.request_id AND q.provider_id = ? 
                                WHERE n.provider_id = ? AND n.type = 'quote_request' AND q.status = 'accepted'" . 
                                (!empty($specialties) ? 
                                " AND qr.device_type IN (" . implode(',', array_fill(0, count($specialties), '?')) . ")" : ""));
    
    $acceptedParams = [$providerId, $providerId];
    if (!empty($specialties)) {
        $acceptedParams = array_merge($acceptedParams, $specialties);
    }
    $acceptedStmt->execute($acceptedParams);
    $accepted_count = $acceptedStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Database error fetching quotes: " . $e->getMessage());
    $message = 'An error occurred while fetching quote requests. Please try again later.';
    $alertType = 'danger';
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed");
        $message = 'Security validation failed. Please try again.';
        $alertType = 'danger';
    } else {
        // Handle quote submission
        if (isset($_POST['action']) && $_POST['action'] === 'submit_quote') {
            try {
                // Validate and sanitize inputs
                $request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
                if ($request_id <= 0) {
                    throw new Exception('Invalid request ID.');
                }
                
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
                
                $warranty = isset($_POST['warranty']) ? trim($_POST['warranty']) : 'Standard warranty';
                $warranty = htmlspecialchars($warranty);
                
                $parts_needed = isset($_POST['parts_needed']) ? trim($_POST['parts_needed']) : '';
                $parts_needed = htmlspecialchars($parts_needed);
                
                $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
                $notes = htmlspecialchars($notes);
                
                // Verify that the quote request exists
                $requestCheckStmt = $pdo->prepare("SELECT id, device_type FROM quote_requests WHERE id = ?");
                $requestCheckStmt->execute([$request_id]);
                $quoteRequest = $requestCheckStmt->fetch(PDO::FETCH_ASSOC);
                if (!$quoteRequest) {
                    throw new Exception('The quote request no longer exists.');
                }
                
                // Check if it's within provider's specialties
                if (!empty($specialties) && !in_array($quoteRequest['device_type'], $specialties)) {
                    throw new Exception('This device type is not within your specialties.');
                }
                
                // Check if quote already exists
                $checkStmt = $pdo->prepare("SELECT id FROM quotes WHERE request_id = ? AND provider_id = ?");
                $checkStmt->execute([$request_id, $providerId]);
                $existingQuote = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existingQuote) {
                    // Update existing quote
                    $updateStmt = $pdo->prepare("UPDATE quotes SET 
                                                price = ?, 
                                                estimated_time = ?, 
                                                description = ?,
                                                warranty = ?,
                                                parts_needed = ?,
                                                notes = ?,
                                                updated_at = CURRENT_TIMESTAMP
                                              WHERE id = ?");
                    $updateStmt->execute([$price, $estimated_time, $description, $warranty, $parts_needed, $notes, $existingQuote['id']]);
                    
                    $message = 'Quote has been updated successfully!';
                } else {
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
                    $insertStmt->execute([$request_id, $providerId, $userId, $price, $estimated_time, $description, $warranty, $parts_needed, $notes]);
                    
                    // Update quote_requests table to 'quoted' status
                    $updateRequestStmt = $pdo->prepare("UPDATE quote_requests SET status = 'quoted' WHERE id = ?");
                    $updateRequestStmt->execute([$request_id]);
                    
                    $message = 'Quote has been submitted successfully!';
                }
                
                $alertType = 'success';
                
                // Refresh the page to show updated data
                header("Location: quotes.php?status=$status_filter&device=$device_filter&search=" . urlencode($search_query) . "&sort=$sort_by&page=$current_page&success=1");
                exit;
                
            } catch (Exception $e) {
                error_log("Error submitting quote: " . $e->getMessage());
                $message = 'An error occurred: ' . $e->getMessage();
                $alertType = 'danger';
            }
        }
    }
}

// Show success message if redirected after successful update
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Quote has been submitted successfully!';
    $alertType = 'success';
}

// Helper function to format date and time
function formatDateTime($date) {
    return date('D, M j, Y - g:i A', strtotime($date));
}

// Get status badge class
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
        case 'rejected':
            return 'danger';
        default:
            return 'secondary';
    }
}

// Helper function to generate pagination URL
function getPaginationUrl($page) {
    global $status_filter, $device_filter, $search_query, $sort_by;
    return "quotes.php?status=" . urlencode($status_filter) . "&device=" . urlencode($device_filter) . "&search=" . urlencode($search_query) . "&sort=" . urlencode($sort_by) . "&page=" . (int)$page;
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

// Get quote status display name
function getQuoteStatusName($quote) {
    if (empty($quote['quote_id'])) {
        return 'Pending Response';
    } else {
        switch ($quote['quote_status']) {
            case 'pending':
                return 'Quote Submitted';
            case 'accepted':
                return 'Quote Accepted';
            case 'rejected':
                return 'Quote Rejected';
            case 'completed':
                return 'Repair Completed';
            default:
                return 'Unknown';
        }
    }
}

// Get quote status class
function getQuoteStatusClass($quote) {
    if (empty($quote['quote_id'])) {
        return 'warning';
    } else {
        switch ($quote['quote_status']) {
            case 'pending':
                return 'info';
            case 'accepted':
                return 'primary';
            case 'rejected':
                return 'danger';
            case 'completed':
                return 'success';
            default:
                return 'secondary';
        }
    }
}

// Function to safely get quote request ID
function getRequestId($quote) {
    if (isset($quote['request_id']) && !empty($quote['request_id'])) {
        return (int)$quote['request_id'];
    } elseif (isset($quote['reference_id']) && !empty($quote['reference_id'])) {
        return (int)$quote['reference_id'];
    } else {
        error_log("Error: Missing both request_id and reference_id in quote data");
        return 0; // Return 0 if both are missing, though this should be caught elsewhere
    }
}

// Function to check if a device type is within provider specialties
function isInSpecialties($deviceType, $specialties) {
    if (empty($specialties)) return false;
    return in_array($deviceType, $specialties);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote Requests - FixItNow</title>
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
        
        /* Quote item styles */
        .quote-item {
            transition: all 0.2s ease;
            border-radius: 0.5rem;
            position: relative;
        }
        
        .quote-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .quote-item .badge {
            font-size: 0.8rem;
        }
        
        .customer-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
        }
        
        .customer-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .avatar-text {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            font-weight: 700;
        }
        
        /* Filter and search styles */
        .filter-card {
            background-color: var(--card-bg);
            padding: 1.5rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
        }
        
        .filter-card .form-select,
        .filter-card .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        /* Responsive table */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        /* Form controls for dark theme */
        [data-bs-theme="dark"] .form-control, 
        [data-bs-theme="dark"] .form-select {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
        }
        
        [data-bs-theme="dark"] .form-control:focus, 
        [data-bs-theme="dark"] .form-select:focus {
            background-color: var(--input-bg);
            color: var(--text-color);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(166, 135, 255, 0.25);
        }
        
        /* Status pills */
        .status-pill {
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
            font-size: 0.75rem;
        }
        
        /* Price display */
        .price-display {
            font-weight: 700;
        }
        
        .currency-icon {
            vertical-align: middle;
            margin-right: 2px;
            height: 14px;
        }
        
        /* Pagination custom styles */
        .pagination .page-link {
            color: var(--primary-color);
            background-color: var(--card-bg);
            border-color: var(--border-color);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
        }
        
        .pagination .page-link:hover {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
        }
        
        /* Loading overlay */
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

        /* Device type badge */
        .device-type-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
            font-size: 0.75rem;
            display: inline-flex;
            align-items: center;
        }
        
        .device-type-badge i {
            margin-right: 5px;
        }

        /* Device specialty badge */
        .specialty-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 50rem;
            font-weight: 600;
            font-size: 0.75rem;
            margin-left: 5px;
        }
        
        /* Issue description truncate */
        .issue-description {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        /* My Specialties pills */
        .specialty-pill {
            font-size: 0.85rem;
            padding: 0.25rem 0.6rem;
            margin-right: 0.25rem;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .content-area {
                padding: 1rem;
            }
            
            .d-actions {
                flex-direction: column;
            }
            
            .d-actions .btn {
                margin-bottom: 0.5rem;
            }
        }

        /* My Specialties header */
        .my-specialties {
            display: inline-flex;
            align-items: center;
            background-color: rgba(0,0,0,0.1);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            margin-right: 1rem;
        }

        .my-specialties span {
            font-weight: 600;
            margin-right: 0.5rem;
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading quote requests...</p>
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
                    <h2 class="page-title">Quote Requests</h2>
                    <p class="text-muted">Manage customer repair quote requests and submit your quotes</p>
                </div>
                <div class="d-flex align-items-center">
                    <div class="my-specialties">
                        <span>My Specialties:</span>
                        <?php if (!empty($specialties)): ?>
                            <?php foreach ($specialties as $specialty): ?>
                                <span class="badge text-bg-primary specialty-pill"><?php echo ucfirst(htmlspecialchars($specialty)); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="badge text-bg-secondary specialty-pill">None set</span>
                        <?php endif; ?>
                    </div>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Dashboard
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
            
            <!-- Stats Overview -->
            <div class="row mb-4">
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4">
                                <?php echo $total_all_quotes; ?>
                            </div>
                            <div class="text-muted">Total Requests</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-warning">
                                <?php echo $need_response_count; ?>
                            </div>
                            <div class="text-muted">Need Response</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-info">
                                <?php echo $quoted_count; ?>
                            </div>
                            <div class="text-muted">Quotes Sent</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="display-4 text-success">
                                <?php echo $accepted_count; ?>
                            </div>
                            <div class="text-muted">Accepted</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filters and Search -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Quote Requests</h5>
                </div>
                <div class="card-body">
                    <form action="quotes.php" method="get" class="row g-3">
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Need Response</option>
                                <option value="quoted" <?php echo $status_filter === 'quoted' ? 'selected' : ''; ?>>Quote Submitted</option>
                                <option value="accepted" <?php echo $status_filter === 'accepted' ? 'selected' : ''; ?>>Accepted</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="device" class="form-label">Device Type</label>
                            <select class="form-select" id="device" name="device">
                                <option value="all" <?php echo $device_filter === 'all' ? 'selected' : ''; ?>>All Devices</option>
                                <?php foreach ($specialties as $specialty): ?>
                                <option value="<?php echo htmlspecialchars($specialty); ?>" <?php echo $device_filter === $specialty ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars($specialty)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="sort" class="form-label">Sort By</label>
                            <select class="form-select" id="sort" name="sort">
                                <option value="date_desc" <?php echo $sort_by === 'date_desc' ? 'selected' : ''; ?>>Date (Newest First)</option>
                                <option value="date_asc" <?php echo $sort_by === 'date_asc' ? 'selected' : ''; ?>>Date (Oldest First)</option>
                                <option value="price_desc" <?php echo $sort_by === 'price_desc' ? 'selected' : ''; ?>>Price (Highest First)</option>
                                <option value="price_asc" <?php echo $sort_by === 'price_asc' ? 'selected' : ''; ?>>Price (Lowest First)</option>
                                <option value="status" <?php echo $sort_by === 'status' ? 'selected' : ''; ?>>Status</option>
                                <option value="customer" <?php echo $sort_by === 'customer' ? 'selected' : ''; ?>>Customer Name</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="search" class="form-label">Search</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="search" name="search" placeholder="Customer name, issue..." value="<?php echo htmlspecialchars($search_query); ?>">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quote Requests List -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Quote Requests (<?php echo $total_all_quotes; ?>)
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($quotes)): ?>
                        <div class="text-center py-5">
                            <div class="text-muted mb-3">
                                <i class="fas fa-file-invoice fa-4x"></i>
                            </div>
                            <h5>No quote requests found for your specialties</h5>
                            <p>You will receive quote requests that match your specialties: 
                                <?php if (!empty($specialties)): ?>
                                    <?php echo implode(', ', array_map('ucfirst', $specialties)); ?>
                                <?php else: ?>
                                    <span class="text-danger">No specialties set. Please update your profile.</span>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Customer</th>
                                        <th>Device</th>
                                        <th>Issue</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Quote</th>
                                        <th width="100">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $quote): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="customer-avatar me-2">
                                                        <?php if (!empty($quote['customer_profile_image'])): ?>
                                                            <?php
                                                            if (preg_match('/^https?:\/\//i', $quote['customer_profile_image'])) {
                                                                $imagePath = $quote['customer_profile_image'];
                                                            } else {
                                                                $imagePath = '../profile_images/' . basename($quote['customer_profile_image']);
                                                                if (!file_exists($imagePath) || !is_readable($imagePath)) {
                                                                    $imagePath = '../default.png';
                                                                }
                                                            }
                                                            ?>
                                                            <img src="<?php echo htmlspecialchars($imagePath); ?>" alt="Customer">
                                                        <?php else: ?>
                                                            <div class="avatar-text">
                                                                <?php 
                                                                $initials = '';
                                                                if (!empty($quote['customer_first_name'])) {
                                                                    $initials .= strtoupper(substr($quote['customer_first_name'], 0, 1));
                                                                }
                                                                if (!empty($quote['customer_last_name'])) {
                                                                    $initials .= strtoupper(substr($quote['customer_last_name'], 0, 1));
                                                                }
                                                                echo $initials ?: 'C';
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <div class="fw-bold">
                                                            <?php 
                                                            $customerName = trim($quote['customer_first_name'] . ' ' . $quote['customer_last_name']);
                                                            echo htmlspecialchars($customerName ?: 'Unknown Customer'); 
                                                            ?>
                                                        </div>
                                                        <div class="small text-muted">ID: <?php echo $quote['customer_id']; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                $deviceType = isset($quote['request_device_type']) ? $quote['request_device_type'] : $quote['device_type'];
                                                ?>
                                                <div>
                                                    <span class="device-type-badge bg-primary bg-opacity-10 text-primary">
                                                        <?php echo getDeviceIcon($deviceType); ?>
                                                        <?php echo ucfirst(htmlspecialchars($deviceType)); ?>
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="issue-description" title="<?php echo htmlspecialchars($quote['issue_description']); ?>">
                                                    <?php echo htmlspecialchars($quote['issue_description']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php echo formatDateTime($quote['created_at']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo getQuoteStatusClass($quote); ?> status-pill">
                                                    <?php echo getQuoteStatusName($quote); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (!empty($quote['quote_id'])): ?>
                                                    <div class="price-display">
                                                        <img src="../assets/images/sar.png" alt="" class="currency-icon">
                                                        <?php echo number_format((float)$quote['quote_price'], 2); ?>
                                                    </div>
                                                    <div class="small text-muted">
                                                        Time: <?php echo htmlspecialchars($quote['estimated_time']); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary status-pill">Not Quoted</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <?php 
                                                    $requestId = getRequestId($quote);
                                                    if ($requestId > 0): 
                                                    ?>
                                                    <a href="quote-detail.php?id=<?php echo $requestId; ?>" class="btn btn-sm btn-primary" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <span class="visually-hidden">Toggle Dropdown</span>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <a class="dropdown-item" href="quote-detail.php?id=<?php echo $requestId; ?>">
                                                                <i class="fas fa-eye text-primary me-2"></i> View Details
                                                            </a>
                                                        </li>
                                                    <?php else: ?>
                                                    <button type="button" class="btn btn-sm btn-primary disabled" title="View Details">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <span class="visually-hidden">Toggle Dropdown</span>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li>
                                                            <span class="dropdown-item text-muted">
                                                                <i class="fas fa-exclamation-circle text-warning me-2"></i> Quote ID not available
                                                            </span>
                                                        </li>
                                                    <?php endif; ?>
                                                        <li>
                                                            <button type="button" class="dropdown-item" onclick="openQuoteModal(<?php echo getRequestId($quote); ?>, '<?php echo addslashes(htmlspecialchars($deviceType)); ?>', '<?php echo addslashes(htmlspecialchars($quote['issue_description'])); ?>', <?php echo (!empty($quote['quote_id']) ? $quote['quote_id'] : 'null'); ?>, <?php echo (!empty($quote['quote_price']) ? $quote['quote_price'] : 0); ?>)">
                                                                <i class="fas fa-file-invoice-dollar text-success me-2"></i> <?php echo empty($quote['quote_id']) ? 'Submit Quote' : 'Edit Quote'; ?>
                                                            </button>
                                                        </li>
                                                        <li>
                                                            <a class="dropdown-item" href="messages.php?customer_id=<?php echo $quote['customer_id']; ?>">
                                                                <i class="fas fa-comments text-info me-2"></i> Message Customer
                                                            </a>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer">
                    <nav aria-label="Quote requests pagination">
                        <ul class="pagination justify-content-center mb-0">
                            <!-- First Page and Previous -->
                            <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getPaginationUrl(1); ?>" aria-label="First">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <li class="page-item <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $current_page > 1 ? getPaginationUrl($current_page - 1) : '#'; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&lsaquo;</span>
                                </a>
                            </li>
                            
                            <?php 
                            // Calculate which page numbers to show
                            $total_visible_pages = 5; // Number of page links to show
                            
                            if ($total_pages <= $total_visible_pages) {
                                // If we have fewer pages than the limit, show all pages
                                $start_page = 1;
                                $end_page = $total_pages;
                            } else {
                                // Calculate start and end pages
                                $half = floor($total_visible_pages / 2);
                                
                                if ($current_page <= $half + 1) {
                                    // Near the start
                                    $start_page = 1;
                                    $end_page = $total_visible_pages;
                                } elseif ($current_page >= $total_pages - $half) {
                                    // Near the end
                                    $start_page = $total_pages - $total_visible_pages + 1;
                                    $end_page = $total_pages;
                                } else {
                                    // In the middle
                                    $start_page = $current_page - $half;
                                    $end_page = $current_page + $half;
                                }
                            }
                            
                            // Show page numbers
                            for ($i = $start_page; $i <= $end_page; $i++): 
                            ?>
                                <li class="page-item <?php echo $i === $current_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo getPaginationUrl($i); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next and Last Page -->
                            <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo $current_page < $total_pages ? getPaginationUrl($current_page + 1) : '#'; ?>" aria-label="Next">
                                    <span aria-hidden="true">&rsaquo;</span>
                                </a>
                            </li>
                            <li class="page-item <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo getPaginationUrl($total_pages); ?>" aria-label="Last">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quote Modal -->
    <div class="modal fade" id="quoteModal" tabindex="-1" aria-labelledby="quoteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="quoteModalLabel">Submit Quote</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="quotes.php" method="post" id="quoteForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="submit_quote">
                        <input type="hidden" name="request_id" id="requestId">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Device Type</label>
                            <input type="text" class="form-control" id="deviceType" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Issue Description</label>
                            <textarea class="form-control" id="issueDescription" rows="3" readonly></textarea>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="price" class="form-label">Quote Price (SAR) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text"><img src="../assets/images/sar.png" alt="" class="currency-icon"></span>
                                    <input type="number" class="form-control" id="price" name="price" min="0.01" step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="estimated_time" class="form-label">Estimated Time <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="estimated_time" name="estimated_time" placeholder="e.g., 2-3 days, 1 hour, etc." required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Quote Description <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="description" name="description" rows="4" required></textarea>
                            <div class="form-text">Provide a detailed description of the repair process, what's included, etc.</div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="warranty" class="form-label">Warranty</label>
                            <input type="text" class="form-control" id="warranty" name="warranty" placeholder="e.g., 30 days, 3 months, etc." value="Standard warranty">
                        </div>
                        
                        <div class="mb-3">
                            <label for="parts_needed" class="form-label">Parts Needed</label>
                            <textarea class="form-control" id="parts_needed" name="parts_needed" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notes" class="form-label">Additional Notes</label>
                            <textarea class="form-control" id="notes" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Quote</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Hide loading overlay when page is loaded
            document.getElementById('loadingOverlay').style.display = 'none';
            
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
            
            // Auto-submit the form when filters change
            document.getElementById('status').addEventListener('change', function() {
                this.form.submit();
            });
            
            document.getElementById('device').addEventListener('change', function() {
                this.form.submit();
            });
            
            document.getElementById('sort').addEventListener('change', function() {
                this.form.submit();
            });
        });
        
        // Quote modal functionality
        function openQuoteModal(requestId, deviceType, issueDescription, quoteId, price) {
            if (!requestId || requestId <= 0) {
                alert('Invalid request ID. Cannot create quote.');
                return;
            }
            
            document.getElementById('requestId').value = requestId;
            document.getElementById('deviceType').value = deviceType;
            document.getElementById('issueDescription').value = issueDescription;
            
            // Set form title based on whether we're creating or editing
            document.getElementById('quoteModalLabel').textContent = quoteId ? 'Edit Quote' : 'Submit Quote';
            
            // Set form values if editing an existing quote
            if (quoteId) {
                // Set the price we know
                document.getElementById('price').value = price > 0 ? price : '';
                
                // For other quote details - try to fetch them via AJAX
                const csrfToken = '<?php echo $csrf_token; ?>';
                
                // Show loading indicator
                document.getElementById('loadingOverlay').style.display = 'flex';
                
                fetch('ajax/get_quote.php?id=' + encodeURIComponent(quoteId) + '&csrf_token=' + encodeURIComponent(csrfToken))
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        // Hide loading indicator
                        document.getElementById('loadingOverlay').style.display = 'none';
                        
                        if (data.error) {
                            throw new Error(data.error);
                        }
                        
                        document.getElementById('estimated_time').value = data.estimated_time || '';
                        document.getElementById('description').value = data.description || '';
                        document.getElementById('warranty').value = data.warranty || 'Standard warranty';
                        document.getElementById('parts_needed').value = data.parts_needed || '';
                        document.getElementById('notes').value = data.notes || '';
                    })
                    .catch(error => {
                        // Hide loading indicator
                        document.getElementById('loadingOverlay').style.display = 'none';
                        
                        console.error('Error fetching quote details:', error);
                        // Set default values if the AJAX request fails
                        document.getElementById('estimated_time').value = '';
                        document.getElementById('description').value = '';
                        document.getElementById('warranty').value = 'Standard warranty';
                        document.getElementById('parts_needed').value = '';
                        document.getElementById('notes').value = '';
                        // Alert the user
                        alert('Could not load quote details: ' + error.message + '. Please try again.');
                    });
            } else {
                // Clear form for new quote
                document.getElementById('price').value = '';
                document.getElementById('estimated_time').value = '';
                document.getElementById('description').value = '';
                document.getElementById('warranty').value = 'Standard warranty';
                document.getElementById('parts_needed').value = '';
                document.getElementById('notes').value = '';
            }
            
            // Show the modal
            try {
                const quoteModal = new bootstrap.Modal(document.getElementById('quoteModal'));
                quoteModal.show();
            } catch (error) {
                console.error('Error showing modal:', error);
                alert('Could not open the quote form. Please refresh the page and try again.');
            }
        }
        
        // Form validation
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
    </script>
</body>
</html>