<?php
include '../includes/db.php';
include '../includes/session.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Check if database connection is working
if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

// Handle withdrawal form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['withdraw'])) {
    $withdrawal_amount = floatval($_POST['withdrawal_amount']);
    $minimum_withdrawal = 20000;
    
    // Basic validation
    if ($withdrawal_amount <= 0) {
        $message = "Please enter a valid withdrawal amount.";
        $message_type = "error";
    } elseif ($withdrawal_amount < $minimum_withdrawal) {
        $message = "Minimum withdrawal amount is Rs" . number_format($minimum_withdrawal, 2);
        $message_type = "error";
    } else {
        // Start transaction
        mysqli_autocommit($conn, FALSE);
        
        try {
            // Get current user balance WITH LOCK to prevent race conditions
            $balance_query = "SELECT referral_total, COALESCE(total_withdrawn, 0) as total_withdrawn FROM users WHERE id = ? FOR UPDATE";
            $stmt = mysqli_prepare($conn, $balance_query);
            
            if (!$stmt) {
                throw new Exception("System error occurred. Please try again.");
            }
            
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            $balance_result = mysqli_stmt_get_result($stmt);
            $user_data = mysqli_fetch_assoc($balance_result);
            mysqli_stmt_close($stmt);
            
            if (!$user_data) {
                throw new Exception("User account not found!");
            }
            
            $current_balance = floatval($user_data['referral_total']);
            $current_withdrawn = floatval($user_data['total_withdrawn']);
            
            if ($withdrawal_amount > $current_balance) {
                throw new Exception("Insufficient balance. Available: Rs" . number_format($current_balance, 2));
            }
            
            // Generate transaction reference
            $transaction_ref = 'WTH-' . date('Ymd') . '-' . str_pad($user_id, 6, '0', STR_PAD_LEFT) . '-' . rand(1000, 9999);
            
            // Insert into referral_withdrawals
            $insert_sql = "INSERT INTO referral_withdrawals 
                (user_id, withdrawal_amount, withdrawal_status, transaction_reference, notes, created_at, processed_at) 
                VALUES (?, ?, 'completed', ?, 'Referral earnings withdrawal', NOW(), NOW())";
            
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            if (!$insert_stmt) {
                throw new Exception("System error occurred. Please try again.");
            }
            
            mysqli_stmt_bind_param($insert_stmt, "ids", $user_id, $withdrawal_amount, $transaction_ref);
            
            if (!mysqli_stmt_execute($insert_stmt)) {
                throw new Exception("Transaction failed. Please try again.");
            }
            
            $withdrawal_id = mysqli_insert_id($conn);
            mysqli_stmt_close($insert_stmt);
            
            // Update users table - deduct from referral_total and add to total_withdrawn
            $new_balance = $current_balance - $withdrawal_amount;
            $new_total_withdrawn = $current_withdrawn + $withdrawal_amount;
            
            $update_sql = "UPDATE users SET referral_total = ?, total_withdrawn = ? WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            
            if (!$update_stmt) {
                throw new Exception("System error occurred. Please try again.");
            }
            
            mysqli_stmt_bind_param($update_stmt, "ddi", $new_balance, $new_total_withdrawn, $user_id);
            
            if (!mysqli_stmt_execute($update_stmt)) {
                throw new Exception("Transaction failed. Please try again.");
            }
            
            if (mysqli_stmt_affected_rows($update_stmt) === 0) {
                throw new Exception("User account error. Please contact support.");
            }
            
            mysqli_stmt_close($update_stmt);
            
            // Commit transaction
            if (!mysqli_commit($conn)) {
                throw new Exception("Transaction failed. Please try again.");
            }
            
            $message = "Withdrawal successful! Rs" . number_format($withdrawal_amount, 2) . " has been processed. Transaction ID: " . $transaction_ref;
            $message_type = "success";
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $message = "Withdrawal failed: " . $e->getMessage();
            $message_type = "error";
            
            // Log the error for debugging (server-side only)
            error_log("Withdrawal Error for User $user_id: " . $e->getMessage());
            
        } finally {
            // Re-enable autocommit
            mysqli_autocommit($conn, TRUE);
        }
    }
}

// Initialize default values
$referral_total = 0;
$total_referrals = 0;
$total_withdrawn = 0;
$user = null;

// Fetch user data for display (fresh data after potential withdrawal)
$final_query = "SELECT 
    COALESCE(referral_total, 0) as referral_total, 
    COALESCE(total_referrals, 0) as total_referrals, 
    COALESCE(total_withdrawn, 0) as total_withdrawn 
    FROM users WHERE id = ?";
    
$stmt = mysqli_prepare($conn, $final_query);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        
        if ($user) {
            $referral_total = floatval($user['referral_total']);
            $total_referrals = intval($user['total_referrals']);
            $total_withdrawn = floatval($user['total_withdrawn']);
        }
    }
    mysqli_stmt_close($stmt);
}

// Fetch recent withdrawals
$withdrawals = null;
$withdrawal_query = "SELECT withdrawal_amount, transaction_reference, created_at, withdrawal_status FROM referral_withdrawals 
                    WHERE user_id = ? 
                    ORDER BY created_at DESC LIMIT 10";
$stmt = mysqli_prepare($conn, $withdrawal_query);

