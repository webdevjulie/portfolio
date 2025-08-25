<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

$success_message = '';
$error_message = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Function to validate strong password
    function isStrongPassword($password) {
        return strlen($password) >= 8 && 
               preg_match('/[A-Z]/', $password) && 
               preg_match('/[a-z]/', $password) && 
               preg_match('/[0-9]/', $password) && 
               preg_match('/[^A-Za-z0-9]/', $password);
    }

    // Add new user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
        $new_username = trim($_POST['username']);
        $new_email = trim($_POST['email']);
        $new_password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($new_username) || empty($new_email) || empty($new_password) || empty($confirm_password)) {
            $error_message = "All fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match.";
        } elseif (!isStrongPassword($new_password)) {
            $error_message = "Password must be at least 8 characters with uppercase, lowercase, number, and special character.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM user_management WHERE username = ? OR email = ?");
            $checkStmt->execute([$new_username, $new_email]);
            
            if ($checkStmt->fetch()) {
                $error_message = "Username or email already exists.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("INSERT INTO user_management (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                
                if ($insertStmt->execute([$new_username, $new_email, $hashed_password])) {
                    $success_message = "Admin account created successfully for " . htmlspecialchars($new_username) . "!";
                } else {
                    $error_message = "Failed to create account. Please try again.";
                }
            }
        }
    }

    // Edit user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
        $edit_id = $_POST['edit_id'];
        $edit_username = trim($_POST['edit_username']);
        $edit_email = trim($_POST['edit_email']);
        $edit_password = $_POST['edit_password'];

        if (empty($edit_username) || empty($edit_email)) {
            $error_message = "Username and email are required.";
        } elseif (!filter_var($edit_email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            $checkStmt = $pdo->prepare("SELECT id FROM user_management WHERE (username = ? OR email = ?) AND id != ?");
            $checkStmt->execute([$edit_username, $edit_email, $edit_id]);
            
            if ($checkStmt->fetch()) {
                $error_message = "Username or email already exists.";
            } else {
                if (!empty($edit_password)) {
                    if (!isStrongPassword($edit_password)) {
                        $error_message = "Password must be at least 8 characters with uppercase, lowercase, number, and special character.";
                    } else {
                        $hashed_password = password_hash($edit_password, PASSWORD_DEFAULT);
                        $updateStmt = $pdo->prepare("UPDATE user_management SET username = ?, email = ?, password = ? WHERE id = ?");
                        $result = $updateStmt->execute([$edit_username, $edit_email, $hashed_password, $edit_id]);
                    }
                } else {
                    $updateStmt = $pdo->prepare("UPDATE user_management SET username = ?, email = ? WHERE id = ?");
                    $result = $updateStmt->execute([$edit_username, $edit_email, $edit_id]);
                }
                
                if (isset($result) && $result) {
                    $success_message = "User updated successfully!";
                } elseif (!isset($error_message)) {
                    $error_message = "Failed to update user. Please try again.";
                }
            }
        }
    }

    // Delete user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $delete_id = $_POST['delete_id'];
        
        if ($delete_id == $_SESSION['user_id']) {
            $error_message = "You cannot delete your own account.";
        } else {
            $deleteStmt = $pdo->prepare("DELETE FROM user_management WHERE id = ?");
            if ($deleteStmt->execute([$delete_id])) {
                $success_message = "User deleted successfully!";
            } else {
                $error_message = "Failed to delete user. Please try again.";
            }
        }
    }

    // Get users and current user info
    $stmt = $pdo->query("SELECT id, username, email, created_at FROM user_management ORDER BY created_at DESC");
    $users = $stmt->fetchAll();

    $userStmt = $pdo->prepare("SELECT username FROM user_management WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - InvestPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --light-orange: #ff8c5a;
            --dark-orange: #e55a2b;
            --sidebar-width: 250px;
        }

        * { font-family: 'Poppins', sans-serif; }

        body {
            background-color: #f8f9fa;
        }

        /* Sidebar Styles */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: linear-gradient(135deg, var(--primary-orange) 0%, var(--dark-orange) 100%);
            z-index: 1000;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }

        .sidebar-header {
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .sidebar-brand:hover {
            color: white;
            text-decoration: none;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 0;
        }

        .nav-link {
            color: rgba(255,255,255,0.9);
            padding: 0.75rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            transition: all 0.3s ease;
            border-radius: 0;
            font-weight: 500;
        }

        .nav-link:hover {
            background-color: rgba(255,255,255,0.1);
            color: white;
            transform: translateX(5px);
        }

        .nav-link.active {
            background-color: rgba(255,255,255,0.2);
            color: white;
            border-right: 4px solid white;
        }

        .nav-link i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* Header Styles */
        .header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: 70px;
            background: white;
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid #e9ecef;
        }

        .welcome-text {
            color: #495057;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .welcome-name {
            color: var(--primary-orange);
            font-weight: 700;
        }

        .user-dropdown .dropdown-toggle {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
            border: none;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .user-dropdown .dropdown-toggle:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
        }

        .user-dropdown .dropdown-toggle:focus {
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }

        .user-dropdown .dropdown-menu {
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border-radius: 10px;
            padding: 0.5rem 0;
            margin-top: 0.5rem;
        }

        .user-dropdown .dropdown-item {
            padding: 0.75rem 1.5rem;
            color: #495057;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .user-dropdown .dropdown-item:hover {
            background-color: #f8f9fa;
            color: var(--primary-orange);
            transform: translateX(5px);
        }

        .user-dropdown .dropdown-divider {
            margin: 0.5rem 0;
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: 70px;
            padding: 2rem;
            min-height: calc(100vh - 70px);
        }

        .d-flex {
            display: flex;
        }

        .align-items-center {
            align-items: center;
        }

        .me-3 {
            margin-right: 1rem; /* Equivalent to Bootstrap spacing */
        }

        .mobile-toggle {
            background-color: #ffa052ff;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            transition: background-color 0.3s ease;
        }

        .mobile-toggle:hover {
            background-color: #f39243ff; /* Light hover effect */
            border-radius: 5px;
        }

        /* Custom Card Styles */
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }

        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
            color: white;
            border-radius: 15px 15px 0 0 !important;
            border: none;
            padding: 1.5rem;
        }

        .card-title {
            margin: 0;
            font-weight: 700;
            font-size: 1.25rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
            border: none;
            border-radius: 25px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.3);
            background: linear-gradient(135deg, var(--dark-orange), var(--primary-orange));
        }

        .btn-secondary {
            border-radius: 25px;
            padding: 0.75rem 2rem;
            font-weight: 600;
        }

        .form-control, .form-select {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25);
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .badge.bg-primary {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange)) !important;
        }

        .table {
            border-radius: 10px;
            overflow: hidden;
        }

        .table thead th {
            background-color: #f8f9fa;
            border: none;
            font-weight: 700;
            color: #495057;
            padding: 1rem;
        }

        .table tbody td {
            padding: 1rem;
            vertical-align: middle;
            border-top: 1px solid #f1f3f4;
        }

        .alert {
            border: none;
            border-radius: 10px;
            padding: 1rem 1.5rem;
        }

        .modal-content {
            border: none;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }

        .modal-header {
            border-bottom: 1px solid #f1f3f4;
            border-radius: 15px 15px 0 0;
        }

        .modal-footer {
            border-top: 1px solid #f1f3f4;
            border-radius: 0 0 15px 15px;
        }

        .page-title {
            color: #495057;
            font-weight: 700;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .page-title i {
            color: var(--primary-orange);
            font-size: 2rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.show {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .header {
                left: 0;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="#" class="sidebar-brand"><i class="bi bi-graph-up-arrow"></i>WebCash Investment</a>
        </div>
        <ul class="sidebar-nav list-unstyled">
            <li class="nav-item"><a href="admin_dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
            <li class="nav-item"><a href="admin_packages.php" class="nav-link"><i class="bi bi-box-seam"></i>Manage Packages</a></li>
            <li class="nav-item"><a href="admin_investors.php" class="nav-link"><i class="bi bi-bank"></i> Investors History</a></li>
            <li class="nav-item"><a href="admin_transactions.php" class="nav-link"><i class="bi bi-credit-card"></i>Withdrawals History</a></li>
            <li class="nav-item"><a href="admin_users.php" class="nav-link"><i class="bi-person-lines-fill"></i>Manage Users Accounts</a></li>
            <li class="nav-item"><a href="admin_user_management.php" class="nav-link active"><i class="bi bi-person-gear me-2"></i> User Management</a></li>
        </ul>
    </nav>

    <!-- Header -->
    <header class="header">
        <div class="d-flex align-items-center">
            <button class="btn btn-link mobile-toggle me-3 d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list fs-4"></i>
            </button>
        </div>
        <div class="user-dropdown dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($currentUser['username'] ?? 'Admin') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="admin_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <h1 class="page-title"><i class="bi bi-people-fill"></i> User Management</h1>

            <!-- Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle-fill me-2"></i><?= $success_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error_message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Add New User -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="bi bi-person-plus-fill me-2"></i>Add New Admin</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="bi bi-person me-1"></i>Username</label>
                                <input type="text" class="form-control" name="username" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="bi bi-envelope me-1"></i>Email</label>
                                <input type="email" class="form-control" name="email" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="bi bi-lock me-1"></i>Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                                <div class="form-text">Must contain: 8+ chars, uppercase, lowercase, number, special char</div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><i class="bi bi-lock-fill me-1"></i>Confirm Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="confirm_password" id="confirm_password" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password', this)">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button type="submit" name="add_user" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-2"></i>Add Admin
                        </button>
                    </form>
                </div>
            </div>

            <!-- Users List -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="bi bi-list-ul me-2"></i>All Admins (<?= count($users) ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr data-user-id="<?= $user['id'] ?>" data-username="<?= htmlspecialchars($user['username']) ?>" data-email="<?= htmlspecialchars($user['email']) ?>">
                                        <td><strong class="text-primary">#<?= $user['id'] ?></strong></td>
                                        <td><?= htmlspecialchars($user['username']) ?></td>
                                        <td><?= htmlspecialchars($user['email']) ?></td>
                                        <td><?= date('M d, Y', strtotime($user['created_at'])) ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editUser(<?= $user['id'] ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editUserModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id">
                        <div class="mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="edit_username" id="edit_username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="edit_email" id="edit_email" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" name="edit_password" id="edit_password">
                                <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('edit_password', this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">Leave empty to keep current password</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_user" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteUserModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash text-danger me-2"></i>Delete User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="delete_id" id="delete_id">
                        <p>Are you sure you want to delete user "<strong id="delete_username"></strong>"?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="delete_user" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Strong password validation
        function validatePassword(password) {
            return password.length >= 8 && 
                   /[A-Z]/.test(password) && 
                   /[a-z]/.test(password) && 
                   /[0-9]/.test(password) && 
                   /[^A-Za-z0-9]/.test(password);
        }

        document.getElementById('password').addEventListener('input', function() {
            const isValid = validatePassword(this.value);
            this.style.borderColor = isValid ? 'green' : 'red';
        });

        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const match = password === this.value;
            this.style.borderColor = match ? 'green' : 'red';
        });

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'bi bi-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'bi bi-eye';
            }
        }

        function editUser(userId) {
            const userRow = document.querySelector(`tr[data-user-id="${userId}"]`);
            document.getElementById('edit_id').value = userId;
            document.getElementById('edit_username').value = userRow.dataset.username;
            document.getElementById('edit_email').value = userRow.dataset.email;
            new bootstrap.Modal(document.getElementById('editUserModal')).show();
        }

        function deleteUser(userId, username) {
            document.getElementById('delete_id').value = userId;
            document.getElementById('delete_username').textContent = username;
            new bootstrap.Modal(document.getElementById('deleteUserModal')).show();
        }
    </script>
</body>
</html>