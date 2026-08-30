<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', 'Invalid job.');
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT * FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('company/manage_jobs.php');
}

$all_skills = [];
$res = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $all_skills[] = $row;
    }
}

$current_skills = [];
$st = $conn->prepare("SELECT skill_id FROM job_skills WHERE job_id = ?");
$st->bind_param('i', $job_id);
$st->execute();
$rs = $st->get_result();
while ($r = $rs->fetch_assoc()) {
    $current_skills[] = (int) $r['skill_id'];
}
$st->close();

if ($job['status'] === 'completed') {
    set_flash('error', 'Completed jobs cannot be edited.');
    redirect('company/manage_jobs.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $requirements = trim($_POST['requirements'] ?? '');
        $budget = (float) ($_POST['budget'] ?? 0);
        $experience_level = $_POST['experience_level'] ?? 'intermediate';        $deadline = trim($_POST['deadline'] ?? '') ?: null;
        $duration = trim($_POST['duration'] ?? '');
        $selected_skills = $_POST['skills'] ?? [];

        if ($title === '' || $category === '') {
            $error = 'Job title and category are required.';
        } elseif ($budget <= 0) {
            $error = 'Budget must be greater than zero.';
        } elseif ($description === '') {
            $error = 'Job description is required.';
        } elseif ($requirements === '') {
            $error = 'Requirements are required.';
        } elseif ($deadline !== null && strtotime($deadline) < time()) {
            $error = 'Deadline cannot be in the past.';
        } else {
            // Handle new attachment upload
            $attachment_name = $job['attachment'];
            if (!empty($_FILES['attachment']['name'])) {
                $new_att = upload_attachment($_FILES['attachment']);
                if ($new_att === null) {
                    $error = 'Invalid attachment. Allowed: JPG, PNG, GIF, WebP, PDF, DOCX, ZIP. Max 500MB.';
                } else {
                    // Delete old attachment
                    if ($job['attachment']) {
                        delete_attachment($job['attachment']);
                    }
                    $attachment_name = $new_att;
                }
            }

            // Handle attachment removal
            if (isset($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1') {
                if ($job['attachment']) {
                    delete_attachment($job['attachment']);
                }
                $attachment_name = null;
            }

            if (!$error) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare('UPDATE jobs SET title=?, category=?, experience_level=?, description=?, requirements=?, budget=?, deadline=?, duration=?, attachment=? WHERE id=? AND company_id=?');
                    $stmt->bind_param('sssssdssii', $title, $category, $experience_level, $description, $requirements, $budget, $deadline, $duration, $attachment_name, $job_id, $company_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM job_skills WHERE job_id = ?");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $stmt->close();

                    if (!empty($selected_skills)) {
                        $stmt = $conn->prepare("INSERT INTO job_skills (job_id, skill_id) VALUES (?, ?)");
                        foreach ($selected_skills as $sid) {
                            $sid = (int) $sid;
                            $stmt->bind_param('ii', $job_id, $sid);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }

                    $conn->commit();
                    set_flash('success', 'Job updated successfully.');
                    redirect('company/manage_jobs.php');
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = 'Error updating job: ' . $e->getMessage();
                }
            }
        }
    }
}

$page_title = 'Edit Job';
require __DIR__ . '/../includes/header.php';
?>

<style>
.drop-zone { border:2px dashed var(--color-border); border-radius:1rem; padding:1.5rem; text-align:center; cursor:pointer; transition:all .3s; position:relative; }
.drop-zone:hover, .drop-zone.dragover { border-color:#6366f1; background:rgba(99,102,241,0.04); }
.drop-zone.has-file { border-color:#10b981; background:rgba(16,185,129,0.04); }
.drop-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }
.file-preview { display:flex; align-items:center; gap:0.75rem; padding:0.75rem 1rem; border-radius:0.75rem; background:var(--color-bg); border:1px solid var(--color-border); margin-top:0.75rem; }
.file-preview .remove-file { margin-left:auto; cursor:pointer; color:#ef4444; }
.btn-gradient { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .3s; box-shadow:0 4px 15px rgba(99,102,241,0.3); }
.btn-gradient:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,0.4); }
.btn-outline { border:2px solid var(--color-border); color:var(--color-text-primary); font-weight:600; padding:0.75rem 1.5rem; border-radius:0.75rem; transition:all .2s; }
.btn-outline:hover { border-color:#6366f1; color:#6366f1; }
</style>

<div class="max-w-3xl mx-auto" style="padding-bottom:3rem">
    <div class="mb-8">
        <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="inline-flex items-center gap-1 text-sm mb-4" style="color:var(--color-text-muted)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to Jobs
        </a>
        <h1 class="text-3xl font-bold" style="color:var(--color-text-primary)">Edit Job</h1>
        <p class="mt-2 text-sm" style="color:var(--color-text-muted)">Update your job posting details</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl border flex items-center gap-3" style="background:rgba(239,68,68,0.06);border-color:rgba(239,68,68,0.2);color:#ef4444">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <span class="text-sm font-medium"><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="editJobForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="remove_attachment" id="removeAttInput" value="0">

        <!-- Basic Info -->
        <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                <svg class="w-5 h-5" style="color:#6366f1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Basic Information
            </h2>
            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Job Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required maxlength="200" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($_POST['title'] ?? $job['title']) ?>">
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Category <span class="text-red-500">*</span></label>
                        <select name="category" required class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                            <option value="">Select a category</option>
                            <?php
                            $cats = [];
                            $res = $conn->query("SELECT name FROM categories WHERE LOWER(name) NOT IN ('direct hire', 'direct offer') ORDER BY CASE WHEN LOWER(name) = 'other' THEN 1 ELSE 0 END, name ASC");
                            if ($res) {
                                while ($row = $res->fetch_assoc()) {
                                    $cats[] = $row['name'];
                                }
                            }
                            $cur_cat = $_POST['category'] ?? $job['category'];
                            foreach ($cats as $cat):
                            ?>
                                <option value="<?= e($cat) ?>" <?= $cur_cat === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Budget ($) <span class="text-red-500">*</span></label>
                        <input type="number" name="budget" step="0.01" min="0.01" required class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($_POST['budget'] ?? $job['budget']) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Details -->
        <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                <svg class="w-5 h-5" style="color:#8b5cf6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Job Details
            </h2>
            <div class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Description <span class="text-red-500">*</span></label>
                    <textarea name="description" rows="5" required class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"><?= e($_POST['description'] ?? $job['description']) ?></textarea>
                </div>
                <div>
                    <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Requirements <span class="text-red-500">*</span></label>
                    <textarea name="requirements" rows="4" required class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all resize-y" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"><?= e($_POST['requirements'] ?? $job['requirements'] ?? '') ?></textarea>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Experience Level</label>
                        <select name="experience_level" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                            <?php $cur_el = $_POST['experience_level'] ?? $job['experience_level'] ?? 'intermediate'; ?>
                            <option value="beginner" <?= $cur_el === 'beginner' ? 'selected' : '' ?>>Beginner</option>
                            <option value="intermediate" <?= $cur_el === 'intermediate' ? 'selected' : '' ?>>Intermediate</option>
                            <option value="expert" <?= $cur_el === 'expert' ? 'selected' : '' ?>>Expert</option>
                        </select>
                    </div>

                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Deadline</label>
                        <input type="datetime-local" name="deadline" min="<?= date('Y-m-d\TH:i') ?>" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($_POST['deadline'] ?? ($job['deadline'] ? date('Y-m-d\TH:i', strtotime($job['deadline'])) : '')) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Estimated Duration</label>
                        <input type="text" name="duration" maxlength="100" placeholder="e.g. 2 weeks" class="w-full px-4 py-3 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-all" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($_POST['duration'] ?? $job['duration'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Skills -->
        <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                <svg class="w-5 h-5" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                Required Skills
            </h2>
            <p class="text-xs mb-3" style="color:var(--color-text-muted)">Click to select the skills needed for this job</p>
            <div class="flex flex-wrap gap-2" id="skillsContainer">
                <?php foreach ($all_skills as $sk): ?>
                    <label class="skill-chip <?= in_array($sk['id'], $current_skills) ? 'selected' : '' ?>">
                        <input type="checkbox" name="skills[]" value="<?= (int)$sk['id'] ?>" <?= in_array($sk['id'], $current_skills) ? 'checked' : '' ?>>
                        <?= e($sk['skill_name']) ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Attachment -->
        <div class="rounded-2xl p-6 mb-6" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 20px rgba(0,0,0,0.04)">
            <h2 class="text-lg font-bold mb-5 flex items-center gap-2" style="color:var(--color-text-primary)">
                <svg class="w-5 h-5" style="color:#f59e0b" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                Attachment
            </h2>

            <?php if ($job['attachment']): ?>
                <div id="existingAttachment" class="file-preview mb-4">
                    <span><?= attachment_icon($job['attachment']) ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($job['attachment']) ?></p>
                        <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" class="text-xs" style="color:#6366f1">Download current file</a>
                    </div>
                    <span class="remove-file" id="removeExistingAtt" title="Remove attachment">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </span>
                </div>
            <?php endif; ?>

            <div class="drop-zone" id="dropZone">
                <input type="file" name="attachment" id="attachmentInput" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.zip,.rar">
                <svg class="w-10 h-10 mx-auto mb-3" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                <p class="text-sm font-medium" style="color:var(--color-text-secondary)">Drag & drop a new file to replace, or <span style="color:#6366f1">browse</span></p>
                <p class="text-xs mt-1" style="color:var(--color-text-muted)">JPG, PNG, PDF, DOCX, ZIP up to 500MB</p>
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

        <div class="flex gap-3">
            <button type="submit" class="btn-gradient flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                Save Changes
            </button>
            <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="btn-outline">Cancel</a>
        </div>
    </form>
</div>

<script>
(function(){
    // Skill chips
    document.querySelectorAll('.skill-chip input[type="checkbox"]').forEach(function(cb){
        cb.addEventListener('change', function(){
            this.closest('.skill-chip').classList.toggle('selected', this.checked);
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

    // Remove existing attachment
    var removeExisting = document.getElementById('removeExistingAtt');
    if(removeExisting){
        removeExisting.addEventListener('click', function(){
            document.getElementById('existingAttachment').style.display = 'none';
            document.getElementById('removeAttInput').value = '1';
        });
    }

    // Validation
    document.getElementById('editJobForm').addEventListener('submit', function(e){
        var title = document.querySelector('[name="title"]').value.trim();
        var cat = document.querySelector('[name="category"]').value;
        var budget = parseFloat(document.querySelector('[name="budget"]').value);
        var desc = document.querySelector('[name="description"]').value.trim();
        var req = document.querySelector('[name="requirements"]').value.trim();
        var skills = document.querySelectorAll('.skill-chip.selected').length;
        var deadline = document.querySelector('[name="deadline"]').value;

        if(!title){ alert('Job title is required.'); e.preventDefault(); return; }
        if(!cat){ alert('Category is required.'); e.preventDefault(); return; }
        if(!budget || budget<=0){ alert('Budget must be greater than zero.'); e.preventDefault(); return; }
        if(!desc){ alert('Job description is required.'); e.preventDefault(); return; }
        if(!req){ alert('Requirements are required.'); e.preventDefault(); return; }
        if(skills===0){ alert('Please select at least one skill.'); e.preventDefault(); return; }
        if(deadline && new Date(deadline) < new Date()){ alert('Deadline cannot be in the past.'); e.preventDefault(); return; }
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
