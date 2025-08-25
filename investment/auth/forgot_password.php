<?php
session_start();
require_once '../includes/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require 'vendor/autoload.php';

// Email config
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'webcashinvestment@gmail.com');
define('SMTP_PASSWORD', 'bcpc fhzf jmaz dyyg');
define('FROM_EMAIL', 'webcashinvestment@gmail.com');
define('FROM_NAME', 'WebCash Investment');

$error = $success = '';
$step = isset($_SESSION['temp_email']) ? (isset($_SESSION['code_verified']) && $_SESSION['code_verified'] ? 'reset' : 'verify') : 'email';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

function getEmailTemplate($code, $email) {
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'><title>Password Reset</title>
    <style>body{font-family:Arial,sans-serif;color:#333}.container{max-width:600px;margin:0 auto;padding:20px}.header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:30px;text-align:center;border-radius:10px 10px 0 0}.content{background:#f8f9fa;padding:30px;border-radius:0 0 10px 10px}.code-box{background:white;border:2px solid #667eea;border-radius:8px;padding:20px;text-align:center;margin:20px 0}.code{font-size:32px;font-weight:bold;color:#667eea;letter-spacing:5px}</style>
    </head>
    <body>
        <div class='container'>
            <div class='header'><h1>🔐 Password Reset</h1></div>
            <div class='content'>
                <p>Your verification code for <strong>{$email}</strong>:</p>
                <div class='code-box'><div class='code'>{$code}</div></div>
                <p><strong>⚠️ Code expires in 3 minutes. Don't share this code.</strong></p>
            </div>
        </div>
    </body>
    </html>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_code'])) {
        $email = trim($_POST['email']);
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check both tables
                $user_table = '';
                $stmt = $conn->prepare("SELECT id FROM user_management WHERE email = ?");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                if ($stmt->get_result()->num_rows > 0) {
                    $user_table = 'user_management';
                } else {
                    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    if ($stmt->get_result()->num_rows > 0) $user_table = 'users';
                }

                if ($user_table) {
                    $code = sprintf('%06d', mt_rand(100000, 999999));
                    $expires = date('Y-m-d H:i:s', time() + 180); // 3 minutes from now
                    
                    // Use REPLACE to avoid duplicate key issues
                    $stmt = $conn->prepare("REPLACE INTO password_reset_tokens (email, token, expires_at, user_table, created_at) VALUES (?, ?, ?, ?, NOW())");
                    $stmt->bind_param("ssss", $email, $code, $expires, $user_table);
                    
                    if ($stmt->execute() && sendEmail($email, "Password Reset Code", getEmailTemplate($code, $email))) {
                        $_SESSION['temp_email'] = $email;
                        $_SESSION['code_verified'] = false;
                        $success = "Verification code sent! Check your email. Code expires in 3 minutes.";
                        $step = 'verify';
                    } else {
                        $error = "Failed to send email. Try again.";
                    }
                } else {
                    // Security: Don't reveal if email exists
                    $_SESSION['temp_email'] = $email;
                    $_SESSION['code_verified'] = false;
                    $success = "If your email is registered, you'll receive a code.";
                    $step = 'verify';
                }
            } catch (Exception $e) {
                $error = "An error occurred. Please try again.";
                error_log("Send code error: " . $e->getMessage());
            }
        }
    } 
    elseif (isset($_POST['verify_code'])) {
        $email = $_SESSION['temp_email'] ?? '';
        $code = trim($_POST['verification_code']);
        
        if (empty($email)) {
            $error = "Session expired. Start over.";
            $step = 'email';
        } elseif (empty($code) || !preg_match('/^\d{6}$/', $code)) {
            $error = "Enter a valid 6-digit code.";
        } else {
            try {
                // Fixed: Use direct timestamp comparison
                $current_time = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("SELECT token FROM password_reset_tokens WHERE email = ? AND expires_at > ? ORDER BY created_at DESC LIMIT 1");
                $stmt->bind_param("ss", $email, $current_time);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    // Fixed: Direct string comparison, both should be strings
                    if (trim($row['token']) === trim($code)) {
                        $_SESSION['code_verified'] = true;
                        $success = "Code verified! Redirecting...";
                        $step = 'reset';
                        echo "<script>setTimeout(() => window.location.href = window.location.href, 2000);</script>";
                    } else {
                        $error = "Invalid code. Try again.";
                    }
                } else {
                    $error = "Code expired or invalid. Request new code.";
                    $step = 'email';
                    unset($_SESSION['temp_email'], $_SESSION['code_verified']);
                }
            } catch (Exception $e) {
                $error = "Verification failed. Try again.";
                error_log("Verify error: " . $e->getMessage());
            }
        }
    }
    elseif (isset($_POST['reset_password'])) {
        $email = $_SESSION['temp_email'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        $confirm_pass = $_POST['confirm_password'] ?? '';
        
        if (!($_SESSION['code_verified'] ?? false)) {
            $error = "Verification required.";
            $step = 'verify';
        } elseif (empty($new_pass) || strlen($new_pass) < 8 || !preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $new_pass)) {
            $error = "Password must be 8+ chars with uppercase, lowercase, and number.";
        } elseif ($new_pass !== $confirm_pass) {
            $error = "Passwords don't match.";
        } else {
            try {
                $stmt = $conn->prepare("SELECT user_table FROM password_reset_tokens WHERE email = ? ORDER BY created_at DESC LIMIT 1");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $user_table = $result->fetch_assoc()['user_table'];
                    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                    
                    $stmt = $conn->prepare("UPDATE {$user_table} SET password = ? WHERE email = ?");
                    $stmt->bind_param("ss", $hashed, $email);
                    
                    if ($stmt->execute()) {
                        // Clean up
                        $stmt = $conn->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
                        $stmt->bind_param("s", $email);
                        $stmt->execute();
                        
                        unset($_SESSION['temp_email'], $_SESSION['code_verified']);
                        $success = "Password reset successfully! Redirecting to login...";
                        echo "<script>setTimeout(() => window.location.href = 'login.php', 3000);</script>";
                    } else {
                        $error = "Failed to update password.";
                    }
                } else {
                    $error = "Session expired. Start over.";
                    $step = 'email';
                }
            } catch (Exception $e) {
                $error = "Reset failed. Try again.";
                error_log("Reset error: " . $e->getMessage());
            }
        }
    }
    elseif (isset($_POST['resend_code'])) {
        $email = $_SESSION['temp_email'] ?? '';
        if ($email) {
            $code = sprintf('%06d', mt_rand(100000, 999999));
            $expires = date('Y-m-d H:i:s', time() + 180);
            
            $stmt = $conn->prepare("UPDATE password_reset_tokens SET token = ?, expires_at = ?, created_at = NOW() WHERE email = ?");
            $stmt->bind_param("sss", $code, $expires, $email);
            
            if ($stmt->execute() && sendEmail($email, "New Password Reset Code", getEmailTemplate($code, $email))) {
                $success = "New code sent! Check your email.";
            } else {
                $error = "Failed to resend code.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="./css/forgot.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card reset-card">
                    <div class="reset-header">
                        <div class="step-indicator">
                            <div class="step <?php echo $step === 'email' ? 'active' : ($step !== 'email' ? 'completed' : ''); ?>"></div>
                            <div class="step <?php echo $step === 'verify' ? 'active' : ($step === 'reset' ? 'completed' : ''); ?>"></div>
                            <div class="step <?php echo $step === 'reset' ? 'active' : ''; ?>"></div>
                        </div>
                        
                        <?php if ($step === 'email'): ?>
                            <h2><i class="bi bi-key me-2"></i>Reset Password</h2>
                            <p>Enter your email to receive a verification code</p>
                        <?php elseif ($step === 'verify'): ?>
                            <h2><i class="bi bi-shield-check me-2"></i>Verify Code</h2>
                            <p>Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($_SESSION['temp_email'] ?? ''); ?></strong></p>
                            <p class="text-danger small"><i class="bi bi-clock me-1"></i>Code expires in 3 minutes</p>
                        <?php else: ?>
                            <h2><i class="bi bi-lock-fill me-2"></i>New Password</h2>
                            <p>Create a strong password for your account</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="reset-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                            <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>
                        
                        <?php if ($step === 'email'): ?>
                            <form method="POST">
                                <div class="form-floating">
                                    <input type="email" class="form-control" id="email" name="email" placeholder="name@example.com" required>
                                    <label for="email"><i class="bi bi-envelope me-2"></i>Email Address</label>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" name="send_code" class="btn btn-reset btn-lg">
                                        <i class="bi bi-send me-2"></i>Send Verification Code
                                    </button>
                                </div>
                            </form>
                            
                        <?php elseif ($step === 'verify'): ?>
                            <form method="POST">
                                <div class="form-floating">
                                    <input type="text" class="form-control text-center" id="verification_code" name="verification_code" 
                                           placeholder="000000" maxlength="6" pattern="[0-9]{6}" required>
                                    <label for="verification_code"><i class="bi bi-shield-check me-2"></i>Verification Code</label>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" name="verify_code" class="btn btn-reset btn-lg">
                                        <i class="bi bi-check-circle me-2"></i>Verify Code
                                    </button>
                                </div>
                                <div class="text-center mt-3">
                                    <button type="submit" name="resend_code" class="btn btn-link">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Resend Code
                                    </button>
                                </div>
                            </form>
                            
                        <?php else: ?>
                            <form method="POST">
                                <div class="form-floating position-relative">
                                    <input type="password" class="form-control" id="new_password" name="new_password" 
                                           placeholder="New Password" minlength="8" required>
                                    <label for="new_password"><i class="bi bi-lock me-2"></i>New Password</label>
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password', 'toggleIcon1')">
                                        <i class="bi bi-eye" id="toggleIcon1"></i>
                                    </button>
                                </div>
                                
                                <div class="password-requirements">
                                    <small class="text-muted">
                                        <i class="bi bi-info-circle me-1"></i>8+ characters with uppercase, lowercase, and number
                                    </small>
                                </div>
                                
                                <div class="form-floating position-relative">
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           placeholder="Confirm Password" minlength="8" required>
                                    <label for="confirm_password"><i class="bi bi-lock-fill me-2"></i>Confirm Password</label>
                                    <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', 'toggleIcon2')">
                                        <i class="bi bi-eye" id="toggleIcon2"></i>
                                    </button>
                                </div>
                                
                                <div class="d-grid">
                                    <button type="submit" name="reset_password" class="btn btn-reset btn-lg">
                                        <i class="bi bi-arrow-clockwise me-2"></i>Reset Password
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                        
                        <div class="back-link">
                            <p class="mb-0">Remember your password? 
                                <a href="login.php"><i class="bi bi-arrow-left me-1"></i>Back to Login</a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Countdown timer
        let timeLeft = 180;
        function startCountdown() {
            if (document.getElementById('verification_code')) {
                const timer = setInterval(() => {
                    const mins = Math.floor(timeLeft / 60);
                    const secs = timeLeft % 60;
                    const elem = document.querySelector('.text-danger.small');
                    if (elem) {
                        elem.innerHTML = `<i class="bi bi-clock me-1"></i>Code expires in ${mins}:${secs.toString().padStart(2, '0')}`;
                        if (timeLeft <= 0) {
                            clearInterval(timer);
                            elem.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Code expired!';
                        }
                    }
                    timeLeft--;
                }, 1000);
            }
        }
        
        if (document.getElementById('verification_code')) {
            startCountdown();
            document.getElementById('verification_code').focus();
        }
        
        // Password toggle
        function togglePassword(fieldId, iconId) {
            const field = document.getElementById(fieldId);
            const icon = document.getElementById(iconId);
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'bi bi-eye';
            }
        }
        
        // Only allow numbers in verification code
        const verifyInput = document.getElementById('verification_code');
        if (verifyInput) {
            verifyInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        
        // Password matching validation
        const newPass = document.getElementById('new_password');
        const confirmPass = document.getElementById('confirm_password');
        if (newPass && confirmPass) {
            function validatePasswords() {
                if (confirmPass.value && newPass.value !== confirmPass.value) {
                    confirmPass.setCustomValidity('Passwords do not match');
                } else {
                    confirmPass.setCustomValidity('');
                }
            }
            newPass.addEventListener('input', validatePasswords);
            confirmPass.addEventListener('input', validatePasswords);
        }
    </script>
</body>
</html>