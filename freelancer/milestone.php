<?php
$page_title = 'Milestone Details';
require __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';

$ms_id = (int) ($_GET['id'] ?? 0);
if ($ms_id <= 0) { redirect('freelancer/my_tasks.php'); }

// Handle milestone actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $ms_action = $_POST['ms_action'] ?? '';
    $post_milestone_id = (int) ($_POST['milestone_id'] ?? 0);

    if ($ms_action === 'start' && $post_milestone_id > 0) {
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status
            FROM milestones m
            JOIN assignments a ON a.job_id = m.job_id AND a.freelancer_id = ?
            WHERE m.id = ? AND m.status = 'funded'
              AND (m.freelancer_id = ? OR m.freelancer_id IS NULL)
        ");
        $st->bind_param('iii', $fl_freelancer_id, $post_milestone_id, $fl_freelancer_id);
        $st->execute();
        $ms_check = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms_check) {
            $conn->begin_transaction();
            try {
                $st = $conn->prepare("UPDATE milestones SET status = 'in_progress' WHERE id = ?");
                $st->bind_param('i', $post_milestone_id);
                $st->execute();
                $st->close();

                // Update assignment status to working (from any active state)
                $st = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND freelancer_id = ? AND status IN ('assigned', 'submitted')");
                $st->bind_param('ii', $ms_check['job_id'], $fl_freelancer_id);
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
        redirect('freelancer/milestone.php?id=' . $post_milestone_id);
    } elseif ($ms_action === 'submit' && $post_milestone_id > 0) {
        $st = $conn->prepare("
            SELECT m.id, m.job_id, m.status
            FROM milestones m
            JOIN assignments a ON a.job_id = m.job_id AND a.freelancer_id = ?
            WHERE m.id = ? AND m.status IN ('in_progress', 'revision_requested')
              AND (m.freelancer_id = ? OR m.freelancer_id IS NULL)
        ");
        $st->bind_param('iii', $fl_freelancer_id, $post_milestone_id, $fl_freelancer_id);
        $st->execute();
        $ms_check = $st->get_result()->fetch_assoc();
        $st->close();

        if ($ms_check) {
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
                    redirect('freelancer/milestone.php?id=' . $post_milestone_id);
                }
            }

            if ($submission_link === '' && $submission_file === null) {
                set_flash('error', 'Please provide a submission link or upload a file.');
                redirect('freelancer/milestone.php?id=' . $post_milestone_id);
            }

            $conn->begin_transaction();
            try {
                $now = date('Y-m-d H:i:s');
                $file_for_db = $submission_file ?? '';
                $st = $conn->prepare("UPDATE milestones SET submission_link=?, submission_file=?, submission_note=?, status='submitted', submitted_at=? WHERE id=?");
                $st->bind_param('ssssi', $submission_link, $file_for_db, $submission_note, $now, $post_milestone_id);
                $st->execute();
                $st->close();

                $st = $conn->prepare("UPDATE assignments SET status='submitted' WHERE job_id=? AND freelancer_id=? AND status IN ('working', 'assigned')");
                $st->bind_param('ii', $ms_check['job_id'], $fl_freelancer_id);
                $st->execute();
                $st->close();

                $conn->commit();

                // Notify company (after commit so notification failure doesn't roll back submission)
                try {
                    $ns = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.id=?");
                    $ns->bind_param('i', $ms_check['job_id']);
                    $ns->execute();
                    $ni = $ns->get_result()->fetch_assoc();
                    $ns->close();
                    if ($ni) {
                        create_notification($conn, (int) $ni['user_id'], 'work_submitted', $fl_user['username'] . " submitted work for a milestone.", 'company/view_applications.php?id=' . $ms_check['job_id']);
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
           e.status AS escrow_status, e.funded_at, e.released_at
    FROM milestones m
    JOIN jobs j ON m.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    JOIN assignments a ON a.job_id = j.id AND a.freelancer_id = ?
    LEFT JOIN escrow e ON e.milestone_id = m.id
    WHERE m.id = ?
      AND (m.freelancer_id = ? OR m.freelancer_id IS NULL)
");
$st->bind_param('iii', $fl_freelancer_id, $ms_id, $fl_freelancer_id);
$st->execute();
$milestone = $st->get_result()->fetch_assoc();
$st->close();

if (!$milestone) { redirect('freelancer/my_tasks.php'); }

// Fetch all milestones for this job (for sidebar/progress) — only those assigned to this freelancer
$all_ms = [];
$st = $conn->prepare("SELECT id, title, amount, status, sort_order FROM milestones WHERE job_id = ? AND (freelancer_id = ? OR freelancer_id IS NULL) ORDER BY sort_order ASC");
$st->bind_param('ii', $milestone['job_id'], $fl_freelancer_id);
$st->execute();
$mr = $st->get_result();
while ($row = $mr->fetch_assoc()) { $all_ms[] = $row; }
$st->close();

$approved_count = 0;
foreach ($all_ms as $am) { if ($am['status'] === 'approved') $approved_count++; }
$progress = count($all_ms) > 0 ? round(($approved_count / count($all_ms)) * 100) : 0;

require __DIR__ . '/../includes/freelancer_layout.php';

$status_labels = ['draft'=>'Draft','funded'=>'Funded','in_progress'=>'In Progress','submitted'=>'Under Review','approved'=>'Approved','revision_requested'=>'Revision Requested'];
$escrow_labels = ['held'=>'Held in Escrow','released'=>'Released','refunded'=>'Refunded'];
$escrow_colors = ['held'=>'#f59e0b','released'=>'#10b981','refunded'=>'#ef4444'];
$draft_enabled = ($milestone['status'] === 'draft');
?>

<style>
.ms-status-lg { display:inline-flex; align-items:center; gap:0.375rem; padding:0.375rem 0.875rem; border-radius:9999px; font-size:0.8125rem; font-weight:600; }
.ms-draft-lg { background:rgba(107,114,128,0.1); color:#6b7280; }
.ms-funded-lg { background:rgba(245,158,11,0.1); color:#f59e0b; }
.ms-in_progress-lg { background:rgba(99,102,241,0.1); color:#6366f1; }
.ms-submitted-lg { background:rgba(139,92,246,0.1); color:#8b5cf6; }
.ms-approved-lg { background:rgba(16,185,129,0.1); color:#10b981; }
.ms-revision_requested-lg { background:rgba(239,68,68,0.1); color:#ef4444; }

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
    <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="inline-flex items-center gap-1.5 text-sm font-medium mb-4 transition-colors" style="color:var(--color-text-muted)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to My Tasks
    </a>

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
                    $steps = ['funded', 'in_progress', 'submitted', 'approved'];
                    $step_labels = ['Funded', 'Working', 'Submitted', 'Approved'];
                    $current_idx = array_search($milestone['status'], $steps);
                    if ($milestone['status'] === 'revision_requested') $current_idx = 1;
                    if ($milestone['status'] === 'draft') $current_idx = -1;
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
            <div class="rounded-2xl p-4 flex items-start gap-3" style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.15)">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                <div>
                    <p class="text-sm font-bold text-red-600">Revision Requested</p>
                    <p class="text-xs mt-0.5 text-red-500">The company has requested changes. Please update your work and resubmit.</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Action Area -->
            <?php if ($milestone['status'] === 'draft'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="opacity:0.8">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)">
                            <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Waiting for Funding</h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">Escrow has not been funded yet</p>
                        </div>
                    </div>
                    <p class="text-sm" style="color:var(--color-text-secondary)">The company needs to fund this milestone via Escrow before you can start working. You'll be notified once it's funded.</p>
                    <div class="mt-4 p-3 rounded-xl flex items-center gap-2" style="background:rgba(245,158,11,0.06);border:1px solid rgba(245,158,11,0.15)">
                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span class="text-xs font-medium text-amber-600">Submission is disabled until the milestone is funded.</span>
                    </div>
                </div>

            <?php elseif ($milestone['status'] === 'funded'): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)">
                            <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Ready to Start</h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">Escrow is active — $<?= number_format((float) $milestone['amount'], 2) ?> held</p>
                        </div>
                    </div>
                    <p class="text-sm mb-4" style="color:var(--color-text-secondary)">This milestone has been funded via Escrow. Click below to begin working.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="ms_action" value="start">
                        <input type="hidden" name="milestone_id" value="<?= (int) $milestone['id'] ?>">
                        <button type="submit" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);box-shadow:0 4px 15px rgba(99,102,241,0.3)" onclick="return confirm('Start working on this milestone?')">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Start Milestone
                        </button>
                    </form>
                </div>

            <?php elseif ($milestone['status'] === 'in_progress'): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                            <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Work in Progress</h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">Upload your deliverables when ready</p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="submitForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="ms_action" value="submit">
                        <input type="hidden" name="milestone_id" value="<?= (int) $milestone['id'] ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Submission Link</label>
                            <input type="url" name="submission_link" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="https://drive.google.com/... or https://github.com/...">
                            <p class="text-xs mt-1" style="color:var(--color-text-muted)">Link to your work (Google Drive, GitHub, Figma, etc.)</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Upload File</label>
                            <div class="upload-zone-lg" id="uploadZone">
                                <input type="file" name="submission_file" id="fileInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar" class="hidden">
                                <svg class="w-10 h-10 mx-auto mb-2" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                                <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Click or drag to upload</p>
                                <p class="text-xs mt-1" style="color:var(--color-text-muted)">ZIP, PDF, DOCX, Images — Max 10MB</p>
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
                            <textarea name="submission_note" rows="4" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Describe what you've delivered, any instructions for the reviewer..."></textarea>
                        </div>

                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);box-shadow:0 4px 15px rgba(139,92,246,0.3)" onclick="return confirmSubmit()">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            Submit for Review
                        </button>
                    </form>
                </div>

            <?php elseif ($milestone['status'] === 'revision_requested'): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(239,68,68,0.1)">
                            <svg class="w-6 h-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-red-600">Revision Requested</h2>
                            <p class="text-xs" style="color:var(--color-text-muted)">Update your work and resubmit</p>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" id="submitForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="ms_action" value="submit">
                        <input type="hidden" name="milestone_id" value="<?= (int) $milestone['id'] ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Submission Link</label>
                            <input type="url" name="submission_link" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="https://drive.google.com/... or https://github.com/...">
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Upload File</label>
                            <div class="upload-zone-lg" id="uploadZone">
                                <input type="file" name="submission_file" id="fileInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar" class="hidden">
                                <svg class="w-10 h-10 mx-auto mb-2" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                                <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Click or drag to upload</p>
                                <p class="text-xs mt-1" style="color:var(--color-text-muted)">ZIP, PDF, DOCX, Images — Max 10MB</p>
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
                            <textarea name="submission_note" rows="4" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Describe the changes you've made..."></textarea>
                        </div>

                        <button type="submit" class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white transition-all" style="background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:0 4px 15px rgba(239,68,68,0.3)" onclick="return confirmSubmit()">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Resubmit Work
                        </button>
                    </form>
                </div>

            <?php elseif ($milestone['status'] === 'submitted'): ?>
                <div class="glass rounded-2xl p-6 reveal">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-2.5 h-2.5 rounded-full bg-purple-500 animate-pulse"></div>
                        <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Under Review</h2>
                    </div>
                    <p class="text-sm mb-4" style="color:var(--color-text-secondary)">Your work has been submitted and is awaiting the company's review. You'll be notified once a decision is made.</p>

                    <?php if ($milestone['submitted_at']): ?>
                        <p class="text-xs" style="color:var(--color-text-muted)">Submitted <?= date('F j, Y \a\t g:ia', strtotime($milestone['submitted_at'])) ?></p>
                    <?php endif; ?>

                    <?php if ($milestone['submission_link'] || $milestone['submission_file'] || $milestone['submission_note']): ?>
                    <div class="mt-4 p-4 rounded-xl space-y-2" style="background:var(--color-bg);border:1px solid var(--color-border)">
                        <p class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Your Submission</p>
                        <?php if ($milestone['submission_link']): ?>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                <a href="<?= e($milestone['submission_link']) ?>" target="_blank" class="text-sm font-medium text-primary-600 hover:underline">View Submission Link</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($milestone['submission_file']): ?>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                <a href="<?= e(base_url('uploads/attachments/' . $milestone['submission_file'])) ?>" target="_blank" class="text-sm font-medium text-emerald-600 hover:underline">View Uploaded File</a>
                            </div>
                        <?php endif; ?>
                        <?php if ($milestone['submission_note']): ?>
                            <div class="mt-2 p-3 rounded-lg text-sm leading-relaxed" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-secondary)">
                                <?= nl2br(e($milestone['submission_note'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($milestone['status'] === 'approved'): ?>
                <div class="glass rounded-2xl p-6 reveal" style="background:rgba(16,185,129,0.03);border:1px solid rgba(16,185,129,0.15)">
                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" style="background:rgba(16,185,129,0.1)">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-emerald-700">Milestone Approved &amp; Paid</h2>
                            <?php if ($milestone['approved_at']): ?>
                                <p class="text-xs text-emerald-600"><?= date('F j, Y \a\t g:ia', strtotime($milestone['approved_at'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p class="text-sm text-emerald-600">Payment of $<?= number_format((float) $milestone['amount'], 2) ?> has been released from Escrow.</p>
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
                        <span class="font-bold text-lg" style="color:#f59e0b">$<?= number_format((float) $milestone['amount'], 2) ?></span>
                    </div>
                    <div class="h-px" style="background:var(--color-border)"></div>
                    <div class="flex justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Escrow</span>
                        <?php if ($milestone['escrow_status']): ?>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full" style="background:<?= $escrow_colors[$milestone['escrow_status']] ?? '#6b7280' ?>15;color:<?= $escrow_colors[$milestone['escrow_status']] ?? '#6b7280' ?>">
                                <?= $escrow_labels[$milestone['escrow_status']] ?? $milestone['escrow_status'] ?>
                            </span>
                        <?php else: ?>
                            <span class="text-xs" style="color:var(--color-text-muted)">No escrow</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($milestone['funded_at']): ?>
                    <div class="flex justify-between text-xs">
                        <span style="color:var(--color-text-muted)">Funded</span>
                        <span style="color:var(--color-text-secondary)"><?= date('M j, Y', strtotime($milestone['funded_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($milestone['released_at']): ?>
                    <div class="flex justify-between text-xs">
                        <span style="color:var(--color-text-muted)">Released</span>
                        <span style="color:var(--color-text-secondary)"><?= date('M j, Y', strtotime($milestone['released_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="h-px" style="background:var(--color-border)"></div>
                    <?php if (!empty($milestone['deadline'])): ?>
                    <div class="flex justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Milestone Deadline</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($milestone['deadline'])) ?></span>
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
                    <?php foreach ($all_ms as $am):
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
                        <span class="text-[10px] font-semibold flex-shrink-0 ml-2" style="color:var(--color-text-muted)"><?= $am_labels[$am['status']] ?? '' ?></span>
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
    var link = form.querySelector('[name="submission_link"]').value.trim();
    var fileInput = form.querySelector('[name="submission_file"]');
    if (!link && (!fileInput.files || !fileInput.files.length)) {
        alert('Please provide a submission link or upload a file.');
        return false;
    }
    return confirm('Submit this work for review?');
}
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
