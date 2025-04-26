<?php
/**
 * Database Connection - FixItNow Platform
 * 
 * This file establishes a connection to the MySQL database using PDO.
 * Converting from mysqli to PDO for compatibility with the rest of the application.
 */

// Database configuration
$host = 'localhost';
$dbname = 'fixitnow_db';
$username = 'root';
$password = '';

try {
    // Create PDO connection (required by booking-detail.php and other files)
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Set the PDO error mode to exception
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Also create mysqli connection for backward compatibility with any files that might use it
    $conn = new mysqli($host, $username, $password, $dbname);
    
    // Check mysqli connection
    if ($conn->connect_error) {
        throw new Exception("MySQLi connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    header("Location: ../error.php?message=" . urlencode("Database connection failed. Please contact the administrator."));
    exit;
}
?>