<?php
/**
 * Database Connection
 * 
 * This file establishes a connection to the MySQL database using PDO
 * for the FixItNow application.
 */

// Database configuration
$db_host = 'localhost';      // Database host
$db_name = 'fixitnow_db';    // Database name
$db_user = 'root';           // Database username
$db_pass = '';               // Database password
$db_charset = 'utf8mb4';     // Character set (supports multilingual characters)

// Connection options
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,             // Throw exceptions on errors
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Default fetch as associative array
    PDO::ATTR_EMULATE_PREPARES => false,                     // Use real prepared statements
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$db_charset}" // Set character encoding
];

// DSN (Data Source Name)
$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

// Try to establish the database connection
try {
    // Create PDO instance
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
    
    // Set the connection encoding
    $pdo->exec("SET NAMES {$db_charset}");
    
} catch (PDOException $e) {
    // Log error (to file, not displayed to users)
    error_log("Database connection failed: " . $e->getMessage());
    
    // Handle the error - customize this based on your error handling strategy
    // In production, you might not want to show detailed error messages
    die("Database connection failed. Please try again later or contact technical support.");
}