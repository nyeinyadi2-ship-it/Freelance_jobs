<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

$job_id = (int) ($_GET['id'] ?? 0);
if ($job_id <= 0) {
    set_flash('error', 'Invalid job ID.');
    redirect('admin/approve_jobs.php');
}

// Handle moderation actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if (in_array($action, ['hide', 'restore', 'remove'], true)) {
        try {
            if ($action === 'hide') {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'rejected' WHERE id = ? AND status = 'open'");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt2->bind_param('i', $job_id);
                    $stmt2->execute();
                    $job_info = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();

                    if ($job_info) {
                        create_notification($conn, (int) $job_info['user_id'], 'job_hidden',
                            "Hidden your job \"{$job_info['title']}\" for policy violation.",
                            'company/manage_jobs.php', $_SESSION['user_id'] ?? null);
                    }
                    set_flash('success', 'Job has been hidden successfully.');
                } else {
                    set_flash('error', 'Could not hide this job.');
                }
                $stmt->close();

            } elseif ($action === 'restore') {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'open' WHERE id = ? AND status = 'rejected'");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt2->bind_param('i', $job_id);
                    $stmt2->execute();
                    $job_info = $stmt2->get_result()->fetch_assoc();
                    $stmt2->close();

                    if ($job_info) {
                        create_notification($conn, (int) $job_info['user_id'], 'job_restored',
                            "Restored your job \"{$job_info['title']}\". It is now visible again.",
                            'company/manage_jobs.php', $_SESSION['user_id'] ?? null);
                    }
                    set_flash('success', 'Job has been restored successfully.');
                } else {
                    set_flash('error', 'Could not restore this job.');
                }
                $stmt->close();

            } elseif ($action === 'remove') {
                $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    set_flash('success', 'Job has been permanently removed.');
                } else {
                    set_flash('error', 'Could not remove this job.');
                }
                $stmt->close();
            }
        } catch (mysqli_sql_exception $e) {
            set_flash('error', 'An error occurred while processing the request.');
        }
    }

    redirect('admin/approve_jobs.php');
}

// Fetch job with company info
$job = null;
try {
    $stmt = $conn->prepare("
        SELECT j.*, c.company_name, c.logo_image, c.location AS company_location,
               c.website, c.industry, c.company_size, c.description AS company_description,
               c.established_year, c.phone AS company_phone,
               u.username AS posted_by_name, u.profile_image AS posted_by_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE j.id = ?
    ");
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $job = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $job = null;
}

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('admin/approve_jobs.php');
}

// Fetch skills
$skills = [];
try {
    $ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
    $ss->bind_param('i', $job_id);
    $ss->execute();
    $sr = $ss->get_result();
    while ($sk = $sr->fetch_assoc()) {
        $skills[] = $sk['skill_name'];
    }
    $ss->close();
} catch (mysqli_sql_exception $e) {}

// Fetch milestones
$milestones = [];
try {
    $ms = $conn->prepare('SELECT * FROM milestones WHERE job_id = ? ORDER BY sort_order ASC');
    $ms->bind_param('i', $job_id);
    $ms->execute();
    $mr = $ms->get_result();
    while ($m = $mr->fetch_assoc()) {
        $milestones[] = $m;
    }
    $ms->close();
} catch (mysqli_sql_exception $e) {}

// Count applications
$app_count = 0;
try {
    $ac = $conn->prepare('SELECT COUNT(*) AS cnt FROM job_applications WHERE job_id = ?');
    $ac->bind_param('i', $job_id);
    $ac->execute();
    $app_count = (int) $ac->get_result()->fetch_assoc()['cnt'];
    $ac->close();
} catch (mysqli_sql_exception $e) {}

// Count assignments
$assign_count = 0;
try {
    $as = $conn->prepare('SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status NOT IN (\'rejected\', \'cancelled\')');
    $as->bind_param('i', $job_id);
    $as->execute();
    $assign_count = (int) $as->get_result()->fetch_assoc()['cnt'];
    $as->close();
} catch (mysqli_sql_exception $e) {}

$page_title = 'Job Details - ' . e($job['title']);
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Breadcrumb + Back -->
<div class="mb-5 admin-fade">
    <div class="flex items-center gap-2 mb-2">
        <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-xs hover:underline" style="color:var(--color-text-muted)">Moderate Jobs</a>
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-xs font-medium" style="color:var(--color-text-primary)">Job Details</span>
    </div>
    <button type="button" onclick="history.back()" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-md border hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors" style="color:var(--color-text-muted);border-color:var(--color-border)">
        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Moderate Jobs
    </button>
</div>

