<?php
// Database connection using PDO
try {
    $pdo = new PDO("mysql:host=localhost;dbname=fixitnow_db;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    die("Database connection error: " . $e->getMessage());
}
?>

<?php
// Database connection using MySQLi
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "fixitnow_db";

// Create connection
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Database connection error: " . $conn->connect_error);
}

// Set character set
$conn->set_charset("utf8mb4");
?>