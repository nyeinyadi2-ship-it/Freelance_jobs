<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('freelancer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf()) {
    set_flash('error', 'Invalid request.');
    redirect('freelancer/dashboard.php');
}

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);
$proposal_id = (int) ($_POST['proposal_id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$freelancer_id || $proposal_id <= 0 || !in_array($action, ['accept', 'reject'])) {
    set_flash('error', 'Invalid input.');
    redirect('freelancer/dashboard.php');
}

$stmt = $conn->prepare("SELECT p.id, p.company_id, j.title FROM proposal_projects p JOIN jobs j ON p.job_id = j.id WHERE p.id = ? AND p.freelancer_id = ? AND p.status = 'pending'");
$stmt->bind_param('ii', $proposal_id, $freelancer_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proposal) {
    set_flash('error', 'Proposal project not found or already responded.');
    redirect('freelancer/dashboard.php');
}

$new_status = ($action === 'accept') ? 'accepted' : 'rejected';

$stmt = $conn->prepare("UPDATE proposal_projects SET status = ? WHERE id = ?");
$stmt->bind_param('si', $new_status, $proposal_id);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    // Notify company
    $stmt2 = $conn->prepare("SELECT user_id FROM companies WHERE id = ?");
    $stmt2->bind_param('i', $proposal['company_id']);
    $stmt2->execute();
    $company_user = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();

    if ($company_user) {
        $fl_name = $user['username']; // or fetch freelancer name
        $msg = $action === 'accept' ? "Freelancer accepted the Test Assignment for '{$proposal['title']}'." : "Freelancer declined the Test Assignment for '{$proposal['title']}'.";
        create_notification($conn, (int)$company_user['user_id'], 'system', $msg, 'company/dashboard.php');
    }

    set_flash('success', "You have {$new_status} the test assignment.");
} else {
    set_flash('error', 'Failed to update status.');
}
$stmt->close();

redirect("freelancer/view_proposal.php?id=$proposal_id");
