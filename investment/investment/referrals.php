<?php
include '../includes/db.php';
include '../includes/session.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];

$success_message = $error_message = '';

// Get current user info
$user_query = "SELECT fullname, referral_total, referral_earnings, total_referrals FROM users WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$user_info = $stmt->get_result()->fetch_assoc();
$stmt->close();

$available_balance = $user_info['referral_total'] ?? 0;

// GET FIRST-TIME USERS WHO WERE REFERRED BY CURRENT USER
// These are users who have current_user_id as their referral_id and have NOT completed any payments yet
$first_time_pending_query = "
    SELECT u.id, u.fullname, u.email, u.phone, u.created_at, u.referral_id,
           ui.id as investment_id, ui.investment_amount, ui.package_name, 
           ui.investment_status, ui.start_date, ui.maturity_date,
           ROUND(ui.investment_amount * 0.10, 2) as expected_bonus
    FROM users u
    LEFT JOIN user_investments ui ON u.id = ui.user_id AND ui.investment_status = 'pending'
    WHERE u.referral_id = ? 
    AND NOT EXISTS (
        SELECT 1 FROM payment_history ph 
        WHERE ph.user_id = u.id AND ph.payment_status = 'completed'
    )
    AND ui.id IS NOT NULL
    ORDER BY u.created_at DESC
";

$stmt = $conn->prepare($first_time_pending_query);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$first_time_pending = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// GET FIRST-TIME USERS WHO HAVE COMPLETED THEIR FIRST PAYMENT (and earned bonus)
$first_time_completed_query = "
    SELECT u.id, u.fullname, u.email, u.phone, u.created_at, u.referral_id,
           ph.amount, ph.original_investment, ph.profit_amount, ph.package_name,
           ph.payment_date, ph.transaction_reference,
           ROUND(ph.original_investment * 0.10, 2) as bonus_earned,
           'completed' as bonus_status
    FROM users u
    INNER JOIN payment_history ph ON u.id = ph.user_id AND ph.payment_status = 'completed'
    WHERE u.referral_id = ? 
    AND ph.id = (
        SELECT MIN(ph2.id) FROM payment_history ph2 
        WHERE ph2.user_id = u.id AND ph2.payment_status = 'completed'
    )
    ORDER BY ph.payment_date DESC
    LIMIT 50
";

$stmt = $conn->prepare($first_time_completed_query);
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$first_time_completed = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count total first-time referrals (both pending and completed)
$total_first_time_referrals = count($first_time_pending) + count($first_time_completed);

// Calculate total bonuses earned from first-time referrals (10% of each investment)
$total_bonuses_earned = 0;
foreach ($first_time_completed as $user) {
    $total_bonuses_earned += $user['bonus_earned'];
}

