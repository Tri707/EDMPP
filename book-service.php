<?php
// Start session securely
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userRole = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$isCustomer = $loggedIn && $userRole === 'customer';

// Redirect if not logged in as customer
if (!$isCustomer) {
    $_SESSION['error_message'] = 'You must be logged in as a customer to book a service.';
    header('Location: login.php?redirect=book-service.php');
    exit;
}

// Database connection
include 'conn.php';

// Set proper character set
$conn->set_charset("utf8mb4");

// Initialize variables
$providerId = isset($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;
$provider = null;
$services = [];
$errorMessage = '';
$successMessage = '';
$availableDates = [];
$availableTimeSlots = [];

// Security helper function for HTML output
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// Function to safely format prices with the currency image
function formatPrice($price, $currencyImgPath = 'sar/sar.png') {
    if (empty($price) || !is_numeric($price)) return 'Not set';
    
    // Use the image path for currency display
    $currencyImg = '<img src="' . h($currencyImgPath) . '" alt="SAR" class="currency-icon" width="16" height="16" style="margin-right: 4px; vertical-align: -3px;">';
    
    return $currencyImg . ' ' . number_format((float)$price, 2);
}

// Get provider details
if ($providerId > 0) {
    try {
        $providerQuery = "
            SELECT p.*, u.first_name, u.last_name, u.profile_image, 
                   COUNT(DISTINCT r.id) as review_count, 
                   AVG(r.rating) as avg_rating
            FROM providers p
            JOIN users u ON p.user_id = u.id
            LEFT JOIN reviews r ON p.id = r.provider_id
            WHERE p.id = ? AND u.status = 'active'
            GROUP BY p.id
        ";
        
        $stmt = $conn->prepare($providerQuery);
        $stmt->bind_param('i', $providerId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $provider = $result->fetch_assoc();
            
            // Get provider services
            $servicesQuery = "
                SELECT * FROM services 
                WHERE provider_id = ? 
                AND is_active = 1 
                AND deleted_by_provider = 0
                ORDER BY category, name
            ";
            
            $stmt = $conn->prepare($servicesQuery);
            $stmt->bind_param('i', $providerId);
            $stmt->execute();
            $servicesResult = $stmt->get_result();
            
            if ($servicesResult && $servicesResult->num_rows > 0) {
                while ($row = $servicesResult->fetch_assoc()) {
                    $services[] = $row;
                }
            }
            
            // Get available dates (next 30 days)
            $startDate = date('Y-m-d');
            $endDate = date('Y-m-d', strtotime('+30 days'));
            
            // First get the provider's working hours
            $workingDaysQuery = "
                SELECT day_of_week 
                FROM provider_working_hours 
                WHERE provider_id = ? 
                AND is_available = 1
                GROUP BY day_of_week
            ";
            $stmt = $conn->prepare($workingDaysQuery);
            $stmt->bind_param('i', $providerId);
            $stmt->execute();
            $workingDaysResult = $stmt->get_result();
            
            $workingDays = [];
            if ($workingDaysResult && $workingDaysResult->num_rows > 0) {
                while ($row = $workingDaysResult->fetch_assoc()) {
                    $workingDays[] = $row['day_of_week']; // 0 = Sunday, 1 = Monday, etc.
                }
            } else {
                // If no specific working days are set, assume all days are working days
                $workingDays = [0, 1, 2, 3, 4, 5, 6];
            }
            
            // Check provider schedule slots and time off
            $availableDatesQuery = "
                SELECT DISTINCT DATE(s.date) as available_date
                FROM schedule_slots s
                LEFT JOIN provider_time_off t ON 
                    (t.provider_id = s.provider_id AND 
                    s.date BETWEEN t.start_date AND t.end_date AND
                    (t.all_day = 1 OR (TIME(s.start_time) BETWEEN t.start_time AND t.end_time)))
                WHERE s.provider_id = ?
                AND s.date BETWEEN ? AND ?
                AND t.id IS NULL
                GROUP BY s.date
                HAVING COUNT(s.id) > 0
                ORDER BY s.date
            ";
            
            $stmt = $conn->prepare($availableDatesQuery);
            $stmt->bind_param('iss', $providerId, $startDate, $endDate);
            $stmt->execute();
            $datesResult = $stmt->get_result();
            
            if ($datesResult && $datesResult->num_rows > 0) {
                while ($row = $datesResult->fetch_assoc()) {
                    $availableDates[] = $row['available_date'];
                }
            }
            
            // If there are no schedule slots but the provider has working hours,
            // generate available dates based on working days
            if (empty($availableDates) && !empty($workingDays)) {
                $currentDate = new DateTime($startDate);
                $endDateObj = new DateTime($endDate);
                
                while ($currentDate <= $endDateObj) {
                    $dayOfWeek = (int)$currentDate->format('w'); // 0 (Sunday) to 6 (Saturday)
                    
                    if (in_array($dayOfWeek, $workingDays)) {
                        // Check if this date is not in provider_time_off
                        $dateStr = $currentDate->format('Y-m-d');
                        $timeOffQuery = "
                            SELECT id FROM provider_time_off 
                            WHERE provider_id = ? 
                            AND ? BETWEEN start_date AND end_date 
                            AND all_day = 1
                        ";
                        
                        $stmt = $conn->prepare($timeOffQuery);
                        $stmt->bind_param('is', $providerId, $dateStr);
                        $stmt->execute();
                        $timeOffResult = $stmt->get_result();
                        
                        if ($timeOffResult->num_rows === 0) {
                            $availableDates[] = $dateStr;
                        }
                    }
                    
                    $currentDate->modify('+1 day');
                }
            }
        } else {
            $errorMessage = 'The requested technician was not found or is no longer active.';
        }
    } catch (Exception $e) {
        error_log("Error loading provider details: " . $e->getMessage());
        $errorMessage = "An error occurred while loading technician details. Please try again later.";
    }
} else {
    $errorMessage = 'Invalid technician ID. Please select a valid technician.';
}

// Process booking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_booking'])) {
    // Validate inputs
    $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
    $bookingDate = isset($_POST['booking_date']) ? $_POST['booking_date'] : '';
    $bookingTime = isset($_POST['booking_time']) ? $_POST['booking_time'] : '';
    $notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
    
    $validationErrors = [];
    
    if ($serviceId <= 0) {
        $validationErrors[] = 'Please select a service.';
    }
    
    if (empty($bookingDate)) {
        $validationErrors[] = 'Please select a booking date.';
    }
    
    if (empty($bookingTime)) {
        $validationErrors[] = 'Please select a booking time.';
    }
    
    // If validation passes, create the booking
    if (empty($validationErrors)) {
        try {
            // Get service details for price
            $serviceQuery = "SELECT * FROM services WHERE id = ? AND provider_id = ? AND is_active = 1";
            $stmt = $conn->prepare($serviceQuery);
            $stmt->bind_param('ii', $serviceId, $providerId);
            $stmt->execute();
            $serviceResult = $stmt->get_result();
            
            if ($serviceResult && $serviceResult->num_rows > 0) {
                $service = $serviceResult->fetch_assoc();
                $totalPrice = $service['price'];
                
                // Start transaction
                $conn->begin_transaction();
                
                // Create booking
                $insertBookingQuery = "
                    INSERT INTO bookings (
                        customer_id, provider_id, service_id, booking_date, 
                        booking_time, status, total_price, payment_status, notes
                    ) VALUES (?, ?, ?, ?, ?, 'pending', ?, 'unpaid', ?)
                ";
                
                $stmt = $conn->prepare($insertBookingQuery);
                $status = 'pending';
                $paymentStatus = 'unpaid';
                
                $stmt->bind_param('iiissds', 
                    $userId, $providerId, $serviceId, $bookingDate, 
                    $bookingTime, $totalPrice, $notes
                );
                
                $stmt->execute();
                $bookingId = $conn->insert_id;
                
                if ($bookingId) {
                    // Record status history
                    $historyQuery = "
                        INSERT INTO booking_status_history (
                            booking_id, status, created_by, user_id, notes
                        ) VALUES (?, 'pending', 'customer', ?, 'Booking created')
                    ";
                    
                    $stmt = $conn->prepare($historyQuery);
                    $stmt->bind_param('ii', $bookingId, $userId);
                    $stmt->execute();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $successMessage = "Your booking has been successfully created! The technician will review and confirm your appointment soon.";
                    
                    // Redirect to prevent form resubmission
                    header("Location: customer/bookings.php");
                    exit;
                } else {
                    throw new Exception("Failed to create booking record.");
                }
            } else {
                throw new Exception("The selected service is no longer available.");
            }
        } catch (Exception $e) {
            // Roll back transaction on error
            $conn->rollback();
            error_log("Booking error: " . $e->getMessage());
            $errorMessage = "An error occurred while creating your booking: " . $e->getMessage();
        }
    } else {
        $errorMessage = implode("<br>", $validationErrors);
    }
}

