<?php
require_once __DIR__ . '/../config/db.php';

echo "Migrating existing payments to wallet_transactions...\n";

// Fetch all paid payments
$stmt = $conn->prepare("SELECT p.*, j.company_id as job_company_id, j.id as job_id FROM payments p LEFT JOIN assignments a ON p.assignment_id = a.id LEFT JOIN milestones m ON p.milestone_id = m.id LEFT JOIN jobs j ON (a.job_id = j.id OR m.job_id = j.id) WHERE p.status = 'paid'");
$stmt->execute();
$res = $stmt->get_result();

$inserted = 0;
while ($row = $res->fetch_assoc()) {
    $company_user_id = 0;
    // get user_id of company
    $cid = $row['company_id'] ?: $row['job_company_id'];
    if ($cid) {
        $cs = $conn->prepare("SELECT user_id FROM companies WHERE id = ?");
        $cs->bind_param('i', $cid);
        $cs->execute();
        $cr = $cs->get_result()->fetch_assoc();
        $company_user_id = $cr['user_id'] ?? 0;
        $cs->close();
    }

    $freelancer_user_id = 0;
    if ($row['freelancer_id']) {
        $fs = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
        $fs->bind_param('i', $row['freelancer_id']);
        $fs->execute();
        $fr = $fs->get_result()->fetch_assoc();
        $freelancer_user_id = $fr['user_id'] ?? 0;
        $fs->close();
    }
    
    $job_id = $row['job_id'] ?? null;
    $milestone_id = $row['milestone_id'] ?? null;
    $amount = $row['amount'];
    $method = $row['payment_method'] ?? 'platform_fund';
    $ref = $row['transaction_reference'] ?? '';
    $created_at = $row['paid_at'] ?? $row['created_at'];

    if ($company_user_id && $freelancer_user_id) {
        // check if exists
        $chk = $conn->prepare("SELECT id FROM wallet_transactions WHERE sender_id = ? AND receiver_id = ? AND amount = ? AND created_at = ? AND type = 'payment'");
        $chk->bind_param('iids', $company_user_id, $freelancer_user_id, $amount, $created_at);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, transaction_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, ?, 'completed', ?)");
            $desc = 'Legacy Payment';
            $ins->bind_param('iiiisidsss', $company_user_id, $company_user_id, $freelancer_user_id, $job_id, $milestone_id, $desc, $amount, $method, $ref, $created_at);
            $ins->execute();
            $ins->close();
            $inserted++;
        }
        $chk->close();
    }
}
$stmt->close();
echo "Migrated $inserted legacy payments into wallet_transactions.\n";
