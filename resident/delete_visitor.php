<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../index.php");
    exit();
}

if (isset($_GET['id'])) {
    $id = $conn->real_escape_string($_GET['id']);
    $resident_id = $_SESSION['user_id'];
    
    // Only delete if the record belongs to the logged-in user
    $conn->query("DELETE FROM visitors WHERE id = '$id' AND resident_id = '$resident_id'");
}

header("Location: dashboard.php");
exit();
?>