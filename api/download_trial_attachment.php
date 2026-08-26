<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    die('Unauthorized');
}

$proposal_id = (int)($_GET['id'] ?? 0);
$file_param = $_GET['file'] ?? '';

if ($proposal_id <= 0 || empty($file_param)) {
    http_response_code(400);
    die('Invalid request');
}

// Ensure the user is a freelancer and fetch their freelancer_id
if ($user['role'] !== 'freelancer') {
    http_response_code(403);
    die('Forbidden: Only freelancers can download trial task attachments.');
}

$stmt = $conn->prepare("SELECT id FROM freelancers WHERE user_id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fl) {
    http_response_code(403);
    die('Forbidden: Freelancer profile not found.');
}
$freelancer_id = $fl['id'];

// Verify the proposal belongs to this freelancer
$stmt = $conn->prepare("
    SELECT attachment 
    FROM proposal_projects 
    WHERE id = ? AND freelancer_id = ?
");
$stmt->bind_param('ii', $proposal_id, $freelancer_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proposal) {
    http_response_code(404);
    die('Trial Task not found or unauthorized.');
}

// Allow if the file_param is part of the attachment string (handles single or comma-separated)
$attachments = array_map('trim', explode(',', $proposal['attachment']));
if (!in_array($file_param, $attachments)) {
    http_response_code(404);
    die('File not associated with this Trial Task.');
}

$file_path = __DIR__ . '/../uploads/attachments/' . basename($file_param);
if (!file_exists($file_path)) {
    http_response_code(404);
    die('Attached file not found on server.');
}

$mime = mime_content_type($file_path);
if (!$mime) {
    $mime = 'application/octet-stream';
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($file_param) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit;
