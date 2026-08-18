<?php
$page_title = 'My Tasks';
require __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';

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

    if ($ms_action === 'start' && $milestone_id > 0) {
        // Funded → In Progress
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status
            FROM milestones m
            JOIN assignments a ON a.job_id = m.job_id AND a.freelancer_id = ?
            WHERE m.id = ? AND m.status = 'funded'
        ");
        $st->bind_param('ii', $fl_freelancer_id, $milestone_id);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            $conn->begin_transaction();
            try {
                $st = $conn->prepare("UPDATE milestones SET status = 'in_progress' WHERE id = ?");
                $st->bind_param('i', $milestone_id);
                $st->execute();
                $st->close();

                // Update assignment status to working (from any active state)
                $st = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND status IN ('assigned', 'submitted')");
                $st->bind_param('i', $ms['job_id']);
                $st->execute();
                $st->close();

                $conn->commit();
                set_flash('success', 'Milestone started! You can now work on it.');
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Failed to start milestone.');
            }
        } else {
            set_flash('error', 'Milestone not found or not funded yet.');
        }
    } elseif ($ms_action === 'submit' && $milestone_id > 0) {
        // In Progress / Revision Requested → Submitted
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, j.deadline as job_deadline
            FROM milestones m
            JOIN assignments a ON a.job_id = m.job_id AND a.freelancer_id = ?
            JOIN jobs j ON j.id = m.job_id
            WHERE m.id = ? AND m.status IN ('in_progress', 'revision_requested', 'overdue')
        ");
        $st->bind_param('ii', $fl_freelancer_id, $milestone_id);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            $now_dt = new DateTime();
            $ms_dl = !empty($ms['ms_deadline']) ? new DateTime($ms['ms_deadline']) : null;
            if ($ms_dl && $ms_dl <= $now_dt) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/my_tasks.php');
            }

            foreach (['submission_file', 'submission_note'] as $col) {
                $chk = $conn->query("SHOW COLUMNS FROM milestones LIKE '$col'");
                if (!$chk || $chk->num_rows === 0) {
                    $type = $col === 'submission_note' ? 'TEXT DEFAULT NULL' : 'VARCHAR(255) DEFAULT NULL AFTER submission_link';
                    $conn->query("ALTER TABLE milestones ADD COLUMN $col $type");
                }
            }

            $submission_link = trim($_POST['submission_link'] ?? '');
            $submission_note = trim($_POST['submission_note'] ?? '');
            $submission_file = null;

            if (!empty($_FILES['submission_file']['name'])) {
                $submission_file = upload_attachment($_FILES['submission_file']);
                if ($submission_file === null) {
                    set_flash('error', 'Invalid file. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR. Max 10MB.');
                    redirect('freelancer/milestone.php?id=' . $milestone_id);
                }
            }

            if ($submission_link === '' && $submission_file === null) {
                set_flash('error', 'Please provide a submission link or upload a file.');
                redirect('freelancer/milestone.php?id=' . $milestone_id);
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $file_for_db = $submission_file ?? '';
                $st = $conn->prepare("UPDATE milestones SET submission_link=?, submission_file=?, submission_note=?, status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('ssssi', $submission_link, $file_for_db, $submission_note, $now, $milestone_id);
                $st->execute();
                $st->close();

                $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE job_id=? AND status IN ('working', 'assigned')");
                $st->bind_param('i', $ms['job_id']);
                $st->execute();

                // Update job status
                $st_job = $conn->prepare("UPDATE jobs SET status='submitted' WHERE id=? AND status='in_progress'");
                $st_job->bind_param('i', $ms['job_id']);
                $st_job->execute();
                $st->close();

                $conn->commit();

                // Notify company (after commit so notification failure doesn't roll back submission)
                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', $fl_user['username'] . " submitted work for a milestone.", 'company/view_applications.php?id=' . $ms['job_id']);
                    }
                } catch (Exception $ne) {
                    error_log("Notification failed after submission: " . $ne->getMessage());
                }

                set_flash('success', 'Work submitted for review!');
            } catch (Exception $e) {
                $conn->rollback();
                if ($submission_file !== null) {
                    delete_attachment($submission_file);
                }
                set_flash('error', 'Failed to submit work. Please try again.');
            }
        } else {
            set_flash('error', 'Milestone not found or not ready for submission.');
        }
    } elseif ($ms_action === 'quick_submit' && $milestone_id > 0) {
        // Quick submit: change status to submitted without requiring link/file
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status, m.deadline as ms_deadline, j.deadline as job_deadline
            FROM milestones m
            JOIN assignments a ON a.job_id = m.job_id AND a.freelancer_id = ?
            JOIN jobs j ON j.id = m.job_id
            WHERE m.id = ? AND m.status IN ('in_progress', 'revision_requested', 'overdue')
              AND (m.freelancer_id = ? OR m.freelancer_id IS NULL)
        ");
        $st->bind_param('iii', $fl_freelancer_id, $milestone_id, $fl_freelancer_id);
        $st->execute();
        $ms = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms) {
            $now_dt = new DateTime();
            $ms_dl = !empty($ms['ms_deadline']) ? new DateTime($ms['ms_deadline']) : null;
            if ($ms_dl && $ms_dl <= $now_dt) {
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

                $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE job_id=? AND freelancer_id=? AND status IN ('working', 'assigned')");
                $st->bind_param('ii', $ms['job_id'], $fl_freelancer_id);
                $st->execute();

                // Update job status
                $st_job = $conn->prepare("UPDATE jobs SET status='submitted' WHERE id=? AND status='in_progress'");
                $st_job->bind_param('i', $ms['job_id']);
                $st_job->execute();
                $st->close();

                $conn->commit();

                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', $fl_user['username'] . " submitted work for a milestone.", 'company/view_applications.php?id=' . $ms['job_id']);
                    }
                } catch (Exception $ne) {
                    error_log("Notification failed after submission: " . $ne->getMessage());
                }

                set_flash('success', 'Milestone submitted for review!');
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Failed to submit milestone. Please try again.');
            }
        } else {
            set_flash('error', 'Milestone not found or not ready for submission.');
        }
    } elseif ($ms_action === 'submit_fixed_task') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
        $st = $conn->prepare("SELECT id, job_id, status, deadline FROM assignments WHERE id = ? AND freelancer_id = ? AND status IN ('assigned', 'working', 'overdue')");
        $st->bind_param('ii', $assignment_id, $fl_freelancer_id);
        $st->execute();
        $assignment = $st->get_result()->fetch_assoc();
        $st->close();

        if ($assignment) {
            if (!empty($assignment['deadline']) && new DateTime($assignment['deadline']) <= new DateTime()) {
                set_flash('error', 'Submission blocked: Deadline has passed.');
                redirect('freelancer/my_tasks.php');
            }

            $submission_note = trim($_POST['submission_note'] ?? '');
            $submission_file = null;

            if (!empty($_FILES['submission_file']['name'])) {
                $submission_file = upload_attachment($_FILES['submission_file']);
                if ($submission_file === null) {
                    set_flash('error', 'Invalid file. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR. Max 10MB.');
                    redirect('freelancer/my_tasks.php');
                }
            }

            if ($submission_note === '' && $submission_file === null) {
                set_flash('error', 'Please provide a submission note or upload a file.');
                redirect('freelancer/my_tasks.php');
            }

            $conn->begin_transaction();
            try {
                // Update assignment status
                $st = $conn->prepare("UPDATE assignments SET status = 'submitted' WHERE id = ?");
                $st->bind_param('i', $assignment_id);
                $st->execute();
                $st->close();

                // Update job status
                $st = $conn->prepare("UPDATE jobs SET status = 'submitted' WHERE id = ?");
                $st->bind_param('i', $assignment['job_id']);
                $st->execute();
                $st->close();

                // Insert into submissions
                $file_for_db = $submission_file ?? null;
                $st = $conn->prepare("INSERT INTO submissions (assignment_id, freelancer_id, file_path, notes, status) VALUES (?, ?, ?, ?, 'pending')");
                $st->bind_param('iiss', $assignment_id, $fl_freelancer_id, $file_for_db, $submission_note);
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
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', $fl_user['username'] . " submitted work for " . $ni['title'], 'company/view_applications.php?id=' . $assignment['job_id']);
                    }
                } catch (Exception $ne) {
                    error_log("Notification failed: " . $ne->getMessage());
                }

                set_flash('success', 'Work submitted for review!');
            } catch (Exception $e) {
                $conn->rollback();
                if ($submission_file !== null) {
                    delete_attachment($submission_file);
                }
                set_flash('error', 'Failed to submit work.');
            }
        } else {
            set_flash('error', 'Task not found or already submitted.');
        }
    }
    redirect('freelancer/my_tasks.php');
}

