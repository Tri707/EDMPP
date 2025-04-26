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

// DSN (Data Source Name)
$dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";

// PDO options
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // Create PDO connection
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    // Log error (to file, not displayed to users)
    error_log("Database connection failed: " . $e->getMessage());
    
    // Handle the error
    die("Database connection failed. Please try again later or contact technical support.");
}

// No need to return anything as $pdo will be in the global scope when included
?>