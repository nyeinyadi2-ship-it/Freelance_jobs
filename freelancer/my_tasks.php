<?php
$page_title = 'My Projects';
require __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';
require_once __DIR__ . '/../includes/job_helpers.php';

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
        redirect('freelancer/my_tasks.php');
    }
    $ms_action = $_POST['ms_action'] ?? '';
    $milestone_id = (int) ($_POST['milestone_id'] ?? 0);

    if ($ms_action === 'submit' && $milestone_id > 0) {
        // In Progress → Submitted (Rejection blocks resubmissions)
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, j.deadline as job_deadline, a.id as assignment_id, a.freelancer_id as asgn_fl_id, m.freelancer_id as ms_fl_id
            FROM milestones m
            LEFT JOIN assignments a ON a.job_id = m.job_id AND (a.freelancer_id = m.freelancer_id OR a.freelancer_id = ?)
            JOIN jobs j ON j.id = m.job_id
            WHERE m.id = ? AND m.status IN ('funded', 'in_progress', 'overdue', 'revision_requested')
              AND (m.freelancer_id = ? OR a.freelancer_id = ? OR m.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = ?))
        ");
        $st->bind_param('iiiii', $fl_freelancer_id, $milestone_id, $fl_freelancer_id, $fl_freelancer_id, $fl_uid);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            if (is_deadline_passed($ms['ms_deadline']) || is_deadline_passed($ms['job_deadline'])) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/my_tasks.php');
            }

            $submission_note = trim($_POST['submission_note'] ?? '');
            $submission_file = null;

            $upload_err = null;
            if (!empty($_FILES['submission_file']['name'])) {
                $submission_file = upload_attachment($_FILES['submission_file'], 500 * 1024 * 1024, $upload_err);
                if ($submission_file === null) {
                    $msg = $upload_err ? 'File upload error: ' . $upload_err : 'Invalid file. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR. Max 500MB.';
                    set_flash('error', $msg);
                    redirect('freelancer/my_tasks.php');
                }
            }

            if (empty($submission_file)) {
                set_flash('error', 'Please upload a file to submit your work.');
                redirect('freelancer/my_tasks.php');
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $file_for_db = $submission_file ?? '';
                $link_for_db = '';

                $st = $conn->prepare("UPDATE milestones SET submission_link=?, submission_file=?, submission_note=?, status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('ssssi', $link_for_db, $file_for_db, $submission_note, $now, $milestone_id);
                $st->execute();
                $st->close();

                $assignment_id = (int) ($ms['assignment_id'] ?? 0);
                if ($assignment_id > 0) {
                    $v_stmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM submissions WHERE assignment_id = ?");
                    $v_stmt->bind_param('i', $assignment_id);
                    $v_stmt->execute();
                    $version = (int) ($v_stmt->get_result()->fetch_row()[0] ?? 1);
                    $v_stmt->close();

                    $sub_file = !empty($submission_file) ? $submission_file : null;
                    $sub_link = null;
                    $sub_notes = !empty($submission_note) ? $submission_note : null;
                    $sub_fl_id = (int) ($ms['asgn_fl_id'] ?: ($ms['ms_fl_id'] ?: $fl_freelancer_id));

                    $sub_st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, file_path, github_link, notes, version, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                    $sub_st->bind_param('iisssi', $assignment_id, $sub_fl_id, $sub_file, $sub_link, $sub_notes, $version);
                    $sub_st->execute();
                    $sub_st->close();

                    $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE id=? AND status IN ('working', 'assigned', 'overdue', 'revision_requested') AND status != 'completed'");
                    $st->bind_param('i', $assignment_id);
                    $st->execute();
                    $st->close();
                }

                $conn->commit();

                // Notify company (after commit so notification failure doesn't roll back submission)
                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', "Submitted work for a milestone.", 'company/view_applications.php?id=' . $ms['job_id'], $fl_uid);
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
    } elseif ($ms_action === 'request_extension' && $milestone_id > 0) {
        $requested_deadline = trim($_POST['requested_deadline'] ?? '');
        $reason = trim($_POST['extension_reason'] ?? '');

        if (empty($requested_deadline)) {
            set_flash('error', 'Please provide a new deadline date.');
            redirect('freelancer/my_tasks.php');
        }

        $st = $conn->prepare("SELECT m.id, m.freelancer_id, m.deadline, m.status, m.extension_requested, j.title AS job_title, j.id AS job_id FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.freelancer_id = ? AND m.status IN ('overdue', 'in_progress', 'funded')");
        $st->bind_param('ii', $milestone_id, $fl_freelancer_id);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            // One-time extension rule
            if ((int)$ms['extension_requested'] === 1) {
                set_flash('error', 'You have already submitted an extension request for this milestone. Only one request is allowed.');
                redirect('freelancer/my_tasks.php');
            }

            $current_deadline = $ms['deadline'] ?? date('Y-m-d H:i:s');
            $requested_dt     = date('Y-m-d H:i:s', strtotime($requested_deadline));
            $now_ts           = date('Y-m-d H:i:s');

            // Atomic update — prevents double-click
            $upd = $conn->prepare("
                UPDATE milestones
                SET extension_requested    = 1,
                    extension_deadline     = ?,
                    extension_reason       = ?,
                    extension_status       = 'pending',
                    extension_requested_at = ?
                WHERE id = ? AND extension_requested = 0
            ");
            $upd->bind_param('sssi', $requested_dt, $reason, $now_ts, $milestone_id);
            $upd->execute();
            $affected = $upd->affected_rows;
            $upd->close();

            if ($affected === 0) {
                set_flash('error', 'Extension request already submitted for this milestone.');
                redirect('freelancer/my_tasks.php');
            }

            // Record history
            record_milestone_history(
                $conn,
                $milestone_id,
                $fl_freelancer_id,
                null,
                $fl_user['id'] ?? null,
                $ms['status'],
                $ms['status'],
                'EXTENSION_REQUESTED',
                'Requested extension to ' . date('Y-m-d', strtotime($requested_dt)) . '. Reason: ' . $reason,
                $current_deadline,
                $requested_dt
            );

            try {
                $ns = $conn->prepare("SELECT c.user_id FROM milestones m JOIN jobs j ON m.job_id = j.id JOIN companies c ON j.company_id = c.id WHERE m.id = ?");
                $ns->bind_param('i', $milestone_id);
                $ns->execute();
                $ni = $ns->get_result()->fetch_assoc();
                $ns->close();
                if ($ni) {
                    create_notification($conn, (int)$ni['user_id'], 'admin_announcement', "Requested a deadline extension for a milestone in \"{$ms['job_title']}\".", 'company/view_applications.php?id=' . $ms['job_id'], $fl_uid);
                }
            } catch (Throwable $ne) {
                error_log('Notification failed: ' . $ne->getMessage());
            }

            set_flash('success', 'Extension request submitted. Waiting for company approval.');
        } else {
            set_flash('error', 'Milestone not found or not eligible for extension.');
        }
    } elseif ($ms_action === 'quick_submit' && $milestone_id > 0) {
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, j.deadline as job_deadline, a.id as assignment_id, a.freelancer_id as asgn_fl_id, m.freelancer_id as ms_fl_id
            FROM milestones m
            LEFT JOIN assignments a ON a.job_id = m.job_id AND (a.freelancer_id = m.freelancer_id OR a.freelancer_id = ?)
            JOIN jobs j ON j.id = m.job_id
            WHERE m.id = ? AND m.status IN ('funded', 'in_progress', 'revision_requested', 'overdue')
              AND (m.freelancer_id = ? OR a.freelancer_id = ? OR m.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = ?))
        ");
        $st->bind_param('iiiii', $fl_freelancer_id, $milestone_id, $fl_freelancer_id, $fl_freelancer_id, $fl_uid);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            if (is_deadline_passed($ms['ms_deadline']) || is_deadline_passed($ms['job_deadline'])) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/my_tasks.php');
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $st = $conn->prepare("UPDATE milestones SET status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('si', $now, $milestone_id);
                $st->execute();
                $st->close();

                $assignment_id = (int) ($ms['assignment_id'] ?? 0);
                if ($assignment_id > 0) {
                    $v_stmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM submissions WHERE assignment_id = ?");
                    $v_stmt->bind_param('i', $assignment_id);
                    $v_stmt->execute();
                    $version = (int) ($v_stmt->get_result()->fetch_row()[0] ?? 1);
                    $v_stmt->close();

                    $quick_note = "Quick submission for milestone #" . $ms['id'];
                    $sub_fl_id = (int) ($ms['asgn_fl_id'] ?: ($ms['ms_fl_id'] ?: $fl_freelancer_id));

                    $sub_st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, notes, version, status) VALUES (?, ?, ?, ?, 'pending')");
                    $sub_st->bind_param('iisi', $assignment_id, $sub_fl_id, $quick_note, $version);
                    $sub_st->execute();
                    $sub_st->close();

                    $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE id=? AND status IN ('working', 'assigned', 'overdue', 'revision_requested')");
                    $st->bind_param('i', $assignment_id);
                    $st->execute();
                    $st->close();
                }

                $conn->commit();

                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', "Submitted work for a milestone.", 'company/view_applications.php?id=' . $ms['job_id'], $fl_uid);
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
    } elseif ($ms_action === 'submit_fixed_task') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        // Check if assignment or any submission is rejected
        $chk_rej = $conn->prepare("SELECT status FROM assignments WHERE id = ?");
        $chk_rej->bind_param('i', $assignment_id);
        $chk_rej->execute();
        $asgn_row = $chk_rej->get_result()->fetch_assoc();
        $chk_rej->close();

        if ($asgn_row && $asgn_row['status'] === 'rejected') {
            set_flash('error', 'Submissions are permanently disabled because this project has been rejected.');
            redirect('freelancer/my_tasks.php');
        }

        $st = $conn->prepare("SELECT id, job_id, status, deadline, freelancer_id FROM assignments WHERE id = ? AND (freelancer_id = ? OR freelancer_id IN (SELECT id FROM freelancers WHERE user_id = ?)) AND status IN ('assigned', 'working', 'overdue', 'revision_requested') AND status != 'completed'");
        $st->bind_param('iii', $assignment_id, $fl_freelancer_id, $fl_uid);
        $st->execute();
        $assignment = $st->get_result()->fetch_assoc();
        $st->close();

        if ($assignment) {
            if (is_deadline_passed($assignment['deadline'])) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/my_tasks.php');
            }

            $submission_note = trim($_POST['submission_note'] ?? '');
            $submission_file = null;

            $upload_err = null;
            if (!empty($_FILES['submission_file']['name'])) {
                $submission_file = upload_attachment($_FILES['submission_file'], 500 * 1024 * 1024, $upload_err);
                if ($submission_file === null) {
                    $msg = $upload_err ? 'File upload error: ' . $upload_err : 'Invalid file. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR. Max 500MB.';
                    set_flash('error', $msg);
                    redirect('freelancer/my_tasks.php');
                }
            }

            if (empty($submission_file)) {
                set_flash('error', 'Please upload a file to submit your work.');
                redirect('freelancer/my_tasks.php');
            }

            $conn->begin_transaction();
            try {
                // Calculate next version
                $v_stmt = $conn->prepare("SELECT COALESCE(MAX(version), 0) + 1 FROM submissions WHERE assignment_id = ?");
                $v_stmt->bind_param('i', $assignment_id);
                $v_stmt->execute();
                $version = (int) ($v_stmt->get_result()->fetch_row()[0] ?? 1);
                $v_stmt->close();

                // Update assignment status
                $st = $conn->prepare("UPDATE assignments SET status = 'submitted' WHERE id = ? AND status != 'completed'");
                $st->bind_param('i', $assignment_id);
                $st->execute();
                $st->close();

                // Insert into unified submissions
                $file_for_db = !empty($submission_file) ? $submission_file : null;
                $link_for_db = null;
                $notes_for_db = !empty($submission_note) ? $submission_note : null;
                $sub_fl_id = (int) ($assignment['freelancer_id'] ?: $fl_freelancer_id);

                $st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, file_path, github_link, notes, version, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
                $st->bind_param('iisssi', $assignment_id, $sub_fl_id, $file_for_db, $link_for_db, $notes_for_db, $version);
                $st->execute();
                $st->close();

                $conn->commit();

                // Notify company
                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $assignment['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', "Submitted work for " . $ni['title'], 'company/view_applications.php?id=' . $assignment['job_id'], $fl_uid);
                    }
                } catch (Throwable $ne) {
                    error_log("Notification failed: " . $ne->getMessage());
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
            set_flash('error', 'Task not found or not ready for submission.');
        }
    }
    redirect('freelancer/my_tasks.php');
}

