<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/upload.php';

require_role('freelancer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    set_flash('error', 'Invalid request.');
    redirect('freelancer/dashboard.php');
}

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);
$proposal_id = (int) ($_POST['proposal_id'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

if (!$freelancer_id || $proposal_id <= 0) {
    set_flash('error', 'Invalid input.');
    redirect('freelancer/dashboard.php');
}

$stmt = $conn->prepare("SELECT p.id, p.company_id, p.status, p.deadline, j.title FROM proposal_projects p JOIN jobs j ON p.job_id = j.id WHERE p.id = ? AND p.freelancer_id = ? AND p.status = 'in_progress'");
$stmt->bind_param('ii', $proposal_id, $freelancer_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($proposal && !empty($proposal['deadline'])) {
    if (new DateTime($proposal['deadline']) <= new DateTime()) {
        set_flash('error', 'Submission blocked: Deadline has passed.');
        redirect("freelancer/view_proposal.php?id=$proposal_id");
    }
}

if (!$proposal) {
    set_flash('error', 'Post not found or not in progress.');
    redirect("freelancer/view_proposal.php?id=$proposal_id");
}

// Handle file upload
$file_path = null;
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $upload_err = null;
    $uploaded_filename = upload_attachment($_FILES['file'], 500 * 1024 * 1024, $upload_err);
    if ($uploaded_filename !== null) {
        $file_path = 'uploads/attachments/' . $uploaded_filename;
    } else {
        set_flash('error', 'Failed to upload file: ' . $upload_err);
        redirect("freelancer/view_proposal.php?id=$proposal_id");
    }
} else {
    set_flash('error', 'Please upload your completed work before submitting the Trial Task.');
    redirect("freelancer/view_proposal.php?id=$proposal_id");
}

$stmt = $conn->prepare("
    INSERT INTO proposal_project_submissions (proposal_project_id, freelancer_id, file, comment)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param('iiss', $proposal_id, $freelancer_id, $file_path, $comment);

if ($stmt->execute()) {
    // Update proposal status to 'submitted'
    $conn->query("UPDATE proposal_projects SET status = 'submitted' WHERE id = $proposal_id");

    // Notify company
    $stmt2 = $conn->prepare("SELECT user_id FROM companies WHERE id = ?");
    $stmt2->bind_param('i', $proposal['company_id']);
    $stmt2->execute();
    $company_user = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if ($company_user) {
        $fl_name = $user['username'];
        create_notification($conn, (int)$company_user['user_id'], 'system', "Freelancer submitted their work for '{$proposal['title']}'.", 'company/review_proposal.php?id=' . $proposal_id);
    }

    set_flash('success', 'Your trial task has been submitted successfully.');
} else {
    set_flash('error', 'Could not submit trial task.');
}
$stmt->close();

redirect("freelancer/view_proposal.php?id=$proposal_id");
