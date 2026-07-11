<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', __('error.company_not_found'));
    redirect('index.php');
}

// Fetch all skills for multi-select
$all_skills = [];
$sr = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
while ($row = $sr->fetch_assoc()) {
    $all_skills[] = $row;
}

$error = '';
$old = [
    'title' => '', 'category' => '', 'budget' => '', 'experience_level' => 'intermediate',
    'gender_requirement' => 'any', 'skills' => [], 'description' => '', 'requirements' => '',
    'deadline' => '', 'duration' => '', 'freelancers_needed' => '1', 'visibility' => 'public',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = __('error.invalid_request');
    } else {
        $old['title'] = trim($_POST['title'] ?? '');
        $old['category'] = trim($_POST['category'] ?? '');
        $old['budget'] = trim($_POST['budget'] ?? '');
        $old['experience_level'] = $_POST['experience_level'] ?? 'intermediate';
        $old['gender_requirement'] = $_POST['gender_requirement'] ?? 'any';
        $old['skills'] = $_POST['skills'] ?? [];
        $old['description'] = trim($_POST['description'] ?? '');
        $old['requirements'] = trim($_POST['requirements'] ?? '');
        $old['deadline'] = trim($_POST['deadline'] ?? '');
        $old['duration'] = trim($_POST['duration'] ?? '');
        $old['freelancers_needed'] = trim($_POST['freelancers_needed'] ?? '1');
        $old['visibility'] = $_POST['visibility'] ?? 'public';

        // Validation
        if ($old['title'] === '') {
            $error = 'Job title is required.';
        } elseif ($old['category'] === '') {
            $error = 'Category is required.';
        } elseif (!is_numeric($old['budget']) || (float) $old['budget'] <= 0) {
            $error = 'Budget must be greater than zero.';
        } elseif ($old['description'] === '') {
            $error = 'Job description is required.';
        } elseif ($old['requirements'] === '') {
            $error = 'Requirements are required.';
        } elseif (empty($old['skills'])) {
            $error = 'Please select at least one skill.';
        } elseif ($old['deadline'] !== '' && strtotime($old['deadline']) < time()) {
            $error = 'Deadline cannot be in the past.';
        } else {
            // Handle attachment upload
            $attachment_name = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment_name = upload_attachment($_FILES['attachment']);
                if ($attachment_name === null) {
                    $error = 'Invalid attachment. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP. Max 10MB.';
                }
            }

            if (!$error) {
                $budget = (float) $old['budget'];
                $deadline = $old['deadline'] !== '' ? $old['deadline'] : null;
                $freelancers_needed = max(1, (int) $old['freelancers_needed']);
                $status = 'pending';

                $stmt = $conn->prepare('INSERT INTO jobs (company_id, title, category, experience_level, gender_requirement, description, budget, deadline, duration, freelancers_needed, visibility, attachment, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->bind_param('isssssdssisss', $company_id, $old['title'], $old['category'], $old['experience_level'], $old['gender_requirement'], $old['description'], $budget, $deadline, $old['duration'], $freelancers_needed, $old['visibility'], $attachment_name, $status);
                $stmt->execute();
                $job_id = $stmt->insert_id;
                $stmt->close();

                // Insert job skills
                if (!empty($old['skills']) && $job_id > 0) {
                    $skill_stmt = $conn->prepare('INSERT INTO job_skills (job_id, skill_id) VALUES (?, ?)');
                    foreach ($old['skills'] as $skill_id) {
                        $sid = (int) $skill_id;
                        if ($sid > 0) {
                            $skill_stmt->bind_param('ii', $job_id, $sid);
                            $skill_stmt->execute();
                        }
                    }
                    $skill_stmt->close();
                }

                // Notify admin
                $admin_id = get_admin_user_id($conn);
                if ($admin_id) {
                    create_notification($conn, $admin_id, 'new_job', "New job \"{$old['title']}\" posted by " . e($user['username']) . " and needs approval.", "admin/approve_jobs.php");
                }

                set_flash('success', __('success.job_posted'));
                redirect('company/manage_jobs.php');
            }
        }
    }
}

