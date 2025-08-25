<?php
session_start();

// Auth & DB setup
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=investment", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT username, role FROM user_management WHERE id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$current_user) {
        header("Location: ../auth/login.php");
        exit();
    }
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper Functions
// Helper functions that need to be implemented based on your system
function hasPendingAssignments($pdo, $user_id) {
    // Implementation depends on your assignments table structure
    // Example implementation:
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM assignments 
            WHERE user_id = :user_id 
            AND status = 'pending'
        ");
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false; // Default to allowing if we can't check
    }
}

function hasCompletedWithdrawals($pdo, $user_id) {
    // Check if user has any withdrawal records
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM payment_history 
            WHERE user_id = :user_id 
            AND payment_type = 'withdrawal'
            AND payment_status = 'completed'
        ");
        $stmt->execute([':user_id' => $user_id]);
        return $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false; // Default to allowing if we can't check
    }
}

function canUserBeSender($pdo, $user_id) {
    try {
        // Rule 1: Block if user has pending assignments
        if (hasPendingAssignments($pdo, $user_id)) {
            return ['can_send' => false, 'reason' => 'User has pending assignments'];
        }

        // Rule 2: Block if user already requested withdrawal
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM referral_withdrawals 
            WHERE user_id = :user_id AND withdrawal_status = 'completed'
        ");
        $stmt->execute([':user_id' => $user_id]);
        $withdrawal_count = $stmt->fetchColumn();
        
        if ($withdrawal_count > 0) {
            return ['can_send' => false, 'reason' => 'Users with completed withdrawals cannot be senders'];
        }

        // CRITICAL FIX: Check if user has EVER been a sender before
        // Check payment_history table
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as sender_count
            FROM payment_history 
            WHERE sender_user_id = :user_id 
            AND payment_status = 'completed'
        ");
        $stmt->execute([':user_id' => $user_id]);
        $has_been_sender_payment = $stmt->fetchColumn();
        
        if ($has_been_sender_payment > 0) {
            return ['can_send' => false, 'reason' => 'User can only be sender once in their lifecycle (payment history)'];
        }

        // Check withdrawal_history table for sender records
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as sender_count
            FROM withdrawal_history 
            WHERE sender_id = :user_id
        ");
        $stmt->execute([':user_id' => $user_id]);
        $has_been_sender_withdrawal = $stmt->fetchColumn();
        
        if ($has_been_sender_withdrawal > 0) {
            return ['can_send' => false, 'reason' => 'User already served as sender (withdrawal history)'];
        }

        // NEW: Check boards table for sender records
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as sender_count
            FROM boards 
            WHERE sender_id = :user_id 
            AND status IN ('completed', 'pending')
        ");
        $stmt->execute([':user_id' => $user_id]);
        $has_been_sender_boards = $stmt->fetchColumn();
        
        if ($has_been_sender_boards > 0) {
            return ['can_send' => false, 'reason' => 'User already served as sender (boards system)'];
        }

        // Get user's last activity to determine their current state
        $stmt = $pdo->prepare("
            (SELECT 'receiver' as role, payment_date as activity_date, group_id
             FROM payment_history 
             WHERE user_id = :user_id 
             AND payment_type IN ('investment_return','referral_bonus')
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, payment_date as activity_date, group_id
             FROM payment_history 
             WHERE sender_user_id = :user_id 
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, created_at as activity_date, group_id
             FROM boards 
             WHERE sender_id = :user_id 
             AND status IN ('completed', 'pending')
             ORDER BY created_at DESC LIMIT 1)
            ORDER BY activity_date DESC LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $lastActivity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastActivity) {
            // Brand new user - can be sender (but only once)
            return ['can_send' => true, 'reason' => 'New user can be sender (one-time only)'];
        }

        if ($lastActivity['role'] === 'sender') {
            // This should never happen due to the checks above, but keeping for safety
            return ['can_send' => false, 'reason' => 'User already served as sender'];
        }

        // Last activity was receiver, check if they have completed their investment cycle
        if ($lastActivity['role'] === 'receiver') {
            // Check if user's investment is completed before they can be a sender
            $stmt = $pdo->prepare("
                SELECT ui.investment_status, ui.expected_return,
                       COALESCE((
                           SELECT SUM(amount) 
                           FROM payment_history 
                           WHERE user_id = :user_id 
                           AND payment_status = 'completed' 
                           AND payment_type IN ('investment_return', 'referral_bonus')
                       ), 0) as total_received
                FROM user_investments ui 
                WHERE ui.user_id = :user_id 
                ORDER BY ui.created_at DESC LIMIT 1
            ");
            $stmt->execute([':user_id' => $user_id]);
            $investment = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($investment) {
                $expected_return = floatval($investment['expected_return'] ?? 0);
                $total_received = floatval($investment['total_received'] ?? 0);
                
                // User must complete their investment before becoming a sender
                if ($total_received >= $expected_return || $investment['investment_status'] === 'completed') {
                    return ['can_send' => true, 'reason' => 'Eligible - completed investment, can be sender (one-time only)'];
                } else {
                    return ['can_send' => false, 'reason' => 'Must complete investment returns before being sender'];
                }
            }

            return ['can_send' => true, 'reason' => 'Eligible - was receiver, can now be sender (one-time only)'];
        }

    } catch (Exception $e) {
        return ['can_send' => false, 'reason' => 'Error: ' . $e->getMessage()];
    }
}

function canUserBeReceiver($pdo, $user_id) {
    try {
        // Check if user has pending assignments first
        if (hasPendingAssignments($pdo, $user_id)) {
            return ['can_receive' => false, 'reason' => 'User has pending assignments'];
        }
        
        // CRITICAL FIX: Check if user has EVER been a receiver in boards table
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as receiver_count
            FROM boards 
            WHERE receiver_id = :user_id 
            AND status IN ('completed', 'pending')
        ");
        $stmt->execute([':user_id' => $user_id]);
        $has_been_receiver_boards = $stmt->fetchColumn();
        
        if ($has_been_receiver_boards > 0) {
            return ['can_receive' => false, 'reason' => 'User already served as receiver in boards system'];
        }
        
        // Priority: Check for withdrawal requests first
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count, COALESCE(SUM(withdrawal_amount), 0) as amount 
            FROM referral_withdrawals 
            WHERE user_id = :user_id AND withdrawal_status = 'completed'
        ");
        $stmt->execute([':user_id' => $user_id]);
        $withdrawal_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($withdrawal_data['count'] > 0) {
            return [
                'can_receive' => true, 
                'reason' => 'Has completed withdrawal request', 
                'remaining' => $withdrawal_data['amount'], 
                'is_withdrawal' => true
            ];
        }

        // Get investment details
        $stmt = $pdo->prepare("
            SELECT ui.investment_status, ui.expected_return, ui.investment_amount, ui.package_name,
                   COALESCE((
                       SELECT SUM(amount) 
                       FROM payment_history 
                       WHERE user_id = :user_id 
                       AND payment_status = 'completed' 
                       AND payment_type IN ('investment_return', 'referral_bonus')
                   ), 0) as total_received
            FROM user_investments ui 
            WHERE ui.user_id = :user_id 
            ORDER BY ui.created_at DESC LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $investment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$investment) {
            return ['can_receive' => false, 'reason' => 'No investment record found'];
        }

        $expected_return = floatval($investment['expected_return'] ?? 0);

        // Get user's last activity to determine their current state (including boards for both sender and receiver)
        $stmt = $pdo->prepare("
            (SELECT 'receiver' as role, payment_date as activity_date, group_id, 'payment_history' as source
             FROM payment_history 
             WHERE user_id = :user_id 
             AND payment_type IN ('investment_return','referral_bonus')
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, payment_date as activity_date, group_id, 'payment_history' as source
             FROM payment_history 
             WHERE sender_user_id = :user_id 
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, created_at as activity_date, group_id, 'boards' as source
             FROM boards 
             WHERE sender_id = :user_id 
             AND status IN ('completed', 'pending')
             ORDER BY created_at DESC LIMIT 1)
            UNION ALL
            (SELECT 'receiver' as role, created_at as activity_date, group_id, 'boards' as source
             FROM boards 
             WHERE receiver_id = :user_id 
             AND status IN ('completed', 'pending')
             ORDER BY created_at DESC LIMIT 1)
            ORDER BY activity_date DESC LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $lastActivity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastActivity) {
            // Brand new user - eligible to be receiver
            return [
                'can_receive' => true, 
                'reason' => 'New user - eligible to be receiver', 
                'remaining' => $expected_return
            ];
        }

        if ($lastActivity['role'] === 'receiver') {
            // Last activity was receiver
            // Check if investment is still incomplete
            $total_received = floatval($investment['total_received'] ?? 0);
            $remaining_needed = $expected_return - $total_received;
            
            if ($investment['investment_status'] !== 'completed' && $remaining_needed > 0) {
                return [
                    'can_receive' => true, 
                    'reason' => 'Still needs investment returns', 
                    'remaining' => $expected_return
                ];
            }
            
            // Investment complete, must be sender first before being receiver again
            return ['can_receive' => false, 'reason' => 'Must be sender first before being receiver again'];
        }

        if ($lastActivity['role'] === 'sender') {
            // Last activity was sender, now eligible to be receiver again
            return [
                'can_receive' => true, 
                'reason' => 'Eligible to be receiver after being sender', 
                'remaining' => $expected_return
            ];
        }
            
    } catch (Exception $e) {
        return ['can_receive' => false, 'reason' => 'Error checking receiver eligibility: ' . $e->getMessage()];
    }
}

// Helper function to get user's current cycle state (updated to include boards)
function getUserCycleState($pdo, $user_id) {
    try {
        // Check if user has ever been a sender (one-time rule) across all tables
        $stmt = $pdo->prepare("
            SELECT 
                (SELECT COUNT(*) FROM payment_history WHERE sender_user_id = :user_id AND payment_status = 'completed') +
                (SELECT COUNT(*) FROM withdrawal_history WHERE sender_id = :user_id) +
                (SELECT COUNT(*) FROM boards WHERE sender_id = :user_id AND status IN ('completed', 'pending')) 
                as total_sender_count
        ");
        $stmt->execute([':user_id' => $user_id]);
        $has_been_sender = $stmt->fetchColumn();
        
        // Get last activity across all tables
        $stmt = $pdo->prepare("
            (SELECT 'receiver' as role, payment_date as activity_date, group_id, 'payment_history' as source
             FROM payment_history 
             WHERE user_id = :user_id 
             AND payment_type IN ('investment_return','referral_bonus')
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, payment_date as activity_date, group_id, 'payment_history' as source
             FROM payment_history 
             WHERE sender_user_id = :user_id 
             AND payment_status = 'completed'
             ORDER BY payment_date DESC LIMIT 1)
            UNION ALL
            (SELECT 'sender' as role, created_at as activity_date, group_id, 'boards' as source
             FROM boards 
             WHERE sender_id = :user_id 
             AND status IN ('completed', 'pending')
             ORDER BY created_at DESC LIMIT 1)
            ORDER BY activity_date DESC LIMIT 1
        ");
        $stmt->execute([':user_id' => $user_id]);
        $lastActivity = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$lastActivity) {
            // New user - can be both receiver and sender (but sender only once)
            return [
                'state' => 'new', 
                'next_eligible' => 'both',
                'lifetime_sender_count' => 0,
                'can_be_sender_again' => true
            ];
        }
        
        if ($lastActivity['role'] === 'receiver') {
            return [
                'state' => 'was_receiver', 
                'next_eligible' => $has_been_sender > 0 ? 'receiver_only' : 'sender', 
                'group_id' => $lastActivity['group_id'],
                'lifetime_sender_count' => $has_been_sender,
                'can_be_sender_again' => $has_been_sender == 0,
                'last_activity_source' => $lastActivity['source']
            ];
        } else {
            return [
                'state' => 'was_sender', 
                'next_eligible' => 'receiver_only', 
                'group_id' => $lastActivity['group_id'],
                'lifetime_sender_count' => $has_been_sender,
                'can_be_sender_again' => false,
                'last_activity_source' => $lastActivity['source']
            ];
        }
        
    } catch (Exception $e) {
        return ['state' => 'error', 'message' => $e->getMessage()];
    }
}

// Enhanced function to check user's sender history across all tables
function getUserSenderHistory($pdo, $user_id) {
    try {
        // Get sender history from payment_history
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as payment_history_sender_count,
                MIN(payment_date) as first_payment_sender_date,
                MAX(payment_date) as last_payment_sender_date,
                GROUP_CONCAT(DISTINCT group_id) as payment_groups_sent_to
            FROM payment_history 
            WHERE sender_user_id = :user_id 
            AND payment_status = 'completed'
        ");
        $stmt->execute([':user_id' => $user_id]);
        $paymentHistory = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get sender history from boards
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as boards_sender_count,
                MIN(created_at) as first_board_sender_date,
                MAX(created_at) as last_board_sender_date,
                GROUP_CONCAT(DISTINCT group_id) as board_groups_sent_to,
                GROUP_CONCAT(DISTINCT status) as board_statuses
            FROM boards 
            WHERE sender_id = :user_id 
            AND status IN ('completed', 'pending')
        ");
        $stmt->execute([':user_id' => $user_id]);
        $boardsHistory = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get sender history from withdrawal_history
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as withdrawal_history_sender_count
            FROM withdrawal_history 
            WHERE sender_id = :user_id
        ");
        $stmt->execute([':user_id' => $user_id]);
        $withdrawalHistory = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total_times_sender' => 
                ($paymentHistory['payment_history_sender_count'] ?? 0) + 
                ($boardsHistory['boards_sender_count'] ?? 0) + 
                ($withdrawalHistory['withdrawal_history_sender_count'] ?? 0),
            'payment_history' => $paymentHistory,
            'boards_history' => $boardsHistory,
            'withdrawal_history' => $withdrawalHistory,
            'can_be_sender_again' => (
                ($paymentHistory['payment_history_sender_count'] ?? 0) + 
                ($boardsHistory['boards_sender_count'] ?? 0) + 
                ($withdrawalHistory['withdrawal_history_sender_count'] ?? 0)
            ) == 0
        ];
        
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Additional helper function to check if user has pending assignments in boards
function hasPendingBoardAssignments($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as pending_count
            FROM boards 
            WHERE (receiver_id = :user_id OR sender_id = :user_id) 
            AND status = 'pending'
        ");
        $stmt->execute([':user_id' => $user_id]);
        $pending_count = $stmt->fetchColumn();
        
        return $pending_count > 0;
        
    } catch (Exception $e) {
        return false;
    }
}

function canUserBeInvestmentReceiver($pdo, $user_id) {
    $result = canUserBeReceiver($pdo, $user_id);
    if (isset($result['is_withdrawal'])) {
        return ['can_receive' => false, 'reason' => 'User has withdrawal request'];
    }
    return $result;
}

function canUserBeWithdrawalReceiver($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(withdrawal_amount), 0) as amount FROM referral_withdrawals WHERE user_id = :user_id AND withdrawal_status = 'completed'");
        $stmt->execute([':user_id' => $user_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $data['count'] > 0
            ? ['can_receive' => true, 'reason' => 'Has completed withdrawal request', 'remaining' => $data['amount'], 'is_withdrawal' => true]
            : ['can_receive' => false, 'reason' => 'No completed withdrawal found'];
    } catch (Exception $e) {
        return ['can_receive' => false, 'reason' => 'Error checking eligibility'];
    }
}

function processReferralWithdrawals($pdo) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT rw.*, u.fullname FROM referral_withdrawals rw JOIN users u ON rw.user_id = u.id WHERE rw.withdrawal_status = 'pending' ORDER BY rw.created_at ASC");
        $stmt->execute();
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($withdrawals)) {
            $pdo->rollBack();
            return ['success' => true, 'processed_count' => 0, 'total_withdrawals' => 0, 'errors' => [], 'message' => 'No pending withdrawals'];
        }
        
        $processed_count = 0;
        $errors = [];
        
        foreach ($withdrawals as $withdrawal) {
            try {
                $all_users_stmt = $pdo->prepare("SELECT u.id, u.fullname FROM users u WHERE u.status = 'active' AND u.id != :user_id AND NOT EXISTS (SELECT 1 FROM referral_withdrawals rw2 WHERE rw2.user_id = u.id AND rw2.withdrawal_status = 'completed')");
                $all_users_stmt->execute([':user_id' => $withdrawal['user_id']]);
                $potential_senders = $all_users_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $available_senders = [];
                foreach ($potential_senders as $sender) {
                    if (canUserBeSender($pdo, $sender['id'])['can_send']) {
                        $available_senders[] = $sender;
                    }
                }
                
                if (empty($available_senders)) {
                    $errors[] = "No available senders for withdrawal ID {$withdrawal['id']}";
                    continue;
                }
                
                $selected_sender = $available_senders[0];
                $start_date = date('Y-m-d H:i:s');
                $maturity_date = date('Y-m-d H:i:s', strtotime('+7 days'));
                
                $insert_assignment = $pdo->prepare("INSERT INTO boards (receiver_id, sender_id, sender_name, amount, status, assignment_type, start_date, maturity_date, created_at, updated_at) VALUES (:receiver_id, :sender_id, :sender_name, :amount, 'pending', 'referral_withdrawal', :start_date, :maturity_date, NOW(), NOW())");
                
                if ($insert_assignment->execute([':receiver_id' => $withdrawal['user_id'], ':sender_id' => $selected_sender['id'], ':sender_name' => $selected_sender['fullname'], ':amount' => $withdrawal['withdrawal_amount'], ':start_date' => $start_date, ':maturity_date' => $maturity_date])) {
                    $update_withdrawal = $pdo->prepare("UPDATE referral_withdrawals SET withdrawal_status = 'completed', processed_at = NOW(), assigned_sender_id = :sender_id WHERE id = :withdrawal_id");
                    
                    if ($update_withdrawal->execute([':withdrawal_id' => $withdrawal['id'], ':sender_id' => $selected_sender['id']])) {
                        $processed_count++;
                    } else {
                        $errors[] = "Failed to update withdrawal status for ID {$withdrawal['id']}";
                    }
                } else {
                    $errors[] = "Failed to create assignment for withdrawal ID {$withdrawal['id']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing withdrawal ID {$withdrawal['id']}: " . $e->getMessage();
            }
        }
        
        if ($processed_count > 0) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
        
        return ['success' => true, 'processed_count' => $processed_count, 'total_withdrawals' => count($withdrawals), 'errors' => $errors];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'error' => $e->getMessage(), 'processed_count' => 0, 'total_withdrawals' => 0, 'errors' => []];
    }
}

