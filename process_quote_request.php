<?php
// Start session
session_start();

// Check if user is logged in and is a customer
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || $_SESSION['role'] !== 'customer') {
    $_SESSION['error_message'] = "You must be logged in as a customer to submit a quote request.";
    header("Location: index.php");
    exit;
}

// Database connection
require_once 'conn.php';

// Get user ID
$customer_id = (int)$_SESSION['user_id'];

// Validate form submission
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = "Invalid request method.";
    header("Location: index.php");
    exit;
}

// Get and sanitize input
$device_type = isset($_POST['device_type']) ? $conn->real_escape_string(trim($_POST['device_type'])) : '';
$device_brand = isset($_POST['device_brand']) ? $conn->real_escape_string(trim($_POST['device_brand'])) : 'Not specified';
$device_model = isset($_POST['device_model']) ? $conn->real_escape_string(trim($_POST['device_model'])) : 'Not specified';
$device_condition = 'good'; // Default value
$issue_description = isset($_POST['issue_description']) ? $conn->real_escape_string(trim($_POST['issue_description'])) : '';
$additional_info = isset($_POST['additional_info']) ? $conn->real_escape_string(trim($_POST['additional_info'])) : '';
$urgency = 'medium'; // Default value
$service_type = 'repair'; // Default value

// Validate required fields
if (empty($device_type) || empty($issue_description)) {
    $_SESSION['error_message'] = "Please fill in all required fields.";
    header("Location: index.php");
    exit;
}

// Validate device type
$valid_device_types = ['smartphone', 'laptop', 'tablet', 'desktop', 'gaming', 'tv'];
if (!in_array($device_type, $valid_device_types)) {
    $_SESSION['error_message'] = "Invalid device type selected.";
    header("Location: index.php");
    exit;
}

// Initialize request ID variable
$request_id = 0;

try {
    // Begin transaction
    $conn->begin_transaction();
    
    // Use the stored procedure to create the quote request
    $stmt = $conn->prepare("CALL create_quote_notifications(?, ?, ?)");
    $stmt->bind_param("iss", $customer_id, $device_type, $issue_description);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to create quote request: " . $stmt->error);
    }
    
    // Get the result to retrieve the request_id
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        if ($row['status'] === 'success') {
            $request_id = $row['request_id'];
        } else {
            throw new Exception("Quote request creation failed.");
        }
    } else {
        throw new Exception("No result returned from procedure.");
    }
    
    $stmt->close();
    
    // If there's a separate form submission with device details, update the request
    if (!empty($device_brand) || !empty($device_model) || !empty($additional_info)) {
        $updateStmt = $conn->prepare("UPDATE quote_requests SET 
            device_brand = ?, 
            device_model = ?,
            additional_info = ?
            WHERE id = ?");
        
        $updateStmt->bind_param("sssi", $device_brand, $device_model, $additional_info, $request_id);
        
        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update quote request details: " . $updateStmt->error);
        }
        
        $updateStmt->close();
    }
    
    // Handle file upload (if provided)
    if (isset($_FILES['device_image']) && $_FILES['device_image']['error'] === UPLOAD_ERR_OK) {
        $file_name = $_FILES['device_image']['name'];
        $file_tmp = $_FILES['device_image']['tmp_name'];
        $file_type = $_FILES['device_image']['type'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Validate file extension
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (!in_array($file_ext, $allowed_extensions)) {
            throw new Exception("Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.");
        }
        
        // Validate file size (5MB max)
        if ($_FILES['device_image']['size'] > 5242880) {
            throw new Exception("File size too large. Maximum file size is 5MB.");
        }
        
        // Create unique filename
        $unique_filename = uniqid() . '_' . $file_name;
        $upload_dir = 'uploads/request_images/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                throw new Exception("Failed to create upload directory.");
            }
        }
        
        $upload_path = $upload_dir . $unique_filename;
        
        // Move uploaded file
        if (!move_uploaded_file($file_tmp, $upload_path)) {
            throw new Exception("Failed to upload file.");
        }
        
        // Save file information to database
        $mediaStmt = $conn->prepare("INSERT INTO quote_request_media 
            (request_id, file_name, original_name, file_path, file_type, media_type) 
            VALUES (?, ?, ?, ?, ?, 'image')");
        
        $mediaStmt->bind_param("issss", $request_id, $unique_filename, $file_name, $upload_path, $file_type);
        
        if (!$mediaStmt->execute()) {
            // Remove uploaded file if database insertion fails
            @unlink($upload_path);
            throw new Exception("Failed to save media information: " . $mediaStmt->error);
        }
        
        $mediaStmt->close();
    }
    
    // Commit transaction
    $conn->commit();
    
    // Set success message and redirect
    $_SESSION['success_message'] = "Your quote request has been submitted successfully. Our technicians will contact you soon.";
    header("Location: index.php?quote_submitted=success");
    exit;
    
} catch (Exception $e) {
    // Rollback transaction on error
    $conn->rollback();
    
    // Log error
    error_log("Quote request error: " . $e->getMessage());
    
    // Set error message and redirect
    $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
    header("Location: index.php");
    exit;
}

// Close database connection
$conn->close();
?>