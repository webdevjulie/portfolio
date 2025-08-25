<?php
include '../includes/db.php';
include '../includes/session.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$userId = $_SESSION['user_id'];
$success_message = '';
$error_message = '';

// Get current user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Handle form submission
if ($_POST) {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validate input
    if (empty($fullname)) {
        $errors[] = "Full name is required";
    }
    
    if (!empty($phone) && !preg_match('/^[\d\s\+\-\(\)]{7,15}$/', $phone)) {
        $errors[] = "Please enter a valid phone number (7-15 characters)";
    }
    
    // If password change is requested
    if (!empty($current_password) || !empty($new_password) || !empty($confirm_password)) {
        if (empty($current_password)) {
            $errors[] = "Current password is required to change password";
        } elseif (!password_verify($current_password, $user['password'])) {
            $errors[] = "Current password is incorrect";
        }
        
        if (empty($new_password)) {
            $errors[] = "New password is required";
        } elseif (strlen($new_password) < 6) {
            $errors[] = "New password must be at least 6 characters long";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "New passwords do not match";
        }
    }
    
    if (empty($errors)) {
        try {
            // Update basic profile information
            $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, phone = ?, last_login = NOW() WHERE id = ?");
            $updateStmt->bind_param("ssi", $fullname, $phone, $userId);
            
            if ($updateStmt->execute()) {
                // Update password if provided
                if (!empty($new_password)) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $passwordStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $passwordStmt->bind_param("si", $hashed_password, $userId);
                    $passwordStmt->execute();
                }
                
                $success_message = "Profile updated successfully!";
                
                // Refresh user data
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();
                
            } else {
                $error_message = "Failed to update profile. Please try again.";
            }
        } catch (Exception $e) {
            $error_message = "An error occurred while updating your profile.";
        }
    } else {
        $error_message = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Investment Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --secondary-orange: #ff8c42;
            --light-orange: #ffebe5;
            --pure-white: #ffffff;
            --text-dark: #333333;
            --text-muted: #666666;
            --border-light: #e0e0e0;
            --shadow-light: rgba(255, 107, 53, 0.1);
            --success-orange: #ff6b35;
            --danger-orange: #e55b2b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--pure-white);
            color: var(--text-dark);
            line-height: 1.6;
            margin-left: 250px;
        }

        .content {
            padding: 2rem;
            max-width: 800px;
            margin: 0 auto;
            transition: all 0.3s ease;
        }

        .page-header {
            background: linear-gradient(135deg, var(--primary-orange), var(--secondary-orange));
            border-radius: 15px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 10px 30px var(--shadow-light);
            color: var(--pure-white);
        }

        .page-header h1 {
            font-weight: 700;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--pure-white);
        }

        .page-header .breadcrumb {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .page-header .breadcrumb a {
            color: rgba(255, 255, 255, 0.9) !important;
            text-decoration: none;
        }

        .form-card {
            background: var(--pure-white);
            border-radius: 15px;
            padding: 2rem;
            box-shadow: 0 5px 20px var(--shadow-light);
            border: 2px solid var(--light-orange);
            margin-bottom: 2rem;
        }

        .form-card h5 {
            color: var(--primary-orange);
            margin-bottom: 1.5rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            color: var(--text-dark);
            font-weight: 500;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-label .material-icons {
            color: var(--primary-orange);
        }

        .form-control {
            background: var(--pure-white);
            border: 2px solid var(--border-light);
            border-radius: 10px;
            color: var(--text-dark);
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            background: var(--light-orange);
            border-color: var(--primary-orange);
            color: var(--text-dark);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        .btn-modern {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .btn-primary-modern {
            background: linear-gradient(135deg, var(--primary-orange), var(--secondary-orange));
            color: var(--pure-white);
        }

        .btn-primary-modern:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px var(--shadow-light);
            color: var(--pure-white);
            filter: brightness(1.1);
        }

        .btn-secondary-modern {
            background: var(--pure-white);
            color: var(--primary-orange);
            border: 2px solid var(--primary-orange);
        }

        .btn-secondary-modern:hover {
            background: var(--light-orange);
            color: var(--primary-orange);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px var(--shadow-light);
        }

        .alert {
            border: none;
            border-radius: 10px;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: var(--light-orange);
            color: var(--primary-orange);
            border: 2px solid var(--primary-orange);
        }

        .alert-success .material-icons {
            color: var(--primary-orange);
        }

        .alert-danger {
            background: #ffebe5;
            color: var(--danger-orange);
            border: 2px solid var(--danger-orange);
        }

        .alert-danger .material-icons {
            color: var(--danger-orange);
        }

        .password-section {
            border-top: 2px solid var(--light-orange);
            margin-top: 2rem;
            padding-top: 2rem;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid var(--light-orange);
        }

        .current-info {
            background: var(--light-orange);
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border: 2px solid var(--primary-orange);
        }

        .current-info strong {
            color: var(--primary-orange);
        }

        .input-group-text {
            background: var(--pure-white);
            border: 2px solid var(--border-light);
            color: var(--primary-orange);
        }

        .input-group .form-control {
            border-left: none;
        }

        .input-group-text:hover {
            border-color: var(--primary-orange);
        }

        .password-toggle {
            cursor: pointer;
            user-select: none;
        }

        .password-toggle:hover {
            color: var(--secondary-orange);
            background: var(--light-orange);
        }

        .text-muted {
            color: var(--text-muted) !important;
        }

        @media (max-width: 768px) {
            body {
                margin-left: 0;
            }
            
            .content {
                padding: 1rem;
            }
            
            .form-card {
                padding: 1.5rem;
            }
            
            .form-actions {
                flex-direction: column;
            }
        }

        .page-transition {
            transition: all 0.3s ease;
        }

        /* Override Bootstrap styles */
        .btn-check:focus + .btn, .btn:focus {
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--pure-white);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-orange);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--secondary-orange);
        }
    </style>
