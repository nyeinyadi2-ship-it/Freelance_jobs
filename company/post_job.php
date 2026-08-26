<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

$error = '';
$old = [
    'title' => '', 'category' => '', 'budget' => '', 'payment_type' => 'fixed', 'experience_level' => 'intermediate',
    'gender_requirement' => 'any', 'description' => '', 'requirements' => '',
    'deadline' => '', 'duration' => '', 'freelancers_needed' => '1',
];

$skills = [];
$skills_result = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name ASC");
if ($skills_result) {
    while ($row = $skills_result->fetch_assoc()) {
        $skills[] = $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $content_length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $post_max = _ini_bytes(ini_get('post_max_size'));
        if ($content_length > $post_max) {
            $error = 'Your file is too large. Maximum total upload size is ' . round($post_max / 1048576) . 'MB. Please use a smaller file.';
        } else {
            $error = 'Invalid request. Please try again.';
        }
    } else {
        $old['title'] = trim($_POST['title'] ?? '');
        $old['category'] = trim($_POST['category'] ?? '');
        $old['payment_type'] = $_POST['payment_type'] ?? 'fixed';
        $old['budget'] = trim($_POST['budget'] ?? '');
        $old['experience_level'] = $_POST['experience_level'] ?? 'intermediate';
        $old['gender_requirement'] = $_POST['gender_requirement'] ?? 'any';
        $old['description'] = trim($_POST['description'] ?? '');
        $old['requirements'] = trim($_POST['requirements'] ?? '');
        $old['deadline'] = trim($_POST['deadline'] ?? '');
        $old['duration'] = trim($_POST['duration'] ?? '');
        $old['freelancers_needed'] = trim($_POST['freelancers_needed'] ?? '1');

        // Validation
        if ($old['title'] === '') {
            $error = 'Job title is required. Please enter a title for your job posting.';
        } elseif ($old['category'] === '') {
            $error = 'Category is required. Please select a category for your job.';
        } elseif (!is_numeric($old['budget']) || (float) $old['budget'] <= 0) {
            $error = 'Total Project Payment (Budget) must be greater than zero.';
        } elseif ($old['description'] === '') {
            $error = 'Job description is required. Please describe your project.';
        } elseif ($old['requirements'] === '') {
            $error = 'Requirements are required. Please list the qualifications needed.';
        } elseif ($old['deadline'] !== '' && strtotime($old['deadline']) < time()) {
            $error = 'Deadline cannot be in the past. Please select a future date.';
        }

        $total_budget = (float) $old['budget'];

        // Validate milestones if payment type is milestone and no error yet
        if (!$error && $old['payment_type'] === 'milestone') {
            $ms_titles = $_POST['ms_title'] ?? [];
            $ms_amounts = $_POST['ms_amount'] ?? [];
            $ms_descs = $_POST['ms_desc'] ?? [];
            $ms_deadlines = $_POST['ms_deadline'] ?? [];
            $has_valid_milestone = false;
            $milestone_sum = 0;
            foreach ($ms_titles as $idx => $mtitle) {
                $mtitle = trim($mtitle);
                $mamt = (float)($ms_amounts[$idx] ?? 0);
                $mdesc = trim($ms_descs[$idx] ?? '');
                $mdeadline = $ms_deadlines[$idx] ?? '';
                if ($mtitle !== '') {
                    $has_valid_milestone = true;
                    $milestone_sum += $mamt;
                    if ($mamt <= 0) {
                        $error = 'Milestone "' . $mtitle . '" amount must be greater than zero.';
                        break;
                    }
                    if ($mdesc === '') {
                        $error = 'Milestone "' . $mtitle . '" description is required.';
                        break;
                    }
                    if ($mdeadline === '') {
                        $error = 'Milestone "' . $mtitle . '" deadline is required.';
                        break;
                    }
                    if (strtotime($mdeadline) < time()) {
                        $error = 'Milestone "' . $mtitle . '" deadline cannot be in the past.';
                        break;
                    }
                }
            }
            if (!$error && !$has_valid_milestone) {
                $error = 'Please add at least one milestone with a valid title.';
            }
            if (!$error && round($milestone_sum, 2) !== round($total_budget, 2)) {
                $error = 'Total milestone amount (' . number_format($milestone_sum, 2) . ' MMK) must exactly match the Total Project Payment (' . number_format($total_budget, 2) . ' MMK).';
            }
        }

        if (!$error) {
            // Handle attachment upload
            $attachment_name = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment_name = upload_attachment($_FILES['attachment']);
                if ($attachment_name === null) {
                    $error = 'Invalid attachment. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP. Max 10MB.';
                }
            }

            if (!$error) {
                $budget = $total_budget;
                $payment_type = $old['payment_type'];
                
                $deadline = $old['deadline'] !== '' ? $old['deadline'] : null;
                $freelancers_needed = 1;
                $status = 'open';

                $stmt = $conn->prepare('INSERT INTO jobs (company_id, title, category, experience_level, gender_requirement, description, requirements, budget, deadline, duration, freelancers_needed, attachment, status, payment_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('issssssdssisss', $company_id, $old['title'], $old['category'], $old['experience_level'], $old['gender_requirement'], $old['description'], $old['requirements'], $budget, $deadline, $old['duration'], $freelancers_needed, $attachment_name, $status, $payment_type);
                $stmt->execute();
                $job_id = $stmt->insert_id;
                $stmt->close();
                
                if ($payment_type === 'milestone' && !empty($_POST['ms_title'])) {
                    $stmt_ms = $conn->prepare("INSERT INTO milestones (job_id, title, description, amount, deadline, status, sort_order) VALUES (?, ?, ?, ?, ?, 'draft', ?)");
                    $titles = $_POST['ms_title'];
                    $amounts = $_POST['ms_amount'];
                    $descs = $_POST['ms_desc'] ?? [];
                    $deadlines = $_POST['ms_deadline'] ?? [];
                    foreach ($titles as $idx => $mtitle) {
                        $mamt = (float)$amounts[$idx];
                        $mdesc = trim($descs[$idx] ?? '');
                        $mdeadline = !empty($deadlines[$idx]) ? $deadlines[$idx] : null;
                        $ms_order = $idx + 1;
                        if ($mamt > 0 && trim($mtitle) !== '') {
                            $mtitle_clean = trim($mtitle);
                            $stmt_ms->bind_param('issdsi', $job_id, $mtitle_clean, $mdesc, $mamt, $mdeadline, $ms_order);
                            $stmt_ms->execute();
                        }
                    }
                    $stmt_ms->close();
                }

                if (!empty($_POST['skills']) && is_array($_POST['skills'])) {
                    $stmt_skill = $conn->prepare("INSERT INTO job_skills (job_id, skill_id) VALUES (?, ?)");
                    foreach ($_POST['skills'] as $skill_id) {
                        $skill_id = (int)$skill_id;
                        $stmt_skill->bind_param('ii', $job_id, $skill_id);
                        $stmt_skill->execute();
                    }
                    $stmt_skill->close();
                }

                // Notify admin
                $admin_id = get_admin_user_id($conn);
                if ($admin_id) {
                    create_notification($conn, $admin_id, 'new_job', "Posted a new job \"{$old['title']}\".", "admin/approve_jobs.php", $user_id);
                }

                set_flash('success', 'Job posted successfully and is now live.');
                redirect('company/manage_jobs.php');
            }
        }
    }
}

