<?php
/**
 * Provider Schedule - FixItNow Platform
 * 
 * This file displays the schedule for service providers, showing upcoming bookings,
 * allowing them to manage their availability, and add/edit time-off periods.
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
require_once 'conn.php';

// Verify that database connection is established
if (!isset($pdo) || $pdo === null) {
    // Try to establish connection directly if include failed
    try {
        $host = 'localhost';
        $dbname = 'fixitnow_db';
        $username = 'root';
        $password = ''; // default for WAMP
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        error_log("Critical database connection error: " . $e->getMessage());
        die("Database connection failed. Please contact the administrator.");
    }
}

// Initialize variables with default values
$providerProfileImage = '../default.png';
$providerId = 0;
$providerData = [];
$upcomingBookings = [];
$availabilityData = [];
$timeOffData = [];
$message = '';
$alertType = '';

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
                }
            }
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

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle availability submission
    if (isset($_POST['action']) && $_POST['action'] === 'update_availability') {
        try {
            // Begin transaction
            $pdo->beginTransaction();
            
            // First, delete all existing working hours for this provider
            $deleteStmt = $pdo->prepare("DELETE FROM provider_working_hours WHERE provider_id = ?");
            $deleteStmt->execute([$providerId]);
            
            // Then insert new working hours
            $insertStmt = $pdo->prepare("INSERT INTO provider_working_hours (provider_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?)");
            
            // Process each day of the week
            for ($day = 0; $day <= 6; $day++) {
                if (isset($_POST["available_$day"]) && $_POST["available_$day"] === 'on') {
                    $startTime = $_POST["start_time_$day"] ?? '09:00:00';
                    $endTime = $_POST["end_time_$day"] ?? '17:00:00';
                    
                    // Validate time format
                    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/', $startTime)) {
                        $startTime = '09:00:00';
                    }
                    if (!preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])$/', $endTime)) {
                        $endTime = '17:00:00';
                    }
                    
                    $insertStmt->execute([$providerId, $day, $startTime, $endTime, 1]);
                } else {
                    // Insert as unavailable
                    $insertStmt->execute([$providerId, $day, '00:00:00', '00:00:00', 0]);
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            $message = 'Your availability has been updated successfully!';
            $alertType = 'success';
        } catch (PDOException $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            error_log("Database error updating availability: " . $e->getMessage());
            $message = 'An error occurred while updating your availability. Please try again.';
            $alertType = 'danger';
        }
    }
    
    // Handle time-off submission
    if (isset($_POST['action']) && $_POST['action'] === 'add_time_off') {
        try {
            $startDate = $_POST['time_off_start'] ?? '';
            $endDate = $_POST['time_off_end'] ?? '';
            $allDay = isset($_POST['all_day']) ? 1 : 0;
            $startTime = $_POST['time_off_start_time'] ?? '00:00:00';
            $endTime = $_POST['time_off_end_time'] ?? '23:59:59';
            $reason = $_POST['time_off_reason'] ?? '';
            
            // Validate dates
            if (empty($startDate) || empty($endDate)) {
                throw new Exception('Start and end dates are required.');
            }
            
            // Convert to database format
            $startDate = date('Y-m-d', strtotime($startDate));
            $endDate = date('Y-m-d', strtotime($endDate));
            
            // Ensure end date is not before start date
            if (strtotime($endDate) < strtotime($startDate)) {
                throw new Exception('End date cannot be before start date.');
            }
            
            // Insert time-off record
            $insertStmt = $pdo->prepare("INSERT INTO provider_time_off (provider_id, start_date, end_date, all_day, start_time, end_time, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->execute([$providerId, $startDate, $endDate, $allDay, $startTime, $endTime, $reason]);
            
            $message = 'Your time-off period has been added successfully!';
            $alertType = 'success';
        } catch (Exception $e) {
            error_log("Error adding time-off: " . $e->getMessage());
            $message = 'An error occurred: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }
    
    // Handle delete time-off submission
    if (isset($_POST['action']) && $_POST['action'] === 'delete_time_off') {
        try {
            $timeOffId = (int)$_POST['time_off_id'];
            
            // Delete time-off record
            $deleteStmt = $pdo->prepare("DELETE FROM provider_time_off WHERE id = ? AND provider_id = ?");
            $result = $deleteStmt->execute([$timeOffId, $providerId]);
            
            if ($deleteStmt->rowCount() > 0) {
                $message = 'Time-off period has been deleted successfully!';
                $alertType = 'success';
            } else {
                $message = 'Time-off period not found or you do not have permission to delete it.';
                $alertType = 'warning';
            }
        } catch (PDOException $e) {
            error_log("Database error deleting time-off: " . $e->getMessage());
            $message = 'An error occurred while deleting the time-off period.';
            $alertType = 'danger';
        }
    }
    
    // Handle update booking status
    if (isset($_POST['action']) && $_POST['action'] === 'update_booking_status') {
        try {
            $bookingId = (int)$_POST['booking_id'];
            $newStatus = $_POST['new_status'];
            $statusNote = $_POST['status_note'] ?? '';
            
            // Validate status
            $validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid status value.');
            }
            
            // Begin transaction
            $pdo->beginTransaction();
            
            // Update booking status
            $updateStmt = $pdo->prepare("UPDATE bookings SET status = ? WHERE id = ? AND provider_id = ?");
            $updateStmt->execute([$newStatus, $bookingId, $providerId]);
            
            if ($updateStmt->rowCount() > 0) {
                // Add status history record
                $historyStmt = $pdo->prepare("INSERT INTO booking_status_history (booking_id, status, created_by, user_id, notes) VALUES (?, ?, 'provider', ?, ?)");
                $historyStmt->execute([$bookingId, $newStatus, $userId, $statusNote]);
                
                // Commit transaction
                $pdo->commit();
                
                $message = 'Booking status has been updated successfully!';
                $alertType = 'success';
            } else {
                // Rollback transaction
                $pdo->rollBack();
                
                $message = 'Booking not found or you do not have permission to update it.';
                $alertType = 'warning';
            }
        } catch (Exception $e) {
            // Rollback transaction on error
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            error_log("Error updating booking status: " . $e->getMessage());
            $message = 'An error occurred: ' . $e->getMessage();
            $alertType = 'danger';
        }
    }
}

// Fetch upcoming bookings
try {
    $bookingsQuery = "
        SELECT 
            b.id, 
            b.booking_date, 
            b.booking_time, 
            b.status, 
            b.total_price,
            b.notes,
            b.service_id,
            COALESCE(s.name, 'General Service') as service_name,
            c.id as customer_id,
            c.first_name as customer_first_name,
            c.last_name as customer_last_name,
            c.profile_image as customer_image,
            c.phone as customer_phone,
            c.email as customer_email
        FROM bookings b
        JOIN users c ON b.customer_id = c.id
        LEFT JOIN services s ON b.service_id = s.id
        WHERE b.provider_id = ? 
        ORDER BY b.booking_date ASC, b.booking_time ASC
    ";
    
    $bookingsStmt = $pdo->prepare($bookingsQuery);
    $bookingsStmt->execute([$providerId]);
    $allBookings = $bookingsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Format bookings for FullCalendar
    $calendarEvents = [];
    foreach ($allBookings as $booking) {
        $startDateTime = $booking['booking_date'] . 'T' . $booking['booking_time'];
        
        // Calculate end time (assume 1 hour duration if not specified)
        $endDateTime = date('Y-m-d\TH:i:s', strtotime($startDateTime) + 3600);
        
        // Determine color based on status
        $color = '#6c757d'; // Default gray
        $textColor = '#ffffff';
        
        switch ($booking['status']) {
            case 'confirmed':
                $color = '#0d6efd'; // Blue
                break;
            case 'completed':
                $color = '#198754'; // Green
                break;
            case 'cancelled':
                $color = '#dc3545'; // Red
                break;
            case 'pending':
                $color = '#ffc107'; // Yellow
                $textColor = '#000000';
                break;
        }
        
        $calendarEvents[] = [
            'id' => $booking['id'],
            'title' => $booking['customer_first_name'] . ' ' . $booking['customer_last_name'] . ' - ' . $booking['service_name'],
            'start' => $startDateTime,
            'end' => $endDateTime,
            'color' => $color,
            'textColor' => $textColor,
            'extendedProps' => [
                'status' => $booking['status'],
                'customer' => $booking['customer_first_name'] . ' ' . $booking['customer_last_name'],
                'phone' => $booking['customer_phone'],
                'email' => $booking['customer_email'],
                'service' => $booking['service_name'],
                'price' => $booking['total_price'],
                'notes' => $booking['notes']
            ]
        ];
    }
    
    // Encode for JavaScript
    $calendarEventsJson = json_encode($calendarEvents, JSON_NUMERIC_CHECK);
} catch (PDOException $e) {
    error_log("Database error fetching bookings: " . $e->getMessage());
    $calendarEvents = [];
    $calendarEventsJson = '[]';
}

// Fetch provider availability
try {
    $availabilityQuery = "
        SELECT 
            day_of_week,
            start_time,
            end_time,
            is_available
        FROM provider_working_hours
        WHERE provider_id = ?
        ORDER BY day_of_week ASC
    ";
    
    $availabilityStmt = $pdo->prepare($availabilityQuery);
    $availabilityStmt->execute([$providerId]);
    $availabilityResults = $availabilityStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Create array with default values for all days
    $availabilityData = [];
    for ($day = 0; $day <= 6; $day++) {
        $availabilityData[$day] = [
            'day_of_week' => $day,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_available' => 0
        ];
    }
    
    // Update with fetched data
    foreach ($availabilityResults as $row) {
        $day = (int)$row['day_of_week'];
        $availabilityData[$day] = $row;
    }
} catch (PDOException $e) {
    error_log("Database error fetching availability: " . $e->getMessage());
    // Default values already set
}

// Fetch time-off periods
try {
    $timeOffQuery = "
        SELECT 
            id,
            start_date,
            end_date,
            all_day,
            start_time,
            end_time,
            reason
        FROM provider_time_off
        WHERE provider_id = ?
        ORDER BY start_date ASC
    ";
    
    $timeOffStmt = $pdo->prepare($timeOffQuery);
    $timeOffStmt->execute([$providerId]);
    $timeOffData = $timeOffStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Format time-off for FullCalendar
    $timeOffEvents = [];
    foreach ($timeOffData as $timeOff) {
        $startDate = $timeOff['start_date'];
        $endDate = $timeOff['end_date'];
        $allDay = (bool)$timeOff['all_day'];
        
        if ($allDay) {
            // For all-day events, FullCalendar expects the end date to be exclusive
            $endDate = date('Y-m-d', strtotime($endDate . ' +1 day'));
            
            $timeOffEvents[] = [
                'id' => 'timeoff_' . $timeOff['id'],
                'title' => 'Time Off: ' . $timeOff['reason'],
                'start' => $startDate,
                'end' => $endDate,
                'allDay' => true,
                'color' => '#6610f2', // Purple
                'rendering' => 'background',
                'extendedProps' => [
                    'type' => 'timeoff',
                    'timeOffId' => $timeOff['id'],
                    'reason' => $timeOff['reason']
                ]
            ];
        } else {
            // For partial-day time off
            $startDateTime = $startDate . 'T' . $timeOff['start_time'];
            $endDateTime = $endDate . 'T' . $timeOff['end_time'];
            
            $timeOffEvents[] = [
                'id' => 'timeoff_' . $timeOff['id'],
                'title' => 'Time Off: ' . $timeOff['reason'],
                'start' => $startDateTime,
                'end' => $endDateTime,
                'allDay' => false,
                'color' => '#6610f2', // Purple
                'rendering' => 'background',
                'extendedProps' => [
                    'type' => 'timeoff',
                    'timeOffId' => $timeOff['id'],
                    'reason' => $timeOff['reason'],
                    'startTime' => $timeOff['start_time'],
                    'endTime' => $timeOff['end_time']
                ]
            ];
        }
    }
    
    // Add time-off events to calendar events
    $calendarEvents = array_merge($calendarEvents, $timeOffEvents);
    $calendarEventsJson = json_encode($calendarEvents, JSON_NUMERIC_CHECK);
} catch (PDOException $e) {
    error_log("Database error fetching time-off periods: " . $e->getMessage());
    // Keep existing calendar events
}

// Helper function to get day name
function getDayName($dayNum) {
    $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return $days[$dayNum] ?? 'Unknown';
}

// Helper function to format time for display
function formatTime($time) {
    return date('g:i A', strtotime($time));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Schedule - FixItNow</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- FullCalendar CSS -->
    <link href="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.0/main.min.css" rel="stylesheet">
    
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
        
        /* Calendar Styles */
        .fc-theme-standard .fc-scrollgrid {
            border-color: var(--border-color);
        }
        
        .fc-theme-standard th, .fc-theme-standard td {
            border-color: var(--border-color);
        }
        
        .fc .fc-toolbar-title {
            font-size: 1.5rem;
            font-weight: 600;
        }
        
        .fc-button-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-hover) !important;
        }
        
        .fc-button-primary:hover {
            background-color: var(--primary-hover) !important;
            border-color: var(--primary-color) !important;
        }
        
        .fc-event {
            cursor: pointer;
            border-radius: 4px !important;
            border: none !important;
            padding: 3px 6px;
            font-size: 0.85rem;
        }
        
        /* Status badges */
        .status-badge {
            padding: 0.35rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 50rem;
        }
        
        .status-badge.pending {
            background-color: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-badge.confirmed {
            background-color: rgba(13, 110, 253, 0.2);
            color: #0d6efd;
        }
        
        .status-badge.completed {
            background-color: rgba(25, 135, 84, 0.2);
            color: #198754;
        }
        
        .status-badge.cancelled {
            background-color: rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
        
        /* Time selector styles */
        .time-selector {
            padding: 0.5rem;
            border-radius: 0.375rem;
            border: 1px solid var(--input-border);
            background-color: var(--input-bg);
            color: var(--text-color);
        }
        
        /* Availability table */
        .availability-table th, .availability-table td {
            padding: 0.75rem;
            vertical-align: middle;
        }
        
        .availability-table thead th {
            background-color: rgba(0, 0, 0, 0.05);
            font-weight: 600;
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
            box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
        }
        
        [data-bs-theme="dark"] .form-check-input {
            background-color: var(--input-bg);
            border-color: var(--input-border);
        }
        
        [data-bs-theme="dark"] .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-hover);
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
        
        /* Time-off list */
        .time-off-item {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .time-off-item:last-child {
            border-bottom: none;
        }
        
        .time-off-date {
            font-weight: 600;
        }
        
        .time-off-reason {
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        /* Mobile responsive adjustments */
        @media (max-width: 576px) {
            .content-area {
                padding: 1rem;
            }
            
            .fc .fc-toolbar {
                flex-direction: column;
                gap: 0.5rem;
            }
            
            .fc .fc-toolbar-title {
                font-size: 1.2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Loading overlay (shown during page load) -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner-container">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-2 text-primary">Loading schedule...</p>
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
                        <a class="nav-link active" href="schedule.php">
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
                        <a class="nav-link" href="quotes.php">
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
            <div class="row mb-4">
                <div class="col-md-8">
                    <h2 class="page-title">My Schedule</h2>
                    <p class="text-muted">Manage your bookings, availability, and time-off periods.</p>
                </div>
                <div class="col-md-4 text-end">
                    <button type="button" class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addTimeOffModal">
                        <i class="fas fa-plus me-1"></i> Add Time Off
                    </button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#availabilityModal">
                        <i class="fas fa-clock me-1"></i> Set Availability
                    </button>
                </div>
            </div>
            
            <!-- Display alert message if set -->
            <?php if(!empty($message)): ?>
            <div class="alert alert-<?php echo $alertType; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <!-- Calendar and Sidebar -->
            <div class="row">
                <!-- Main Calendar -->
                <div class="col-lg-9">
                    <div class="card h-100">
                        <div class="card-body">
                            <div id="calendar"></div>
                        </div>
                    </div>
                </div>
                
                <!-- Schedule Sidebar -->
                <div class="col-lg-3">
                    <!-- Legend Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Calendar Legend</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="status-badge confirmed me-2">Confirmed</div>
                                <span class="small">Confirmed bookings</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div class="status-badge pending me-2">Pending</div>
                                <span class="small">Pending approval</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div class="status-badge completed me-2">Completed</div>
                                <span class="small">Completed bookings</span>
                            </div>
                            <div class="d-flex align-items-center mb-2">
                                <div class="status-badge cancelled me-2">Cancelled</div>
                                <span class="small">Cancelled bookings</span>
                            </div>
                            <div class="d-flex align-items-center">
                                <div class="badge me-2" style="background-color: #6610f2;">Time Off</div>
                                <span class="small">Your time off periods</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Current Availability Card -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Your Availability</h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php for ($day = 0; $day <= 6; $day++): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?php echo getDayName($day); ?></span>
                                    <?php if ((int)$availabilityData[$day]['is_available'] === 1): ?>
                                        <span class="badge bg-success rounded-pill">
                                            <?php echo formatTime($availabilityData[$day]['start_time']); ?> - 
                                            <?php echo formatTime($availabilityData[$day]['end_time']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary rounded-pill">Not Available</span>
                                    <?php endif; ?>
                                </li>
                                <?php endfor; ?>
                            </ul>
                        </div>
                        <div class="card-footer text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#availabilityModal">
                                Update Availability
                            </button>
                        </div>
                    </div>
                    
                    <!-- Upcoming Time Off Card -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-umbrella-beach me-2"></i>Upcoming Time Off</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($timeOffData)): ?>
                                <div class="p-4 text-center">
                                    <div class="text-muted mb-3">
                                        <i class="fas fa-calendar-day fa-3x"></i>
                                    </div>
                                    <p class="small text-muted">You don't have any upcoming time off periods.</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($timeOffData as $timeOff): ?>
                                        <div class="time-off-item">
                                            <div class="d-flex justify-content-between">
                                                <div class="time-off-date">
                                                    <?php 
                                                        $startDate = date('M j, Y', strtotime($timeOff['start_date']));
                                                        $endDate = date('M j, Y', strtotime($timeOff['end_date']));
                                                        
                                                        if ($startDate === $endDate) {
                                                            echo $startDate;
                                                        } else {
                                                            echo "$startDate - $endDate";
                                                        }
                                                    ?>
                                                </div>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this time-off period?');">
                                                    <input type="hidden" name="action" value="delete_time_off">
                                                    <input type="hidden" name="time_off_id" value="<?php echo $timeOff['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            </div>
                                            <div class="time-off-reason mt-1">
                                                <?php echo htmlspecialchars($timeOff['reason']); ?>
                                                <?php if (!(bool)$timeOff['all_day']): ?>
                                                    <div class="small mt-1">
                                                        <i class="far fa-clock me-1"></i>
                                                        <?php echo formatTime($timeOff['start_time']); ?> - 
                                                        <?php echo formatTime($timeOff['end_time']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addTimeOffModal">
                                Add Time Off
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Availability Modal -->
    <div class="modal fade" id="availabilityModal" tabindex="-1" aria-labelledby="availabilityModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="availabilityModalLabel">Set Your Weekly Availability</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_availability">
                        <p class="text-muted mb-4">Set your regular weekly working hours. Customers will only be able to book appointments during these hours.</p>
                        
                        <table class="table availability-table">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    <th>Available</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($day = 0; $day <= 6; $day++): ?>
                                <tr>
                                    <td><?php echo getDayName($day); ?></td>
                                    <td>
                                        <div class="form-check form-switch">
                                            <input class="form-check-input day-toggle" type="checkbox" id="available_<?php echo $day; ?>" name="available_<?php echo $day; ?>" <?php echo ((int)$availabilityData[$day]['is_available'] === 1) ? 'checked' : ''; ?> data-day="<?php echo $day; ?>">
                                            <label class="form-check-label" for="available_<?php echo $day; ?>"></label>
                                        </div>
                                    </td>
                                    <td>
                                        <select class="form-select time-select" name="start_time_<?php echo $day; ?>" id="start_time_<?php echo $day; ?>" <?php echo ((int)$availabilityData[$day]['is_available'] === 0) ? 'disabled' : ''; ?>>
                                            <?php 
                                                $selected_start = date('H:i:s', strtotime($availabilityData[$day]['start_time']));
                                                for ($hour = 0; $hour < 24; $hour++) {
                                                    for ($minute = 0; $minute < 60; $minute += 30) {
                                                        $time = sprintf('%02d:%02d:00', $hour, $minute);
                                                        $display_time = date('g:i A', strtotime($time));
                                                        $selected = ($time === $selected_start) ? 'selected' : '';
                                                        echo "<option value=\"$time\" $selected>$display_time</option>";
                                                    }
                                                }
                                            ?>
                                        </select>
                                    </td>
                                    <td>
                                        <select class="form-select time-select" name="end_time_<?php echo $day; ?>" id="end_time_<?php echo $day; ?>" <?php echo ((int)$availabilityData[$day]['is_available'] === 0) ? 'disabled' : ''; ?>>
                                            <?php 
                                                $selected_end = date('H:i:s', strtotime($availabilityData[$day]['end_time']));
                                                for ($hour = 0; $hour < 24; $hour++) {
                                                    for ($minute = 0; $minute < 60; $minute += 30) {
                                                        $time = sprintf('%02d:%02d:00', $hour, $minute);
                                                        $display_time = date('g:i A', strtotime($time));
                                                        $selected = ($time === $selected_end) ? 'selected' : '';
                                                        echo "<option value=\"$time\" $selected>$display_time</option>";
                                                    }
                                                }
                                            ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Availability</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Time Off Modal -->
    <div class="modal fade" id="addTimeOffModal" tabindex="-1" aria-labelledby="addTimeOffModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addTimeOffModalLabel">Add Time Off</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_time_off">
                        
                        <div class="mb-3">
                            <label for="time_off_start" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="time_off_start" name="time_off_start" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="time_off_end" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="time_off_end" name="time_off_end" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="all_day" name="all_day" checked>
                            <label class="form-check-label" for="all_day">All Day</label>
                        </div>
                        
                        <div id="time_inputs" style="display: none;">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="time_off_start_time" class="form-label">Start Time</label>
                                    <input type="time" class="form-control" id="time_off_start_time" name="time_off_start_time" value="09:00">
                                </div>
                                <div class="col-md-6">
                                    <label for="time_off_end_time" class="form-label">End Time</label>
                                    <input type="time" class="form-control" id="time_off_end_time" name="time_off_end_time" value="17:00">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="time_off_reason" class="form-label">Reason (optional)</label>
                            <textarea class="form-control" id="time_off_reason" name="time_off_reason" rows="3" placeholder="Reason for time off..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Time Off</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Booking Details Modal -->
    <div class="modal fade" id="bookingDetailsModal" tabindex="-1" aria-labelledby="bookingDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bookingDetailsModalLabel">Booking Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div id="bookingDetails">
                        <!-- Booking details will be loaded here dynamically -->
                    </div>
                    
                    <hr>
                    
                    <!-- Status Update Form -->
                    <form id="updateStatusForm" method="post">
                        <input type="hidden" name="action" value="update_booking_status">
                        <input type="hidden" id="booking_id" name="booking_id" value="">
                        
                        <div class="mb-3">
                            <label for="new_status" class="form-label">Update Status</label>
                            <select class="form-select" id="new_status" name="new_status">
                                <option value="pending">Pending</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="status_note" class="form-label">Status Note (optional)</label>
                            <textarea class="form-control" id="status_note" name="status_note" rows="2" placeholder="Add note about status change..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Update Status</button>
                    </form>
                </div>
                <div class="modal-footer">
                    <a href="#" id="viewDetailLink" class="btn btn-secondary me-auto">View Full Details</a>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- FullCalendar JS -->
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.10.0/main.min.js"></script>
    
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
            
            // Initialize FullCalendar
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'dayGridMonth,timeGridWeek,timeGridDay'
                },
                events: <?php echo $calendarEventsJson; ?>,
                eventTimeFormat: {
                    hour: 'numeric',
                    minute: '2-digit',
                    meridiem: 'short'
                },
                dayMaxEvents: true,
                eventClick: function(info) {
                    // Check if the event is a time-off event
                    if (info.event.extendedProps && info.event.extendedProps.type === 'timeoff') {
                        // Show time-off details
                        alert('Time Off: ' + info.event.extendedProps.reason);
                        return;
                    }
                    
                    // Regular booking event
                    const event = info.event;
                    const props = event.extendedProps;
                    
                    // Format the booking details
                    let detailsHtml = `
                        <div class="mb-3">
                            <h6>Customer</h6>
                            <p>${props.customer}</p>
                        </div>
                        <div class="mb-3">
                            <h6>Service</h6>
                            <p>${props.service}</p>
                        </div>
                        <div class="mb-3">
                            <h6>Date & Time</h6>
                            <p>${info.event.start.toLocaleDateString()} at ${info.event.start.toLocaleTimeString([], {hour: 'numeric', minute:'2-digit'})}</p>
                        </div>
                        <div class="mb-3">
                            <h6>Status</h6>
                            <p><span class="status-badge ${props.status}">${props.status.charAt(0).toUpperCase() + props.status.slice(1)}</span></p>
                        </div>
                        <div class="mb-3">
                            <h6>Price</h6>
                            <p>SAR ${props.price}</p>
                        </div>`;
                    
                    if (props.notes) {
                        detailsHtml += `
                            <div class="mb-3">
                                <h6>Notes</h6>
                                <p>${props.notes}</p>
                            </div>`;
                    }
                    
                    // Set the details in the modal
                    document.getElementById('bookingDetails').innerHTML = detailsHtml;
                    
                    // Set the booking ID in the form
                    document.getElementById('booking_id').value = event.id;
                    
                    // Set the current status in the dropdown
                    document.getElementById('new_status').value = props.status;
                    
                    // Update the link to the full details page
                    document.getElementById('viewDetailLink').href = `booking-detail.php?id=${event.id}`;
                    
                    // Show the modal
                    const bookingModal = new bootstrap.Modal(document.getElementById('bookingDetailsModal'));
                    bookingModal.show();
                }
            });
            calendar.render();
            
            // Toggle time inputs in time-off modal
            const allDayCheckbox = document.getElementById('all_day');
            const timeInputs = document.getElementById('time_inputs');
            
            allDayCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    timeInputs.style.display = 'none';
                } else {
                    timeInputs.style.display = 'block';
                }
            });
            
            // Toggle time selects in availability modal
            const dayToggles = document.querySelectorAll('.day-toggle');
            
            dayToggles.forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const day = this.dataset.day;
                    const startTimeSelect = document.getElementById(`start_time_${day}`);
                    const endTimeSelect = document.getElementById(`end_time_${day}`);
                    
                    if (this.checked) {
                        startTimeSelect.disabled = false;
                        endTimeSelect.disabled = false;
                    } else {
                        startTimeSelect.disabled = true;
                        endTimeSelect.disabled = true;
                    }
                });
            });
            
            // Date range validation for time-off
            const startDateInput = document.getElementById('time_off_start');
            const endDateInput = document.getElementById('time_off_end');
            
            startDateInput.addEventListener('change', function() {
                endDateInput.min = this.value;
                if (endDateInput.value && endDateInput.value < this.value) {
                    endDateInput.value = this.value;
                }
            });
        });
    </script>
</body>
</html>