// Get available time slots based on selected date
if (isset($_GET['get_timeslots']) && !empty($_GET['date'])) {
    $selectedDate = $_GET['date'];
    $timeslots = [];
    
    try {
        // First check if there are specific schedule slots for this date
        $timeslotsQuery = "
            SELECT s.start_time, s.end_time
            FROM schedule_slots s
            LEFT JOIN bookings b ON 
                s.provider_id = b.provider_id AND 
                s.date = b.booking_date AND 
                s.start_time = b.booking_time AND 
                b.status != 'cancelled'
            LEFT JOIN provider_time_off t ON 
                t.provider_id = s.provider_id AND 
                s.date BETWEEN t.start_date AND t.end_date AND
                ((t.all_day = 1) OR 
                (TIME(s.start_time) BETWEEN t.start_time AND t.end_time))
            WHERE s.provider_id = ? 
            AND s.date = ?
            AND b.id IS NULL
            AND t.id IS NULL
            ORDER BY s.start_time
        ";
        
        $stmt = $conn->prepare($timeslotsQuery);
        $stmt->bind_param('is', $providerId, $selectedDate);
        $stmt->execute();
        $timeslotsResult = $stmt->get_result();
        
        if ($timeslotsResult && $timeslotsResult->num_rows > 0) {
            while ($row = $timeslotsResult->fetch_assoc()) {
                $timeslots[] = [
                    'start_time' => date('h:i A', strtotime($row['start_time'])),
                    'end_time' => date('h:i A', strtotime($row['end_time'])),
                    'value' => $row['start_time']
                ];
            }
        } else {
            // If no specific slots found, generate times based on provider_working_hours
            $dayOfWeek = date('w', strtotime($selectedDate)); // 0=Sunday, 6=Saturday
            
            $workingHoursQuery = "
                SELECT start_time, end_time
                FROM provider_working_hours
                WHERE provider_id = ?
                AND day_of_week = ?
                AND is_available = 1
            ";
            
            $stmt = $conn->prepare($workingHoursQuery);
            $stmt->bind_param('ii', $providerId, $dayOfWeek);
            $stmt->execute();
            $workingHoursResult = $stmt->get_result();
            
            if ($workingHoursResult && $workingHoursResult->num_rows > 0) {
                while ($row = $workingHoursResult->fetch_assoc()) {
                    $startTime = strtotime($row['start_time']);
                    $endTime = strtotime($row['end_time']);
                    
                    // Generate hourly slots (could be adjusted to different intervals)
                    $timeInterval = 30 * 60; // 30-minute intervals
                    for ($time = $startTime; $time < $endTime; $time += $timeInterval) {
                        // Check if this time is already booked
                        $timeStr = date('H:i:s', $time);
                        
                        $bookedQuery = "
                            SELECT id FROM bookings
                            WHERE provider_id = ?
                            AND booking_date = ?
                            AND booking_time = ?
                            AND status != 'cancelled'
                        ";
                        
                        $stmt = $conn->prepare($bookedQuery);
                        $stmt->bind_param('iss', $providerId, $selectedDate, $timeStr);
                        $stmt->execute();
                        $bookedResult = $stmt->get_result();
                        
                        if ($bookedResult->num_rows === 0) {
                            $timeslots[] = [
                                'start_time' => date('h:i A', $time),
                                'end_time' => date('h:i A', $time + $timeInterval),
                                'value' => $timeStr
                            ];
                        }
                    }
                }
            }
            
            // If still no slots and today's date is available, generate default time slots
            if (empty($timeslots) && in_array($selectedDate, $availableDates)) {
                // Generate default slots from 8 AM to 9 PM with 30-minute intervals
                $startHour = 8;
                $endHour = 21;
                $interval = 30 * 60; // 30 minutes in seconds
                
                for ($time = $startHour * 3600; $time < $endHour * 3600; $time += $interval) {
                    $timeStr = date('H:i:s', $time);
                    
                    // Check if this time is already booked
                    $bookedQuery = "
                        SELECT id FROM bookings
                        WHERE provider_id = ?
                        AND booking_date = ?
                        AND booking_time = ?
                        AND status != 'cancelled'
                    ";
                    
                    $stmt = $conn->prepare($bookedQuery);
                    $stmt->bind_param('iss', $providerId, $selectedDate, $timeStr);
                    $stmt->execute();
                    $bookedResult = $stmt->get_result();
                    
                    if ($bookedResult->num_rows === 0) {
                        $timeslots[] = [
                            'start_time' => date('h:i A', $time),
                            'end_time' => date('h:i A', $time + $interval),
                            'value' => $timeStr
                        ];
                    }
                }
            }
        }
        
        // Return JSON response
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'timeslots' => $timeslots]);
        exit;
    } catch (Exception $e) {
        // Return error
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Failed to load time slots: ' . $e->getMessage()]);
        exit;
    }
}

