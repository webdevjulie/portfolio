<?php
session_start();
require_once '../includes/db.php'; // Adjust path as needed

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

// Email configuration
define('SMTP_HOST', 'smtp.gmail.com'); 
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'webcashinvestment@gmail.com');
define('SMTP_PASSWORD', 'bcpc fhzf jmaz dyyg');
define('FROM_EMAIL', 'webcashinvestment@gmail.com');
define('FROM_NAME', 'WebCash Investment');

$error = '';
$success = '';

// Check if user has verified code and can access this page
if (!isset($_SESSION['temp_email']) || !isset($_SESSION['code_verified']) || $_SESSION['code_verified'] !== true) {
    header('Location: forgot_password.php');
    exit();
}

/**
 * Send email using PHPMailer
 */
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);
        $mail->addReplyTo(FROM_EMAIL, FROM_NAME);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Handle password reset form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $email = $_SESSION['temp_email'];
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $new_password)) {
        $error = "Password must contain at least one uppercase letter, one lowercase letter, and one number.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            // Get user table from reset token
            $stmt = $conn->prepare("SELECT user_table FROM password_reset_tokens WHERE email = ? AND expires_at > NOW()");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows === 1) {
                $row = $result->fetch_assoc();
                $user_table = $row['user_table'];
                
                // Update password in appropriate table
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE {$user_table} SET password = ? WHERE email = ?");
                $update_stmt->bind_param("ss", $hashed_password, $email);
                
                if ($update_stmt->execute()) {
                    // Delete used token
                    $delete_stmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
                    $delete_stmt->bind_param("s", $email);
                    $delete_stmt->execute();
                    
                    // Send confirmation email
                    $subject = "Password Reset Successful";
                    $confirmationBody = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <title>Password Reset Successful</title>
                        <style>
                            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                            .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px; text-align: center; }
                            .header { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
                            .content { background: #f8f9fa; padding: 20px; border-radius: 0 0 8px 8px; }
                            .footer { text-align: center; margin-top: 20px; color: #666; font-size: 14px; }
                        </style>
                    </head>
                    <body>
                        <div class='container'>
                            <div class='header'>
                                <h2>✅ Password Reset Successful</h2>
                            </div>
                            <div class='content'>
                                <div class='success'>
                                    <p><strong>Your password has been successfully reset!</strong></p>
                                    <p>Account: <strong>{$email}</strong></p>
                                    <p>Date: <strong>" . date('F j, Y \a\t g:i A') . "</strong></p>
                                </div>
                                <p style='margin-top: 20px;'>You can now log in with your new password. For security reasons, please:</p>
                                <ul style='text-align: left; max-width: 400px; margin: 0 auto;'>
                                    <li>Keep your password secure and confidential</li>
                                    <li>Log out from all other devices if necessary</li>
                                    <li>Contact support immediately if you didn't make this change</li>
                                </ul>
                                <p style='margin-top: 20px;'>Thank you for keeping your account secure!</p>
                                <p><strong>" . FROM_NAME . " Team</strong></p>
                            </div>
                            <div class='footer'>
                                <p>This is an automated message, please do not reply to this email.</p>
                                <p>&copy; " . date('Y') . " " . FROM_NAME . ". All rights reserved.</p>
                            </div>
                        </div>
                    </body>
                    </html>";
                    
                    sendEmail($email, $subject, $confirmationBody);
                    
                    // Clear session
                    unset($_SESSION['temp_email']);
                    unset($_SESSION['code_verified']);
                    
                    $success = "Password reset successfully! You will be redirected to login page in 5 seconds.";
                    echo "<script>
                        setTimeout(function(){ 
                            window.location.href = 'login.php'; 
                        }, 5000);
                    </script>";
                } else {
                    $error = "Failed to update password. Please try again.";
                }
            } else {
                $error = "Invalid or expired reset session. Please start over.";
                // Clear session and redirect
                unset($_SESSION['temp_email']);
                unset($_SESSION['code_verified']);
                echo "<script>
                    setTimeout(function(){ 
                        window.location.href = 'forgot_password.php'; 
                    }, 3000);
                </script>";
            }
        } catch (Exception $e) {
            $error = "An error occurred. Please try again.";
            error_log("Password reset error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./css/forgot.css">
    <style>
        .reset-card {
            max-width: 480px;
            margin: 2rem auto;
            border: none;
            border-radius: 15px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }
        
        .reset-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .reset-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.5rem;
        }
        
        .reset-body {
            padding: 2rem;
            background: white;
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            z-index: 5;
        }
        
        .password-requirements {
            margin: 0.75rem 0 1.5rem 0;
        }
        
        .strength-meter {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin: 0.5rem 0;
            overflow: hidden;
        }
        
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        .strength-weak { background: #dc3545; width: 25%; }
        .strength-fair { background: #ffc107; width: 50%; }
        .strength-good { background: #17a2b8; width: 75%; }
        .strength-strong { background: #28a745; width: 100%; }
        
        .btn-reset {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 2rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }
        
        .back-link {
            margin-top: 2rem;
            text-align: center;
            padding-top: 1.5rem;
            border-top: 1px solid #e9ecef;
        }
        
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .back-link a:hover {
            color: #764ba2;
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card reset-card">
                    <div class="reset-header">
                        <h2><i class="bi bi-lock-fill me-2"></i>Set New Password</h2>
                        <p class="mb-0">Create a strong password for your account</p>
                        <small class="text-white-50">
                            <i class="bi bi-envelope me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['temp_email']); ?>
                        </small>
                    </div>
                    
                    <div class="reset-body">
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
                        
                        <form method="POST" action="" id="resetForm">
                            <div class="form-floating position-relative mb-3">
                                <input type="password" 
                                       class="form-control" 
                                       id="new_password" 
                                       name="new_password" 
                                       placeholder="New Password"
                                       minlength="8"
                                       pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$"
                                       title="Password must contain at least one uppercase letter, one lowercase letter, and one number"
                                       required>
                                <label for="new_password"><i class="bi bi-lock me-2"></i>New Password</label>
                                <button type="button" class="password-toggle" onclick="togglePassword('new_password', 'toggleIcon1')">
                                    <i class="bi bi-eye" id="toggleIcon1"></i>
                                </button>
                            </div>
                            
                            <div class="strength-meter">
                                <div class="strength-bar" id="strengthBar"></div>
                            </div>
                            
                            <div class="password-requirements">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle me-1"></i>
                                    Password must be at least 8 characters with uppercase, lowercase, and number
                                </small>
                                <div class="mt-2" id="strengthText">
                                    <small class="text-muted">Password strength: <span id="strengthLevel">Enter password</span></small>
                                </div>
                            </div>
                            
                            <div class="form-floating position-relative mb-4">
                                <input type="password" 
                                       class="form-control" 
                                       id="confirm_password" 
                                       name="confirm_password" 
                                       placeholder="Confirm Password"
                                       minlength="8"
                                       required>
                                <label for="confirm_password"><i class="bi bi-lock-fill me-2"></i>Confirm Password</label>
                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                    <i class="bi bi-eye" id="toggleIcon2"></i>
                                </button>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="reset_password" class="btn btn-reset btn-lg">
                                    <i class="bi bi-arrow-clockwise me-2"></i>Update Password
                                </button>
                            </div>
                        </form>
                        
                        <div class="back-link">
                            <p class="mb-0">Need to verify code again? 
                                <a href="forgot_password.php">
                                    <i class="bi bi-arrow-left me-1"></i>Back to Verification
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
        function togglePassword(fieldId, iconId) {
            const passwordField = document.getElementById(fieldId);
            const toggleIcon = document.getElementById(iconId);
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.className = 'bi bi-eye-slash';
            } else {
                passwordField.type = 'password';
                toggleIcon.className = 'bi bi-eye';
            }
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            let strength = 0;
            let feedback = [];
            
            // Length check
            if (password.length >= 8) strength += 1;
            else feedback.push("at least 8 characters");
            
            // Lowercase check
            if (/[a-z]/.test(password)) strength += 1;
            else feedback.push("lowercase letter");
            
            // Uppercase check
            if (/[A-Z]/.test(password)) strength += 1;
            else feedback.push("uppercase letter");
            
            // Number check
            if (/\d/.test(password)) strength += 1;
            else feedback.push("number");
            
            // Special character bonus
            if (/[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)) strength += 1;
            
            return { strength, feedback };
        }
        
        // Update password strength indicator
        function updatePasswordStrength() {
            const password = document.getElementById('new_password').value;
            const strengthBar = document.getElementById('strengthBar');
            const strengthLevel = document.getElementById('strengthLevel');
            
            if (!password) {
                strengthBar.className = 'strength-bar';
                strengthLevel.textContent = 'Enter password';
                strengthLevel.className = 'text-muted';
                return;
            }
            
            const { strength, feedback } = checkPasswordStrength(password);
            
            // Update strength bar
            strengthBar.className = 'strength-bar';
            if (strength <= 2) {
                strengthBar.classList.add('strength-weak');
                strengthLevel.textContent = 'Weak';
                strengthLevel.className = 'text-danger';
            } else if (strength === 3) {
                strengthBar.classList.add('strength-fair');
                strengthLevel.textContent = 'Fair';
                strengthLevel.className = 'text-warning';
            } else if (strength === 4) {
                strengthBar.classList.add('strength-good');
                strengthLevel.textContent = 'Good';
                strengthLevel.className = 'text-info';
            } else {
                strengthBar.classList.add('strength-strong');
                strengthLevel.textContent = 'Strong';
                strengthLevel.className = 'text-success';
            }
        }
        
        // Password validation
        function validatePasswords() {
            const password = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const confirmField = document.getElementById('confirm_password');
            
            // Check if passwords match
            if (confirmPassword && password !== confirmPassword) {
                confirmField.setCustomValidity('Passwords do not match');
                confirmField.classList.add('is-invalid');
            } else {
                confirmField.setCustomValidity('');
                confirmField.classList.remove('is-invalid');
                if (confirmPassword) confirmField.classList.add('is-valid');
            }
        }
        
        // Event listeners
        document.getElementById('new_password').addEventListener('input', function() {
            updatePasswordStrength();
            validatePasswords();
        });
        
        document.getElementById('confirm_password').addEventListener('input', validatePasswords);
        
        // Auto-hide alerts after 8 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (!alert.classList.contains('alert-success') || !alert.textContent.includes('successfully')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    if (bsAlert) bsAlert.close();
                }
            });
        }, 8000);
        
        // Focus on password field
        document.getElementById('new_password').focus();
    </script>
</body>
</html>