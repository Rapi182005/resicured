<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized entry session window.']);
    exit();
}

require_once '../config/database.php';

// Safe require for Gmail script
if (file_exists('send_gmail.php')) {
    require_once 'send_gmail.php';
}

if (isset($_POST['qr_token'])) {
    $qr_token = $conn->real_escape_string(trim($_POST['qr_token']));
    
    date_default_timezone_set('Asia/Manila'); 
    $current_date = date('Y-m-d');

    // Joined users table to fetch resident email from users.email
    $query = "SELECT v.id, v.visitor_name, v.message, v.visit_date, v.status, v.time_in, v.time_out, 
                     r.full_name as resident_name, u.email as resident_email, r.house_number 
              FROM visitors v
              JOIN residents r ON v.resident_id = r.user_id 
              JOIN users u ON r.user_id = u.id 
              WHERE BINARY v.qr_code_token = '$qr_token' LIMIT 1";
              
    $result = $conn->query($query);

    if (!$result) {
        echo json_encode(['success' => false, 'message' => 'Database Query Error: ' . $conn->error]);
        exit();
    }

    if ($result->num_rows > 0) {
        $pass = $result->fetch_assoc();
        
        $clean_visit_date = date('Y-m-d', strtotime(trim($pass['visit_date'])));

        // Check if the QR pass exit/visit date is in the past
        $is_late = false;
        $late_message = "";

        if ($clean_visit_date < $current_date) {
            $is_late = true;
            $late_message = "Warning: QR pass is late for the exit time (Pass Date: " . date('M d, Y', strtotime($clean_visit_date)) . ").";
        } else if ($clean_visit_date > $current_date) {
            echo json_encode([
                'success' => false, 
                'message' => "Access Denied: Pass is valid for future date " . date('M d, Y', strtotime($clean_visit_date)) . " (Today is " . date('M d, Y', strtotime($current_date)) . ")"
            ]);
            exit();
        }

        if (!in_array($pass['status'], ['approved', 'entered', 'used'])) {
            echo json_encode(['success' => false, 'message' => 'Access Denied: Pass is ' . strtoupper($pass['status'])]);
        } else {
            $visitor_id = $pass['id'];
            $now = date('Y-m-d H:i:s');

            if (empty($pass['time_in'])) {
                // First scan: Log Time In
                $action_type = 'TIME IN';
                $log_type = 'entry';
                $time_in_display = date('g:i A', strtotime($now));
                $time_out_display = null;
                
                $conn->query("UPDATE visitors SET time_in = '$now', status = 'entered' WHERE id = '$visitor_id'");

                // Send TIME IN notification to homeowner safely
                if (!empty($pass['resident_email']) && function_exists('sendVisitorNotification')) {
                    try {
                        sendVisitorNotification($pass['resident_email'], $pass['resident_name'], $pass['visitor_name'], $time_in_display, 'TIME IN');
                    } catch (\Throwable $e) {
                        error_log("Gmail Alert Failed (Time In): " . $e->getMessage());
                    }
                }
            } else if (empty($pass['time_out'])) {
                // Second scan: Log Time Out
                $action_type = 'TIME OUT';
                $log_type = 'exit';
                $time_in_display = date('g:i A', strtotime($pass['time_in']));
                $time_out_display = date('g:i A', strtotime($now));
                
                $conn->query("UPDATE visitors SET time_out = '$now', status = 'used' WHERE id = '$visitor_id'");

                // Send TIME OUT notification to homeowner safely
                if (!empty($pass['resident_email']) && function_exists('sendVisitorNotification')) {
                    try {
                        sendVisitorNotification($pass['resident_email'], $pass['resident_name'], $pass['visitor_name'], $time_out_display, 'TIME OUT');
                    } catch (\Throwable $e) {
                        error_log("Gmail Alert Failed (Time Out): " . $e->getMessage());
                    }
                }
            } else {
                // Subsequent scans: Already finished
                $action_type = 'PASS ALREADY USED';
                $log_type = 'duplicate_scan';
                $time_in_display = date('g:i A', strtotime($pass['time_in']));
                $time_out_display = date('g:i A', strtotime($pass['time_out']));
            }

            $log_sql = "INSERT INTO access_logs (person_id, person_type, log_type, timestamp) 
                        VALUES ('$visitor_id', 'visitor', '$log_type', '$now')";
            $conn->query($log_sql);

            echo json_encode([
                'success' => true,
                'action_type' => $action_type,
                'visitor_name' => $pass['visitor_name'],
                'resident_name' => $pass['resident_name'],
                'house_number' => $pass['house_number'],
                'message' => $pass['message'] ?? '',
                'time_in' => $time_in_display,
                'time_out' => $time_out_display,
                'is_late' => $is_late,
                'late_message' => $late_message
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