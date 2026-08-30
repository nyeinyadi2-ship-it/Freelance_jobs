<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../includes/job_helpers.php';

header('Content-Type: application/json');

function send_json(bool $success, string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'error'   => $success ? null : $message
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(false, 'Invalid request method.', 405);
}

if (!verify_csrf()) {
    send_json(false, 'Invalid or expired CSRF token. Please refresh the page and try again.', 403);
}

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'freelancer') {
    send_json(false, 'Unauthorized. Freelancer login required.', 401);
}

$fl_uid = (int) $user['user_id'];

// Get freelancer ID from DB
$st_fl = $conn->prepare("SELECT id FROM freelancers WHERE user_id = ?");
$st_fl->bind_param('i', $fl_uid);
$st_fl->execute();
$fl_row = $st_fl->get_result()->fetch_assoc();
$st_fl->close();

$freelancer_id = (int) ($fl_row['id'] ?? 0);
if ($freelancer_id <= 0) {
    send_json(false, 'Freelancer profile not found.', 404);
}

$milestone_id = (int) ($_POST['milestone_id'] ?? 0);
$requested_deadline = trim($_POST['requested_deadline'] ?? '');
$reason = trim($_POST['extension_reason'] ?? '');

if ($milestone_id <= 0) {
    send_json(false, 'Invalid milestone ID.', 400);
}

if (empty($requested_deadline)) {
    send_json(false, 'Please provide a valid new deadline date.', 400);
}

if (empty($reason)) {
    send_json(false, 'Please provide a reason for the extension request.', 400);
}

// Validate requested deadline format and make sure it is in the future
try {
    $tz = new DateTimeZone('Asia/Yangon');
    $now = new DateTime('now', $tz);
    $req_dt = new DateTime($requested_deadline, $tz);
    if ($req_dt <= $now) {
        send_json(false, 'Requested deadline must be a date and time in the future.', 400);
    }
    $requested_dt_str = $req_dt->format('Y-m-d H:i:s');
} catch (Throwable $e) {
    send_json(false, 'Invalid deadline date/time format.', 400);
}

// Fetch milestone details
$st_ms = $conn->prepare("
    SELECT m.id, m.job_id, m.freelancer_id, m.deadline, m.status, m.extension_requested, m.extension_status,
           j.title AS job_title, j.company_id, c.user_id AS company_user_id
    FROM milestones m
    JOIN jobs j ON m.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    WHERE m.id = ?
");
$st_ms->bind_param('i', $milestone_id);
$st_ms->execute();
$ms = $st_ms->get_result()->fetch_assoc();
$st_ms->close();

if (!$ms) {
    send_json(false, 'Milestone not found.', 404);
}

// Check freelancer authorization for this milestone
if ((int) $ms['freelancer_id'] !== $freelancer_id) {
    // Also check if assigned via assignments
    $st_asgn = $conn->prepare("SELECT id FROM assignments WHERE job_id = ? AND freelancer_id = ?");
    $st_asgn->bind_param('ii', $ms['job_id'], $freelancer_id);
    $st_asgn->execute();
    $asgn_row = $st_asgn->get_result()->fetch_assoc();
    $st_asgn->close();

    if (!$asgn_row) {
        send_json(false, 'You do not have permission to request an extension for this milestone.', 403);
    }
}

// Check if extension is already requested or pending
if ((int) $ms['extension_requested'] === 1 || $ms['extension_status'] === 'pending') {
    send_json(false, 'You have already submitted an extension request for this milestone. Only one request is allowed.', 400);
}

// Check eligible milestone statuses
$eligible_statuses = ['overdue', 'in_progress', 'funded', 'revision_requested'];
if (!in_array($ms['status'], $eligible_statuses, true)) {
    send_json(false, 'This milestone is not currently eligible for an extension request.', 400);
}

$now_ts = date('Y-m-d H:i:s');

// Atomic database update to save the extension request
$upd = $conn->prepare("
    UPDATE milestones
    SET extension_requested    = 1,
        extension_deadline     = ?,
        extension_reason       = ?,
        extension_status       = 'pending',
        extension_requested_at = ?
    WHERE id = ? AND (extension_requested = 0 OR extension_requested IS NULL)
");
$upd->bind_param('sssi', $requested_dt_str, $reason, $now_ts, $milestone_id);
$upd->execute();
$affected = $upd->affected_rows;
$upd->close();

if ($affected === 0) {
    send_json(false, 'Extension request was already submitted or failed to save.', 400);
}

// Record entry in milestone_history table
try {
    record_milestone_history(
        $conn,
        $milestone_id,
        $freelancer_id,
        (int) $ms['company_id'],
        $fl_uid,
        $ms['status'],
        $ms['status'],
        'EXTENSION_REQUESTED',
        'Requested extension to ' . date('Y-m-d H:i', strtotime($requested_dt_str)) . '. Reason: ' . $reason,
        $ms['deadline'],
        $requested_dt_str
    );
} catch (Throwable $he) {
    error_log("Failed to record milestone history for extension request: " . $he->getMessage());
}

// Notify company (wrapped in try/catch so notification issue can never break the JSON response)
try {
    if (!empty($ms['company_user_id'])) {
        create_notification(
            $conn,
            (int) $ms['company_user_id'],
            'admin_announcement',
            "Requested a deadline extension for a milestone in \"" . $ms['job_title'] . "\".",
            'company/view_applications.php?id=' . $ms['job_id'],
            $fl_uid
        );
    }
} catch (Throwable $ne) {
    error_log("Notification failed after extension request: " . $ne->getMessage());
}

send_json(true, 'Extension request submitted successfully. Waiting for company approval.');
