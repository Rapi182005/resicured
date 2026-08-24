<?php
session_start();

// Redirect active sessions directly to their respective portals
if (isset($_SESSION['role'])) {
    $role = strtolower(trim($_SESSION['role']));
    if ($role === 'admin') {
        header("Location: admin/dashboard.php");
        exit();
    } elseif ($role === 'guard') {
        header("Location: guard/dashboard.php");
        exit();
    } elseif ($role === 'resident') {
        header("Location: resident/dashboard.php");
        exit();
    }
}

$error = isset($_GET['error']) ? trim($_GET['error']) : '';
$success = isset($_GET['success']) ? trim($_GET['success']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Residential Access & Portal Gateway</title>
    
    <!-- Google Fonts & Font Awesome Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --brand-orange: #f97316;
            --brand-orange-hover: #ea580c;
            --brand-dark: #0f172a;
            --input-bg: #ffffff;
            --input-border: #cbd5e1;
            --text-muted: #64748b;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #0f172a;
            background-image: 
                radial-gradient(at 0% 0%, rgba(249, 115, 22, 0.15) 0px, transparent 45%),
                radial-gradient(at 100% 100%, rgba(30, 41, 59, 0.8) 0px, transparent 50%),
                radial-gradient(at 50% 50%, rgba(15, 23, 42, 0.9) 0px, transparent 100%);
            background-repeat: no-repeat;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 24px 16px;
            color: #1e293b;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            border-radius: 20px;
            padding: 40px 32px 32px 32px;
            box-shadow: 
                0 20px 25px -5px rgba(0, 0, 0, 0.2), 
                0 8px 10px -6px rgba(0, 0, 0, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            position: relative;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .logo-icon-wrapper {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border: 1px solid rgba(249, 115, 22, 0.25);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--brand-orange);
            font-size: 26px;
            margin-bottom: 16px;
            box-shadow: 0 10px 15px -3px rgba(249, 115, 22, 0.12);
        }

        .brand-title {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.5px;
            color: var(--brand-dark);
            margin: 0 0 4px 0;
        }

        .brand-subtitle {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 500;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            color: #334155;
            margin-bottom: 6px;
        }

        .custom-input-group {
            position: relative;
            display: flex;
            align-items: center;
            width: 100%;
        }

        .input-icon-left {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 2;
        }

        .custom-input {
            width: 100%;
            height: 46px;
            padding: 10px 14px 10px 42px;
            font-size: 14px;
            font-weight: 500;
            color: #0f172a;
            background-color: var(--input-bg);
            border: 1.5px solid var(--input-border);
            border-radius: 10px;
            outline: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .custom-input::placeholder {
            color: #94a3b8;
            font-weight: 400;
        }

        .custom-input:hover {
            border-color: #94a3b8;
        }

        .custom-input:focus {
            background-color: #ffffff;
            border-color: var(--brand-orange);
            box-shadow: 0 0 0 3.5px rgba(249, 115, 22, 0.15);
        }

        .custom-input-group:focus-within .input-icon-left {
            color: var(--brand-orange);
        }

        .toggle-password-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px 8px;
            border-radius: 6px;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease, background-color 0.2s ease;
            z-index: 2;
        }

        .toggle-password-btn:hover {
            color: var(--brand-dark);
            background-color: #f1f5f9;
        }

        .btn-submit {
            width: 100%;
            height: 46px;
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
            cursor: pointer;
            box-shadow: 0 8px 16px -4px rgba(234, 88, 12, 0.35);
            transition: all 0.2s ease;
            margin-top: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #fb923c 0%, #ea580c 100%);
            transform: translateY(-1px);
            box-shadow: 0 10px 20px -4px rgba(234, 88, 12, 0.45);
        }

        .btn-submit:active {
            transform: translateY(0);
            box-shadow: 0 4px 8px -2px rgba(234, 88, 12, 0.3);
        }

        .custom-alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: opacity 0.5s ease, transform 0.5s ease, margin-bottom 0.5s ease, padding 0.5s ease;
            opacity: 1;
            transform: translateY(0);
        }

        .custom-alert.fade-out {
            opacity: 0;
            transform: translateY(-8px);
            margin-bottom: 0;
            padding-top: 0;
            padding-bottom: 0;
            overflow: hidden;
        }

        .custom-alert-danger {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .custom-alert-success {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .portal-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="brand-header">
        <div class="logo-icon-wrapper">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <h1 class="brand-title">ResiCured</h1>
        <p class="brand-subtitle">Residential Access & Portal Gateway</p>
    </div>

    <!-- NOTIFICATION ALERTS -->
    <?php if (!empty($error)): ?>
        <?php 
            $is_logout_msg = stripos($error, 'logged out') !== false || stripos($error, 'success') !== false;
            $alert_class = $is_logout_msg ? 'custom-alert-success' : 'custom-alert-danger';
            $icon_class  = $is_logout_msg ? 'fa-circle-check' : 'fa-circle-exclamation';
        ?>
        <div class="custom-alert <?php echo $alert_class; ?>" id="autoDismissAlert" role="alert">
            <i class="fa-solid <?php echo $icon_class; ?> fs-6"></i>
            <div><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="custom-alert custom-alert-success" id="autoDismissAlert" role="alert">
            <i class="fa-solid fa-circle-check fs-6"></i>
            <div><?php echo htmlspecialchars($success); ?></div>
        </div>
    <?php endif; ?>

    <!-- LOGIN FORM -->
    <form action="login_process.php" method="POST">
        <div class="form-group">
            <label class="field-label" for="usernameInput">Username</label>
            <div class="custom-input-group">
                <input 
                    type="text" 
                    id="usernameInput"
                    name="username" 
                    class="custom-input" 
                    placeholder="Enter identifier" 
                    required 
                    autocomplete="username"
                >
                <i class="fa-regular fa-user input-icon-left"></i>
            </div>
        </div>

        <div class="form-group">
            <label class="field-label" for="passwordInput">Password</label>
            <div class="custom-input-group">
                <input 
                    type="password" 
                    id="passwordInput"
                    name="password" 
                    class="custom-input" 
                    placeholder="••••••••" 
                    style="padding-right: 42px;"
                    required 
                    autocomplete="current-password"
                >
                <i class="fa-solid fa-key input-icon-left"></i>
                <button type="button" class="toggle-password-btn" id="togglePasswordBtn" aria-label="Toggle password visibility">
                    <i class="fa-regular fa-eye" id="toggleIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" name="login_btn" value="1" class="btn-submit">
            Secure Sign In
        </button>
    </form>

    <div class="portal-footer">
        <i class="fa-solid fa-lock"></i>
        <span>End-to-End Encrypted Session</span>
    </div>
</div>

<script>
    // Toggle Password Visibility
    const togglePasswordBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('passwordInput');
    const toggleIcon = document.getElementById('toggleIcon');

    if (togglePasswordBtn && passwordInput && toggleIcon) {
        togglePasswordBtn.addEventListener('click', function () {
            const isPassword = passwordInput.getAttribute('type') === 'password';
            passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
            
            toggleIcon.classList.toggle('fa-eye', !isPassword);
            toggleIcon.classList.toggle('fa-eye-slash', isPassword);
        });
    }

    // Auto-dismiss alert after exactly 3.5 seconds (3500ms)
    const alertBox = document.getElementById('autoDismissAlert');
    if (alertBox) {
        setTimeout(function() {
            alertBox.classList.add('fade-out');
            
            setTimeout(function() {
                alertBox.remove();
            }, 500);

            // Clean GET query params from URL so reloading doesn't show alert again
            if (window.history.replaceState) {
                window.history.replaceState(null, document.title, window.location.pathname);
            }
        }, 3500);
    }
</script>

</body>
</html>