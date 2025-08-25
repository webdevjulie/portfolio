<?php
session_start();
include '../includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Fetch packages from database
$packages = [];
$stmt = $conn->prepare("SELECT id, name, amount, description FROM packages ORDER BY amount ASC");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Calculate return amount and profit (you can adjust these formulas as needed)
    $invest_amount = $row['amount'];
    $return_amount = $invest_amount * 1.5; // 50% return
    $profit = $return_amount - $invest_amount;
    
    $packages[] = [
        'id' => $row['id'],
        'name' => $row['name'],
        'invest_amount' => $invest_amount,
        'return_amount' => $return_amount,
        'profit' => $profit,
        'description' => $row['description']
    ];
}

// If no packages found in database, create default ones
if (empty($packages)) {
    // Insert default packages
    $default_packages = [
        ['Starter Package', 5000, 'Perfect for beginners to start their investment journey'],
        ['Growth Package', 10000, 'Ideal for steady growth and better returns'],
        ['Premium Package', 20000, 'Maximum returns for serious investors'],
    ];
    
    $insert_stmt = $conn->prepare("INSERT INTO packages (name, amount, description, created_by) VALUES (?, ?, ?, ?)");
    foreach ($default_packages as $pkg) {
        $insert_stmt->bind_param("sdsi", $pkg[0], $pkg[1], $pkg[2], $_SESSION['user_id']);
        $insert_stmt->execute();
    }
    
    // Fetch the newly inserted packages
    $stmt = $conn->prepare("SELECT id, name, amount, description FROM packages ORDER BY amount ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $invest_amount = $row['amount'];
        $return_amount = $invest_amount * 1.5; // 50% return
        $profit = $return_amount - $invest_amount;
        
        $packages[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'invest_amount' => $invest_amount,
            'return_amount' => $return_amount,
            'profit' => $profit,
            'description' => $row['description']
        ];
    }
}

// Check if user already has an active investment
$user_id = $_SESSION['user_id'];
$active_investment_check = $conn->prepare("SELECT id FROM user_investments WHERE user_id = ? AND investment_status IN ('pending', 'active')");
$active_investment_check->bind_param("i", $user_id);
$active_investment_check->execute();
$has_active_investment = $active_investment_check->get_result()->num_rows > 0;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $package_id = $_POST['package_id'];

    // Check again if user has active investment (prevent double submission)
    if ($has_active_investment) {
        $error = "You already have an active investment. Please wait for it to complete before making a new investment.";
    } else {
        // Find the selected package from database
        $selected_package = null;
        foreach ($packages as $package) {
            if ($package['id'] == $package_id) {
                $selected_package = $package;
                break;
            }
        }
        
        if ($selected_package) {
            $amount = $selected_package['invest_amount'];
            $return_amount = $selected_package['return_amount'];
            $profit_amount = $selected_package['profit'];
            $package_name = $selected_package['name'];
            
            // Calculate maturity date (CHANGED: 7 days from now instead of 30 days)
            $start_date = date('Y-m-d');
            $maturity_date = date('Y-m-d', strtotime('+7 days'));
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Insert into user_investments table ONLY
                $investment_stmt = $conn->prepare("INSERT INTO user_investments (user_id, package_id, package_name, investment_amount, expected_return, profit_amount, investment_status, start_date, maturity_date) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?)");
                $investment_stmt->bind_param("iissddss", $user_id, $package_id, $package_name, $amount, $return_amount, $profit_amount, $start_date, $maturity_date);
                $investment_stmt->execute();
                
                // Get the investment ID for reference
                $investment_id = $conn->insert_id;
                
                // REMOVED: Insert into boards table - This section has been completely removed
                // No longer inserting investment records into the boards table
                
                // Commit transaction
                $conn->commit();
                
                $_SESSION['success_message'] = "Investment package purchased successfully! Your investment ID is #" . $investment_id . " and is being processed.";
                header("Location: dashboard.php?success=1");
                exit();
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error = "Failed to process investment. Please try again. Error: " . $e->getMessage();
            }
        } else {
            $error = "Invalid package selected.";
        }
    }
}