if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    $withdrawals = mysqli_stmt_get_result($stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Withdrawals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-orange: #ff6b35;
            --light-orange: #ff8c61;
            --dark-orange: #e55a2b;
        }
        
        .btn-primary {
            background-color: var(--primary-orange);
            border-color: var(--primary-orange);
        }
        
        .btn-primary:hover {
            background-color: var(--dark-orange);
            border-color: var(--dark-orange);
        }
        
        .text-primary {
            color: var(--primary-orange) !important;
        }
        
        .bg-primary {
            background-color: var(--primary-orange) !important;
        }
        
        .border-primary {
            border-color: var(--primary-orange) !important;
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
            color: white;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .badge-success {
            background-color: #28a745 !important;
        }
        
        .badge-warning {
            background-color: #ffc107 !important;
        }
        
        .badge-danger {
            background-color: #dc3545 !important;
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'sidebar.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-md-10 offset-md-2 main-content">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center py-3 mb-4 border-bottom">
                    <div>
                        <h1 class="h3 mb-0 text-primary"><i class="fas fa-coins me-2"></i>Referral Withdrawals</h1>
                        <p class="text-muted mb-0">Manage your referral earnings and withdraw your funds</p>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                    <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
                        <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i>
                        <?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <div class="mb-2">
                                <i class="fas fa-wallet fa-2x"></i>
                            </div>
                            <h5>Available Balance</h5>
                            <h3 class="mb-1">Rs<?= number_format($referral_total, 2) ?></h3>
                            <small>Ready for withdrawal</small>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <div class="mb-2">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                            <h5>Total Referrals</h5>
                            <h3 class="mb-1"><?= number_format($total_referrals, 0) ?></h3>
                            <small>People referred</small>
                        </div>
                    </div>
                    
                    <div class="col-md-4 mb-3">
                        <div class="stat-card">
                            <div class="mb-2">
                                <i class="fas fa-download fa-2x"></i>
                            </div>
                            <h5>Total Withdrawn</h5>
                            <h3 class="mb-1">Rs<?= number_format($total_withdrawn, 2) ?></h3>
                            <small>All time withdrawals</small>
                        </div>
                    </div>
                </div>

                <!-- Withdrawal Form -->
                <div class="row">
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Withdraw Funds</h5>
                            </div>
                            <div class="card-body">
                                <div class="text-center mb-4">
                                    <h6 class="text-muted">Available for Withdrawal</h6>
                                    <h3 class="text-primary">Rs<?= number_format($referral_total, 2) ?></h3>
                                    <small class="text-muted">Minimum withdrawal amount: Rs20,000</small>
                                </div>
                                
                                <form method="POST" action="" id="withdrawalForm">
                                    <div class="mb-3">
                                        <label for="withdrawal_amount" class="form-label">Withdrawal Amount</label>
                                        <div class="input-group">
                                            <span class="input-group-text">Rs</span>
                                            <input type="number" 
                                                   id="withdrawal_amount" 
                                                   name="withdrawal_amount" 
                                                   class="form-control"
                                                   step="0.01" 
                                                   min="20000" 
                                                   max="<?= $referral_total ?>"
                                                   placeholder="Enter amount (Min: Rs20,000)"
                                                   required>
                                        </div>
                                    </div>
                                    
                                    <button type="submit" 
                                            name="withdraw" 
                                            value="1"
                                            class="btn btn-primary w-100"
                                            id="withdrawBtn"
                                            disabled>
                                        <i class="fas fa-paper-plane me-2"></i>
                                        Minimum Rs20,000 Required
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Transaction History -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($withdrawals && mysqli_num_rows($withdrawals) > 0): ?>
                                    <div class="list-group list-group-flush">
                                        <?php while ($withdrawal = mysqli_fetch_assoc($withdrawals)): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <h6 class="mb-1">Rs<?= number_format($withdrawal['withdrawal_amount'], 2) ?></h6>
                                                    <small class="text-muted">Ref: <?= htmlspecialchars($withdrawal['transaction_reference']) ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <small class="text-muted d-block"><?= date('M d, Y', strtotime($withdrawal['created_at'])) ?></small>
                                                    <span class="badge <?= $withdrawal['withdrawal_status'] === 'completed' ? 'badge-success' : ($withdrawal['withdrawal_status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                                                        <?= strtoupper($withdrawal['withdrawal_status']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                        <h6>No withdrawals yet</h6>
                                        <p class="text-muted">Your withdrawal history will appear here once you make your first withdrawal.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const withdrawalInput = document.getElementById('withdrawal_amount');
            const withdrawBtn = document.getElementById('withdrawBtn');
            const availableBalance = <?= $referral_total ?>;
            
            function updateButtonState() {
                const amount = parseFloat(withdrawalInput.value) || 0;
                
                if (amount < 20000) {
                    withdrawBtn.disabled = true;
                    withdrawBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Minimum Rs20,000 Required';
                } else if (amount > availableBalance) {
                    withdrawBtn.disabled = true;
                    withdrawBtn.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i>Insufficient Balance';
                } else {
                    withdrawBtn.disabled = false;
                    withdrawBtn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Process Withdrawal';
                }
            }
            
            // Initial state
            updateButtonState();
            
            // Update on input change
            withdrawalInput.addEventListener('input', updateButtonState);
            withdrawalInput.addEventListener('keyup', updateButtonState);
        });
    </script>
</body>
</html>