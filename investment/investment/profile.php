<?php
include '../includes/db.php';
include '../includes/session.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Get user data with proper prepared statement
$userId = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

// Get user investments data
$investmentsStmt = $conn->prepare("SELECT * FROM user_investments WHERE user_id = ? ORDER BY created_at DESC");
$investmentsStmt->bind_param("i", $userId);
$investmentsStmt->execute();
$investmentsResult = $investmentsStmt->get_result();
$userInvestments = $investmentsResult->fetch_all(MYSQLI_ASSOC);

// Calculate investment totals
$totalInvestments = 0;
$totalExpectedReturns = 0;
$totalProfit = 0;
$pendingInvestments = 0;
$activeInvestments = 0;
$completedInvestments = 0;

// Get the most recent active investment for maturity calculation
$latestActiveInvestment = null;
$earliestStartDate = null;
$latestMaturityDate = null;

foreach ($userInvestments as $investment) {
    $totalInvestments += $investment['investment_amount'];
    $totalExpectedReturns += $investment['expected_return'];
    $totalProfit += $investment['profit_amount'];
    
    switch ($investment['investment_status']) {
        case 'pending':
            $pendingInvestments += $investment['investment_amount'];
            break;
        case 'active':
            $activeInvestments += $investment['investment_amount'];
            if (!$latestActiveInvestment || strtotime($investment['start_date']) > strtotime($latestActiveInvestment['start_date'])) {
                $latestActiveInvestment = $investment;
            }
            // Track earliest start date and latest maturity date
            if (!$earliestStartDate || strtotime($investment['start_date']) < strtotime($earliestStartDate)) {
                $earliestStartDate = $investment['start_date'];
            }
            if (!$latestMaturityDate || strtotime($investment['maturity_date']) > strtotime($latestMaturityDate)) {
                $latestMaturityDate = $investment['maturity_date'];
            }
            break;
        case 'completed':
            $completedInvestments += $investment['investment_amount'];
            break;
    }
}

// Calculate days until maturity based on latest active investment
$daysRemaining = 0;
$isMatured = false;
$maturityDate = null;
$startDate = null;

if ($latestActiveInvestment) {
    $maturityDate = $latestActiveInvestment['maturity_date'] ? new DateTime($latestActiveInvestment['maturity_date']) : null;
    $startDate = $latestActiveInvestment['start_date'] ? new DateTime($latestActiveInvestment['start_date']) : null;
} elseif ($latestMaturityDate) {
    // Use latest maturity date if no active investments
    $maturityDate = new DateTime($latestMaturityDate);
    $startDate = $earliestStartDate ? new DateTime($earliestStartDate) : null;
}

$currentDate = new DateTime();

if ($maturityDate) {
    if ($currentDate > $maturityDate) {
        $isMatured = true;
    } else {
        $daysRemaining = $currentDate->diff($maturityDate)->days;
    }
}

// Get additional transaction data for pending amounts
$pendingTransactionsStmt = $conn->prepare("SELECT SUM(amount) as pending_transactions FROM transactions WHERE user_id = ? AND type = 'investment' AND status = 'pending'");
$pendingTransactionsStmt->bind_param("i", $userId);
$pendingTransactionsStmt->execute();
$pendingTransactionsResult = $pendingTransactionsStmt->get_result();
$pendingTransactionsData = $pendingTransactionsResult->fetch_assoc();
$pendingTransactions = $pendingTransactionsData['pending_transactions'] ?? 0;

// Get referral information
$referralBonus = $user['referral_bonus'] ?? 0;
$referralTotal = $user['referral_total'] ?? 0;

// Get referrer information if user has referral_id
$referrerName = null;
if ($user['referral_id']) {
    $referrerStmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $referrerStmt->bind_param("i", $user['referral_id']);
    $referrerStmt->execute();
    $referrerResult = $referrerStmt->get_result();
    if ($referrerData = $referrerResult->fetch_assoc()) {
        $referrerName = $referrerData['email'];
    }
}

// Count total referrals made by this user
$referralCountStmt = $conn->prepare("SELECT COUNT(*) as total_referrals FROM users WHERE referral_id = ?");
$referralCountStmt->bind_param("i", $userId);
$referralCountStmt->execute();
$referralCountResult = $referralCountStmt->get_result();
$totalReferrals = $referralCountResult->fetch_assoc()['total_referrals'];

