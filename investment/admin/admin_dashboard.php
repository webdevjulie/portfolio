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

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get filter parameters
    $selectedMonth = isset($_GET['month']) ? $_GET['month'] : '';
    $selectedYear = isset($_GET['year']) ? $_GET['year'] : '';
    
    // Build date filter conditions and parameters
    $userDateFilter = "";
    $paymentDateFilter = "";
    $withdrawalDateFilter = "";
    $userParams = [];
    $paymentParams = [];
    $withdrawalParams = [];
    
    if ($selectedMonth && $selectedYear) {
        $userDateFilter = "WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
        $paymentDateFilter = "WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
        $withdrawalDateFilter = "WHERE YEAR(confirmed_at) = ? AND MONTH(confirmed_at) = ?";
        $userParams = [$selectedYear, $selectedMonth];
        $paymentParams = [$selectedYear, $selectedMonth];
        $withdrawalParams = [$selectedYear, $selectedMonth];
    } elseif ($selectedYear) {
        $userDateFilter = "WHERE YEAR(created_at) = ?";
        $paymentDateFilter = "WHERE YEAR(created_at) = ?";
        $withdrawalDateFilter = "WHERE YEAR(confirmed_at) = ?";
        $userParams = [$selectedYear];
        $paymentParams = [$selectedYear];
        $withdrawalParams = [$selectedYear];
    }

    // Total Users Query
    $totalUsersQuery = "SELECT COUNT(*) FROM users " . $userDateFilter;
    $stmt = $pdo->prepare($totalUsersQuery);
    $stmt->execute($userParams);
    $totalUsers = $stmt->fetchColumn();

    // Total Payment History (all payments received by users)
    $totalPaymentsQuery = "SELECT COALESCE(SUM(amount), 0) FROM payment_history " . $paymentDateFilter;
    $stmt = $pdo->prepare($totalPaymentsQuery);
    $stmt->execute($paymentParams);
    $totalPayments = $stmt->fetchColumn();

    // Active Users (users who are not blocked and have active status)
    $activeUsersFilter = $userDateFilter ? str_replace("WHERE", "AND", $userDateFilter) : "";
    $activeUsersQuery = "SELECT COUNT(*) FROM users WHERE is_blocked = 0 AND active = 'active' " . $activeUsersFilter;
    $stmt = $pdo->prepare($activeUsersQuery);
    $stmt->execute($userParams);
    $activeUsers = $stmt->fetchColumn();

    // Total Withdrawals
    $totalWithdrawalsQuery = "SELECT COALESCE(SUM(amount), 0) FROM withdrawal_history " . $withdrawalDateFilter;
    $stmt = $pdo->prepare($totalWithdrawalsQuery);
    $stmt->execute($withdrawalParams);
    $totalWithdrawals = $stmt->fetchColumn();

    // Pending Investments (users with pending investment status)
    $pendingInvestmentsFilter = $userDateFilter ? str_replace("WHERE", "AND", $userDateFilter) : "";
    $pendingInvestmentsQuery = "SELECT COUNT(*) FROM users WHERE investment_status = 'pending' " . $pendingInvestmentsFilter;
    $stmt = $pdo->prepare($pendingInvestmentsQuery);
    $stmt->execute($userParams);
    $pendingInvestments = $stmt->fetchColumn();

    // Get user info for header
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get available years for dropdown
    $yearsQuery = "SELECT DISTINCT year FROM (
        SELECT YEAR(created_at) as year FROM users WHERE created_at IS NOT NULL
        UNION ALL
        SELECT YEAR(created_at) as year FROM payment_history WHERE created_at IS NOT NULL
        UNION ALL
        SELECT YEAR(confirmed_at) as year FROM withdrawal_history WHERE confirmed_at IS NOT NULL
    ) as combined_dates WHERE year IS NOT NULL ORDER BY year DESC";
    $yearsStmt = $pdo->query($yearsQuery);
    $availableYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);

    // Calculate Referral Bonuses from users.referral_total column
    $referralBonusesQuery = "SELECT COALESCE(SUM(referral_total), 0) FROM users " . $userDateFilter;
    $stmt = $pdo->prepare($referralBonusesQuery);
    $stmt->execute($userParams);
    $totalReferralBonuses = $stmt->fetchColumn();

} catch (PDOException $e) {
    die("Connection or query failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin_dashboard.css">
</head>
<body>
    <!-- Loading Overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
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
                <i class="bi bi-person-circle"></i><?= htmlspecialchars($user['fullname'] ?? 'Admin') ?>
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
            <h2 class="mb-4">Dashboard Overview</h2>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
                    <div>
                        <h5 class="filter-title mb-0">
                            <i class="bi bi-funnel-fill me-2"></i>Smart Filters
                        </h5>
                        <p class="mb-0 text-white-50">Data updates automatically when you select filters</p>
                    </div>
                    <div class="period-indicator">
                        <i class="bi bi-calendar-event"></i>
                        <span id="currentPeriod">
                            <?php 
                                if ($selectedMonth && $selectedYear) {
                                    echo date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear));
                                } elseif ($selectedYear) {
                                    echo "Year: " . $selectedYear;
                                } else {
                                    echo "All Time";
                                }
                            ?>
                        </span>
                    </div>
                </div>
                
                <form method="GET" action="" id="filterForm">
                    <div class="filter-controls">
                        <div class="filter-group">
                            <label for="month" class="form-label">
                                <i class="bi bi-calendar3 me-1"></i>Month
                            </label>
                            <select name="month" id="month" class="form-select">
                                <option value="">All Months</option>
                                <?php
                                $months = [
                                    '01' => 'January', '02' => 'February', '03' => 'March',
                                    '04' => 'April', '05' => 'May', '06' => 'June',
                                    '07' => 'July', '08' => 'August', '09' => 'September',
                                    '10' => 'October', '11' => 'November', '12' => 'December'
                                ];
                                foreach ($months as $num => $name) {
                                    $selected = ($selectedMonth == $num) ? 'selected' : '';
                                    echo "<option value='$num' $selected>$name</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label for="year" class="form-label">
                                <i class="bi bi-calendar4-range me-1"></i>Year
                            </label>
                            <select name="year" id="year" class="form-select">
                                <option value="">All Years</option>
                                <?php
                                if (empty($availableYears)) {
                                    // Fallback years if no data exists
                                    $currentYear = date('Y');
                                    for ($i = $currentYear; $i >= $currentYear - 5; $i--) {
                                        $selected = ($selectedYear == $i) ? 'selected' : '';
                                        echo "<option value='$i' $selected>$i</option>";
                                    }
                                } else {
                                    foreach ($availableYears as $year) {
                                        $selected = ($selectedYear == $year) ? 'selected' : '';
                                        echo "<option value='$year' $selected>$year</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="clear-btn">
                                <i class="bi bi-arrow-clockwise me-2"></i>Reset Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Dashboard Cards -->
            <div class="row g-4">
                <!-- Total Users Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card total-users shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Total Users</h5>
                                    <p class="display-6 mb-0"><?= number_format($totalUsers) ?></p>
                                </div>
                                <div class="card-icon total-users">
                                    <i class="bi bi-people-fill"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                <?php if ($selectedMonth && $selectedYear): ?>
                                    Registered in <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?>
                                <?php elseif ($selectedYear): ?>
                                    Registered in <?= $selectedYear ?>
                                <?php else: ?>
                                    Total registered users
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Active Users Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card active-users shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Active Users</h5>
                                    <p class="display-6 mb-0"><?= number_format($activeUsers) ?></p>
                                </div>
                                <div class="card-icon active-users">
                                    <i class="bi bi-person-check-fill"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-check-circle me-1"></i>
                                <?php 
                                $activePercentage = $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0;
                                echo $activePercentage . "% of total users";
                                ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Total Payments Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card total-investments shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Total Payments</h5>
                                    <p class="display-6 mb-0">₨ <?= number_format($totalPayments, 2) ?></p>
                                </div>
                                <div class="card-icon total-investments">
                                    <i class="bi bi-credit-card-fill"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-graph-up-arrow me-1"></i>
                                <?php if ($selectedMonth && $selectedYear): ?>
                                    Payments in <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?>
                                <?php elseif ($selectedYear): ?>
                                    Payments in <?= $selectedYear ?>
                                <?php else: ?>
                                    All payment transactions
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Total Withdrawals Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card total-withdrawals shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Total Withdrawals</h5>
                                    <p class="display-6 mb-0">₨ <?= number_format($totalWithdrawals, 2) ?></p>
                                </div>
                                <div class="card-icon total-withdrawals">
                                    <i class="bi bi-arrow-up-circle-fill"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-cash-stack me-1"></i>
                                <?php if ($selectedMonth && $selectedYear): ?>
                                    Withdrawn in <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?>
                                <?php elseif ($selectedYear): ?>
                                    Withdrawn in <?= $selectedYear ?>
                                <?php else: ?>
                                    Total withdrawal amount
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Pending Investments Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card pending-matches shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Pending Investments</h5>
                                    <p class="display-6 mb-0"><?= number_format($pendingInvestments) ?></p>
                                </div>
                                <div class="card-icon pending-matches">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-hourglass-split me-1"></i>
                                Users awaiting investment processing
                            </small>
                        </div>
                    </div>
                </div>

                <!-- Referral Bonuses Card -->
                <div class="col-lg-3 col-md-6">
                    <div class="card dashboard-card referral-payouts shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h5 class="card-title mb-2">Referral Bonuses</h5>
                                    <p class="display-6 mb-0">₨ <?= number_format($totalReferralBonuses, 2) ?></p>
                                </div>
                                <div class="card-icon referral-payouts">
                                    <i class="bi bi-gift-fill"></i>
                                </div>
                            </div>
                            <small class="text-muted">
                                <i class="bi bi-award me-1"></i>
                                <?php if ($selectedMonth && $selectedYear): ?>
                                    Bonuses for users registered in <?= date('F Y', mktime(0, 0, 0, $selectedMonth, 1, $selectedYear)) ?>
                                <?php elseif ($selectedYear): ?>
                                    Bonuses for users registered in <?= $selectedYear ?>
                                <?php else: ?>
                                    Total referral bonuses
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
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

        // Add smooth scrolling
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                document.querySelector(this.getAttribute('href')).scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Auto-submit form when dropdowns change
        document.getElementById('month').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        document.getElementById('year').addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });

        // Show loading overlay when form is submitted
        document.getElementById('filterForm').addEventListener('submit', function() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        });
    </script>

    <style>
        .active-users {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
        }
        
        .total-withdrawals {
            background: linear-gradient(135deg, #fd7e14, #e83e8c);
            color: white;
        }
        
        .card-icon.active-users,
        .card-icon.total-withdrawals {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .dashboard-card.active-users .text-muted,
        .dashboard-card.total-withdrawals .text-muted {
            color: rgba(255, 255, 255, 0.8) !important;
        }
    </style>
</body>
</html>