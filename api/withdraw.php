<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = current_user();
if (!$user || $user['role'] !== 'freelancer') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$_POST['csrf_token'] = $input['csrf_token'] ?? '';

if (!verify_csrf()) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (($input['action'] ?? '') !== 'request_withdrawal') {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit;
}

$amount = (float) ($input['amount'] ?? 0);
if ($amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid amount']);
    exit;
}

// Get freelancer info
$stmt = $conn->prepare("SELECT id, payment_method, payment_account_name, payment_account_number, payment_bank_name FROM freelancers WHERE user_id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fl) {
    echo json_encode(['success' => false, 'error' => 'Freelancer profile not found']);
    exit;
}

if (empty($fl['payment_method'])) {
    echo json_encode(['success' => false, 'error' => 'Please configure your payment method in your Profile first.']);
    exit;
}

$details = ($fl['payment_account_name'] ?? '') . ' | ' . ($fl['payment_account_number'] ?? '');
if ($fl['payment_method'] === 'bank_transfer' && !empty($fl['payment_bank_name'])) {
    $details .= ' | ' . $fl['payment_bank_name'];
}

$conn->begin_transaction();
try {
    // Deduct from available_balance if enough funds
    $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance - ? WHERE id = ? AND available_balance >= ?");
    $stmt->bind_param('did', $amount, $user['user_id'], $amount);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new Exception("Insufficient available balance for this withdrawal.");
    }

    // Create pending withdrawal request
    $stmt = $conn->prepare("INSERT INTO withdraw_requests (freelancer_id, amount, status, payment_method, payment_details) VALUES (?, ?, 'pending', ?, ?)");
    $stmt->bind_param('idss', $fl['id'], $amount, $fl['payment_method'], $details);
    $stmt->execute();
    $withdraw_id = $stmt->insert_id;
    $stmt->close();

    // Insert wallet transaction
    $tx_id = uniqid('tx_withdraw_');
    $type = 'withdrawal';
    $stmt = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, payment_method, transaction_id, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->bind_param('idsss', $user['user_id'], $amount, $type, $fl['payment_method'], $tx_id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
