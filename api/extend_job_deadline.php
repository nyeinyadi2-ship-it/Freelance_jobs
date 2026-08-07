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
$new_deadline = $_POST['new_deadline'] ?? '';

if (!$company_id || $job_id <= 0 || empty($new_deadline)) {
    set_flash('error', 'Invalid input.');
    redirect('company/manage_jobs.php');
}

// Check if the job belongs to this company and is expired
$stmt = $conn->prepare("SELECT status FROM jobs WHERE id = ? AND company_id = ? AND status = 'expired'");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found or not expired.');
    redirect('company/manage_jobs.php');
}

// Update the deadline and reactivate the job (approved = active)
$stmt = $conn->prepare("UPDATE jobs SET deadline = ?, status = 'approved' WHERE id = ?");
$stmt->bind_param('si', $new_deadline, $job_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    set_flash('success', 'Job deadline extended successfully and job is active again.');
} else {
    set_flash('error', 'Failed to extend job deadline.');
}
$stmt->close();

redirect('company/manage_jobs.php');
