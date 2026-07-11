<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($job_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        try {
            $status = $action === 'approve' ? 'approved' : 'rejected';
            $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ? AND status = 'pending'");
            $stmt->bind_param('si', $status, $job_id);
            $stmt->execute();

            if ($stmt->affected_rows > 0) {
                $stmt2 = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                $stmt2->bind_param('i', $job_id);
                $stmt2->execute();
                $job_info = $stmt2->get_result()->fetch_assoc();
                $stmt2->close();

                if ($job_info) {
                    $msg = $action === 'approve'
                        ? "Your job \"{$job_info['title']}\" has been approved and is now visible to freelancers."
                        : "Your job \"{$job_info['title']}\" has been rejected.";
                    $link = 'company/manage_jobs.php';
                    create_notification($conn, (int) $job_info['user_id'], 'job_' . $status, $msg, $link);
                }

                set_flash('success', __('success.job_status', [':status' => $status]));
            } else {
                set_flash('error', __('error.could_not_update_job'));
            }
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            set_flash('error', __('error.could_not_update_job'));
        }
    }

    redirect('admin/approve_jobs.php');
}

$jobs = [];
try {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.description, j.budget, j.created_at, j.category,
               j.experience_level, j.gender_requirement, j.deadline, j.duration,
               j.freelancers_needed, j.visibility, j.attachment,
               c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status = 'pending'
        ORDER BY j.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        // Fetch skills
        $ss = $conn->prepare('SELECT s.skill_name FROM job_skills js JOIN skills s ON js.skill_id = s.id WHERE js.job_id = ?');
        $ss->bind_param('i', $row['id']); $ss->execute();
        $sr = $ss->get_result();
        $row['skills'] = [];
        while ($sk = $sr->fetch_assoc()) { $row['skills'][] = $sk['skill_name']; }
        $ss->close();
        $jobs[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $jobs = [];
}

$page_title = __('admin.approve_title');
require __DIR__ . '/../includes/header.php';
?>

<style>
.skill-tag { display:inline-flex; padding:0.15rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:500; background:rgba(99,102,241,0.08); color:#6366f1; }
.remote-badge { display:inline-flex; align-items:center; gap:0.25rem; padding:0.2rem 0.5rem; border-radius:9999px; font-size:0.7rem; font-weight:600; background:rgba(16,185,129,0.1); color:#10b981; }
</style>

<!-- Page Header -->
<div class="mb-6 admin-fade">
    <div class="flex items-center gap-3 mb-1">
        <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-sm hover:underline" style="color:var(--color-text-muted)"><?= e(__('admin.dashboard_title')) ?></a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        <span class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e(__('admin.approve_title')) ?></span>
    </div>
    <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e(__('admin.approve_title')) ?></h1>
</div>

<?php if (empty($jobs)): ?>
    <div class="card text-center py-12 admin-fade">
        <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <p class="text-sm" style="color:var(--color-text-muted)"><?= e(__('admin.no_pending')) ?></p>
    </div>
<?php else: ?>
    <p class="text-sm mb-4" style="color:var(--color-text-muted)"><?= count($jobs) ?> pending job<?= count($jobs) !== 1 ? 's' : '' ?> to review</p>
    <div class="space-y-4">
        <?php foreach ($jobs as $idx => $job): ?>
            <div class="card admin-fade" style="transition-delay:<?= ($idx * 0.05) ?>s">
                <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 mb-2">
                            <?php if ($job['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-8 h-8 rounded-lg object-cover">
                            <?php else: ?>
                                <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-xs">
                                    <?= e(_first_char($job['company_name'])) ?>
                                </div>
                            <?php endif; ?>
                            <span class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e(__('admin.posted_by')) ?> <?= e($job['company_name']) ?></span>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap mb-2">
                            <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                            <span class="remote-badge">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Remote
                            </span>
                        </div>
                        <p class="text-sm mb-2" style="color:var(--color-text-secondary)"><?= nl2br(e(mb_strimwidth($job['description'] ?? '', 0, 200, '...'))) ?></p>

                        <!-- Job Meta -->
                        <div class="flex items-center gap-4 text-xs mb-2 flex-wrap" style="color:var(--color-text-muted)">
                            <span class="font-bold" style="color:#6366f1">$<?= e(number_format((float) $job['budget'], 2)) ?></span>
                            <?php if ($job['category']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                                    <?= e($job['category']) ?>
                                </span>
                            <?php endif; ?>
                            <span class="capitalize"><?= e(str_replace('_', ' ', $job['experience_level'])) ?></span>
                            <?php if ($job['gender_requirement'] !== 'any'): ?>
                                <span class="capitalize"><?= e($job['gender_requirement']) ?> only</span>
                            <?php endif; ?>
                            <?php if ($job['deadline']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    <?= e(date('M j, Y', strtotime($job['deadline']))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($job['duration']): ?>
                                <span>Duration: <?= e($job['duration']) ?></span>
                            <?php endif; ?>
                            <span><?= (int)$job['freelancers_needed'] ?> freelancer<?= (int)$job['freelancers_needed'] > 1 ? 's' : '' ?> needed</span>
                            <?php if ($job['visibility'] === 'private'): ?>
                                <span class="font-medium" style="color:#f59e0b">Private</span>
                            <?php endif; ?>
                            <?php if ($job['attachment']): ?>
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <a href="<?= e(attachment_url($job['attachment'])) ?>" target="_blank" style="color:#6366f1">View attachment</a>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($job['skills'])): ?>
                            <div class="flex flex-wrap gap-1.5 mb-2">
                                <?php foreach (array_slice($job['skills'], 0, 8) as $sk): ?>
                                    <span class="skill-tag"><?= e($sk) ?></span>
                                <?php endforeach; ?>
                                <?php if (count($job['skills']) > 8): ?>
                                    <span class="skill-tag">+<?= count($job['skills']) - 8 ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="flex items-center gap-3 text-xs" style="color:var(--color-text-placeholder)">
                            <span><?= e($job['created_at']) ?></span>
                        </div>
                    </div>
                    <div class="flex gap-2 flex-shrink-0 lg:flex-col">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-primary text-sm flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <?= e(__('admin.approve')) ?>
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-danger text-sm flex items-center gap-1.5">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                <?= e(__('admin.reject')) ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    var els = document.querySelectorAll('.admin-fade');
    els.forEach(function(el) { el.classList.add('animate'); });
    var obs = new IntersectionObserver(function(entries) {
        entries.forEach(function(e) { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.1 });
    els.forEach(function(el) { obs.observe(el); });
})();
</script>
