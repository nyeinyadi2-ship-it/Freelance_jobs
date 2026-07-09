<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('freelancer');

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);

if (!$freelancer_id) {
    set_flash('error', __('error.freelancer_not_found'));
    redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);

    if ($job_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'approved'");
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            set_flash('error', __('error.job_not_available'));
        } else {
            $stmt = $conn->prepare('SELECT id FROM assignments WHERE job_id = ?');
            $stmt->bind_param('i', $job_id);
            $stmt->execute();
            $has_assignment = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($has_assignment) {
                set_flash('error', __('error.job_already_assigned'));
            } else {
                $stmt = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $existing = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($existing) {
                    set_flash('error', __('error.already_applied'));
                } else {
                    $stmt = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $job_id, $freelancer_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("SELECT j.title, c.user_id FROM jobs j JOIN companies c ON j.company_id = c.id WHERE j.id = ?");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $job_info = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($job_info) {
                        $msg = $user['username'] . " applied for your job \"{$job_info['title']}\".";
                        create_notification($conn, (int) $job_info['user_id'], 'new_application', $msg, 'company/view_applications.php?id=' . $job_id);
                    }

                    set_flash('success', __('success.application_submitted'));
                }
            }
        }
    }

    redirect('freelancer/browse_jobs.php' . (!empty($_GET['q']) ? '?q=' . urlencode($_GET['q']) : ''));
}

$search = trim($_GET['q'] ?? '');
$jobs = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name, c.logo_image,
               (SELECT ja.status FROM job_applications ja WHERE ja.job_id = j.id AND ja.freelancer_id = ?) AS my_status,
               (SELECT COUNT(*) FROM assignments a WHERE a.job_id = j.id) AS is_assigned
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status = 'approved' AND (j.title LIKE ? OR j.description LIKE ?)
        ORDER BY j.created_at DESC
    ");
    $stmt->bind_param('iss', $freelancer_id, $like, $like);
} else {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name, c.logo_image,
               (SELECT ja.status FROM job_applications ja WHERE ja.job_id = j.id AND ja.freelancer_id = ?) AS my_status,
               (SELECT COUNT(*) FROM assignments a WHERE a.job_id = j.id) AS is_assigned
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status = 'approved'
        ORDER BY j.created_at DESC
    ");
    $stmt->bind_param('i', $freelancer_id);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $jobs[] = $row;
}
$stmt->close();

$page_title = __('freelancer.browse_title');
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold mb-6" style="color:var(--color-text-primary)"><?= __('freelancer.browse_title') ?></h1>

<form method="GET" class="mb-6 flex gap-2">
    <input type="text" name="q" placeholder="<?= e(__('freelancer.search_placeholder')) ?>" class="form-input flex-1" value="<?= e($search) ?>">
    <button type="submit" class="btn-primary"><?= __('freelancer.search') ?></button>
    <?php if ($search !== ''): ?>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-secondary"><?= __('freelancer.clear') ?></a>
    <?php endif; ?>
</form>

<?php if (empty($jobs)): ?>
    <div class="card text-center" style="color:var(--color-text-muted)">
        <?= $search !== '' ? __('freelancer.no_search_results') : __('freelancer.no_jobs') ?>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $job): ?>
            <div class="card">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h2>
                        <p class="text-sm mb-2" style="color:var(--color-text-muted)">
                            <?php if ($job['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="inline-block h-5 w-auto mr-1 align-middle">
                            <?php endif; ?>
                            <?= e($job['company_name']) ?> &middot; <?= __('company.budget') ?>: $<?= e(number_format((float) $job['budget'], 2)) ?>
                        </p>
                        <p style="color:var(--color-text-secondary)"><?= nl2br(e($job['description'])) ?></p>
                        <p class="text-xs mt-2" style="color:var(--color-text-placeholder)"><?= __('freelancer.posted') ?>: <?= e($job['created_at']) ?></p>
                    </div>
                    <div>
                        <?php if ((int) $job['is_assigned'] > 0): ?>
                            <span class="text-sm" style="color:var(--color-text-muted)"><?= __('freelancer.assigned') ?></span>
                        <?php elseif ($job['my_status']): ?>
                            <?= status_badge($job['my_status']) ?>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <button type="submit" class="btn-primary"><?= __('freelancer.apply') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
