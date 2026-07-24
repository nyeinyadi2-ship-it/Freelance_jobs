<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('login.php');
}

$jobs = [];
$stmt = $conn->prepare('
    SELECT j.id, j.title, j.category, j.experience_level, j.budget, j.status, j.created_at,
           j.deadline, j.freelancers_needed, j.visibility, j.attachment,
           (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = j.id) AS app_count
    FROM jobs j
    WHERE j.company_id = ?
    ORDER BY j.created_at DESC
');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Fetch skills for this job
    $skills_stmt = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ? ORDER BY s.skill_name');
    $skills_stmt->bind_param('i', $row['id']);
    $skills_stmt->execute();
    $skills_result = $skills_stmt->get_result();
    $row['skills'] = [];
    while ($sk = $skills_result->fetch_assoc()) {
        $row['skills'][] = $sk['skill_name'];
    }
    $skills_stmt->close();
    $jobs[] = $row;
}
$stmt->close();

$page_title = 'My Jobs';
require __DIR__ . '/../includes/header.php';
?>

<style>
.job-card { border-radius:1rem; padding:1.5rem; transition:all .3s; background:var(--color-card); border:1px solid var(--color-border); box-shadow:0 2px 10px rgba(0,0,0,0.03); }
.job-card:hover { box-shadow:0 8px 30px rgba(99,102,241,0.1); transform:translateY(-2px); }
.skill-tag { display:inline-flex; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:500; background:rgba(99,102,241,0.08); color:#6366f1; }
.remote-badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(16,185,129,0.1); color:#10b981; }
.btn-gradient-sm { background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; font-weight:600; padding:0.5rem 1rem; border-radius:0.5rem; font-size:0.8125rem; transition:all .2s; }
.btn-gradient-sm:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(99,102,241,0.3); }
</style>

<div class="max-w-6xl mx-auto" style="padding-bottom:3rem">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold" style="color:var(--color-text-primary)">My Jobs</h1>
            <p class="mt-1 text-sm" style="color:var(--color-text-muted)">Manage all your posted jobs</p>
        </div>
        <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-gradient-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            Post Job
        </a>
    </div>

    <?php if (empty($jobs)): ?>
        <div class="text-center py-20 rounded-2xl" style="background:var(--color-card);border:1px solid var(--color-border)">
            <svg class="w-20 h-20 mx-auto mb-4 opacity-20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <p class="text-lg font-semibold mb-2" style="color:var(--color-text-secondary)">No jobs posted yet</p>
            <p class="text-sm mb-4" style="color:var(--color-text-muted)">Start by posting your first job to find talented freelancers</p>
            <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-gradient-sm inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Post Your First Job
            </a>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($jobs as $idx => $job): ?>
                <div class="job-card" style="animation-delay:<?= ($idx * 0.05) ?>s">
                    <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-3 mb-2 flex-wrap">
                                <h3 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h3>
                                <?= status_badge($job['status']) ?>
                                <span class="remote-badge">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Remote
                                </span>
                                <?php if ($job['visibility'] === 'private'): ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style="background:rgba(245,158,11,0.1);color:#f59e0b">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        Private
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="flex items-center gap-4 text-sm mb-3 flex-wrap" style="color:var(--color-text-muted)">
                                <span class="font-bold" style="color:#6366f1">$<?= e(number_format((float) $job['budget'], 2)) ?></span>
                                <?php if ($job['category']): ?>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                        <?= e($job['category']) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="capitalize"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></span>
                                <?php if ($job['deadline']): ?>
                                    <span class="flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                                    </span>
                                <?php endif; ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <?= (int) $job['freelancers_needed'] ?> needed
                                </span>
                            </div>

                            <?php if (!empty($job['skills'])): ?>
                                <div class="flex flex-wrap gap-1.5 mb-3">
                                    <?php foreach (array_slice($job['skills'], 0, 6) as $sk): ?>
                                        <span class="skill-tag"><?= e($sk) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($job['skills']) > 6): ?>
                                        <span class="skill-tag">+<?= count($job['skills']) - 6 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <p class="text-xs" style="color:var(--color-text-muted)">
                                Posted <?= e($job['created_at']) ?>
                                <span class="mx-1">·</span>
                                <?= (int) $job['app_count'] ?> application<?= (int) $job['app_count'] !== 1 ? 's' : '' ?>
                                <?php if ($job['attachment']): ?>
                                    <span class="mx-1">·</span>
                                    <span class="flex items-center gap-1 inline-flex">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                        Attachment
                                    </span>
                                <?php endif; ?>
                            </p>
                        </div>

                        <div class="flex gap-2 flex-shrink-0 lg:flex-col">
                            <?php if ($job['status'] !== 'completed'): ?>
                                <a href="<?= e(base_url('company/edit_job.php?id=' . $job['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(99,102,241,0.08);color:#6366f1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Edit
                                </a>
                            <?php endif; ?>
                            <a href="<?= e(base_url('company/view_applications.php?id=' . $job['id'])) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all" style="background:rgba(16,185,129,0.08);color:#10b981">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                Applications (<?= (int) $job['app_count'] ?>)
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
