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
        SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status = 'pending'
        ORDER BY j.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $jobs = [];
}

$page_title = __('admin.approve_title');
require __DIR__ . '/../includes/header.php';
?>

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
    <div class="space-y-4">
        <?php foreach ($jobs as $idx => $job): ?>
            <div class="card admin-fade" style="transition-delay:<?= ($idx * 0.05) ?>s">
                <div class="flex flex-wrap justify-between items-start gap-4">
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
                        <h2 class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                        <p class="text-sm mb-2" style="color:var(--color-text-secondary)"><?= nl2br(e(mb_strimwidth($job['description'] ?? '', 0, 200, '...'))) ?></p>
                        <div class="flex items-center gap-4 text-xs" style="color:var(--color-text-muted)">
                            <span>$<?= e(number_format((float) $job['budget'], 2)) ?></span>
                            <span>·</span>
                            <span><?= e($job['created_at']) ?></span>
                        </div>
                    </div>
                    <div class="flex gap-2 flex-shrink-0">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-primary text-sm">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <?= e(__('admin.approve')) ?>
                            </button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-danger text-sm">
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
