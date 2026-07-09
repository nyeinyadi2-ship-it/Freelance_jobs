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
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $submission_link = trim($_POST['submission_link'] ?? '');

    if ($assignment_id <= 0 || $submission_link === '') {
        set_flash('error', __('error.submission_link_required'));
    } elseif (!filter_var($submission_link, FILTER_VALIDATE_URL)) {
        set_flash('error', __('error.invalid_url'));
    } else {
        $stmt = $conn->prepare("
            UPDATE assignments
            SET submission_link = ?, status = 'submitted'
            WHERE id = ? AND freelancer_id = ? AND status = 'assigned'
        ");
        $stmt->bind_param('sii', $submission_link, $assignment_id, $freelancer_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $notify_stmt = $conn->prepare("
                SELECT j.title, c.user_id
                FROM assignments a
                JOIN jobs j ON a.job_id = j.id
                JOIN companies c ON j.company_id = c.id
                WHERE a.id = ?
            ");
            $notify_stmt->bind_param('i', $assignment_id);
            $notify_stmt->execute();
            $notify_info = $notify_stmt->get_result()->fetch_assoc();
            $notify_stmt->close();

            if ($notify_info) {
                $job_stmt = $conn->prepare('SELECT job_id FROM assignments WHERE id = ?');
                $job_stmt->bind_param('i', $assignment_id);
                $job_stmt->execute();
                $job_row = $job_stmt->get_result()->fetch_assoc();
                $job_stmt->close();
                $job_id_link = $job_row ? (int) $job_row['job_id'] : 0;
                create_notification($conn, (int) $notify_info['user_id'], 'work_submitted', $user['username'] . " has submitted work for \"{$notify_info['title']}\".", $job_id_link > 0 ? 'company/view_applications.php?id=' . $job_id_link : null);
            }

            set_flash('success', __('success.work_submitted'));
        } else {
            set_flash('error', __('error.could_not_submit'));
        }
        $stmt->close();
    }

    redirect('freelancer/my_tasks.php');
}

$tasks = [];
$stmt = $conn->prepare("
    SELECT a.id, a.status, a.submission_link, a.assigned_at, j.title, j.description, j.budget, c.company_name, c.logo_image
    FROM assignments a
    JOIN jobs j ON a.job_id = j.id
    JOIN companies c ON j.company_id = c.id
    WHERE a.freelancer_id = ?
    ORDER BY a.assigned_at DESC
");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}
$stmt->close();

$page_title = __('freelancer.my_tasks_title');
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold mb-6" style="color:var(--color-text-primary)"><?= __('freelancer.my_tasks_title') ?></h1>

<?php if (empty($tasks)): ?>
    <div class="card text-center" style="color:var(--color-text-muted)">
        <?= __('freelancer.no_tasks') ?>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-indigo-600 hover:underline block mt-2"><?= __('freelancer.browse_available') ?></a>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($tasks as $task): ?>
            <div class="card">
                <div class="flex flex-wrap justify-between items-start gap-4 mb-3">
                    <div>
                        <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= e($task['title']) ?></h2>
                        <p class="text-sm" style="color:var(--color-text-muted)">
                            <?php if ($task['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="inline-block h-5 w-auto mr-1 align-middle">
                            <?php endif; ?>
                            <?= e($task['company_name']) ?> &middot; <?= __('freelancer.table.budget') ?>: $<?= e(number_format((float) $task['budget'], 2)) ?>
                        </p>
                    </div>
                    <?= status_badge($task['status']) ?>
                </div>

                <p style="color:var(--color-text-secondary)"><?= nl2br(e($task['description'])) ?></p>
                <p class="text-xs mb-4" style="color:var(--color-text-placeholder)"><?= __('freelancer.assigned_at') ?>: <?= e($task['assigned_at']) ?></p>

                <?php if ($task['status'] === 'assigned'): ?>
                    <form method="POST" class="flex flex-wrap gap-2 items-end">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="assignment_id" value="<?= (int) $task['id'] ?>">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('freelancer.submission_link') ?></label>
                            <input type="url" name="submission_link" required class="form-input" placeholder="https://drive.google.com/...">
                        </div>
                        <button type="submit" class="btn-primary"><?= __('freelancer.submit_work') ?></button>
                    </form>
                <?php elseif ($task['submission_link']): ?>
                    <p class="text-sm">
                        <?= __('freelancer.submitted') ?>:
                        <a href="<?= e($task['submission_link']) ?>" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">
                            <?= e($task['submission_link']) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
