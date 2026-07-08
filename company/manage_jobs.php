<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
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

$page_title = 'Manage Jobs';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex justify-between items-center mb-6">
    <h1 class="text-2xl font-bold text-gray-900">My Jobs</h1>
    <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-primary">Post Job</a>
</div>

<?php if (empty($jobs)): ?>
    <div class="card text-center text-gray-500">You have not posted any jobs yet.</div>
<?php else: ?>
    <div class="card overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b text-left text-gray-500">
                    <th class="py-2 pr-4">Title</th>
                    <th class="py-2 pr-4">Budget</th>
                    <th class="py-2 pr-4">Status</th>
                    <th class="py-2 pr-4">Applications</th>
                    <th class="py-2 pr-4">Posted</th>
                    <th class="py-2">Actions</th>
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
                                    <a href="<?= e(base_url('company/edit_job.php?id=' . $job['id'])) ?>" class="text-indigo-600 hover:underline">Edit</a>
                                <?php endif; ?>
                                <a href="<?= e(base_url('company/view_applications.php?id=' . $job['id'])) ?>" class="text-green-600 hover:underline">Applications</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
