<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// DB connection settings
$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get current user info
    $stmt = $pdo->prepare("SELECT fullname as username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['username' => 'Admin'];

    // Handle search and filter parameters
    $searchTerm = $_GET['search'] ?? '';
    $typeFilter = $_GET['type'] ?? '';
    $dateFilter = $_GET['date'] ?? '';

    // Build query with filters
    $whereConditions = [];
    $params = [];

    if (!empty($searchTerm)) {
        $whereConditions[] = "(receiver.fullname LIKE ? OR sender.fullname LIKE ? OR wh.amount LIKE ? OR wh.reference_id LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
    }

    if (!empty($typeFilter)) {
        $whereConditions[] = "wh.assignment_type = ?";
        $params[] = $typeFilter;
    }

    if (!empty($dateFilter)) {
        switch($dateFilter) {
            case 'today':
                $whereConditions[] = "DATE(wh.confirmed_at) = CURDATE()";
                break;
            case 'week':
                $whereConditions[] = "wh.confirmed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
                break;
            case 'month':
                $whereConditions[] = "MONTH(wh.confirmed_at) = MONTH(NOW()) AND YEAR(wh.confirmed_at) = YEAR(NOW())";
                break;
            case 'year':
                $whereConditions[] = "YEAR(wh.confirmed_at) = YEAR(NOW())";
                break;
        }
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Main query
    $withdrawalQuery = "
        SELECT 
            wh.*,
            receiver.fullname as receiver_name,
            receiver.email as receiver_email,
            receiver.phone as receiver_phone,
            receiver.status as receiver_status,
            sender.fullname as sender_username,
            sender.email as sender_email
        FROM withdrawal_history wh
        LEFT JOIN users receiver ON wh.receiver_id = receiver.id
        LEFT JOIN users sender ON wh.sender_id = sender.id
        $whereClause
        ORDER BY wh.confirmed_at DESC
    ";
    
    $withdrawalStmt = $pdo->prepare($withdrawalQuery);
    $withdrawalStmt->execute($params);
    $withdrawalHistory = $withdrawalStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count for search results
    $totalQuery = "
        SELECT COUNT(*) as total
        FROM withdrawal_history wh
        LEFT JOIN users receiver ON wh.receiver_id = receiver.id
        LEFT JOIN users sender ON wh.sender_id = sender.id
    ";
    $totalStmt = $pdo->prepare($totalQuery);
    $totalStmt->execute();
    $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC)['total'];

} catch (PDOException $e) {
    $currentUser = ['username' => 'Admin'];
    $withdrawalHistory = [];
    $totalCount = 0;
}

// Helper functions
function formatCurrency($amount) {
    return 'Rs' . number_format($amount, 2);
}

function formatDate($dateString) {
    return $dateString ? date('M d, Y', strtotime($dateString)) : 'N/A';
}

function formatTime($dateString) {
    return $dateString ? date('h:i A', strtotime($dateString)) : 'N/A';
}

