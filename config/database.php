<?php
date_default_timezone_set('Asia/Manila');

$host = "localhost";
$user = "root";
$password = "";
$database = "gabaybatas_db";

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+08:00'");
?>