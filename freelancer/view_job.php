<?php
$page_title = 'Job Details';
require __DIR__ . '/../includes/freelancer_init.php';

$job_id = (int) ($_GET['id'] ?? 0);
if ($job_id <= 0) {
    set_flash('error', 'Invalid job ID.');
    redirect('freelancer/browse_jobs.php');
}

// Handle Apply
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'apply' && verify_csrf()) {
    $st = $conn->prepare("SELECT id, company_id FROM jobs WHERE id = ? AND status = 'approved'");
    $st->bind_param('i', $job_id); $st->execute();
    $job_check = $st->get_result()->fetch_assoc(); $st->close();

    if (!$job_check) {
        set_flash('error', __('error.job_not_available'));
    } else {
        $st = $conn->prepare('SELECT id FROM assignments WHERE job_id = ?');
        $st->bind_param('i', $job_id); $st->execute();
        $already_assigned = $st->get_result()->num_rows > 0; $st->close();

        if ($already_assigned) {
            set_flash('error', __('error.job_already_assigned'));
        } else {
            $st = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
            $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute();
            $already_applied = $st->get_result()->num_rows > 0; $st->close();

            if ($already_applied) {
                set_flash('error', __('error.already_applied'));
            } else {
                $st = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                $st->bind_param('ii', $job_id, $fl_freelancer_id); $st->execute(); $st->close();

                $st = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                $st->bind_param('i', $job_id); $st->execute();
                $ji = $st->get_result()->fetch_assoc(); $st->close();
                if ($ji) {
                    create_notification($conn, (int) $ji['user_id'], 'new_application', $fl_user['username'] . " applied for your job \"{$ji['title']}\".", 'company/view_applications.php?id=' . $job_id);
                }
                set_flash('success', __('success.application_submitted'));
            }
        }
    }
    redirect('freelancer/view_job.php?id=' . $job_id);
}

