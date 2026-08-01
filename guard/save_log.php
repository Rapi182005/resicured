<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') {
    echo json_encode(["status" => "error", "message" => "Unauthorized session handle context."]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host = "localhost";
    $user = "root";
    $pass = "";
    $db_name = "resicured_db";

    // Grab post parameters
    $incoming_type = isset($_POST['person_type']) ? trim($_POST['person_type']) : '';
    $role_type = isset($_POST['role_type']) ? trim($_POST['role_type']) : '';
    
    // Determine the exact person type
    if (strtolower($incoming_type) === 'resident' || strtolower($role_type) === 'resident') {
        $person_type = 'resident';
    } elseif (strtolower($incoming_type) === 'visitor' || strtolower($role_type) === 'visitor') {
        $person_type = 'visitor';
    } else {
        $person_type = 'frequent_personnel';
    }

    $person_id = isset($_POST['person_id']) ? intval($_POST['person_id']) : 0;
    $log_type = isset($_POST['log_type']) ? trim($_POST['log_type']) : '';

    if ($person_id === 0 || empty($log_type)) {
        echo json_encode(["status" => "error", "message" => "Incomplete request parameter metrics."]);
        exit();
    }

    try {
        $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $query = "INSERT INTO access_logs (person_type, person_id, log_type, timestamp) 
                  VALUES (:person_type, :person_id, :log_type, NOW())";
                  
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':person_type', $person_type);
        $stmt->bindParam(':person_id', $person_id);
        $stmt->bindParam(':log_type', $log_type);
        $stmt->execute();

        echo json_encode(["status" => "success", "message" => "Log entry mapped successfully."]);
    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database exception: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid protocol method access request structure."]);
}
?>