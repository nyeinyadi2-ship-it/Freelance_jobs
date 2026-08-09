<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/upload.php';

require_role('company');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    set_flash('error', 'Invalid request.');
    redirect('company/manage_jobs.php');
}

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_POST['job_id'] ?? 0);
$freelancer_id = (int) ($_POST['freelancer_id'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$instructions = trim($_POST['instructions'] ?? '');
$deadline = $_POST['deadline'] ?? '';

if (!$company_id || $job_id <= 0 || $freelancer_id <= 0 || empty($title) || empty($description) || empty($deadline)) {
    set_flash('error', 'Missing required fields.');
    redirect("company/view_applications.php?id=$job_id");
}

// Verify company owns job
$stmt = $conn->prepare("SELECT id, title FROM jobs WHERE id = ? AND company_id = ?");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('company/manage_jobs.php');
}

// Handle file upload
$attachment_path = null;
if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $error_msg = null;
    $uploaded_filename = upload_attachment($_FILES['attachment'], 10485760, $error_msg);
    if ($uploaded_filename !== null) {
        $attachment_path = $uploaded_filename;
    } else {
        set_flash('error', 'Failed to upload attachment: ' . $error_msg);
        redirect("company/view_applications.php?id=$job_id");
    }
}

$stmt = $conn->prepare("
    INSERT INTO proposal_projects (job_id, company_id, freelancer_id, title, description, instructions, attachment, deadline, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$stmt->bind_param('iiisssss', $job_id, $company_id, $freelancer_id, $title, $description, $instructions, $attachment_path, $deadline);

if ($stmt->execute()) {
    $proposal_id = $conn->insert_id;
    // Notify freelancer
    $stmt2 = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
    $stmt2->bind_param('i', $freelancer_id);
    $stmt2->execute();
    $fl_user = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if ($fl_user) {
        create_notification($conn, (int)$fl_user['user_id'], 'system', "You have received a Test Assignment for '{$job['title']}'.", 'freelancer/view_proposal.php?id=' . $proposal_id);
    }

    set_flash('success', 'Test Assignment sent successfully.');
} else {
    set_flash('error', 'Could not send test assignment.');
}
$stmt->close();

redirect("company/view_applications.php?id=$job_id");
