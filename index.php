<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ResiCured - Platform Authentication</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/theme/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --subdivision-orange: #e66a00; /* Rich Professional Orange */
            --subdivision-amber: #ffaa00;  /* Subdued Amber Accent */
            --text-dark: #2d3748;          /* Slate Dark for Text */
        }

        body {
            background-color: #f7fafc;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }

        .auth-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(230, 106, 0, 0.05);
            width: 100%;
            max-width: 420px;
            padding: 40px 32px;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-icon {
            color: var(--subdivision-orange);
            font-size: 2.2rem;
            margin-bottom: 12px;
        }

        .brand-title {
            color: var(--text-dark);
            font-size: 24px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
        }

        .brand-subtitle {
            color: #718096;
            font-size: 13px;
            margin-top: 4px;
        }

        .form-label {
            color: #4a5568;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-bottom: 6px;
        }

        /* Fixed Alignment Input Group Container */
        .input-wrapper {
            position: relative;
            margin-bottom: 20px;
            width: 100%;
        }

        .input-wrapper input {
            width: 100%;
            padding: 12px 44px 12px 44px; /* Added right padding to prevent text hitting the eye icon */
            border: 1.5px solid #cbd5e1;
            border-radius: 6px;
            background-color: #f8fafc;
            font-size: 14px;
            color: var(--text-dark);
            transition: all 0.2s ease;
            box-sizing: border-box; /* Fixes alignment overflow */
        }

        .input-wrapper input:focus {
            outline: none;
            border-color: var(--subdivision-orange);
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(230, 106, 0, 0.1);
        }

        /* Left side field icon */
        .input-wrapper .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
            transition: color 0.2s ease;
            pointer-events: none;
        }

        .input-wrapper input:focus + .field-icon {
            color: var(--subdivision-orange);
        }

        /* Right side toggle icon button */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 15px;
            cursor: pointer;
            transition: color 0.2s ease;
            background: none;
            border: none;
            padding: 0;
        }

        .password-toggle:hover {
            color: var(--subdivision-orange);
        }

        .btn-submit {
            background: linear-gradient(90deg, var(--subdivision-orange) 0%, var(--subdivision-amber) 100%);
            border: none;
            color: #ffffff;
            font-weight: 600;
            font-size: 14px;
            padding: 14px;
            border-radius: 6px;
            width: 100%;
            transition: opacity 0.2s ease;
            margin-top: 8px;
        }

        .btn-submit:hover {
            opacity: 0.95;
            color: #ffffff;
        }

        .alert-custom {
            font-size: 13px;
            background-color: #fff5f5;
            border: 1px solid #fed7d7;
            color: #c53030;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 20px;
            transition: opacity 0.5s ease;
        }
        
        .alert-success-custom {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="brand-header">
        <i class="fa fa-shield-halved brand-icon"></i>
        <h1 class="brand-title">ResiCured</h1>
        <p class="brand-subtitle">Residential Access & Portal Gateway</p>
    </div>

    <?php if(isset($_GET['error'])): ?>
        <?php 
            $isLogout = (strpos(strtolower($_GET['error']), 'logged out') !== false);
            $alertClass = $isLogout ? 'alert-custom alert-success-custom' : 'alert-custom';
            $iconClass = $isLogout ? 'fa-circle-check' : 'fa-circle-with-check';
        ?>
        <div id="notificationAlert" class="<?php echo $alertClass; ?> d-flex align-items-center">
            <i class="fa <?php echo $isLogout ? 'fa-circle-check' : 'fa-circle-exclamation'; ?> me-2 fs-6"></i>
            <div><?php echo htmlspecialchars($_GET['error']); ?></div>
        </div>
    <?php endif; ?>

    <form action="login_process.php" method="POST">
        <div>
            <label class="form-label">Username</label>
            <div class="input-wrapper">
                <input type="text" name="username" required autocomplete="off" placeholder="Enter identifier">
                <i class="fa fa-user field-icon"></i>
            </div>
        </div>

        <div>
            <label class="form-label">Password</label>
            <div class="input-wrapper">
                <input type="password" name="password" id="passwordField" required placeholder="••••••••">
                <i class="fa fa-key field-icon"></i>
                <button type="button" id="togglePasswordBtn" class="password-toggle">
                    <i class="fa fa-eye" id="toggleIcon"></i>
                </button>
            </div>
        </div>

        <button type="submit" name="login_btn" class="btn btn-submit">
            Secure Sign In
        </button>
    </form>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        // --- 1. Notification Alert Box Dismissal ---
        const alertBox = document.getElementById("notificationAlert");
        if (alertBox) {
            setTimeout(function() {
                alertBox.style.opacity = "0";
                setTimeout(function() {
                    alertBox.remove();
                }, 500);
            }, 4000);
        }

        // --- 2. Password Visibility Toggle ---
        const passwordField = document.getElementById("passwordField");
        const toggleBtn = document.getElementById("togglePasswordBtn");
        const toggleIcon = document.getElementById("toggleIcon");

        toggleBtn.addEventListener("click", function() {
            // Check current input field context type
            if (passwordField.type === "password") {
                passwordField.type = "text";
                toggleIcon.classList.remove("fa-eye");
                toggleIcon.classList.add("fa-eye-slash"); // Swaps icon to hidden eye state
            } else {
                passwordField.type = "password";
                toggleIcon.classList.remove("fa-eye-slash");
                toggleIcon.classList.add("fa-eye"); // Restores open eye state
            }
        });
    });
</script>

</body>
</html>