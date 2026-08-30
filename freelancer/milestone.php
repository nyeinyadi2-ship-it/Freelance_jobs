<?php
$page_title = 'Milestone Details';
require __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../includes/job_helpers.php';

$ms_id = (int) ($_GET['id'] ?? 0);
if ($ms_id <= 0) { redirect('freelancer/my_tasks.php'); }

// Handle milestone actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $post_max = _ini_bytes(ini_get('post_max_size'));
        if ($content_length > $post_max) {
            set_flash('error', 'Your file is too large. Maximum total upload size is ' . round($post_max / 1048576) . 'MB. Please use a smaller file.');
        } else {
            set_flash('error', 'Invalid request. Please try again.');
        }
        $redirect_id = (int) ($_POST['milestone_id'] ?? ($_GET['id'] ?? 0));
        if ($redirect_id > 0) redirect('freelancer/milestone.php?id=' . $redirect_id);
        redirect('freelancer/my_tasks.php');
    }
    $ms_action = $_POST['ms_action'] ?? '';
    $post_milestone_id = (int) ($_POST['milestone_id'] ?? 0);

    if ($ms_action === 'submit' && $post_milestone_id > 0) {
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, a.id as assignment_id, a.freelancer_id as asgn_fl_id, m.freelancer_id as ms_fl_id
            FROM milestones m
            LEFT JOIN assignments a ON a.job_id = m.job_id AND (a.freelancer_id = m.freelancer_id OR a.freelancer_id = ?)
            WHERE m.id = ? AND m.status IN ('funded', 'in_progress', 'overdue', 'revision_requested')
              AND (m.freelancer_id = ? OR a.freelancer_id = ? OR m.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = ?))
        ");
        $st->bind_param('iiiii', $fl_freelancer_id, $post_milestone_id, $fl_freelancer_id, $fl_freelancer_id, $fl_uid);
        $st->execute();
        $ms_check = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms_check) {
            if (is_deadline_passed($ms_check['ms_deadline'])) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            $submission_note = trim($_POST['submission_note'] ?? '');
            $submission_file = null;

            $upload_err = null;
            if (!empty($_FILES['submission_file']['name'])) {
                $submission_file = upload_attachment($_FILES['submission_file'], 500 * 1024 * 1024, $upload_err);
                if ($submission_file === null) {
                    $msg = $upload_err ? 'File upload error: ' . $upload_err : 'Invalid file. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR. Max 500MB.';
                    set_flash('error', $msg);
                    redirect('freelancer/milestone.php?id=' . $post_milestone_id);
                }
            }

            if (empty($submission_file)) {
                set_flash('error', 'Please upload a file to submit your work.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $file_for_db = $submission_file ?? '';
                $link_for_db = '';
                $st = $conn->prepare("UPDATE milestones SET submission_link=?, submission_file=?, submission_note=?, status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('ssssi', $link_for_db, $file_for_db, $submission_note, $now, $post_milestone_id);
                $st->execute();
                $st->close();

                $assignment_id = (int) ($ms_check['assignment_id'] ?? 0);
                if ($assignment_id > 0) {
                    $v_stmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM submissions WHERE assignment_id = ?");
                    $v_stmt->bind_param('i', $assignment_id);
                    $v_stmt->execute();
                    $version = (int) ($v_stmt->get_result()->fetch_row()[0] ?? 1);
                    $v_stmt->close();

                    $sub_file = !empty($submission_file) ? $submission_file : null;
                    $sub_link = null;
                    $sub_notes = !empty($submission_note) ? $submission_note : null;
                    $sub_fl_id = (int) ($ms_check['asgn_fl_id'] ?: ($ms_check['ms_fl_id'] ?: $fl_freelancer_id));

                    $sub_st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, file_path, github_link, notes, version, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    $sub_st->bind_param('iisssi', $assignment_id, $sub_fl_id, $sub_file, $sub_link, $sub_notes, $version);
                    $sub_st->execute();
                    $sub_st->close();

                    $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE id=? AND status IN ('working', 'assigned', 'overdue', 'revision_requested')");
                    $st->bind_param('i', $assignment_id);
                    $st->execute();
                    $st->close();
                }

                $conn->commit();

                // Notify company (after commit so notification failure doesn't roll back submission)
                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms_check['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', "Submitted work for a milestone.", 'company/view_applications.php?id=' . $ms_check['job_id'], $fl_uid);
                    }
                } catch (Throwable $ne) {
                    error_log("Notification failed after submission: " . $ne->getMessage());
                }

                set_flash('success', 'Work submitted for review!');
            } catch (Throwable $e) {
                $conn->rollback();
                if ($submission_file !== null) {
                    delete_attachment($submission_file);
                }
                error_log('Submission error: ' . $e->getMessage());
                set_flash('error', 'Failed to submit your work. Please check your submission details and try again.');
            }
        } else {
            set_flash('error', 'Milestone not found or not ready for submission.');
        }
        redirect('freelancer/milestone.php?id=' . $post_milestone_id);
    } elseif ($ms_action === 'request_extension' && $post_milestone_id > 0) {
        $requested_deadline = trim($_POST['requested_deadline'] ?? '');
        $reason = trim($_POST['extension_reason'] ?? '');

        if (empty($requested_deadline)) {
            set_flash('error', 'Please provide a new deadline date.');
            redirect('freelancer/milestone.php?id=' . $post_milestone_id);
        }

        // Fetch milestone — must belong to this freelancer and be in an eligible status
        $st = $conn->prepare("
            SELECT m.id, m.freelancer_id, m.deadline, m.status, m.extension_requested, m.job_id
            FROM milestones m
            WHERE m.id = ? AND m.freelancer_id = ?
              AND m.status IN ('overdue', 'in_progress', 'funded')
        ");
        $st->bind_param('ii', $post_milestone_id, $fl_freelancer_id);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            // One-time extension rule: if already requested (pending/approved/rejected), block
            if ((int)$ms['extension_requested'] === 1) {
                set_flash('error', 'You have already submitted an extension request for this milestone. Only one request is allowed.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            $current_deadline = $ms['deadline'] ?? date('Y-m-d H:i:s');
            $requested_dt    = date('Y-m-d H:i:s', strtotime($requested_deadline));
            $now_ts          = date('Y-m-d H:i:s');

            // Atomic update — only succeeds if extension_requested is still 0 (prevents double-click)
            $upd = $conn->prepare("
                UPDATE milestones
                SET extension_requested    = 1,
                    extension_deadline     = ?,
                    extension_reason       = ?,
                    extension_status       = 'pending',
                    extension_requested_at = ?
                WHERE id = ? AND extension_requested = 0
            ");
            $upd->bind_param('sssi', $requested_dt, $reason, $now_ts, $post_milestone_id);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();

            if ($affected === 0) {
                set_flash('error', 'Extension request already submitted for this milestone.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            // Record history — EXTENSION_REQUESTED
            record_milestone_history(
                $conn,
                $post_milestone_id,
                $fl_freelancer_id,
                null,
                $fl_user['id'],
                $ms['status'],
                $ms['status'],
                'EXTENSION_REQUESTED',
                'Requested extension to ' . date('Y-m-d', strtotime($requested_dt)) . '. Reason: ' . $reason,
                $current_deadline,
                $requested_dt
            );

            // Notify company
            try {
                $ns = $conn->prepare("SELECT j.title, j.id AS job_id, c.user_id FROM milestones m JOIN jobs j ON m.job_id = j.id JOIN companies c ON j.company_id = c.id WHERE m.id = ?");
                $ns->bind_param('i', $post_milestone_id);
                $ns->execute();
                $ni = $ns->get_result()->fetch_assoc();
                $ns->close();
                if ($ni) {
                    create_notification($conn, (int)$ni['user_id'], 'admin_announcement', "Requested a deadline extension for a milestone in \"{$ni['title']}\".", 'company/view_applications.php?id=' . $ni['job_id'], $fl_uid);
                }
            } catch (Exception $ne) {
                error_log('Notification failed: ' . $ne->getMessage());
            }

            set_flash('success', 'Extension request submitted. Waiting for company approval.');
        } else {
            set_flash('error', 'Milestone not found or not eligible for extension.');
        }
        redirect('freelancer/milestone.php?id=' . $post_milestone_id);
    } elseif ($ms_action === 'quick_submit' && $post_milestone_id > 0) {
        // Quick submit: change status to submitted without requiring link/file
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, a.id as assignment_id, a.freelancer_id as asgn_fl_id, m.freelancer_id as ms_fl_id
            FROM milestones m
            LEFT JOIN assignments a ON a.job_id = m.job_id AND (a.freelancer_id = m.freelancer_id OR a.freelancer_id = ?)
            WHERE m.id = ? AND m.status IN ('funded', 'in_progress', 'overdue', 'revision_requested')
              AND (m.freelancer_id = ? OR a.freelancer_id = ? OR m.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = ?))
        ");
        $st->bind_param('iiiii', $fl_freelancer_id, $post_milestone_id, $fl_freelancer_id, $fl_freelancer_id, $fl_uid);
        $st->execute();
        $ms_check = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms_check) {
            if (is_deadline_passed($ms_check['ms_deadline'])) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $st = $conn->prepare("UPDATE milestones SET status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('si', $now, $post_milestone_id);
                $st->execute();
                $st->close();

                $assignment_id = (int) ($ms_check['assignment_id'] ?? 0);
                if ($assignment_id > 0) {
                    $v_stmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM submissions WHERE assignment_id = ?");
                    $v_stmt->bind_param('i', $assignment_id);
                    $v_stmt->execute();
                    $version = (int) ($v_stmt->get_result()->fetch_row()[0] ?? 1);
                    $v_stmt->close();

                    $quick_note = "Quick submission for milestone #" . $ms_check['id'];
                    $sub_fl_id = (int) ($ms_check['asgn_fl_id'] ?: ($ms_check['ms_fl_id'] ?: $fl_freelancer_id));

                    $sub_st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, notes, version, status) VALUES (?, ?, ?, ?, 'pending')");
                    $sub_st->bind_param('iisi', $assignment_id, $sub_fl_id, $quick_note, $version);
                    $sub_st->execute();
                    $sub_st->close();

                    $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE id=? AND status IN ('working', 'assigned', 'overdue', 'revision_requested', 'not_started', 'in_progress') AND status != 'completed'");
                    $st->bind_param('i', $assignment_id);
                    $st->execute();
                    $st->close();
                }

                $conn->commit();

                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms_check['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', "Submitted work for a milestone.", 'company/view_applications.php?id=' . $ms_check['job_id'], $fl_uid);
                    }
                } catch (Throwable $ne) {
                    error_log("Notification failed after submission: " . $ne->getMessage());
                }

                set_flash('success', 'Milestone submitted for review!');
            } catch (Throwable $e) {
                $conn->rollback();
                error_log('Submission error: ' . $e->getMessage());
                set_flash('error', 'Failed to submit your work. Please check your submission details and try again.');
            }
        } else {
            set_flash('error', 'Milestone not found or not ready for submission.');
        }
        redirect('freelancer/milestone.php?id=' . $post_milestone_id);
    }
    redirect('freelancer/my_tasks.php');
}

