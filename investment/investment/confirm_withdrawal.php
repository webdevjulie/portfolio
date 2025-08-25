<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output
ini_set('log_errors', 1);

// Set JSON header first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Buffer output to catch any unexpected output
ob_start();

try {
    include '../includes/db.php';
    include '../includes/session.php';
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Include error: ' . $e->getMessage()]);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized access - No session']);
    exit();
}

$userId = $_SESSION['user_id'];

// Get and validate input
$rawInput = file_get_contents('php://input');

// Debug logging
error_log("Raw input: " . $rawInput);

// Handle both JSON and form data
if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg(), 'raw_input' => $rawInput]);
        exit();
    }
} else {
    // Fallback to POST data
    $input = $_POST;
}

// Debug logging
error_log("Parsed input: " . print_r($input, true));

if (!$input || !isset($input['action'])) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Missing action parameter',
        'received_input' => $input,
        'raw_input' => $rawInput,
        'post_data' => $_POST
    ]);
    exit();
}

$allowedActions = ['confirm_payment', 'confirm_referral_withdrawal'];
if (!in_array($input['action'], $allowedActions)) {
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request action: ' . $input['action'] . '. Expected: ' . implode(' or ', $allowedActions)
    ]);
    exit();
}

$action = $input['action'];
$assignmentType = ($action === 'confirm_payment') ? 'investment' : 'referral_withdrawal';

$boardId = isset($input['board_id']) ? intval($input['board_id']) : 0;
$senderId = isset($input['sender_id']) ? intval($input['sender_id']) : 0;
$senderIndex = isset($input['sender_index']) ? intval($input['sender_index']) : 0;

// Validate required parameters
if ($boardId <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid or missing board_id']);
    exit();
}

if ($senderId <= 0) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid or missing sender_id']);
    exit();
}

