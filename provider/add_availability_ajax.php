<?php
// Start session
session_start();

// Check if user is logged in and is a provider
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'provider') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include database connection
include '../admin/conn.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if the request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get provider ID from session or post data
$userId = $_SESSION['user_id'];
$providerId = isset($_POST['provider_id']) ? intval($_POST['provider_id']) : 0;

// Verify if the provider ID is valid for the current user
try {
    $checkStmt = $pdo->prepare("SELECT p.id FROM providers p JOIN users u ON p.user_id = u.id 
                                WHERE u.id = ? AND u.role = 'provider'");
    $checkStmt->execute([$userId]);
    $provider = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$provider || $provider['id'] != $providerId) {
        echo json_encode(['success' => false, 'message' => 'Invalid provider ID']);
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error checking provider: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Validate required fields
$required = ['date', 'start_time', 'end_time', 'max_appointments'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => 'Missing required field: ' . $field]);
        exit;
    }
}

// Sanitize and validate inputs
$date = filter_var($_POST['date'], FILTER_SANITIZE_STRING);
$startTime = filter_var($_POST['start_time'], FILTER_SANITIZE_STRING);
$endTime = filter_var($_POST['end_time'], FILTER_SANITIZE_STRING);
$maxAppointments = filter_var($_POST['max_appointments'], FILTER_VALIDATE_INT);
$description = isset($_POST['description']) ? filter_var($_POST['description'], FILTER_SANITIZE_STRING) : '';

// Validate date format (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format']);
    exit;
}

// Validate time formats (HH:MM)
if (!preg_match('/^\d{2}:\d{2}$/', $startTime) || !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
    echo json_encode(['success' => false, 'message' => 'Invalid time format']);
    exit;
}

// Validate max appointments
if ($maxAppointments < 1) {
    echo json_encode(['success' => false, 'message' => 'Maximum appointments must be at least 1']);
    exit;
}

// Check if end time is after start time
if (strtotime($endTime) <= strtotime($startTime)) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time']);
    exit;
}

// Check for overlapping availability slots
try {
    $overlapStmt = $pdo->prepare("
        SELECT COUNT(*) as overlap_count
        FROM schedule_slots
        WHERE provider_id = ?
        AND date = ?
        AND (
            (start_time <= ? AND end_time > ?) OR
            (start_time < ? AND end_time >= ?) OR
            (start_time >= ? AND end_time <= ?)
        )
    ");
    
    $overlapStmt->execute([
        $providerId,
        $date,
        $startTime,
        $startTime,
        $endTime,
        $endTime,
        $startTime,
        $endTime
    ]);
    
    $overlapResult = $overlapStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($overlapResult['overlap_count'] > 0) {
        echo json_encode(['success' => false, 'message' => 'This time slot overlaps with an existing availability slot']);
        exit;
    }
} catch (PDOException $e) {
    error_log("Database error checking overlap: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Insert new availability slot
try {
    $insertStmt = $pdo->prepare("
        INSERT INTO schedule_slots (provider_id, date, start_time, end_time, max_appointments, description)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $insertResult = $insertStmt->execute([
        $providerId,
        $date,
        $startTime,
        $endTime,
        $maxAppointments,
        $description
    ]);
    
    if ($insertResult) {
        $slotId = $pdo->lastInsertId();
        echo json_encode([
            'success' => true, 
            'message' => 'Availability slot added successfully',
            'slot_id' => $slotId,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add availability slot']);
    }
} catch (PDOException $e) {
    error_log("Database error inserting availability: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}