// Fetch assigned jobs with milestones
$tasks = [];
$st = $conn->prepare("
    SELECT a.id AS assignment_id, a.status AS assignment_status, a.assigned_at, a.deadline,
           a.budget, a.payment_type,
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
    $ms = $conn->prepare("SELECT * FROM milestones WHERE job_id = ? AND (freelancer_id = ? OR freelancer_id IS NULL) ORDER BY sort_order ASC");
    $ms->bind_param('ii', $task['job_id'], $fl_freelancer_id);
    $ms->execute();
    $mr = $ms->get_result();
    while ($m = $mr->fetch_assoc()) { $task['milestones'][] = $m; }
    $ms->close();
}
unset($task);

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
.ms-status { display:inline-flex; align-items:center; gap:0.25rem; padding:0.25rem 0.65rem; border-radius:9999px; font-size:0.7rem; font-weight:600; }
.ms-draft { background:rgba(107,114,128,0.1); color:#6b7280; }
.ms-funded { background:rgba(245,158,11,0.1); color:#f59e0b; }
.ms-in_progress { background:rgba(99,102,241,0.1); color:#6366f1; }
.ms-submitted { background:rgba(139,92,246,0.1); color:#8b5cf6; }
.ms-approved { background:rgba(16,185,129,0.1); color:#10b981; }
.ms-revision_requested { background:rgba(239,68,68,0.1); color:#ef4444; }
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
        <p class="text-xl font-semibold mb-2">No assigned tasks yet.</p>
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
                    <?php if ($all_approved): ?>
                        <span class="ms-status ms-approved">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Project Completed
                        </span>
                    <?php elseif (!empty($task['deadline']) && new DateTime($task['deadline']) <= new DateTime() && in_array($task['assignment_status'], ['working', 'assigned'])): ?>
                        <span class="ms-status ms-revision_requested">
                            Deadline Passed
                        </span>
                    <?php else: ?>
                        <?= status_badge($task['assignment_status']) ?>
                    <?php endif; ?>
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
                    <span style="color:var(--color-text-muted)">Budget (<?= ucfirst(e($task['payment_type'] ?? 'fixed')) ?>): <strong class="text-primary-600"><?= number_format((float) $task['budget'], 2) ?> MMK</strong></span>
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
                            $status_labels = ['draft'=>'Draft','funded'=>'Funded','in_progress'=>'In Progress','submitted'=>'Under Review','approved'=>'Approved', 'payment_pending'=>'Payment Pending', 'paid'=>'Received', 'revision_requested'=>'Revision Requested'];
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
                                            <?php if (!empty($ms['deadline'])): ?><p class="text-[11px] mt-0.5" style="color:var(--color-text-muted)">Due: <?= date('M j, Y', strtotime($ms['deadline'])) ?></p><?php endif; ?>
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
                                    $steps = ['funded', 'in_progress', 'submitted', 'payment_pending', 'paid'];
                                    $step_labels = ['Funded', 'Working', 'Submitted', 'Pending Pay', 'Received'];
                                    $current_idx = array_search($ms['status'], $steps);
                                    if ($ms['status'] === 'revision_requested') $current_idx = 1;
                                    if ($ms['status'] === 'draft') $current_idx = -1;
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
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)">Waiting for escrow funding</span>
                                </div>
                            <?php elseif ($ms['status'] === 'funded'): ?>
                                <div class="p-3 flex items-center justify-between" style="border-top:1px solid var(--color-border)">
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)">Escrow funded — ready to start</span>
                                    <a href="<?= e(base_url('freelancer/milestone.php?id=' . $ms['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">Start Working</a>
                                </div>
                            <?php elseif ($ms['status'] === 'in_progress' || $ms['status'] === 'revision_requested'): ?>
                                <div class="p-3 flex items-center justify-between" style="border-top:1px solid var(--color-border)">
                                    <span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= $ms['status'] === 'revision_requested' ? 'Revision needed — resubmit work' : 'Working on this milestone' ?></span>
                                    <div class="flex items-center gap-2">
                                        <?php 
                                        $ms_dl = !empty($ms['deadline']) ? new DateTime($ms['deadline']) : null;
                                        $job_dl = !empty($task['deadline']) ? new DateTime($task['deadline']) : null;
                                        $now = new DateTime();
                                        $can_submit = true;
                                        if (($ms_dl && $ms_dl <= $now) || ($job_dl && $job_dl <= $now)) {
                                            $can_submit = false;
                                        }
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
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="pt-4 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-bold mb-4" style="color:var(--color-text-primary)">Assignment Delivery</h3>
                    
                    <?php if ($task['assignment_status'] === 'working' || $task['assignment_status'] === 'assigned'): ?>
                        <?php 
                        $can_submit_fixed = true;
                        $job_dl = !empty($task['deadline']) ? new DateTime($task['deadline']) : null;
                        if ($job_dl && $job_dl <= new DateTime()) {
                            $can_submit_fixed = false;
                        }
                        ?>
                        <?php if ($can_submit_fixed): ?>
                        <div class="p-5 rounded-xl border" style="background:var(--color-bg);border-color:var(--color-border)">
                            <h4 class="text-sm font-semibold mb-2" style="color:var(--color-text-primary)">Submit Your Work</h4>
                            <p class="text-xs mb-4" style="color:var(--color-text-secondary)">Please provide your completed work or a link to the project files. Add a note explaining what you have done.</p>
                            
                            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Submit this work for review?')">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="ms_action" value="submit_fixed_task">
                                <input type="hidden" name="assignment_id" value="<?= (int) $task['assignment_id'] ?>">
                                
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--color-text-secondary)">Submission Note / Message</label>
                                    <textarea name="submission_note" rows="3" required class="w-full px-3 py-2 rounded-lg text-sm border focus:ring-2 focus:ring-primary-500" style="background:var(--color-card);border-color:var(--color-border);color:var(--color-text-primary)" placeholder="I have completed the requested work..."></textarea>
                                </div>
                                
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold mb-1" style="color:var(--color-text-secondary)">Attachment (Optional)</label>
                                    <input type="file" name="submission_file" class="w-full text-sm">
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
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
