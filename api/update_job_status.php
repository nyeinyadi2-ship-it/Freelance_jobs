<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    set_flash('error', 'Invalid request.');
    redirect('index.php');
}

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

$job_id = (int) ($_POST['job_id'] ?? 0);
$status = $_POST['status'] ?? '';
$allowed_statuses = ['in_review', 'closed', 'cancelled'];

if ($job_id <= 0 || !in_array($status, $allowed_statuses, true)) {
    set_flash('error', 'Invalid request parameters.');
    redirect('index.php');
}

try {
    // Make sure job belongs to this company
    $chk = $conn->prepare("SELECT id, status, title FROM jobs WHERE id = ? AND company_id = ?");
    $chk->bind_param('ii', $job_id, $company_id);
    $chk->execute();
    $job = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$job) {
        set_flash('error', 'Job not found or permission denied.');
        redirect('company/manage_jobs.php');
    }

    if ($job['status'] === 'closed' || $job['status'] === 'cancelled' || $job['status'] === 'completed') {
        set_flash('error', 'Cannot change status of a completed, closed or cancelled job.');
        redirect('company/view_job.php?id=' . $job_id);
    }
    
    // Only allow marking as in_review if it is currently open
    if ($status === 'in_review' && $job['status'] !== 'open') {
        set_flash('error', 'Only open jobs can be marked as in review.');
        redirect('company/view_job.php?id=' . $job_id);
    }

    $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $job_id);
    $stmt->execute();
    
    if ($stmt->affected_rows > 0) {
        $msg = 'Job status updated successfully.';
        if ($status === 'in_review') $msg = 'Job marked as In Review.';
        elseif ($status === 'closed') $msg = 'Job has been closed.';
        elseif ($status === 'cancelled') $msg = 'Project has been cancelled.';
        set_flash('success', $msg);
    } else {
        set_flash('error', 'No changes were made.');
    }
    $stmt->close();
    
} catch (Exception $e) {
    set_flash('error', 'An error occurred while updating job status.');
}

redirect('company/view_job.php?id=' . $job_id);
