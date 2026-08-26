<?php
require_once __DIR__ . '/config/db.php';

$conn->begin_transaction();
try {
    // 1. Find all users who had 'funding' deductions and sum the amounts
    $stmt = $conn->query("SELECT user_id, SUM(amount) as total_refund FROM wallet_transactions WHERE type = 'funding' AND sender_id = user_id GROUP BY user_id");
    
    while ($row = $stmt->fetch_assoc()) {
        $user_id = (int)$row['user_id'];
        $refund = (float)$row['total_refund'];
        
        if ($refund > 0) {
            $up = $conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
            $up->bind_param('di', $refund, $user_id);
            $up->execute();
            $up->close();
            echo "Refunded $refund to user_id $user_id\n";
        }
    }
    
    // 2. Set the amount to 0 for all existing 'funding' transactions
    $conn->query("UPDATE wallet_transactions SET amount = 0 WHERE type = 'funding'");
    
    $conn->commit();
    echo "Done.\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "Error: " . $e->getMessage() . "\n";
}
