<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$redirect_to = trim($_POST['redirect_to'] ?? '');

function get_dest_url(string $fallback, string $custom): string {
    if (!empty($custom) && (strpos($custom, 'company/') === 0 || strpos($custom, 'index.php') === 0)) {
        return $custom;
    }
    return $fallback;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    set_flash('error', 'Invalid request method.');
    redirect(get_dest_url('company/manage_jobs.php', $redirect_to));
}

if (!verify_csrf()) {
    set_flash('error', 'Invalid or expired CSRF token.');
    redirect(get_dest_url('company/manage_jobs.php', $redirect_to));
}

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_POST['job_id'] ?? 0);

$fallback_url = $job_id > 0 ? 'company/view_job.php?id=' . $job_id : 'company/manage_jobs.php';
$dest_url = get_dest_url($fallback_url, $redirect_to);

if (!$company_id || $job_id <= 0) {
    set_flash('error', 'Invalid input.');
    redirect($dest_url);
}

// Check if the job belongs to this company
$stmt = $conn->prepare("SELECT id, status FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found or permission denied.');
    redirect('company/manage_jobs.php');
}

if ($job['status'] === 'closed') {
    set_flash('error', 'This job is already closed.');
    redirect($dest_url);
}

// Close the job safely without deleting any related records
$stmt = $conn->prepare("UPDATE jobs SET status = 'closed' WHERE id = ?");
$stmt->bind_param('i', $job_id);
$stmt->execute();

if ($stmt->affected_rows > 0 || $job['status'] !== 'closed') {
    set_flash('success', 'Job has been closed successfully. All related records have been preserved.');
} else {
    set_flash('error', 'No changes were made to the job status.');
}
$stmt->close();

redirect($dest_url);
