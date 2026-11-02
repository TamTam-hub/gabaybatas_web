<?php
date_default_timezone_set('Asia/Manila');

// Local XAMPP defaults. The live server sets its own values in
// config/database.local.php (kept out of git); see config/database.example.php.
$host = "localhost";
$user = "root";
$password = "";
$database = "gabaybatas_db";
if (is_file(__DIR__ . '/database.local.php')) {
    require __DIR__ . '/database.local.php';
}

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
?>
