<?php
require 'config/db.php';

// Give user 34 enough balance
$conn->query("UPDATE users SET available_balance = 5000000 WHERE id = 34");

// Simulate funding milestone 54
$milestone_id = 54;
$job_id = 52;
$company_id = 15;
$user_id = 34;

$stmt = $conn->prepare("
    SELECT m.id, m.amount, m.status, m.freelancer_id, m.job_id, j.company_id, j.title, 
           (SELECT status FROM assignments a WHERE a.job_id = m.job_id AND a.freelancer_id = m.freelancer_id AND a.status != 'completed' LIMIT 1) as assignment_status
    FROM milestones m 
    JOIN jobs j ON m.job_id = j.id 
    WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'draft'
");
$stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
$stmt->execute();
$ms = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($ms) {
    if (empty($ms['freelancer_id'])) {
        echo "Error: No freelancer assigned.\n";
    } elseif (!in_array($ms['assignment_status'], ['assigned', 'working', 'submitted', 'payment_pending'])) {
        echo "Error: Invalid assignment.\n";
    } else {
        $amount = (float) $ms['amount'];
        
        $conn->begin_transaction();
        
        $stmt_bal = $conn->prepare("UPDATE users SET available_balance = available_balance - ?, reserved_balance = reserved_balance + ? WHERE id = ? AND available_balance >= ?");
        $stmt_bal->bind_param('ddid', $amount, $amount, $user_id, $amount);
        $stmt_bal->execute();
        if ($stmt_bal->affected_rows === 0) {
            echo "Error: Insufficient balance.\n";
            $conn->rollback();
        } else {
            $up = $conn->prepare("UPDATE milestones SET status = 'funded' WHERE id = ?");
            $up->bind_param('i', $milestone_id);
            $up->execute();
            
            $conn->commit();
            echo "Success: Funded $amount MMK.\n";
            
            $u = $conn->query("SELECT available_balance, reserved_balance FROM users WHERE id = $user_id")->fetch_assoc();
            echo "Available: {$u['available_balance']}, Reserved: {$u['reserved_balance']}\n";
        }
    }
} else {
    echo "Error: Milestone not found or not draft.\n";
}
