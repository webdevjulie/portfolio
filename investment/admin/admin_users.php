<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Connect to the database
$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle block/unblock actions
    if ($_POST['action'] ?? '' === 'toggle_block' && isset($_POST['user_id'])) {
        $userId = $_POST['user_id'];
        
        // Get current status
        $stmt = $pdo->prepare("SELECT is_blocked FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user) {
            $newStatus = $user['is_blocked'] ? 0 : 1;
            $updateStmt = $pdo->prepare("UPDATE users SET is_blocked = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $userId]);
            
            // Set success message
            $_SESSION['success_message'] = $newStatus ? 'User has been blocked successfully.' : 'User has been unblocked successfully.';
        }
        
        // Redirect to prevent form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    // Query users with block status
    $stmt = $pdo->query("SELECT id, fullname, email, last_login, is_blocked FROM users ORDER BY last_login DESC");
    $users = $stmt->fetchAll();

    // Get current user info for header
    $userStmt = $pdo->prepare("SELECT username, email FROM user_management WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Connection or query failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin_users.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <style>


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

        .status-badge {
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .status-active {
            background-color: #d1f2eb;
            color: #0f5132;
        }
        
        .status-blocked {
            background-color: #f8d7da;  
            color: #721c24;
        }
        
        .btn-action {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 0.375rem;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        
        .btn-block {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-block:hover {
            background-color: #bb2d3b;
            color: white;
        }
        
        .btn-unblock {
            background-color: #198754;
            color: white;
        }
        
        .btn-unblock:hover {
            background-color: #157347;
            color: white;
        }
        
        .table th {
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        .alert-dismissible {
            border-radius: 0.5rem;
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
            <li class="nav-item"><a href="admin_dashboard.php" class="nav-link <?= $currentPage === 'admin_dashboard.php' ? 'active' : '' ?>"><i class="bi bi-speedometer2"></i>Dashboard</a></li>
            <li class="nav-item"><a href="admin_packages.php" class="nav-link <?= $currentPage === 'admin_packages.php' ? 'active' : '' ?>"><i class="bi bi-box-seam"></i>Manage Packages</a></li>
            <li class="nav-item"><a href="admin_investors.php" class="nav-link <?= $currentPage === 'admin_investors.php' ? 'active' : '' ?>"><i class="bi bi-bank"></i> Investors History</a></li>
            <li class="nav-item"><a href="admin_transactions.php" class="nav-link <?= $currentPage === 'admin_transactions.php' ? 'active' : '' ?>"><i class="bi bi-credit-card"></i>Withdrawals History</a></li>
            <li class="nav-item"><a href="admin_users.php" class="nav-link <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>"><i class="bi-person-lines-fill"></i>Manage Users Accounts</a></li>
            <li class="nav-item"><a href="admin_user_management.php" class="nav-link <?= $currentPage === 'admin_user_management.php' ? 'active' : '' ?>"><i class="bi bi-person-gear me-2"></i> User Management</a></li>
        </ul>
    </nav>

    <!-- Header -->
    <header class="header">
        <div class="d-flex align-items-center">
            <button class="mobile-toggle me-3" onclick="toggleSidebar()"><i class="bi bi-list"></i></button>
        </div>
        
        <div class="user-dropdown dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i><?= htmlspecialchars($currentUser['username'] ?? 'Admin') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="admin_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <div class="container-fluid">
            <!-- Success Alert -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i>
                    <?= $_SESSION['success_message'] ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header fade-in">
                <h1 class="page-title">
                    <i class="bi bi-people me-3"></i>
                    Manage Users
                </h1>
                <p class="page-subtitle">View and manage all registered users in the system</p>
            </div>

            <!-- Stats Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stats-card fade-in">
                        <div class="stats-number"><?= count($users) ?></div>
                        <div class="stats-label">Total Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.1s;">
                        <div class="stats-number"><?= count(array_filter($users, function($user) { return $user['last_login'] && !$user['is_blocked']; })) ?></div>
                        <div class="stats-label">Active Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.2s;">
                        <div class="stats-number"><?= count(array_filter($users, function($user) { return $user['is_blocked']; })) ?></div>
                        <div class="stats-label">Blocked Users</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stats-card fade-in" style="animation-delay: 0.3s;">
                        <div class="stats-number"><?= count(array_filter($users, function($user) { return !$user['last_login']; })) ?></div>
                        <div class="stats-label">Never Logged In</div>
                    </div>
                </div>
            </div>

            <!-- Search Section -->
            <div class="search-section fade-in">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0">
                                <i class="bi bi-search text-muted"></i>
                            </span>
                            <input type="text" class="form-control search-input border-start-0" placeholder="Search users by name, email..." id="searchInput">
                        </div>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-outline-secondary">
                            <i class="bi bi-funnel me-2"></i>Filter
                        </button>
                    </div>
                </div>
            </div>

            <!-- Users Table -->
            <div class="table-container fade-in">
                <div class="table-responsive">
                    <table class="table align-middle" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($users)): ?>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <span class="user-id"><?= htmlspecialchars($user['id']) ?></span>
                                        </td>
                                        <td>
                                            <span class="user-name"><?= htmlspecialchars($user['fullname']) ?></span>
                                        </td>
                                        <td>
                                            <span class="user-email"><?= htmlspecialchars($user['email']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($user['is_blocked']): ?>
                                                <span class="status-badge status-blocked">
                                                    <i class="bi bi-x-circle me-1"></i>Blocked
                                                </span>
                                            <?php else: ?>
                                                <span class="status-badge status-active">
                                                    <i class="bi bi-check-circle me-1"></i>Active
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="last-login <?= !$user['last_login'] ? 'never' : '' ?>">
                                                <?php if ($user['last_login']): ?>
                                                    <i class="bi bi-clock me-1"></i>
                                                    <?= date('M j, Y g:i A', strtotime($user['last_login'])) ?>
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle me-1"></i>
                                                    Never
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="toggle_block">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <?php if ($user['is_blocked']): ?>
                                                    <button type="submit" class="btn btn-unblock btn-sm" 
                                                            onclick="return confirm('Are you sure you want to unblock this user?')">
                                                        <i class="bi bi-unlock me-1"></i>Unblock
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-block btn-sm"
                                                            onclick="return confirm('Are you sure you want to block this user?')">
                                                        <i class="bi bi-lock me-1"></i>Block
                                                    </button>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <i class="bi bi-inbox display-1 text-muted d-block mb-3"></i>
                                        <h5 class="text-muted">No users found</h5>
                                        <p class="text-muted">There are no users in the system yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow border-0 rounded-4">
            <div class="modal-header bg-danger text-white rounded-top-4">
                <h5 class="modal-title" id="logoutModalLabel">
                <i class="bi bi-exclamation-circle-fill me-2"></i>Confirm Logout
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fs-5">Are you sure you want to log out?</p>
                <img src="https://cdn-icons-png.flaticon.com/512/1828/1828479.png" width="60" alt="logout icon" />
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <a href="../auth/logout.php" class="btn btn-danger">Yes, Logout</a>
            </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function logout() {
            // Optional: add a delay or animation
            setTimeout(() => {
            window.location.href = '../auth/logout.php'; // Change path if needed
            }, 300);
        }
    </script>

    <script>
        // Toggle sidebar for mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                    sidebar.classList.remove('show');
                }
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            if (window.innerWidth > 768) {
                sidebar.classList.remove('show');
            }
        });

        // Enhanced search functionality
        document.getElementById('searchInput').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const tableRows = document.querySelectorAll('#usersTable tbody tr');
            
            tableRows.forEach(row => {
                // Skip the "no users found" row
                if (row.cells.length === 1) return;
                
                const fullName = row.cells[1].textContent.toLowerCase();
                const email = row.cells[2].textContent.toLowerCase();
                const status = row.cells[3].textContent.toLowerCase();
                
                if (fullName.includes(searchValue) || email.includes(searchValue) || status.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Add smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>