</head>
<body>
    <!-- Include sidebar -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="content page-transition">
        <!-- Page Header -->
        <div class="page-header">
            <h1>
                <span class="material-icons">edit</span>
                Edit Profile
            </h1>
            <nav class="breadcrumb">
                <a href="profile.php">Profile</a>
                <span class="mx-2">/</span>
                <span>Edit Profile</span>
            </nav>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <span class="material-icons">check_circle</span>
                <?= $success_message ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <span class="material-icons">error</span>
                <?= $error_message ?>
            </div>
        <?php endif; ?>

        <!-- Edit Profile Form -->
        <form method="POST" id="editProfileForm">
            <!-- Basic Information -->
            <div class="form-card">
                <h5>
                    <span class="material-icons">person</span>
                    Basic Information
                </h5>

                <div class="current-info">
                    <strong>Current Email:</strong> <?= htmlspecialchars($user['email']) ?> 
                    <span class="text-muted">(Email cannot be changed)</span>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size: 1.2rem;">badge</span>
                        Full Name *
                    </label>
                    <input type="text" 
                           class="form-control" 
                           name="fullname" 
                           value="<?= htmlspecialchars($user['fullname'] ?? '') ?>" 
                           placeholder="Enter your full name"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size: 1.2rem;">phone</span>
                        Phone Number
                    </label>
                    <input type="tel" 
                           class="form-control" 
                           name="phone" 
                           value="<?= htmlspecialchars($user['phone'] ?? '') ?>" 
                           placeholder="Enter your phone number">
                </div>
            </div>

            <!-- Password Change Section -->
            <div class="form-card">
                <h5>
                    <span class="material-icons">lock</span>
                    Change Password
                </h5>
                <p class="text-muted mb-3">Leave blank if you don't want to change your password</p>

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size: 1.2rem;">lock_open</span>
                        Current Password
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               name="current_password" 
                               id="currentPassword"
                               placeholder="Enter current password">
                        <span class="input-group-text password-toggle" onclick="togglePassword('currentPassword', this)">
                            <span class="material-icons">visibility</span>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size: 1.2rem;">lock</span>
                        New Password
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               name="new_password" 
                               id="newPassword"
                               placeholder="Enter new password (min. 6 characters)">
                        <span class="input-group-text password-toggle" onclick="togglePassword('newPassword', this)">
                            <span class="material-icons">visibility</span>
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <span class="material-icons" style="font-size: 1.2rem;">lock</span>
                        Confirm New Password
                    </label>
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               name="confirm_password" 
                               id="confirmPassword"
                               placeholder="Confirm new password">
                        <span class="input-group-text password-toggle" onclick="togglePassword('confirmPassword', this)">
                            <span class="material-icons">visibility</span>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <a href="profile.php" class="btn-modern btn-secondary-modern">
                    <span class="material-icons" style="font-size: 1.2rem;">cancel</span>
                    Cancel
                </a>
                <button type="submit" class="btn-modern btn-primary-modern">
                    <span class="material-icons" style="font-size: 1.2rem;">save</span>
                    Save Changes
                </button>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add smooth page transition
            const content = document.querySelector('.page-transition');
            if (content) {
                content.style.opacity = '0';
                content.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    content.style.opacity = '1';
                    content.style.transform = 'translateY(0)';
                }, 100);
            }

            // Form validation
            const form = document.getElementById('editProfileForm');
            const newPassword = document.getElementById('newPassword');
            const confirmPassword = document.getElementById('confirmPassword');
            const currentPassword = document.getElementById('currentPassword');

            // Real-time password validation
            function validatePasswords() {
                if (newPassword.value && confirmPassword.value) {
                    if (newPassword.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                }
            }

            newPassword.addEventListener('input', validatePasswords);
            confirmPassword.addEventListener('input', validatePasswords);

            // Check if password change is being attempted
            [currentPassword, newPassword, confirmPassword].forEach(input => {
                input.addEventListener('input', function() {
                    const hasPasswordInput = currentPassword.value || newPassword.value || confirmPassword.value;
                    
                    if (hasPasswordInput) {
                        currentPassword.required = true;
                        newPassword.required = true;
                        confirmPassword.required = true;
                    } else {
                        currentPassword.required = false;
                        newPassword.required = false;
                        confirmPassword.required = false;
                    }
                });
            });

            // Form submission validation
            form.addEventListener('submit', function(e) {
                const hasPasswordInput = currentPassword.value || newPassword.value || confirmPassword.value;
                
                if (hasPasswordInput) {
                    if (!currentPassword.value) {
                        e.preventDefault();
                        alert('Current password is required to change password');
                        currentPassword.focus();
                        return;
                    }
                    
                    if (newPassword.value.length < 6) {
                        e.preventDefault();
                        alert('New password must be at least 6 characters long');
                        newPassword.focus();
                        return;
                    }
                    
                    if (newPassword.value !== confirmPassword.value) {
                        e.preventDefault();
                        alert('New passwords do not match');
                        confirmPassword.focus();
                        return;
                    }
                }

                // Add loading state to submit button
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="material-icons" style="font-size: 1.2rem;">hourglass_empty</span> Saving...';
                submitBtn.disabled = true;

                // Re-enable button after 3 seconds in case of errors
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 3000);
            });

            // Add focus effects to form controls
            const formControls = document.querySelectorAll('.form-control');
            formControls.forEach(control => {
                control.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                control.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });

            // Auto-dismiss alerts after 5 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-20px)';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                }, 5000);
            });
        });

        function togglePassword(inputId, toggleBtn) {
            const input = document.getElementById(inputId);
            const icon = toggleBtn.querySelector('.material-icons');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.textContent = 'visibility_off';
            } else {
                input.type = 'password';
                icon.textContent = 'visibility';
            }
        }
    </script>

    <body oncontextmenu="return false;" onkeydown="return disableInspect(event)">
    <script>
        function disableInspect(e) {
            if (e.key === "F12" || 
                (e.ctrlKey && e.shiftKey && e.key === "I") || 
                (e.ctrlKey && e.shiftKey && e.key === "J") || 
                (e.ctrlKey && e.key === "U")) {
            return false;
            }
        }
    </script>
</body>
</html>