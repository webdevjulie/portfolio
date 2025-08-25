<?php
include 'includes/db.php';
include 'includes/session.php';

if (isset($_POST['confirm'])) {
    $boardId = intval($_POST['board_id']);

    // Confirm board
    $conn->query("UPDATE boards SET status = 'confirmed', confirmed_at = NOW() WHERE id = $boardId");

    // Start new 7-day cycle for the sender
    $senderId = $conn->query("SELECT sender_id FROM boards WHERE id = $boardId")->fetch_assoc()['sender_id'];
    $now = date('Y-m-d');
    $maturity = date('Y-m-d', strtotime('+7 days'));

    $conn->query("UPDATE users SET investment_status = 'active', start_date = '$now', maturity_date = '$maturity' WHERE id = $senderId");

    header("Location: dashboard.php");
    exit();
}
?>
