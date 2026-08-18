<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    die('Unauthorized');
}

$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    http_response_code(400);
    die('Invalid request');
}

// Check authorization (either the paying company or the receiving freelancer)
$stmt = $conn->prepare("
    SELECT p.transaction_slip, c.user_id AS company_user_id, f.user_id AS freelancer_user_id
    FROM payments p
    LEFT JOIN companies c ON p.company_id = c.id
    LEFT JOIN freelancers f ON p.freelancer_id = f.id
    WHERE p.id = ?
");
$stmt->bind_param('i', $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    http_response_code(404);
    die('Payment not found');
}

if ($payment['company_user_id'] != $user['user_id'] && $payment['freelancer_user_id'] != $user['user_id']) {
    http_response_code(403);
    die('Forbidden');
}

if (empty($payment['transaction_slip'])) {
    http_response_code(404);
    die('No transaction slip attached');
}

$slip_path = __DIR__ . '/../uploads/slips/' . $payment['transaction_slip'];
if (!file_exists($slip_path)) {
    http_response_code(404);
    die('Slip file not found on server');
}

$mime = mime_content_type($slip_path);
if (!$mime) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($slip_path));
header('Cache-Control: private, max-age=86400'); // Cache for 24 hours
readfile($slip_path);
exit;