<div class="grid lg:grid-cols-3 gap-4">
    <!-- Main Content -->
    <div class="lg:col-span-2 space-y-4">
        <!-- Job Header Card -->
        <div class="card admin-fade">
            <div class="flex items-start gap-3 mb-4">
                <?php if ($job['logo_image']): ?>
                    <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-lg object-cover flex-shrink-0">
                <?php else: ?>
                    <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-sm flex-shrink-0">
                        <?= e(_first_char($job['company_name'])) ?>
                    </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?></span>
                        <?php if ($job['status'] === 'rejected'): ?>
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">Hidden</span>
                        <?php elseif ($job['status'] === 'open'): ?>
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">Active</span>
                        <?php else: ?>
                            <?= status_badge($job['status']) ?>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-lg font-bold mb-1" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h1>
                    <div class="flex items-center gap-2 text-xs flex-wrap" style="color:var(--color-text-muted)">
                        <span>Posted <?= e($job['created_at']) ?></span>
                        <?php if ($job['category']): ?>
                            <span>&middot;</span>
                            <span><?= e($job['category']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Quick Info Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3 rounded-lg" style="background:var(--color-card-hover)">
                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider mb-0.5" style="color:var(--color-text-muted)">Budget</p>
                    <p class="text-sm font-bold text-indigo-600"><?= e(number_format((float) $job['budget'], 0)) ?> MMK</p>
                </div>
                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider mb-0.5" style="color:var(--color-text-muted)">Experience</p>
                    <p class="text-sm font-medium capitalize" style="color:var(--color-text-primary)"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></p>
                </div>

                <div>
                    <p class="text-[10px] font-medium uppercase tracking-wider mb-0.5" style="color:var(--color-text-muted)">Deadline</p>
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= $job['deadline'] ? e(date('M j, Y', strtotime($job['deadline']))) : '—' ?></p>
                </div>
            </div>
        </div>

        <!-- Description -->
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Job Description</h2>
            <div class="text-sm leading-relaxed" style="color:var(--color-text-secondary)">
                <?= nl2br(e($job['description'] ?? 'No description provided.')) ?>
            </div>
        </div>

        <!-- Requirements -->
        <?php if (!empty(trim($job['requirements'] ?? ''))): ?>
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Requirements</h2>
            <div class="text-sm leading-relaxed" style="color:var(--color-text-secondary)">
                <?= nl2br(e($job['requirements'])) ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Skills -->
        <?php if (!empty($skills)): ?>
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Required Skills</h2>
            <div class="flex flex-wrap gap-1.5">
                <?php foreach ($skills as $sk): ?>
                    <span class="inline-flex px-2 py-1 rounded-md text-xs font-medium" style="background:rgba(99,102,241,0.08);color:#6366f1"><?= e($sk) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Milestones -->
        <?php if (!empty($milestones)): ?>
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Project Milestones</h2>
            <div class="space-y-2">
                <?php foreach ($milestones as $ms): ?>
                    <div class="flex items-center justify-between p-2.5 rounded-lg border" style="border-color:var(--color-border)">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($ms['title']) ?></p>
                            <?php if ($ms['description']): ?>
                                <p class="text-xs mt-0.5 line-clamp-2" style="color:var(--color-text-muted)"><?= e($ms['description']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-3 ml-3 flex-shrink-0">
                            <?php if ($ms['deadline']): ?>
                                <span class="text-[10px]" style="color:var(--color-text-muted)"><?= e(date('M j, Y', strtotime($ms['deadline']))) ?></span>
                            <?php endif; ?>
                            <span class="text-sm font-semibold text-indigo-600"><?= e(number_format((float) $ms['amount'], 0)) ?> MMK</span>
                            <?php
                            $ms_status_classes = [
                                'draft' => 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                'funded' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                                'in_progress' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
                                'submitted' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
                                'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
                                'revision_requested' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                            ];
                            $ms_sc = $ms_status_classes[$ms['status']] ?? $ms_status_classes['draft'];
                            ?>
                            <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold capitalize <?= $ms_sc ?>"><?= e(str_replace('_', ' ', $ms['status'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attachment -->
        <?php if ($job['attachment']): ?>
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Attachment</h2>
            <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-2 px-3 py-2 rounded-lg border text-sm font-medium transition-colors hover:bg-gray-50 dark:hover:bg-gray-800" style="border-color:var(--color-border);color:var(--color-text-primary)">
                <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                <?= e(basename($job['attachment'])) ?>
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar -->
    <div class="space-y-4">
        <!-- Moderation Actions -->
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Moderation Actions</h2>
            <div class="space-y-2">
                <?php if ($job['status'] === 'open' || $job['status'] === 'approved'): ?>
                    <form method="POST" id="hideForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="hide">
                        <button type="button" onclick="document.getElementById('hideModal').classList.remove('hidden')" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                            Hide Job
                        </button>
                    </form>
                <?php elseif ($job['status'] === 'rejected'): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="restore">
                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium bg-green-50 text-green-600 hover:bg-green-100 dark:bg-green-900/20 dark:text-green-400 dark:hover:bg-green-900/40 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Restore Job
                        </button>
                    </form>

                    <form method="POST" id="removeForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="remove">
                        <button type="button" onclick="document.getElementById('removeModal').classList.remove('hidden')" class="w-full inline-flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg text-sm font-medium bg-red-50 text-red-600 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Permanently Delete
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Job Info -->
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Job Information</h2>
            <div class="space-y-2.5 text-sm">
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Job ID</span>
                    <span class="font-medium" style="color:var(--color-text-primary)">#<?= $job['id'] ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Status</span>
                    <?php if ($job['status'] === 'rejected'): ?>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300">Hidden</span>
                    <?php elseif ($job['status'] === 'open'): ?>
                        <span class="inline-flex px-1.5 py-0.5 rounded text-[10px] font-semibold bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">Active</span>
                    <?php else: ?>
                        <?= status_badge($job['status']) ?>
                    <?php endif; ?>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Payment Type</span>
                    <span class="font-medium capitalize" style="color:var(--color-text-primary)"><?= e($job['payment_type'] ?? 'fixed') ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Duration</span>
                    <span style="color:var(--color-text-primary)"><?= e($job['duration'] ?: '—') ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Gender</span>
                    <span class="capitalize" style="color:var(--color-text-primary)"><?= e(ucfirst($job['gender_requirement'] ?? 'any')) ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Visibility</span>
                    <span class="capitalize" style="color:var(--color-text-primary)"><?= e(ucfirst($job['visibility'] ?? 'public')) ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Applications</span>
                    <span style="color:var(--color-text-primary)"><?= $app_count ?></span>
                </div>
                <div class="flex justify-between">
                    <span style="color:var(--color-text-muted)">Assignments</span>
                    <span style="color:var(--color-text-primary)"><?= $assign_count ?></span>
                </div>
            </div>
        </div>

        <!-- Company Info -->
        <div class="card admin-fade">
            <h2 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)">Company Information</h2>
            <div class="flex items-center gap-2.5 mb-3">
                <?php if ($job['logo_image']): ?>
                    <img src="<?= e(base_url('uploads/images/' . $job['logo_image'])) ?>" alt="" class="w-9 h-9 rounded-lg object-cover flex-shrink-0">
                <?php else: ?>
                    <div class="w-9 h-9 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-xs flex-shrink-0">
                        <?= e(_first_char($job['company_name'])) ?>
                    </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['company_name']) ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)">Posted by <?= e($job['posted_by_name']) ?></p>
                </div>
            </div>
            <div class="space-y-2 text-sm">
                <?php if ($job['company_location']): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        <span style="color:var(--color-text-secondary)"><?= e($job['company_location']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($job['industry']): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        <span style="color:var(--color-text-secondary)"><?= e($job['industry']) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($job['website']): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                        <a href="<?= e($job['website']) ?>" target="_blank" class="text-indigo-600 hover:underline" style="font-size:0.8125rem"><?= e($job['website']) ?></a>
                    </div>
                <?php endif; ?>
                <?php if ($job['company_size']): ?>
                    <div class="flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <span style="color:var(--color-text-secondary)"><?= e($job['company_size']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($job['company_description']): ?>
                <div class="mt-3 pt-3 border-t" style="border-color:var(--color-border)">
                    <p class="text-xs leading-relaxed line-clamp-4" style="color:var(--color-text-muted)"><?= e($job['company_description']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Hide Confirmation Modal -->
<div id="hideModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-sm" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="p-5 text-center">
                <div class="w-10 h-10 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:rgba(239,68,68,0.1)">
                    <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </div>
                <h3 class="text-sm font-semibold mb-1" style="color:var(--color-text-primary)">Hide Job</h3>
                <p class="text-xs mb-5" style="color:var(--color-text-muted)">Are you sure you want to hide this job? It will no longer be visible to freelancers.</p>
                <div class="flex justify-center gap-2">
                    <button type="button" onclick="document.getElementById('hideModal').classList.add('hidden')" class="btn-secondary text-xs">Cancel</button>
                    <button type="submit" form="hideForm" class="btn-danger text-xs">Hide Job</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Remove Confirmation Modal -->
<div id="removeModal" class="fixed inset-0 z-50 hidden" style="background:rgba(0,0,0,0.4);backdrop-filter:blur(4px)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="card w-full max-w-sm" style="background:var(--color-card);border:1px solid var(--color-border)">
            <div class="p-5 text-center">
                <div class="w-10 h-10 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:rgba(239,68,68,0.1)">
                    <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </div>
                <h3 class="text-sm font-semibold mb-1" style="color:var(--color-text-primary)">Permanently Delete</h3>
                <p class="text-xs mb-5" style="color:var(--color-text-muted)">This action cannot be undone. The job and all associated data will be permanently removed.</p>
                <div class="flex justify-center gap-2">
                    <button type="button" onclick="document.getElementById('removeModal').classList.add('hidden')" class="btn-secondary text-xs">Cancel</button>
                    <button type="submit" form="removeForm" class="btn-danger text-xs">Delete Permanently</button>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>

<script>
(function() {
    var els = document.querySelectorAll('.admin-fade');
    els.forEach(function(el) { el.classList.add('animate'); });
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.1 });
    els.forEach(function(el) { obs.observe(el); });

    // Close modals on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.getElementById('hideModal').classList.add('hidden');
            document.getElementById('removeModal').classList.add('hidden');
        }
    });

    // Close modals on backdrop click
    document.getElementById('hideModal').addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
    document.getElementById('removeModal').addEventListener('click', function(e) { if (e.target === this) this.classList.add('hidden'); });
})();
</script>
