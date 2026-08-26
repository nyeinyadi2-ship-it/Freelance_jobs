<?php
header('Content-Type: text/plain');
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once __DIR__ . '/../config/db.php';

// Simulate values
$assignment_id = 1; // You can just use dummy values or find a real one
$company_id = 1;
$job_id = 1;
$fl_user_id = 2;
$freelancer_id = 1;
$user_id = 1; // Company
$amount = 100.00;
$pmt_method = 'wallet';
$now = date('Y-m-d H:i:s');
$desc = "Test Payment";
$null_ms = null;

try {
    $conn->begin_transaction();

    $stmt_pmt = $conn->prepare("INSERT INTO payments (assignment_id, company_id, freelancer_id, amount, payment_method, status, paid_at) VALUES (?, ?, ?, ?, ?, 'paid', ?)");
    if (!$stmt_pmt) throw new Exception("Prepare failed: " . $conn->error);
    $stmt_pmt->bind_param('iiidss', $assignment_id, $company_id, $freelancer_id, $amount, $pmt_method, $now);
    if (!$stmt_pmt->execute()) throw new Exception("Execute pmt failed: " . $stmt_pmt->error);
    $stmt_pmt->close();
    echo "payments insert OK\n";

    $stmt_wt = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, 'completed', ?)");
    if (!$stmt_wt) throw new Exception("Prepare wt failed: " . $conn->error);
    $stmt_wt->bind_param('iiiiisdss', $fl_user_id, $user_id, $fl_user_id, $job_id, $null_ms, $desc, $amount, $pmt_method, $now);
    if (!$stmt_wt->execute()) throw new Exception("Execute wt failed: " . $stmt_wt->error);
    $stmt_wt->close();
    echo "wallet_transactions insert OK\n";

    $conn->rollback();
    echo "All good, rolled back.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    $conn->rollback();
}
?>
