<?php
include '../includes/db.php';
include '../includes/session.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch user info
$userId = $_SESSION['user_id'];
$userQuery = $conn->prepare("SELECT * FROM users WHERE id = ?");
$userQuery->bind_param("i", $userId);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();

// Generate referral link
$referralLink = "http://" . $_SERVER['HTTP_HOST'] . "/investment/auth/register.php?ref=" . $user['id'];

// Initialize variables
$selectedPackage = null;
$packageName = "No Package Selected";
$packageAmount = 0;
$expectedReturns = 0;
$paymentCompleted = false;

// Fetch user investment
$investmentQuery = $conn->prepare("
    SELECT ui.*, p.name as package_name, p.amount as package_amount 
    FROM user_investments ui 
    LEFT JOIN packages p ON ui.package_id = p.id 
    WHERE ui.user_id = ? 
    ORDER BY ui.created_at DESC 
    LIMIT 1
");
$investmentQuery->bind_param("i", $userId);
$investmentQuery->execute();
$userInvestment = $investmentQuery->get_result()->fetch_assoc();

if ($userInvestment) {
    $selectedPackage = [
        'id' => $userInvestment['package_id'],
        'name' => $userInvestment['package_name'],
        'amount' => $userInvestment['investment_amount'],
        'status' => $userInvestment['investment_status']
    ];
    $packageName = $userInvestment['package_name'];
    $packageAmount = $userInvestment['investment_amount'];
    $expectedReturns = $userInvestment['expected_return'];
    
    if ($userInvestment['investment_status'] === 'completed') {
        $paymentCompleted = true;
    }
} elseif (isset($user['package_id']) && $user['package_id'] > 0) {
    $packageQuery = $conn->prepare("SELECT * FROM packages WHERE id = ?");
    $packageQuery->bind_param("i", $user['package_id']);
    $packageQuery->execute();
    $selectedPackage = $packageQuery->get_result()->fetch_assoc();
    
    if ($selectedPackage) {
        $packageName = $selectedPackage['name'];
        $packageAmount = $selectedPackage['amount'];
        $expectedReturns = $packageAmount * 1.5;
    }
}

// Fetch ongoing investments
$ongoingInvestmentsQuery = $conn->prepare("
    SELECT ui.*, p.name as package_name, p.amount as package_amount 
    FROM user_investments ui 
    LEFT JOIN packages p ON ui.package_id = p.id 
    WHERE ui.user_id = ? AND ui.investment_status IN ('pending', 'active')
    ORDER BY ui.created_at DESC
");
$ongoingInvestmentsQuery->bind_param("i", $userId);
$ongoingInvestmentsQuery->execute();
$ongoingInvestments = $ongoingInvestmentsQuery->get_result();

// Fetch ongoing referral withdrawals
$ongoingWithdrawalsQuery = $conn->prepare("
    SELECT rw.* 
    FROM referral_withdrawals rw 
    WHERE rw.user_id = ? AND rw.withdrawal_status IN ('pending', 'active')
    ORDER BY rw.created_at DESC
");
$ongoingWithdrawalsQuery->bind_param("i", $userId);
$ongoingWithdrawalsQuery->execute();
$ongoingWithdrawals = $ongoingWithdrawalsQuery->get_result();

function getSenderInfo($boardRecord, $conn) {
    $senders = [];
    
    // Check for multi-sender based on is_multi_sender flag
    if ($boardRecord['is_multi_sender'] == 1) {
        // For multi-sender, we need to get all boards with the same group_id or reference_id
        $groupCondition = !empty($boardRecord['group_id']) ? 'group_id = ?' : 'reference_id = ?';
        $groupValue = !empty($boardRecord['group_id']) ? $boardRecord['group_id'] : $boardRecord['reference_id'];
        
        $multiSenderQuery = $conn->prepare("
            SELECT DISTINCT sender_id, sender_name, amount, sender_package_amount 
            FROM boards 
            WHERE {$groupCondition} AND sender_id != ? AND sender_id IS NOT NULL
        ");
        $multiSenderQuery->bind_param("si", $groupValue, $boardRecord['receiver_id']);
        $multiSenderQuery->execute();
        $multiSenderResults = $multiSenderQuery->get_result();
        
        $index = 0;
        while ($senderRow = $multiSenderResults->fetch_assoc()) {
            // Get sender details from users table
            $senderQuery = $conn->prepare("SELECT id, fullname, email, phone FROM users WHERE id = ?");
            $senderQuery->bind_param("i", $senderRow['sender_id']);
            $senderQuery->execute();
            $senderResult = $senderQuery->get_result()->fetch_assoc();
            
            if ($senderResult) {
                $senders[] = [
                    'id' => $senderResult['id'],
                    'fullname' => $senderResult['fullname'] ?? $senderRow['sender_name'] ?? 'N/A',
                    'email' => $senderResult['email'] ?? 'N/A',
                    'phone' => $senderResult['phone'] ?? 'N/A',
                    'amount' => floatval($senderRow['amount']),
                    'sender_index' => $index
                ];
                $index++;
            }
        }
    } else {
        // Single sender - get from current board record
        $sender = [
            'id' => $boardRecord['sender_id'],
            'fullname' => 'N/A',
            'email' => 'N/A', 
            'phone' => 'N/A',
            'amount' => floatval($boardRecord['amount']),
            'sender_index' => 0
        ];
        
        // If sender_id exists, fetch from users table
        if (!empty($boardRecord['sender_id'])) {
            $senderQuery = $conn->prepare("SELECT id, fullname, email, phone FROM users WHERE id = ?");
            $senderQuery->bind_param("i", $boardRecord['sender_id']);
            $senderQuery->execute();
            $result = $senderQuery->get_result()->fetch_assoc();
            
            if ($result) {
                $sender['id'] = $result['id'];
                $sender['fullname'] = $result['fullname'] ?? $boardRecord['sender_name'] ?? 'N/A';
                $sender['email'] = $result['email'] ?? 'N/A';
                $sender['phone'] = $result['phone'] ?? 'N/A';
            } else {
                // Fallback to stored sender_name in boards table
                $sender['fullname'] = $boardRecord['sender_name'] ?? 'N/A';
            }
        } else {
            // Fallback to stored sender_name in boards table
            $sender['fullname'] = $boardRecord['sender_name'] ?? 'N/A';
        }
        
        $senders[] = $sender;
    }
    
    return $senders;
}


// Check if user is a sender (needs to make payment) - GET ALL PENDING ASSIGNMENTS
$isSender = false;
$allSenderAssignments = [];

// Get ALL pending boards where user is a sender
$senderQuery = $conn->prepare("
    SELECT * FROM boards 
    WHERE sender_id = ? 
    AND status = 'pending' 
    AND assignment_type = 'investment'
    ORDER BY created_at DESC
");
$senderQuery->bind_param("i", $userId);
$senderQuery->execute();
$senderResults = $senderQuery->get_result();

while($senderResult = $senderResults->fetch_assoc()) {
    $isSender = true;
    
    // Get the current user's investment amount (the sender's amount)
    $senderInvestmentQuery = $conn->prepare("
        SELECT investment_amount 
        FROM user_investments 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $senderInvestmentQuery->bind_param("i", $userId);
    $senderInvestmentQuery->execute();
    $senderInvestmentResult = $senderInvestmentQuery->get_result()->fetch_assoc();
    $senderInvestmentAmount = $senderInvestmentResult ? floatval($senderInvestmentResult['investment_amount']) : floatval($senderResult['sender_package_amount'] ?: $senderResult['amount']);
    
    // Get receiver details
    $receiverQuery = $conn->prepare("SELECT id, fullname, email, phone FROM users WHERE id = ?");
    $receiverQuery->bind_param("i", $senderResult['receiver_id']);
    $receiverQuery->execute();
    $receiverInfo = $receiverQuery->get_result()->fetch_assoc();
    
    // Add to assignments array
    $allSenderAssignments[] = [
        'board' => $senderResult,
        'receiver_info' => $receiverInfo,
        'amount' => $senderInvestmentAmount
    ];
}

// Check for pending payments (user as receiver)
$isReceiver = false;
$receiverDetails = null;
$receiverQuery = $conn->prepare("
    SELECT * FROM boards 
    WHERE receiver_id = ? AND status = 'pending' AND assignment_type = 'investment'
    ORDER BY created_at DESC LIMIT 1
");
$receiverQuery->bind_param("i", $userId);
$receiverQuery->execute();
$receiverResult = $receiverQuery->get_result()->fetch_assoc();

if ($receiverResult) {
    $isReceiver = true;
    $receiverDetails = $receiverResult;
}

// Check for referral withdrawals
$isReferralReceiver = false;
$referralDetails = null;
$referralQuery = $conn->prepare("
    SELECT * FROM boards 
    WHERE receiver_id = ? AND status = 'pending' AND assignment_type = 'referral_withdrawal'
    ORDER BY created_at DESC LIMIT 1
");
$referralQuery->bind_param("i", $userId);
$referralQuery->execute();
$referralResult = $referralQuery->get_result()->fetch_assoc();

if ($referralResult) {
    $isReferralReceiver = true;
    $referralDetails = $referralResult;
}

// Get referral data
$referralCount = floatval($user['total_referrals'] ?? 0);
$totalReferralBonus = floatval($user['referral_earnings'] ?? 0);
$outgoingDonations = $userInvestment ? $userInvestment['investment_amount'] : ($packageAmount > 0 ? $packageAmount : 0);

// Check if user is a withdrawal sender (needs to make referral withdrawal payment) - GET ALL PENDING ASSIGNMENTS
$isWithdrawalSender = false;
$allWithdrawalSenderAssignments = [];

// Get ALL pending boards where user is a sender for referral withdrawals
$withdrawalSenderQuery = $conn->prepare("
    SELECT * FROM boards 
    WHERE sender_id = ? 
    AND status = 'pending' 
    AND assignment_type = 'referral_withdrawal'
    ORDER BY created_at DESC
");
$withdrawalSenderQuery->bind_param("i", $userId);
$withdrawalSenderQuery->execute();
$withdrawalSenderResults = $withdrawalSenderQuery->get_result();

while($withdrawalSenderResult = $withdrawalSenderResults->fetch_assoc()) {
    $isWithdrawalSender = true;
    
    // Get receiver details
    $receiverQuery = $conn->prepare("SELECT id, fullname, email, phone FROM users WHERE id = ?");
    $receiverQuery->bind_param("i", $withdrawalSenderResult['receiver_id']);
    $receiverQuery->execute();
    $receiverInfo = $receiverQuery->get_result()->fetch_assoc();
    
    // Add to withdrawal assignments array
    $allWithdrawalSenderAssignments[] = [
        'board' => $withdrawalSenderResult,
        'receiver_info' => $receiverInfo
    ];
}

function getSenderMaturityInfo($senderId, $receiverDetails, $conn) {
    $maturityInfo = [
        'start_date' => null,
        'maturity_date' => null,
        'days_remaining' => 0,
        'can_confirm' => false
    ];
    
    // Get the board record for this sender-receiver pair
    if ($receiverDetails['is_multi_sender'] == 1) {
        // For multi-sender, find the specific board record for this sender
        $groupCondition = !empty($receiverDetails['group_id']) ? 'group_id = ?' : 'reference_id = ?';
        $groupValue = !empty($receiverDetails['group_id']) ? $receiverDetails['group_id'] : $receiverDetails['reference_id'];
        
        $query = $conn->prepare("
            SELECT start_date, maturity_date
            FROM boards 
            WHERE {$groupCondition} AND sender_id = ? AND assignment_type = 'investment'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $query->bind_param("si", $groupValue, $senderId);
    } else {
        // For single sender, use the current board record
        $query = $conn->prepare("
            SELECT start_date, maturity_date
            FROM boards 
            WHERE id = ? AND sender_id = ?
            LIMIT 1
        ");
        $query->bind_param("ii", $receiverDetails['id'], $senderId);
    }
    
    $query->execute();
    $result = $query->get_result()->fetch_assoc();
    
    if ($result) {
        $maturityInfo['start_date'] = $result['start_date'];
        $maturityInfo['maturity_date'] = $result['maturity_date'];
        
        if ($result['maturity_date']) {
            $today = new DateTime();
            $maturityDate = new DateTime($result['maturity_date']);
            $interval = $today->diff($maturityDate);
            
            if ($maturityDate <= $today) {
                $maturityInfo['can_confirm'] = true;
                $maturityInfo['days_remaining'] = 0;
            } else {
                $maturityInfo['can_confirm'] = false;
                $maturityInfo['days_remaining'] = $interval->days;
            }
        } else {
            // If no maturity date is set, don't allow confirmation
            $maturityInfo['can_confirm'] = false;
        }
    }
    
    return $maturityInfo;
}


// Keep the existing isSenderConfirmed function unchanged
function isSenderConfirmed($boardId, $senderId, $assignmentType, $conn) {
    if ($assignmentType === 'referral_withdrawal') {
        $checkQuery = $conn->prepare("SELECT id FROM withdrawal_history WHERE board_id = ? AND sender_id = ?");
        $checkQuery->bind_param("ii", $boardId, $senderId);
    } else {
        $checkQuery = $conn->prepare("SELECT id FROM payment_history WHERE sender_user_id = ? AND transaction_reference = ?");
        $transactionRef = "BOARD_" . $boardId . "_SENDER_" . $senderId;
        $checkQuery->bind_param("is", $senderId, $transactionRef);
    }
    
    $checkQuery->execute();
    $result = $checkQuery->get_result()->fetch_assoc();
    return $result !== null;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - My Investment Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/customer_dashboard.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <div class="welcome-header">
            <h1 class="welcome-title">Welcome back, <?= htmlspecialchars(explode('@', $user['email'])[0]) ?>! 👋</h1>
            <p class="welcome-subtitle">Here's your investment overview and recent activity</p>
        </div>

        <!-- Stats Cards -->
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="card-icon package-icon">
                        <span class="material-icons">card_giftcard</span>
                    </div>
                    <div class="stats-title">Selected Package</div>
                    <div class="stats-value"><?= htmlspecialchars($packageName) ?></div>
                    <div class="stats-subtitle">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="status-badge status-<?= $selectedPackage ? ($paymentCompleted ? 'completed' : 'active') : 'none' ?>">
                                <?= $selectedPackage ? ($paymentCompleted ? 'Completed' : 'Active') : 'Not Selected' ?>
                            </span>
                            <?php if ($selectedPackage && $packageAmount > 0): ?>
                                <strong>₨<?= number_format($packageAmount) ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="card-icon receive-icon">
                        <span class="material-icons">trending_up</span>
                    </div>
                    <div class="stats-title"><?= $paymentCompleted ? 'Total Received' : 'Expected Returns' ?></div>
                    <div class="stats-value">₨<?= number_format($expectedReturns) ?></div>
                    <div class="stats-subtitle">
                        <?= $selectedPackage ? ($paymentCompleted ? 'Payment completed successfully' : '50% profit on investment') : 'Select a package to see returns' ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="card-icon outgoing-icon">
                        <span class="material-icons">trending_down</span>
                    </div>
                    <div class="stats-title">Outgoing Donations</div>
                    <div class="stats-value">₨<?= number_format($outgoingDonations) ?></div>
                    <div class="stats-subtitle">Total investments & donations</div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stats-card">
                    <div class="card-icon referral-icon">
                        <span class="material-icons">group</span>
                    </div>
                    <div class="stats-title">Referrals</div>
                    <div class="stats-value"><?= $referralCount ?></div>
                    <div class="stats-subtitle">Bonus: ₨<?= number_format($totalReferralBonus) ?></div>
                </div>
            </div>


            <!-- Referrals Section (Separate Row) -->
            <div class="row g-4 mb-4">
                <!-- 📌 Enlarged Referral Link -->
                <div class="col-lg-6 col-md-12">
                    <div class="stats-card">
                        <div class="card-icon referral-icon">
                            <span class="material-icons">link</span>
                        </div>
                        <div class="stats-title">Referral Link</div>
                        <div class="mt-2">
                            <small style="color: var(--primary-orange); word-break: break-word;">
                                <?= "http://localhost/investment/auth/register.php?ref=" . htmlspecialchars($user['id']) ?>
                            </small>
                        </div>
                    </div>
                </div>

                <!-- 📌 Enlarged Referral Withdrawals -->
                <div class="col-lg-6 col-md-12">
                    <div class="stats-card">
                        <div class="card-icon withdrawal-icon">
                            <span class="material-icons">account_balance_wallet</span>
                        </div>
                        <div class="stats-title">Referral Withdrawals</div>
                        <div class="mt-3 text-center">
                            <a href="withdrawals.php" class="btn btn-sm btn-primary">Withdraw Bonus</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ongoing Investments Section -->
        <?php if ($ongoingInvestments->num_rows > 0): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board" style="border: 2px solid #17a2b8; background: rgba(23, 162, 184, 0.05);">
                    <h2 class="board-title" style="color: #17a2b8;">
                        <span class="material-icons">trending_up</span>
                        Ongoing Investments
                    </h2>
                    <div class="alert alert-info mb-4">
                        <span class="material-icons">info</span>
                        <strong>Active Investments:</strong> You have <?= $ongoingInvestments->num_rows ?> active investment(s).
                    </div>
                    
                    <?php while($investment = $ongoingInvestments->fetch_assoc()): ?>
                    <div class="investment-card p-4 mb-3" style="background: rgba(23, 162, 184, 0.1); border-radius: 12px; border: 2px solid #17a2b8;">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="investment-info">
                                    <h5 class="mb-3" style="color: #17a2b8;">
                                        <strong><?= htmlspecialchars($investment['package_name']) ?></strong>
                                        <span class="badge badge-<?= $investment['investment_status'] === 'active' ? 'success' : 'warning' ?> ms-2">
                                            <?= ucfirst($investment['investment_status']) ?>
                                        </span>
                                    </h5>
                                    <div class="investment-details">
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Investment Amount:</strong></small>
                                            <span class="ms-2"><strong style="color: #17a2b8; font-size: 1.1em;">₨<?= number_format($investment['investment_amount']) ?></strong></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Expected Return:</strong></small>
                                            <span class="ms-2"><strong>₨<?= number_format($investment['expected_return']) ?></strong></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Start Date:</strong></small>
                                            <span class="ms-2"><?= $investment['start_date'] ? date('M d, Y', strtotime($investment['start_date'])) : 'Not started' ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Maturity Date:</strong></small>
                                            <span class="ms-2"><?= $investment['maturity_date'] ? date('M d, Y', strtotime($investment['maturity_date'])) : 'Not set' ?></span>
                                        </div>
                                        <div>
                                            <small class="text-muted"><strong>Created:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($investment['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ongoing Withdrawals Section -->
        <?php if ($ongoingWithdrawals->num_rows > 0): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board" style="border: 2px solid #fd7e14; background: rgba(253, 126, 20, 0.05);">
                    <h2 class="board-title" style="color: #fd7e14;">
                        <span class="material-icons">account_balance_wallet</span>
                        Ongoing Withdrawals
                    </h2>
                    
                    <div class="alert alert-warning mb-4">
                        <span class="material-icons">pending</span>
                        <strong>Pending Withdrawals:</strong> You have <?= $ongoingWithdrawals->num_rows ?> pending withdrawal(s).
                    </div>
                    
                    <?php while($withdrawal = $ongoingWithdrawals->fetch_assoc()): ?>
                    <div class="withdrawal-card p-4 mb-3" style="background: rgba(253, 126, 20, 0.1); border-radius: 12px; border: 2px solid #fd7e14;">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="withdrawal-info">
                                    <h5 class="mb-3" style="color: #fd7e14;">
                                        <strong>Referral Withdrawal</strong>
                                        <span class="badge badge-<?= $withdrawal['withdrawal_status'] === 'pending' ? 'warning' : 'info' ?> ms-2">
                                            <?= ucfirst($withdrawal['withdrawal_status']) ?>
                                        </span>
                                    </h5>
                                    <div class="withdrawal-details">
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Withdrawal Amount:</strong></small>
                                            <span class="ms-2"><strong style="color: #fd7e14; font-size: 1.1em;">₨<?= number_format($withdrawal['withdrawal_amount']) ?></strong></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Transaction Reference:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($withdrawal['transaction_reference'] ?? 'N/A') ?></span>
                                        </div>
                                        <?php if ($withdrawal['assigned_sender_id']): ?>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Assigned Sender ID:</strong></small>
                                            <span class="ms-2"><?= $withdrawal['assigned_sender_id'] ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Created:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($withdrawal['created_at'])) ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Last Updated:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($withdrawal['updated_at'])) ?></span>
                                        </div>
                                        <?php if ($withdrawal['processed_at']): ?>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Processed At:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($withdrawal['processed_at'])) ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($withdrawal['notes']): ?>
                                        <div>
                                            <small class="text-muted"><strong>Notes:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($withdrawal['notes']) ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-center justify-content-end">
                                <div class="status-info text-end">
                                    <div class="mb-2">
                                        <small class="text-muted">Status</small>
                                        <div class="h5 mb-0" style="color: <?= $withdrawal['withdrawal_status'] === 'completed' ? '#28a745' : ($withdrawal['withdrawal_status'] === 'pending' ? '#ffc107' : '#dc3545') ?>;">
                                            <?= ucfirst($withdrawal['withdrawal_status']) ?>
                                        </div>
                                    </div>
                                    <?php if ($withdrawal['withdrawal_status'] === 'pending'): ?>
                                    <div class="spinner-border spinner-border-sm text-warning" role="status" aria-hidden="true"></div>
                                    <small class="text-muted d-block">Processing...</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Payment Required (User as Sender) -->
        <?php if ($isSender && !empty($allSenderAssignments)): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board" style="border: 2px solid #ff6b35; background: rgba(255, 107, 53, 0.05);">
                    <h2 class="board-title" style="color: #ff6b35;">
                        <span class="material-icons">send</span>
                        Payment Required - You Need to Send Money
                    </h2>
                    
                    <div class="alert alert-warning mb-4">
                        <span class="material-icons">pending_actions</span>
                        <strong>Action Required:</strong> You have <?= count($allSenderAssignments) ?> pending payment(s) to make.
                    </div>
                    
                    <?php foreach($allSenderAssignments as $index => $assignment): ?>
                    <div class="receiver-card p-4 mb-3" style="background: rgba(255, 107, 53, 0.1); border-radius: 12px; border: 2px solid #ff6b35;">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="receiver-info">
                                    <h5 class="mb-3" style="color: #ff6b35;"><strong>Send Payment To:</strong></h5>
                                    <h6 class="mb-2"><strong><?= htmlspecialchars($assignment['receiver_info']['fullname'] ?? 'N/A') ?></strong></h6>
                                    <div class="receiver-details">
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Email:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($assignment['receiver_info']['email'] ?? 'N/A') ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Phone:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($assignment['receiver_info']['phone'] ?? 'N/A') ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Amount to Send:</strong></small>
                                            <span class="ms-2"><strong style="color: #ff6b35; font-size: 1.1em;">₨<?= number_format($assignment['board']['amount']) ?></strong></span>
                                        </div>
                                        <div>
                                            <small class="text-muted"><strong>Assignment Date:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($assignment['board']['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Withdrawal Required (User as Sender) -->
        <?php if ($isWithdrawalSender && !empty($allWithdrawalSenderAssignments)): ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board" style="border: 2px solid #6f42c1; background: rgba(111, 66, 193, 0.05);">
                    <h2 class="board-title" style="color: #6f42c1;">
                        <span class="material-icons">account_balance_wallet</span>
                        Withdrawal Payment Required - You Need to Send Money
                    </h2>
                    
                    <div class="alert alert-info mb-4">
                        <span class="material-icons">info</span>
                        <strong>Referral Withdrawal:</strong> You have <?= count($allWithdrawalSenderAssignments) ?> pending referral withdrawal payment(s) to make.
                    </div>
                    
                    <?php foreach($allWithdrawalSenderAssignments as $index => $assignment): ?>
                    <div class="receiver-card p-4 mb-3" style="background: rgba(111, 66, 193, 0.1); border-radius: 12px; border: 2px solid #6f42c1;">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="receiver-info">
                                    <h5 class="mb-3" style="color: #6f42c1;"><strong>Send Withdrawal Payment To:</strong></h5>
                                    <h6 class="mb-2"><strong><?= htmlspecialchars($assignment['receiver_info']['fullname'] ?? 'N/A') ?></strong></h6>
                                    <div class="receiver-details">
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Email:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($assignment['receiver_info']['email'] ?? 'N/A') ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Phone:</strong></small>
                                            <span class="ms-2"><?= htmlspecialchars($assignment['receiver_info']['phone'] ?? 'N/A') ?></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Amount to Send:</strong></small>
                                            <span class="ms-2"><strong style="color: #6f42c1; font-size: 1.1em;">₨<?= number_format($assignment['board']['amount']) ?></strong></span>
                                        </div>
                                        <div class="mb-2">
                                            <small class="text-muted"><strong>Board ID:</strong></small>
                                            <span class="ms-2">#<?= $assignment['board']['id'] ?></span>
                                        </div>
                                        <div>
                                            <small class="text-muted"><strong>Assignment Date:</strong></small>
                                            <span class="ms-2"><?= date('M d, Y H:i', strtotime($assignment['board']['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Referral Withdrawal Confirmation -->
        <?php if ($isReferralReceiver): 
            $senders = getSenderInfo($referralDetails, $conn);
        ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board" style="border: 2px solid #28a745; background: rgba(40, 167, 69, 0.05);">
                    <h2 class="board-title" style="color: #28a745;">
                        <span class="material-icons">account_balance_wallet</span>
                        Referral Withdrawal Confirmation Required
                    </h2>
                    
                    <div class="alert alert-success mb-4">
                        <span class="material-icons">payments</span>
                        <strong>Great news!</strong> You have a referral withdrawal of <strong>₨<?= number_format($referralDetails['amount']) ?></strong> waiting for confirmation.
                    </div>
                    
                    <?php foreach ($senders as $index => $sender): 
                        $isConfirmed = isSenderConfirmed($referralDetails['id'], $sender['id'] ?? $sender['sender_index'], 'referral_withdrawal', $conn);
                    ?>
                        <div class="sender-card mb-3 p-3" style="background: rgba(40, 167, 69, 0.1); border-radius: 8px; border-left: 4px solid #28a745;">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="sender-info">
                                        <h6 class="mb-2"><strong><?= htmlspecialchars($sender['fullname']) ?></strong></h6>
                                        <div class="sender-details">
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Email:</strong></small>
                                                <small><?= htmlspecialchars($sender['email']) ?></small>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Phone:</strong></small>
                                                <small><?= htmlspecialchars($sender['phone']) ?></small>
                                            </div>
                                            <div>
                                                <small class="text-muted"><strong>Amount:</strong></small>
                                                <small><strong>₨<?= number_format($sender['amount']) ?></strong></small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-center justify-content-end">
                                    <?php if ($isConfirmed): ?>
                                        <button class="btn btn-success btn-sm" disabled>
                                            <span class="material-icons" style="font-size: 16px;">check</span>
                                            Confirmed
                                        </button>
                                    <?php else: ?>
                                        <button id="confirm-referral-btn-<?= $index ?>" class="btn btn-success btn-sm" onclick="confirmWithdrawal(<?= $referralDetails['id'] ?>, <?= $sender['id'] ?? $index ?>, <?= $index ?>)">
                                            <span class="material-icons" style="font-size: 16px;">check_circle</span>
                                            Confirm from this sender
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div id="referral-error-<?= $index ?>" class="alert alert-danger mt-2" style="display: none;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

         <?php if ($isReceiver): 
            $senders = getSenderInfo($receiverDetails, $conn);
        ?>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="matching-board">
                    <h2 class="board-title">
                        <span class="material-icons">payment</span>
                        Payment Confirmation Required
                    </h2>
                    
                    <div class="alert alert-info mb-4">
                        <span class="material-icons">info</span>
                        <strong>Payment pending:</strong> Please confirm once you receive the payment from each sender below.
                    </div>
                    
                    <?php foreach ($senders as $index => $sender): 
                        $isConfirmed = isSenderConfirmed($receiverDetails['id'], $sender['id'] ?? $sender['sender_index'], 'investment', $conn);
                        
                        // Check maturity date for this sender based on boards table
                        $maturityInfo = getSenderMaturityInfo($sender['id'], $receiverDetails, $conn);
                        $canConfirm = $maturityInfo['can_confirm'];
                    ?>
                        <div class="sender-card mb-3 p-3" style="background: rgba(0, 123, 255, 0.05); border-radius: 8px; border-left: 4px solid #007bff;">
                            <div class="row">
                                <div class="col-md-8">
                                    <div class="sender-info">
                                        <h6 class="mb-2"><strong><?= htmlspecialchars($sender['fullname']) ?></strong></h6>
                                        <div class="sender-details">
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Email:</strong></small>
                                                <small><?= htmlspecialchars($sender['email']) ?></small>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Phone:</strong></small>
                                                <small><?= htmlspecialchars($sender['phone']) ?></small>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Amount:</strong></small>
                                                <small><strong>₨<?= number_format($sender['amount']) ?></strong></small>
                                            </div>
                                            <?php if ($maturityInfo['start_date']): ?>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Investment Start:</strong></small>
                                                <small><?= date('M d, Y', strtotime($maturityInfo['start_date'])) ?></small>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($maturityInfo['maturity_date']): ?>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Maturity Date:</strong></small>
                                                <small><?= date('M d, Y', strtotime($maturityInfo['maturity_date'])) ?></small>
                                            </div>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Status:</strong></small>
                                                <small class="<?= $canConfirm ? 'text-success' : 'text-warning' ?>">
                                                    <?= $canConfirm ? 'Ready for confirmation' : 'Waiting for maturity' ?>
                                                </small>
                                            </div>
                                            <?php if (!$canConfirm && $maturityInfo['days_remaining'] > 0): ?>
                                            <div class="mb-1">
                                                <small class="text-muted"><strong>Days remaining:</strong></small>
                                                <small class="text-warning"><?= $maturityInfo['days_remaining'] ?> days</small>
                                            </div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <div class="mb-1">
                                                <small class="text-warning"><em>Maturity date not set</em></small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 d-flex align-items-center justify-content-end">
                                    <?php if ($isConfirmed): ?>
                                        <button class="btn btn-success btn-sm" disabled>
                                            <span class="material-icons" style="font-size: 16px;">check</span>
                                            Confirmed
                                        </button>
                                    <?php elseif (!$canConfirm && $maturityInfo['maturity_date']): ?>
                                        <button class="btn btn-warning btn-sm" disabled title="Investment has not reached maturity date yet">
                                            <span class="material-icons" style="font-size: 16px;">schedule</span>
                                            Not Matured Yet
                                        </button>
                                    <?php elseif (!$maturityInfo['maturity_date']): ?>
                                        <button class="btn btn-secondary btn-sm" disabled title="Maturity date not set">
                                            <span class="material-icons" style="font-size: 16px;">help</span>
                                            Date Not Set
                                        </button>
                                    <?php else: ?>
                                        <button id="confirm-payment-btn-<?= $index ?>" class="btn btn-primary btn-sm" onclick="confirmPayment(<?= $receiverDetails['id'] ?>, <?= $sender['id'] ?? $index ?>, <?= $index ?>)">
                                            <span class="material-icons" style="font-size: 16px;">check_circle</span>
                                            Confirm from this sender
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!$canConfirm && $maturityInfo['maturity_date'] && !$isConfirmed): ?>
                            <div class="alert alert-warning mt-2">
                                <span class="material-icons" style="font-size: 16px;">info</span>
                                <small>This investment will be ready for confirmation on <strong><?= date('M d, Y', strtotime($maturityInfo['maturity_date'])) ?></strong></small>
                            </div>
                            <?php elseif (!$maturityInfo['maturity_date'] && !$isConfirmed): ?>
                            <div class="alert alert-info mt-2">
                                <span class="material-icons" style="font-size: 16px;">info</span>
                                <small>Maturity date has not been set for this investment. Please contact administrator.</small>
                            </div>
                            <?php endif; ?>
                            
                            <div id="payment-error-<?= $index ?>" class="alert alert-danger mt-2" style="display: none;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    function confirmPayment(boardId, senderId, senderIndex) {
        const button = document.getElementById('confirm-payment-btn-' + senderIndex);
        const errorDiv = document.getElementById('payment-error-' + senderIndex);
        
        button.innerHTML = '<span class="material-icons" style="font-size: 16px;">hourglass_empty</span> Processing...';
        button.disabled = true;
        errorDiv.style.display = 'none';
        
        fetch('confirm_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'confirm_payment', 
                board_id: boardId,
                sender_id: senderId,
                sender_index: senderIndex 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check</span> Confirmed!';
                button.className = 'btn btn-success btn-sm';
                button.disabled = true;
                
                // Check if all senders are confirmed
                if (data.all_confirmed) {
                    setTimeout(() => location.reload(), 2000);
                }
            } else {
                errorDiv.textContent = data.message;
                errorDiv.style.display = 'block';
                button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check_circle</span> Confirm from this sender';
                button.disabled = false;
            }
        })
        .catch(error => {
            errorDiv.textContent = 'Network error. Please try again.';
            errorDiv.style.display = 'block';
            button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check_circle</span> Confirm from this sender';
            button.disabled = false;
        });
    }

    function confirmWithdrawal(boardId, senderId, senderIndex) {
        const button = document.getElementById('confirm-referral-btn-' + senderIndex);
        const errorDiv = document.getElementById('referral-error-' + senderIndex);
        
        button.innerHTML = '<span class="material-icons" style="font-size: 16px;">hourglass_empty</span> Processing...';
        button.disabled = true;
        errorDiv.style.display = 'none';
        
        fetch('confirm_withdrawal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                action: 'confirm_referral_withdrawal', 
                board_id: boardId,
                sender_id: senderId,
                sender_index: senderIndex 
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check</span> Confirmed!';
                button.className = 'btn btn-success btn-sm';
                button.disabled = true;
                
                // Check if all senders are confirmed
                if (data.all_confirmed) {
                    setTimeout(() => location.reload(), 2000);
                }
            } else {
                errorDiv.textContent = data.message;
                errorDiv.style.display = 'block';
                button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check_circle</span> Confirm from this sender';
                button.disabled = false;
            }
        })
        .catch(error => {
            errorDiv.textContent = 'Network error. Please try again.';
            errorDiv.style.display = 'block';
            button.innerHTML = '<span class="material-icons" style="font-size: 16px;">check_circle</span> Confirm from this sender';
            button.disabled = false;
        });
    }
    </script>

    <style>
    .badge-success {
        background-color: #28a745 !important;
        color: white !important;
    }
    
    .badge-warning {
        background-color: #ffc107 !important;
        color: #212529 !important;
    }
    
    .badge-info {
        background-color: #17a2b8 !important;
        color: white !important;
    }
    
    .badge-danger {
        background-color: #dc3545 !important;
        color: white !important;
    }
    
    .progress-bar.bg-info {
        background-color: #17a2b8 !important;
    }
    
    .investment-card, .withdrawal-card {
        transition: all 0.3s ease;
    }
    
    .investment-card:hover, .withdrawal-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.1);
    }
    
    .spinner-border-sm {
        width: 1rem;
        height: 1rem;
    }
    </style>
</body>
</html>