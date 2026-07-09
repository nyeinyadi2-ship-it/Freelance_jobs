<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? $_POST['job_id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', __('error.invalid_job'));
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT id, title, budget, status FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', __('error.job_not_found'));
    redirect('company/manage_jobs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept') {
        $application_id = (int) ($_POST['application_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT ja.id, ja.freelancer_id
            FROM job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $application = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($application) {
            $conn->begin_transaction();
            try {
                $freelancer_id = (int) $application['freelancer_id'];

                $stmt = $conn->prepare("UPDATE job_applications SET status = 'accepted' WHERE id = ?");
                $stmt->bind_param('i', $application_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE job_applications SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
                $stmt->bind_param('ii', $job_id, $application_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, status) VALUES (?, ?, 'assigned')");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $assignment_id = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                $stmt->bind_param('i', $freelancer_id);
                $stmt->execute();
                $fl_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($fl_user) {
                    create_notification($conn, (int) $fl_user['id'], 'hired', "You have been hired for \"{$job['title']}\". Check your tasks.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                set_flash('success', __('success.freelancer_hired'));
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', __('error.could_not_hire'));
            }
        } else {
            set_flash('error', __('error.application_not_found'));
        }
    } elseif ($action === 'reject') {
        $application_id = (int) ($_POST['application_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT ja.freelancer_id
            FROM job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $rejected_app = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            SET ja.status = 'rejected'
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $stmt->close();

        if ($rejected_app) {
            $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
            $stmt->bind_param('i', $rejected_app['freelancer_id']);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($fl_user) {
                create_notification($conn, (int) $fl_user['id'], 'rejected', "Your application for \"{$job['title']}\" has been rejected.", 'freelancer/browse_jobs.php');
            }
        }

        set_flash('success', __('success.application_rejected'));
    } elseif ($action === 'complete_payment') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT a.id, a.status, j.budget
            FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.id = ? AND a.job_id = ? AND j.company_id = ? AND a.status = 'submitted'
        ");
        $stmt->bind_param('iii', $assignment_id, $job_id, $company_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($assignment) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $stmt->close();

                $amount = (float) $assignment['budget'];
                $paid_status = 'paid';
                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, amount, status, paid_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param('ids', $assignment_id, $amount, $paid_status);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $fl_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($fl_user) {
                    create_notification($conn, (int) $fl_user['id'], 'payment', "Payment of \${$amount} for \"{$job['title']}\" has been processed.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                set_flash('success', __('success.work_paid'));
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', __('error.could_not_pay'));
            }
        } else {
            set_flash('error', __('error.assignment_not_ready'));
        }
    }

    redirect('company/view_applications.php?id=' . $job_id);
}

$applications = [];
$stmt = $conn->prepare("
    SELECT ja.id, ja.status, ja.applied_at, f.full_name, f.portfolio_url, u.email, u.profile_image
    FROM job_applications ja
    JOIN freelancers f ON ja.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE ja.job_id = ?
    ORDER BY ja.applied_at DESC
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();

$assignment = null;
$payment = null;
$stmt = $conn->prepare("
    SELECT a.id, a.status, a.submission_link, a.assigned_at, f.full_name
    FROM assignments a
    JOIN freelancers f ON a.freelancer_id = f.id
    WHERE a.job_id = ?
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($assignment) {
    $stmt = $conn->prepare('SELECT id, amount, status, paid_at FROM payments WHERE assignment_id = ?');
    $stmt->bind_param('i', $assignment['id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$page_title = __('company.applications');
require __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-indigo-600 hover:underline text-sm">&larr; <?= __('company.back_jobs') ?></a>
    <h1 class="text-2xl font-bold mt-2" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h1>
    <p style="color:var(--color-text-muted)"><?= __('company.table.budget') ?>: $<?= e(number_format((float) $job['budget'], 2)) ?> &middot; <?= status_badge($job['status']) ?></p>
</div>

<?php if ($assignment): ?>
    <div class="card mb-6">
        <h2 class="text-lg font-semibold mb-3"><?= __('company.assignment') ?></h2>
        <p class="text-sm mb-2" style="color:var(--color-text-secondary)"><?= __('company.assigned_to') ?>: <strong><?= e($assignment['full_name']) ?></strong></p>
        <p class="mb-2">Status: <?= status_badge($assignment['status']) ?></p>

        <?php if ($assignment['submission_link']): ?>
            <p class="mb-2">
                <?= __('company.submission') ?>:
                <a href="<?= e($assignment['submission_link']) ?>" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">
                    <?= e($assignment['submission_link']) ?>
                </a>
            </p>
        <?php endif; ?>

        <?php if ($assignment['status'] === 'submitted'): ?>
            <form method="POST" class="mt-4">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                <input type="hidden" name="action" value="complete_payment">
                <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                <button type="submit" class="btn-primary" onclick="return confirm('<?= __('company.confirm_pay') ?>')">
                    <?= __('company.approve_pay', [':amount' => number_format((float) $job['budget'], 2)]) ?>
                </button>
            </form>
        <?php elseif ($payment): ?>
            <p class="mt-2 text-sm" style="color:var(--color-text-secondary)">
                <?= __('company.payment') ?>: <?= status_badge($payment['status']) ?>
                <?php if ($payment['paid_at']): ?> &middot; <?= __('company.paid_at') ?> <?= e($payment['paid_at']) ?><?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="text-lg font-semibold mb-4"><?= __('company.applications_list') ?></h2>

    <?php if (empty($applications)): ?>
        <p style="color:var(--color-text-muted)"><?= __('company.no_job_applications') ?></p>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($applications as $app): ?>
                <div class="rounded-lg p-4 flex flex-wrap justify-between items-start gap-4" style="border:1px solid var(--color-border)">
                    <div class="flex items-start gap-3">
                        <?php $appImg = profile_image_url($app['profile_image']); ?>
                        <?php if ($appImg): ?>
                            <img src="<?= e($appImg) ?>" alt="" class="w-10 h-10 rounded-full object-cover border flex-shrink-0" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold flex-shrink-0" style="background:rgba(99,102,241,0.1)">
                                <?= e(strtoupper(substr($app['full_name'], 0, 1))) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="font-medium"><?= e($app['full_name']) ?></p>
                            <p class="text-sm" style="color:var(--color-text-muted)"><?= e($app['email']) ?></p>
                            <?php if ($app['portfolio_url']): ?>
                                <a href="<?= e($app['portfolio_url']) ?>" target="_blank" rel="noopener" class="text-sm text-indigo-600 hover:underline"><?= __('company.portfolio') ?></a>
                            <?php endif; ?>
                            <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('company.applied') ?>: <?= e($app['applied_at']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?= status_badge($app['status']) ?>
                        <?php if ($app['status'] === 'pending' && !$assignment): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-primary text-sm"><?= __('company.accept') ?></button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-danger text-sm"><?= __('admin.reject') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