// Fetch assigned jobs with milestones
$tasks = [];
$st = $conn->prepare("
    SELECT a.id AS assignment_id, a.status AS assignment_status, a.assignment_type, a.assigned_at, a.deadline,
           a.budget, a.payment_type, a.rejection_reason,
           j.id AS job_id, j.title, j.description, j.status AS job_status,
           c.company_name, c.logo_image
    FROM assignments a
    JOIN jobs j ON a.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    WHERE a.freelancer_id = ?
    ORDER BY a.assigned_at DESC
");
$st->bind_param('i', $fl_freelancer_id);
$st->execute();
$r = $st->get_result();
while ($row = $r->fetch_assoc()) { $tasks[] = $row; }
$st->close();

// Fetch milestones for each task
foreach ($tasks as &$task) {
    $task['milestones'] = [];
    $ms = $conn->prepare("
        SELECT m1.* 
        FROM milestones m1
        WHERE m1.job_id = ? 
          AND m1.freelancer_id = ?
        ORDER BY m1.sort_order ASC
    ");
    $ms->bind_param('ii', $task['job_id'], $fl_freelancer_id);
    $ms->execute();
    $mr = $ms->get_result();
    while ($m = $mr->fetch_assoc()) { $task['milestones'][] = $m; }
    $ms->close();

    // Fetch submissions for assignment
    $task['submissions'] = [];
    $sub_st = $conn->prepare("
        SELECT id, file_path, github_link, notes, version, status, revision_notes, created_at
        FROM submissions
        WHERE assignment_id = ?
        ORDER BY version DESC
    ");
    $sub_st->bind_param('i', $task['assignment_id']);
    $sub_st->execute();
    $sub_res = $sub_st->get_result();
    while ($sub_row = $sub_res->fetch_assoc()) {
        $task['submissions'][] = $sub_row;
    }
    $sub_st->close();
}
unset($task);

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
.ms-status { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.65rem; border-radius:9999px; font-size:0.7rem; font-weight:600; }
.ms-draft { background:rgba(107,114,128,0.1); color:#6b7280; }
.ms-funded { background:rgba(59,130,246,0.1); color:#3b82f6; }
.ms-in_progress { background:rgba(99,102,241,0.1); color:#6366f1; }
.ms-submitted { background:rgba(139,92,246,0.1); color:#8b5cf6; }
.ms-approved { background:rgba(16,185,129,0.1); color:#10b981; }
.ms-revision_requested { background:rgba(239,68,68,0.1); color:#ef4444; }
.ms-overdue { background:rgba(220,38,38,0.1); color:#dc2626; }
.ms-cancelled { background:rgba(107,114,128,0.1); color:#6b7280; }
.ms-payment_pending { background:rgba(59,130,246,0.1); color:#3b82f6; }
.ms-paid { background:rgba(16,185,129,0.1); color:#10b981; }

.ms-timeline { display:flex; align-items:center; gap:0; margin:0.75rem 0; }
.ms-timeline-step { display:flex; align-items:center; gap:0.375rem; }
.ms-timeline-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.ms-timeline-line { width:24px; height:2px; flex-shrink:0; }
.ms-tl-active { background:linear-gradient(135deg,#6366f1,#8b5cf6); }
.ms-tl-done { background:#10b981; }
.ms-tl-pending { background:var(--color-border); }
.ms-tl-line-active { background:linear-gradient(90deg,#10b981,#6366f1); }
.ms-tl-line-done { background:#10b981; }
.ms-tl-line-pending { background:var(--color-border); }

.upload-zone { border:2px dashed var(--color-border); border-radius:0.75rem; padding:1.25rem; text-align:center; cursor:pointer; transition:all .3s; }
.upload-zone:hover { border-color:#6366f1; background:rgba(99,102,241,0.03); }
.upload-zone.has-file { border-color:#10b981; background:rgba(16,185,129,0.03); }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">
<?php if (empty($tasks)): ?>
    <div class="glass rounded-2xl text-center py-20" style="color:var(--color-text-placeholder)">
        <svg class="w-24 h-24 mx-auto mb-6 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        <p class="text-xl font-semibold mb-2">No assigned projects yet.</p>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white mt-3">Browse available jobs</a>
    </div>
<?php else: ?>
    <div class="space-y-6">
        <?php foreach ($tasks as $task):
            $total_ms = count($task['milestones']);
            $approved_ms = 0;
            foreach ($task['milestones'] as $m) {
                if ($m['status'] === 'paid' || $m['status'] === 'payment_pending') $approved_ms++;
            }
            $all_approved = $total_ms > 0 && $approved_ms === $total_ms;
            $progress = $total_ms > 0 ? round(($approved_ms / $total_ms) * 100) : 0;
        ?>
            <div class="glass rounded-2xl p-6 hover-lift reveal">
                <!-- Header -->
                <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                    <div class="flex items-center gap-3">
                        <?php if ($task['logo_image']): ?><img src="<?= e(base_url('uploads/images/' . $task['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php endif; ?>
                        <div>
                            <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></p>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($task['title']) ?></h2>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <?php if (!empty($task['assignment_type']) && $task['assignment_type'] === 'direct_hire'): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 border border-purple-200 dark:border-purple-800/50">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Direct Hire
                            </span>
                        <?php endif; ?>
                        <?php if ($task['assignment_status'] === 'completed' || $all_approved): ?>
                            <?= project_status_badge('completed') ?>
                        <?php else: ?>
                            <?= project_status_badge($task['assignment_status']) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="text-sm mb-3 leading-relaxed" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($task['description'] ?? '', 0, 200, '...')) ?></p>

                <!-- Progress bar -->
                <?php if ($total_ms > 0): ?>
                <div class="mb-4">
                    <div class="flex items-center justify-between text-xs mb-1.5">
                        <span style="color:var(--color-text-muted)">Project Progress</span>
                        <span class="font-bold" style="color:var(--color-text-primary)"><?= $approved_ms ?>/<?= $total_ms ?> milestones &middot; <?= $progress ?>%</span>
                    </div>
                    <div class="w-full h-2.5 rounded-full overflow-hidden" style="background:var(--color-border)">
                        <div class="h-full rounded-full transition-all duration-700" style="width:<?= $progress ?>%;background:linear-gradient(135deg,#6366f1,#8b5cf6)"></div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="flex flex-wrap items-center gap-4 text-sm mb-4">
                    <span style="color:var(--color-text-muted)">Budget (<?= ($task['payment_type'] ?? 'fixed') === 'fixed' ? 'Project Payment' : ucfirst(e($task['payment_type'] ?? 'fixed')) ?>): <strong class="text-primary-600"><?= number_format((float) $task['budget'], 2) ?> MMK</strong></span>
                    <span style="color:var(--color-text-placeholder)">Assigned <?= date('M j, Y', strtotime($task['assigned_at'])) ?></span>
                    <?php if (!empty($task['deadline'])): ?>
                        <?php 
                        $dl_date = new DateTime($task['deadline']);
                        $now = new DateTime();
                        $is_overdue = $dl_date <= $now;
                        $dl_class = $is_overdue ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-700 dark:text-gray-300 font-semibold';
                        ?>
                        <span style="color:var(--color-text-muted)">Deadline: <span class="<?= $dl_class ?>"><?= date('M j, Y', strtotime($task['deadline'])) ?></span></span>
                    <?php endif; ?>
                </div>

                <!-- Milestones -->
                <?php if (!empty($task['milestones'])): ?>
                <div class="pt-4 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-bold mb-4" style="color:var(--color-text-primary)">Milestones</h3>
                    <div class="space-y-4">
                        <?php foreach ($task['milestones'] as $ms):
                            $status_labels = ['draft'=>'Draft','funded'=>'Funded','in_progress'=>'In Progress','submitted'=>'Under Review','approved'=>'Approved', 'payment_pending'=>'Payment Pending', 'paid'=>'Received', 'revision_requested'=>'Revision Requested', 'overdue'=>'Overdue', 'cancelled'=>'Cancelled'];
                            $status_class = 'ms-' . $ms['status'];
                        ?>
                        <div class="rounded-xl overflow-hidden transition-all hover:shadow-md" style="border:1px solid var(--color-border)">
                            <!-- Milestone Header (clickable) -->
                            <a href="<?= e(base_url('freelancer/milestone.php?id=' . $ms['id'])) ?>" class="block p-4 transition-colors" style="background:var(--color-bg);text-decoration:none">
                                <div class="flex flex-wrap items-start justify-between gap-2">
                                    <div class="flex items-start gap-3">
                                        <?php if ($ms['status'] === 'paid'): ?>
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(16,185,129,0.1)">
                                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5 text-xs font-bold" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-muted)"><?= $ms['sort_order'] ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="text-sm font-bold" style="color:var(--color-text-primary)"><?= e($ms['title']) ?></p>
                                            <?php if ($ms['description']): ?><p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e(mb_strimwidth($ms['description'], 0, 80, '...')) ?></p><?php endif; ?>
                                            <?php if (!empty($ms['deadline'])): ?><p class="text-[11px] mt-0.5" style="color:var(--color-text-muted)">Due: <?= date('M j, Y, g:i A', strtotime($ms['deadline'])) ?></p><?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-bold" style="color:#f59e0b"><?= number_format((float) $ms['amount'], 2) ?> MMK</span>
                                        <span class="ms-status <?= $status_class ?>"><?= $status_labels[$ms['status']] ?? $ms['status'] ?></span>
                                        <svg class="w-4 h-4 flex-shrink-0" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                    </div>
                                </div>

                                <!-- Status Timeline -->
                                <div class="ms-timeline mt-3">
                                    <?php
                                    $steps = ['draft', 'in_progress', 'submitted', 'payment_pending', 'paid'];
                                    $step_labels = ['Draft', 'Working', 'Submitted', 'Pending Pay', 'Received'];
                                    $current_idx = array_search($ms['status'], $steps);
                                    if ($ms['status'] === 'revision_requested') $current_idx = 1;
                                    if ($ms['status'] === 'funded') $current_idx = 1;
                                    if ($current_idx === false) $current_idx = -1;
                                    ?>
                                    <?php for ($si = 0; $si < count($steps); $si++): ?>
                                        <div class="ms-timeline-step">
                                            <div class="ms-timeline-dot <?= $si < $current_idx ? 'ms-tl-done' : ($si === $current_idx ? 'ms-tl-active' : 'ms-tl-pending') ?>"></div>
                                            <span class="text-[10px] font-semibold <?= $si <= $current_idx ? '' : 'opacity-40' ?>" style="color:<?= $si <= $current_idx ? 'var(--color-text-primary)' : 'var(--color-text-muted)' ?>"><?= $step_labels[$si] ?></span>
                                        </div>
                                        <?php if ($si < count($steps) - 1): ?>
                                            <div class="ms-timeline-line <?= $si < $current_idx ? 'ms-tl-line-done' : ($si === $current_idx ? 'ms-tl-line-active' : 'ms-tl-line-pending') ?>"></div>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                            </a>

                            <!-- Milestone Body -->
                            <?php if ($ms['status'] === 'draft'): ?>
                                <div class="p-3 flex items-center gap-2" style="border-top:1px solid var(--color-border)">
                                    <svg class="w-3.5 h-3.5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)">Waiting for company to start</span>
                                </div>
                            <?php elseif ($ms['status'] === 'in_progress' || $ms['status'] === 'funded' || $ms['status'] === 'revision_requested'): ?>
                                <div class="p-3 flex items-center justify-between" style="border-top:1px solid var(--color-border)">
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= $ms['status'] === 'revision_requested' ? 'Revision needed — resubmit work' : 'Working on this milestone' ?></span>
                                    <div class="flex items-center gap-2">
                                        <?php 
                                        $can_submit = !is_deadline_passed($ms['deadline'] ?? null) && !is_deadline_passed($task['deadline'] ?? null);
                                        ?>
                                        <?php if ($can_submit): ?>
                                        <form method="POST" style="display:inline" onsubmit="return confirm('Submit this milestone for review?')">
                                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="ms_action" value="quick_submit">
                                            <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background:linear-gradient(135deg,#10b981,#059669)">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                                Submit Milestone
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-xs font-bold text-red-500">Deadline Passed</span>
                                        <?php endif; ?>
                                        <a href="<?= e(base_url('freelancer/milestone.php?id=' . $ms['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background:linear-gradient(135deg,#8b5cf6,#6366f1)">Details</a>
                                    </div>
                                </div>
                            <?php elseif ($ms['status'] === 'submitted'): ?>
                                <div class="p-3 flex items-center gap-2" style="border-top:1px solid var(--color-border)">
                                    <div class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></div>
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)">Under review — awaiting decision</span>
                                </div>
                            <?php elseif ($ms['status'] === 'payment_pending'): ?>
                                <div class="p-3 flex items-center gap-2" style="border-top:1px solid var(--color-border);background:rgba(59,130,246,0.03)">
                                    <svg class="w-3.5 h-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    <span class="text-xs font-medium text-blue-600">Work approved — awaiting payment</span>
                                </div>
                            <?php elseif ($ms['status'] === 'paid'): ?>
                                <div class="p-3 flex items-center gap-2" style="border-top:1px solid var(--color-border);background:rgba(16,185,129,0.03)">
                                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    <span class="text-xs font-medium text-emerald-600">Payment received</span>
                                </div>
                            <?php elseif ($ms['status'] === 'overdue'): ?>
                                <div class="p-3 flex items-center justify-between" style="border-top:1px solid var(--color-border);background:rgba(220,38,38,0.03)">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-3.5 h-3.5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span class="text-xs font-medium text-red-600">Overdue</span>
                                    </div>
                                    <a href="<?= e(base_url('freelancer/milestone.php?id=' . $ms['id'])) ?>" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-[11px] font-semibold text-white" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">Request Extension</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="pt-4 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-bold mb-4" style="color:var(--color-text-primary)">Assignment Delivery</h3>
                    
                    <?php if (in_array($task['assignment_status'], ['working', 'assigned', 'overdue', 'revision_requested']) && $task['assignment_status'] !== 'completed'): ?>
                        <?php 
                        $can_submit_fixed = !is_deadline_passed($task['deadline'] ?? null);
                        ?>
                        <?php if ($can_submit_fixed): ?>
                        <div class="p-5 rounded-xl border" style="background:var(--color-bg);border-color:var(--color-border)">
                            <h4 class="text-sm font-semibold mb-2" style="color:var(--color-text-primary)">Submit Your Work</h4>
                            <p class="text-xs mb-4" style="color:var(--color-text-secondary)">Please upload your completed work file and optional submission notes.</p>
                            
                            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Submit this work for review?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="ms_action" value="submit_fixed_task">
                                <input type="hidden" name="assignment_id" value="<?= (int) $task['assignment_id'] ?>">
                                
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--color-text-secondary)">Upload Work File <span class="text-red-500">*</span></label>
                                    <input type="file" name="submission_file" required class="w-full text-sm">
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--color-text-secondary)">Submission Note / Message (Optional)</label>
                                    <textarea name="submission_note" rows="3" class="w-full px-3 py-2 rounded-lg text-sm border focus:ring-2 focus:ring-primary-500" style="background:var(--color-card);border-color:var(--color-border);color:var(--color-text-primary)" placeholder="I have completed the requested work..."></textarea>
                                </div>
                                
                                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold text-white shadow-lg" style="background:linear-gradient(135deg,#10b981,#059669)">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                    Submit Work
                                </button>
                            </form>
                        </div>
                        <?php else: ?>
                        <div class="p-4 rounded-xl border flex items-center gap-3" style="background:rgba(239,68,68,0.05);border-color:rgba(239,68,68,0.2)">
                            <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <span class="text-sm font-semibold text-red-600">The deadline for this assignment has passed. Submission is no longer allowed.</span>
                        </div>
                        <?php endif; ?>
                    <?php elseif ($task['assignment_status'] === 'submitted'): ?>
                        <div class="p-4 rounded-xl border flex items-center gap-3" style="background:rgba(139,92,246,0.05);border-color:rgba(139,92,246,0.2)">
                            <div class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></div>
                            <span class="text-sm font-semibold text-purple-600">Work submitted successfully. Awaiting company review.</span>
                        </div>
                    <?php elseif ($task['assignment_status'] === 'payment_pending'): ?>
                        <div class="p-4 rounded-xl border flex items-center gap-3" style="background:rgba(59,130,246,0.05);border-color:rgba(59,130,246,0.2)">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span class="text-sm font-semibold text-blue-600">Work approved. Awaiting payment from the company.</span>
                        </div>
                    <?php elseif ($task['assignment_status'] === 'completed'): ?>
                        <div class="p-4 rounded-xl border flex items-center gap-3" style="background:rgba(16,185,129,0.05);border-color:rgba(16,185,129,0.2)">
                            <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <span class="text-sm font-semibold text-emerald-600">Project completed and payment processed.</span>
                        </div>
                    <?php elseif ($task['assignment_status'] === 'rejected'): ?>
                        <div class="p-4 rounded-xl border border-red-200 dark:border-red-800 bg-red-50/40 dark:bg-red-900/10">
                            <div class="flex items-center justify-between gap-2 mb-3 pb-2 border-b border-red-200 dark:border-red-800">
                                <h4 class="text-xs font-bold uppercase tracking-wider text-red-600 dark:text-red-400">Assignment Permanently Rejected</h4>
                                <?= status_badge('rejected') ?>
                            </div>

                            <?php if (!empty($task['rejection_reason'])): ?>
                                <div class="mb-3 p-3 rounded-lg bg-red-100/60 dark:bg-red-900/30 text-xs text-red-700 dark:text-red-300">
                                    <span class="font-bold block mb-1">Company Rejection Reason:</span>
                                    <p class="leading-relaxed"><?= nl2br(e($task['rejection_reason'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Submitted Work Results Details (Displayed ONLY AFTER freelancer submits work) -->
                    <?php if (!empty($task['submissions'])): ?>
                        <div class="mt-4 p-4 rounded-xl border" style="background:var(--color-bg);border-color:var(--color-border)">
                            <h4 class="text-xs font-bold uppercase tracking-wider mb-3" style="color:var(--color-text-muted)">Submitted Work & Results History</h4>
                            <div class="space-y-3">
                                <?php foreach ($task['submissions'] as $sub): ?>
                                    <div class="p-3 rounded-lg border text-xs" style="background:var(--color-card);border-color:var(--color-border)">
                                        <div class="flex items-center justify-between gap-2 mb-2">
                                            <span class="font-bold text-gray-800 dark:text-gray-200">Submission v<?= (int)$sub['version'] ?></span>
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-400"><?= date('M j, Y, g:i A', strtotime($sub['created_at'])) ?></span>
                                                <?= status_badge($sub['status']) ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($sub['notes'])): ?>
                                            <p class="text-gray-700 dark:text-gray-300 mb-2"><?= nl2br(e($sub['notes'])) ?></p>
                                        <?php endif; ?>

                                        <?php if (!empty($sub['file_path'])): ?>
                                            <a href="<?= e(base_url('api/download_submission.php?submission_id=' . (int)$sub['id'])) ?>" target="_blank" class="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 font-semibold hover:underline">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                Download Attached File
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
