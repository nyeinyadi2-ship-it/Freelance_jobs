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

// Verify ownership
$stmt = $conn->prepare("SELECT id, attachment FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found or you do not have permission to delete it.');
    redirect('company/manage_jobs.php');
}

// Safely handle jobs with applications/assignments to prevent breaking existing data
$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM job_applications WHERE job_id = ?) as apps,
        (SELECT COUNT(*) FROM assignments WHERE job_id = ?) as assignments
");
$stmt->bind_param('ii', $job_id, $job_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($counts['apps'] > 0 || $counts['assignments'] > 0) {
    set_flash('error', 'Cannot delete this job because it has existing applications or assignments. Please close the job instead to preserve records.');
    redirect('company/manage_jobs.php');
}

// Proceed to delete the job
$stmt = $conn->prepare("DELETE FROM jobs WHERE id = ?");
$stmt->bind_param('i', $job_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    // Delete attachment if exists
    if (!empty($job['attachment'])) {
        $file_path = __DIR__ . '/../uploads/attachments/' . $job['attachment'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }
    set_flash('success', 'Job deleted successfully.');
} else {
    set_flash('error', 'Failed to delete job.');
}
$stmt->close();

redirect('company/manage_jobs.php');
