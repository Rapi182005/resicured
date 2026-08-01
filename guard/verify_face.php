<?php
header('Content-Type: application/json');
require_once '../config/database.php';

// Check if incoming request parameters are set
if (!isset($_POST['person_id']) || !isset($_POST['person_type'])) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing required POST parameters."
    ]);
    exit;
}

$person_id = intval($_POST['person_id']); 

// FIX: Convert incoming person type (e.g. "Resident" -> "resident") to lowercase 
// to prevent mismatch bugs with your database ENUMs and dashboard queries.
$person_type = strtolower(trim($_POST['person_type'])); 
$person_type = mysqli_real_escape_string($conn, $person_type); 

// 1. Look up the absolute latest log entry for this specific individual to check transit state
$check_query = "SELECT log_type FROM access_logs 
                WHERE person_id = ? AND person_type = ? 
                ORDER BY timestamp DESC LIMIT 1";

$stmt = $conn->prepare($check_query);
$stmt->bind_param("is", $person_id, $person_type);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 2. Toggle status alternating sequence: if last was 'entry', next is 'checkout'
$next_action = ($result && $result['log_type'] === 'entry') ? 'checkout' : 'entry';

// 3. Insert the alternating tracking log entry record
$insert_query = "INSERT INTO access_logs (person_id, person_type, log_type, timestamp) 
                 VALUES (?, ?, ?, NOW())";

$ins_stmt = $conn->prepare($insert_query);
$ins_stmt->bind_param("iss", $person_id, $person_type, $next_action);

if ($ins_stmt->execute()) {
    echo json_encode([
        "status" => "verified",
        "action" => $next_action, 
        "timestamp" => date('h:i A')
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Failed to write biometric tracking entry log to database."
    ]);
}
$ins_stmt->close();
$conn->close();
?>