// Fetch user's investment history
$user_investments = [];
$history_stmt = $conn->prepare("SELECT * FROM user_investments WHERE user_id = ? ORDER BY created_at DESC");
$history_stmt->bind_param("i", $user_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result();

while ($row = $history_result->fetch_assoc()) {
    $user_investments[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Packages - Choose Your Plan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/customer_packages.css">
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="content">
        <div class="page-header">
            <h2><i class="fas fa-chart-line me-3"></i>Investment Packages</h2>
            <p>Choose the perfect investment plan to grow your wealth</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if ($has_active_investment): ?>
            <div class="alert alert-warning">
                <i class="fas fa-info-circle me-2"></i>You already have an active investment. Complete your current investment before making a new one.
            </div>
        <?php endif; ?>

        <?php if (empty($packages)): ?>
            <div class="no-packages">
                <i class="fas fa-box-open" style="font-size: 4rem; color: #ddd; margin-bottom: 1rem;"></i>
                <h4>No Investment Packages Available</h4>
                <p>Please contact the administrator to add investment packages.</p>
            </div>
        <?php else: ?>
            <form method="POST" id="investmentForm">
                <div class="row g-4 justify-content-center">
                    <?php foreach ($packages as $index => $package): ?>
                        <div class="col-lg-4 col-md-6">
                            <div class="package-card <?= $has_active_investment ? 'disabled' : '' ?>" onclick="<?= !$has_active_investment ? "selectPackage('{$package['id']}')" : '' ?>">
                                <?php if ($index === 1): ?>
                                    <div class="popular-badge">Most Popular</div>
                                <?php endif; ?>
                                
                                <input type="radio" name="package_id" value="<?= $package['id'] ?>" 
                                       class="package-radio" id="package_<?= $package['id'] ?>" 
                                       <?= $has_active_investment ? 'disabled' : 'required' ?>>
                                
                                <div class="package-header">
                                    <h3 class="package-name"><?= htmlspecialchars($package['name']) ?></h3>
                                    <div class="package-amount">₨ <?= number_format($package['invest_amount']) ?></div>
                                    <small style="opacity: 0.9;">Investment Amount</small>
                                </div>
                                
                                <div class="package-body">
                                    <div class="investment-details mb-3">
                                        <div class="row text-center">
                                            <div class="col-6">
                                                <div class="invest-box">
                                                    <h5 style="color: var(--primary-orange); margin: 0;">Invest</h5>
                                                    <h4 style="color: #333; margin: 0;">₨ <?= number_format($package['invest_amount']) ?></h4>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="return-box">
                                                    <h5 style="color: #28a745; margin: 0;">Get</h5>
                                                    <h4 style="color: #28a745; margin: 0;">₨ <?= number_format($package['return_amount']) ?></h4>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="profit-highlight mt-3 p-2" style="background: rgba(40, 167, 69, 0.1); border-radius: 10px;">
                                            <strong style="color: #28a745;">
                                                <i class="fas fa-arrow-up me-1"></i>
                                                Profit: ₨ <?= number_format($package['profit']) ?>
                                            </strong>
                                        </div>
                                    </div>
                                    
                                    <p class="package-description">
                                        <?= htmlspecialchars($package['description']) ?>
                                    </p>
                                    
                                    <ul class="package-features">
                                        <li><i class="fas fa-check-circle"></i> Secure Investment</li>
                                        <li><i class="fas fa-chart-line"></i> Guaranteed Returns</li>
                                        <li><i class="fas fa-clock"></i> Quick Processing</li>
                                        <li><i class="fas fa-shield-alt"></i> Risk Protection</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!$has_active_investment): ?>
                    <div class="selection-indicator" id="selectionIndicator">
                        <h5><i class="fas fa-info-circle me-2"></i>Package Selected</h5>
                        <p>Click "Invest Now" to proceed with your investment</p>
                    </div>

                    <div class="text-center mt-4">
                        <button type="submit" class="btn invest-button" id="investButton" disabled>
                            <i class="fas fa-rocket me-2"></i>Invest Now
                        </button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectPackage(packageId) {
            // Remove selected class from all cards
            document.querySelectorAll('.package-card').forEach(card => {
                card.classList.remove('selected');
            });
            
            // Add selected class to clicked card
            event.currentTarget.classList.add('selected');
            
            // Select the radio button
            document.getElementById('package_' + packageId).checked = true;
            
            // Enable invest button
            document.getElementById('investButton').disabled = false;
            
            // Show selection indicator
            document.getElementById('selectionIndicator').classList.add('show');
        }

        // Form validation
        document.getElementById('investmentForm').addEventListener('submit', function(e) {
            const selectedPackage = document.querySelector('input[name="package_id"]:checked');
            if (!selectedPackage) {
                e.preventDefault();
                alert('Please select an investment package first.');
                return false;
            }
            
            // Show loading state
            const button = document.getElementById('investButton');
            button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
            button.disabled = true;
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