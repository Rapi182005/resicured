<?php
session_start();
require_once '../config/database.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'resident') {
    header("Location: ../index.php");
    exit();
}

$id = $_GET['id'] ?? 0;

// Handle Form Submission
if (isset($_POST['update_btn'])) {
    $name = $conn->real_escape_string($_POST['visitor_name']);
    $date = $conn->real_escape_string($_POST['visit_date']);
    $conn->query("UPDATE visitors SET visitor_name='$name', visit_date='$date' WHERE id='$id' AND resident_id='{$_SESSION['user_id']}'");
    header("Location: dashboard.php");
    exit();
}

// Fetch current data
$result = $conn->query("SELECT * FROM visitors WHERE id='$id' AND resident_id='{$_SESSION['user_id']}'");
$data = $result->fetch_assoc();
?>

<form method="POST">
    <input type="text" name="visitor_name" value="<?php echo htmlspecialchars($data['visitor_name']); ?>" required>
    <input type="date" name="visit_date" value="<?php echo $data['visit_date']; ?>" required>
    <button type="submit" name="update_btn">Save Changes</button>
</form>