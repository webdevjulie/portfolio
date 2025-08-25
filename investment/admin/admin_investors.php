<?php
// Database connection
$host = 'localhost';
$dbname = 'investment';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Pagination and search
$records_per_page = 7;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$offset = ($page - 1) * $records_per_page;

// Build search query
$search_condition = '';
$params = [];
if ($search) {
    $search_condition = "AND (u.fullname LIKE :search OR u.email LIKE :search OR ph.package_name LIKE :search OR ph.payment_type LIKE :search OR ph.payment_status LIKE :search)";
    $params[':search'] = "%$search%";
}

// Get total count
try {
    $count_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM payment_history ph INNER JOIN users u ON ph.user_id = u.id WHERE 1=1 $search_condition");
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $records_per_page);
} catch (PDOException $e) {
    $total_records = 0;
    $total_pages = 0;
}

// Get payment records
try {
    $stmt = $pdo->prepare("
        SELECT ph.*, u.fullname, u.email, u.phone
        FROM payment_history ph
        INNER JOIN users u ON ph.user_id = u.id
        WHERE 1=1 $search_condition
        ORDER BY ph.created_at DESC
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $payment_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $payment_records = [];
}

// Helper functions
function getBadgeClass($type, $value) {
    $classes = [
        'payment_type' => [
            'investment_return' => 'bg-primary',
            'referral_bonus' => 'bg-success',
            'withdrawal' => 'bg-warning'
        ],
        'status' => [
            'completed' => 'bg-success',
            'pending' => 'bg-warning',
            'failed' => 'bg-danger'
        ]
    ];
    return $classes[$type][$value] ?? 'bg-secondary';
}

function formatCurrency($amount) {
    return 'Rs' . number_format($amount, 2);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History - WebCash Investment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin_investors.css">
    <style>
        .mobile-toggle {
            background-color: #ffa052ff;
            color: white;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        .mobile-toggle:hover { background-color: #f39243ff; }
        .table th { background-color: #f8f9fa; }
        .empty-state { text-align: center; padding: 3rem 1rem; }
        .empty-state i { font-size: 4rem; color: #dee2e6; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="#" class="sidebar-brand">
                <i class="bi bi-graph-up-arrow"></i>WebCash Investment
            </a>
        </div>
        <ul class="sidebar-nav list-unstyled">
            <li class="nav-item">
                <a href="admin_dashboard.php" class="nav-link <?= $currentPage === 'admin_dashboard.php' ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i>Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_packages.php" class="nav-link <?= $currentPage === 'admin_packages.php' ? 'active' : '' ?>">
                    <i class="bi bi-box-seam"></i>Manage Packages
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_investors.php" class="nav-link <?= $currentPage === 'admin_investors.php' ? 'active' : '' ?>">
                    <i class="bi bi-bank"></i>Investors History
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_transactions.php" class="nav-link <?= $currentPage === 'admin_transactions.php' ? 'active' : '' ?>">
                    <i class="bi bi-credit-card"></i>Withdrawals History
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_users.php" class="nav-link <?= $currentPage === 'admin_users.php' ? 'active' : '' ?>">
                    <i class="bi-person-lines-fill"></i>Manage Users
                </a>
            </li>
            <li class="nav-item">
                <a href="admin_user_management.php" class="nav-link <?= $currentPage === 'admin_user_management.php' ? 'active' : '' ?>">
                    <i class="bi bi-person-gear"></i>User Management
                </a>
            </li>
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
            <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-person-circle"></i><?= htmlspecialchars($current_user['username'] ?? 'Admin') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="admin_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Search -->
        <div class="search-container mb-4">
            <form method="GET" id="searchForm">
                <div class="position-relative">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="form-control search-input" name="search" 
                           value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search by name, email, package, payment type, or status...">
                </div>
            </form>
        </div>

        <!-- Payment History Table -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment History</h5>
                        <small class="opacity-75">Showing <?= count($payment_records) ?> of <?= $total_records ?> records</small>
                    </div>
                    <i class="bi bi-cash-stack display-6 opacity-25"></i>
                </div>
            </div>
            
            <div class="card-body p-0">
                <?php if ($payment_records): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><i class="bi bi-person me-2"></i>User</th>
                                    <th><i class="bi bi-box me-2"></i>Package</th>
                                    <th><i class="bi bi-tag me-2"></i>Type</th>
                                    <th><i class="bi bi-wallet me-2"></i>Amount</th>
                                    <th><i class="bi bi-flag me-2"></i>Status</th>
                                    <th><i class="bi bi-calendar me-2"></i>Date</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_records as $record): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($record['fullname']) ?></div>
                                        <small class="text-muted">
                                            <i class="bi bi-envelope me-1"></i><?= htmlspecialchars($record['email']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?= htmlspecialchars($record['package_name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?= getBadgeClass('payment_type', $record['payment_type']) ?>">
                                            <?= ucfirst(str_replace('_', ' ', $record['payment_type'])) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold text-success"><?= formatCurrency($record['amount']) ?></td>
                                    <td>
                                        <span class="badge <?= getBadgeClass('status', $record['payment_status']) ?>">
                                            <?= ucfirst($record['payment_status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div><?= date('M d, Y', strtotime($record['payment_date'])) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($record['payment_date'])) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" 
                                                data-bs-target="#detailsModal" onclick="showDetails(<?= htmlspecialchars(json_encode($record)) ?>)">
                                            <i class="bi bi-eye me-1"></i>View
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-center mt-4">
                        <nav>
                            <ul class="pagination">
                                <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <i class="bi bi-credit-card"></i>
                        <h4 class="text-muted mt-3"><?= $search ? 'No payment records found' : 'No payment records yet' ?></h4>
                        <p class="text-muted"><?= $search ? 'Try adjusting your search terms.' : 'No payment transactions recorded yet.' ?></p>
                        <?php if ($search): ?>
                        <a href="?" class="btn btn-outline-primary">
                            <i class="bi bi-arrow-left me-2"></i>Show All Records
                        </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-credit-card me-2"></i>Payment Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- User Info -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-person me-2"></i>User Information</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Name:</strong> <span id="modal-name"></span></p>
                                    <p><strong>Email:</strong> <span id="modal-email"></span></p>
                                    <p class="mb-0"><strong>Phone:</strong> <span id="modal-phone"></span></p>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Info -->
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-credit-card me-2"></i>Payment Info</h6>
                                </div>
                                <div class="card-body">
                                    <p><strong>Type:</strong> <span id="modal-type" class="badge"></span></p>
                                    <p><strong>Package:</strong> <span id="modal-package" class="badge bg-light text-dark border"></span></p>
                                    <p class="mb-0"><strong>Status:</strong> <span id="modal-status" class="badge"></span></p>
                                </div>
                            </div>
                        </div>
                        

                        
                        <!-- Sender Info (conditional) -->
                        <div class="col-12" id="sender-section" style="display:none;">
                            <div class="card">
                                <div class="card-header bg-light">
                                    <h6 class="mb-0"><i class="bi bi-person-check me-2"></i>Sender Information</h6>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-4"><p><strong>Name:</strong> <span id="modal-sender-name"></span></p></div>
                                        <div class="col-md-4"><p><strong>Email:</strong> <span id="modal-sender-email"></span></p></div>
                                        <div class="col-md-4"><p><strong>Phone:</strong> <span id="modal-sender-phone"></span></p></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Show payment details in modal
        function showDetails(record) {
            document.getElementById('modal-name').textContent = record.fullname;
            document.getElementById('modal-email').textContent = record.email;
            document.getElementById('modal-phone').textContent = record.phone || 'Not provided';
            
            const typeElement = document.getElementById('modal-type');
            typeElement.textContent = record.payment_type.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
            typeElement.className = 'badge <?= getBadgeClass("payment_type", "") ?>' + getTypeBadgeClass(record.payment_type);
            
            document.getElementById('modal-package').textContent = record.package_name;
            
            const statusElement = document.getElementById('modal-status');
            statusElement.textContent = record.payment_status.charAt(0).toUpperCase() + record.payment_status.slice(1);
            statusElement.className = 'badge ' + getStatusBadgeClass(record.payment_status);
            

            
            // Show sender info if available
            const senderSection = document.getElementById('sender-section');
            if (record.sender_name || record.sender_email || record.sender_phone) {
                document.getElementById('modal-sender-name').textContent = record.sender_name || 'Not provided';
                document.getElementById('modal-sender-email').textContent = record.sender_email || 'Not provided';
                document.getElementById('modal-sender-phone').textContent = record.sender_phone || 'Not provided';
                senderSection.style.display = 'block';
            } else {
                senderSection.style.display = 'none';
            }
        }
        
        function getTypeBadgeClass(type) {
            const classes = { investment_return: 'bg-primary', referral_bonus: 'bg-success', withdrawal: 'bg-warning' };
            return classes[type] || 'bg-secondary';
        }
        
        function getStatusBadgeClass(status) {
            const classes = { completed: 'bg-success', pending: 'bg-warning', failed: 'bg-danger' };
            return classes[status] || 'bg-secondary';
        }
        
        // Auto-submit search with delay
        let searchTimeout;
        document.querySelector('input[name="search"]').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => document.getElementById('searchForm').submit(), 500);
        });
        
        // Mobile sidebar toggle
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }
        
        // Close sidebar on outside click (mobile)
        document.addEventListener('click', function(e) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.querySelector('.mobile-toggle');
            if (window.innerWidth <= 768 && !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('show');
            }
        });
    </script>
</body>
</html>