function formatFullDate($dateString) {
    return $dateString ? date('F j, Y g:i A', strtotime($dateString)) : 'N/A';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - InvestPro</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin_transactions.css">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --light-orange: #ff8c42;
            --dark-orange: #e55a2b;
            --accent-orange: #ffab91;
            --bg-light: #fafafa;
            --text-dark: #2c3e50;
            --shadow: 0 4px 20px rgba(255, 107, 53, 0.1);
            --shadow-hover: 0 8px 30px rgba(255, 107, 53, 0.2);
        }

        * { font-family: 'Poppins', sans-serif; }

        body {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            min-height: 100vh;
        }

        .main-content { background: transparent; padding: 2rem; }

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


        .page-header {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            border-left: 5px solid var(--primary-orange);
        }

        .page-title { color: var(--primary-orange); font-weight: 700; margin-bottom: 0.5rem; }
        .page-subtitle { color: #6c757d; margin-bottom: 0; }

        .search-filter-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .search-input, .filter-select {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 0.8rem 1rem;
            background: #f8f9fa;
            transition: all 0.3s ease;
        }

        .search-input:focus, .filter-select:focus {
            border-color: var(--primary-orange);
            box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
            background: white;
        }

        .transaction-card {
            background: white;
            border: none;
            border-radius: 20px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-orange) 0%, var(--light-orange) 100%);
            border: none;
            padding: 1.5rem 2rem;
        }

        .table th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            color: var(--text-dark);
            padding: 1rem;
        }

        .table td { border: none; padding: 1rem; vertical-align: middle; }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: rgba(255, 107, 53, 0.05);
            transform: scale(1.01);
        }

        .badge-investment {
            background: linear-gradient(135deg, #28a745, #20c997);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
        }

        .badge-referral {
            background: linear-gradient(135deg, #17a2b8, #6f42c1);
            padding: 0.5rem 1rem;
            border-radius: 50px;
            font-weight: 500;
        }

        .transaction-amount { font-weight: 700; font-size: 1.1rem; color: #28a745; }

        .action-btn {
            background: var(--primary-orange);
            border: none;
            border-radius: 50px;
            padding: 0.5rem 1rem;
            color: white;
            transition: all 0.3s ease;
        }

        .action-btn:hover {
            background: var(--dark-orange);
            transform: scale(1.1);
            color: white;
        }

        .empty-state { text-align: center; padding: 4rem 2rem; color: #6c757d; }
        .empty-state i { font-size: 4rem; margin-bottom: 1rem; color: var(--accent-orange); }

        .modal-content { border-radius: 20px; border: none; box-shadow: var(--shadow-hover); }
        .modal-header { background: linear-gradient(135deg, var(--primary-orange) 0%, var(--light-orange) 100%); border-radius: 20px 20px 0 0; border: none; }

        .search-results { color: var(--primary-orange); font-weight: 500; margin-bottom: 1rem; }

        @media (max-width: 768px) {
            .main-content { padding: 1rem; }
            .page-header, .search-filter-section { padding: 1.5rem; }
        }

        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
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
            <button class="mobile-toggle me-3" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="user-dropdown dropdown">
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-person-circle"></i> <?= htmlspecialchars($currentUser['username']) ?>
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
            <!-- Page Header -->
            <div class="page-header fade-in">
                <h1 class="page-title">
                    <i class="bi bi-credit-card me-3"></i>
                    Transaction Management
                </h1>
                <p class="page-subtitle">Monitor and manage all financial transactions with advanced search and filtering</p>
            </div>

            <!-- Search and Filter Section -->
            <div class="search-filter-section fade-in">
                <form method="GET" action="">
                    <div class="row align-items-end">
                        <div class="col-lg-6 col-md-6 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-search me-2"></i>Search Transactions
                            </label>
                            <input type="text" 
                                   class="form-control search-input" 
                                   name="search" 
                                   value="<?= htmlspecialchars($searchTerm) ?>"
                                   placeholder="Search by name, amount, or reference..."
                                   autocomplete="off">
                        </div>
                        <div class="col-lg-2 col-md-3 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-funnel me-2"></i>Type Filter
                            </label>
                            <select class="form-select filter-select" name="type">
                                <option value="">All Types</option>
                                <option value="investment" <?= $typeFilter === 'investment' ? 'selected' : '' ?>>Investment</option>
                                <option value="referral_withdrawal" <?= $typeFilter === 'referral_withdrawal' ? 'selected' : '' ?>>Referral</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-3 mb-3">
                            <label class="form-label fw-semibold">
                                <i class="bi bi-calendar me-2"></i>Date Filter
                            </label>
                            <select class="form-select filter-select" name="date">
                                <option value="">All Dates</option>
                                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>This Week</option>
                                <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>This Month</option>
                                <option value="year" <?= $dateFilter === 'year' ? 'selected' : '' ?>>This Year</option>
                            </select>
                        </div>
                        <div class="col-lg-2 col-md-12 mb-3">
                            <button type="submit" class="btn btn-primary w-100 me-2">
                                <i class="bi bi-search me-2"></i>Filter
                            </button>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="search-results">
                            <i class="bi bi-funnel me-1"></i>
                            Showing <?= count($withdrawalHistory) ?> of <?= $totalCount ?> transactions
                        </div>
                        <a href="?" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-clockwise me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>

            <!-- Transactions Table -->
            <div class="card transaction-card fade-in">
                <div class="card-header">
                    <h5 class="mb-0 text-white">
                        <i class="bi bi-list-check me-2"></i>
                        Withdrawal History
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($withdrawalHistory)): ?>
                        <div class="empty-state">
                            <i class="bi bi-<?= !empty($searchTerm) || !empty($typeFilter) || !empty($dateFilter) ? 'search' : 'inbox' ?>"></i>
                            <h5><?= !empty($searchTerm) || !empty($typeFilter) || !empty($dateFilter) ? 'No transactions found' : 'No transactions available' ?></h5>
                            <p><?= !empty($searchTerm) || !empty($typeFilter) || !empty($dateFilter) ? 'Try adjusting your search criteria or filters' : 'There are no withdrawal transactions to display.' ?></p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th><i class="bi bi-tag me-1"></i>Type</th>
                                        <th><i class="bi bi-person-check me-1"></i>Receiver</th>
                                        <th><i class="bi bi-person-plus me-1"></i>Sender</th>
                                        <th><i class="bi bi-cash-stack me-1"></i>Amount</th>
                                        <th><i class="bi bi-calendar me-1"></i>Date</th>
                                        <th><i class="bi bi-gear me-1"></i>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($withdrawalHistory as $transaction): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?= $transaction['assignment_type'] === 'investment' ? 'badge-investment' : 'badge-referral' ?>">
                                                    <i class="bi bi-<?= $transaction['assignment_type'] === 'investment' ? 'briefcase' : 'people' ?> me-1"></i>
                                                    <?= ucfirst(str_replace('_', ' ', $transaction['assignment_type'])) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong class="text-primary"><?= htmlspecialchars($transaction['receiver_name'] ?? 'Unknown User') ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bi bi-envelope me-1"></i>
                                                        <?= htmlspecialchars($transaction['receiver_email'] ?? 'N/A') ?>
                                                    </small>
                                                    <?php if (!empty($transaction['receiver_phone'])): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bi bi-telephone me-1"></i>
                                                        <?= htmlspecialchars($transaction['receiver_phone']) ?>
                                                    </small>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if ($transaction['sender_id']): ?>
                                                    <div>
                                                        <strong><?= htmlspecialchars($transaction['sender_username'] ?? $transaction['sender_name']) ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            <i class="bi bi-person-badge me-1"></i>
                                                            ID: <?= $transaction['sender_id'] ?>
                                                        </small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="bi bi-gear me-1"></i>System
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="transaction-amount">
                                                    <?= formatCurrency($transaction['amount']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?= formatDate($transaction['confirmed_at']) ?></strong>
                                                    <br>
                                                    <small class="text-muted">
                                                        <i class="bi bi-clock me-1"></i>
                                                        <?= formatTime($transaction['confirmed_at']) ?>
                                                    </small>
                                                </div>
                                            </td>
                                            <td>
                                                <button class="btn action-btn btn-sm" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#detailsModal"
                                                        onclick="showDetails(<?= htmlspecialchars(json_encode($transaction)) ?>)">
                                                    <i class="bi bi-eye"></i> View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Transaction Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white">
                    <h5 class="modal-title" id="detailsModalLabel">
                        <i class="bi bi-info-circle me-2"></i>Transaction Details
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalContent">
                    <!-- Content will be populated by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-2"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content shadow border-0">
                <div class="modal-header bg-danger text-white">
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
        // Toggle sidebar
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
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

        // Show transaction details in modal
        function showDetails(transaction) {
            const modalContent = document.getElementById('modalContent');
            
            const formatDate = (dateString) => {
                if (!dateString) return 'N/A';
                return new Date(dateString).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            };

            const formatCurrency = (amount) => {
                return 'Rs' + parseFloat(amount).toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
            };

            modalContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Basic Information</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td><strong><i class="bi bi-hash me-2"></i>Transaction ID:</strong></td>
                                        <td><span class="badge bg-primary">#${transaction.id}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-link me-2"></i>Board ID:</strong></td>
                                        <td><span class="badge bg-secondary">${transaction.board_id || 'N/A'}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-tag me-2"></i>Type:</strong></td>
                                        <td><span class="badge ${transaction.assignment_type === 'investment' ? 'badge-investment' : 'badge-referral'}">${transaction.assignment_type.replace('_', ' ').toUpperCase()}</span></td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-wallet me-2"></i>Amount:</strong></td>
                                        <td><span class="text-success fs-5"><strong>${formatCurrency(transaction.amount)}</strong></span></td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-header" style="background: var(--primary-orange); color: white;">
                                <h6 class="mb-0"><i class="bi bi-people me-2"></i>User Information</h6>
                            </div>
                            <div class="card-body">
                                <table class="table table-borderless table-sm">
                                    <tr>
                                        <td><strong><i class="bi bi-person-check me-2"></i>Receiver:</strong></td>
                                        <td>${transaction.receiver_name || 'Unknown'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-envelope me-2"></i>Receiver Email:</strong></td>
                                        <td>${transaction.receiver_email || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-telephone me-2"></i>Receiver Phone:</strong></td>
                                        <td>${transaction.receiver_phone || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-person-plus me-2"></i>Sender:</strong></td>
                                        <td>${transaction.sender_username || transaction.sender_name || 'System'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-envelope-at me-2"></i>Sender Email:</strong></td>
                                        <td>${transaction.sender_email || 'N/A'}</td>
                                    </tr>
                                    <tr>
                                        <td><strong><i class="bi bi-person-badge me-2"></i>Sender ID:</strong></td>
                                        <td>${transaction.sender_id ? `<span class="badge bg-info">${transaction.sender_id}</span>` : '<span class="text-muted">N/A</span>'}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-calendar me-2"></i>Dates & Timeline</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <table class="table table-borderless table-sm">
                                            <tr>
                                                <td><strong><i class="bi bi-clock-history me-2"></i>Original Created:</strong></td>
                                                <td>${formatDate(transaction.original_created_at)}</td>
                                            </tr>
                                            <tr>
                                                <td><strong><i class="bi bi-check-circle me-2"></i>Confirmed At:</strong></td>
                                                <td><span class="text-success">${formatDate(transaction.confirmed_at)}</span></td>
                                            </tr>
                                        </table>
                                    </div>
                                    <div class="col-md-6">
                                        <table class="table table-borderless table-sm">
                                            <tr>
                                                <td><strong><i class="bi bi-calendar-event me-2"></i>Start Date:</strong></td>
                                                <td>${transaction.start_date ? new Date(transaction.start_date).toLocaleDateString() : 'N/A'}</td>
                                            </tr>
                                            <tr>
                                                <td><strong><i class="bi bi-calendar-check me-2"></i>Maturity Date:</strong></td>
                                                <td>${transaction.maturity_date ? `<span class="badge ${new Date(transaction.maturity_date) > new Date() ? 'bg-warning' : 'bg-success'}">${new Date(transaction.maturity_date).toLocaleDateString()}</span>` : 'N/A'}</td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                ${transaction.processing_notes ? `
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="card border-0 bg-light">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="mb-0"><i class="bi bi-sticky me-2"></i>Processing Notes</h6>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    ${transaction.processing_notes}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>` : ''}
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="d-flex justify-content-center">
                            <small class="text-muted">
                                <i class="bi bi-shield-check me-1"></i>
                                This transaction has been verified and processed by the system
                            </small>
                        </div>
                    </div>
                </div>
            `;
        }

        // Auto-submit form on input change for better UX
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const searchInput = document.querySelector('input[name="search"]');
            const selects = document.querySelectorAll('select');
            
            let searchTimeout;
            
            // Auto-submit on search input with debounce
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    form.submit();
                }, 500);
            });
            
            // Auto-submit on select change
            selects.forEach(select => {
                select.addEventListener('change', function() {
                    form.submit();
                });
            });

            // Add scroll to top button
            const scrollBtn = document.createElement('button');
            scrollBtn.innerHTML = '<i class="bi bi-arrow-up"></i>';
            scrollBtn.className = 'btn position-fixed';
            scrollBtn.style.cssText = `
                bottom: 2rem; 
                right: 2rem; 
                background: var(--primary-orange); 
                color: white; 
                border-radius: 50%; 
                width: 50px; 
                height: 50px; 
                box-shadow: var(--shadow); 
                z-index: 1000;
                display: none;
                border: none;
            `;
            
            scrollBtn.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
            
            document.body.appendChild(scrollBtn);
            
            // Show/hide scroll button
            window.addEventListener('scroll', () => {
                if (window.scrollY > 300) {
                    scrollBtn.style.display = 'block';
                } else {
                    scrollBtn.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>