// Get total completed transactions for additional stats
$completedTransactionsStmt = $conn->prepare("SELECT SUM(amount) as total_completed FROM transactions WHERE user_id = ? AND status = 'completed'");
$completedTransactionsStmt->bind_param("i", $userId);
$completedTransactionsStmt->execute();
$completedTransactionsResult = $completedTransactionsStmt->get_result();
$totalCompletedTransactions = $completedTransactionsResult->fetch_assoc()['total_completed'] ?? 0;

// Count total investments
$totalInvestmentCount = count($userInvestments);
$activeInvestmentCount = count(array_filter($userInvestments, function($inv) { return $inv['investment_status'] == 'active'; }));
$completedInvestmentCount = count(array_filter($userInvestments, function($inv) { return $inv['investment_status'] == 'completed'; }));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Investment Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/customers_profile.css">
</head>
<body>
    <!-- Sidebar - Include your sidebar.php here -->
    <?php include 'sidebar.php'; ?>
    
    <!-- Main Content -->
    <div class="content page-transition">
        <!-- Profile Header -->
        <div class="profile-header">
            <div class="d-flex align-items-center">
                <div class="profile-avatar">
                    <span class="material-icons" style="font-size: 3rem;">person</span>
                </div>
                <div class="ms-4">
                    <h1 class="profile-name">
                        <?= htmlspecialchars($user['fullname'] ?? $user['email']) ?>
                    </h1>
                    <p class="profile-email">
                        <span class="material-icons" style="font-size: 1.2rem; margin-right: 0.5rem;">email</span>
                        <?= htmlspecialchars($user['email']) ?> | Member ID: #<?= str_pad($user['id'], 4, '0', STR_PAD_LEFT) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">account_balance_wallet</span>
                </div>
                <div class="stat-value">₨ <?= number_format($totalInvestments) ?></div>
                <p class="stat-label">Total Investments</p>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">trending_up</span>
                </div>
                <div class="stat-value">₨ <?= number_format($totalExpectedReturns) ?></div>
                <p class="stat-label">Expected Returns</p>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">emoji_events</span>
                </div>
                <div class="stat-value">₨ <?= number_format($totalProfit) ?></div>
                <p class="stat-label">Total Profit</p>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">pie_chart</span>
                </div>
                <div class="stat-value"><?= $totalInvestmentCount ?></div>
                <p class="stat-label">Investment Packages</p>
            </div>

            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">group</span>
                </div>
                <div class="stat-value"><?= $totalReferrals ?></div>
                <p class="stat-label">Total Referrals</p>
            </div>

            <?php if ($completedInvestments > 0): ?>
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">check_circle</span>
                </div>
                <div class="stat-value">₨ <?= number_format($completedInvestments) ?></div>
                <p class="stat-label">Completed Investments</p>
            </div>
            <?php endif; ?>

            <?php if ($referralBonus > 0): ?>
            <div class="stat-card">
                <div class="stat-icon">
                    <span class="material-icons">card_giftcard</span>
                </div>
                <div class="stat-value">₨ <?= number_format($referralBonus, 2) ?></div>
                <p class="stat-label">Referral Bonus</p>
            </div>
            <?php endif; ?>

            <?php if ($maturityDate && !$isMatured): ?>
                <div class="stat-card countdown-card">
                    <div class="countdown-number"><?= $daysRemaining ?></div>
                    <div class="countdown-label">Days Until Maturity</div>
                </div>
            <?php elseif ($isMatured): ?>
                <div class="stat-card">
                    <div class="matured-badge">
                        <span class="material-icons me-2">check_circle</span>
                        Investment Matured!
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Information Cards -->
        <div class="info-cards">
            <div class="info-card">
                <h5><span class="material-icons">account_circle</span>Account Information</h5>
                
                <?php if ($user['fullname']): ?>
                <div class="info-item">
                    <span class="info-label">Full Name</span>
                    <span class="info-value"><?= htmlspecialchars($user['fullname']) ?></span>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <span class="info-label">Email Address</span>
                    <span class="info-value"><?= htmlspecialchars($user['email']) ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Member ID</span>
                    <span class="info-value">#<?= str_pad($user['id'], 4, '0', STR_PAD_LEFT) ?></span>
                </div>
                
                <?php if ($referrerName): ?>
                <div class="info-item">
                    <span class="info-label">Referred By</span>
                    <span class="info-value"><?= htmlspecialchars($referrerName) ?></span>
                </div>
                <?php endif; ?>
                
                <div class="info-item">
                    <span class="info-label">Account Status</span>
                    <span class="status-badge status-<?= $user['investment_status'] ?? 'pending' ?>">
                        <?= ucfirst($user['investment_status'] ?? 'pending') ?>
                    </span>
                </div>
            </div>

            <div class="info-card">
                <h5><span class="material-icons">pie_chart</span>Investment Summary</h5>
                
                <div class="info-item">
                    <span class="info-label">Total Packages</span>
                    <span class="info-value"><?= $totalInvestmentCount ?> Investments</span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Active Packages</span>
                    <span class="info-value"><?= $activeInvestmentCount ?> Active</span>
                </div>
        
                <?php if ($startDate): ?>
                <div class="info-item">
                    <span class="info-label">First Investment</span>
                    <span class="info-value"><?= date('M d, Y', strtotime($earliestStartDate)) ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($maturityDate): ?>
                <div class="info-item">
                    <span class="info-label">Latest Maturity</span>
                    <span class="info-value"><?= date('M d, Y', strtotime($latestMaturityDate)) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($userInvestments)): ?>
            <div class="info-card">
                <h5><span class="material-icons">list</span>Investment Packages</h5>
                
                <div class="investment-list">
                    <?php foreach ($userInvestments as $investment): ?>
                    <div class="investment-item">
                        <div class="investment-header">
                            <span class="investment-name"><?= htmlspecialchars($investment['package_name']) ?></span>
                            <span class="status-badge status-<?= $investment['investment_status'] ?>">
                                <?= ucfirst($investment['investment_status']) ?>
                            </span>
                        </div>
                        <div class="investment-details">
                            <div><strong>Amount:</strong> ₨<?= number_format($investment['investment_amount']) ?></div>
                            <div><strong>Expected:</strong> ₨<?= number_format($investment['expected_return']) ?></div>
                            <div><strong>Profit:</strong> ₨<?= number_format($investment['profit_amount']) ?></div>
                            <?php if ($investment['start_date']): ?>
                            <div><strong>Start:</strong> <?= date('M d, Y', strtotime($investment['start_date'])) ?></div>
                            <?php endif; ?>
                            <?php if ($investment['maturity_date']): ?>
                            <div><strong>Maturity:</strong> <?= date('M d, Y', strtotime($investment['maturity_date'])) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($referralBonus > 0 || $totalReferrals > 0): ?>
            <div class="info-card">
                <h5><span class="material-icons">group</span>Referral Program</h5>
                
                <div class="info-item">
                    <span class="info-label">Total Referrals</span>
                    <span class="info-value"><?= $totalReferrals ?> Users</span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Referral Bonus</span>
                    <span class="info-value">₨ <?= number_format($referralBonus, 2) ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Total Referral Earnings</span>
                    <span class="info-value">₨ <?= number_format($referralTotal, 2) ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Your Referral Link</span>
                    <span class="info-value">
                        <small style="color: var(--primary-orange);">
                           http://localhost/investment/auth/register.php?ref=<?= $user['id'] ?>
                        </small>
                    </span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="packages.php" class="btn-modern btn-primary-modern">
                <span class="material-icons me-2" style="font-size: 1.2rem;">add</span>
                New Investment
            </a>
            <a href="edit-profile.php" class="btn-modern btn-secondary-modern">
                <span class="material-icons me-2" style="font-size: 1.2rem;">edit</span>
                Edit Profile
            </a>
        </div>
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

            // Add hover effects to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-5px) scale(1.02)';
                });
                
                card.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0) scale(1)';
                });
            });

            // Add click feedback to buttons
            const buttons = document.querySelectorAll('.btn-modern');
            buttons.forEach(button => {
                button.addEventListener('click', function(e) {
                    this.style.transform = 'translateY(-2px) scale(0.98)';
                    setTimeout(() => {
                        this.style.transform = 'translateY(-2px) scale(1)';
                    }, 150);
                });
            });

            // Add smooth scrolling to investment list
            const investmentList = document.querySelector('.investment-list');
            if (investmentList) {
                investmentList.addEventListener('scroll', function() {
                    this.style.scrollBehavior = 'smooth';
                });
            }

            // Add animation to investment items on load
            const investmentItems = document.querySelectorAll('.investment-item');
            investmentItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                
                setTimeout(() => {
                    item.style.opacity = '1';
                    item.style.transform = 'translateX(0)';
                }, 200 + (index * 100));
            });
        });
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