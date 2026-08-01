<?php
// Start session at the absolute top
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. SET HEADER IMMEDIATELY
header('Content-Type: application/json');

// 2. GATEWAY AUTHENTICATION
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized entry session window.']);
    exit();
}

require_once '../config/database.php';

if (isset($_POST['qr_token'])) {
    // Clean up any rogue spaces or string variations passed by scanners
    $qr_token = $conn->real_escape_string(trim($_POST['qr_token']));
    
    // Explicitly enforce system timezone to prevent host clock drops
    date_default_timezone_set('Asia/Manila'); 
    $current_date = date('Y-m-d');

    // Query to match visitor pass token with resident host records
    $query = "SELECT v.id, v.visitor_name, v.visit_date, v.status, r.full_name as resident_name, r.house_number 
              FROM visitors v
              JOIN residents r ON v.resident_id = r.user_id 
              WHERE BINARY v.qr_code_token = '$qr_token' LIMIT 1";
              
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        $pass = $result->fetch_assoc();
        
        // Clean up database date string to ensure strtotime() parses 2026 cleanly
        $clean_visit_date = date('Y-m-d', strtotime(trim($pass['visit_date'])));

        // Check structural access dates against target calendars
        if ($clean_visit_date !== $current_date) {
            echo json_encode([
                'success' => false, 
                'message' => "Access Denied: Pass is valid for " . date('M d, Y', strtotime($clean_visit_date)) . " (System tracking says today is " . date('M d, Y', strtotime($current_date)) . ")"
            ]);
        } elseif ($pass['status'] !== 'approved') {
            echo json_encode(['success' => false, 'message' => 'Access Denied: Pass is ' . strtoupper($pass['status'])]);
        } else {
            // Valid pass! Inject entry ledger item into logging sequence
            $visitor_id = $pass['id'];
            $log_sql = "INSERT INTO access_logs (person_id, person_type, log_type, timestamp) 
                        VALUES ('$visitor_id', 'visitor', 'entry', NOW())";
            $conn->query($log_sql);

            echo json_encode([
                'success' => true,
                'visitor_name' => $pass['visitor_name'],
                'resident_name' => $pass['resident_name'],
                'house_number' => $pass['house_number']
            ]);
        }
    } else {
        echo json_encode([
            'success' => false, 
            'message' => "Security Error: Token match failed. ($qr_token)"
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Empty parameter streams.']);
}
?>