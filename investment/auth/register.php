<?php
session_start();

// Database connection
$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

try {
    $conn = new mysqli($host, $username, $password, $dbname);
    // Set charset to avoid character encoding issues
    $conn->set_charset("utf8mb4");
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

$error = '';
$success = '';

if (isset($_GET['ref']) && is_numeric($_GET['ref'])) {
    $_SESSION['referrer_id'] = intval($_GET['ref']);
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $referrerId = isset($_SESSION['referrer_id']) ? intval($_SESSION['referrer_id']) : null;
    $phone = trim($_POST['phone']);

    try {
        // Start transaction to handle any conflicts
        $conn->autocommit(FALSE);
        
        // Check if email already exists
        $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
        if (!$check) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $check->bind_param("s", $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "Email already registered.";
            $conn->rollback();
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            // Validate referral ID if provided
            if (!empty($referrerId)) {
                $refQuery = $conn->prepare("SELECT id FROM users WHERE id = ?");
                if (!$refQuery) {
                    throw new Exception("Referral check prepare failed: " . $conn->error);
                }
                
                $refQuery->bind_param("i", $referrerId);
                $refQuery->execute();
                $refQuery->store_result();
                
                if ($refQuery->num_rows !== 1) {
                    $referrerId = null; // invalid referral
                }
                $refQuery->close();
            }

            // Insert new user with explicit column specification (removed referral_earnings)
            $insert = $conn->prepare("INSERT INTO users (fullname, email, phone, password, referral_id, referral_total, referral_earnings, created_at, status, active, investment_status) VALUES (?, ?, ?, ?, ?, 0.00, 0.00, NOW(), 'active', 'active', 'pending')");

            if (!$insert) {
                throw new Exception("Insert prepare failed: " . $conn->error);
            }
            
            $insert->bind_param("ssssi", $fullname, $email, $phone, $hashedPassword, $referrerId);

            if ($insert->execute()) {
                $newUserId = $insert->insert_id;
                
                // Manually update referrer's total_referrals count after user insertion
                if (!empty($referrerId)) {
                    $updateReferrer = $conn->prepare("UPDATE users SET total_referrals = total_referrals + 1 WHERE id = ?");
                    if ($updateReferrer) {
                        $updateReferrer->bind_param("i", $referrerId);
                        $updateReferrer->execute();
                        $updateReferrer->close();
                    }
                }
                
                $_SESSION['user_id'] = $newUserId;
                $conn->commit();
                $success = "Registration successful! Redirecting...";
                
                // Clear referrer session after successful registration
                unset($_SESSION['referrer_id']);
                
                header("refresh:2;url=login.php");
                exit();
            } else {
                throw new Exception("Registration failed: " . $insert->error);
            }
            
        }
        
        $check->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Registration failed: " . $e->getMessage();
        
        // Log the error for debugging (optional)
        error_log("Registration error: " . $e->getMessage());
    } finally {
        $conn->autocommit(TRUE);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Join Us</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --light-orange: #ff8c42;
            --dark-orange: #e55a2b;
            --orange-gradient: linear-gradient(135deg, #ff6b35 0%, #ff8c42 100%);
            --shadow-orange: rgba(255, 107, 53, 0.2);
        }
        
        * {
            font-family: 'Inter', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #ff6b35 0%, #ff8c42 50%, #ffb347 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container-fluid {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        
        .row {
            width: 100%;
            margin: 0;
        }
        
        .register-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.2);
            overflow: hidden;
            margin-left: 410px;
            max-width: 500px;
            width: 100%;
        }
        
        .register-header {
            background: var(--orange-gradient);
            color: white;
            padding: 2rem;
            text-align: center;
            position: relative;
        }
        
        .register-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            opacity: 0.3;
        }
        
        .register-header h2 {
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .register-header p {
            font-weight: 300;
            opacity: 0.9;
            position: relative;
            z-index: 1;
            margin: 0;
        }
        
        .register-body {
            padding: 2.5rem;
        }
        
        .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .form-floating > .form-control {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            height: 58px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-floating > .form-control:focus {
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 0.2rem var(--shadow-orange);
        }
        
        .form-floating > label {
            color: #6c757d;
            font-weight: 500;
        }
        
        .btn-register {
            background: var(--orange-gradient);
            border: none;
            border-radius: 12px;
            padding: 0.875rem 2rem;
            font-weight: 600;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-register::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s;
        }
        
        .btn-register:hover::before {
            left: 100%;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--shadow-orange);
        }
        
        .alert {
            border: none;
            border-radius: 12px;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #dc3545, #e74c3c);
            color: white;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #28a745, #32c754);
            color: white;
        }
        
        .login-link {
            text-align: center;
            padding: 1.5rem 0 0 0;
            border-top: 1px solid #e9ecef;
            margin-top: 1.5rem;
        }
        
        .login-link a {
            color: var(--primary-orange);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .login-link a:hover {
            color: var(--dark-orange);
            text-decoration: underline;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
            padding: 5px;
            border-radius: 4px;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover {
            color: var(--primary-orange);
        }
        
        .loading-spinner {
            display: none;
        }
        
        .btn-register.loading .loading-spinner {
            display: inline-block;
        }
        
        .btn-register.loading .btn-text {
            display: none;
        }
        
        .password-strength {
            height: 4px;
            border-radius: 2px;
            background: #e9ecef;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #28a745; width: 75%; }
        .strength-strong { background: #198754; width: 100%; }
        
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        
        @media (max-width: 576px) {
            .register-card {
                margin: 10px;
                border-radius: 15px;
            }
            
            .register-header {
                padding: 1.5rem;
            }
            
            .register-header h2 {
                font-size: 1.5rem;
            }
            
            .register-body {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card register-card">
                    <div class="register-header">
                        <h2><i class="bi bi-person-plus-fill me-2"></i>Join Us</h2>
                        <p>Create your account to get started</p>
                    </div>
                    
                    <div class="register-body">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success" role="alert">
                                <i class="bi bi-check-circle-fill me-2"></i>
                                <?php echo htmlspecialchars($success); ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="" id="registerForm" class="needs-validation" novalidate>
                            <div class="form-floating">
                                <input type="text" 
                                       class="form-control" 
                                       id="fullname" 
                                       name="fullname" 
                                       placeholder="John Doe"
                                       required>
                                <label for="fullname"><i class="bi bi-person me-2"></i>Full Name</label>
                                <div class="invalid-feedback">
                                    Please provide your full name.
                                </div>
                            </div>
                            
                            <div class="form-floating">
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       placeholder="name@example.com"
                                       required>
                                <label for="email"><i class="bi bi-envelope me-2"></i>Email Address</label>
                                <div class="invalid-feedback">
                                    Please provide a valid email address.
                                </div>
                            </div>

                            <div class="form-floating">
                                <input type="tel" 
                                    class="form-control" 
                                    id="phone" 
                                    name="phone" 
                                    placeholder="+1234567890" 
                                    pattern="^\+?\d{7,15}$"
                                    required>
                                <label for="phone"><i class="bi bi-telephone me-2"></i>Phone Number</label>
                                <div class="invalid-feedback">
                                    Please enter a valid phone number with 7 to 15 digits. You can include a leading "+".
                                </div>
                            </div>

                            <div class="form-floating position-relative">
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Password"
                                       minlength="6"
                                       required>
                                <label for="password"><i class="bi bi-lock me-2"></i>Password</label>
                                <button type="button" class="password-toggle" onclick="togglePassword()">
                                    <i class="bi bi-eye" id="toggleIcon"></i>
                                </button>
                                <div class="invalid-feedback">
                                    Password must be at least 6 characters long.
                                </div>
                                <div class="password-strength">
                                    <div class="password-strength-bar" id="strengthBar"></div>
                                </div>
                                <div class="form-text" id="strengthText">
                                    Password strength: <span id="strengthLevel">Enter password</span>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-register btn-lg" id="registerBtn">
                                    <span class="loading-spinner spinner-border spinner-border-sm me-2" role="status"></span>
                                    <span class="btn-text">
                                        <i class="bi bi-person-check me-2"></i>Create Account
                                    </span>
                                </button>
                            </div>
                        </form>
                        
                        <div class="login-link">
                            <p class="mb-0">Already have an account? 
                                <a href="login.php">
                                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In Here
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }
        
        function checkPasswordStrength(password) {
            let score = 0;
            let feedback = [];
            
            if (password.length >= 8) score += 1;
            else feedback.push("at least 8 characters");
            
            if (/[a-z]/.test(password)) score += 1;
            else feedback.push("lowercase letters");
            
            if (/[A-Z]/.test(password)) score += 1;
            else feedback.push("uppercase letters");
            
            if (/\d/.test(password)) score += 1;
            else feedback.push("numbers");
            
            if (/[^A-Za-z0-9]/.test(password)) score += 1;
            else feedback.push("special characters");
            
            return { score, feedback };
        }
        
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthLevel = document.getElementById('strengthLevel');
            
            if (password.length === 0) {
                strengthBar.className = 'password-strength-bar';
                strengthLevel.textContent = 'Enter password';
                return;
            }
            
            const { score } = checkPasswordStrength(password);
            
            strengthBar.className = 'password-strength-bar';
            
            if (score <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthLevel.textContent = 'Weak';
            } else if (score === 3) {
                strengthBar.classList.add('strength-fair');
                strengthLevel.textContent = 'Fair';
            } else if (score === 4) {
                strengthBar.classList.add('strength-good');
                strengthLevel.textContent = 'Good';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthLevel.textContent = 'Strong';
            }
        });
        
        document.getElementById('registerForm').addEventListener('submit', function() {
            const registerBtn = document.getElementById('registerBtn');
            registerBtn.classList.add('loading');
            registerBtn.disabled = true;
        });
        
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        (function() {
            'use strict';
            window.addEventListener('load', function() {
                const forms = document.getElementsByClassName('needs-validation');
                const validation = Array.prototype.filter.call(forms, function(form) {
                    form.addEventListener('submit', function(event) {
                        if (form.checkValidity() === false) {
                            event.preventDefault();
                            event.stopPropagation();
                        }
                        form.classList.add('was-validated');
                    }, false);
                });
            }, false);
        })();
        
        document.getElementById('email').addEventListener('blur', function() {
            const email = this.value;
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            
            if (email && !emailRegex.test(email)) {
                this.setCustomValidity('Please enter a valid email address');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>