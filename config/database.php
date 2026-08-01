<?php
$host = "localhost";
$db_user = "root";
$db_pass = ""; // Leave blank if using default XAMPP
$db_name = "resicured_db";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    die("Database Connection Failed: " . $conn->connect_error);
}
?>