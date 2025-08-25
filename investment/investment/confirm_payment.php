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
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input: ' . json_last_error_msg()]);
    exit();
}

if (!$input || !isset($input['action']) || $input['action'] !== 'confirm_payment') {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request action']);
    exit();
}

$boardId = intval($input['board_id']);
$senderId = intval($input['sender_id']);
$senderIndex = intval($input['sender_index']);

try {
    $conn->begin_transaction();
    
    // Get board details - Modified to handle concurrent confirmations
    $boardQuery = $conn->prepare("
        SELECT * FROM boards 
        WHERE id = ? AND receiver_id = ? AND assignment_type = 'investment'
        AND (status = 'pending' OR (status = 'completed' AND is_multi_sender = 1))
    ");
    $boardQuery->bind_param("ii", $boardId, $userId);
    $boardQuery->execute();
    $board = $boardQuery->get_result()->fetch_assoc();
    
    if (!$board) {
        throw new Exception('Board record not found or already fully processed');
    }
    
    // For multi-sender with group_id, check if this specific sender is already confirmed
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
        $boardId = $specificBoard['id'];
    }
    
    // Get current sender IDs - use group_id logic for multi-sender
    $senderIds = [];
    if ($board['is_multi_sender'] == 1 && !empty($board['group_id'])) {
        // For multi-sender, get all senders in the same group_id
        $groupSendersQuery = $conn->prepare("
            SELECT DISTINCT sender_id FROM boards 
            WHERE group_id = ? AND receiver_id = ? AND status = 'pending'
        ");
        $groupSendersQuery->bind_param("si", $board['group_id'], $userId);
        $groupSendersQuery->execute();
        $groupSendersResult = $groupSendersQuery->get_result();
        
        while ($row = $groupSendersResult->fetch_assoc()) {
            if ($row['sender_id']) {
                $senderIds[] = intval($row['sender_id']);
            }
        }
    } else {
        // Single sender case
        $senderIds = [$board['sender_id']];
    }
    
    // Check if sender exists in the board
    if (!in_array($senderId, $senderIds)) {
        throw new Exception('Sender not found in this board');
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
    $receivedAmount = floatval($board['amount']); // This is what the receiver gets from the board
    $paymentAmount = floatval($board['amount']); // Keep existing logic for compatibility
    $senderPackageAmount = floatval($board['sender_package_amount']);
    $senderExpectedReturn = floatval($board['sender_expected_return']);
    $profitAmount = $senderExpectedReturn - $senderPackageAmount; // Calculate profit
    $packageName = $board['package_name'];
    
    // Check if this sender payment is already confirmed
    $transactionRef = "BOARD_" . $boardId . "_SENDER_" . $senderId;
    $checkQuery = $conn->prepare("SELECT id FROM payment_history WHERE board_id = ? AND sender_user_id = ?");
    $checkQuery->bind_param("ii", $boardId, $senderId);
    $checkQuery->execute();
    $existing = $checkQuery->get_result()->fetch_assoc();
    
    if ($existing) {
        // Payment already confirmed, return success with current status
        $remainingBoardsQuery = $conn->prepare("
            SELECT COUNT(*) as pending_count FROM boards 
            WHERE " . ($board['group_id'] ? "group_id = ? AND receiver_id = ?" : "receiver_id = ?") . " AND status = 'pending'
        ");
        
        if ($board['group_id']) {
            $remainingBoardsQuery->bind_param("si", $board['group_id'], $userId);
        } else {
            $remainingBoardsQuery->bind_param("i", $userId);
        }
        
        $remainingBoardsQuery->execute();
        $remainingResult = $remainingBoardsQuery->get_result()->fetch_assoc();
        $remainingSenderCount = $remainingResult['pending_count'];
        
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
    
    // Insert payment history with board connection and all sender details INCLUDING received_amount
    $insertPayment = $conn->prepare("
        INSERT INTO payment_history (
            user_id, board_id, payment_type, amount, received_amount, original_investment, 
            profit_amount, package_name, payment_status, transaction_reference, 
            notes, sender_name, sender_email, sender_phone, sender_user_id,
            is_multi_sender, group_id
        ) VALUES (?, ?, 'investment_return', ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $processingNotes = "Investment return payment from " . $sender['fullname'] . " - Package: " . $packageName . 
                      ", Investment: ₨" . number_format($senderPackageAmount) . 
                      ", Expected Return: ₨" . number_format($senderExpectedReturn) . 
                      ", Profit: ₨" . number_format($profitAmount) .
                      ", Received Amount: ₨" . number_format($receivedAmount);
    
    $isMultiSender = $board['is_multi_sender'];
    $groupId = $board['group_id'];
    
    $insertPayment->bind_param("iiddddsssssssis", 
        $userId, $boardId, $paymentAmount, $receivedAmount, $senderPackageAmount, 
        $profitAmount, $packageName, $transactionRef, 
        $processingNotes, $sender['fullname'], $sender['email'], 
        $sender['phone'], $senderId, $isMultiSender, $groupId
    );
    
    if (!$insertPayment->execute()) {
        throw new Exception('Failed to record payment history for sender ID: ' . $senderId);
    }
    
    // Now handle board status updates
    $confirmedSenderId = $senderId;
    
    if ($board['is_multi_sender'] == 1 && !empty($board['group_id'])) {
        // For multi-sender with group_id, mark this specific board record as completed
        $updateThisBoard = $conn->prepare("
            UPDATE boards 
            SET status = 'completed', updated_at = NOW() 
            WHERE id = ? AND status = 'pending'
        ");
        $updateThisBoard->bind_param("i", $boardId);
        $updateResult = $updateThisBoard->execute();
        
        if ($conn->affected_rows == 0) {
            // This board was already processed by another request
            ob_clean();
            echo json_encode([
                'success' => true, 
                'message' => 'Payment already confirmed by another request',
                'already_confirmed' => true,
                'confirmed_sender_id' => $senderId
            ]);
            exit();
        }
        
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
            // Payment history is preserved with board_id reference
            $deleteGroupBoards = $conn->prepare("
                DELETE FROM boards 
                WHERE group_id = ? AND receiver_id = ? AND status = 'completed'
            ");
            $deleteGroupBoards->bind_param("si", $board['group_id'], $userId);
            $deleteGroupBoards->execute();
            $allConfirmed = true;
            
            // Delete receiver's investment when all payments confirmed
            $deleteReceiverInvestment = $conn->prepare("
                DELETE FROM user_investments 
                WHERE user_id = ? AND investment_status = 'pending'
                ORDER BY created_at DESC LIMIT 1
            ");
            $deleteReceiverInvestment->bind_param("i", $userId);
            $deleteReceiverInvestment->execute();
        } else {
            $allConfirmed = false;
        }
        
        $originalSenderCount = count($senderIds);
    } else {
        // Single sender - delete board immediately after payment recorded
        $deleteBoard = $conn->prepare("DELETE FROM boards WHERE id = ? AND status = 'pending'");
        $deleteBoard->bind_param("i", $boardId);
        $deleteBoard->execute();
        
        if ($conn->affected_rows > 0) {
            $allConfirmed = true;
            
            // Delete receiver's investment
            $deleteReceiverInvestment = $conn->prepare("
                DELETE FROM user_investments 
                WHERE user_id = ? AND investment_status = 'pending'
                ORDER BY created_at DESC LIMIT 1
            ");
            $deleteReceiverInvestment->bind_param("i", $userId);
            $deleteReceiverInvestment->execute();
        } else {
            // Board was already deleted by another request
            $allConfirmed = true;
        }
        
        $remainingSenderCount = 0;
        $originalSenderCount = 1;
    }
    
    $conn->commit();
    
    // Clean any unexpected output before sending JSON
    ob_clean();
    
    echo json_encode([
        'success' => true, 
        'message' => $allConfirmed ? 'All payments confirmed. Board records moved to payment history.' : 'Payment confirmed. Waiting for remaining senders.',
        'all_confirmed' => $allConfirmed,
        'remaining_senders' => $remainingSenderCount,
        'total_original_senders' => $originalSenderCount,
        'confirmed_count' => $originalSenderCount - $remainingSenderCount,
        'confirmed_sender_id' => $confirmedSenderId,
        'payment_amount' => $paymentAmount,
        'received_amount' => $receivedAmount,
        'original_investment' => $senderPackageAmount,
        'profit_amount' => $profitAmount,
        'expected_return' => $senderExpectedReturn,
        'debug' => [
            'board_id' => $boardId,
            'sender_id' => $senderId,
            'user_id' => $userId,
            'board_deleted' => $allConfirmed,
            'transaction_ref' => $transactionRef,
            'group_id' => $groupId,
            'is_multi_sender' => $isMultiSender,
            'payment_recorded_with_board_connection' => true,
            'received_amount_from_board' => $receivedAmount
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