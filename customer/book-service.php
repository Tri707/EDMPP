<?php
// Error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    header('Location: ../login.php?redirect=customer/book-service');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$userData = [];
$userProfileImage = '../default.png';
$userInitials = 'CN'; // Default initials
$services = [];
$serviceCategories = [];
$providers = [];
$selectedServiceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
$selectedProviderId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
$selectedDate = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
$selectedTime = isset($_POST['booking_time']) ? $_POST['booking_time'] : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$errorMessage = '';
$successMessage = '';
$notificationCount = 0;
$recentNotifications = [];

// Check for flash messages from previous redirects
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Handle AJAX request for providers
    if ($_POST['action'] === 'get_providers' && isset($_POST['service_id'])) {
        $serviceId = (int)$_POST['service_id'];
        $providers = [];
        
        try {
            $providerQuery = "
                SELECT p.id, u.first_name, u.last_name, p.hourly_rate, p.is_verified, p.experience,
                      (SELECT AVG(rating) FROM reviews r WHERE r.provider_id = p.id) as avg_rating
                FROM providers p
                JOIN users u ON p.user_id = u.id
                JOIN services s ON s.provider_id = p.id
                WHERE s.id = ? AND s.is_active = 1 AND p.is_verified = 1 AND u.status = 'active'
                GROUP BY p.id
                ORDER BY avg_rating DESC, p.experience DESC
            ";
            
            $providerStmt = $conn->prepare($providerQuery);
            $providerStmt->bind_param("i", $serviceId);
            $providerStmt->execute();
            $providerResult = $providerStmt->get_result();
            
            while ($row = $providerResult->fetch_assoc()) {
                $providers[] = $row;
            }
            
            $providerStmt->close();
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($providers);
            exit;
        } catch (Exception $e) {
            // Return error
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Handle AJAX request for time slots
    if ($_POST['action'] === 'get_slots' && isset($_POST['provider_id']) && isset($_POST['date'])) {
        $providerId = (int)$_POST['provider_id'];
        $date = $_POST['date'];
        $timeSlots = [];
        
        try {
            $slotsQuery = "
                SELECT 
                    s.start_time, 
                    s.end_time,
                    COALESCE(COUNT(b.id), 0) as booked_count,
                    s.max_appointments
                FROM schedule_slots s
                LEFT JOIN bookings b ON 
                    b.provider_id = s.provider_id AND 
                    b.booking_date = ? AND 
                    (b.booking_time BETWEEN s.start_time AND s.end_time) AND
                    b.status IN ('pending', 'confirmed')
                WHERE s.provider_id = ? AND s.date = ?
                GROUP BY s.id, s.start_time, s.end_time
                HAVING booked_count < max_appointments
                ORDER BY s.start_time
            ";
            
            $slotsStmt = $conn->prepare($slotsQuery);
            $slotsStmt->bind_param("sis", $date, $providerId, $date);
            $slotsStmt->execute();
            $slotsResult = $slotsStmt->get_result();
            
            // If no slots found, generate some dummy slots for demo
            if ($slotsResult->num_rows == 0) {
                // Create demo time slots from 9 AM to 5 PM
                $startTime = new DateTime('09:00:00');
                $endTime = new DateTime('17:00:00');
                $interval = new DateInterval('PT1H'); // 1 hour
                
                $period = new DatePeriod($startTime, $interval, $endTime);
                
                foreach ($period as $time) {
                    $timeSlots[] = [
                        'time' => $time->format('H:i:s'),
                        'formatted_time' => $time->format('h:i A')
                    ];
                }
            } else {
                while ($row = $slotsResult->fetch_assoc()) {
                    // Generate available time slots in 30-minute increments
                    $start = new DateTime($row['start_time']);
                    $end = new DateTime($row['end_time']);
                    $interval = new DateInterval('PT30M'); // 30 minutes
                    
                    $period = new DatePeriod($start, $interval, $end);
                    
                    foreach ($period as $time) {
                        $timeSlot = $time->format('H:i:s');
                        $timeSlots[] = [
                            'time' => $timeSlot,
                            'formatted_time' => $time->format('h:i A')
                        ];
                    }
                }
            }
            
            $slotsStmt->close();
            
            // Return JSON response
            header('Content-Type: application/json');
            echo json_encode($timeSlots);
            exit;
        } catch (Exception $e) {
            // Return error
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    }
    
    // Handle booking creation
    if ($_POST['action'] === 'create_booking') {
        $selectedServiceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
        $selectedProviderId = isset($_POST['provider_id']) ? (int)$_POST['provider_id'] : 0;
        $selectedDate = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
        $selectedTime = isset($_POST['booking_time']) ? $_POST['booking_time'] : '';
        $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
        $totalPrice = 0;
        
        // Validate inputs
        $errors = [];
        
        if ($selectedServiceId <= 0) {
            $errors[] = "Please select a service";
        }
        
        if ($selectedProviderId <= 0) {
            $errors[] = "Please select a provider";
        }
        
        if (empty($selectedDate)) {
            $errors[] = "Please select a date";
        } else {
            // Validate date format and check if it's in the future
            try {
                $bookingDate = new DateTime($selectedDate);
                $today = new DateTime();
                $today->setTime(0, 0, 0); // Set to start of day for comparison
                if ($bookingDate < $today) {
                    $errors[] = "Booking date must be in the future";
                }
            } catch (Exception $e) {
                $errors[] = "Invalid date format";
            }
        }
        
        if (empty($selectedTime)) {
            $errors[] = "Please select a time slot";
        }
        
        // If no errors, get service price and create booking
        if (empty($errors)) {
            try {
                // Get service price
                $priceStmt = $conn->prepare("SELECT price FROM services WHERE id = ? AND is_active = 1");
                $priceStmt->bind_param("i", $selectedServiceId);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                $serviceData = $priceResult->fetch_assoc();
                $priceStmt->close();
                
                if (!$serviceData) {
                    throw new Exception("Selected service is no longer available");
                }
                
                $totalPrice = (float)$serviceData['price'];
                
                // Start transaction
                $conn->begin_transaction();
                
                // Create booking
                $bookingStmt = $conn->prepare("
                    INSERT INTO bookings (
                        customer_id, provider_id, service_id, booking_date, 
                        booking_time, status, total_price, notes
                    ) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)
                ");
                
                $bookingStmt->bind_param(
                    "iiissds", 
                    $userId, $selectedProviderId, $selectedServiceId, 
                    $selectedDate, $selectedTime, $totalPrice, $notes
                );
                
                $bookingStmt->execute();
                $bookingId = $conn->insert_id;
                $bookingStmt->close();
                
                if (!$bookingId) {
                    throw new Exception("Failed to create booking");
                }
                
                // Create booking history
                $historyStmt = $conn->prepare("
                    INSERT INTO booking_status_history (
                        booking_id, status, created_by, user_id, notes
                    ) VALUES (?, 'pending', 'customer', ?, 'Booking created')
                ");
                
                $historyStmt->bind_param("ii", $bookingId, $userId);
                $historyStmt->execute();
                $historyStmt->close();
                
                // Commit transaction
                $conn->commit();
                
                // Set success message
                $successMessage = "Booking created successfully! Your booking ID is #" . $bookingId;
                
                // Reset form
                $selectedServiceId = 0;
                $selectedProviderId = 0;
                $selectedDate = '';
                $selectedTime = '';
                $notes = '';
                
            } catch (Exception $e) {
                // Rollback transaction
                $conn->rollback();
                $errorMessage = $e->getMessage();
            }
        } else {
            $errorMessage = implode("<br>", $errors);
        }
    }
}

// Get user data and other necessary information
try {
    // Get user data
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'customer'");
    $stmt->bind_param("i", $userId);
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
    
    // Get unread notifications count
    $notifCountStmt = $conn->prepare("
        SELECT COUNT(*) as count FROM quote_notifications
        WHERE recipient_id = ? AND is_read = 0
    ");
    $notifCountStmt->bind_param("i", $userId);
    $notifCountStmt->execute();
    $notifCountResult = $notifCountStmt->get_result();
    $notifCountData = $notifCountResult->fetch_assoc();
    $notificationCount = $notifCountData ? (int)$notifCountData['count'] : 0;
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
    
    while ($row = $recentNotifResult->fetch_assoc()) {
        $recentNotifications[] = $row;
    }
    $recentNotifStmt->close();
    
    // Get available services
    $serviceQuery = "
        SELECT s.*, p.id as provider_id, CONCAT(u.first_name, ' ', u.last_name) as provider_name
        FROM services s
        JOIN providers p ON s.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE s.is_active = 1 AND u.status = 'active'
        ORDER BY s.category, s.name
    ";
    
    $serviceStmt = $conn->prepare($serviceQuery);
    $serviceStmt->execute();
    $serviceResult = $serviceStmt->get_result();
    
    $services = [];
    $serviceCategories = [];
    
    while ($row = $serviceResult->fetch_assoc()) {
        $services[] = $row;
        if (!in_array($row['category'], $serviceCategories)) {
            $serviceCategories[] = $row['category'];
        }
    }
    $serviceStmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $errorMessage = "An error occurred while fetching data. Please try again later.";
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="description" content="Book services on FixItNow service booking platform">
    <meta name="robots" content="noindex, nofollow">
    <title>Book Service - FixItNow</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Flatpickr Date Picker -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    
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
        
        /* Enhanced Header Styles (from bookings.php) */
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
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
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
        
        /* Form Styles */
        .form-label {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-control, .form-select {
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text-color);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
        }
        
        /* Service selection cards */
        .service-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            border-radius: 0.75rem;
            overflow: hidden;
        }
        
        .service-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 0.5rem 1rem var(--shadow-color);
        }
        
        .service-card.selected {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
        }
        
        .service-card .card-body {
            padding: 1.25rem;
        }
        
        .service-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }
        
        .service-provider {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
        }
        
        .service-price {
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-color);
        }
        
        .service-duration {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        /* Provider selection */
        .provider-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            padding: 1rem;
            border-radius: 0.75rem;
        }
        
        .provider-card:hover {
            background-color: rgba(121, 82, 179, 0.05);
            transform: translateY(-3px);
        }
        
        .provider-card.selected {
            border-color: var(--primary-color);
            background-color: rgba(121, 82, 179, 0.1);
        }
        
        .provider-info {
            display: flex;
            align-items: center;
        }
        
        .provider-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
        }
        
        .provider-details {
            flex: 1;
        }
        
        .provider-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .provider-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .provider-rating {
            color: var(--warning-color);
        }
        
        /* Time slots */
        .time-slot {
            display: inline-block;
            padding: 0.5rem 1rem;
            margin: 0.5rem;
            border-radius: 2rem;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .time-slot:hover {
            background-color: rgba(121, 82, 179, 0.05);
            border-color: var(--primary-color);
        }
        
        .time-slot.selected {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Booking steps */
        .booking-step {
            position: relative;
            padding-bottom: 2rem;
        }
        
        .booking-step:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 1.25rem;
            top: 2.5rem;
            bottom: 0;
            width: 2px;
            background-color: var(--border-color);
        }
        
        .step-number {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            margin-right: 1rem;
            position: relative;
            z-index: 2;
        }
        
        .step-content {
            margin-top: 1rem;
            margin-left: 3.5rem;
        }
        
        /* Booking summary */
        .booking-summary {
            background-color: rgba(121, 82, 179, 0.05);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .summary-title {
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--primary-color);
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .summary-label {
            font-weight: 600;
        }
        
        .summary-value {
            font-weight: 400;
        }
        
        .summary-total {
            font-weight: 700;
            color: var(--primary-color);
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
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .nav-button {
                padding: 0.5rem 0.75rem;
                font-size: 0.85rem;
            }
            
            .nav-button i {
                margin-right: 0.3rem;
            }
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 1.5rem;
            }
            
            .step-content {
                margin-left: 0;
            }
        }
        
        @media (max-width: 576px) {
            .booking-summary {
                padding: 1rem;
            }
            
            .summary-item {
                flex-direction: column;
            }
            
            .summary-value {
                margin-top: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <!-- Improved Header (from bookings.php) -->
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
                                <a href="notification.php?id=<?php echo (int)$notification['id']; ?>" class="notification-item">
                                    <div class="notification-icon <?php echo $notification['type'] === 'new_quote' ? 'success' : 'primary'; ?>">
                                        <i class="fas fa-<?php echo $notification['type'] === 'new_quote' ? 'check-circle' : 'info-circle'; ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($notification['type'] === 'new_quote' ? 'New Quote' : 'Notification', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div class="notification-text"><?php echo htmlspecialchars($notification['message'] ?? '', ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="notification-time"><?php echo timeAgo($notification['created_at'] ?? ''); ?></div>
                                    </div>
                                </a>
                                <?php endforeach; ?>
                                <div class="dropdown-divider"></div>
                                <a href="notifications.php" class="dropdown-item text-center view-all">
                                    View all notifications
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="header-icon-btn me-3" id="themeToggle" aria-label="Toggle theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div class="dropdown user-dropdown">
                        <button class="user-dropdown-toggle" type="button" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                            <div class="user-avatar"><?php echo htmlspecialchars($userInitials, ENT_QUOTES, 'UTF-8'); ?></div>
                            <i class="fas fa-chevron-down fa-xs ms-2"></i>
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
        <div class="mobile-menu d-lg-none" id="mobileMenu">
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
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
                <a href="bookings.php" class="mobile-menu-item">
                    <i class="fas fa-calendar-check"></i> My Bookings
                </a>
                <a href="book-service.php" class="mobile-menu-item active">
                    <i class="fas fa-calendar-plus"></i> Book Service
                </a>
                <a href="profile.php" class="mobile-menu-item">
                    <i class="fas fa-user"></i> My Profile
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
                        <a class="nav-link" href="bookings.php">
                            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
                            <span class="nav-text">My Bookings</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="book-service.php">
                            <span class="nav-icon"><i class="fas fa-calendar-plus" aria-hidden="true"></i></span>
                            <span class="nav-text">Book Service</span>
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
                        <a class="nav-link " href="notifications.php">
                            <span class="nav-icon"><i class="fas fa-bell" aria-hidden="true"></i></span>
                            <span class="nav-text">Notifications</span>
                            <?php if ($notificationCount > 0): ?>
                            <span class="badge bg-danger rounded-pill ms-auto"><?php echo $notificationCount; ?></span>
                            <?php endif; ?>
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
                    <li class="nav-item">
                        <a class="nav-link" href="settings.php">
                            <span class="nav-icon"><i class="fas fa-cog" aria-hidden="true"></i></span>
                            <span class="nav-text">Settings</span>
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
            <div class="mb-4">
                <h1 class="page-title">Book a Service</h1>
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
            
            <div class="card">
                <div class="card-body p-4">
                    <form id="bookingForm" method="POST" action="book-service.php">
                        <input type="hidden" name="action" value="create_booking">
                        
                        <!-- Step 1: Select Service -->
                        <div class="booking-step" id="step1">
                            <div class="d-flex align-items-center">
                                <div class="step-number">1</div>
                                <h3 class="mb-0">Select a Service</h3>
                            </div>
                            
                            <div class="step-content">
                                <div class="mb-3">
                                    <label for="serviceCategory" class="form-label">Service Category</label>
                                    <select class="form-select" id="serviceCategory">
                                        <option value="">All Categories</option>
                                        <?php foreach ($serviceCategories as $category): ?>
                                        <option value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars(ucfirst($category), ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row g-3 mt-2" id="servicesList">
                                    <?php if (empty($services)): ?>
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="fas fa-info-circle me-2"></i>No services available at the moment.
                                        </div>
                                    </div>
                                    <?php else: ?>
                                        <?php foreach ($services as $service): ?>
                                        <div class="col-md-6 col-lg-4 service-item" data-category="<?php echo htmlspecialchars($service['category'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="card service-card" data-service-id="<?php echo (int)$service['id']; ?>" data-provider-id="<?php echo (int)$service['provider_id']; ?>">
                                                <div class="card-body">
                                                    <div class="service-title"><?php echo htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <div class="service-provider">
                                                        <i class="fas fa-user-cog me-1"></i><?php echo htmlspecialchars($service['provider_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                    <div class="service-price">
                                                        <?php echo formatPrice($service['price']); ?>
                                                    </div>
                                                    <div class="service-duration">
                                                        <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars($service['duration'] ?? 60, ENT_QUOTES, 'UTF-8'); ?> minutes
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <input type="hidden" name="service_id" id="selectedServiceId" value="<?php echo (int)$selectedServiceId; ?>">
                                
                                <div class="mt-4">
                                    <button type="button" id="nextToStep2" class="btn btn-primary" disabled>
                                        Continue to Select Provider
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 2: Select Provider -->
                        <div class="booking-step" id="step2" style="display: none;">
                            <div class="d-flex align-items-center">
                                <div class="step-number">2</div>
                                <h3 class="mb-0">Select a Provider</h3>
                            </div>
                            
                            <div class="step-content">
                                <div id="providersList">
                                    <!-- Providers will be loaded here via AJAX -->
                                    <div class="text-center py-4">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading available providers...</p>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="provider_id" id="selectedProviderId" value="<?php echo (int)$selectedProviderId; ?>">
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" id="backToStep1" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" id="nextToStep3" class="btn btn-primary" disabled>
                                        Continue to Select Date & Time
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 3: Select Date and Time -->
                        <div class="booking-step" id="step3" style="display: none;">
                            <div class="d-flex align-items-center">
                                <div class="step-number">3</div>
                                <h3 class="mb-0">Select Date & Time</h3>
                            </div>
                            
                            <div class="step-content">
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <label for="bookingDate" class="form-label">Select Date</label>
                                        <input type="text" class="form-control" id="bookingDate" name="booking_date" placeholder="Select a date" readonly>
                                    </div>
                                </div>
                                
                                <div id="timeSlots" class="mb-4">
                                    <p>Please select a date to see available time slots.</p>
                                </div>
                                
                                <input type="hidden" name="booking_time" id="selectedTime" value="">
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" id="backToStep2" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="button" id="nextToStep4" class="btn btn-primary" disabled>
                                        Continue to Review
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Step 4: Review and Confirm -->
                        <div class="booking-step" id="step4" style="display: none;">
                            <div class="d-flex align-items-center">
                                <div class="step-number">4</div>
                                <h3 class="mb-0">Review and Confirm</h3>
                            </div>
                            
                            <div class="step-content">
                                <div class="booking-summary">
                                    <h4 class="summary-title">Booking Summary</h4>
                                    
                                    <div class="summary-item">
                                        <div class="summary-label">Service:</div>
                                        <div class="summary-value" id="summaryService">Not selected</div>
                                    </div>
                                    
                                    <div class="summary-item">
                                        <div class="summary-label">Provider:</div>
                                        <div class="summary-value" id="summaryProvider">Not selected</div>
                                    </div>
                                    
                                    <div class="summary-item">
                                        <div class="summary-label">Date:</div>
                                        <div class="summary-value" id="summaryDate">Not selected</div>
                                    </div>
                                    
                                    <div class="summary-item">
                                        <div class="summary-label">Time:</div>
                                        <div class="summary-value" id="summaryTime">Not selected</div>
                                    </div>
                                    
                                    <div class="summary-item">
                                        <div class="summary-label">Total Price:</div>
                                        <div class="summary-value summary-total" id="summaryPrice">-</div>
                                    </div>
                                </div>
                                
                                <div class="mb-3 mt-4">
                                    <label for="notes" class="form-label">Additional Notes</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="Any special instructions or information for the provider"><?php echo htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                                
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="termsCheck" required>
                                    <label class="form-check-label" for="termsCheck">
                                        I agree to the <a href="../terms.php" target="_blank">Terms and Conditions</a>
                                    </label>
                                </div>
                                
                                <div class="mt-4 d-flex justify-content-between">
                                    <button type="button" id="backToStep3" class="btn btn-outline-secondary">
                                        <i class="fas fa-arrow-left me-2"></i>Back
                                    </button>
                                    <button type="submit" class="btn btn-success" id="confirmBooking">
                                        <i class="fas fa-check-circle me-2"></i>Confirm Booking
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0">© 2023 FixItNow. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <ul class="footer-links d-flex flex-wrap justify-content-md-end">
                        <li><a href="../about.php">About</a></li>
                        <li><a href="../contact.php">Contact</a></li>
                        <li><a href="../privacy.php">Privacy Policy</a></li>
                        <li><a href="../terms.php">Terms of Service</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </footer>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Flatpickr -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('show');
                    const isOpen = mobileMenu.classList.contains('show');
                    mobileMenuToggle.innerHTML = isOpen ? 
                        '<i class="fas fa-times"></i>' : 
                        '<i class="fas fa-bars"></i>';
                });
                
                // Close mobile menu when clicking outside
                document.addEventListener('click', function(event) {
                    if (!mobileMenu.contains(event.target) && !mobileMenuToggle.contains(event.target)) {
                        if (mobileMenu.classList.contains('show')) {
                            mobileMenu.classList.remove('show');
                            mobileMenuToggle.innerHTML = '<i class="fas fa-bars"></i>';
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
            
            // Auto-dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                });
            }, 5000);
            
            // Service category filter
            const serviceCategorySelect = document.getElementById('serviceCategory');
            const serviceItems = document.querySelectorAll('.service-item');
            
            if (serviceCategorySelect) {
                serviceCategorySelect.addEventListener('change', function() {
                    const selectedCategory = this.value;
                    
                    serviceItems.forEach(function(item) {
                        const itemCategory = item.getAttribute('data-category');
                        
                        if (selectedCategory === '' || itemCategory === selectedCategory) {
                            item.style.display = 'block';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
            
            // Service selection
            const serviceCards = document.querySelectorAll('.service-card');
            const selectedServiceIdInput = document.getElementById('selectedServiceId');
            const nextToStep2Btn = document.getElementById('nextToStep2');
            
            serviceCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    // Remove selected class from all cards
                    serviceCards.forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                    
                    // Set selected service ID
                    const serviceId = this.getAttribute('data-service-id');
                    selectedServiceIdInput.value = serviceId;
                    
                    // Enable next button
                    nextToStep2Btn.disabled = false;
                });
            });
            
            // Navigation between steps
            const step1 = document.getElementById('step1');
            const step2 = document.getElementById('step2');
            const step3 = document.getElementById('step3');
            const step4 = document.getElementById('step4');
            
            const nextToStep2 = document.getElementById('nextToStep2');
            const backToStep1 = document.getElementById('backToStep1');
            const nextToStep3 = document.getElementById('nextToStep3');
            const backToStep2 = document.getElementById('backToStep2');
            const nextToStep4 = document.getElementById('nextToStep4');
            const backToStep3 = document.getElementById('backToStep3');
            
            // Step 1 to Step 2
            nextToStep2.addEventListener('click', function() {
                const serviceId = selectedServiceIdInput.value;
                
                if (!serviceId) {
                    alert('Please select a service');
                    return;
                }
                
                // Show loading in providers list
                document.getElementById('providersList').innerHTML = `
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading available providers...</p>
                    </div>
                `;
                
                // Fetch providers via AJAX
                fetch('book-service.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_providers&service_id=${serviceId}`
                })
                .then(response => response.json())
                .then(providers => {
                    // Clear loading spinner
                    document.getElementById('providersList').innerHTML = '';
                    
                    if (providers.length === 0) {
                        document.getElementById('providersList').innerHTML = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>No providers available for this service.
                            </div>
                        `;
                        return;
                    }
                    
                    // Add providers to the list
                    providers.forEach(provider => {
                        const avgRating = provider.avg_rating ? parseFloat(provider.avg_rating).toFixed(1) : 'New';
                        const ratingStars = provider.avg_rating ? 
                            `<i class="fas fa-star"></i> ${avgRating}` : 
                            'No ratings yet';
                        
                        const providerInitials = `${provider.first_name.charAt(0)}${provider.last_name.charAt(0)}`;
                        
                        const providerEl = document.createElement('div');
                        providerEl.classList.add('provider-card', 'mb-3');
                        providerEl.setAttribute('data-provider-id', provider.id);
                        
                        providerEl.innerHTML = `
                            <div class="provider-info">
                                <div class="provider-avatar">${providerInitials}</div>
                                <div class="provider-details">
                                    <div class="provider-name">${provider.first_name} ${provider.last_name}</div>
                                    <div class="provider-meta">
                                        <div class="provider-rating">${ratingStars}</div>
                                        <div class="provider-experience"><i class="fas fa-briefcase me-1"></i>${provider.experience || 'Not specified'}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('providersList').appendChild(providerEl);
                        
                        // Add click event to provider card
                        providerEl.addEventListener('click', function() {
                            const providerCards = document.querySelectorAll('.provider-card');
                            providerCards.forEach(card => card.classList.remove('selected'));
                            this.classList.add('selected');
                            
                            document.getElementById('selectedProviderId').value = this.getAttribute('data-provider-id');
                            document.getElementById('nextToStep3').disabled = false;
                        });
                    });
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('providersList').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>Error loading providers. Please try again.
                        </div>
                    `;
                });
                
                // Hide step 1, show step 2
                step1.style.display = 'none';
                step2.style.display = 'block';
            });
            
            // Step 2 to Step 1
            backToStep1.addEventListener('click', function() {
                step2.style.display = 'none';
                step1.style.display = 'block';
            });
            
            // Step 2 to Step 3
            nextToStep3.addEventListener('click', function() {
                const providerId = document.getElementById('selectedProviderId').value;
                
                if (!providerId) {
                    alert('Please select a provider');
                    return;
                }
                
                // Hide step 2, show step 3
                step2.style.display = 'none';
                step3.style.display = 'block';
                
                // Initialize date picker
                const today = new Date();
                const nextYear = new Date(today.getFullYear() + 1, today.getMonth(), today.getDate());
                
                flatpickr('#bookingDate', {
                    minDate: 'today',
                    maxDate: nextYear,
                    altInput: true,
                    altFormat: 'F j, Y',
                    dateFormat: 'Y-m-d',
                    disable: [
                        function(date) {
                            // Disable weekends or specific days if needed
                            // return date.getDay() === 0 || date.getDay() === 6;
                            return false;
                        }
                    ],
                    onChange: function(selectedDates, dateStr) {
                        // Clear time slots and show loading
                        document.getElementById('timeSlots').innerHTML = `
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="mt-2">Loading available time slots...</p>
                            </div>
                        `;
                        
                        // Fetch time slots via AJAX
                        fetch('book-service.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: `action=get_slots&provider_id=${providerId}&date=${dateStr}`
                        })
                        .then(response => response.json())
                        .then(slots => {
                            // Clear loading spinner
                            document.getElementById('timeSlots').innerHTML = '';
                            
                            if (slots.length === 0) {
                                document.getElementById('timeSlots').innerHTML = `
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>No time slots available for this date. Please select another date.
                                    </div>
                                `;
                                document.getElementById('nextToStep4').disabled = true;
                                return;
                            }
                            
                            // Add time slots
                            const timeSlotsContainer = document.createElement('div');
                            timeSlotsContainer.classList.add('time-slots-container');
                            
                            slots.forEach(slot => {
                                const timeSlot = document.createElement('div');
                                timeSlot.classList.add('time-slot');
                                timeSlot.textContent = slot.formatted_time;
                                timeSlot.setAttribute('data-time', slot.time);
                                
                                timeSlot.addEventListener('click', function() {
                                    const timeSlots = document.querySelectorAll('.time-slot');
                                    timeSlots.forEach(ts => ts.classList.remove('selected'));
                                    this.classList.add('selected');
                                    
                                    document.getElementById('selectedTime').value = this.getAttribute('data-time');
                                    document.getElementById('nextToStep4').disabled = false;
                                });
                                
                                timeSlotsContainer.appendChild(timeSlot);
                            });
                            
                            document.getElementById('timeSlots').appendChild(timeSlotsContainer);
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            document.getElementById('timeSlots').innerHTML = `
                                <div class="alert alert-danger">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Error loading time slots. Please try again.
                                </div>
                            `;
                        });
                    }
                });
            });
            
            // Step 3 to Step 2
            backToStep2.addEventListener('click', function() {
                step3.style.display = 'none';
                step2.style.display = 'block';
            });
            
            // Step 3 to Step 4
            nextToStep4.addEventListener('click', function() {
                const bookingDate = document.getElementById('bookingDate').value;
                const bookingTime = document.getElementById('selectedTime').value;
                
                if (!bookingDate) {
                    alert('Please select a date');
                    return;
                }
                
                if (!bookingTime) {
                    alert('Please select a time slot');
                    return;
                }
                
                // Populate summary
                const serviceCard = document.querySelector('.service-card.selected');
                const serviceName = serviceCard ? serviceCard.querySelector('.service-title').textContent : 'Not selected';
                const servicePrice = serviceCard ? serviceCard.querySelector('.service-price').innerHTML : '-';
                
                const providerCard = document.querySelector('.provider-card.selected');
                const providerName = providerCard ? providerCard.querySelector('.provider-name').textContent : 'Not selected';
                
                const formattedDate = flatpickr.formatDate(new Date(bookingDate), 'F j, Y');
                const selectedTimeSlot = document.querySelector('.time-slot.selected');
                const formattedTime = selectedTimeSlot ? selectedTimeSlot.textContent : '';
                
                document.getElementById('summaryService').textContent = serviceName;
                document.getElementById('summaryProvider').textContent = providerName;
                document.getElementById('summaryDate').textContent = formattedDate;
                document.getElementById('summaryTime').textContent = formattedTime;
                document.getElementById('summaryPrice').innerHTML = servicePrice;
                
                // Hide step 3, show step 4
                step3.style.display = 'none';
                step4.style.display = 'block';
            });
            
            // Step 4 to Step 3
            backToStep3.addEventListener('click', function() {
                step4.style.display = 'none';
                step3.style.display = 'block';
            });
            
            // Form submission validation
            document.getElementById('bookingForm').addEventListener('submit', function(e) {
                const termsCheck = document.getElementById('termsCheck');
                
                if (!termsCheck.checked) {
                    alert('Please agree to the Terms and Conditions');
                    e.preventDefault();
                    return;
                }
                
                // Additional validation if needed
                const serviceId = selectedServiceIdInput.value;
                const providerId = document.getElementById('selectedProviderId').value;
                const bookingDate = document.getElementById('bookingDate').value;
                const bookingTime = document.getElementById('selectedTime').value;
                
                if (!serviceId || !providerId || !bookingDate || !bookingTime) {
                    alert('Please complete all required fields');
                    e.preventDefault();
                    return;
                }
            });
        });
    </script>
</body>
</html>