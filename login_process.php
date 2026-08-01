<?php
session_start();
require_once 'config/database.php';

if (isset($_POST['login_btn'])) {
    // Escape inputs to prevent basic SQL injections
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    // 1. HARDCODED CHECK FOR CAPSTONE CONVENIENCE
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['user_id'] = 9999; // Temporary mock ID for hardcoded admin
        $_SESSION['username'] = 'admin';
        $_SESSION['role'] = 'admin';
        
        header("Location: admin/dashboard.php");
        exit();
    }

    // 2. DATABASE CHECK (For normal Residents, Guards, or dynamic Admins)
    $query = "SELECT * FROM users WHERE username = '$username'";
    $result = $conn->query($query);

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Checking password (supports plain text for your test accounts or hashed fields)
        if ($password === $user['password'] || password_verify($password, $user['password'])) {
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];

            // Redirect based on user role mapping
            if ($user['role'] === 'admin') {
                header("Location: admin/dashboard.php");
            } elseif ($user['role'] === 'guard') {
                header("Location: guard/dashboard.php");
            } elseif ($user['role'] === 'resident') {
                header("Location: resident/dashboard.php");
            }
            exit();
        } else {
            header("Location: index.php?error=Incorrect Password");
            exit();
        }
    } else {
        header("Location: index.php?error=User not found");
        exit();
    }
} else {
    header("Location: index.php");
    exit();
}
?>