// Fetch milestone with job and company info
$st = $conn->prepare("
    SELECT m.*, j.title AS job_title, j.description AS job_description, j.budget AS job_budget, j.deadline AS job_deadline,
           j.id AS job_id, j.status AS job_status,
           c.company_name, c.logo_image,
           a.id AS assignment_id, a.status AS assignment_status, a.assigned_at,
           CASE WHEN m.status = 'draft' THEN NULL WHEN m.status IN ('paid', 'completed') THEN 'released' ELSE 'held' END AS escrow_status,
           NULL AS funded_at, NULL AS released_at
    FROM milestones m
    JOIN jobs j ON m.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    JOIN assignments a ON a.job_id = j.id AND a.freelancer_id = ?
    WHERE m.id = ?
      AND m.freelancer_id = ?
");
$st->bind_param('iii', $fl_freelancer_id, $ms_id, $fl_freelancer_id);
$st->execute();
$milestone = $st->get_result()->fetch_assoc();
$st->close();

if (!$milestone) { redirect('freelancer/my_tasks.php'); }

$payment_details = null;
if (in_array($milestone['status'], ['paid', 'payment_pending'])) {
    $st_pay = $conn->prepare("SELECT payment_method, transaction_reference, transaction_slip, paid_at FROM payments WHERE milestone_id = ? AND freelancer_id = ? ORDER BY id DESC LIMIT 1");
    $st_pay->bind_param('ii', $ms_id, $fl_freelancer_id);
    $st_pay->execute();
    $payment_details = $st_pay->get_result()->fetch_assoc();
    $st_pay->close();
}

// Fetch all milestones for this job (for sidebar/progress) — only those assigned to this freelancer
$all_ms = [];
$st = $conn->prepare("
    SELECT m1.id, m1.title, m1.amount, m1.status, m1.sort_order 
    FROM milestones m1
    WHERE m1.job_id = ? 
      AND m1.freelancer_id = ?
    ORDER BY m1.sort_order ASC
");
$st->bind_param('ii', $milestone['job_id'], $fl_freelancer_id);
$st->execute();
$mr = $st->get_result();
while ($row = $mr->fetch_assoc()) { $all_ms[] = $row; }
$st->close();

$approved_count = 0;
foreach ($all_ms as $am) { if ($am['status'] === 'approved') $approved_count++; }
$progress = count($all_ms) > 0 ? round(($approved_count / count($all_ms)) * 100) : 0;

require __DIR__ . '/../includes/freelancer_layout.php';

$status_labels = ['draft'=>'Draft','funded'=>'In Progress','in_progress'=>'In Progress','submitted'=>'Under Review','approved'=>'Approved','revision_requested'=>'Revision Requested','payment_pending'=>'Payment Pending','paid'=>'Received','overdue'=>'Overdue','cancelled'=>'Cancelled'];
$escrow_labels = ['held'=>'In Progress','released'=>'Released','refunded'=>'Refunded'];
$escrow_colors = ['held'=>'#f59e0b','released'=>'#10b981','refunded'=>'#ef4444'];
$draft_enabled = ($milestone['status'] === 'draft');
?>

<style>
.ms-status-lg { display:inline-flex; align-items:center; gap:0.375rem; padding:0.375rem 0.875rem; border-radius:9999px; font-size:0.8125rem; font-weight:600; }
.ms-draft-lg { background:rgba(107,114,128,0.1); color:#6b7280; }
.ms-funded-lg { background:rgba(59,130,246,0.1); color:#3b82f6; }
.ms-in_progress-lg { background:rgba(99,102,241,0.1); color:#6366f1; }
.ms-submitted-lg { background:rgba(139,92,246,0.1); color:#8b5cf6; }
.ms-approved-lg { background:rgba(16,185,129,0.1); color:#10b981; }
.ms-payment_pending-lg { background:rgba(245,158,11,0.1); color:#f59e0b; }
.ms-revision_requested-lg { background:rgba(245,158,11,0.1); color:#f59e0b; }
.ms-overdue-lg { background:rgba(220,38,38,0.1); color:#dc2626; }
.ms-cancelled-lg { background:rgba(107,114,128,0.1); color:#6b7280; }

.ms-timeline-lg { display:flex; align-items:center; gap:0; margin:1rem 0; }
.ms-timeline-step-lg { display:flex; align-items:center; gap:0.5rem; }
.ms-timeline-dot-lg { width:14px; height:14px; border-radius:50%; flex-shrink:0; border:2.5px solid; }
.ms-timeline-line-lg { flex:1; height:3px; min-width:20px; }
.ms-tl-active-lg { background:linear-gradient(135deg,#6366f1,#8b5cf6); border-color:#6366f1; }
.ms-tl-done-lg { background:#10b981; border-color:#10b981; }
.ms-tl-pending-lg { background:var(--color-card); border-color:var(--color-border); }
.ms-tl-line-active-lg { background:linear-gradient(90deg,#10b981,#6366f1); }
.ms-tl-line-done-lg { background:#10b981; }
.ms-tl-line-pending-lg { background:var(--color-border); }

.upload-zone-lg { border:2px dashed var(--color-border); border-radius:0.75rem; padding:1.5rem; text-align:center; cursor:pointer; transition:all .3s; }
.upload-zone-lg:hover { border-color:#6366f1; background:rgba(99,102,241,0.03); }
.upload-zone-lg.has-file { border-color:#10b981; background:rgba(16,185,129,0.03); }
</style>

<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">
    <!-- Back link -->
    <div class="mb-4">
        <button type="button" onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Milestone Header Card -->
            <div class="glass rounded-2xl p-6 reveal">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
                    <div class="flex items-start gap-3">
                        <?php if ($milestone['status'] === 'approved'): ?>
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)">
                                <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            </div>
                        <?php else: ?>
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 text-lg font-bold" style="background:var(--color-bg);border:1.5px solid var(--color-border);color:var(--color-text-muted)"><?= $milestone['sort_order'] ?></div>
                        <?php endif; ?>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-wider mb-1" style="color:var(--color-text-muted)">Milestone <?= $milestone['sort_order'] ?> of <?= count($all_ms) ?></p>
                            <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e($milestone['title']) ?></h1>
                        </div>
                    </div>
                    <?php
                    $status_class = 'ms-' . $milestone['status'] . '-lg';
                    ?>
                    <span class="ms-status-lg <?= $status_class ?>"><?= $status_labels[$milestone['status']] ?? $milestone['status'] ?></span>
                </div>

                <?php if ($milestone['description']): ?>
                    <p class="text-sm leading-relaxed mb-4" style="color:var(--color-text-secondary)"><?= nl2br(e($milestone['description'])) ?></p>
                <?php endif; ?>

                <!-- Status Timeline -->
                <div class="ms-timeline-lg">
                    <?php
                    $steps = ['draft', 'in_progress', 'submitted', 'approved'];
                    $step_labels = ['Draft', 'Working', 'Submitted', 'Approved'];
                    $current_idx = array_search($milestone['status'], $steps);
                    if ($milestone['status'] === 'revision_requested') $current_idx = 1;
                    if ($milestone['status'] === 'funded') $current_idx = 1;
                    if ($current_idx === false) $current_idx = -1;
                    ?>
                    <?php for ($si = 0; $si < count($steps); $si++): ?>
                        <div class="ms-timeline-step-lg">
                            <div class="ms-timeline-dot-lg <?= $si < $current_idx ? 'ms-tl-done-lg' : ($si === $current_idx ? 'ms-tl-active-lg' : 'ms-tl-pending-lg') ?>"></div>
                            <span class="text-xs font-semibold hidden sm:inline <?= $si <= $current_idx ? '' : 'opacity-40' ?>" style="color:<?= $si <= $current_idx ? 'var(--color-text-primary)' : 'var(--color-text-muted)' ?>"><?= $step_labels[$si] ?></span>
                        </div>
                        <?php if ($si < count($steps) - 1): ?>
                            <div class="ms-timeline-line-lg <?= $si < $current_idx ? 'ms-tl-line-done-lg' : ($si === $current_idx ? 'ms-tl-line-active-lg' : 'ms-tl-line-pending-lg') ?>"></div>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Revision Warning -->
            <?php if ($milestone['status'] === 'revision_requested'): ?>
            <div class="rounded-2xl p-4 flex items-start gap-3 mb-6" style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.15)">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div class="w-full">
                    <p class="text-sm font-bold text-red-600">Revision Requested</p>
                    <p class="text-xs mt-0.5 text-red-500 mb-2">The company has requested changes. Please update your work and resubmit.</p>
                    <?php if (!empty($milestone['revision_notes'])): ?>
                        <div class="mt-2 p-3 rounded-lg text-sm bg-white dark:bg-gray-800 text-red-800 dark:text-red-200 border border-red-100 dark:border-red-900 shadow-sm">
                            <span class="font-bold text-xs uppercase tracking-wider mb-1 block">Feedback from Company:</span>
                            <?= nl2br(e($milestone['revision_notes'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($milestone['status'] === 'overdue'): ?>
            <div class="rounded-2xl p-4 flex items-start gap-3 mb-6" style="background:rgba(220,38,38,0.06);border:1px solid rgba(220,38,38,0.15)">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div class="w-full">
                    <p class="text-sm font-bold text-red-600">Milestone Overdue</p>
                    <p class="text-xs mt-0.5 text-red-500 mb-2">The deadline for this milestone has passed. Please contact the company or request an extension.</p>
                    <?php if (!empty($milestone['deadline'])): ?>
                        <div class="mt-2 p-3 rounded-lg text-sm bg-white dark:bg-gray-800 text-red-800 dark:text-red-200 border border-red-100 dark:border-red-900 shadow-sm">
                            <span class="font-bold text-xs uppercase tracking-wider mb-1 block">Deadline Was:</span>
                            <?= date('F j, Y \a\t g:ia', strtotime($milestone['deadline'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $ext_requested = (int)($milestone['extension_requested'] ?? 0);
                    $ext_status    = $milestone['extension_status'] ?? 'none';
                    ?>

                    <?php if ($ext_requested === 0): ?>
                        <!-- No extension requested yet — show button -->
                        <button type="button" onclick="document.getElementById('requestExtModal').classList.remove('hidden')" class="mt-3 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 2px 8px rgba(59,130,246,0.3)">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Request Extension
                        </button>

                    <?php elseif ($ext_status === 'pending'): ?>
                        <!-- Extension pending company review -->
                        <div class="mt-3 p-3 rounded-lg text-xs font-semibold text-amber-700" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.2)">
                            <svg class="w-4 h-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Extension request <strong>Pending</strong> — waiting for company review
                            <?php if (!empty($milestone['extension_deadline'])): ?>
                                <p class="mt-1 font-normal">Requested new deadline: <strong><?= date('M j, Y', strtotime($milestone['extension_deadline'])) ?></strong></p>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($ext_status === 'approved'): ?>
                        <!-- Extension approved — show new deadline -->
                        <div class="mt-3 p-3 rounded-lg text-xs font-semibold text-emerald-700" style="background:rgba(16,185,129,0.08);border:1px solid rgba(16,185,129,0.2)">
                            <svg class="w-4 h-4 inline mr-1 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Extension <strong>Approved</strong>
                            <?php if (!empty($milestone['deadline'])): ?>
                                <p class="mt-1 font-normal">New deadline: <strong><?= date('M j, Y', strtotime($milestone['deadline'])) ?></strong></p>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($ext_status === 'rejected'): ?>
                        <!-- Extension rejected -->
                        <div class="mt-3 p-3 rounded-lg text-xs font-semibold text-red-700" style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2)">
                            <svg class="w-4 h-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Extension <strong>Rejected</strong> — original deadline remains unchanged. No further extension requests are allowed.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Request Extension Modal -->
            <?php if ($ext_requested === 0): ?>
            <div id="requestExtModal" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('requestExtModal').classList.add('hidden')"></div>
                <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
                    <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                        <h3 class="text-base font-bold" style="color:var(--color-text-primary)">Request Deadline Extension</h3>
                        <button type="button" onclick="document.getElementById('requestExtModal').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                            <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="p-5">
                        <form method="POST" id="extRequestForm">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="ms_action" value="request_extension">
                            <input type="hidden" name="milestone_id" value="<?= (int) $milestone['id'] ?>">

                            <div class="mb-4 p-3 rounded-lg" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                <label class="block text-[11px] font-semibold uppercase tracking-wider mb-1" style="color:var(--color-text-muted)">Current Deadline</label>
                                <p class="text-sm font-bold" style="color:var(--color-text-primary)"><?= !empty($milestone['deadline']) ? date('M j, Y g:ia', strtotime($milestone['deadline'])) : 'No deadline set' ?></p>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Requested New Deadline <span class="text-red-500">*</span></label>
                                <input type="datetime-local" name="requested_deadline" required min="<?= date('Y-m-d\TH:i') ?>" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow">
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Reason <span class="text-red-500">*</span></label>
                                <textarea name="extension_reason" required rows="3" placeholder="Explain why you need more time..." class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow"></textarea>
                            </div>

                            <div id="extFormError" class="hidden mb-4 p-3 rounded-lg text-xs font-semibold text-red-700 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800"></div>
                            <div id="extFormSuccess" class="hidden mb-4 p-3 rounded-lg text-xs font-semibold text-emerald-700 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800"></div>

                            <div class="flex gap-2 justify-end">
                                <button type="button" onclick="document.getElementById('requestExtModal').classList.add('hidden')" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Cancel</button>
                                <button type="submit" id="extSubmitBtn" class="px-4 py-2 text-sm font-semibold text-white rounded-lg transition-all" style="background:linear-gradient(135deg,#3b82f6,#2563eb);box-shadow:0 2px 8px rgba(59,130,246,0.3)">Submit Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Action Area -->
            <?php if ($milestone['status'] === 'draft'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="opacity:0.8">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)">
                            <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Waiting for Company</h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">The company has not started this milestone</p>
                        </div>
                    </div>
                    <p class="text-sm" style="color:var(--color-text-secondary)">The company needs to start this milestone before you can begin working. You'll be notified once it's started.</p>
                    <div class="mt-4 p-3 rounded-xl flex items-center gap-2" style="background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.15)">
                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="text-xs font-medium text-amber-600">Submission is disabled until the milestone is started.</span>
                    </div>
                </div>

            <?php elseif (in_array($milestone['status'], ['in_progress', 'funded', 'revision_requested'], true)): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                            <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= $milestone['status'] === 'revision_requested' ? 'Resubmit Deliverables' : 'Work in Progress' ?></h2>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= $milestone['status'] === 'revision_requested' ? 'Update your files and notes according to company feedback and resubmit' : 'Upload your deliverables when ready' ?></p>
                        </div>
                    </div>

                    <?php if (!empty($milestone['revision_notes']) || !empty($milestone['rejection_reason'])): ?>
                        <div class="mb-4 p-3.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
                            <span class="font-bold block mb-1">Company Feedback / Requested Changes:</span>
                            <p class="leading-relaxed whitespace-pre-wrap"><?= nl2br(e($milestone['revision_notes'] ?: $milestone['rejection_reason'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php 
                    $can_submit = !is_deadline_passed($milestone['deadline'] ?? null);
                    ?>
                    <?php if (!$can_submit): ?>
                        <div class="p-4 rounded-xl border flex items-center gap-3 mb-4" style="background:rgba(239,68,68,0.05);border-color:rgba(239,68,68,0.2)">
                            <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <span class="text-sm font-semibold text-red-600">The deadline has passed. Submission is no longer allowed.</span>
                        </div>
                    <?php else: ?>
                    <form method="POST" enctype="multipart/form-data" id="submitForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="ms_action" value="submit">
                        <input type="hidden" name="milestone_id" value="<?= (int) $milestone['id'] ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Attach File</label>
                            <div class="upload-zone-lg" id="uploadZone">
                                <input type="file" name="submission_file" id="fileInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar" class="hidden">
                                <svg class="w-10 h-10 mx-auto mb-2" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                                <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Click or drag to attach</p>
                                <p class="text-xs mt-1" style="color:var(--color-text-muted)">ZIP, PDF, DOCX, Images — Max 500MB</p>
                            </div>
                            <div id="fileInfo" class="hidden mt-3 flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:rgba(16,185,129,0.1)">
                                    <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)" id="fileName"></p>
                                    <p class="text-xs" style="color:var(--color-text-muted)" id="fileSize"></p>
                                </div>
                                <button type="button" onclick="clearFile()" class="text-red-400 hover:text-red-600 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="mb-5">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Submission Note</label>
                            <textarea name="submission_note" rows="4" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Describe what you've delivered, any instructions for the reviewer..."><?= e($milestone['submission_note'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);box-shadow:0 4px 15px rgba(139,92,246,0.3)" onclick="return confirmSubmit()">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            <?= $milestone['status'] === 'revision_requested' ? 'Resubmit Work' : 'Submit with Details' ?>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

            <?php elseif ($milestone['status'] === 'rejected'): ?>
                <div class="glass rounded-2xl p-6 reveal border border-red-200 dark:border-red-900/40" style="background:rgba(239,68,68,0.03)">
                    <div class="flex items-center justify-between gap-3 mb-4 pb-3 border-b border-red-200 dark:border-red-800">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-red-100 dark:bg-red-900/30 text-red-600">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </div>
                            <div>
                                <h2 class="text-base font-bold text-red-600 dark:text-red-400">Milestone Permanently Rejected</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Resubmissions are disabled for this milestone.</p>
                            </div>
                        </div>
                        <?= status_badge('rejected') ?>
                    </div>

                    <?php if (!empty($milestone['rejection_reason'])): ?>
                        <div class="mb-4 p-3.5 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-xs text-red-700 dark:text-red-300">
                            <span class="font-bold block mb-1">Company Rejection Reason:</span>
                            <p class="leading-relaxed"><?= nl2br(e($milestone['rejection_reason'])) ?></p>
                        </div>
                    <?php endif; ?>
                        <div class="flex items-center justify-between gap-2">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Submission Details (Read-Only)</h4>
                            <?php if (!empty($milestone['submitted_at'])): ?>
                                <span class="text-xs text-gray-400">Submitted <?= date('M j, Y \a\t g:i A', strtotime($milestone['submitted_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($milestone['submission_file']): ?>
                            <div class="flex items-center justify-between p-3 rounded-lg border bg-white dark:bg-gray-800 text-xs border-gray-200 dark:border-gray-700">
                                <div class="flex items-center gap-2 min-w-0">
                                    <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200 truncate"><?= e(basename($milestone['submission_file'])) ?></span>
                                </div>
                                <a href="<?= e(base_url('api/download_submission.php?milestone_id=' . $milestone['id'])) ?>" target="_blank" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-semibold text-xs hover:bg-emerald-700">Download File</a>
                            </div>
                        <?php endif; ?>

                        <?php if ($milestone['submission_note']): ?>
                            <div class="p-3 rounded-lg border bg-white dark:bg-gray-800 text-xs border-gray-200 dark:border-gray-700">
                                <span class="text-gray-400 block mb-1 font-semibold uppercase text-[10px]">Notes</span>
                                <p class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap"><?= nl2br(e($milestone['submission_note'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($milestone['status'] === 'submitted'): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-2.5 h-2.5 rounded-full bg-purple-500 animate-pulse"></div>
                        <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Under Review</h2>
                    </div>
                    <p class="text-sm mb-4" style="color:var(--color-text-secondary)">Your work has been submitted and is awaiting the company's review. You'll be notified once a decision is made.</p>

                    <?php 
                    $has_fl_sub = !empty($milestone['submitted_at']) || !empty($milestone['submission_file']) || !empty($milestone['submission_link']) || !empty($milestone['submission_note']);
                    ?>
                    <?php if ($has_fl_sub): ?>
                    <div class="mt-4 p-4 rounded-xl space-y-3" style="background:var(--color-bg);border:1px solid var(--color-border)">
                        <div class="flex items-center justify-between gap-2 pb-2 border-b" style="border-color:var(--color-border)">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-purple-600 dark:text-purple-400">Submission Details</h4>
                            <div class="flex items-center gap-2 text-xs">
                                <?php if (!empty($milestone['submitted_at'])): ?>
                                    <span class="text-gray-500 dark:text-gray-400">Submitted <?= date('M j, Y \a\t g:i A', strtotime($milestone['submitted_at'])) ?></span>
                                <?php endif; ?>
                                <?= status_badge($milestone['status']) ?>
                            </div>
                        </div>
                        <?php if ($milestone['submission_file']): ?>
                            <div class="flex items-center justify-between p-3 rounded-lg border bg-white dark:bg-gray-800 text-xs" style="border-color:var(--color-border)">
                                <div class="flex items-center gap-2 min-w-0">
                                    <svg class="w-4 h-4 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span class="font-semibold text-gray-800 dark:text-gray-200 truncate"><?= e(basename($milestone['submission_file'])) ?></span>
                                </div>
                                <a href="<?= e(base_url('api/download_submission.php?milestone_id=' . $milestone['id'])) ?>" target="_blank" class="px-3 py-1.5 rounded-lg bg-emerald-600 text-white font-semibold text-xs hover:bg-emerald-700">Download File</a>
                            </div>
                        <?php endif; ?>

                        <?php if ($milestone['submission_note']): ?>
                            <div class="p-3 rounded-lg border bg-white dark:bg-gray-800 text-xs" style="border-color:var(--color-border)">
                                <span class="text-gray-400 block mb-1 font-semibold uppercase text-[10px]">Notes</span>
                                <p class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap"><?= nl2br(e($milestone['submission_note'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($milestone['status'] === 'payment_pending'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="background:rgba(245,158,11,0.03);border:1px solid rgba(245,158,11,0.15)">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)">
                            <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-amber-700">Payment Pending</h2>
                        </div>
                    </div>
                    <p class="text-sm text-amber-600">The company has approved your work. Payment is currently being processed via your preferred payment method.</p>
                </div>

            <?php elseif ($milestone['status'] === 'paid'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="background:rgba(16,185,129,0.03);border:1px solid rgba(16,185,129,0.15)">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(16,185,129,0.1)">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-emerald-700">Payment Status: Received</h2>
                            <?php if ($milestone['approved_at'] || ($payment_details && $payment_details['paid_at'])): ?>
                                <p class="text-xs text-emerald-600">Paid on <?= date('F j, Y \a\t g:ia', strtotime($payment_details['paid_at'] ?? $milestone['approved_at'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="text-sm text-emerald-600">You have received payment of <?= number_format((float) $milestone['amount'], 2) ?> MMK for this milestone.</p>
                    
                    <?php if ($payment_details): ?>
                    <div class="mt-4 p-4 rounded-xl border border-emerald-100 bg-white/50">
                        <h4 class="text-sm font-semibold text-emerald-800 mb-3">Transaction Details</h4>
                        <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                            <div>
                                <p class="text-emerald-600/70 text-xs uppercase tracking-wider font-semibold mb-1">Method</p>
                                <p class="text-emerald-900 font-medium"><?= e(ucwords(str_replace('_', ' ', $payment_details['payment_method']))) ?></p>
                            </div>
                            <?php if ($payment_details['transaction_reference']): ?>
                            <div>
                                <p class="text-emerald-600/70 text-xs uppercase tracking-wider font-semibold mb-1">Reference No.</p>
                                <p class="text-emerald-900 font-medium"><?= e($payment_details['transaction_reference']) ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($payment_details['transaction_slip']): ?>
                            <a href="<?= e(base_url('api/download_slip.php?milestone_id=' . $milestone['id'])) ?>" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg hover:bg-emerald-100 transition-colors font-medium text-sm">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                View Transaction Slip
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            <?php elseif ($milestone['status'] === 'cancelled'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="background:rgba(239,68,68,0.03);border:1px solid rgba(239,68,68,0.15)">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(239,68,68,0.1)">
                            <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-red-700">Project Rejected / Cancelled</h2>
                        </div>
                    </div>
                    <p class="text-sm text-red-600">This milestone has been cancelled because the project was rejected by the company.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Milestone Info -->
            <div class="glass rounded-2xl p-5 reveal">
                <h3 class="text-sm font-bold mb-4" style="color:var(--color-text-primary)">Milestone Details</h3>
                <div class="space-y-3">
                    <div class="flex justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Amount</span>
                        <span class="font-bold text-lg" style="color:#f59e0b"><?= number_format((float) $milestone['amount'], 2) ?> MMK</span>
                    </div>
                    <div class="h-px" style="background:var(--color-border)"></div>
                    <?php if (!empty($milestone['deadline'])): ?>
                    <div class="flex justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Milestone Deadline</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= date('M j, Y, g:i A', strtotime($milestone['deadline'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Job Deadline</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= $milestone['job_deadline'] ? date('M j, Y', strtotime($milestone['job_deadline'])) : 'Not set' ?></span>
                    </div>
                </div>
            </div>

            <!-- Project Info -->
            <div class="glass rounded-2xl p-5 reveal">
                <h3 class="text-sm font-bold mb-3" style="color:var(--color-text-primary)">Project</h3>
                <a href="<?= e(base_url('freelancer/view_job.php?id=' . $milestone['job_id'])) ?>" class="flex items-center gap-3 p-3 rounded-xl transition-colors hover:opacity-80" style="background:var(--color-bg);border:1px solid var(--color-border)">
                    <?php if ($milestone['logo_image']): ?>
                        <img src="<?= e(base_url('uploads/images/' . $milestone['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-lg object-contain flex-shrink-0">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-lg flex items-center justify-center text-sm font-bold flex-shrink-0" style="background:rgba(99,102,241,0.1);color:#6366f1"><?= strtoupper(mb_substr($milestone['company_name'], 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <p class="text-sm font-bold truncate" style="color:var(--color-text-primary)"><?= e($milestone['job_title']) ?></p>
                        <p class="text-xs" style="color:var(--color-text-muted)"><?= e($milestone['company_name']) ?></p>
                    </div>
                </a>
            </div>

            <!-- All Milestones -->
            <div class="glass rounded-2xl p-5 reveal">
                <h3 class="text-sm font-bold mb-3" style="color:var(--color-text-primary)">All Milestones</h3>
                <div class="w-full h-2 rounded-full mb-3" style="background:var(--color-border)">
                    <div class="h-2 rounded-full transition-all" style="width:<?= $progress ?>%;background:linear-gradient(135deg,#6366f1,#8b5cf6)"></div>
                </div>
                <div class="space-y-2">
                    <?php 
                    $has_revision = false;
                    foreach ($all_ms as $am):
                        if ($am['status'] === 'revision_requested') $has_revision = true;
                        $is_current = $am['id'] == $ms_id;
                        $am_labels = ['draft'=>'Draft','funded'=>'Funded','in_progress'=>'Working','submitted'=>'Review','approved'=>'Done','revision_requested'=>'Revision'];
                    ?>
                    <a href="<?= e(base_url('freelancer/milestone.php?id=' . $am['id'])) ?>" class="flex items-center justify-between p-2.5 rounded-lg transition-all <?= $is_current ? 'ring-2 ring-indigo-500' : '' ?>" style="background:<?= $is_current ? 'rgba(99,102,241,0.06)' : 'var(--color-bg)' ?>;border:1px solid <?= $is_current ? 'rgba(99,102,241,0.3)' : 'var(--color-border)' ?>">
                        <div class="flex items-center gap-2 min-w-0">
                            <?php if ($am['status'] === 'approved'): ?>
                                <div class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)"><svg class="w-3 h-3 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                            <?php else: ?>
                                <div class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 text-[10px] font-bold" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-muted)"><?= $am['sort_order'] ?></div>
                            <?php endif; ?>
                            <span class="text-xs font-medium truncate <?= $is_current ? 'text-indigo-600' : '' ?>" style="color:<?= $is_current ? '' : 'var(--color-text-secondary)' ?>"><?= e($am['title']) ?></span>
                        </div>
                        <?php if ($am['status'] === 'revision_requested'): ?>
                            <div class="flex items-center gap-1 text-red-500 ml-2 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span class="text-[10px] font-bold uppercase tracking-wide">Revision Required</span>
                            </div>
                        <?php else: ?>
                            <span class="text-[10px] font-semibold flex-shrink-0 ml-2" style="color:var(--color-text-muted)"><?= $am_labels[$am['status']] ?? '' ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var zone = document.getElementById('uploadZone');
var input = document.getElementById('fileInput');
var info = document.getElementById('fileInfo');

if (zone && input) {
    zone.addEventListener('click', function() { input.click(); });
    zone.addEventListener('dragover', function(e) { e.preventDefault(); zone.style.borderColor = '#6366f1'; });
    zone.addEventListener('dragleave', function() { zone.style.borderColor = ''; });
    zone.addEventListener('drop', function(e) {
        e.preventDefault(); zone.style.borderColor = '';
        if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; showFileInfo(); }
    });
    input.addEventListener('change', showFileInfo);
}

function showFileInfo() {
    if (input && input.files && input.files.length) {
        document.getElementById('fileName').textContent = input.files[0].name;
        document.getElementById('fileSize').textContent = (input.files[0].size / 1024).toFixed(0) + ' KB';
        info.classList.remove('hidden');
        zone.classList.add('has-file');
    }
}

function clearFile() {
    if (input) input.value = '';
    if (info) info.classList.add('hidden');
    if (zone) zone.classList.remove('has-file');
}

function confirmSubmit() {
    var form = document.getElementById('submitForm');
    var fileInput = form.querySelector('[name="submission_file"]');
    if (!fileInput.files || !fileInput.files.length) {
        alert('Please upload your completed work before submitting.');
        return false;
    }
    if (fileInput.files && fileInput.files.length && fileInput.files[0].size > 500 * 1024 * 1024) {
        alert('File size must not exceed 500MB.');
        return false;
    }
    return confirm('Submit this work for review?');
}

var extForm = document.getElementById('extRequestForm');
if (extForm) {
    var _extSubmitting = false;
    extForm.addEventListener('submit', function(e) {
        e.preventDefault();
        if (_extSubmitting) return;

        var btn = document.getElementById('extSubmitBtn');
        var errDiv = document.getElementById('extFormError');
        var succDiv = document.getElementById('extFormSuccess');

        if (errDiv) { errDiv.classList.add('hidden'); errDiv.textContent = ''; }
        if (succDiv) { succDiv.classList.add('hidden'); succDiv.textContent = ''; }

        _extSubmitting = true;
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Submitting...';
        }

        var formData = new FormData(extForm);

        fetch('<?= e(base_url("api/request_extension.php")) ?>', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(res) {
            return res.json().then(function(data) {
                return { ok: res.ok, data: data };
            });
        })
        .then(function(result) {
            var data = result.data;
            if (result.ok && data.success) {
                if (succDiv) {
                    succDiv.textContent = data.message || 'Extension request submitted successfully.';
                    succDiv.classList.remove('hidden');
                }
                if (btn) {
                    btn.textContent = 'Submitted!';
                }
                setTimeout(function() {
                    window.location.reload();
                }, 1200);
            } else {
                _extSubmitting = false;
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Submit Request';
                }
                var errMsg = (data && (data.message || data.error)) ? (data.message || data.error) : 'Failed to submit extension request. Please try again.';
                if (errDiv) {
                    errDiv.textContent = errMsg;
                    errDiv.classList.remove('hidden');
                } else {
                    alert(errMsg);
                }
            }
        })
        .catch(function(err) {
            _extSubmitting = false;
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Submit Request';
            }
            if (errDiv) {
                errDiv.textContent = 'An unexpected error occurred. Please check your network connection and try again.';
                errDiv.classList.remove('hidden');
            } else {
                alert('An unexpected error occurred.');
            }
        });
    });
}
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