$page_title = 'Post a Job';
require __DIR__ . '/../includes/header.php';
?>

<style>
/* Multi-step form styles */
.step-indicator { display:flex; gap:0; margin-bottom:2rem; }
.step-dot { flex:1; text-align:center; position:relative; }
.step-dot::after { content:''; position:absolute; top:18px; left:50%; width:100%; height:2px; background:var(--color-border); z-index:0; }
.step-dot:last-child::after { display:none; }
.step-dot .dot { width:36px; height:36px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:0.875rem; position:relative; z-index:1; transition:all .3s; }
.step-dot .label { display:block; margin-top:0.5rem; font-size:0.75rem; font-weight:600; color:var(--color-text-muted); }
.step-dot.active .dot { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; box-shadow:0 0 0 4px rgba(99,102,241,0.2); }
.step-dot.active .label { color:#6366f1; }
.step-dot.done .dot { background:linear-gradient(135deg,#10b981,#34d399); color:#fff; }
.step-dot.done .label { color:#10b981; }
.step-panel { display:none; animation:fadeSlide .4s ease; }
.step-panel.active { display:block; }
@keyframes fadeSlide { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }

/* Drag & drop zone */
.drop-zone { border:2px dashed var(--color-border); border-radius:1rem; padding:2rem; text-align:center; cursor:pointer; transition:all .3s; position:relative; }
.drop-zone:hover, .drop-zone.dragover { border-color:#6366f1; background:rgba(99,102,241,0.04); }
.drop-zone.has-file { border-color:#10b981; background:rgba(16,185,129,0.04); }
.drop-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }
.file-preview { display:flex; align-items:center; gap:0.75rem; padding:0.75rem 1rem; border-radius:0.75rem; background:var(--color-bg); border:1px solid var(--color-border); margin-top:0.75rem; }
.file-preview .remove-file { margin-left:auto; cursor:pointer; color:#ef4444; }

/* Skills chips */
.skill-chip { display:inline-flex; align-items:center; gap:0.375rem; padding:0.375rem 0.75rem; border-radius:9999px; font-size:0.8125rem; font-weight:500; cursor:pointer; transition:all .2s; border:1.5px solid var(--color-border); color:var(--color-text-secondary); user-select:none; }
.skill-chip:hover { border-color:#6366f1; color:#6366f1; }
.skill-chip.selected { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; border-color:transparent; box-shadow:0 2px 8px rgba(99,102,241,0.3); }
.skill-chip input { display:none; }

/* Gradient button */
.btn-gradient { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .3s; box-shadow:0 4px 15px rgba(99,102,241,0.3); }
.btn-gradient:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,0.4); }
.btn-gradient:active { transform:translateY(0); }
.btn-outline { border:2px solid var(--color-border); color:var(--color-text-primary); font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .2s; }
.btn-outline:hover { border-color:#6366f1; color:#6366f1; }
</style>

<div class="max-w-3xl mx-auto" style="padding-bottom:3rem">
    <!-- Header -->
    <div class="mb-8 text-center">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-4" style="background:linear-gradient(135deg,rgba(99,102,241,0.1),rgba(139,92,246,0.1))">
            <svg class="w-8 h-8" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </div>
        <h1 class="text-3xl font-bold" style="color:var(--color-text-primary)">Post a New Job</h1>
        <p class="mt-2 text-sm" style="color:var(--color-text-muted)">Fill in the details below to create your job posting</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl border flex items-center gap-3" style="background:rgba(239,68,68,0.06);border-color:rgba(239,68,68,0.2);color:#ef4444">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-sm font-medium"><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="jobForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot active" data-step="1">
                <span class="dot">1</span>
                <span class="label">Basics</span>
            </div>
            <div class="step-dot" data-step="2">
                <span class="dot">2</span>
                <span class="label">Details</span>
            </div>
            <div class="step-dot" data-step="3">
                <span class="dot">3</span>
                <span class="label">Skills & Attach</span>
            </div>
            <div class="step-dot" data-step="4">
                <span class="dot">4</span>
                <span class="label">Review</span>
            </div>
        </div>

        <!-- STEP 1: Basics -->
        <div class="step-panel active" data-panel="1">
            <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Basic Information
                </h2>

                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Job Title <span class="text-red-500">*</span></label>
                        <input type="text" name="title" required maxlength="200" placeholder="e.g. Full-Stack Web Developer Needed" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($old['title']) ?>">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Category <span class="text-red-500">*</span></label>
                        <select name="category" required class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                            <option value="">Select a category</option>
                            <?php
                            $cats = ['Web Development','Mobile Development','UI/UX Design','Graphic Design','Content Writing','Digital Marketing','Data Science','DevOps','Blockchain','Video & Animation','Translation','Other'];
                            foreach ($cats as $cat):
                            ?>
                                <option value="<?= e($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Budget ($) <span class="text-red-500">*</span></label>
                            <input type="number" name="budget" step="0.01" min="0.01" required placeholder="0.00" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($old['budget']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Visibility</label>
                            <select name="visibility" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                                <option value="public" <?= $old['visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                                <option value="private" <?= $old['visibility'] === 'private' ? 'selected' : '' ?>>Private</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="button" onclick="goStep(2)" class="btn-gradient flex items-center gap-2">
                    Next: Details
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>

        <!-- STEP 2: Details -->
        <div class="step-panel" data-panel="2">
            <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5" style="color:#8b5cf6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Job Details
                </h2>

                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Job Description <span class="text-red-500">*</span></label>
                        <textarea name="description" rows="5" required placeholder="Describe the project, goals, and what you're looking for..." class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"><?= e($old['description']) ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Requirements <span class="text-red-500">*</span></label>
                        <textarea name="requirements" rows="4" required placeholder="List the required qualifications, experience, and deliverables..." class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"><?= e($old['requirements']) ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Experience Level</label>
                            <select name="experience_level" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                                <option value="beginner" <?= $old['experience_level'] === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                                <option value="intermediate" <?= $old['experience_level'] === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                                <option value="expert" <?= $old['experience_level'] === 'expert' ? 'selected' : '' ?>>Expert</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Gender Requirement</label>
                            <select name="gender_requirement" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                                <option value="any" <?= $old['gender_requirement'] === 'any' ? 'selected' : '' ?>>Any</option>
                                <option value="male" <?= $old['gender_requirement'] === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= $old['gender_requirement'] === 'female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Deadline</label>
                            <input type="datetime-local" name="deadline" min="<?= date('Y-m-d\TH:i') ?>" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($old['deadline']) ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Estimated Duration</label>
                            <input type="text" name="duration" placeholder="e.g. 2 weeks, 1 month" maxlength="100" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($old['duration']) ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Freelancers Needed</label>
                            <input type="number" name="freelancers_needed" min="1" max="50" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($old['freelancers_needed']) ?>">
                        </div>
                        <div class="flex items-end pb-1">
                            <div class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl" style="background:linear-gradient(135deg,rgba(16,185,129,0.1),rgba(52,211,153,0.1));border:1px solid rgba(16,185,129,0.2)">
                                <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Remote Only</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-between">
                <button type="button" onclick="goStep(1)" class="btn-outline flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back
                </button>
                <button type="button" onclick="goStep(3)" class="btn-gradient flex items-center gap-2">
                    Next: Skills
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>

        <!-- STEP 3: Skills & Attachment -->
        <div class="step-panel" data-panel="3">
            <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                    Skills & Attachment
                </h2>

                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-semibold mb-2" style="color:var(--color-text-secondary)">Required Skills <span class="text-red-500">*</span></label>
                        <p class="text-xs mb-3" style="color:var(--color-text-muted)">Click to select the skills needed for this job</p>
                        <div class="flex flex-wrap gap-2" id="skillsContainer">
                            <?php foreach ($all_skills as $sk): ?>
                                <label class="skill-chip <?= in_array($sk['id'], $old['skills']) ? 'selected' : '' ?>">
                                    <input type="checkbox" name="skills[]" value="<?= (int)$sk['id'] ?>" <?= in_array($sk['id'], $old['skills']) ? 'checked' : '' ?>>
                                    <?= e($sk['skill_name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold mb-2" style="color:var(--color-text-secondary)">Attachment (Optional)</label>
                        <div class="drop-zone" id="dropZone">
                            <input type="file" name="attachment" id="attachmentInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar">
                            <svg class="w-10 h-10 mx-auto mb-3" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                            <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Drag & drop a file here, or <span style="color:#6366f1">browse</span></p>
                            <p class="text-xs mt-1" style="color:var(--color-text-muted)">JPG, PNG, PDF, DOCX, ZIP up to 10MB</p>
                        </div>
                        <div id="filePreview" class="file-preview" style="display:none">
                            <span id="fileIcon"></span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)" id="fileName"></p>
                                <p class="text-xs" style="color:var(--color-text-muted)" id="fileSize"></p>
                            </div>
                            <span class="remove-file" id="removeFile" title="Remove file">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-between">
                <button type="button" onclick="goStep(2)" class="btn-outline flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back
                </button>
                <button type="button" onclick="goStep(4)" class="btn-gradient flex items-center gap-2">
                    Next: Review
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        </div>

        <!-- STEP 4: Review -->
        <div class="step-panel" data-panel="4">
            <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
                <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5" style="color:#f59e0b" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Review Your Job
                </h2>

                <div class="space-y-4" id="reviewSection">
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Title</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewTitle">-</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Category</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewCategory">-</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Budget</span>
                        <span class="text-sm font-bold" style="color:#6366f1" id="reviewBudget">-</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Experience Level</span>
                        <span class="text-sm font-semibold capitalize" style="color:var(--color-text-primary)" id="reviewExp">-</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Gender Requirement</span>
                        <span class="text-sm font-semibold capitalize" style="color:var(--color-text-primary)" id="reviewGender">-</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Deadline</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewDeadline">Not set</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Duration</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewDuration">Not set</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Freelancers Needed</span>
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="reviewFreelancers">1</span>
                    </div>
                    <div class="flex justify-between py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Visibility</span>
                        <span class="text-sm font-semibold capitalize" style="color:var(--color-text-primary)" id="reviewVisibility">public</span>
                    </div>
                    <div class="py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium block mb-1" style="color:var(--color-text-muted)">Description</span>
                        <p class="text-sm" style="color:var(--color-text-secondary)" id="reviewDesc">-</p>
                    </div>
                    <div class="py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium block mb-1" style="color:var(--color-text-muted)">Requirements</span>
                        <p class="text-sm" style="color:var(--color-text-secondary)" id="reviewReq">-</p>
                    </div>
                    <div class="py-2 border-b" style="border-color:var(--color-border)">
                        <span class="text-sm font-medium block mb-1" style="color:var(--color-text-muted)">Skills</span>
                        <div class="flex flex-wrap gap-1.5" id="reviewSkills"></div>
                    </div>
                    <div class="flex justify-between py-2">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Attachment</span>
                        <span class="text-sm" style="color:var(--color-text-primary)" id="reviewAttachment">None</span>
                    </div>
                    <div class="flex justify-between py-2">
                        <span class="text-sm font-medium" style="color:var(--color-text-muted)">Work Location</span>
                        <span class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-600">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Remote
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex justify-between">
                <button type="button" onclick="goStep(3)" class="btn-outline flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    Back
                </button>
                <button type="submit" class="btn-gradient flex items-center gap-2 text-base px-8 py-3">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    Submit for Approval
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function(){
    var currentStep = 1;
    var totalSteps = 4;

    window.goStep = function(step) {
        if (step < 1 || step > totalSteps) return;
        document.querySelectorAll('.step-panel').forEach(function(p){ p.classList.remove('active'); });
        document.querySelectorAll('.step-dot').forEach(function(d){ d.classList.remove('active','done'); });

        for (var i = 1; i < step; i++) {
            document.querySelector('.step-dot[data-step="'+i+'"]').classList.add('done');
        }
        document.querySelector('.step-dot[data-step="'+step+'"]').classList.add('active');
        document.querySelector('.step-panel[data-panel="'+step+'"]').classList.add('active');
        currentStep = step;

        if (step === 4) buildReview();
        window.scrollTo({top:0,behavior:'smooth'});
    };

    function buildReview() {
        var f = document.getElementById('jobForm');
        var g = function(n){ var el=f.querySelector('[name="'+n+'"]'); return el?el.value:''; };
        document.getElementById('reviewTitle').textContent = g('title') || '-';
        document.getElementById('reviewCategory').textContent = g('category') || '-';
        document.getElementById('reviewBudget').textContent = g('budget') ? '$'+parseFloat(g('budget')).toLocaleString('en',{minimumFractionDigits:2}) : '-';
        document.getElementById('reviewExp').textContent = g('experience_level');
        document.getElementById('reviewGender').textContent = g('gender_requirement');
        document.getElementById('reviewDeadline').textContent = g('deadline') ? new Date(g('deadline')).toLocaleString() : 'Not set';
        document.getElementById('reviewDuration').textContent = g('duration') || 'Not set';
        document.getElementById('reviewFreelancers').textContent = g('freelancers_needed') || '1';
        document.getElementById('reviewVisibility').textContent = g('visibility');
        document.getElementById('reviewDesc').textContent = g('description') || '-';
        document.getElementById('reviewReq').textContent = g('requirements') || '-';

        var skillsHtml = '';
        document.querySelectorAll('.skill-chip.selected').forEach(function(c){
            skillsHtml += '<span class="inline-flex px-2.5 py-1 text-xs font-medium rounded-full" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff">'+c.textContent.trim()+'</span>';
        });
        document.getElementById('reviewSkills').innerHTML = skillsHtml || '<span class="text-xs" style="color:var(--color-text-muted)">No skills selected</span>';

        var fileInput = document.getElementById('attachmentInput');
        document.getElementById('reviewAttachment').textContent = fileInput.files.length > 0 ? fileInput.files[0].name : 'None';
    }

    // Skill chips toggle
    document.querySelectorAll('.skill-chip').forEach(function(chip){
        chip.addEventListener('click', function(e){
            e.preventDefault();
            var cb = chip.querySelector('input[type=checkbox]');
            cb.checked = !cb.checked;
            chip.classList.toggle('selected', cb.checked);
        });
    });

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
    }

    removeFile.addEventListener('click', function(){
        fileInput.value = '';
        filePreview.style.display = 'none';
        dropZone.classList.remove('has-file');
    });

    // Client-side validation on submit
    document.getElementById('jobForm').addEventListener('submit', function(e){
        var title = document.querySelector('[name="title"]').value.trim();
        var cat = document.querySelector('[name="category"]').value;
        var budget = parseFloat(document.querySelector('[name="budget"]').value);
        var desc = document.querySelector('[name="description"]').value.trim();
        var req = document.querySelector('[name="requirements"]').value.trim();
        var skills = document.querySelectorAll('.skill-chip.selected').length;
        var deadline = document.querySelector('[name="deadline"]').value;

        if(!title){ alert('Job title is required.'); e.preventDefault(); goStep(1); return; }
        if(!cat){ alert('Category is required.'); e.preventDefault(); goStep(1); return; }
        if(!budget || budget<=0){ alert('Budget must be greater than zero.'); e.preventDefault(); goStep(1); return; }
        if(!desc){ alert('Job description is required.'); e.preventDefault(); goStep(2); return; }
        if(!req){ alert('Requirements are required.'); e.preventDefault(); goStep(2); return; }
        if(skills===0){ alert('Please select at least one skill.'); e.preventDefault(); goStep(3); return; }
        if(deadline && new Date(deadline) < new Date()){ alert('Deadline cannot be in the past.'); e.preventDefault(); goStep(2); return; }

        var fileInput = document.getElementById('attachmentInput');
        if(fileInput.files.length){
            var file = fileInput.files[0];
            var ext = file.name.split('.').pop().toLowerCase();
            var allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','zip','rar'];
            if(allowed.indexOf(ext)===-1){ alert('Invalid file type.'); e.preventDefault(); goStep(3); return; }
            if(file.size > 10*1024*1024){ alert('File must be under 10MB.'); e.preventDefault(); goStep(3); return; }
        }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
