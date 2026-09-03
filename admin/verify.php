<?php
session_start();
require_once 'config/database.php';

$message = '';
$status_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_code_btn'])) {
    $email = trim($_POST['email']);
    $code = trim($_POST['verification_code']);

    if (!empty($email) && !empty($code)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND verification_code = ? AND role = 'resident'");
        $stmt->bind_param("ss", $email, $code);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 1) {
            $update = $conn->prepare("UPDATE users SET is_verified = 1, verification_code = NULL WHERE email = ?");
            $update->bind_param("s", $email);
            $update->execute();

            $status_type = "success";
            $message = "Your email has been verified successfully! You can now log in.";
        } else {
            $status_type = "danger";
            $message = "Invalid email address or verification code.";
        }
    } else {
        $status_type = "danger";
        $message = "Please complete all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ResiCured - Activate Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center justify-content-center min-vh-100">
    <div class="card border-0 shadow-sm p-4" style="max-width: 400px; width: 100%; border-radius: 12px;">
        <h4 class="fw-bold text-dark text-center mb-1">Verify Account</h4>
        <p class="text-muted small text-center mb-4">Enter the 6-digit code sent to your Gmail.</p>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $status_type; ?> small py-2"><?= $message; ?></div>
        <?php endif; ?>

        <form method="POST" action="verify.php">
            <div class="mb-3">
                <label class="form-label small fw-bold">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="name@domain.com" required>
            </div>
            <div class="mb-4">
                <label class="form-label small fw-bold">6-Digit Verification Code</label>
                <input type="text" name="verification_code" class="form-control text-center fw-bold fs-5" maxlength="6" placeholder="000000" required>
            </div>
            <button type="submit" name="verify_code_btn" class="btn btn-warning text-white fw-bold w-100 py-2">Activate Profile</button>
        </form>
    </div>
</body>
</html>