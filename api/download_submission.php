<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    die('Unauthorized');
}

$milestone_id = (int)($_GET['milestone_id'] ?? 0);
$submission_id = (int)($_GET['submission_id'] ?? 0);
if ($milestone_id <= 0 && $submission_id <= 0) {
    http_response_code(400);
    die('Invalid request');
}

$file_name = '';
$company_user_id = 0;
$freelancer_user_id = 0;

if ($milestone_id > 0) {
    $stmt = $conn->prepare("
        SELECT m.submission_file, c.user_id AS company_user_id, f.user_id AS freelancer_user_id
        FROM milestones m
        JOIN jobs j ON m.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        LEFT JOIN freelancers f ON m.freelancer_id = f.id
        WHERE m.id = ?
    ");
    $stmt->bind_param('i', $milestone_id);
    $stmt->execute();
    $ms = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ms) {
        http_response_code(404);
        die('Milestone not found');
    }
    
    $file_name = $ms['submission_file'];
    $company_user_id = $ms['company_user_id'];
    $freelancer_user_id = $ms['freelancer_user_id'];
} else {
    $stmt = $conn->prepare("
        SELECT s.file_path, c.user_id AS company_user_id, f.user_id AS freelancer_user_id
        FROM submissions s
        JOIN assignments a ON s.assignment_id = a.id
        JOIN jobs j ON a.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        JOIN freelancers f ON s.freelancer_id = f.id
        WHERE s.id = ?
    ");
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sub) {
        http_response_code(404);
        die('Submission not found');
    }

    $file_name = $sub['file_path'];
    $company_user_id = $sub['company_user_id'];
    $freelancer_user_id = $sub['freelancer_user_id'];
}

if ($company_user_id != $user['user_id'] && $freelancer_user_id != $user['user_id']) {
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        die('Forbidden');
    }
}

if (empty($file_name)) {
    http_response_code(404);
    die('No file attached');
}

$clean_name = basename($file_name);
$upload_dir = realpath(__DIR__ . '/../uploads/attachments');
$file_path = $upload_dir ? $upload_dir . DIRECTORY_SEPARATOR . $clean_name : __DIR__ . '/../uploads/attachments/' . $clean_name;

if (!file_exists($file_path) || ($upload_dir && strpos(realpath($file_path), $upload_dir) !== 0)) {
    http_response_code(404);
    die('Attached file not found on server');
}

$mime = mime_content_type($file_path);
if (!$mime) {
    $mime = 'application/octet-stream';
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($file_path));
readfile($file_path);
exit;
