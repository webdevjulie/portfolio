<?php
session_start();

$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Simulate logged-in user
$_SESSION['user_id'] = 1;

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    die('Not logged in');
}

$stmt = $pdo->prepare("SELECT * FROM user_management WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    die("User not found.");
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password'])) {
    $newPassword = trim($_POST['new_password']);
    $confirmPassword = trim($_POST['confirm_password']);

    // Validate password inputs
    if (empty($newPassword)) {
        $error = 'New password cannot be empty!';
    } elseif (strlen($newPassword) < 6) {
        $error = 'Password must be at least 6 characters long!';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match!';
    } else {
        try {
            $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE user_management SET password = ? WHERE id = ?");
            $result = $updateStmt->execute([$hashed, $userId]);
            
            if ($result && $updateStmt->rowCount() > 0) {
                $success = 'Password updated successfully!';
                // Clear the form by redirecting to prevent resubmission
                if ($success) {
                    $_SESSION['password_updated'] = true;
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            } else {
                $error = 'Failed to update password. Please try again.';
            }
        } catch (PDOException $e) {
            $error = 'Database error occurred. Please try again.';
            error_log("Password update error: " . $e->getMessage());
        }
    }
}

// Check if password was just updated (from redirect)
if (isset($_SESSION['password_updated'])) {
    $success = 'Password updated successfully!';
    unset($_SESSION['password_updated']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #fff8f0;
        }

        * { font-family: 'Poppins', sans-serif; }
        
        .card {
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(255, 102, 0, 0.1);
            border: none;
        }
        .card-header {
            background-color: #ff7f0e;
            color: white;
            font-weight: 600;
            border-top-left-radius: 16px;
            border-top-right-radius: 16px;
            padding: 20px;
            font-size: 1.2rem;
        }
        .nav-tabs {
            border-bottom: 2px solid #ff7f0e;
        }
        .nav-tabs .nav-link.active {
            background-color: #ff7f0e;
            color: white !important;
            border: none;
            font-weight: 500;
        }
        .nav-tabs .nav-link {
            color: #ff7f0e;
            font-weight: 500;
            border: none;
            margin-right: 5px;
        }
        .nav-tabs .nav-link:hover {
            background-color: #ffa64d;
            color: white;
            border: none;
        }
        .btn-warning {
            background-color: #ff7f0e;
            border: none;
            font-weight: 500;
            padding: 10px 30px;
        }
        .btn-warning:hover {
            background-color: #ff8800;
        }
        .form-label {
            color: #ff7f0e;
            font-weight: 500;
        }
        .form-control:focus {
            border-color: #ff7f0e;
            box-shadow: 0 0 0 0.2rem rgba(255, 127, 14, 0.25);
        }
        .input-group .btn-outline-secondary {
            border-color: #ced4da;
            color: #6c757d;
        }
        .input-group .btn-outline-secondary:hover {
            background-color: #f8f9fa;
            border-color: #ff7f0e;
            color: #ff7f0e;
        }
        .input-group .btn-outline-secondary:focus {
            box-shadow: 0 0 0 0.2rem rgba(255, 127, 14, 0.25);
            border-color: #ff7f0e;
        }
        .table th {
            background-color: #ffa64d;
            color: white;
            font-weight: 500;
        }
        .alert {
            border: none;
            border-radius: 8px;
        }
        .password-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }
    </style>
</head>
<body>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header text-center">
                    <i class="fas fa-user-circle me-2"></i>My Profile
                </div>
                <div class="card-body p-4">
                    <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                        <li class="nav-item">
                            <button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab">
                                <i class="fas fa-info-circle me-1"></i>Information
                            </button>
                        </li>
                        <li class="nav-item">
                            <button class="nav-link" id="security-tab" data-bs-toggle="tab" data-bs-target="#security" type="button" role="tab">
                                <i class="fas fa-shield-alt me-1"></i>Security
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content" id="profileTabContent">
                        <!-- Info Tab -->
                        <div class="tab-pane fade show active" id="info" role="tabpanel">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <tr>
                                        <th width="30%">Username</th>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Email</th>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Role</th>
                                        <td>
                                            <span class="badge bg-<?= $user['role'] === 'admin' ? 'danger' : 'primary' ?>">
                                                <?= htmlspecialchars(ucfirst($user['role'])) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Member Since</th>
                                        <td><?= date('F j, Y', strtotime($user['created_at'])) ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <!-- Security Tab -->
                        <div class="tab-pane fade" id="security" role="tabpanel">
                            <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($success): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">
                                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                </div>
                            <?php endif; ?>

                            <h5 class="mb-3" style="color: #ff7f0e;">Change Password</h5>
                            <form method="POST" id="passwordForm">
                                <div class="mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="new_password" id="new_password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleNewPassword">
                                            <i class="fas fa-eye" id="newPasswordIcon"></i>
                                        </button>
                                    </div>
                                    <div class="password-requirements">
                                        Password must be at least 6 characters long
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <div class="input-group">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword">
                                            <i class="fas fa-eye" id="confirmPasswordIcon"></i>
                                        </button>
                                    </div>
                                    <div class="invalid-feedback" id="password-mismatch" style="display: none;">
                                        Passwords do not match
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" class="btn btn-warning" id="updateBtn">
                                        <i class="fas fa-key me-2"></i>Update Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div><!-- /tab-content -->
                </div><!-- /card-body -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://kit.fontawesome.com/a076d05399.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const passwordForm = document.getElementById('passwordForm');
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const mismatchFeedback = document.getElementById('password-mismatch');
    const updateBtn = document.getElementById('updateBtn');

    // Real-time password confirmation check
    function checkPasswordMatch() {
        if (confirmPassword.value !== '') {
            if (newPassword.value !== confirmPassword.value) {
                confirmPassword.classList.add('is-invalid');
                mismatchFeedback.style.display = 'block';
                updateBtn.disabled = true;
            } else {
                confirmPassword.classList.remove('is-invalid');
                mismatchFeedback.style.display = 'none';
                updateBtn.disabled = false;
            }
        }
    }

    // Toggle password visibility functions
    function togglePasswordVisibility(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    // Event listeners for toggle buttons
    document.getElementById('toggleNewPassword').addEventListener('click', function() {
        togglePasswordVisibility('new_password', 'newPasswordIcon');
    });

    document.getElementById('toggleConfirmPassword').addEventListener('click', function() {
        togglePasswordVisibility('confirm_password', 'confirmPasswordIcon');
    });

    confirmPassword.addEventListener('input', checkPasswordMatch);
    newPassword.addEventListener('input', function() {
        if (confirmPassword.value !== '') {
            checkPasswordMatch();
        }
    });

    // Form submission with loading state
    passwordForm.addEventListener('submit', function(e) {
        updateBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Updating...';
        updateBtn.disabled = true;
    });

    // Auto-dismiss alerts after 5 seconds
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});
</script>
</body>
</html>