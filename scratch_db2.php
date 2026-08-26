<?php
require_once __DIR__ . '/config/db.php';
$stmt = $conn->prepare("
    SELECT wt.*, u.username as freelancer_name, u.profile_image, j.title as job_title, m.title as ms_title,
           p.id as payment_id, p.transaction_slip
    FROM wallet_transactions wt
    LEFT JOIN users u ON wt.receiver_id = u.id
    LEFT JOIN jobs j ON wt.job_id = j.id
    LEFT JOIN milestones m ON wt.milestone_id = m.id
    LEFT JOIN payments p ON p.paid_at = wt.created_at AND p.amount = wt.amount
    WHERE wt.user_id = 34 OR wt.sender_id = 34 
    ORDER BY wt.created_at DESC
");
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
echo json_encode($rows, JSON_PRETTY_PRINT);