function recordPaymentInHistory($pdo, $assignment_data) {
    try {
        $pdo->beginTransaction();
        
        // Check if this is a multi-sender assignment
        $is_multi_sender = isset($assignment_data['is_multi_sender']) && $assignment_data['is_multi_sender'];
        $sender_details = [];
        
        if ($is_multi_sender && isset($assignment_data['sender_details'])) {
            // Parse sender_details JSON if it's a string
            if (is_string($assignment_data['sender_details'])) {
                $sender_details = json_decode($assignment_data['sender_details'], true);
            } else {
                $sender_details = $assignment_data['sender_details'];
            }
        }
        
        // Get receiver information for payment history
        $stmt = $pdo->prepare("SELECT fullname, email, phone FROM users WHERE id = :receiver_id");
        $stmt->execute([':receiver_id' => $assignment_data['receiver_id']]);
        $receiver_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If we have sender details, create payment records for each sender (they become receivers in next cycle)
        if (!empty($sender_details)) {
            foreach ($sender_details as $sender) {
                $sender_amount = floatval($sender['amount']); // This is their investment amount
                $sender_expected_return = floatval($sender['expected_return']); // This is what they should receive
                $profit_amount = $sender_expected_return - $sender_amount;
                
                // Record payment where the sender becomes the receiver (gets their return)
                $stmt = $pdo->prepare("
                    INSERT INTO payment_history (
                        user_id, 
                        investment_id, 
                        payment_type, 
                        amount, 
                        original_investment, 
                        profit_amount, 
                        package_name, 
                        payment_status, 
                        payment_method, 
                        transaction_reference, 
                        payment_date, 
                        sender_name, 
                        sender_email, 
                        sender_phone, 
                        sender_user_id, 
                        sender_details,
                        is_multi_sender,
                        created_at, 
                        updated_at
                    ) VALUES (
                        :user_id, 
                        :investment_id, 
                        :payment_type, 
                        :amount, 
                        :original_investment, 
                        :profit_amount, 
                        :package_name, 
                        'completed', 
                        'direct_transfer', 
                        :transaction_reference, 
                        NOW(), 
                        :sender_name, 
                        :sender_email, 
                        :sender_phone, 
                        :sender_user_id, 
                        :sender_details,
                        :is_multi_sender,
                        NOW(), 
                        NOW()
                    )
                ");
                
                $result = $stmt->execute([
                    ':user_id' => $sender['id'], // The sender becomes the receiver in payment history
                    ':investment_id' => $assignment_data['investment_id'] ?? 0,
                    ':payment_type' => $assignment_data['assignment_type'] === 'referral_withdrawal' ? 'referral_bonus' : 'investment_return',
                    ':amount' => $sender_expected_return, // They receive their expected return
                    ':original_investment' => $sender_amount, // Their original investment
                    ':profit_amount' => $profit_amount,
                    ':package_name' => $sender['package_name'] ?? 'Unknown',
                    ':transaction_reference' => $assignment_data['transaction_reference'] ?? null,
                    ':sender_name' => $receiver_info['fullname'] ?? '', // The original receiver becomes the sender
                    ':sender_email' => $receiver_info['email'] ?? '',
                    ':sender_phone' => $receiver_info['phone'] ?? '',
                    ':sender_user_id' => $assignment_data['receiver_id'], // Original receiver becomes sender
                    ':sender_details' => json_encode($sender_details),
                    ':is_multi_sender' => 1
                ]);
                
                if (!$result) {
                    throw new Exception("Failed to create payment record for sender ID: " . $sender['id']);
                }
            }
        } else {
            // Single sender assignment - get sender information
            $stmt = $pdo->prepare("SELECT fullname, email, phone FROM users WHERE id = :sender_id");
            $stmt->execute([':sender_id' => $assignment_data['sender_id']]);
            $sender_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $profit_amount = $assignment_data['amount'] - ($assignment_data['original_investment'] ?? 0);
            
            // Record payment where receiver gets the payment from sender
            $stmt = $pdo->prepare("
                INSERT INTO payment_history (
                    user_id, 
                    investment_id, 
                    payment_type, 
                    amount, 
                    original_investment, 
                    profit_amount, 
                    package_name, 
                    payment_status, 
                    payment_method, 
                    transaction_reference, 
                    payment_date, 
                    sender_name, 
                    sender_email, 
                    sender_phone, 
                    sender_user_id, 
                    created_at, 
                    updated_at
                ) VALUES (
                    :user_id, 
                    :investment_id, 
                    :payment_type, 
                    :amount, 
                    :original_investment, 
                    :profit_amount, 
                    :package_name, 
                    'completed', 
                    'direct_transfer', 
                    :transaction_reference, 
                    NOW(), 
                    :sender_name, 
                    :sender_email, 
                    :sender_phone, 
                    :sender_user_id, 
                    NOW(), 
                    NOW()
                )
            ");
            
            $result = $stmt->execute([
                ':user_id' => $assignment_data['receiver_id'],
                ':investment_id' => $assignment_data['investment_id'] ?? 0,
                ':payment_type' => $assignment_data['assignment_type'] === 'referral_withdrawal' ? 'referral_bonus' : 'investment_return',
                ':amount' => $assignment_data['amount'],
                ':original_investment' => $assignment_data['original_investment'] ?? 0,
                ':profit_amount' => $profit_amount,
                ':package_name' => $assignment_data['package_name'] ?? 'Unknown',
                ':transaction_reference' => $assignment_data['transaction_reference'] ?? null,
                ':sender_name' => $sender_info['fullname'] ?? '',
                ':sender_email' => $sender_info['email'] ?? '',
                ':sender_phone' => $sender_info['phone'] ?? '',
                ':sender_user_id' => $assignment_data['sender_id']
            ]);
            
            if (!$result) {
                throw new Exception("Failed to create payment record");
            }
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Payment history recording error: " . $e->getMessage());
        return false;
    }
}

// Manual Assignment Processing - FIXED VERSION - Withdrawal amount fix
if (isset($_POST['action']) && $_POST['action'] === 'assign_manual_sender') {
    $receiver_id = $_POST['receiver_id'];
    $selected_senders = json_decode($_POST['selected_senders'] ?? '[]', true);
    $total_amount = floatval($_POST['amount']);

    if (empty($receiver_id) || empty($selected_senders) || empty($total_amount)) {
        $error_message = "Please select a receiver, at least one sender, and ensure amount is calculated.";
    } else {
        $receiverEligibility = canUserBeReceiver($pdo, $receiver_id);
        
        if (!$receiverEligibility['can_receive']) {
            $error_message = "Cannot assign this user as receiver: " . $receiverEligibility['reason'];
        } else {
            $receiver_need = floatval($receiverEligibility['remaining']);
            $is_withdrawal = $receiverEligibility['is_withdrawal'] ?? false;
            
            // Assignment amount is always the receiver's need, not the sender total
            $assignment_amount = $receiver_need;
            
            if ($total_amount <= 0) {
                $error_message = "Total amount must be greater than zero.";
            } elseif (abs($total_amount - $assignment_amount) > 0.01) {
                $error_message = "Assignment amount must equal receiver's need: ₱" . number_format($assignment_amount, 2) . ", but submitted amount is: ₱" . number_format($total_amount, 2);
            } else {
                $sender_details = [];
                $calculated_total = 0;
                $validation_errors = [];
                
                foreach ($selected_senders as $sender_id) {
                    $senderEligibility = canUserBeSender($pdo, $sender_id);
                    
                    if (!$senderEligibility['can_send']) {
                        $validation_errors[] = "Sender ID {$sender_id} not eligible: " . $senderEligibility['reason'];
                        continue;
                    }
                    
                    try {
                        $stmt = $pdo->prepare("SELECT u.id, u.fullname, u.email, ui.investment_amount, ui.expected_return, ui.package_name FROM users u INNER JOIN user_investments ui ON u.id = ui.user_id WHERE u.id = :sender_id AND u.status = 'active' ORDER BY ui.created_at DESC LIMIT 1");
                        $stmt->execute([':sender_id' => $sender_id]);
                        $sender_info = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$sender_info) {
                            $validation_errors[] = "Sender ID {$sender_id} not found or inactive.";
                            continue;
                        }
                        
                        $package_amount = floatval($sender_info['investment_amount'] ?? 0);
                        $expected_return = floatval($sender_info['expected_return'] ?? 0);
                        
                        if ($package_amount <= 0) {
                            $validation_errors[] = "Sender {$sender_info['fullname']} has no valid package amount.";
                            continue;
                        }
                        
                        $sender_details[] = [
                            'id' => (int)$sender_id,
                            'name' => $sender_info['fullname'],
                            'email' => $sender_info['email'],
                            'amount' => $package_amount,
                            'expected_return' => $expected_return,
                            'package_name' => $sender_info['package_name'] ?? 'Unknown Package'
                        ];
                        
                        $calculated_total += $package_amount;
                        
                    } catch (Exception $e) {
                        $validation_errors[] = "Error validating sender ID {$sender_id}: " . $e->getMessage();
                    }
                }
                
                if (!empty($validation_errors)) {
                    $error_message = "Sender validation errors:<br>" . implode('<br>', $validation_errors);
                } elseif (empty($sender_details)) {
                    $error_message = "No valid senders found.";
                } elseif ($calculated_total < $receiver_need) {
                    $error_message = "Sender packages total (₱" . number_format($calculated_total, 2) . ") cannot cover receiver's need (₱" . number_format($receiver_need, 2) . ").";
                } else {
                    try {
                        $pdo->beginTransaction();
                        
                        $assignment_type = $is_withdrawal ? 'referral_withdrawal' : 'investment';
                        $start_date = date('Y-m-d H:i:s');
                        $maturity_date = date('Y-m-d H:i:s', strtotime('+7 days'));
                        
                        // Get receiver info for display
                        $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = :receiver_id");
                        $stmt->execute([':receiver_id' => $receiver_id]);
                        $receiver_info = $stmt->fetch(PDO::FETCH_ASSOC);
                        $receiver_name = $receiver_info['fullname'] ?? 'Unknown Receiver';
                        
                        // Prepare sender data for single assignment record
                        $sender_ids = array_column($sender_details, 'id');
                        $sender_names = implode(', ', array_column($sender_details, 'name'));
                        $primary_sender_id = $sender_details[0]['id']; // Use first sender as primary
                        $is_multi_sender = count($sender_details) > 1;
                        
                        // FIXED: Create SEPARATE assignment records for each sender with GROUP ID
                        $assignment_ids = [];
                        $assignment_created = true;
                        
                        // Generate a unique group ID for this assignment batch
                        $group_id = 'GRP_' . date('YmdHis') . '_' . $receiver_id;
                        
                        foreach ($sender_details as $sender) {
                            // FIXED: For withdrawals, use investment_amount directly
                            // For regular investments, calculate proportional amount
                            if ($is_withdrawal) {
                                // For withdrawals, each sender record gets their full investment amount
                                $sender_assignment_amount = $sender['amount']; // This is investment_amount
                            } else {
                                // For regular investments, calculate proportional amount based on receiver need
                                $total_sender_packages = array_sum(array_column($sender_details, 'amount'));
                                $sender_proportion = $sender['amount'] / $total_sender_packages;
                                $sender_assignment_amount = $assignment_amount * $sender_proportion;
                            }
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO boards (
                                    receiver_id, 
                                    sender_id, 
                                    sender_name, 
                                    amount, 
                                    status, 
                                    assignment_type, 
                                    is_multi_sender, 
                                    group_id,
                                    start_date, 
                                    maturity_date, 
                                    created_at, 
                                    updated_at,
                                    sender_package_amount,
                                    sender_expected_return,
                                    package_name
                                ) VALUES (
                                    :receiver_id, 
                                    :sender_id, 
                                    :sender_name, 
                                    :amount, 
                                    'pending', 
                                    :assignment_type, 
                                    :is_multi_sender, 
                                    :group_id,
                                    :start_date, 
                                    :maturity_date, 
                                    NOW(), 
                                    NOW(),
                                    :sender_package_amount,
                                    :sender_expected_return,
                                    :package_name
                                )
                            ");
                            
                            $individual_assignment = $stmt->execute([
                                ':receiver_id' => $receiver_id,
                                ':sender_id' => $sender['id'],
                                ':sender_name' => $sender['name'],
                                ':amount' => $sender_assignment_amount,
                                ':assignment_type' => $assignment_type,
                                ':is_multi_sender' => $is_multi_sender ? 1 : 0,
                                ':group_id' => $group_id,
                                ':start_date' => $start_date,
                                ':maturity_date' => $maturity_date,
                                ':sender_package_amount' => $sender['amount'],
                                ':sender_expected_return' => $sender['expected_return'],
                                ':package_name' => $sender['package_name']
                            ]);
                            
                            if ($individual_assignment) {
                                $assignment_ids[] = $pdo->lastInsertId();
                            } else {
                                $assignment_created = false;
                                break;
                            }
                        }
                        
                        if (!$assignment_created) {
                            $pdo->rollBack();
                            $error_message = "Failed to create one or more assignment records.";
                        } else {
                            
                            // If this is a withdrawal assignment, clean up the withdrawal request
                            if ($is_withdrawal) {
                                $delete_stmt = $pdo->prepare("
                                    DELETE FROM referral_withdrawals 
                                    WHERE user_id = :receiver_id 
                                    AND withdrawal_status = 'completed' 
                                    AND withdrawal_amount <= :total_amount 
                                    ORDER BY created_at ASC 
                                    LIMIT 1
                                ");
                                $delete_stmt->execute([
                                    ':receiver_id' => $receiver_id, 
                                    ':total_amount' => $assignment_amount
                                ]);
                            }
                            
                            $pdo->commit();
                            
                            // Success message with group information
                            $assignment_ids_list = implode(', #', $assignment_ids);
                            $sender_list = implode('<br>', array_map(function($sender, $index) use ($assignment_ids, $is_withdrawal) {
                                $amount_display = $is_withdrawal ? $sender['amount'] : ($sender['amount']); // Show investment amount for withdrawals
                                return "• {$sender['name']} (₱" . number_format($amount_display, 2) . ") - Record #{$assignment_ids[$index]}";
                            }, $sender_details, array_keys($sender_details)));
                            
                            $success_message = "
                                <strong>Assignment Group Created Successfully!</strong><br>
                                <strong>Group ID:</strong> {$group_id}<br>
                                <strong>Assignment Type:</strong> " . ($is_withdrawal ? 'Referral Withdrawal' : 'Investment') . "<br>
                                <strong>Database Records:</strong> #{$assignment_ids_list}<br>
                                <strong>Receiver:</strong> {$receiver_name} (needs ₱" . number_format($assignment_amount, 2) . " total)<br>
                                <strong>Individual Records:</strong><br>
                                {$sender_list}<br>
                                <strong>Total Records Created:</strong> " . count($assignment_ids) . "<br>
                                <strong>Total Sender Packages:</strong> ₱" . number_format($calculated_total, 2) . "<br>
                                <em>Note: " . ($is_withdrawal ? 'For withdrawals, each record stores the sender\'s investment amount.' : 'For investments, amounts are proportionally distributed.') . "</em>
                            ";
                        }
                        
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        $error_message = "Database error: " . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Updated query to group assignments for display while keeping separate records
try {
    // First get all assignments grouped by group_id or individual records
    $assignments_raw = $pdo->query("
        SELECT 
            b.*, 
            u.fullname as receiver_name, 
            u.email as receiver_email,
            s.fullname as actual_sender_name,
            b.sender_package_amount,
            b.sender_expected_return,
            b.package_name as sender_package_name
        FROM boards b 
        JOIN users u ON b.receiver_id = u.id 
        JOIN users s ON b.sender_id = s.id 
        ORDER BY b.created_at DESC, b.group_id, b.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Group assignments by group_id for display
    $assignments = [];
    $grouped_assignments = [];
    
    foreach ($assignments_raw as $row) {
        if ($row['group_id'] && $row['is_multi_sender']) {
            // This is part of a multi-sender group
            if (!isset($grouped_assignments[$row['group_id']])) {
                $grouped_assignments[$row['group_id']] = [
                    'group_id' => $row['group_id'],
                    'receiver_id' => $row['receiver_id'],
                    'receiver_name' => $row['receiver_name'],
                    'receiver_email' => $row['receiver_email'],
                    'assignment_type' => $row['assignment_type'],
                    'status' => $row['status'],
                    'created_at' => $row['created_at'],
                    'start_date' => $row['start_date'],
                    'maturity_date' => $row['maturity_date'],
                    'is_multi_sender' => true,
                    'is_group' => true,
                    'total_amount' => 0,
                    'senders' => [],
                    'record_ids' => []
                ];
            }
            
            $grouped_assignments[$row['group_id']]['total_amount'] += $row['amount'];
            $grouped_assignments[$row['group_id']]['senders'][] = [
                'id' => $row['sender_id'],
                'name' => $row['actual_sender_name'],
                'amount' => $row['amount'],
                'package_amount' => $row['sender_package_amount'],
                'expected_return' => $row['sender_expected_return'],
                'package_name' => $row['sender_package_name']
            ];
            $grouped_assignments[$row['group_id']]['record_ids'][] = $row['id'];
        } else {
            // Single sender assignment
            $row['is_group'] = false;
            $row['senders'] = [[
                'id' => $row['sender_id'],
                'name' => $row['actual_sender_name'],
                'amount' => $row['amount'],
                'package_amount' => $row['sender_package_amount'],
                'expected_return' => $row['sender_expected_return'],
                'package_name' => $row['sender_package_name']
            ]];
            $row['total_amount'] = $row['amount'];
            $row['record_ids'] = [$row['id']];
            $assignments[] = $row;
        }
    }
    
    // Add grouped assignments to the main assignments array
    foreach ($grouped_assignments as $group) {
        $assignments[] = $group;
    }
    
    // Sort by created_at descending
    usort($assignments, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get data for display
try {
    $pending_withdrawals_stmt = $pdo->prepare("SELECT COUNT(*) as count, COALESCE(SUM(withdrawal_amount), 0) as total_amount FROM referral_withdrawals WHERE withdrawal_status = 'pending'");
    $pending_withdrawals_stmt->execute();
    $pending_withdrawals_data = $pending_withdrawals_stmt->fetch(PDO::FETCH_ASSOC);
    $pending_withdrawals_count = $pending_withdrawals_data['count'];
    $pending_withdrawals_total = $pending_withdrawals_data['total_amount'];
} catch (Exception $e) {
    $pending_withdrawals_count = 0;
    $pending_withdrawals_total = 0;
}

try {
    // FIXED: Correctly show receiver's need as expected_return, not investment_amount
    $investment_users_query = "SELECT u.id, u.fullname, u.email, u.investment_status, u.status as user_status, ui.package_id, ui.package_name, ui.expected_return as package_amount, ui.expected_return, ui.investment_amount, ui.investment_status as user_investment_status, 'investment' as user_type FROM users u INNER JOIN user_investments ui ON u.id = ui.user_id WHERE u.status = 'active'";
    
    $withdrawal_users_query = "SELECT u.id, u.fullname, u.email, u.investment_status, u.status as user_status, NULL as package_id, 'Withdrawal Request' as package_name, 0 as package_amount, COALESCE(SUM(rw.withdrawal_amount), 0) as expected_return, 0 as investment_amount, 'pending' as user_investment_status, 'withdrawal' as user_type FROM users u INNER JOIN referral_withdrawals rw ON u.id = rw.user_id WHERE u.status = 'active' AND rw.withdrawal_status = 'completed' GROUP BY u.id, u.fullname, u.email, u.investment_status, u.status HAVING COALESCE(SUM(rw.withdrawal_amount), 0) > 0";
    
    $all_users_query = "($investment_users_query) UNION ALL ($withdrawal_users_query) ORDER BY user_type DESC, fullname ASC";
    
    $all_users_raw = $pdo->query($all_users_query)->fetchAll(PDO::FETCH_ASSOC);
    $all_users = [];

    foreach ($all_users_raw as $user) {
        $is_withdrawal_user = ($user['user_type'] === 'withdrawal');
        
        $receiverEligibility = $is_withdrawal_user ? 
            canUserBeWithdrawalReceiver($pdo, $user['id']) : 
            canUserBeInvestmentReceiver($pdo, $user['id']);
        
        $senderEligibility = canUserBeSender($pdo, $user['id']);
        
        // FIXED: For receivers, use expected_return as their need (what they should receive)
        $expected_return = floatval($user['expected_return'] ?? 0);
        
        $user['can_be_sender'] = $senderEligibility['can_send'];
        $user['sender_restriction_reason'] = $senderEligibility['reason'];
        $user['can_be_receiver'] = $receiverEligibility['can_receive'];
        $user['receiver_restriction_reason'] = $receiverEligibility['reason'];
        // FIXED: Always use expected_return as remaining_needed for receivers
        $user['remaining_needed'] = $is_withdrawal_user ? ($receiverEligibility['remaining'] ?? 0) : $expected_return;
        $user['has_pending'] = hasPendingAssignments($pdo, $user['id']);
        $user['is_withdrawal'] = $is_withdrawal_user;
        // FIXED: target_amount should always be expected_return for investment users
        $user['target_amount'] = $is_withdrawal_user ? ($receiverEligibility['remaining'] ?? 0) : $expected_return;
        
        $all_users[] = $user;
    }

    $assignments = $pdo->query("SELECT b.*, u.fullname as receiver_name, u.email as receiver_email, s.fullname as actual_sender_name FROM boards b JOIN users u ON b.receiver_id = u.id LEFT JOIN users s ON b.sender_id = s.id ORDER BY b.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

    $pending_withdrawals = $pdo->query("SELECT rw.*, u.fullname, u.email, DATE_FORMAT(rw.created_at, '%M %d, %Y at %h:%i %p') as formatted_date FROM referral_withdrawals rw JOIN users u ON rw.user_id = u.id WHERE rw.withdrawal_status = 'pending' ORDER BY rw.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { 
            --primary-orange: #ff6b35; 
            --light-orange: #ff8c5a; 
            --dark-orange: #e55a2b;
            --sidebar-width: 250px; 
            --white: #ffffff;
            --light-gray: #f8f9fa;
            --gray: #6c757d;
            --dark-gray: #495057;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --dark: #343a40;
            --border-color: #dee2e6;
            --shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --shadow-lg: 0 1rem 3rem rgba(0, 0, 0, 0.175);
        }
        * { font-family: 'Poppins', sans-serif; }
        .body { background-color: #f8f9fa; }
        .sidebar { position: fixed; top: 0; left: 0; height: 100vh; width: var(--sidebar-width); background: linear-gradient(135deg, var(--primary-orange) 0%, var(--dark-orange) 100%); z-index: 1000; transition: all 0.3s ease; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-brand { color: white; font-size: 1.5rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; }
        .sidebar-brand:hover { color: white; text-decoration: none; }
        .sidebar-nav { padding: 1rem 0; }
        .nav-item { margin: 0.25rem 0; }
        .nav-link { color: rgba(255,255,255,0.9); padding: 0.75rem 1.5rem; display: flex; align-items: center; gap: 0.75rem; text-decoration: none; transition: all 0.3s ease; border-radius: 0; font-weight: 500; }
        .nav-link:hover { background-color: rgba(255,255,255,0.1); color: white; transform: translateX(5px); }
        .nav-link.active { background-color: rgba(255,255,255,0.2); color: white; border-right: 4px solid white; }
        .nav-link i { font-size: 1.1rem; width: 20px; text-align: center; }
        .header { position: fixed; top: 0; left: var(--sidebar-width); right: 0; height: 70px; background: white; z-index: 999; display: flex; align-items: center; justify-content: space-between; padding: 0 2rem; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-bottom: 1px solid #e9ecef; }
        .welcome-text { color: #495057; font-size: 1.1rem; font-weight: 600; }
        .welcome-name { color: var(--primary-orange); font-weight: 700; }
        .user-dropdown .dropdown-toggle { background: linear-gradient(135deg, var(--primary-orange), var(--light-orange)); border: none; color: white; padding: 0.5rem 1rem; border-radius: 25px; font-weight: 500; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s ease; }
        .user-dropdown .dropdown-toggle:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3); }
        .user-dropdown .dropdown-toggle:focus { box-shadow: 0 0 0 0.25rem rgba(255, 107, 53, 0.25); }
        .user-dropdown .dropdown-menu { border: none; box-shadow: 0 4px 20px rgba(0,0,0,0.1); border-radius: 10px; padding: 0.5rem 0; margin-top: 0.5rem; }
        .user-dropdown .dropdown-item { padding: 0.75rem 1.5rem; color: #495057; font-weight: 500; transition: all 0.3s ease; }
        .user-dropdown .dropdown-item:hover { background-color: #f8f9fa; color: var(--primary-orange); transform: translateX(5px); }
        .main-content { margin-left: var(--sidebar-width); margin-top: 70px; padding: 2rem; min-height: calc(100vh - 70px); }
        .sender-row { background-color: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 10px; border: 1px solid #dee2e6; }
        .sender-row:hover { background-color: #e9ecef; }
        .delete-sender { color: #dc3545; cursor: pointer; }
        .delete-sender:hover { color: #bb2d3b; }
        .table th { border-top: none; font-weight: 600; color: #495057; }
        .table-responsive { max-height: 600px; overflow-y: auto; }
        .pending-indicator { background: linear-gradient(135deg, #ffc107, #ffb300); color: #000; padding: 0.25rem 0.5rem; border-radius: 15px; font-size: 0.75rem; margin-left: 0.5rem; }
        .withdrawal-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(255, 107, 53, 0.7); } 70% { box-shadow: 0 0 0 10px rgba(255, 107, 53, 0); } 100% { box-shadow: 0 0 0 0 rgba(255, 107, 53, 0); } }
        
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
            color: white;
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
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .header { left: 0; }
            .main-content { margin-left: 0; }
            .mobile-toggle { display: block !important; }
        }
        .mobile-toggle { display: none; background: none; border: none; font-size: 1.5rem; color: #495057; }
        .sender-item {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            background: #f8f9fa;
            transition: all 0.2s ease;
        }

        /* Page Header */
        .page-header {
        background: linear-gradient(135deg, var(--primary-orange), var(--light-orange));
        color: var(--white);
        padding: 2rem;
        margin: -20px -20px 2rem -20px;
        }

        .page-header h2 {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 0.5rem;
        }

        .page-header p {
        font-size: 1.1rem;
        margin-bottom: 0.25rem;
        }

        .page-header small {
        opacity: 0.9;
        font-size: 0.9rem;
        }

        /* Utility Classes */
        .d-flex { display: flex; }
        .justify-content-between { justify-content: space-between; }
        .align-items-center { align-items: center; }
        .gap-2 { gap: 0.5rem; }
        .mb-0 { margin-bottom: 0; }
        .mb-2 { margin-bottom: 0.5rem; }
        .mb-3 { margin-bottom: 1rem; }
        .mb-4 { margin-bottom: 1.5rem; }
        .me-1 { margin-right: 0.25rem; }
        .me-2 { margin-right: 0.5rem; }
        .me-3 { margin-right: 1rem; }
        .mt-3 { margin-top: 1rem; }
        .mt-2 { margin-top: 0.5rem; }
        .p-3 { padding: 1rem; }
        .py-4 { padding-top: 1.5rem; padding-bottom: 1.5rem; }
        .py-5 { padding-top: 3rem; padding-bottom: 3rem; }
        .text-center { text-align: center; }
        .text-muted { color: var(--gray) !important; }
        .text-success { color: var(--success) !important; }
        .text-dark { color: var(--dark) !important; }
        .fw-bold { font-weight: 700; }
        .fs-1 { font-size: 2.5rem; }
        .fs-4 { font-size: 1.5rem; }
        .fs-5 { font-size: 1.25rem; }
        .opacity-90 { opacity: 0.9; }
        .col-12 { width: 100%; }
        .col-md-3 { width: 25%; }

        /* Row and Column System */
        .row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -15px;
        }

        .row > * {
        padding: 0 15px;
        }

        @media (max-width: 768px) {
        .col-md-3 {
            width: 100%;
            margin-bottom: 1rem;
        }
        }

        /* Buttons */
        .btn {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 6px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.9rem;
        }

        .btn-lg {
        padding: 0.75rem 1.5rem;
        font-size: 1rem;
        }

        .btn-sm {
        padding: 0.375rem 0.75rem;
        font-size: 0.875rem;
        }

        .btn-primary {
        background: var(--primary-orange);
        color: var(--white);
        }

        .btn-primary:hover {
        background: var(--dark-orange);
        transform: translateY(-1px);
        }

        .btn-light {
        background: var(--white);
        color: var(--dark-gray);
        border: 1px solid var(--border-color);
        }

        .btn-light:hover {
        background: var(--light-gray);
        transform: translateY(-1px);
        }

        .btn-danger {
        background: var(--danger);
        color: var(--white);
        }

        .btn-danger:hover {
        background: #c82333;
        transform: translateY(-1px);
        }

        .btn-warning {
        background: var(--warning);
        color: var(--dark);
        }

        .btn-warning:hover {
        background: #e0a800;
        transform: translateY(-1px);
        }

        .btn-secondary {
        background: var(--gray);
        color: var(--white);
        }

        .btn-secondary:hover {
        background: var(--dark-gray);
        }

        .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none !important;
        }

        /* Withdrawal Pulse Animation */
        .withdrawal-pulse {
        animation: pulse 2s infinite;
        }

        @keyframes pulse {
        0% { transform: scale(1); }
        50% { transform: scale(1.05); }
        100% { transform: scale(1); }
        }

        /* Alert Messages */
        .alert {
        padding: 1rem;
        border-radius: 8px;
        margin-bottom: 1rem;
        border: 1px solid transparent;
        position: relative;
        }

        .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border-color: var(--success);
        color: #155724;
        }

        .alert-danger {
        background: rgba(220, 53, 69, 0.1);
        border-color: var(--danger);
        color: #721c24;
        }

        .alert-info {
        background: rgba(23, 162, 184, 0.1);
        border-color: var(--info);
        color: #0c5460;
        }

        .alert-warning {
        background: rgba(255, 193, 7, 0.1);
        border-color: var(--warning);
        color: #856404;
        }

        .alert-dismissible .btn-close {
        position: absolute;
        top: 0.5rem;
        right: 0.5rem;
        padding: 0.75rem;
        background: none;
        border: none;
        font-size: 1.25rem;
        cursor: pointer;
        opacity: 0.5;
        }

        .alert-dismissible .btn-close:hover {
        opacity: 1;
        }

        .border-start {
        border-left: 4px solid var(--warning) !important;
        }

        .border-4 {
        border-width: 4px !important;
        }

        /* Cards */
        .card {
        background: var(--white);
        border-radius: 12px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border-color);
        overflow: hidden;
        margin-bottom: 1.5rem;
        }

        .card-header {
        background: linear-gradient(135deg, var(--light-gray), var(--white));
        padding: 1.25rem;
        border-bottom: 1px solid var(--border-color);
        }

        .card-header h5 {
        margin: 0;
        font-weight: 600;
        color: var(--dark-gray);
        }

        .card-body {
        padding: 1.5rem;
        }

        .card-title {
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        opacity: 0.9;
        }

        /* Summary Cards with Orange Theme */
        .bg-primary {
        background: linear-gradient(135deg, var(--primary-orange), var(--light-orange)) !important;
        }

        .bg-warning {
        background: linear-gradient(135deg, var(--warning), #ffca2c) !important;
        }

        .bg-info {
        background: linear-gradient(135deg, var(--info), #20c997) !important;
        }

        .bg-danger {
        background: linear-gradient(135deg, var(--danger), #e74c3c) !important;
        }

        .text-white {
        color: var(--white) !important;
        }

        /* Table Styles */
        .table-responsive {
        overflow-x: auto;
        border-radius: 8px;
        }

        .table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
        }

        .table th,
        .table td {
        padding: 1rem;
        vertical-align: middle;
        border-bottom: 1px solid var(--border-color);
        }

        .table th {
        background: var(--light-gray);
        font-weight: 600;
        color: var(--dark-gray);
        border-top: none;
        }

        .table-light th {
        background: linear-gradient(135deg, var(--light-gray), #f1f3f4);
        }

        .table-hover tbody tr:hover {
        background: rgba(255, 107, 53, 0.05);
        transition: background-color 0.2s ease;
        }

        .table-sm th,
        .table-sm td {
        padding: 0.5rem;
        }

        /* Badges */
        .badge {
        display: inline-flex;
        align-items: center;
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 20px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        }

        .bg-success {
        background: var(--success) !important;
        color: var(--white) !important;
        }

        .bg-warning {
        background: var(--warning) !important;
        color: var(--dark) !important;
        }

        .bg-info {
        background: var(--info) !important;
        color: var(--white) !important;
        }

        .bg-danger {
        background: var(--danger) !important;
        color: var(--white) !important;
        }

        .bg-secondary {
        background: var(--gray) !important;
        color: var(--white) !important;
        }

        .bg-light {
        background: var(--light-gray) !important;
        color: var(--dark) !important;
        }

        /* Modal Styles */
        .modal {
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1056;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        }

        .modal.fade.show {
        display: flex;
        align-items: center;
        justify-content: center;
        }

        .modal-dialog {
        max-width: 500px;
        width: 90%;
        margin: 1.75rem auto;
        }

        .modal-lg {
        max-width: 800px;
        }

        .modal-xl {
        max-width: 1140px;
        }

        .modal-content {
        background: var(--white);
        border-radius: 12px;
        box-shadow: var(--shadow-lg);
        overflow: hidden;
        }

        .modal-header {
        padding: 1.5rem;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        }

        .modal-header.bg-warning {
        background: linear-gradient(135deg, var(--warning), #ffca2c) !important;
        }

        .modal-header.bg-primary {
        background: linear-gradient(135deg, var(--primary-orange), var(--light-orange)) !important;
        }

        .modal-title {
        margin: 0;
        font-weight: 600;
        }

        .modal-body {
        padding: 1.5rem;
        max-height: 70vh;
        overflow-y: auto;
        }

        .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid var(--border-color);
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        }

        .btn-close,
        .btn-close-white {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        opacity: 0.7;
        padding: 0;
        width: 1.5rem;
        height: 1.5rem;
        display: flex;
        align-items: center;
        justify-content: center;
        }

        .btn-close:hover,
        .btn-close-white:hover {
        opacity: 1;
        }

        .btn-close::before,
        .btn-close-white::before {
        content: "×";
        font-size: 1.5rem;
        line-height: 1;
        }

        .btn-close-white {
        color: var(--white);
        }

        /* Form Styles */
        .form-label {
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: var(--dark-gray);
        display: block;
        }

        .form-select,
        .form-control {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        font-size: 1rem;
        transition: all 0.2s ease;
        background: var(--white);
        }

        .form-select:focus,
        .form-control:focus {
        outline: none;
        border-color: var(--primary-orange);
        box-shadow: 0 0 0 0.2rem rgba(255, 107, 53, 0.25);
        }

        .form-select-lg,
        .form-control-lg {
        padding: 1rem;
        font-size: 1.1rem;
        }

        .input-group {
        display: flex;
        position: relative;
        }

        .input-group-text {
        padding: 0.75rem;
        background: var(--light-gray);
        border: 1px solid var(--border-color);
        border-right: none;
        border-radius: 6px 0 0 6px;
        font-weight: 600;
        }

        .input-group .form-control {
        border-left: none;
        border-radius: 0 6px 6px 0;
        }

        .input-group-lg .input-group-text {
        padding: 1rem;
        font-size: 1.1rem;
        }

        .bg-success.input-group-text {
        background: var(--success) !important;
        color: var(--white) !important;
        border-color: var(--success);
        }

        .form-text {
        font-size: 0.875rem;
        color: var(--gray);
        margin-top: 0.25rem;
        }

        .bg-light {
        background: var(--light-gray) !important;
        }

        .border {
        border: 1px solid var(--border-color) !important;
        }

        .rounded {
        border-radius: 6px !important;
        }

        /* Grid System for Forms */
        .d-grid {
        display: grid;
        }

        /* Icons */
        .bi {
        vertical-align: -0.125em;
        }

        /* Display Classes */
        .display-1 {
        font-size: 6rem;
        font-weight: 300;
        line-height: 1.2;
        }

        .display-4 {
        font-size: 2.5rem;
        font-weight: 300;
        line-height: 1.2;
        }

        /* List Styles */
        ul {
        padding-left: 1.5rem;
        }

        li {
        margin-bottom: 0.25rem;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
        .main-content {
            padding: 10px;
        }
        
        .page-header {
            padding: 1.5rem;
            margin: -10px -10px 1.5rem -10px;
        }
        
        .page-header .d-flex {
            flex-direction: column;
            gap: 1rem;
        }
        
        .btn-lg {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        
        .modal-dialog {
            width: 95%;
            margin: 1rem auto;
        }
        
        .table-responsive {
            font-size: 0.875rem;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        .modal-body {
            padding: 1rem;
        }
        }

        @media (max-width: 576px) {
        .page-header h2 {
            font-size: 1.5rem;
        }
        
        .fs-1 {
            font-size: 2rem;
        }
        
        .table th,
        .table td {
            padding: 0.5rem;
        }
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
        width: 8px;
        height: 8px;
        }

        ::-webkit-scrollbar-track {
        background: var(--light-gray);
        border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
        background: var(--primary-orange);
        border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
        background: var(--dark-orange);
        }

        /* Print Styles */
        @media print {
        .btn,
        .modal,
        .alert-dismissible .btn-close {
            display: none !important;
        }
        
        .card {
            box-shadow: none;
            border: 1px solid var(--border-color);
        }
        
        .page-header {
            background: none !important;
            color: var(--dark) !important;
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
                <i class="bi bi-person-circle"></i><?= htmlspecialchars($current_user['username'] ?? 'Admin') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="admin_profile.php"><i class="bi bi-person me-2"></i>Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </header>

    <div class="main-content">
        <div class="content-area">
            <!-- Page Header -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-2"><i class="bi bi-arrow-left-right me-2"></i>Assignment Management System</h2>
                        <p class="mb-0 opacity-90">Manage sender-receiver assignments with rotation logic</p>
                        <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Users must complete assignments as receivers before they can be senders again, and vice versa</small>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($pending_withdrawals_count > 0): ?>
                        <button class="btn btn-danger btn-lg withdrawal-pulse" data-bs-toggle="modal" data-bs-target="#processWithdrawalsModal">
                            <i class="bi bi-cash-stack me-2"></i>Process Withdrawals (<?= $pending_withdrawals_count ?>)
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#manualAssignModal">
                            <i class="bi bi-plus-circle me-2"></i>New Assignment
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($info_message)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i><?php echo $info_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div><h6 class="card-title">Total Assignments</h6><h3 class="mb-0"><?php echo count($assignments); ?></h3></div>
                                <div class="align-self-center"><i class="bi bi-arrow-left-right fs-1"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div><h6 class="card-title">Pending Assignments</h6><h3 class="mb-0"><?php echo count(array_filter($assignments, fn($a) => $a['status'] === 'pending')); ?></h3></div>
                                <div class="align-self-center"><i class="bi bi-clock fs-1"></i></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($pending_withdrawals)): ?>
            <!-- Pending Withdrawals Alert -->
            <div class="alert alert-warning border-start border-warning border-4 mb-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                    <div>
                        <h6 class="mb-1">Pending Referral Withdrawals Detected!</h6>
                        <p class="mb-2">There are <?= $pending_withdrawals_count ?> withdrawal requests totaling ₱<?= number_format($pending_withdrawals_total, 2) ?> waiting to be processed into assignments.</p>
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#processWithdrawalsModal">
                            <i class="bi bi-gear me-1"></i>Process All Withdrawals
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <!-- All Assignments Table -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list-check me-2"></i>All Assignments</h5>
                            <small class="text-muted">Sender-receiver assignments with dates and status tracking</small>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($assignments)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr><th>Receiver</th><th>Sender</th><th>Amount(Expected Return)</th><th>Type</th><th>Status</th><th>Start Date</th><th>Maturity Date</th><th>Created</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($assignments as $assignment): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($assignment['receiver_name']); ?></strong>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($assignment['receiver_email']); ?></small>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($assignment['sender_name']); ?></strong></td>
                                                <td><span class="fw-bold text-success">Rs<?php echo number_format($assignment['amount'], 2); ?></span></td>
                                                <td>
                                                    <?php
                                                    $type = $assignment['assignment_type'] ?? 'investment';
                                                    $type_badge = $type === 'referral_withdrawal' ? 'bg-info' : 'bg-secondary';
                                                    $type_icon = $type === 'referral_withdrawal' ? 'bi-cash-stack' : 'bi-piggy-bank';
                                                    ?>
                                                    <span class="badge <?php echo $type_badge; ?>">
                                                        <i class="bi <?php echo $type_icon; ?> me-1"></i><?php echo ucfirst(str_replace('_', ' ', $type)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status = $assignment['status'];
                                                    $badge_class = match($status) {
                                                        'completed' => 'bg-success',
                                                        'pending' => 'bg-warning text-dark',
                                                        'active' => 'bg-info',
                                                        'cancelled' => 'bg-danger',
                                                        default => 'bg-secondary'
                                                    };
                                                    $status_icon = match($status) {
                                                        'completed' => 'bi-check-circle',
                                                        'pending' => 'bi-clock',
                                                        'active' => 'bi-play-circle',
                                                        'cancelled' => 'bi-x-circle',
                                                        default => 'bi-question-circle'
                                                    };
                                                    ?>
                                                    <span class="badge <?php echo $badge_class; ?>">
                                                        <i class="bi <?php echo $status_icon; ?> me-1"></i><?php echo ucfirst($status); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($assignment['start_date'])): ?>
                                                        <small><?php echo date('M j, Y', strtotime($assignment['start_date'])); ?></small>
                                                        <br><small class="text-muted"><?php echo date('g:i A', strtotime($assignment['start_date'])); ?></small>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($assignment['maturity_date'])): ?>
                                                        <small><?php echo date('M j, Y', strtotime($assignment['maturity_date'])); ?></small>
                                                        <br><small class="text-muted"><?php echo date('g:i A', strtotime($assignment['maturity_date'])); ?></small>
                                                        <?php
                                                        $days_left = ceil((strtotime($assignment['maturity_date']) - time()) / (60 * 60 * 24));
                                                        if ($days_left > 0 && $assignment['status'] === 'pending') {
                                                            echo "<br><small class='badge bg-light text-dark'>{$days_left} days left</small>";
                                                        } elseif ($days_left <= 0 && $assignment['status'] === 'pending') {
                                                            echo "<br><small class='badge bg-danger'>Overdue</small>";
                                                        }
                                                        ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <small><?php echo date('M j, Y', strtotime($assignment['created_at'])); ?></small>
                                                    <br><small class="text-muted"><?php echo date('g:i A', strtotime($assignment['created_at'])); ?></small>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="bi bi-inbox display-1 text-muted"></i>
                                    <h4 class="text-muted mt-3">No assignments yet</h4>
                                    <p class="text-muted">Create your first assignment by clicking the "New Assignment" button above.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Process Withdrawals Modal -->
    <div class="modal fade" id="processWithdrawalsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title"><i class="bi bi-cash-stack me-2"></i>Process Referral Withdrawals</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Process Overview:</strong> This will convert all pending referral withdrawals into sender-receiver assignments and remove them from the withdrawal queue.
                    </div>

                    <?php if (!empty($pending_withdrawals)): ?>
                    <h6 class="mb-3">Pending Withdrawals (<?= count($pending_withdrawals) ?>)</h6>
                    <div class="table-responsive mb-3" style="max-height: 300px;">
                        <table class="table table-sm">
                            <thead class="table-light">
                                <tr><th>User</th><th>Amount</th><th>Date</th><th>Reference</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_withdrawals as $withdrawal): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($withdrawal['fullname']) ?></strong>
                                        <br><small class="text-muted"><?= htmlspecialchars($withdrawal['email']) ?></small>
                                    </td>
                                    <td><span class="fw-bold text-success">₱<?= number_format($withdrawal['withdrawal_amount'], 2) ?></span></td>
                                    <td><small><?= date('M j, Y g:i A', strtotime($withdrawal['created_at'])) ?></small></td>
                                    <td><small class="text-muted"><?= htmlspecialchars($withdrawal['transaction_reference'] ?? 'N/A') ?></small></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Important Notes:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Each withdrawal will be converted to a new assignment with "Pending" status</li>
                            <li>System will automatically find available senders based on rotation rules</li>
                            <li>Start date will be today, maturity date will be 7 days from now</li>
                            <li>Withdrawals will be permanently removed from the referral_withdrawals table</li>
                            <li>This action cannot be undone</li>
                        </ul>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="process_withdrawals">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="bi bi-gear me-2"></i>Process All <?= $pending_withdrawals_count ?> Withdrawal(s)
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-check-circle display-4 text-success"></i>
                        <h5 class="mt-3 text-success">No Pending Withdrawals</h5>
                        <p class="text-muted">All referral withdrawals have been processed.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Manual Assignment Modal - Multi-Sender Support -->
<div class="modal fade" id="manualAssignModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-people-fill me-2"></i>Create Multi-Sender Assignment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="assign_manual_sender">
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Multi-Sender Assignment:</strong> Select one receiver and multiple senders with packages. The total sender amount must meet or exceed the receiver's investment requirement.
                    </div>

                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Rotation Rule:</strong> Only eligible users will appear based on rotation rules and package availability.
                    </div>
                    
                    <!-- Receiver Selection -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <label for="receiver_id" class="form-label fs-5">
                                <i class="bi bi-arrow-down-circle me-2 text-success"></i>Select Receiver
                            </label>
                            <select class="form-select form-select-lg" name="receiver_id" required id="receiver_select">
                                <option value="">Choose a receiver...</option>
                            </select>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>Select the person who will receive the investment amount
                                <br><small class="text-success">💡 Only shows users eligible to receive based on their investment requirements</small>
                            </div>
                        </div>
                    </div>

                    <!-- Sender Selection -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <label class="form-label fs-5">
                                <i class="bi bi-arrow-up-circle me-2 text-primary"></i>Select Senders (Multiple Selection)
                            </label>
                            <div class="border rounded p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                <div id="sender_container">
                                    <div class="text-center text-muted py-4">
                                        <i class="bi bi-person-plus fs-1"></i>
                                        <p class="mb-0">Please select a receiver first to see available senders</p>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>Select multiple senders whose package amounts will be combined
                                <br><small class="text-primary">💡 Only shows users with active packages who are eligible to send</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Amount Display -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <label for="amount" class="form-label fs-5">
                                <i class="bi bi-wallet me-2 text-success"></i>Total Assignment Amount
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-success text-white">Rs</span>
                                <input type="number" class="form-control" name="amount" placeholder="0.00" 
                                       step="0.01" min="0.01" required readonly>
                            </div>
                            <div class="form-text">
                                <strong>Auto-calculated based on selected senders:</strong>
                                <br>• Total amount = Sum of all selected sender packages
                                <br>• Must meet or exceed receiver's investment requirement
                                <br>• Assignment will be created with "Pending" status
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" disabled>
                        <i class="bi bi-check-circle me-1"></i>Create Assignment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Logout Modal -->
    <div class="modal fade" id="logoutModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Logout</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to logout?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <a href="../auth/logout.php" class="btn btn-danger">Logout</a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle sidebar for mobile
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('show');
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileToggle = document.querySelector('.mobile-toggle');
            
            if (window.innerWidth <= 768 && !sidebar.contains(event.target) && !mobileToggle.contains(event.target)) {
                sidebar.classList.remove('show');
            }
        });

        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('show');
            }
        });

        // Auto-dismiss alerts after 8 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                new bootstrap.Alert(alert).close();
            });
        }, 8000);

// FIXED Assignment Modal - Allow flexible sender combinations
document.addEventListener('DOMContentLoaded', function() {
    const receiverSelect = document.getElementById('receiver_select');
    const senderContainer = document.getElementById('sender_container');
    const amountInput = document.querySelector('input[name="amount"]');
    const allUsers = <?php echo json_encode($all_users); ?>;
    
    let selectedSenders = [];
    let receiverInfo = null;
    
    // Populate receiver dropdown
    function populateReceiverSelect() {
        receiverSelect.innerHTML = `<option value="">Choose a receiver...</option>`;
        
        allUsers.forEach(user => {
            if (!user.can_be_receiver) return;
            
            // For receivers - their NEED is always expected_return
            const expectedReturn = parseFloat(user.expected_return) || 0;
            const investmentAmount = parseFloat(user.investment_amount) || 0;
            const isWithdrawal = user.is_withdrawal || false;
            
            if (expectedReturn <= 0 && !isWithdrawal) return;
            
            // For investment users, always use their full expected_return as target amount
            const targetAmount = isWithdrawal ? (parseFloat(user.remaining_needed) || 0) : expectedReturn;
            
            let displayText;
            if (isWithdrawal) {
                displayText = `${user.fullname} - WITHDRAWAL: Rs${targetAmount.toLocaleString()}`;
            } else {
                displayText = `${user.fullname} - Need: Rs${targetAmount.toLocaleString()} (Invested: Rs${investmentAmount.toLocaleString()})`;
            }
            
            const option = new Option(displayText, user.id);
            Object.assign(option.dataset, { 
                name: user.fullname, 
                isWithdrawal, 
                targetAmount,
                expectedReturn,
                investmentAmount
            });
            option.style.cssText = isWithdrawal ? 'background:#fff3cd;font-weight:bold;color:#856404' : 'background:#d1ecf1;color:#0c5460';
            
            receiverSelect.appendChild(option);
        });
    }
    
    // Populate sender list
    function populateSenderList(excludeId = null) {
        if (!senderContainer) return;
        
        const availableSenders = allUsers.filter(user => 
            user.can_be_sender && user.id != excludeId && parseFloat(user.investment_amount || 0) > 0
        ).sort((a, b) => parseFloat(b.investment_amount) - parseFloat(a.investment_amount));
        
        if (!availableSenders.length) {
            senderContainer.innerHTML = `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>No available senders found.</div>`;
            return;
        }
        
        senderContainer.innerHTML = availableSenders.map(user => {
            const packageAmount = parseFloat(user.investment_amount || 0);
            const expectedReturn = parseFloat(user.expected_return || 0);
            
            return `
                <div class="sender-item">
                    <div class="form-check">
                        <input class="form-check-input sender-checkbox" type="checkbox" 
                               value="${user.id}" id="sender_${user.id}"
                               data-package-amount="${packageAmount}" 
                               data-expected-return="${expectedReturn}"
                               data-sender-name="${user.fullname}">
                        <label class="form-check-label w-100" for="sender_${user.id}">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <strong>${user.fullname}</strong>
                                    <div><i class="bi bi-box me-1"></i>Can Send: <span class="text-primary fw-bold">Rs${packageAmount.toLocaleString()}</span> (Package: ${user.package_name || 'Package'})</div>
                                    <div><i class="bi bi-arrow-up-circle me-1"></i>Will Receive Later: <span class="text-success fw-bold">Rs${expectedReturn.toLocaleString()}</span></div>
                                </div>
                                <span class="badge bg-primary">Rs${packageAmount.toLocaleString()}</span>
                            </div>
                        </label>
                    </div>
                </div>
            `;
        }).join('');
        
        senderContainer.querySelectorAll('.sender-checkbox').forEach(cb => 
            cb.addEventListener('change', updateSelectedSenders)
        );
        
        if (receiverInfo) suggestOptimalSenders();
    }
    
    // Suggest optimal senders
    function suggestOptimalSenders() {
        const targetAmount = parseFloat(receiverInfo.targetAmount);
        const checkboxes = Array.from(senderContainer.querySelectorAll('.sender-checkbox'));
        
        // Look for exact match first
        const exactMatch = checkboxes.find(cb => 
            parseFloat(cb.dataset.packageAmount) === targetAmount
        );
        
        if (exactMatch) {
            senderContainer.insertAdjacentHTML('afterbegin', `
                <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><strong>Perfect Match!</strong> ${exactMatch.dataset.senderName} package (Rs${parseFloat(exactMatch.dataset.packageAmount).toLocaleString()}) exactly matches receiver's need (Rs${targetAmount.toLocaleString()}).</div>
            `);
        } else {
            // Find single sender that can cover full amount
            const singleCoverage = checkboxes.find(cb => 
                parseFloat(cb.dataset.packageAmount) >= targetAmount
            );
            
            // Find combinations of multiple senders
            const combinations = [];
            
            // Try combinations of 2 senders
            for (let i = 0; i < checkboxes.length; i++) {
                for (let j = i + 1; j < checkboxes.length; j++) {
                    const total = parseFloat(checkboxes[i].dataset.packageAmount) + parseFloat(checkboxes[j].dataset.packageAmount);
                    if (total >= targetAmount) {
                        combinations.push({
                            senders: [checkboxes[i], checkboxes[j]],
                            total: total,
                            count: 2
                        });
                    }
                }
            }
            
            // Try combinations of 3 senders
            for (let i = 0; i < checkboxes.length; i++) {
                for (let j = i + 1; j < checkboxes.length; j++) {
                    for (let k = j + 1; k < checkboxes.length; k++) {
                        const total = parseFloat(checkboxes[i].dataset.packageAmount) + 
                                    parseFloat(checkboxes[j].dataset.packageAmount) + 
                                    parseFloat(checkboxes[k].dataset.packageAmount);
                        if (total >= targetAmount) {
                            combinations.push({
                                senders: [checkboxes[i], checkboxes[j], checkboxes[k]],
                                total: total,
                                count: 3
                            });
                        }
                    }
                }
            }
            
            let suggestions = [];
            
            if (singleCoverage) {
                suggestions.push(`<strong>Option 1:</strong> ${singleCoverage.dataset.senderName} (Rs${parseFloat(singleCoverage.dataset.packageAmount).toLocaleString()}) - Single sender`);
            }
            
            if (combinations.length > 0) {
                // Sort by total amount ascending, then by sender count ascending
                combinations.sort((a, b) => {
                    if (a.total !== b.total) return a.total - b.total;
                    return a.count - b.count;
                });
                
                // Show best 2-3 combinations
                const topCombos = combinations.slice(0, 3);
                topCombos.forEach((combo, index) => {
                    const senderNames = combo.senders.map(s => 
                        `${s.dataset.senderName} (Rs${parseFloat(s.dataset.packageAmount).toLocaleString()})`
                    ).join(' + ');
                    suggestions.push(`<strong>Option ${suggestions.length + 1}:</strong> ${senderNames} = Rs${combo.total.toLocaleString()}`);
                });
            }
            
            if (suggestions.length > 0) {
                senderContainer.insertAdjacentHTML('afterbegin', `
                    <div class="alert alert-info">
                        <i class="bi bi-lightbulb me-2"></i><strong>Suggestions to cover Rs${targetAmount.toLocaleString()}:</strong><br>
                        ${suggestions.join('<br>')}
                        <div class="mt-2"><small class="text-muted">You can select any combination of senders whose total packages can cover the receiver's need</small></div>
                    </div>
                `);
            }
        }
    }
    
    // FIXED: Update selected senders - Allow any combination that meets the need
    function updateSelectedSenders() {
        const checkbox = event.target;
        const packageAmount = parseFloat(checkbox.dataset.packageAmount);
        const targetAmount = parseFloat(receiverInfo?.targetAmount || 0);
        
        if (checkbox.checked) {
            const currentTotal = selectedSenders.reduce((sum, s) => sum + s.packageAmount, 0);
            const newTotal = currentTotal + packageAmount;
            
            // FIXED: More flexible validation rules
            const maxReasonableLimit = targetAmount * 3.0; // Allow up to 3x the target for flexibility
            
            if (newTotal > maxReasonableLimit) {
                checkbox.checked = false;
                alert(`Cannot select ${checkbox.dataset.senderName}.\nTotal sender packages would be too excessive.\nReceiver needs: Rs${targetAmount.toLocaleString()}\nCurrent total: Rs${currentTotal.toLocaleString()}\nTrying to add: Rs${packageAmount.toLocaleString()}\nNew total would be: Rs${newTotal.toLocaleString()}\nReasonable limit: Rs${maxReasonableLimit.toLocaleString()}`);
                return;
            }
        }
        
        // Update selected senders array
        selectedSenders = [];
        senderContainer.querySelectorAll('.sender-checkbox').forEach(cb => {
            cb.closest('.sender-item').classList.toggle('selected', cb.checked);
            if (cb.checked) {
                selectedSenders.push({
                    id: cb.value,
                    name: cb.dataset.senderName,
                    packageAmount: parseFloat(cb.dataset.packageAmount),
                    expectedReturn: parseFloat(cb.dataset.expectedReturn)
                });
            }
        });
        
        updateCheckboxStates();
        updateAmountCalculation();
    }
    
    // FIXED: Update checkbox states - Only disable if would exceed reasonable limit
    function updateCheckboxStates() {
        if (!receiverInfo) return;
        
        const targetAmount = parseFloat(receiverInfo.targetAmount);
        const currentTotal = selectedSenders.reduce((sum, s) => sum + s.packageAmount, 0);
        const maxReasonableLimit = targetAmount * 3.0;
        
        senderContainer.querySelectorAll('.sender-checkbox').forEach(cb => {
            if (!cb.checked) {
                const packageAmount = parseFloat(cb.dataset.packageAmount);
                const newTotal = currentTotal + packageAmount;
                
                let shouldDisable = false;
                let disableReason = '';
                
                // Only disable if adding would exceed the reasonable limit
                if (newTotal > maxReasonableLimit) {
                    shouldDisable = true;
                    disableReason = `Adding this package would exceed reasonable limit (Rs${maxReasonableLimit.toLocaleString()})`;
                }
                
                cb.disabled = shouldDisable;
                const senderItem = cb.closest('.sender-item');
                senderItem.classList.toggle('disabled', shouldDisable);
                senderItem.title = disableReason;
            }
        });
    }
    
    // Update amount calculation
    function updateAmountCalculation() {
        const existingHelp = amountInput.parentNode.querySelector('.amount-help');
        if (existingHelp) existingHelp.remove();
        
        if (!receiverInfo || !selectedSenders.length) {
            amountInput.value = '';
            amountInput.removeAttribute('max');
            return;
        }
        
        const totalSenderPackages = selectedSenders.reduce((sum, s) => sum + s.packageAmount, 0);
        const receiverNeed = parseFloat(receiverInfo.targetAmount);
        
        // Assignment amount is always the receiver's NEED
        const assignmentAmount = receiverNeed;
        
        // Check if senders can cover the receiver's need
        const canCoverNeed = totalSenderPackages >= receiverNeed;
        const isExactMatch = Math.abs(totalSenderPackages - receiverNeed) < 0.01;
        const isSingleSender = selectedSenders.length === 1;
        const shortage = canCoverNeed ? 0 : (receiverNeed - totalSenderPackages);
        
        amountInput.value = assignmentAmount.toFixed(2);
        amountInput.setAttribute('max', assignmentAmount.toFixed(2));
        
        let statusClass, statusIcon, statusText;
        
        if (canCoverNeed) {
            if (isExactMatch) {
                statusClass = 'alert-success';
                statusIcon = 'bi-check-circle';
                statusText = 'PERFECT MATCH - READY TO ASSIGN';
            } else if (isSingleSender) {
                statusClass = 'alert-success';
                statusIcon = 'bi-check-circle';
                statusText = 'SINGLE SENDER COVERS FULL AMOUNT - READY TO ASSIGN';
            } else {
                statusClass = 'alert-success';
                statusIcon = 'bi-check-circle';
                statusText = 'MULTIPLE SENDERS COVER AMOUNT - READY TO ASSIGN';
            }
        } else {
            statusClass = 'alert-warning';
            statusIcon = 'bi-exclamation-triangle';
            statusText = `NEED MORE: Rs${shortage.toLocaleString()} (${((totalSenderPackages/receiverNeed)*100).toFixed(1)}% covered)`;
        }
        
        const helpText = document.createElement('div');
        helpText.className = 'form-text amount-help mt-3';
        helpText.innerHTML = `
            <div class="alert ${statusClass} p-3">
                <div class="d-flex align-items-center mb-2">
                    <i class="${statusIcon} me-2"></i><strong>${statusText}</strong>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Receiver:</strong> ${receiverInfo.name}<br>
                        <strong>Amount Needed:</strong> Rs${receiverNeed.toLocaleString()}<br>
                        <strong>Assignment Amount:</strong> <span class="text-success">Rs${assignmentAmount.toLocaleString()}</span><br>
                        <strong>Selected Senders:</strong> ${selectedSenders.length}
                    </div>
                    <div class="col-md-6">
                        <strong>Sender Packages Total:</strong> <span class="text-primary">Rs${totalSenderPackages.toLocaleString()}</span><br>
                        <strong>Coverage:</strong> <span class="${canCoverNeed ? 'text-success' : 'text-warning'}">${canCoverNeed ? '✓ Sufficient' : '⚠ Insufficient'}</span><br>
                        ${shortage > 0 ? `<strong class="text-warning">Still Need:</strong> <span class="text-danger">Rs${shortage.toLocaleString()}</span>` : ''}
                        ${totalSenderPackages > receiverNeed ? '<strong class="text-info">Note:</strong> <small>Sender packages exceed receiver need (OK)</small>' : ''}
                    </div>
                </div>
                ${selectedSenders.length ? `<hr><strong>Selected Senders:</strong><ul class="mb-0">${selectedSenders.map(s => `<li>${s.name}: Package Rs${s.packageAmount.toLocaleString()} → Will get Rs${s.expectedReturn.toLocaleString()}</li>`).join('')}</ul>` : ''}
                <div class="mt-2">
                    <small class="text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        You can select any combination of senders. Total packages must be ≥ Rs${receiverNeed.toLocaleString()} to cover receiver's need.
                    </small>
                </div>
            </div>
        `;
        
        amountInput.parentNode.appendChild(helpText);
        
        // Update submit button
        const submitBtn = document.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = !canCoverNeed;
            submitBtn.className = `btn ${canCoverNeed ? 'btn-success' : 'btn-secondary'}`;
            submitBtn.innerHTML = `<i class="bi bi-${canCoverNeed ? 'check-circle' : 'exclamation-triangle'} me-1"></i>${canCoverNeed ? 'Create Assignment' : 'Need Sufficient Coverage'}`;
        }
    }
    
    // Handle receiver change
    function handleReceiverChange() {
        const selectedId = receiverSelect.value;
        selectedSenders = [];
        
        if (!selectedId) {
            receiverInfo = null;
            senderContainer.innerHTML = '';
            amountInput.value = '';
            amountInput.removeAttribute('max');
            return;
        }
        
        const option = receiverSelect.options[receiverSelect.selectedIndex];
        receiverInfo = {
            id: selectedId,
            name: option.dataset.name,
            isWithdrawal: option.dataset.isWithdrawal === 'true',
            targetAmount: parseFloat(option.dataset.targetAmount) || 0,
            expectedReturn: parseFloat(option.dataset.expectedReturn) || 0,
            investmentAmount: parseFloat(option.dataset.investmentAmount) || 0
        };
        
        populateSenderList(selectedId);
        updateAmountCalculation();
    }
    
    // Initialize
    populateReceiverSelect();
    receiverSelect.addEventListener('change', handleReceiverChange);
    
    // Amount input validation
    amountInput.addEventListener('input', function() {
        const max = parseFloat(this.getAttribute('max'));
        const current = parseFloat(this.value);
        
        if (current <= 0) {
            this.setCustomValidity('Amount must be greater than 0');
        } else if (max && Math.abs(current - max) > 0.01) {
            this.setCustomValidity(`Amount must exactly match receiver's need: Rs${max.toLocaleString()}`);
        } else {
            this.setCustomValidity('');
        }
    });
    
    // Form submission validation
    document.querySelector('form').addEventListener('submit', function(e) {
        if (!selectedSenders.length || !receiverInfo) {
            e.preventDefault();
            alert('Please select receiver and at least one sender.');
            return false;
        }
        
        const totalSenderPackages = selectedSenders.reduce((sum, s) => sum + s.packageAmount, 0);
        const receiverNeed = parseFloat(receiverInfo.targetAmount);
        
        if (totalSenderPackages < receiverNeed) {
            e.preventDefault();
            const shortage = receiverNeed - totalSenderPackages;
            alert(`Insufficient coverage!\nReceiver needs: Rs${receiverNeed.toLocaleString()}\nSender packages total: Rs${totalSenderPackages.toLocaleString()}\nStill need: Rs${shortage.toLocaleString()}\n\nPlease select additional senders or different combinations to cover the receiver's full need.`);
            return false;
        }
        
        // Confirmation for large over-coverage
        const overage = totalSenderPackages - receiverNeed;
        if (overage > receiverNeed) { // Over 100% excess
            const confirmed = confirm(`Large over-coverage detected!\nReceiver needs: Rs${receiverNeed.toLocaleString()}\nSender packages total: Rs${totalSenderPackages.toLocaleString()}\nOver-coverage: Rs${overage.toLocaleString()}\n\nThis means senders are providing ${((overage/receiverNeed)*100).toFixed(0)}% more than needed.\nContinue with this assignment?`);
            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        }
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_senders';
        input.value = JSON.stringify(selectedSenders.map(s => s.id));
        this.appendChild(input);
    });
    
    // Reset modal
    document.getElementById('manualAssignModal').addEventListener('hidden.bs.modal', function() {
        document.querySelector('form').reset();
        selectedSenders = [];
        receiverInfo = null;
        populateReceiverSelect();
        senderContainer.innerHTML = '';
        amountInput.removeAttribute('max');
        amountInput.setCustomValidity('');
        
        const existingHelp = amountInput.parentNode.querySelector('.amount-help');
        if (existingHelp) existingHelp.remove();
        
        const submitBtn = document.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.className = 'btn btn-primary';
            submitBtn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Create Assignment';
        }
    });
});
    </script>
</body>
</html>