// Calculate total expected bonuses from pending referrals
$total_expected_bonuses = 0;
foreach ($first_time_pending as $user) {
    $total_expected_bonuses += $user['expected_bonus'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My First-Time Referrals</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --orange-primary: #FF6B35;
            --orange-light: #FFB085;
            --orange-dark: #E55B2B;
            --orange-bg: #FFF4F0;
            --shadow: 0 4px 15px rgba(255, 107, 53, 0.1);
            --shadow-hover: 0 8px 25px rgba(255, 107, 53, 0.15);
        }

        body {
            background: linear-gradient(135deg, #FFF4F0 0%, #FFFFFF 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-hover);
        }

        .stats-card {
            background: linear-gradient(135deg, var(--orange-primary) 0%, var(--orange-dark) 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .stats-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            transform: rotate(45deg);
        }

        .stats-card-white {
            background: white;
            color: var(--orange-primary);
            border: 2px solid var(--orange-light);
        }

        .page-header {
            background: linear-gradient(135deg, var(--orange-primary) 0%, var(--orange-dark) 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            border-radius: 0 0 24px 24px;
        }

        .user-card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--orange-primary);
            transition: all 0.3s ease;
        }

        .user-card:hover {
            transform: translateX(5px);
            box-shadow: 0 4px 20px rgba(255, 107, 53, 0.1);
        }

        .pending-card {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, #fff9e6 0%, white 100%);
        }

        .completed-card {
            border-left-color: #28a745;
            background: linear-gradient(135deg, #f0fff4 0%, white 100%);
        }

        .badge-orange {
            background: var(--orange-primary);
            color: white;
        }

        .badge-orange-light {
            background: var(--orange-light);
            color: var(--orange-dark);
        }

        .btn-orange {
            background: var(--orange-primary);
            border-color: var(--orange-primary);
            color: white;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-orange:hover {
            background: var(--orange-dark);
            border-color: var(--orange-dark);
            color: white;
            transform: translateY(-2px);
        }

        .section-title {
            color: var(--orange-primary);
            font-weight: 700;
            margin-bottom: 1.5rem;
            position: relative;
            padding-left: 20px;
        }

        .section-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 30px;
            background: var(--orange-primary);
            border-radius: 2px;
        }

        .info-card {
            background: linear-gradient(135deg, var(--orange-bg) 0%, white 100%);
            border: 1px solid var(--orange-light);
        }

        .icon-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: var(--orange-primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-right: 1rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }

        .animate-fade-in {
            animation: fadeIn 0.6s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .pulse-animation {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        @media (max-width: 768px) {
            .user-card {
                padding: 1rem;
            }
            
            .stats-card .card-body {
                padding: 1rem;
            }
        }
    </style>
</head>
<body>

    <?php include 'sidebar.php'; ?>

    <main class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h1 class="mb-2">
                            <i class="fas fa-user-plus me-3"></i>
                            Referrals
                        </h1>
                        <p class="mb-0 opacity-75">
                            <i class="fas fa-gift me-2"></i>
                            Earn 10% bonus from each first-time referral payment
                        </p>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="d-flex align-items-center justify-content-md-end">
                            <div class="icon-circle me-3">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div>
                                <div class="h4 mb-0"><?php echo number_format($total_first_time_referrals); ?></div>
                                <small class="opacity-75">Total Referrals</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid py-4">
            
            <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show animate-fade-in" style="border-radius: 12px; border: none; box-shadow: var(--shadow);">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate-fade-in" style="border-radius: 12px; border: none; box-shadow: var(--shadow);">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row mb-5">
                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card stats-card animate-fade-in">
                        <div class="card-body position-relative">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1 opacity-75">Pending First-Timers</h6>
                                    <h2 class="mb-0"><?php echo count($first_time_pending); ?></h2>
                                    <small class="opacity-75">
                                        <i class="fas fa-clock me-1"></i>
                                        Awaiting payment
                                    </small>
                                </div>
                                <div class="icon-circle" style="background: rgba(255,255,255,0.2);">
                                    <i class="fas fa-hourglass-half"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card stats-card-white animate-fade-in">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1 text-muted">Completed First-Timers</h6>
                                    <h2 class="mb-0"><?php echo count($first_time_completed); ?></h2>
                                    <small class="text-success">
                                        <i class="fas fa-check me-1"></i>
                                        Earned bonuses
                                    </small>
                                </div>
                                <div class="icon-circle">
                                    <i class="fas fa-trophy"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card stats-card animate-fade-in">
                        <div class="card-body position-relative">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1 opacity-75">Expected Bonuses</h6>
                                    <h2 class="mb-0">Rs<?php echo number_format($total_expected_bonuses, 0); ?></h2>
                                    <small class="opacity-75">
                                        <i class="fas fa-gift me-1"></i>
                                        From pending
                                    </small>
                                </div>
                                <div class="icon-circle" style="background: rgba(255,255,255,0.2);">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-6 mb-4">
                    <div class="card stats-card-white animate-fade-in pulse-animation">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="flex-grow-1">
                                    <h6 class="card-title mb-1 text-muted">Available Balance</h6>
                                    <h2 class="mb-0">Rs<?php echo number_format($available_balance, 0); ?></h2>
                                    <small class="text-success">
                                        <i class="fas fa-wallet me-1"></i>
                                        Ready to use
                                    </small>
                                </div>
                                <div class="icon-circle">
                                    <i class="fas fa-piggy-bank"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending First-Time Referrals -->
            <div class="row mb-5">
                <div class="col-12">
                    <h3 class="section-title">
                        <i class="fas fa-clock me-2"></i>
                        Pending First-Time Referrals
                        <span class="badge badge-orange ms-2"><?php echo count($first_time_pending); ?></span>
                    </h3>
                    
                    <?php if (empty($first_time_pending)): ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="empty-state">
                                <i class="fas fa-users text-muted"></i>
                                <h4>No Pending Referrals</h4>
                                <p class="text-muted mb-4">Share your referral link to get new users!</p>
                                <button class="btn btn-orange">
                                    <i class="fas fa-share me-2"></i>
                                    Share Referral Link
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($first_time_pending as $user): ?>
                        <div class="col-lg-6 col-xl-4 mb-4">
                            <div class="card user-card pending-card animate-fade-in">
                                <div class="d-flex align-items-start">
                                    <div class="icon-circle me-3" style="background: #ffc107; width: 40px; height: 40px; font-size: 1rem;">
                                        <i class="fas fa-user-clock"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($user['fullname']); ?></h6>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-star me-1"></i>
                                                FIRST-TIMER
                                            </span>
                                        </div>
                                        
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </p>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Package</small>
                                                <span class="badge badge-orange-light">
                                                    <?php echo htmlspecialchars($user['package_name']); ?>
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Investment</small>
                                                <strong class="text-success">Rs<?php echo number_format($user['investment_amount'], 0); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <small class="text-muted">Your Expected Bonus</small>
                                                <div class="h6 mb-0 text-warning">
                                                    <i class="fas fa-gift me-1"></i>
                                                    Rs<?php echo number_format($user['expected_bonus'], 0); ?>
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo date('M j', strtotime($user['created_at'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($total_expected_bonuses > 0): ?>
                    <div class="card" style="background: linear-gradient(135deg, #fff9e6 0%, white 100%); border: 2px solid #ffc107;">
                        <div class="card-body text-center">
                            <h5 class="mb-0">
                                <i class="fas fa-calculator me-2 text-warning"></i>
                                Total Expected Bonuses: 
                                <strong class="text-warning">Rs<?php echo number_format($total_expected_bonuses, 0); ?></strong>
                            </h5>
                            <small class="text-muted">You'll earn this when all pending referrals complete their payments</small>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Completed First-Time Referrals -->
            <div class="row mb-5">
                <div class="col-12">
                    <h3 class="section-title">
                        <i class="fas fa-trophy me-2"></i>
                        Completed First-Time Referrals
                        <span class="badge bg-success ms-2"><?php echo count($first_time_completed); ?></span>
                    </h3>
                    
                    <?php if (empty($first_time_completed)): ?>
                    <div class="card">
                        <div class="card-body">
                            <div class="empty-state">
                                <i class="fas fa-trophy text-muted"></i>
                                <h4>No Completed Referrals Yet</h4>
                                <p class="text-muted">Your bonuses will appear here when referrals complete their payments.</p>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="row">
                        <?php foreach ($first_time_completed as $user): ?>
                        <div class="col-lg-6 col-xl-4 mb-4">
                            <div class="card user-card completed-card animate-fade-in">
                                <div class="d-flex align-items-start">
                                    <div class="icon-circle me-3" style="background: #28a745; width: 40px; height: 40px; font-size: 1rem;">
                                        <i class="fas fa-check"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($user['fullname']); ?></h6>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check-circle me-1"></i>
                                                COMPLETED
                                            </span>
                                        </div>
                                        
                                        <p class="text-muted small mb-2">
                                            <i class="fas fa-envelope me-1"></i>
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </p>
                                        
                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Package</small>
                                                <span class="badge badge-orange-light">
                                                    <?php echo htmlspecialchars($user['package_name']); ?>
                                                </span>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Investment</small>
                                                <strong class="text-success">Rs<?php echo number_format($user['original_investment'], 0); ?></strong>
                                            </div>
                                        </div>
                                        
                                        <div class="d-flex align-items-center justify-content-between">
                                            <div>
                                                <small class="text-muted">Bonus Earned</small>
                                                <div class="h6 mb-0 text-success">
                                                    <i class="fas fa-wallet me-1"></i>
                                                    Rs<?php echo number_format($user['bonus_earned'], 0); ?>
                                                </div>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo date('M j', strtotime($user['payment_date'])); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Summary Card -->
                    <div class="card" style="background: linear-gradient(135deg, #f0fff4 0%, white 100%); border: 2px solid #28a745;">
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-4">
                                    <div class="icon-circle mx-auto mb-2" style="background: #28a745;">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4 class="mb-0 text-success"><?php echo count($first_time_completed); ?></h4>
                                    <small class="text-muted">Completed First-Timers</small>
                                </div>
                                <div class="col-md-4">
                                    <div class="icon-circle mx-auto mb-2" style="background: var(--orange-primary);">
                                        <i class="fas fa-coins"></i>
                                    </div>
                                    <h4 class="mb-0" style="color: var(--orange-primary);">Rs<?php echo number_format($total_bonuses_earned, 0); ?></h4>
                                    <small class="text-muted">Total Bonuses Earned</small>
                                </div>
                                <div class="col-md-4">
                                    <div class="icon-circle mx-auto mb-2" style="background: #17a2b8;">
                                        <i class="fas fa-piggy-bank"></i>
                                    </div>
                                    <h4 class="mb-0 text-info">Rs<?php echo number_format($available_balance, 0); ?></h4>
                                    <small class="text-muted">Available Balance</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- How It Works -->
            <div class="row">
                <div class="col-12">
                    <div class="card info-card">
                        <div class="card-header" style="background: var(--orange-primary); color: white; border: none;">
                            <h5 class="mb-0">
                                <i class="fas fa-lightbulb me-2"></i>
                                How First-Time Referral Bonuses Work
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 text-center mb-4">
                                    <div class="icon-circle mx-auto mb-3">
                                        <i class="fas fa-share"></i>
                                    </div>
                                    <h6 style="color: var(--orange-primary);">Share Your Link</h6>
                                    <p class="small text-muted">Invite friends using your unique referral link</p>
                                </div>
                                <div class="col-md-3 text-center mb-4">
                                    <div class="icon-circle mx-auto mb-3">
                                        <i class="fas fa-user-plus"></i>
                                    </div>
                                    <h6 style="color: var(--orange-primary);">They Join</h6>
                                    <p class="small text-muted">New users sign up through your link</p>
                                </div>
                                <div class="col-md-3 text-center mb-4">
                                    <div class="icon-circle mx-auto mb-3">
                                        <i class="fas fa-credit-card"></i>
                                    </div>
                                    <h6 style="color: var(--orange-primary);">They Invest</h6>
                                    <p class="small text-muted">They choose a package and make their first payment</p>
                                </div>
                                <div class="col-md-3 text-center mb-4">
                                    <div class="icon-circle mx-auto mb-3">
                                        <i class="fas fa-gift"></i>
                                    </div>
                                    <h6 style="color: var(--orange-primary);">You Earn 10%</h6>
                                    <p class="small text-muted">Automatic bonus added to your balance</p>
                                </div>
                            </div>
                            
                            <hr style="border-color: var(--orange-light);">
                            
                            <div class="row">
                                <div class="col-md-4 text-center mb-3">
                                    <div class="p-3 rounded" style="background: var(--orange-bg); border: 2px solid var(--orange-light);">
                                        <strong>Rs5,000 Investment</strong><br>
                                        <span class="text-success">Your Bonus: Rs500</span>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center mb-3">
                                    <div class="p-3 rounded" style="background: var(--orange-bg); border: 2px solid var(--orange-light);">
                                        <strong>Rs10,000 Investment</strong><br>
                                        <span class="text-success">Your Bonus: Rs1,000</span>
                                    </div>
                                </div>
                                <div class="col-md-4 text-center mb-3">
                                    <div class="p-3 rounded" style="background: var(--orange-bg); border: 2px solid var(--orange-light);">
                                        <strong>Rs20,000 Investment</strong><br>
                                        <span class="text-success">Your Bonus: Rs2,000</span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning mb-0" style="background: linear-gradient(135deg, #fff3cd 0%, white 100%); border: 2px solid #ffc107; border-radius: 12px;">
                                <div class="d-flex align-items-center">
                                    <div class="icon-circle me-3" style="background: #ffc107; width: 40px; height: 40px; font-size: 1rem;">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div>
                                        <strong>Important:</strong> The 10% bonus is awarded <strong>only once per user</strong> when they complete their very first payment. Subsequent investments from the same user do not generate additional bonuses.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                if (alert.querySelector('.btn-close')) {
                    new bootstrap.Alert(alert).close();
                }
            });
        }, 5000);

        // Auto-refresh page every 60 seconds to check for updates
        setTimeout(function() {
            window.location.reload();
        }, 60000);

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Add loading animation to cards
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.animate-fade-in');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Add hover effects to user cards
        document.querySelectorAll('.user-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateX(8px) translateY(-3px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateX(0) translateY(0)';
            });
        });

        // Add click animation to stats cards
        document.querySelectorAll('.stats-card, .stats-card-white').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'scale(0.95)';
                setTimeout(() => {
                    this.style.transform = 'scale(1)';
                }, 150);
            });
        });

        // Smooth scroll for section navigation
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Add progressive loading effect
        window.addEventListener('load', function() {
            document.body.classList.add('loaded');
        });

        // Toast notification for interactions
        function showToast(message, type = 'success') {
            const toastContainer = document.createElement('div');
            toastContainer.className = `toast-container position-fixed top-0 end-0 p-3`;
            toastContainer.style.zIndex = '9999';
            
            const toast = document.createElement('div');
            toast.className = `toast align-items-center text-bg-${type} border-0`;
            toast.setAttribute('role', 'alert');
            toast.innerHTML = `
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            document.body.appendChild(toastContainer);
            
            const bsToast = new bootstrap.Toast(toast);
            bsToast.show();
            
            toast.addEventListener('hidden.bs.toast', function() {
                toastContainer.remove();
            });
        }

        // Add click handlers for interactive elements
        document.querySelectorAll('.btn-orange').forEach(btn => {
            btn.addEventListener('click', function() {
                showToast('Feature coming soon!', 'info');
            });
        });
    </script>


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