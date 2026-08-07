<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request method.');
    redirect('company/manage_jobs.php');
}

if (!verify_csrf()) {
    set_flash('error', 'Invalid CSRF token.');
    redirect('company/manage_jobs.php');
}

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_POST['job_id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', 'Invalid input.');
    redirect('company/manage_jobs.php');
}

// Check if the job belongs to this company
$stmt = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('company/manage_jobs.php');
}

// Close the job
$stmt = $conn->prepare("UPDATE jobs SET status = 'closed' WHERE id = ?");
$stmt->bind_param('i', $job_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    set_flash('success', 'Job closed successfully.');
} else {
    set_flash('error', 'Failed to close job.');
}
$stmt->close();

redirect('company/manage_jobs.php');
