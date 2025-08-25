<?php
session_start();
require_once '../includes/db.php'; // Adjust path as needed

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Basic validation
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            // 1. First check user_management table (admin/staff)
            $stmt = $conn->prepare("SELECT id, email, password, role FROM user_management WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                // Verify password - check if it's hashed or plaintext
                $password_verified = false;
                if (password_verify($password, $user['password'])) {
                    // Password is properly hashed
                    $password_verified = true;
                } elseif (trim($password) === trim($user['password'])) {
                    // Fallback for plaintext passwords (should be migrated to hashed)
                    $password_verified = true;
                    
                    // Optional: Update to hashed password
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE user_management SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_password, $user['id']);
                    $update_stmt->execute();
                }
                
                if ($password_verified) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_email'] = $user['email'];
                    
                    // Redirect based on role
                    if ($user['role'] === 'admin') {
                        header("Location: ../admin/admin_dashboard.php");
                    } else {
                        header("Location: ../investment/dashboard.php");
                    }
                    exit();
                } else {
                    $error = "Incorrect email or password.";
                }
            } else {
                // 2. If not found in user_management, check users table
                // ✅ ADDED BLOCK CHECK: Include is_blocked in the query
                $stmt2 = $conn->prepare("SELECT id, email, password, fullname, is_blocked FROM users WHERE email = ?");
                $stmt2->bind_param("s", $email);
                $stmt2->execute();
                $result2 = $stmt2->get_result();
                
                if ($result2 && $result2->num_rows === 1) {
                    $user2 = $result2->fetch_assoc();
                    
                    // ✅ CHECK IF USER IS BLOCKED BEFORE PASSWORD VERIFICATION
                    if ($user2['is_blocked'] == 1) {
                        $error = "Your account has been blocked. Please contact the administrator for assistance.";
                    } else {
                        // Verify password - check if it's hashed or plaintext
                        $password_verified = false;
                        if (password_verify($password, $user2['password'])) {
                            // Password is properly hashed
                            $password_verified = true;
                        } elseif (trim($password) === trim($user2['password'])) {
                            // Fallback for plaintext passwords (should be migrated to hashed)
                            $password_verified = true;
                            
                            // Optional: Update to hashed password
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                            $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                            $update_stmt->bind_param("si", $hashed_password, $user2['id']);
                            $update_stmt->execute();
                        }
                        
                        if ($password_verified) {
                            // ✅ Update last_login and set user as active
                            $now = date('Y-m-d H:i:s');
                            $update_login_stmt = $conn->prepare("UPDATE users SET last_login = ?, active = 'active' WHERE id = ?");
                            $update_login_stmt->bind_param("si", $now, $user2['id']);
                            $update_login_stmt->execute();

                            // ✅ Set session values
                            $_SESSION['user_id'] = $user2['id'];
                            $_SESSION['user_role'] = 'user'; // fixed role for users table
                            $_SESSION['user_email'] = $user2['email'];
                            $_SESSION['user_fullname'] = $user2['fullname'];

                            header("Location: ../investment/dashboard.php");
                            exit();
                        } else {
                            $error = "Incorrect email or password.";
                        }
                    }
                } else {
                    $error = "Incorrect email or password.";
                }
            }
        } catch (Exception $e) {
            $error = "Login failed. Please try again.";
            // Log the actual error for debugging (don't show to user)
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome Back - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
     <link rel="stylesheet" href="./css/login.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card login-card">
                    <div class="login-header">
                        <h2><i class="bi bi-person-circle me-2"></i>Welcome Back</h2>
                        <p>Sign in to your account to continue</p>
                    </div>
                    
                    <div class="login-body">
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
                        
                        <form method="POST" action="" id="loginForm">
                            <div class="form-floating">
                                <input type="email" 
                                       class="form-control" 
                                       id="email" 
                                       name="email" 
                                       placeholder="name@example.com"
                                       value="<?php echo htmlspecialchars($email ?? ''); ?>"
                                       required>
                                <label for="email"><i class="bi bi-envelope me-2"></i>Email Address</label>
                            </div>
                            
                            <div class="form-floating position-relative">
                                <input type="password" 
                                       class="form-control" 
                                       id="password" 
                                       name="password" 
                                       placeholder="Password"
                                       required>
                                <label for="password"><i class="bi bi-lock me-2"></i>Password</label>
                                <button type="button" class="password-toggle" onclick="togglePassword()">
                                    <i class="bi bi-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-login btn-lg" id="loginBtn">
                                    <span class="loading-spinner spinner-border spinner-border-sm me-2" role="status"></span>
                                    <span class="btn-text">
                                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                                    </span>
                                </button>
                            </div>
                            
                            <div class="text-center mt-3">
                                <a href="forgot_password.php" class="forgot-link">
                                    <i class="bi bi-key me-1"></i>Forgot Password?
                                </a>
                            </div>
                        </form>
                        
                        <div class="signup-link">
                            <p class="mb-0">Don't have an account? 
                                <a href="register.php">
                                    <i class="bi bi-person-plus me-1"></i>Create Account
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Password toggle functionality
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
        
        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const loginBtn = document.getElementById('loginBtn');
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Form validation
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
    </script>
</body>
</html>