$page_title = 'Post a Job';
require __DIR__ . '/../includes/header.php';
?>

<style>
/* ===== Progress Steps ===== */
.post-progress { display:flex; align-items:center; gap:0; margin-bottom:2.5rem; }
.post-progress .p-step { flex:1; text-align:center; position:relative; }
.post-progress .p-step::after { content:''; position:absolute; top:20px; left:calc(50% + 22px); width:calc(100% - 44px); height:2px; background:var(--color-border); transition:background .3s; z-index:0; }
.post-progress .p-step:last-child::after { display:none; }
.post-progress .p-step.done::after { background:linear-gradient(90deg,#6366f1,#8b5cf6); }
.post-progress .p-step .p-dot { width:40px; height:40px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.875rem; position:relative; z-index:1; transition:all .35s cubic-bezier(.4,0,.2,1); border:2px solid var(--color-border); background:var(--color-card); color:var(--color-text-muted); }
.post-progress .p-step.active .p-dot { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border-color:transparent; box-shadow:0 0 0 4px rgba(99,102,241,0.15), 0 4px 12px rgba(99,102,241,0.3); }
.post-progress .p-step.done .p-dot { background:linear-gradient(135deg,#10b981,#34d399); color:#fff; border-color:transparent; }
.post-progress .p-step .p-label { display:block; margin-top:0.625rem; font-size:0.7rem; font-weight:600; color:var(--color-text-muted); letter-spacing:.02em; transition:color .3s; }
.post-progress .p-step.active .p-label { color:#6366f1; }
.post-progress .p-step.done .p-label { color:#10b981; }

/* ===== Step Panels ===== */
.step-panel { display:none; animation:panelIn .4s ease; }
.step-panel.active { display:block; }
@keyframes panelIn { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

/* ===== Cards ===== */
.form-card { background:var(--color-card); border:1px solid var(--color-border); border-radius:1rem; padding:1.75rem; box-shadow:0 1px 3px rgba(0,0,0,0.04), 0 8px 32px rgba(0,0,0,0.03); }
.form-card-header { display:flex; align-items:center; gap:0.75rem; margin-bottom:1.5rem; padding-bottom:1rem; border-bottom:1px solid var(--color-border); }
.form-card-header .fc-icon { width:40px; height:40px; border-radius:0.75rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.form-card-header h2 { font-size:1.05rem; font-weight:700; color:var(--color-text-primary); }

/* ===== Form Inputs ===== */
.form-input { width:100%; padding:0.75rem 1rem; border-radius:0.75rem; font-size:0.875rem; background:var(--color-bg); border:1.5px solid var(--color-border); color:var(--color-text-primary); transition:all .2s; outline:none; }
.form-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.1); }
.form-input::placeholder { color:var(--color-text-muted); }
textarea.form-input { resize:vertical; min-height:100px; }
select.form-input { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236b7280' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 6.646a.5.5 0 01.708 0L8 9.293l2.646-2.647a.5.5 0 01.708.708l-3 3a.5.5 0 01-.708 0l-3-3a.5.5 0 010-.708z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 0.75rem center; padding-right:2.5rem; }
.form-label { display:block; font-size:0.8125rem; font-weight:600; color:var(--color-text-secondary); margin-bottom:0.5rem; }
.form-label .req { color:#ef4444; }
.form-hint { font-size:0.75rem; color:var(--color-text-muted); margin-top:0.25rem; }

/* ===== Drop Zone ===== */
.drop-zone { border:2px dashed var(--color-border); border-radius:1rem; padding:2.5rem 1.5rem; text-align:center; cursor:pointer; transition:all .3s; position:relative; background:var(--color-bg); }
.drop-zone:hover, .drop-zone.dragover { border-color:#818cf8; background:rgba(99,102,241,0.03); }
.drop-zone.has-file { border-color:#10b981; border-style:solid; background:rgba(16,185,129,0.03); }
.drop-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; z-index:2; }

/* ===== Milestone ===== */
.ms-item { padding:1.25rem; border-radius:0.75rem; background:var(--color-bg); border:1.5px solid var(--color-border); transition:all .2s; }
.ms-item:hover { border-color:#818cf8; }
.ms-item.has-error { border-color:#ef4444; background:rgba(239,68,68,0.02); }

/* ===== Validation ===== */
.field-error { font-size:0.75rem; color:#ef4444; margin-top:0.375rem; display:none; align-items:center; gap:0.25rem; }
.field-error.visible { display:flex; }
.form-input.input-error { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,0.1); }
.step-error-banner { padding:0.75rem 1rem; border-radius:0.75rem; margin-bottom:1rem; font-size:0.8125rem; font-weight:500; display:none; align-items:center; gap:0.5rem; background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); color:#ef4444; }
.step-error-banner.visible { display:flex; }
.ms-total-warning { padding:0.5rem 0.75rem; border-radius:0.5rem; font-size:0.75rem; font-weight:600; margin-top:0.5rem; display:none; }
.ms-total-warning.visible { display:block; }
.ms-total-warning.over { background:rgba(239,68,68,0.08); color:#ef4444; border:1px solid rgba(239,68,68,0.2); }
.ms-total-warning.under { background:rgba(245,158,11,0.08); color:#f59e0b; border:1px solid rgba(245,158,11,0.2); }
.ms-total-warning.match { background:rgba(16,185,129,0.08); color:#10b981; border:1px solid rgba(16,185,129,0.2); }

/* ===== Buttons ===== */
.btn-publish { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.875rem 2rem; border-radius:0.75rem; transition:all .3s cubic-bezier(.4,0,.2,1); box-shadow:0 4px 14px rgba(99,102,241,0.3); border:none; cursor:pointer; font-size:0.9375rem; }
.btn-publish:hover { transform:translateY(-2px); box-shadow:0 8px 25px rgba(99,102,241,0.4); }
.btn-publish:active { transform:translateY(0); }
.btn-draft { background:transparent; color:var(--color-text-secondary); font-weight:600; padding:0.875rem 1.5rem; border-radius:0.75rem; transition:all .2s; border:1.5px solid var(--color-border); cursor:pointer; font-size:0.9375rem; }
.btn-draft:hover { border-color:#6366f1; color:#6366f1; background:rgba(99,102,241,0.04); }
.btn-next { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .3s; box-shadow:0 4px 14px rgba(99,102,241,0.25); border:none; cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem; font-size:0.875rem; }
.btn-next:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,0.35); }
.btn-back { background:transparent; color:var(--color-text-secondary); font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .2s; border:1.5px solid var(--color-border); cursor:pointer; display:inline-flex; align-items:center; gap:0.5rem; font-size:0.875rem; }
.btn-back:hover { border-color:#6366f1; color:#6366f1; }

/* ===== Live Preview ===== */
.preview-card { background:var(--color-card); border:1px solid var(--color-border); border-radius:1rem; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04), 0 8px 32px rgba(0,0,0,0.03); position:sticky; top:5.5rem; }
.preview-header { padding:1rem 1.25rem; border-bottom:1px solid var(--color-border); display:flex; align-items:center; gap:0.5rem; }
.preview-header h3 { font-size:0.875rem; font-weight:700; color:var(--color-text-primary); }
.preview-body { padding:1.25rem; }
.preview-section { margin-bottom:1.25rem; }
.preview-section:last-child { margin-bottom:0; }
.preview-section-title { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--color-text-muted); margin-bottom:0.5rem; }
.preview-value { font-size:0.8125rem; color:var(--color-text-primary); line-height:1.6; }
.preview-empty { font-size:0.8125rem; color:var(--color-text-muted); font-style:italic; }
.preview-tag { display:inline-flex; padding:0.25rem 0.625rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(99,102,241,0.08); color:#6366f1; }
.preview-budget { font-size:1.25rem; font-weight:800; color:#6366f1; }
.preview-ms-item { display:flex; justify-content:space-between; align-items:center; padding:0.5rem 0.75rem; border-radius:0.5rem; background:var(--color-bg); margin-bottom:0.375rem; }
.preview-ms-item:last-child { margin-bottom:0; }
.preview-ms-amount { font-size:0.75rem; font-weight:700; color:#f59e0b; }

@media (max-width: 1023px) {
    .preview-card { position:static; }
    .post-progress .p-step .p-label { font-size:0.6rem; }
}
</style>

<div class="max-w-7xl mx-auto" style="padding-bottom:3rem">
    <!-- Page Header -->
    <div class="mb-4">
        <button onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </button>
    </div>
    <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg,rgba(99,102,241,0.12),rgba(139,92,246,0.12))">
                <svg class="w-5 h-5" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)">Post a New Job</h1>
                <p class="text-sm" style="color:var(--color-text-muted)">Fill in the details to create your job posting</p>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl border flex items-center gap-3" style="background:rgba(239,68,68,0.06);border-color:rgba(239,68,68,0.2);color:#ef4444">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-sm font-medium"><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Progress Steps -->
    <div class="post-progress">
        <div class="p-step active" data-step="1"><span class="p-dot">1</span><span class="p-label">Basics</span></div>
        <div class="p-step" data-step="2"><span class="p-dot">2</span><span class="p-label">Details</span></div>
        <div class="p-step" data-step="3"><span class="p-dot">3</span><span class="p-label">Attachment</span></div>
        <div class="p-step" data-step="4"><span class="p-dot">4</span><span class="p-label">Review</span></div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="jobForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="flex flex-col lg:flex-row gap-6">
            <!-- ===== LEFT: Form ===== -->
            <div class="flex-1 min-w-0">

                <!-- STEP 1: Basics -->
                <div class="step-panel active" data-panel="1">
                    <div class="step-error-banner">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span></span>
                    </div>
                    <div class="form-card mb-5">
                        <div class="form-card-header">
                            <div class="fc-icon" style="background:linear-gradient(135deg,rgba(99,102,241,0.12),rgba(139,92,246,0.12))">
                                <svg class="w-5 h-5" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </div>
                            <h2>Basic Information</h2>
                        </div>
                        <div class="space-y-5">
                            <div>
                                <label class="form-label">Job Title <span class="req">*</span></label>
                                <input type="text" name="title" required maxlength="200" placeholder="e.g. Full-Stack Web Developer Needed" class="form-input" value="<?= e($old['title']) ?>" oninput="clearFieldError(this); updatePreview()">
                                <div class="field-error" id="err-title">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span></span>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Category <span class="req">*</span></label>
                                <select name="category" required class="form-input" onchange="clearFieldError(this); updatePreview()">
                                    <option value="">Select a category</option>
                                    <?php
                                    $cats = [];
                                    $res = $conn->query("SELECT name FROM categories ORDER BY name ASC");
                                    if ($res) {
                                        while ($row = $res->fetch_assoc()) {
                                            $cats[] = $row['name'];
                                        }
                                    }
                                    foreach ($cats as $cat):
                                    ?>
                                        <option value="<?= e($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="field-error" id="err-category">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span></span>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-5">
                                <div>
                                    <label class="form-label">Payment Type</label>
                                    <select name="payment_type" id="paymentTypeSelect" class="form-input" onchange="togglePaymentType(); updatePreview()">
                                        <option value="fixed" <?= $old['payment_type'] === 'fixed' ? 'selected' : '' ?>>Fixed Payment</option>
                                        <option value="milestone" <?= $old['payment_type'] === 'milestone' ? 'selected' : '' ?>>Milestone Payment</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 gap-5">
                                <div id="budgetContainer">
                                    <label class="form-label">Total Project Payment (Budget) <span class="req">*</span></label>
                                    <div class="relative">
                                        <input type="number" name="budget" id="fixedBudgetInput" step="0.01" min="0.01" placeholder="0.00" class="form-input" value="<?= e($old['budget']) ?>" oninput="clearFieldError(this); updatePreview(); updateMilestoneTotal()">
                                        <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                            <span class="text-sm text-gray-500 font-medium">MMK</span>
                                        </div>
                                    </div>
                                    <div class="field-error" id="err-budget">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <span></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="milestonesSection" style="<?= $old['payment_type'] === 'fixed' ? 'display:none;' : '' ?>" class="p-4 rounded-xl border border-gray-200 bg-gray-50 dark:bg-gray-800/50">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Project Milestones</h3>
                                        <p class="text-xs text-gray-500">Break down your project into deliverables.</p>
                                    </div>
                                    <button type="button" onclick="addMilestone()" class="text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg transition-colors">
                                        + Add Milestone
                                    </button>
                                </div>
                                <div id="milestonesContainer" class="space-y-3">
                                    <div class="ms-item flex flex-col gap-3">
                                        <div class="flex justify-between items-center">
                                            <span class="text-xs font-bold uppercase tracking-wider" style="color:var(--color-text-muted)">Milestone 1</span>
                                        </div>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div class="sm:col-span-2">
                                                <label class="form-label">Title <span class="req">*</span></label>
                                                <input type="text" name="ms_title[]" required placeholder="e.g. UI Design" class="form-input milestone-title" oninput="updatePreview()">
                                            </div>
                                            <div>
                                                <label class="form-label">Amount (MMK) <span class="req">*</span></label>
                                                <input type="number" name="ms_amount[]" step="0.01" min="0.01" required placeholder="0.00" class="form-input milestone-amount" oninput="updateMilestoneTotal(); updatePreview()">
                                            </div>
                                            <div>
                                                <label class="form-label">Deadline <span class="req">*</span></label>
                                                <input type="datetime-local" name="ms_deadline[]" required class="form-input milestone-deadline" min="<?= date('Y-m-d\TH:i') ?>" oninput="updatePreview()">
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="form-label">Description <span class="req">*</span></label>
                                                <textarea name="ms_desc[]" rows="2" required placeholder="Describe what needs to be delivered in this milestone..." class="form-input milestone-desc" oninput="updatePreview()"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-4 flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Total Milestone Amount:</span>
                                    <span class="text-lg font-bold text-indigo-600" id="milestoneTotal">0.00 MMK</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="button" onclick="goStep(2)" class="btn-next">Next: Details <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>
                    </div>
                </div>

                <!-- STEP 2: Details -->
                <div class="step-panel" data-panel="2">
                    <div class="step-error-banner">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span></span>
                    </div>
                    <div class="form-card mb-5">
                        <div class="form-card-header">
                            <div class="fc-icon" style="background:linear-gradient(135deg,rgba(139,92,246,0.12),rgba(168,85,247,0.12))">
                                <svg class="w-5 h-5" style="color:#8b5cf6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <h2>Job Details</h2>
                        </div>
                        <div class="space-y-5">
                            <div>
                                <label class="form-label">Job Description <span class="req">*</span></label>
                                <textarea name="description" rows="5" required placeholder="Describe the project, goals, and what you're looking for..." class="form-input" oninput="clearFieldError(this); updatePreview()"><?= e($old['description']) ?></textarea>
                                <div class="field-error" id="err-description">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span></span>
                                </div>
                            </div>
                            <div>
                                <label class="form-label">Requirements <span class="req">*</span></label>
                                <textarea name="requirements" rows="4" required placeholder="List the required qualifications, experience, and deliverables..." class="form-input" oninput="clearFieldError(this); updatePreview()"><?= e($old['requirements']) ?></textarea>
                                <div class="field-error" id="err-requirements">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                    <span></span>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="form-label">Experience Level</label>
                                    <select name="experience_level" class="form-input" onchange="updatePreview()">
                                        <option value="beginner" <?= $old['experience_level'] === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                                        <option value="intermediate" <?= $old['experience_level'] === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                                        <option value="expert" <?= $old['experience_level'] === 'expert' ? 'selected' : '' ?>>Expert</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Gender Requirement</label>
                                    <select name="gender_requirement" class="form-input">
                                        <option value="any" <?= $old['gender_requirement'] === 'any' ? 'selected' : '' ?>>Any</option>
                                        <option value="male" <?= $old['gender_requirement'] === 'male' ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= $old['gender_requirement'] === 'female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="form-label">Deadline</label>
                                    <input type="datetime-local" name="deadline" min="<?= date('Y-m-d\TH:i') ?>" class="form-input" value="<?= e($old['deadline']) ?>" onchange="updatePreview()">
                                </div>
                                <div>
                                    <label class="form-label">Estimated Duration</label>
                                    <input type="text" name="duration" placeholder="e.g. 2 weeks, 1 month" maxlength="100" class="form-input" value="<?= e($old['duration']) ?>" oninput="updatePreview()">
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div class="flex items-end pb-1">
                                    <div class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl" style="background:rgba(16,185,129,0.06);border:1px solid rgba(16,185,129,0.15)">
                                        <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span class="text-sm font-semibold text-emerald-700">Remote Only</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between">
                        <button type="button" onclick="goStep(1)" class="btn-back"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back</button>
                        <button type="button" onclick="goStep(3)" class="btn-next">Next: Skills <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>
                    </div>
                </div>

                <!-- STEP 3: Skills & Attachment -->
                <div class="step-panel" data-panel="3">
                    <div class="step-error-banner">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <span></span>
                    </div>
                    <div class="form-card mb-5">
                        <div class="form-card-header">
                            <div class="fc-icon" style="background:linear-gradient(135deg,rgba(16,185,129,0.12),rgba(52,211,153,0.12))">
                                <svg class="w-5 h-5" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                            </div>
                            <h2>Attachment</h2>
                        </div>
                        <div class="space-y-6">
                            <div>
                                <label class="form-label">Required Skills (Optional)</label>
                                <div class="flex flex-wrap gap-2.5">
                                    <?php foreach ($skills as $skill): ?>
                                        <label class="flex items-center gap-2 text-sm p-2.5 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors" style="border:1px solid var(--color-border);background:var(--color-bg)">
                                            <input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>" <?= in_array((string) $skill['id'], $_POST['skills'] ?? [], true) ? 'checked' : '' ?> class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            <span style="color:var(--color-text-primary);font-weight:500;"><?= e($skill['skill_name']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <hr style="border:none;border-top:1px solid var(--color-border);">
                            <div>
                                <label class="form-label">Attachment (Optional)</label>
                                <div class="drop-zone" id="dropZone">
                                    <input type="file" name="attachment" id="attachmentInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar">
                                    <svg class="w-10 h-10 mx-auto mb-3" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                                    <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Drag & drop a file here, or <span style="color:#6366f1">browse</span></p>
                                    <p class="text-xs mt-1" style="color:var(--color-text-muted)">JPG, PNG, PDF, DOCX, ZIP up to 10MB</p>
                                </div>
                                <div id="filePreview" class="flex items-center gap-3 p-3 rounded-xl mt-3" style="display:none;background:var(--color-bg);border:1px solid var(--color-border)">
                                    <span id="fileIcon"></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)" id="fileName"></p>
                                        <p class="text-xs" style="color:var(--color-text-muted)" id="fileSize"></p>
                                    </div>
                                    <button type="button" id="removeFile" class="w-7 h-7 rounded-lg flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Remove file">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between">
                        <button type="button" onclick="goStep(2)" class="btn-back"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back</button>
                        <button type="button" onclick="goStep(4)" class="btn-next">Next: Review <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></button>
                    </div>
                </div>

                <!-- STEP 4: Review -->
                <div class="step-panel" data-panel="4">
                    <div class="form-card mb-5">
                        <div class="form-card-header">
                            <div class="fc-icon" style="background:linear-gradient(135deg,rgba(16,185,129,0.12),rgba(52,211,153,0.12))">
                                <svg class="w-5 h-5" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <h2>Review & Publish</h2>
                        </div>

                        <div class="space-y-3" id="reviewSection">
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Title</span>
                                <span class="text-sm font-semibold text-right" style="color:var(--color-text-primary)" id="reviewTitle">-</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Category</span>
                                <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewCategory">-</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Budget</span>
                                <span class="text-sm font-bold" style="color:#6366f1" id="reviewBudget">-</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Experience Level</span>
                                <span class="text-sm font-semibold capitalize" style="color:var(--color-text-primary)" id="reviewExp">-</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Gender Requirement</span>
                                <span class="text-sm font-semibold capitalize" style="color:var(--color-text-primary)" id="reviewGender">-</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Deadline</span>
                                <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewDeadline">Not set</span>
                            </div>
                            <div class="flex justify-between py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm" style="color:var(--color-text-muted)">Duration</span>
                                <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewDuration">Not set</span>
                            </div>
                            <div class="py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm font-medium block mb-1.5" style="color:var(--color-text-muted)">Description</span>
                                <p class="text-sm leading-relaxed" style="color:var(--color-text-secondary)" id="reviewDesc">-</p>
                            </div>
                            <div class="py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm font-medium block mb-1.5" style="color:var(--color-text-muted)">Requirements</span>
                                <p class="text-sm leading-relaxed" style="color:var(--color-text-secondary)" id="reviewReq">-</p>
                            </div>
                            <div class="py-2.5 border-b" style="border-color:var(--color-border)">
                                <span class="text-sm font-medium block mb-1.5" style="color:var(--color-text-muted)">Milestones</span>
                                <div id="reviewMilestones" class="space-y-1.5"></div>
                            </div>
                            <div class="flex justify-between py-2.5">
                                <span class="text-sm" style="color:var(--color-text-muted)">Attachment</span>
                                <span class="text-sm" style="color:var(--color-text-primary)" id="reviewAttachment">None</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-between items-center">
                        <button type="button" onclick="goStep(3)" class="btn-back"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg> Back</button>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn-draft" onclick="saveDraft()">Save Draft</button>
                            <button type="submit" class="btn-publish flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                Publish Job
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== RIGHT: Live Preview ===== -->
            <div class="w-full lg:w-[380px] flex-shrink-0">
                <div class="preview-card">
                    <div class="preview-header">
                        <svg class="w-4 h-4" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        <h3>Live Job Preview</h3>
                    </div>
                    <div class="preview-body">
                        <!-- Title -->
                        <div class="preview-section">
                            <p class="preview-value font-bold text-base" id="pvTitle" style="color:var(--color-text-primary)">Your job title</p>
                        </div>

                        <!-- Category & Budget -->
                        <div class="preview-section" style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap">
                            <span class="preview-tag" id="pvCategory">Category</span>
                            <span class="preview-budget" id="pvBudget">0 MMK</span>
                        </div>

                        <!-- Meta -->
                        <div class="preview-section" style="display:flex;flex-wrap:wrap;gap:0.75rem">
                            <div id="pvExperience" class="inline-flex items-center gap-1 text-xs" style="color:var(--color-text-muted)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Intermediate
                            </div>
                            <div id="pvDuration" class="inline-flex items-center gap-1 text-xs" style="color:var(--color-text-muted)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span id="pvDurationText">Not set</span>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--color-border);margin:0.75rem 0">

                        <!-- Description -->
                        <div class="preview-section">
                            <p class="preview-section-title">Description</p>
                            <p class="preview-value" id="pvDesc" style="white-space:pre-wrap;max-height:120px;overflow:hidden"><span class="preview-empty">Start typing to see preview...</span></p>
                        </div>

                        <!-- Milestones -->
                        <div class="preview-section">
                            <p class="preview-section-title">Milestones</p>
                            <div id="pvMilestones"><span class="preview-empty">No milestones added</span></div>
                        </div>

                        <!-- Attachment -->
                        <div class="preview-section">
                            <p class="preview-section-title">Attachment</p>
                            <p class="preview-value" id="pvAttachment"><span class="preview-empty">None</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
    var currentStep = 1;
    var totalSteps = 4;
    var milestoneCount = 1;

    window.togglePaymentType = function() {
        var pt = document.getElementById('paymentTypeSelect').value;
        if (pt === 'fixed') {
            document.getElementById('milestonesSection').style.display = 'none';
        } else {
            document.getElementById('milestonesSection').style.display = 'block';
        }
        updatePreview();
    };


    // ===== Error Display Helpers =====
    function showFieldError(fieldName, message) {
        var errEl = document.getElementById('err-' + fieldName);
        if (errEl) {
            errEl.querySelector('span').textContent = message;
            errEl.classList.add('visible');
        }
        var input = document.querySelector('[name="' + fieldName + '"]');
        if (input) input.classList.add('input-error');
    }

    function clearAllFieldErrors() {
        document.querySelectorAll('.field-error').forEach(function(el) {
            el.classList.remove('visible');
        });
        document.querySelectorAll('.form-input.input-error').forEach(function(el) {
            el.classList.remove('input-error');
        });
        document.querySelectorAll('.step-error-banner').forEach(function(el) {
            el.classList.remove('visible');
        });
    }

    window.clearFieldError = function(input) {
        input.classList.remove('input-error');
        var name = input.getAttribute('name');
        var errEl = document.getElementById('err-' + name);
        if (errEl) errEl.classList.remove('visible');
        // Also hide step banner
        var panel = input.closest('.step-panel');
        if (panel) {
            var banner = panel.querySelector('.step-error-banner');
            if (banner) banner.classList.remove('visible');
        }
    };

    function showStepError(step, message) {
        var panel = document.querySelector('.step-panel[data-panel="' + step + '"]');
        if (panel) {
            var banner = panel.querySelector('.step-error-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'step-error-banner';
                banner.innerHTML = '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg><span></span>';
                var formCard = panel.querySelector('.form-card');
                if (formCard) formCard.insertBefore(banner, formCard.firstChild);
            }
            banner.querySelector('span').textContent = message;
            banner.classList.add('visible');
        }
    }

    // ===== Step Validation Rules =====
    function validateStep(step) {
        var errors = [];
        clearAllFieldErrors();

        if (step === 1) {
            var title = document.querySelector('[name="title"]').value.trim();
            var cat = document.querySelector('[name="category"]').value;
            var pt = document.getElementById('paymentTypeSelect').value;
            var budgetStr = document.querySelector('[name="budget"]').value.trim();
            var budget = parseFloat(budgetStr);

            if (!title) {
                errors.push('Job title is required.');
                showFieldError('title', 'Job title is required.');
            }
            if (!cat) {
                errors.push('Category is required.');
                showFieldError('category', 'Please select a category.');
            }
            if (pt === 'fixed') {
                if (!budgetStr || isNaN(budget)) {
                    errors.push('Budget is required.');
                    showFieldError('budget', 'Please enter a valid budget amount.');
                } else if (budget <= 0) {
                    errors.push('Budget must be greater than zero.');
                    showFieldError('budget', 'Budget must be greater than zero.');
                }
            } else {
                var msResult = validateMilestones();
                if (msResult.errors.length > 0) {
                    errors = errors.concat(msResult.errors);
                } else if (msResult.total <= 0) {
                    errors.push('Please add at least one milestone with a valid amount.');
                }
            }
        } else if (step === 2) {
            var desc = document.querySelector('[name="description"]').value.trim();
            var req = document.querySelector('[name="requirements"]').value.trim();
            var deadline = document.querySelector('[name="deadline"]').value;

            if (!desc) {
                errors.push('Job description is required.');
                showFieldError('description', 'Job description is required.');
            }
            if (!req) {
                errors.push('Requirements are required.');
                showFieldError('requirements', 'Requirements are required.');
            }
            if (deadline && new Date(deadline) < new Date()) {
                errors.push('Deadline cannot be in the past.');
                showFieldError('deadline', 'Deadline cannot be in the past.');
            }
        }

        if (errors.length > 0) {
            showStepError(step, errors[0]);
        }
        return errors;
    }

    // ===== Milestone Validation =====
    function validateMilestones() {
        var msTitles = document.querySelectorAll('[name="ms_title[]"]');
        var msAmounts = document.querySelectorAll('[name="ms_amount[]"]');
        var msDescs = document.querySelectorAll('[name="ms_desc[]"]');
        var msDeadlines = document.querySelectorAll('[name="ms_deadline[]"]');
        var milestoneTotal = 0;
        var errors = [];
        var now = new Date();

        // Clear previous milestone errors
        document.querySelectorAll('.ms-item.has-error').forEach(function(el) {
            el.classList.remove('has-error');
        });

        for (var i = 0; i < msTitles.length; i++) {
            var msTitle = msTitles[i].value.trim();
            var msAmount = parseFloat(msAmounts[i].value) || 0;
            var msDesc = msDescs[i] ? msDescs[i].value.trim() : '';
            var msDeadline = msDeadlines[i] ? msDeadlines[i].value : '';

            if (msTitle !== '') {
                var msItem = msTitles[i].closest('.ms-item');
                if (msAmount <= 0) {
                    errors.push('Milestone "' + msTitle + '" amount must be greater than zero.');
                    msItem.classList.add('has-error');
                }
                if (msAmount < 0) {
                    errors.push('Milestone amounts cannot be negative.');
                    msItem.classList.add('has-error');
                }
                if (!msDesc) {
                    errors.push('Milestone "' + msTitle + '" description is required.');
                    msItem.classList.add('has-error');
                }
                if (!msDeadline) {
                    errors.push('Milestone "' + msTitle + '" deadline is required.');
                    msItem.classList.add('has-error');
                } else if (new Date(msDeadline) < now) {
                    errors.push('Milestone "' + msTitle + '" deadline cannot be in the past.');
                    msItem.classList.add('has-error');
                }
                milestoneTotal += msAmount;
            }
        }

        return { errors: errors, total: milestoneTotal };
    }

    window.goStep = function(step) {
        // If moving forward, validate current step first
        if (step > currentStep) {
            for (var s = currentStep; s < step; s++) {
                var errors = validateStep(s);
                if (errors.length > 0) {
                    // Stay on the step with errors - no alert, inline errors shown
                    document.querySelectorAll('.step-panel').forEach(function(p){ p.classList.remove('active'); });
                    document.querySelectorAll('.p-step').forEach(function(d){ d.classList.remove('active','done'); });
                    for (var i = 1; i < s; i++) {
                        document.querySelector('.p-step[data-step="'+i+'"]').classList.add('done');
                    }
                    document.querySelector('.p-step[data-step="'+s+'"]').classList.add('active');
                    document.querySelector('.step-panel[data-panel="'+s+'"]').classList.add('active');
                    currentStep = s;
                    // Scroll to first error
                    var firstError = document.querySelector('.step-panel[data-panel="'+s+'"] .field-error.visible');
                    if (firstError) {
                        firstError.scrollIntoView({behavior:'smooth', block:'center'});
                    } else {
                        window.scrollTo({top:0,behavior:'smooth'});
                    }
                    return;
                }
            }
        }

        if (step < 1 || step > totalSteps) return;
        clearAllFieldErrors();
        document.querySelectorAll('.step-panel').forEach(function(p){ p.classList.remove('active'); });
        document.querySelectorAll('.p-step').forEach(function(d){ d.classList.remove('active','done'); });

        for (var i = 1; i < step; i++) {
            document.querySelector('.p-step[data-step="'+i+'"]').classList.add('done');
        }
        document.querySelector('.p-step[data-step="'+step+'"]').classList.add('active');
        document.querySelector('.step-panel[data-panel="'+step+'"]').classList.add('active');
        currentStep = step;

        if (step === 4) buildReview();
        updatePreview();
        window.scrollTo({top:0,behavior:'smooth'});
    };

    window.addMilestone = function() {
        milestoneCount++;
        var minDate = new Date().toISOString().slice(0, 16);
        var html = '<div class="ms-item relative">' +
            '<div class="flex items-center justify-between mb-3">' +
            '<span class="text-xs font-bold uppercase tracking-wider" style="color:var(--color-text-muted)">Milestone ' + milestoneCount + '</span>' +
            '<button type="button" onclick="removeMilestone(this)" class="w-7 h-7 rounded-lg flex items-center justify-center text-red-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Remove">' +
            '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>' +
            '</button></div>' +
            '<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">' +
            '<div class="sm:col-span-2"><label class="form-label">Title <span class="req">*</span></label>' +
            '<input type="text" name="ms_title[]" required placeholder="e.g. Design Mockups" class="form-input milestone-title" oninput="updatePreview()"></div>' +
            '<div><label class="form-label">Amount (MMK) <span class="req">*</span></label>' +
            '<input type="number" name="ms_amount[]" step="0.01" min="0.01" required placeholder="0.00" class="form-input milestone-amount" oninput="updateMilestoneTotal(); updatePreview()"></div>' +
            '<div><label class="form-label">Deadline <span class="req">*</span></label>' +
            '<input type="datetime-local" name="ms_deadline[]" required min="' + minDate + '" class="form-input milestone-deadline" oninput="updatePreview()"></div>' +
            '<div class="sm:col-span-2"><label class="form-label">Description <span class="req">*</span></label>' +
            '<textarea name="ms_desc[]" rows="2" required placeholder="Describe what needs to be delivered in this milestone..." class="form-input milestone-desc" oninput="updatePreview()"></textarea>' +
            '</div></div></div>';
        document.getElementById('milestonesContainer').insertAdjacentHTML('beforeend', html);
        updateMilestoneTotal();
    };

    window.removeMilestone = function(btn) {
        btn.closest('.ms-item').remove();
        var items = document.querySelectorAll('.ms-item');
        items.forEach(function(item, i) {
            item.querySelector('span').textContent = 'Milestone ' + (i + 1);
        });
        milestoneCount = items.length;
        updateMilestoneTotal();
        updatePreview();
    };

    window.updateMilestoneTotal = function() {
        var total = 0;
        document.querySelectorAll('.milestone-amount').forEach(function(input) {
            total += parseFloat(input.value) || 0;
        });
        document.getElementById('milestoneTotal').textContent = total.toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' MMK';

        // Update warning message
        var budget = parseFloat(document.querySelector('[name="budget"]').value) || 0;
        var warningEl = document.getElementById('msTotalWarning');
        if (!warningEl) return;

        if (total > 0 && budget > 0) {
            if (total > budget) {
                warningEl.className = 'ms-total-warning visible over';
                warningEl.textContent = 'Total (' + total.toLocaleString('en', {minimumFractionDigits:2}) + ' MMK) exceeds budget (' + budget.toLocaleString('en', {minimumFractionDigits:2}) + ' MMK) by ' + (total - budget).toLocaleString('en', {minimumFractionDigits:2}) + ' MMK';
            } else if (total < budget) {
                warningEl.className = 'ms-total-warning visible under';
                warningEl.textContent = 'Total (' + total.toLocaleString('en', {minimumFractionDigits:2}) + ' MMK) is under budget (' + budget.toLocaleString('en', {minimumFractionDigits:2}) + ' MMK) by ' + (budget - total).toLocaleString('en', {minimumFractionDigits:2}) + ' MMK';
            } else {
                warningEl.className = 'ms-total-warning visible match';
                warningEl.textContent = 'Total matches budget exactly (' + total.toLocaleString('en', {minimumFractionDigits:2}) + ' MMK)';
            }
        } else if (total > 0 && budget <= 0) {
            warningEl.className = 'ms-total-warning visible over';
            warningEl.textContent = 'Please set a budget first before adding milestones.';
        } else {
            warningEl.className = 'ms-total-warning';
            warningEl.textContent = '';
        }
    };

    // ===== Live Preview =====
    window.updatePreview = function() {
        var f = document.getElementById('jobForm');
        var g = function(n){ var el=f.querySelector('[name="'+n+'"]'); return el?el.value:''; };

        var title = g('title');
        document.getElementById('pvTitle').textContent = title || 'Your job title';
        document.getElementById('pvTitle').style.color = title ? '' : 'var(--color-text-muted)';

        var cat = g('category');
        var catEl = document.getElementById('pvCategory');
        catEl.textContent = cat || 'Category';
        catEl.style.opacity = cat ? '1' : '0.5';

        var pt = document.getElementById('paymentTypeSelect').value;
        var budget = 0;
        if (pt === 'fixed') {
            budget = parseFloat(g('budget'));
        } else {
            var msResult = validateMilestones();
            budget = msResult.total;
        }
        document.getElementById('pvBudget').textContent = budget > 0 ? budget.toLocaleString('en') + ' MMK' : '0 MMK';

        document.getElementById('pvExperience').querySelector('span:last-child').textContent = g('experience_level') || 'Intermediate';

        var dur = g('duration');
        document.getElementById('pvDurationText').textContent = dur || 'Not set';

        var desc = g('description');
        var descEl = document.getElementById('pvDesc');
        if (desc) {
            descEl.textContent = desc;
            descEl.style.color = '';
        } else {
            descEl.innerHTML = '<span class="preview-empty">Start typing to see preview...</span>';
        }

        // Milestones
        var msHtml = '';
        var titles = document.querySelectorAll('[name="ms_title[]"]');
        var amounts = document.querySelectorAll('[name="ms_amount[]"]');
        var descs = document.querySelectorAll('[name="ms_desc[]"]');
        var deadlines = document.querySelectorAll('[name="ms_deadline[]"]');
        for (var i = 0; i < titles.length; i++) {
            if (titles[i].value.trim()) {
                var msDeadline = deadlines[i] ? deadlines[i].value : '';
                var deadlineStr = msDeadline ? new Date(msDeadline).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'}) : 'No deadline';
                msHtml += '<div class="p-2 rounded-lg mb-2" style="background:var(--color-bg);border:1px solid var(--color-border)">' +
                    '<div class="flex items-center justify-between mb-1">' +
                    '<span class="text-xs font-bold" style="color:var(--color-text-primary)">' + (i+1) + '. ' + titles[i].value + '</span>' +
                    '<span class="preview-ms-amount">' + (parseFloat(amounts[i].value)||0).toLocaleString('en', {minimumFractionDigits: 2}) + ' MMK</span>' +
                    '</div>' +
                    (descs[i] && descs[i].value.trim() ? '<p class="text-xs mb-1" style="color:var(--color-text-muted)">' + descs[i].value.trim().substring(0, 80) + (descs[i].value.trim().length > 80 ? '...' : '') + '</p>' : '') +
                    '<p class="text-xs" style="color:var(--color-text-placeholder)"><svg class="w-3 h-3 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> ' + deadlineStr + '</p>' +
                    '</div>';
            }
        }
        document.getElementById('pvMilestones').innerHTML = msHtml || '<span class="preview-empty">No milestones added</span>';

        // Attachment
        var fileInput = document.getElementById('attachmentInput');
        var attEl = document.getElementById('pvAttachment');
        if (fileInput.files.length > 0) {
            attEl.innerHTML = '<span style="color:var(--color-text-primary)">' + fileInput.files[0].name + '</span>';
        } else {
            attEl.innerHTML = '<span class="preview-empty">None</span>';
        }
    };

    function buildReview() {
        var f = document.getElementById('jobForm');
        var g = function(n){ var el=f.querySelector('[name="'+n+'"]'); return el?el.value:''; };
        var pt = document.getElementById('paymentTypeSelect').value;
        var budget = 0;
        if (pt === 'fixed') {
            budget = parseFloat(g('budget'));
        } else {
            var msResult = validateMilestones();
            budget = msResult.total;
        }

        document.getElementById('reviewTitle').textContent = g('title') || '-';
        document.getElementById('reviewCategory').textContent = g('category') || '-';
        document.getElementById('reviewBudget').textContent = budget > 0 ? budget.toLocaleString('en',{minimumFractionDigits:2}) + ' MMK' : '-';
        document.getElementById('reviewExp').textContent = g('experience_level');
        document.getElementById('reviewGender').textContent = g('gender_requirement');
        document.getElementById('reviewDeadline').textContent = g('deadline') ? new Date(g('deadline')).toLocaleString() : 'Not set';
        document.getElementById('reviewDuration').textContent = g('duration') || 'Not set';
        document.getElementById('reviewDesc').textContent = g('description') || '-';
        document.getElementById('reviewReq').textContent = g('requirements') || '-';

        var msHtml = '';
        var titles = document.querySelectorAll('[name="ms_title[]"]');
        var amounts = document.querySelectorAll('[name="ms_amount[]"]');
        var descs = document.querySelectorAll('[name="ms_desc[]"]');
        for (var i = 0; i < titles.length; i++) {
            if (titles[i].value.trim()) {
                msHtml += '<div class="flex items-center justify-between p-2 rounded-lg" style="background:var(--color-bg)">' +
                    '<span class="text-xs font-medium" style="color:var(--color-text-secondary)">' + (i+1) + '. ' + (titles[i].value||'Untitled') + '</span>' +
                    '<span class="text-xs font-bold" style="color:#f59e0b">$' + (parseFloat(amounts[i].value)||0).toFixed(2) + '</span></div>';
            }
        }
        document.getElementById('reviewMilestones').innerHTML = msHtml || '<span class="text-xs" style="color:var(--color-text-muted)">No milestones added</span>';

        var fileInput = document.getElementById('attachmentInput');
        document.getElementById('reviewAttachment').textContent = fileInput.files.length > 0 ? fileInput.files[0].name : 'None';
    }

    window.saveDraft = function() {
        alert('Draft saved! (Feature placeholder)');
    };

    // Drag & drop
    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('attachmentInput');
    var filePreview = document.getElementById('filePreview');
    var fileName = document.getElementById('fileName');
    var fileSize = document.getElementById('fileSize');
    var fileIcon = document.getElementById('fileIcon');
    var removeFile = document.getElementById('removeFile');

    ['dragenter','dragover'].forEach(function(ev){
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.add('dragover'); });
    });
    ['dragleave','drop'].forEach(function(ev){
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.remove('dragover'); });
    });
    dropZone.addEventListener('drop', function(e){
        if(e.dataTransfer.files.length){ fileInput.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0]); }
    });
    fileInput.addEventListener('change', function(){ if(fileInput.files.length) showFile(fileInput.files[0]); });

    function showFile(file) {
        if (file.size > 10*1024*1024) {
            alert('File is too large. Maximum size is 10MB.');
            fileInput.value = '';
            return;
        }
        var ext = file.name.split('.').pop().toLowerCase();
        var icons = {
            pdf:'<svg class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
            doc:'<svg class="w-8 h-8 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
            docx:'<svg class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
            zip:'<svg class="w-8 h-8 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/></svg>',
        };
        fileIcon.innerHTML = icons[ext] || icons.doc;
        fileName.textContent = file.name;
        fileSize.textContent = (file.size/1024).toFixed(1)+' KB';
        filePreview.style.display = 'flex';
        dropZone.classList.add('has-file');
        updatePreview();
    }

    removeFile.addEventListener('click', function(){
        fileInput.value = '';
        filePreview.style.display = 'none';
        dropZone.classList.remove('has-file');
        updatePreview();
    });

    // Client-side validation on submit
    document.getElementById('jobForm').addEventListener('submit', function(e){
        clearAllFieldErrors();
        var firstErrorStep = null;

        // Validate all steps
        for (var step = 1; step <= 3; step++) {
            var errors = validateStep(step);
            if (errors.length > 0 && !firstErrorStep) {
                firstErrorStep = step;
            }
        }

        // Validate milestones if any are filled in
        var msTitles = document.querySelectorAll('[name="ms_title[]"]');
        var msAmounts = document.querySelectorAll('[name="ms_amount[]"]');
        if (msTitles.length > 0) {
            var msResult = validateMilestones();
            if (msResult.errors.length > 0) {
                showStepError(3, msResult.errors[0]);
                if (!firstErrorStep) firstErrorStep = 3;
            }
        }

        // Validate file attachment
        var fileInput = document.getElementById('attachmentInput');
        if (fileInput.files.length) {
            var file = fileInput.files[0];
            var ext = file.name.split('.').pop().toLowerCase();
            var allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','zip','rar'];
            if (allowed.indexOf(ext) === -1) {
                showStepError(3, 'Invalid file type. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP, RAR.');
                if (!firstErrorStep) firstErrorStep = 3;
            }
            if (file.size > 10*1024*1024) {
                showStepError(3, 'File must be under 10MB.');
                if (!firstErrorStep) firstErrorStep = 3;
            }
        }

        // If there are errors, prevent submission and go to the first error step
        if (firstErrorStep) {
            e.preventDefault();
            goStep(firstErrorStep);
            return;
        }
    });

    // Initial preview
    updatePreview();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
