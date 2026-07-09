<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', __('error.company_not_found'));
    redirect('index.php');
}

$jobs = [];
$stmt = $conn->prepare('
    SELECT j.id, j.title, j.budget, j.status, j.created_at,
           (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = j.id) AS app_count
    FROM jobs j
    WHERE j.company_id = ?
    ORDER BY j.created_at DESC
');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $jobs[] = $row;
}
$stmt->close();

$page_title = __('company.manage_jobs');
require __DIR__ . '/../includes/header.php';
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= __('company.manage_jobs') ?></h1>
    <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-primary"><?= __('company.post_job_btn') ?></a>
</div>

<?php if (empty($jobs)): ?>
    <div class="card text-center" style="color:var(--color-text-muted)"><?= __('company.no_jobs') ?></div>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left" style="color:var(--color-text-muted)">
                    <th class="py-2 pr-4"><?= __('company.table.title') ?></th>
                    <th class="py-2 pr-4"><?= __('company.table.budget') ?></th>
                    <th class="py-2 pr-4"><?= __('company.table.status') ?></th>
                    <th class="py-2 pr-4"><?= __('company.table.applications') ?></th>
                    <th class="py-2 pr-4"><?= __('company.table.posted') ?></th>
                    <th class="py-2"><?= __('company.table.actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                    <tr class="border-b">
                        <td class="py-3 pr-4 font-medium"><?= e($job['title']) ?></td>
                        <td class="py-3 pr-4">$<?= e(number_format((float) $job['budget'], 2)) ?></td>
                        <td class="py-3 pr-4"><?= status_badge($job['status']) ?></td>
                        <td class="py-3 pr-4"><?= (int) $job['app_count'] ?></td>
                        <td class="py-3 pr-4"><?= e($job['created_at']) ?></td>
                        <td class="py-3">
                            <div class="flex gap-2 flex-wrap">
                                <?php if ($job['status'] !== 'completed'): ?>
                                    <a href="<?= e(base_url('company/edit_job.php?id=' . $job['id'])) ?>" class="text-indigo-600 hover:underline"><?= __('company.edit') ?></a>
                                <?php endif; ?>
                                <a href="<?= e(base_url('company/view_applications.php?id=' . $job['id'])) ?>" class="text-green-600 hover:underline"><?= __('company.applications') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