// Check for flash messages
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
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
    <meta name="description" content="Book repair services for your devices at FixItNow">
    <title>Book a Service - FixItNow</title>
    
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            --header-bg: #1e1e24;          /* Header background */
            --header-text: #ffffff;        /* Header text */
            --footer-bg: #1e1e24;          /* Footer background */
            --footer-text: #e9ecef;        /* Footer text */
            --border-color: #dee2e6;       /* Border color */
            --input-bg: #ffffff;           /* Input background */
            --input-border: #ced4da;       /* Input border */
            --modal-bg: #ffffff;           /* Modal background */
            --shadow-color: rgba(0, 0, 0, 0.1); /* Shadow color */
            --danger-color: #dc3545;       /* Danger/red color */
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
            --danger-color: #ff4b5c;       /* Brighter danger color */
            --warning-color: #ffda6a;      /* Brighter warning color */
            --success-color: #3de778;      /* Brighter success color */
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            line-height: 1.6;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        
        /* Header Styles */
        .site-header {
            background-color: var(--header-bg);
            padding: 0.75rem 0;
            color: var(--header-text);
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .logo-text {
            font-weight: 900;
            font-size: 1.6rem;
            letter-spacing: -0.5px;
            color: var(--header-text);
            display: flex;
            align-items: center;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .logo-text:hover {
            transform: scale(1.03);
            color: var(--header-text);
        }
        
        .logo-text .highlight {
            color: var(--accent-color);
            font-weight: 900;
        }
        
        /* Navigation Styles */
        .nav-button {
            display: flex;
            align-items: center;
            padding: 8px 15px;
            border-radius: 8px;
            color: rgba(255, 255, 255, 0.85);
            text-decoration: none;
            transition: all 0.25s ease;
            font-weight: 500;
            font-size: 0.95rem;
            margin: 0 5px;
            position: relative;
        }
        
        .nav-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
            color: var(--header-text);
            transform: translateY(-2px);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            box-shadow: 0 4px 12px rgba(121, 82, 179, 0.3);
        }
        
        .nav-button.active:before {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 50%;
            transform: translateX(-50%);
            width: 5px;
            height: 5px;
            background-color: var(--primary-color);
            border-radius: 50%;
        }
        
        .nav-button i {
            margin-right: 0.5rem;
            font-size: 0.9rem;
        }
        
        /* Theme Toggle Button */
        .theme-toggle {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            border: none;
            color: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-right: 15px;
        }
        
        .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
            color: #fff;
        }
        
        /* Page Title Section */
        .page-title-section {
            background: linear-gradient(135deg, #7952b3 0%, #6941a0 100%);
            color: white;
            padding: 3rem 0;
            position: relative;
            overflow: hidden;
        }
        
        .page-title-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url('images/pattern.svg') repeat;
            opacity: 0.1;
            z-index: 1;
        }
        
        .page-title-content {
            position: relative;
            z-index: 2;
        }
        
        .page-title {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            line-height: 1.2;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            letter-spacing: -0.5px;
        }
        
        .page-description {
            font-size: 1.1rem;
            opacity: 0.95;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
        }
        
        /* Button Styles */
        .btn {
            transition: all 0.3s ease;
            font-weight: 600;
            border-radius: 8px;
            padding: 12px 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        /* Booking Section */
        .booking-section {
            padding: 3rem 0;
        }
        
        .booking-card {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 24px var(--shadow-color);
            margin-bottom: 2rem;
        }
        
        .booking-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-color);
        }
        
        .provider-info {
            display: flex;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .provider-image {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 1.5rem;
            border: 3px solid var(--primary-color);
        }
        
        .provider-details h3 {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .provider-rating {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .provider-rating .star {
            color: #ffc107;
            margin-right: 0.25rem;
        }
        
        .provider-rating .rating-text {
            margin-left: 0.5rem;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .provider-specs {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .provider-spec {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .provider-spec i {
            margin-right: 0.5rem;
            color: var(--primary-color);
        }
        
        /* Service Selection */
        .service-selection {
            margin-bottom: 2rem;
        }
        
        .service-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .service-card:hover {
            box-shadow: 0 6px 18px var(--shadow-color);
            transform: translateY(-5px);
        }
        
        .service-card.selected {
            border-color: var(--primary-color);
            background-color: var(--primary-light);
        }
        
        .service-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .service-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin: 0;
        }
        
        .service-price {
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .service-details {
            font-size: 0.95rem;
            color: var(--text-color);
        }
        
        .service-meta {
            display: flex;
            justify-content: space-between;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        
        .service-category {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .service-category i {
            margin-right: 0.5rem;
        }
        
        .service-duration {
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: var(--text-muted);
        }
        
        .service-duration i {
            margin-right: 0.5rem;
        }
        
        /* Date and Time Selection */
        .date-time-selection {
            margin-bottom: 2rem;
        }
        
        .calendar-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 1.5rem;
            border: 1px solid var(--border-color);
        }
        
        .date-selector {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .date-header {
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            color: var(--text-muted);
        }
        
        .date-cell {
            text-align: center;
            padding: 0.75rem 0;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .date-cell:hover:not(.unavailable) {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .date-cell.selected {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(121, 82, 179, 0.3);
            transform: translateY(-3px);
        }
        
        .date-cell.unavailable {
            color: var(--text-muted);
            opacity: 0.5;
            cursor: not-allowed;
            background-color: rgba(0,0,0,0.05);
        }
        
        .date-day {
            font-size: 0.85rem;
        }
        
        .date-number {
            font-size: 1.2rem;
            font-weight: 700;
            margin: 0.25rem 0;
        }
        
        .date-month {
            font-size: 0.75rem;
        }
        
        .time-selector {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        
        .time-cell {
            text-align: center;
            padding: 0.75rem 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .time-cell:hover:not(.unavailable) {
            background-color: var(--primary-light);
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 3px 8px rgba(0,0,0,0.1);
        }
        
        .time-cell.selected {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
            box-shadow: 0 3px 10px rgba(121, 82, 179, 0.3);
            transform: translateY(-2px);
        }
        
        .time-cell.unavailable {
            color: var(--text-muted);
            opacity: 0.5;
            cursor: not-allowed;
            background-color: rgba(0,0,0,0.05);
        }
        
        /* Summary Section */
        .booking-summary {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 8px 24px var(--shadow-color);
            position: sticky;
            top: 100px;
        }
        
        .summary-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: var(--text-color);
        }
        
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-item:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .summary-value {
            font-weight: 500;
            color: var(--text-muted);
        }
        
        .total-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .booking-notes {
            margin-top: 1.5rem;
        }
        
        .booking-notes textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-control {
            background-color: var(--input-bg);
            border-color: var(--input-border);
            color: var(--text-color);
            border-radius: 8px;
            padding: 12px 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            box-shadow: 0 0 0 0.25rem rgba(121, 82, 179, 0.25);
            border-color: var(--primary-color);
        }
        
        /* No Services State */
        .no-services {
            text-align: center;
            padding: 3rem;
            background-color: var(--card-bg);
            border-radius: 16px;
            box-shadow: 0 8px 24px var(--shadow-color);
        }
        
        .no-services-icon {
            font-size: 4rem;
            color: var(--text-muted);
            margin-bottom: 1.5rem;
            opacity: 0.5;
        }
        
        .no-services-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .no-services-message {
            font-size: 1.1rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Footer Styles */
        .site-footer {
            background-color: var(--footer-bg);
            color: var(--footer-text);
            padding: 4rem 0 2rem;
        }
        
        .footer-logo {
            font-weight: 900;
            font-size: 1.5rem;
            letter-spacing: -0.5px;
            color: white;
            margin-bottom: 1rem;
            display: block;
        }
        
        .footer-logo .highlight {
            color: var(--accent-color);
        }
        
        .footer-about {
            margin-bottom: 2rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-title {
            font-weight: 700;
            margin-bottom: 1.5rem;
            color: white;
        }
        
        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--footer-text);
            text-decoration: none;
            transition: color 0.3s ease;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-links a:hover {
            color: white;
            opacity: 1;
        }
        
        .footer-contact {
            margin-bottom: 0.85rem;
            font-size: 0.95rem;
            opacity: 0.8;
        }
        
        .footer-contact i {
            margin-right: 0.5rem;
            width: 20px;
            text-align: center;
        }
        
        .social-icons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .social-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .social-icon:hover {
            background-color: var(--primary-color);
            transform: translateY(-3px);
            color: white;
        }
        
        .footer-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding-top: 1.5rem;
            margin-top: 3rem;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
        }
        
        .copyright {
            font-size: 0.9rem;
            opacity: 0.7;
        }
        
        /* Responsive Adjustments */
        @media (max-width: 992px) {
            .page-title {
                font-size: 2rem;
            }
            
            .time-selector {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .booking-summary {
                position: static;
                margin-top: 2rem;
            }
        }
        
        @media (max-width: 768px) {
            .page-title-section {
                padding: 2rem 0;
            }
            
            .page-title {
                font-size: 1.75rem;
            }
            
            .provider-info {
                flex-direction: column;
                text-align: center;
            }
            
            .provider-image {
                margin-right: 0;
                margin-bottom: 1rem;
            }
            
            .provider-specs {
                justify-content: center;
            }
            
            .time-selector {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .page-title {
                font-size: 1.5rem;
            }
            
            .booking-card {
                padding: 1.25rem;
            }
            
            .date-selector {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .time-selector {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="site-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <!-- Logo -->
                <a href="index.php" class="logo-text">
                    <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                </a>
                
                <!-- Main Navigation Menu -->
                <div class="d-none d-lg-flex">
                    <a href="index.php" class="nav-button">
                        <i class="fas fa-home"></i> Home
                    </a>
                    <a href="find-technician.php" class="nav-button">
                        <i class="fas fa-search"></i> Find Technician
                    </a>
                    <a href="services.php" class="nav-button">
                        <i class="fas fa-cogs"></i> Services
                    </a>
                    <a href="how-it-works.php" class="nav-button">
                        <i class="fas fa-info-circle"></i> How It Works
                    </a>
                </div>
                
                <!-- Authentication Buttons -->
                <div class="d-flex align-items-center">
                    <!-- Mobile Menu Toggle -->
                    <button type="button" class="btn btn-outline-light d-lg-none me-2" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    
                    <!-- Theme Toggle Button -->
                    <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark/light theme">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                    
                    <?php if ($loggedIn): ?>
                        <!-- User is logged in -->
                        <?php if ($userRole === 'customer'): ?>
                            <a href="customer/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'provider'): ?>
                            <a href="provider/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Dashboard
                            </a>
                        <?php elseif ($userRole === 'admin'): ?>
                            <a href="admin/dashboard.php" class="btn btn-primary dashboard-btn">
                                <i class="fas fa-tachometer-alt me-2"></i> Admin Panel
                            </a>
                        <?php endif; ?>
                        
                        <a href="logout.php" class="btn btn-outline-light ms-2">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    <?php else: ?>
                        <!-- User is not logged in -->
                        <a href="login.php" class="btn btn-outline-light me-2">
                            <i class="fas fa-sign-in-alt me-1"></i> Login
                        </a>
                        <a href="Register.php" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i> Sign Up
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Mobile Menu (Hidden by default) -->
        <div class="container-fluid d-lg-none mt-3 d-none" id="mobileMenu">
            <div class="list-group">
                <a href="index.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-home me-2"></i> Home
                </a>
                <a href="find-technician.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-search me-2"></i> Find Technician
                </a>
                <a href="services.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-cogs me-2"></i> Services
                </a>
                <a href="how-it-works.php" class="list-group-item list-group-item-action">
                    <i class="fas fa-info-circle me-2"></i> How It Works
                </a>
            </div>
        </div>
    </header>
    
    <!-- Page Title Section -->
    <section class="page-title-section">
        <div class="container text-center page-title-content">
            <h1 class="page-title">Book a Service</h1>
            <p class="page-description">Schedule a repair appointment with your chosen technician in just a few simple steps.</p>
        </div>
    </section>
    
    <!-- Booking Section -->
    <section class="booking-section">
        <div class="container">
            <?php if (!empty($errorMessage)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $errorMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($successMessage)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $successMessage; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($provider): ?>
                <form action="book-service.php?provider_id=<?php echo (int)$providerId; ?>" method="POST" id="bookingForm">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="booking-card">
                                <h2 class="booking-title">Technician Information</h2>
                                
                                <div class="provider-info">
                                    <?php 
                                    $profileImage = !empty($provider['profile_image']) ? 'profile_images/' . h($provider['profile_image']) : 'profile_images/../default.png';
                                    $providerName = h($provider['first_name'] . ' ' . $provider['last_name']);
                                    $specialties = !empty($provider['specialties']) ? explode(',', $provider['specialties']) : [];
                                    $specialtiesText = '';
                                    
                                    foreach ($specialties as $specialty) {
                                        switch (trim($specialty)) {
                                            case 'smartphone':
                                                $specialtiesText .= 'Smartphone, ';
                                                break;
                                            case 'laptop':
                                                $specialtiesText .= 'Laptop, ';
                                                break;
                                            case 'tablet':
                                                $specialtiesText .= 'Tablet, ';
                                                break;
                                            case 'desktop':
                                                $specialtiesText .= 'Desktop, ';
                                                break;
                                            case 'gaming':
                                                $specialtiesText .= 'Gaming, ';
                                                break;
                                            case 'tv':
                                                $specialtiesText .= 'TV, ';
                                                break;
                                        }
                                    }
                                    
                                    $specialtiesText = rtrim($specialtiesText, ', ');
                                    if (empty($specialtiesText)) {
                                        $specialtiesText = 'General Repairs';
                                    }
                                    
                                    $experience = '';
                                    switch ($provider['experience']) {
                                        case '0-1':
                                            $experience = 'Less than 1 year';
                                            break;
                                        case '1-3':
                                            $experience = '1-3 years';
                                            break;
                                        case '3-5':
                                            $experience = '3-5 years';
                                            break;
                                        case '5-10':
                                            $experience = '5-10 years';
                                            break;
                                        case '10+':
                                            $experience = 'Over 10 years';
                                            break;
                                        default:
                                            $experience = 'Not specified';
                                    }
                                    ?>
                                    
                                    <img src="<?php echo $profileImage; ?>" class="provider-image" alt="<?php echo $providerName; ?>">
                                    
                                    <div class="provider-details">
                                        <h3><?php echo $providerName; ?></h3>
                                        
                                        <div class="provider-rating">
                                            <?php
                                            $rating = round($provider['avg_rating'] ?? 0, 1);
                                            $fullStars = floor($rating);
                                            $halfStar = $rating - $fullStars >= 0.5;
                                            
                                            for ($i = 1; $i <= 5; $i++) {
                                                if ($i <= $fullStars) {
                                                    echo '<i class="fas fa-star star"></i>';
                                                } elseif ($i == $fullStars + 1 && $halfStar) {
                                                    echo '<i class="fas fa-star-half-alt star"></i>';
                                                } else {
                                                    echo '<i class="far fa-star star"></i>';
                                                }
                                            }
                                            ?>
                                            <span class="rating-text">
                                                <?php echo number_format($rating, 1); ?> (<?php echo (int)$provider['review_count']; ?> reviews)
                                            </span>
                                        </div>
                                        
                                        <div class="provider-specs">
                                            <?php if (!empty($specialtiesText)): ?>
                                                <div class="provider-spec">
                                                    <i class="fas fa-tools"></i> <?php echo $specialtiesText; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($experience)): ?>
                                                <div class="provider-spec">
                                                    <i class="fas fa-user-clock"></i> <?php echo $experience; ?> experience
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($provider['location'])): ?>
                                                <div class="provider-spec">
                                                    <i class="fas fa-map-marker-alt"></i> <?php echo h($provider['location']); ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ((int)$provider['is_verified'] === 1): ?>
                                                <div class="provider-spec">
                                                    <i class="fas fa-check-circle text-primary"></i> Verified Technician
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Service Selection -->
                                <div class="service-selection">
                                    <h3 class="booking-title">Select a Service</h3>
                                    
                                    <?php if (!empty($services)): ?>
                                        <?php foreach ($services as $service): ?>
                                            <div class="service-card" data-service-id="<?php echo (int)$service['id']; ?>" data-service-price="<?php echo (float)$service['price']; ?>" data-service-duration="<?php echo (int)$service['duration']; ?>">
                                                <div class="service-header">
                                                    <h4 class="service-name"><?php echo h($service['name']); ?></h4>
                                                    <div class="service-price"><?php echo formatPrice($service['price']); ?></div>
                                                </div>
                                                
                                                <div class="service-details">
                                                    <?php echo h($service['description']); ?>
                                                </div>
                                                
                                                <div class="service-meta">
                                                    <div class="service-category">
                                                        <?php
                                                        $categoryIcon = 'fa-tools'; // Default
                                                        
                                                        switch ($service['category']) {
                                                            case 'smartphone':
                                                                $categoryIcon = 'fa-mobile-alt';
                                                                $categoryName = 'Smartphone';
                                                                break;
                                                            case 'laptop':
                                                                $categoryIcon = 'fa-laptop';
                                                                $categoryName = 'Laptop';
                                                                break;
                                                            case 'tablet':
                                                                $categoryIcon = 'fa-tablet-alt';
                                                                $categoryName = 'Tablet';
                                                                break;
                                                            case 'desktop':
                                                                $categoryIcon = 'fa-desktop';
                                                                $categoryName = 'Desktop';
                                                                break;
                                                            case 'gaming':
                                                                $categoryIcon = 'fa-gamepad';
                                                                $categoryName = 'Gaming';
                                                                break;
                                                            case 'tv':
                                                                $categoryIcon = 'fa-tv';
                                                                $categoryName = 'TV';
                                                                break;
                                                            default:
                                                                $categoryName = 'General Repair';
                                                        }
                                                        ?>
                                                        <i class="fas <?php echo $categoryIcon; ?>"></i> <?php echo $categoryName; ?>
                                                    </div>
                                                    
                                                    <div class="service-duration">
                                                        <i class="far fa-clock"></i> 
                                                        <?php
                                                        $duration = (int)$service['duration'];
                                                        if ($duration < 60) {
                                                            echo $duration . ' minutes';
                                                        } else {
                                                            $hours = floor($duration / 60);
                                                            $mins = $duration % 60;
                                                            
                                                            if ($mins > 0) {
                                                                echo $hours . 'h ' . $mins . 'm';
                                                            } else {
                                                                echo $hours . ' hour' . ($hours > 1 ? 's' : '');
                                                            }
                                                        }
                                                        ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                        <input type="hidden" name="service_id" id="selectedServiceInput" value="">
                                    <?php else: ?>
                                        <?php
                                        // Get technician specialties
                                        $specialtiesArray = !empty($provider['specialties']) ? explode(',', $provider['specialties']) : [];
                                        $specialtyLabels = [
                                            'smartphone' => 'Smartphone',
                                            'laptop' => 'Laptop',
                                            'tablet' => 'Tablet',
                                            'desktop' => 'Desktop Computer',
                                            'gaming' => 'Gaming Console',
                                            'tv' => 'TV'
                                        ];
                                        ?>
                                        <div class="no-services">
                                            <div class="no-services-icon mb-3">
                                                <i class="fas fa-tools"></i>
                                            </div>
                                            <h3 class="no-services-title">No services available</h3>
                                            <p class="no-services-message">
                                                This technician doesn't have any active services at the moment.
                                                You can request a custom quote instead.
                                            </p>
                                            
                                            <div class="quote-request-form mt-5">
                                                <div class="card bg-light border-0">
                                                    <div class="card-body p-4">
                                                        <h4 class="mb-4 text-center">Request a Custom Quote</h4>
                                                        <form action="request-quote.php" method="POST" id="quoteForm">
                                                            <input type="hidden" name="provider_id" value="<?php echo (int)$providerId; ?>">
                                                            
                                                            <div class="mb-4">
                                                                <label for="device_type" class="form-label fw-medium">Device Type</label>
                                                                <select name="device_type" id="device_type" class="form-select form-select-lg" required>
                                                                    <option value="">Select device type</option>
                                                                    <?php
                                                                    // If provider has specialties, show only those
                                                                    if (!empty($specialtiesArray)) {
                                                                        foreach ($specialtiesArray as $specialty) {
                                                                            $specialty = trim($specialty);
                                                                            if (isset($specialtyLabels[$specialty])) {
                                                                                echo '<option value="' . $specialty . '">' . $specialtyLabels[$specialty] . '</option>';
                                                                            }
                                                                        }
                                                                    } else {
                                                                        // Otherwise show all device types
                                                                        foreach ($specialtyLabels as $value => $label) {
                                                                            echo '<option value="' . $value . '">' . $label . '</option>';
                                                                        }
                                                                    }
                                                                    ?>
                                                                </select>
                                                            </div>
                                                            
                                                            <div class="row mb-4">
                                                                <div class="col-md-6 mb-3 mb-md-0">
                                                                    <label for="device_brand" class="form-label fw-medium">Device Brand (Optional)</label>
                                                                    <input type="text" name="device_brand" id="device_brand" class="form-control form-control-lg" placeholder="e.g. Apple, Samsung, Dell">
                                                                </div>
                                                                
                                                                <div class="col-md-6">
                                                                    <label for="device_model" class="form-label fw-medium">Device Model (Optional)</label>
                                                                    <input type="text" name="device_model" id="device_model" class="form-control form-control-lg" placeholder="e.g. iPhone 13, Galaxy S22">
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="mb-4">
                                                                <label for="issue_description" class="form-label fw-medium">Issue Description</label>
                                                                <textarea name="issue_description" id="issue_description" class="form-control form-control-lg" rows="5" placeholder="Describe the problem with your device in detail..." required></textarea>
                                                            </div>
                                                            
                                                            <div class="mb-4">
                                                                <label for="additional_info" class="form-label fw-medium">Additional Information (Optional)</label>
                                                                <textarea name="additional_info" id="additional_info" class="form-control" rows="3" placeholder="Any other details that might help the technician..."></textarea>
                                                            </div>
                                                            
                                                            <div class="text-center mt-4">
                                                                <button type="submit" class="btn btn-primary btn-lg px-5">
                                                                    <i class="fas fa-paper-plane me-2"></i> Request Quote
                                                                </button>
                                                                
                                                                <a href="find-technician.php" class="btn btn-outline-secondary ms-2">
                                                                    <i class="fas fa-search me-2"></i> Find Another Technician
                                                                </a>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Date and Time Selection -->
                                <?php if (!empty($services)): ?>
                                    <div class="date-time-selection">
                                        <h3 class="booking-title">Choose Date & Time</h3>
                                        
                                        <div class="calendar-container">
                                            <h4 class="mb-3">Select a Date</h4>
                                            
                                            <!-- Available dates will be populated here -->
                                            <div class="date-selector" id="dateSelector">
                                                <?php
                                                // Generate calendar for the next 6 weeks
                                                $currentDay = new DateTime();
                                                $daysOfWeek = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                                
                                                // Output day headers
                                                foreach ($daysOfWeek as $day) {
                                                    echo '<div class="date-header">' . $day . '</div>';
                                                }
                                                
                                                // Set to the beginning of the current week (Sunday)
                                                $startDay = clone $currentDay;
                                                $startDay->modify('last Sunday');
                                                
                                                // Generate 6 weeks of dates
                                                for ($week = 0; $week < 6; $week++) {
                                                    for ($day = 0; $day < 7; $day++) {
                                                        $date = clone $startDay;
                                                        $date->modify("+".($week * 7 + $day)." days");
                                                        
                                                        $dateStr = $date->format('Y-m-d');
                                                        $isPast = $date < $currentDay && $date->format('Y-m-d') != $currentDay->format('Y-m-d');
                                                        $isAvailable = in_array($dateStr, $availableDates);
                                                        
                                                        // Determine cell classes
                                                        $cellClass = "date-cell";
                                                        if ($isPast || !$isAvailable) {
                                                            $cellClass .= " unavailable";
                                                        }
                                                        
                                                        // Determine month
                                                        $month = $date->format('M');
                                                        
                                                        echo '<div class="' . $cellClass . '" data-date="' . $dateStr . '">';
                                                        echo '<div class="date-day">' . $date->format('D') . '</div>';
                                                        echo '<div class="date-number">' . $date->format('d') . '</div>';
                                                        echo '<div class="date-month">' . $month . '</div>';
                                                        echo '</div>';
                                                    }
                                                }
                                                ?>
                                            </div>
                                            
                                            <input type="hidden" name="booking_date" id="selectedDateInput" value="">
                                            
                                <h4 class="mt-4 mb-3">Select a Time</h4>
                                            
                                            <div class="alert alert-info">
                                                <i class="fas fa-info-circle me-2"></i>
                                                Available time slots will be shown based on the technician's working hours and existing appointments
                                            </div>
                                            
                                <div class="time-selector" id="timeSelector">
                                                <!-- Time slots will be loaded dynamically when a date is selected -->
                                                <div class="col-12 text-center p-4">
                                                    <div class="text-muted mb-3">
                                                        <i class="far fa-calendar-alt fa-3x mb-3"></i>
                                                        <p>Please select a date first to view available time slots</p>
                                                    </div>
                                                </div>
                                            </div>
                                            <input type="hidden" name="booking_time" id="selectedTimeInput" value="">
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Booking Summary -->
                        <div class="col-lg-4">
                            <div class="booking-summary">
                                <h3 class="summary-title">Booking Summary</h3>
                                
                                <div class="summary-item">
                                    <div class="summary-label">Technician</div>
                                    <div class="summary-value"><?php echo $providerName; ?></div>
                                </div>
                                
                                <div class="summary-item">
                                    <div class="summary-label">Service</div>
                                    <div class="summary-value" id="summaryService">Not selected</div>
                                </div>
                                
                                <div class="summary-item">
                                    <div class="summary-label">Date</div>
                                    <div class="summary-value" id="summaryDate">Not selected</div>
                                </div>
                                
                                <div class="summary-item">
                                    <div class="summary-label">Time</div>
                                    <div class="summary-value" id="summaryTime">Not selected</div>
                                </div>
                                
                                <div class="summary-item">
                                    <div class="summary-label">Total</div>
                                    <div class="summary-value total-price" id="summaryPrice">SAR 0.00</div>
                                </div>
                                
                                <div class="booking-notes">
                                    <label for="notes" class="form-label">Special Instructions (Optional)</label>
                                    <textarea name="notes" id="notes" class="form-control" placeholder="Add any special instructions or details about your device issue..."></textarea>
                                </div>
                                
                                <?php if (!empty($services)): ?>
                                    <button type="submit" name="submit_booking" class="btn btn-primary btn-lg w-100 mt-4" id="bookingSubmitBtn" disabled>
                                        <i class="fas fa-calendar-check me-2"></i> Confirm Booking
                                    </button>
                                <?php endif; ?>
                                
                                <div class="mt-3 text-center">
                                    <a href="find-technician.php" class="btn btn-link">
                                        <i class="fas fa-arrow-left me-1"></i> Back to Technicians
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="no-services">
                    <div class="no-services-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <h2 class="no-services-title">Technician Not Found</h2>
                    <p class="no-services-message">
                        The technician you're looking for doesn't exist or is no longer active. Please browse our list of available technicians to book a service.
                    </p>
                    <a href="find-technician.php" class="btn btn-lg btn-primary">
                        <i class="fas fa-search me-2"></i> Find a Technician
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>
    
    <!-- Footer -->
    <footer class="site-footer">
        <div class="container">
            <div class="row">
                <div class="col-lg-4 mb-4 mb-lg-0">
                    <div class="footer-logo">
                        <i class="fas fa-tools me-2"></i>FIX<span class="highlight">IT</span>NOW
                    </div>
                    <p class="footer-about">
                        FixItNow is a platform connecting users with expert technicians for all kinds of device repairs. We make device repair easy, reliable, and accessible to everyone.
                    </p>
                    <div class="social-icons">
                        <a href="#" class="social-icon" aria-label="Facebook">
                            <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Twitter">
                            <i class="fab fa-twitter"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-icon" aria-label="LinkedIn">
                            <i class="fab fa-linkedin-in"></i>
                        </a>
                    </div>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">Quick Links</h4>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="find-technician.php">Find Technician</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="how-it-works.php">How It Works</a></li>
                        <li><a href="about.php">About Us</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Customers</h4>
                    <ul class="footer-links">
                        <li><a href="register.php">Sign Up</a></li>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="book-service.php">Book a Service</a></li>
                        <li><a href="#quoteSection">Request a Quote</a></li>
                        <li><a href="faq.php">FAQ</a></li>
                        <li><a href="support.php">Support</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-4 mb-4 mb-md-0">
                    <h4 class="footer-title">For Technicians</h4>
                    <ul class="footer-links">
                        <li><a href="join-as-provider.php">Join as a Provider</a></li>
                        <li><a href="provider/login.php">Provider Login</a></li>
                        <li><a href="provider-terms.php">Provider Terms</a></li>
                        <li><a href="provider-resources.php">Resources</a></li>
                        <li><a href="provider-faq.php">Provider FAQ</a></li>
                    </ul>
                </div>
                
                <div class="col-lg-2 col-md-12">
                    <h4 class="footer-title">Contact Us</h4>
                    <div class="footer-contact">
                        <i class="fas fa-map-marker-alt"></i> 123 Tech Street, Repair City
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-phone"></i> +1 (555) 123-4567
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-envelope"></i> support@fixitnow.com
                    </div>
                    <div class="footer-contact">
                        <i class="fas fa-clock"></i> Mon-Fri: 9AM - 6PM
                    </div>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="copyright">
                    &copy; <?php echo date('Y'); ?> FixItNow. All rights reserved.
                </div>
                <div>
                    <a href="privacy.php" class="me-3 text-white-50">Privacy Policy</a>
                    <a href="terms.php" class="text-white-50">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap & jQuery JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const mobileMenu = document.getElementById('mobileMenu');
            
            if (mobileMenuToggle && mobileMenu) {
                mobileMenuToggle.addEventListener('click', function() {
                    mobileMenu.classList.toggle('d-none');
                    
                    // Change icon based on menu state
                    const icon = mobileMenuToggle.querySelector('i');
                    if (mobileMenu.classList.contains('d-none')) {
                        icon.classList.remove('fa-times');
                        icon.classList.add('fa-bars');
                    } else {
                        icon.classList.remove('fa-bars');
                        icon.classList.add('fa-times');
                    }
                });
            }
            
            // Theme Toggle Functionality
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');
            const htmlElement = document.documentElement;
            
            // Check for saved theme preference
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark') {
                htmlElement.setAttribute('data-bs-theme', 'dark');
                themeIcon.classList.remove('fa-moon');
                themeIcon.classList.add('fa-sun');
            }
            
            // Toggle theme when button is clicked
            themeToggle.addEventListener('click', function() {
                const currentTheme = htmlElement.getAttribute('data-bs-theme');
                
                if (currentTheme === 'dark') {
                    htmlElement.setAttribute('data-bs-theme', 'light');
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                    localStorage.setItem('theme', 'light');
                } else {
                    htmlElement.setAttribute('data-bs-theme', 'dark');
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                    localStorage.setItem('theme', 'dark');
                }
            });
            
            // Service selection
            const serviceCards = document.querySelectorAll('.service-card');
            const selectedServiceInput = document.getElementById('selectedServiceInput');
            const summaryService = document.getElementById('summaryService');
            const summaryPrice = document.getElementById('summaryPrice');
            
            serviceCards.forEach(function(card) {
                card.addEventListener('click', function() {
                    // Remove selected class from all cards
                    serviceCards.forEach(function(c) {
                        c.classList.remove('selected');
                    });
                    
                    // Add selected class to clicked card
                    this.classList.add('selected');
                    
                    // Update hidden input
                    const serviceId = this.getAttribute('data-service-id');
                    const serviceName = this.querySelector('.service-name').textContent;
                    const servicePrice = parseFloat(this.getAttribute('data-service-price'));
                    
                    selectedServiceInput.value = serviceId;
                    summaryService.textContent = serviceName;
                    
                    // Format price with image
                    const formatPrice = function(price) {
                        return 'SAR ' + price.toFixed(2);
                    };
                    
                    summaryPrice.textContent = formatPrice(servicePrice);
                    
                    // Check if we can enable the submit button
                    checkSubmitButton();
                });
            });
            
            // Date selection
            function setupDateCells() {
                const dateCells = document.querySelectorAll('.date-cell:not(.unavailable)');
                const selectedDateInput = document.getElementById('selectedDateInput');
                const summaryDate = document.getElementById('summaryDate');
                const timeSelector = document.getElementById('timeSelector');
                
                dateCells.forEach(function(cell) {
                    cell.addEventListener('click', function() {
                        // Only proceed if not unavailable
                        if (!this.classList.contains('unavailable')) {
                            // Remove selected class from all cells
                            document.querySelectorAll('.date-cell').forEach(function(c) {
                                c.classList.remove('selected');
                            });
                            
                            // Add selected class to clicked cell
                            this.classList.add('selected');
                            
                            // Update hidden input
                            const dateValue = this.getAttribute('data-date');
                            selectedDateInput.value = dateValue;
                            
                            // Format date for display (e.g., "Monday, April 15, 2023")
                            const dateObj = new Date(dateValue);
                            const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
                            summaryDate.textContent = dateObj.toLocaleDateString('en-US', options);
                            
                            // Load time slots for this date
                            loadTimeSlots(dateValue);
                        }
                    });
                });
            }
            
            // Initial setup
            setupDateCells();
            
            // Function to load time slots
            function loadTimeSlots(dateValue) {
                // Show loading state
                timeSelector.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin me-2"></i> Loading available times...</div>';
                
                // Reset time selection
                document.getElementById('selectedTimeInput').value = '';
                document.getElementById('summaryTime').textContent = 'Not selected';
                
                // Fetch available time slots
                fetch('book-service.php?provider_id=<?php echo (int)$providerId; ?>&get_timeslots=1&date=' + dateValue)
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'success' && data.timeslots && data.timeslots.length > 0) {
                            // Populate time slots
                            timeSelector.innerHTML = '';
                            
                            data.timeslots.forEach(function(slot) {
                                const timeCell = document.createElement('div');
                                timeCell.className = 'time-cell';
                                timeCell.setAttribute('data-time', slot.value);
                                timeCell.textContent = slot.start_time;
                                
                                timeCell.addEventListener('click', function() {
                                    // Remove selected class from all time cells
                                    document.querySelectorAll('.time-cell').forEach(function(cell) {
                                        cell.classList.remove('selected');
                                    });
                                    
                                    // Add selected class to clicked cell
                                    this.classList.add('selected');
                                    
                                    // Update hidden input
                                    const timeValue = this.getAttribute('data-time');
                                    document.getElementById('selectedTimeInput').value = timeValue;
                                    document.getElementById('summaryTime').textContent = this.textContent;
                                    
                                    // Check if we can enable the submit button
                                    checkSubmitButton();
                                });
                                
                                timeSelector.appendChild(timeCell);
                            });
                        } else {
                            // No time slots available
                            timeSelector.innerHTML = `
                                <div class="col-12 text-center p-4">
                                    <div class="text-muted mb-3">
                                        <i class="far fa-clock fa-3x mb-3"></i>
                                        <p>No available time slots for this date.</p>
                                    </div>
                                    <p>Please select another date or contact the technician for custom scheduling.</p>
                                </div>`;
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching time slots:', error);
                        timeSelector.innerHTML = `
                            <div class="col-12 text-center p-4">
                                <div class="text-danger mb-3">
                                    <i class="fas fa-exclamation-circle fa-3x mb-3"></i>
                                    <p>Error loading time slots. Please try again.</p>
                                </div>
                                <button type="button" class="btn btn-outline-primary" onclick="loadTimeSlots('${dateValue}')">
                                    <i class="fas fa-sync-alt me-2"></i> Retry
                                </button>
                            </div>`;
                    });
            }
            
            // Function to check if form is complete and enable/disable submit button
            function checkSubmitButton() {
                const submitBtn = document.getElementById('bookingSubmitBtn');
                
                if (!submitBtn) return;
                
                const serviceId = selectedServiceInput.value;
                const bookingDate = selectedDateInput.value;
                const bookingTime = document.getElementById('selectedTimeInput').value;
                
                if (serviceId && bookingDate && bookingTime) {
                    submitBtn.disabled = false;
                } else {
                    submitBtn.disabled = true;
                }
            }
            
            // Auto dismiss alerts after 5 seconds
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    if (typeof bootstrap !== 'undefined') {
                        const bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    } else {
                        // Fallback if bootstrap JS isn't loaded
                        alert.style.display = 'none';
                    }
                });
            }, 5000);
        });
    </script>
</body>
</html>