try {
    $conn->begin_transaction();
    
    // FIRST: Check if this specific sender payment is already confirmed
    $transactionRef = "BOARD_" . $boardId . "_SENDER_" . $senderId;
    
    if ($assignmentType === 'investment') {
        $checkQuery = $conn->prepare("SELECT id FROM payment_history WHERE board_id = ? AND sender_user_id = ?");
        $checkQuery->bind_param("ii", $boardId, $senderId);
    } else {
        $checkQuery = $conn->prepare("SELECT id FROM withdrawal_history WHERE board_id = ? AND sender_id = ?");
        $checkQuery->bind_param("ii", $boardId, $senderId);
    }
    
    $checkQuery->execute();
    $existing = $checkQuery->get_result()->fetch_assoc();
    
    if ($existing) {
        // Payment already confirmed, get current status and return
        $boardQuery = $conn->prepare("
            SELECT * FROM boards 
            WHERE id = ? AND receiver_id = ? AND assignment_type = ?
        ");
        $boardQuery->bind_param("iis", $boardId, $userId, $assignmentType);
        $boardQuery->execute();
        $board = $boardQuery->get_result()->fetch_assoc();
        
        $remainingSenderCount = 0;
        if ($board && $board['is_multi_sender'] == 1 && !empty($board['group_id'])) {
            $remainingBoardsQuery = $conn->prepare("
                SELECT COUNT(*) as pending_count FROM boards 
                WHERE group_id = ? AND receiver_id = ? AND status = 'pending'
            ");
            $remainingBoardsQuery->bind_param("si", $board['group_id'], $userId);
            $remainingBoardsQuery->execute();
            $remainingResult = $remainingBoardsQuery->get_result()->fetch_assoc();
            $remainingSenderCount = $remainingResult['pending_count'];
        }
        
        $conn->commit();
        ob_clean();
        echo json_encode([
            'success' => true, 
            'message' => 'Payment already confirmed',
            'all_confirmed' => $remainingSenderCount == 0,
            'remaining_senders' => $remainingSenderCount,
            'already_confirmed' => true,
            'confirmed_sender_id' => $senderId
        ]);
        exit();
    }
    
    // Get board details - Modified to handle concurrent confirmations
    $boardQuery = $conn->prepare("
        SELECT * FROM boards 
        WHERE id = ? AND receiver_id = ? AND assignment_type = ?
        AND (status = 'pending' OR (status = 'completed' AND is_multi_sender = 1))
    ");
    $boardQuery->bind_param("iis", $boardId, $userId, $assignmentType);
    $boardQuery->execute();
    $board = $boardQuery->get_result()->fetch_assoc();
    
    if (!$board) {
        throw new Exception('Board record not found or already fully processed');
    }
    
    // For multi-sender with group_id, get the specific board record for this sender
    $actualBoardId = $boardId;
    if ($board['is_multi_sender'] == 1 && !empty($board['group_id'])) {
        // Check if this specific sender's board record exists and is pending
        $specificBoardQuery = $conn->prepare("
            SELECT * FROM boards 
            WHERE group_id = ? AND receiver_id = ? AND sender_id = ? AND status = 'pending'
        ");
        $specificBoardQuery->bind_param("sii", $board['group_id'], $userId, $senderId);
        $specificBoardQuery->execute();
        $specificBoard = $specificBoardQuery->get_result()->fetch_assoc();
        
        if (!$specificBoard) {
            throw new Exception('This sender payment has already been confirmed or sender not found in group');
        }
        
        // Use the specific board record for processing
        $board = $specificBoard;
        $actualBoardId = $specificBoard['id'];
    }
    
    // Verify sender exists in this board
    if ($board['sender_id'] != $senderId) {
        throw new Exception('Sender ID mismatch in board record');
    }
    
    // Get sender details (including email and phone for payment history)
    $senderQuery = $conn->prepare("SELECT fullname, email, phone FROM users WHERE id = ?");
    $senderQuery->bind_param("i", $senderId);
    $senderQuery->execute();
    $sender = $senderQuery->get_result()->fetch_assoc();
    
    if (!$sender) {
        throw new Exception('Sender user not found');
    }
    
    // Use board data for payment amounts
    $paymentAmount = floatval($board['amount']);
    $senderPackageAmount = floatval($board['sender_package_amount']);
    $senderExpectedReturn = floatval($board['sender_expected_return']);
    $profitAmount = $senderExpectedReturn - $senderPackageAmount;
    $packageName = $board['package_name'];
    $isMultiSender = $board['is_multi_sender'];
    $groupId = $board['group_id'];
    
    // Insert payment/withdrawal history with board connection
    if ($assignmentType === 'investment') {
        $insertPayment = $conn->prepare("
            INSERT INTO payment_history (
                user_id, board_id, payment_type, amount, original_investment, 
                profit_amount, package_name, payment_status, transaction_reference, 
                notes, sender_name, sender_email, sender_phone, sender_user_id,
                is_multi_sender, group_id
            ) VALUES (?, ?, 'investment_return', ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $processingNotes = "Investment return payment from " . $sender['fullname'] . " - Package: " . $packageName . 
                          ", Investment: ₨" . number_format($senderPackageAmount) . 
                          ", Expected Return: ₨" . number_format($senderExpectedReturn) . 
                          ", Profit: ₨" . number_format($profitAmount);
        
        $insertPayment->bind_param("iidddsssssssis", 
            $userId, $actualBoardId, $paymentAmount, $senderPackageAmount, 
            $profitAmount, $packageName, $transactionRef, 
            $processingNotes, $sender['fullname'], $sender['email'], 
            $sender['phone'], $senderId, $isMultiSender, $groupId
        );
    } else {
        // For referral withdrawals, insert into withdrawal_history
        $insertPayment = $conn->prepare("
            INSERT INTO withdrawal_history (
                board_id, receiver_id, sender_id, sender_name, amount, 
                assignment_type, original_created_at, start_date, maturity_date, 
                reference_id, processing_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $processingNotes = "Referral withdrawal from " . $sender['fullname'] . " - Amount: ₨" . number_format($paymentAmount);
        
        $insertPayment->bind_param("iiisdssssss", 
            $actualBoardId, $userId, $senderId, $sender['fullname'], $paymentAmount,
            $assignmentType, $board['created_at'], $board['start_date'], 
            $board['maturity_date'], $board['reference_id'], $processingNotes
        );
    }
    
    if (!$insertPayment->execute()) {
        throw new Exception('Failed to record payment history for sender ID: ' . $senderId);
    }
    
    // Now handle board status updates
    $allConfirmed = false;
    $remainingSenderCount = 0;
    $originalSenderCount = 1;
    
    if ($board['is_multi_sender'] == 1 && !empty($board['group_id'])) {
        // For multi-sender with group_id, mark this specific board record as completed
        $updateThisBoard = $conn->prepare("
            UPDATE boards 
            SET status = 'completed', updated_at = NOW() 
            WHERE id = ? AND status = 'pending'
        ");
        $updateThisBoard->bind_param("i", $actualBoardId);
        $updateThisBoard->execute();
        
        if ($conn->affected_rows == 0) {
            throw new Exception('Board record was already processed by another request');
        }
        
        // Get total original sender count for this group
        $totalSendersQuery = $conn->prepare("
            SELECT COUNT(*) as total_count FROM boards 
            WHERE group_id = ? AND receiver_id = ?
        ");
        $totalSendersQuery->bind_param("si", $board['group_id'], $userId);
        $totalSendersQuery->execute();
        $totalResult = $totalSendersQuery->get_result()->fetch_assoc();
        $originalSenderCount = $totalResult['total_count'];
        
        // Check if all boards in this group are completed
        $remainingBoardsQuery = $conn->prepare("
            SELECT COUNT(*) as pending_count FROM boards 
            WHERE group_id = ? AND receiver_id = ? AND status = 'pending'
        ");
        $remainingBoardsQuery->bind_param("si", $board['group_id'], $userId);
        $remainingBoardsQuery->execute();
        $remainingResult = $remainingBoardsQuery->get_result()->fetch_assoc();
        $remainingSenderCount = $remainingResult['pending_count'];
        
        if ($remainingSenderCount == 0) {
            // All senders confirmed - delete all completed boards in this group
            $deleteGroupBoards = $conn->prepare("
                DELETE FROM boards 
                WHERE group_id = ? AND receiver_id = ? AND status = 'completed'
            ");
            $deleteGroupBoards->bind_param("si", $board['group_id'], $userId);
            $deleteGroupBoards->execute();
            $allConfirmed = true;
        }
    } else {
        // Single sender - delete board immediately after payment recorded
        $deleteBoard = $conn->prepare("DELETE FROM boards WHERE id = ? AND status = 'pending'");
        $deleteBoard->bind_param("i", $actualBoardId);
        $deleteBoard->execute();
        
        if ($conn->affected_rows > 0) {
            $allConfirmed = true;
            $remainingSenderCount = 0;
        } else {
            throw new Exception('Board record was already processed');
        }
    }
    
    // Handle completion actions when all payments are confirmed
    if ($allConfirmed) {
        if ($assignmentType === 'investment') {
            // Delete receiver's investment only when all senders have confirmed payment
            $deleteInvestmentQuery = $conn->prepare("
                DELETE FROM user_investments 
                WHERE user_id = ? AND investment_status = 'pending'
                ORDER BY created_at DESC LIMIT 1
            ");
            $deleteInvestmentQuery->bind_param("i", $userId);
            $deleteInvestmentQuery->execute();
        } else {
            // For referral withdrawals, mark the referral_withdrawal as completed
            $updateReferralQuery = $conn->prepare("
                UPDATE referral_withdrawals 
                SET withdrawal_status = 'completed', processed_at = NOW()
                WHERE user_id = ? AND withdrawal_status = 'pending' AND withdrawal_amount = ?
                ORDER BY created_at DESC LIMIT 1
            ");
            $updateReferralQuery->bind_param("id", $userId, $paymentAmount);
            $updateReferralQuery->execute();
        }
    }
    
    $conn->commit();
    
    // Clean any unexpected output before sending JSON
    ob_clean();
    
    $responseMessage = $allConfirmed 
        ? ($assignmentType === 'investment' 
            ? 'All payments confirmed. Investment completed.' 
            : 'All referral withdrawals confirmed. Withdrawal completed.')
        : ($assignmentType === 'investment' 
            ? 'Payment confirmed. Waiting for remaining senders.' 
            : 'Referral withdrawal confirmed. Waiting for remaining senders.');
    
    echo json_encode([
        'success' => true, 
        'message' => $responseMessage,
        'all_confirmed' => $allConfirmed,
        'remaining_senders' => $remainingSenderCount,
        'total_original_senders' => $originalSenderCount,
        'confirmed_count' => $originalSenderCount - $remainingSenderCount,
        'confirmed_sender_id' => $senderId,
        'payment_amount' => $paymentAmount,
        'assignment_type' => $assignmentType,
        'original_investment' => $senderPackageAmount,
        'profit_amount' => $profitAmount,
        'expected_return' => $senderExpectedReturn,
        'record_updated' => $allConfirmed,
        'debug' => [
            'board_id' => $actualBoardId,
            'original_board_id' => $boardId,
            'sender_id' => $senderId,
            'user_id' => $userId,
            'board_deleted' => $allConfirmed,
            'transaction_ref' => $transactionRef,
            'group_id' => $groupId,
            'is_multi_sender' => $isMultiSender,
            'assignment_type' => $assignmentType,
            'history_table_used' => $assignmentType === 'investment' ? 'payment_history' : 'withdrawal_history'
        ]
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Error $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'PHP error: ' . $e->getMessage()]);
}
?>