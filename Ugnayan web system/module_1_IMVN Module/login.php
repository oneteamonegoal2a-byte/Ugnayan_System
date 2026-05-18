<?php
require_once 'includes/db.php';

$residentCount = (int) $pdo->query('SELECT COUNT(*) FROM residents')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UGNAYAN - Residence Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css?v=20260518-0848">
</head>
<body>
    <div class="split-layout">
        <div class="brand-side">
            <div class="brand-content">
                <div class="logo-wrapper">
                    <img src="assets/barangay-seal.jpg?v=20260518-0848" alt="Barangay seal">
                </div>
                <h1>UGNAYAN</h1>
                <h2>Barangay Web Management System</h2>
                <p>Serving and connecting our community through modern, efficient, and transparent barangay governance.</p>

                <div class="stats-grid">
                    <div class="stat-box">
                        <h3>Residence</h3>
                        <div class="stat-value"><?= number_format($residentCount) ?></div>
                        <span>Total registered residents</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-side">
            <div class="form-container">
                <div class="form-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to your account to continue</p>
                </div>

                <form id="loginForm" action="actions/login_process.php" method="POST">
                    <div class="input-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>

                    <div class="input-group">
                        <label>Password</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" placeholder="Password" required>
                            <i class="fa-regular fa-eye-slash toggle-password"></i>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">Sign In</button>
                </form>

                <div class="form-footer" id="footer-resident">Don't have an account? <a href="register.php">Register as Resident</a></div>
                <div class="form-footer admin-note">Admin login: admin@ugnayan.com / admin123</div>
            </div>

            <div class="copyright">&copy; 2026 Barangay Management System - Ugnayan</div>
        </div>
    </div>

    <script>
        document.querySelector('.toggle-password').onclick = function() {
            const i = document.querySelector('input[name="password"]');
            i.type = i.type === 'password' ? 'text' : 'password';
            this.classList.toggle('fa-eye');
            this.classList.toggle('fa-eye-slash');
        };
    </script>
</body>
</html>