// Fetch job details
$stmt = $conn->prepare("
    SELECT j.*, c.company_name, c.logo_image, c.location AS company_location, c.website, c.industry, c.company_size, c.description AS company_description,
           u.id AS client_user_id, u.username AS client_username, u.profile_image AS client_profile_image,
           COALESCE(c.company_name, u.username) AS client_display_name
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    JOIN users u ON c.user_id = u.id
    WHERE j.id = ? AND j.status IN ('approved', 'completed')
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Job not found
if (!$job) {
    require __DIR__ . '/../includes/freelancer_layout.php';
?>
<div class="max-w-xl mx-auto px-4 pt-16 pb-24 text-center">
    <div class="glass rounded-3xl p-12">
        <div class="w-20 h-20 mx-auto mb-6 rounded-full bg-gradient-to-br from-red-100 to-red-50 dark:from-red-900/30 dark:to-red-800/20 flex items-center justify-center">
            <svg class="w-10 h-10 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        <h1 class="text-2xl font-bold mb-2" style="color:var(--color-text-primary)">Job Not Found</h1>
        <p class="mb-6" style="color:var(--color-text-muted)">This job may have been removed or is no longer available.</p>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 btn-grad px-6 py-3 text-sm font-semibold rounded-2xl text-white shadow-lg shadow-primary-500/20">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Browse Jobs
        </a>
    </div>
</div>
<?php
    require __DIR__ . '/../includes/freelancer_footer.php';
    exit;
}

// Fetch skills for this job
$skills = [];
$ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
$ss->bind_param('i', $job_id);
$ss->execute();
$sr = $ss->get_result();
while ($row = $sr->fetch_assoc()) $skills[] = $row['skill_name'];
$ss->close();

// Check if freelancer already applied
$my_status = null;
$st = $conn->prepare("SELECT status FROM job_applications WHERE job_id = ? AND freelancer_id = ? LIMIT 1");
$st->bind_param('ii', $job_id, $fl_freelancer_id);
$st->execute();
$app = $st->get_result()->fetch_assoc();
$my_status = $app ? $app['status'] : null;
$st->close();

// Check if job is already assigned
$is_assigned = false;
$st = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ?");
$st->bind_param('i', $job_id);
$st->execute();
$is_assigned = (int) $st->get_result()->fetch_assoc()['cnt'] > 0;
$st->close();

// Total proposals
$proposal_count = 0;
$st = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_applications WHERE job_id = ?");
$st->bind_param('i', $job_id);
$st->execute();
$proposal_count = (int) $st->get_result()->fetch_assoc()['cnt'];
$st->close();

// Similar jobs (same category, exclude current)
$similar = [];
$st = $conn->prepare("
    SELECT j.id, j.title, j.budget, j.created_at, j.experience_level, j.duration,
           c.company_name, c.logo_image
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE j.category = ? AND j.id != ? AND j.status = 'approved'
    ORDER BY j.created_at DESC
    LIMIT 4
");
$st->bind_param('si', $job['category'], $job_id);
$st->execute();
$sr = $st->get_result();
while ($row = $sr->fetch_assoc()) {
    $ssk = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ? LIMIT 3');
    $ssk->bind_param('i', $row['id']);
    $ssk->execute();
    $row['skills'] = [];
    while ($sk = $ssk->get_result()->fetch_assoc()) $row['skills'][] = $sk['skill_name'];
    $ssk->close();
    $similar[] = $row;
}
$st->close();

$job_type = $job['duration'] ?? 'Full Time';
$is_urgent = $job['deadline'] && strtotime($job['deadline']) < strtotime('+7 days');

require __DIR__ . '/../includes/freelancer_layout.php';
?>

<style>
.view-hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #312e81 0%, #4f46e5 35%, #7c3aed 65%, #a855f7 100%);
    border-radius: 24px;
    padding: 2.5rem 2rem;
}
.view-hero::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at 20% 50%, rgba(255,255,255,0.12) 0%, transparent 60%);
    pointer-events: none;
}
.view-hero::after {
    content: '';
    position: absolute; top: -60px; right: -30px;
    width: 250px; height: 250px; border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    pointer-events: none;
}
.skill-pill {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.35rem 0.85rem; border-radius: 9999px;
    font-size: 0.8125rem; font-weight: 500;
    background: rgba(99,102,241,0.08); color: #6366f1;
    border: 1px solid rgba(99,102,241,0.15);
    transition: all 0.2s ease;
}
.skill-pill:hover {
    background: rgba(99,102,241,0.15);
    transform: translateY(-1px);
}
.info-row {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 0.625rem 0;
}
.info-row:not(:last-child) {
    border-bottom: 1px solid var(--color-border);
}
.info-icon {
    flex-shrink: 0; width: 2.25rem; height: 2.25rem;
    border-radius: 12px; display: flex; align-items: center; justify-content: center;
}
.similar-card {
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    border-radius: 16px;
    background: var(--color-card);
    border: 1px solid var(--color-border);
}
.similar-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 40px rgba(79,70,229,0.12);
    border-color: rgba(99,102,241,0.2);
}
</style>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <!-- Back button -->
    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-2 text-sm font-medium mb-6 px-4 py-2 rounded-xl transition-all hover:bg-gray-100 dark:hover:bg-gray-800" style="color:var(--color-text-secondary)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Browse Jobs
    </a>

    <!-- Hero Section -->
    <div class="view-hero mb-8">
        <div class="relative z-10">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 flex-wrap mb-3">
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-white bg-white/20 backdrop-blur-sm">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <?= e($job['category'] ?: 'General') ?>
                        </span>
                        <?php if ($job['experience_level']): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-white bg-white/20 backdrop-blur-sm">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                <?= e(ucfirst($job['experience_level'])) ?>
                            </span>
                        <?php endif; ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-white bg-white/20 backdrop-blur-sm">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Remote
                        </span>
                        <?php if ($is_urgent): ?>
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold text-white bg-red-500/40 backdrop-blur-sm animate-pulse">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Urgent
                            </span>
                        <?php endif; ?>
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-white mb-3"><?= e($job['title']) ?></h1>
                    <div class="flex items-center gap-3 flex-wrap text-white/80 text-sm">
                        <span class="flex items-center gap-1.5">
                            <?php if ($job['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-6 h-6 rounded-lg object-contain bg-white/20">
                            <?php else: ?>
                                <div class="w-6 h-6 rounded-lg bg-white/20 flex items-center justify-center text-white text-[10px] font-bold"><?= strtoupper(mb_substr($job['company_name'] ?? 'C', 0, 1)) ?></div>
                            <?php endif; ?>
                            <?= e($job['company_name']) ?>
                        </span>
                        <span class="w-1 h-1 rounded-full bg-white/40"></span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <?= e($job['company_location'] ?: 'Remote') ?>
                        </span>
                        <span class="w-1 h-1 rounded-full bg-white/40"></span>
                        <span class="flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <?= $job['deadline'] ? 'Deadline: ' . date('M j, Y', strtotime($job['deadline'])) : 'No deadline' ?>
                        </span>
                    </div>
                </div>
                <div class="text-right flex-shrink-0">
                    <div class="text-3xl sm:text-4xl font-extrabold text-white">$<?= number_format((float) $job['budget'], 2) ?></div>
                    <div class="text-white/70 text-sm mt-1">Budget</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Job Description -->
            <div class="glass rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/></svg>
                    Job Description
                </h2>
                <div class="prose prose-sm max-w-none" style="color:var(--color-text-secondary); line-height: 1.8;">
                    <?= nl2br(e($job['description'] ?: 'No description provided.')) ?>
                </div>

                <?php if ($job['attachment']): ?>
                <div class="mt-6 pt-6 border-t" style="border-color:var(--color-border)">
                    <h3 class="text-sm font-semibold mb-3 flex items-center gap-2" style="color:var(--color-text-primary)">
                        <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                        Attachments
                    </h3>
                    <a href="<?= e(base_url('uploads/' . $job['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-2.5 px-4 py-3 rounded-xl text-sm font-medium transition-all hover:bg-gray-100 dark:hover:bg-gray-800" style="background:rgba(99,102,241,0.06);color:var(--color-text-secondary);border:1px solid var(--color-border)">
                        <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <?= e(basename($job['attachment'])) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Required Skills -->
            <?php if (!empty($skills)): ?>
            <div class="glass rounded-2xl p-6 sm:p-8">
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    Required Skills
                </h2>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($skills as $sk): ?>
                        <span class="skill-pill">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            <?= e($sk) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Similar Jobs -->
            <?php if (!empty($similar)): ?>
            <div>
                <h2 class="text-lg font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Similar Jobs
                </h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach ($similar as $sj): ?>
                    <a href="<?= e(base_url('freelancer/view_job.php?id=' . $sj['id'])) ?>" class="similar-card p-5 block">
                        <div class="flex items-center gap-3 mb-3">
                            <?php if ($sj['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/' . $sj['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border" style="border-color:var(--color-border)">
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-indigo-600 font-bold" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($sj['company_name'] ?? 'C', 0, 1)) ?></div>
                            <?php endif; ?>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($sj['title']) ?></p>
                                <p class="text-xs" style="color:var(--color-text-muted)"><?= e($sj['company_name']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-sm font-bold text-primary-600">$<?= number_format((float) $sj['budget'], 2) ?></span>
                            <span class="text-xs" style="color:var(--color-text-placeholder)"><?= e(ucfirst($sj['experience_level'])) ?></span>
                        </div>
                        <?php if (!empty($sj['skills'])): ?>
                            <div class="flex flex-wrap gap-1 mt-3">
                                <?php foreach ($sj['skills'] as $sk): ?>
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-medium" style="background:rgba(99,102,241,0.06);color:#6366f1"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Action Card -->
            <div class="glass rounded-2xl p-6 sticky top-24">
                <?php if ($is_assigned): ?>
                    <div class="text-center py-4">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                            <svg class="w-7 h-7 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        </div>
                        <p class="font-semibold" style="color:var(--color-text-primary)">Position Filled</p>
                        <p class="text-xs mt-1" style="color:var(--color-text-muted)">This job has been assigned to a freelancer.</p>
                    </div>
                <?php elseif ($my_status): ?>
                    <div class="text-center py-4">
                        <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
                            <svg class="w-7 h-7 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <p class="font-semibold" style="color:var(--color-text-primary)">Application Submitted</p>
                        <p class="text-xs mt-1" style="color:var(--color-text-muted)">You have applied for this job.</p>
                        <div class="mt-3"><?= status_badge($my_status) ?></div>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="apply">
                        <button type="submit" class="w-full btn-grad px-6 py-3.5 text-sm font-semibold rounded-2xl text-white shadow-lg shadow-primary-500/20 flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Apply Now
                        </button>
                    </form>
                    <p class="text-xs text-center mt-2" style="color:var(--color-text-muted)">Submit your proposal for this job</p>
                <?php endif; ?>

                <div class="mt-5 pt-5 border-t" style="border-color:var(--color-border)">
                    <div class="flex items-center justify-between text-sm">
                        <span style="color:var(--color-text-muted)">Proposals</span>
                        <span class="font-bold flex items-center gap-1.5" style="color:var(--color-text-primary)">
                            <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            <?= $proposal_count ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Job Details Card -->
            <div class="glass rounded-2xl p-6">
                <h3 class="text-sm font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Job Details
                </h3>
                <div class="space-y-1">
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(99,102,241,0.08)">
                            <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Category</p>
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['category'] ?: 'Not specified') ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(16,185,129,0.08)">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Budget</p>
                            <p class="text-sm font-bold text-primary-600">$<?= number_format((float) $job['budget'], 2) ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(245,158,11,0.08)">
                            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Duration / Job Type</p>
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e($job['duration'] ?: 'Not specified') ?></p>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(99,102,241,0.08)">
                            <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Experience Level</p>
                            <p class="text-sm font-medium capitalize" style="color:var(--color-text-primary)"><?= e($job['experience_level'] ?: 'Not specified') ?></p>
                        </div>
                    </div>
                    <?php if ($job['gender_requirement'] !== 'any'): ?>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(236,72,153,0.08)">
                            <svg class="w-4 h-4 text-pink-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Gender Requirement</p>
                            <p class="text-sm font-medium capitalize" style="color:var(--color-text-primary)"><?= e($job['gender_requirement']) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ((int) $job['freelancers_needed'] > 1): ?>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(245,158,11,0.08)">
                            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Freelancers Needed</p>
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= (int) $job['freelancers_needed'] ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(99,102,241,0.08)">
                            <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Posted Date</p>
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($job['created_at'])) ?></p>
                        </div>
                    </div>
                    <?php if ($job['deadline']): ?>
                    <div class="info-row">
                        <div class="info-icon" style="background:rgba(239,68,68,0.08)">
                            <svg class="w-4 h-4 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs" style="color:var(--color-text-muted)">Application Deadline</p>
                            <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($job['deadline'])) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Client Info Card -->
            <div class="glass rounded-2xl p-6">
                <h3 class="text-sm font-bold mb-4 flex items-center gap-2" style="color:var(--color-text-primary)">
                    <svg class="w-4 h-4 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    About the Client
                </h3>
                <div class="flex items-center gap-3 mb-4">
                    <?php
                        $client_img = profile_image_url($job['client_profile_image']);
                    ?>
                    <?php if ($client_img): ?>
                        <img src="<?= e($client_img) ?>" alt="" class="w-12 h-12 rounded-xl object-cover">
                    <?php else: ?>
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-primary-500 to-accent-500 flex items-center justify-center text-white font-bold text-lg">
                            <?= strtoupper(mb_substr($job['client_display_name'] ?? 'C', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="flex items-center gap-1.5">
                            <p class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= e($job['client_display_name']) ?></p>
                            <span class="w-4 h-4 rounded-full bg-blue-500 flex items-center justify-center" title="Verified Client">
                                <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                            </span>
                        </div>
                        <p class="text-xs" style="color:var(--color-text-muted)"><?= e($job['client_username']) ?></p>
                    </div>
                </div>
                <div class="space-y-2 text-xs" style="color:var(--color-text-secondary)">
                    <?php if ($job['company_location']): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <?= e($job['company_location']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['website']): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
                            <a href="<?= e($job['website']) ?>" target="_blank" class="text-primary-600 hover:underline"><?= e($job['website']) ?></a>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['industry']): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            <?= e($job['industry']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($job['company_size']): ?>
                        <div class="flex items-center gap-2">
                            <svg class="w-3.5 h-3.5 flex-shrink-0" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <?= e($job['company_size']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
