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

$stats = ['pending' => 0, 'approved' => 0, 'completed' => 0];
$stmt = $conn->prepare('SELECT status, COUNT(*) AS cnt FROM jobs WHERE company_id = ? GROUP BY status');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $stats[$row['status']] = (int) $row['cnt'];
}
$stmt->close();

$recent_apps = [];
$stmt = $conn->prepare("
    SELECT ja.id, ja.status, ja.applied_at, j.title, f.full_name
    FROM job_applications ja
    JOIN jobs j ON ja.job_id = j.id
    JOIN freelancers f ON ja.freelancer_id = f.id
    WHERE j.company_id = ?
    ORDER BY ja.applied_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_apps[] = $row;
}
$stmt->close();

$page_title = 'Company Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">Company Dashboard</h1>

<div class="grid md:grid-cols-3 gap-4 mb-8">
    <div class="card">
        <p class="text-sm text-gray-500">Pending Jobs</p>
        <p class="text-3xl font-bold text-yellow-600"><?= $stats['pending'] ?? 0 ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Approved Jobs</p>
        <p class="text-3xl font-bold text-green-600"><?= $stats['approved'] ?? 0 ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Completed Jobs</p>
        <p class="text-3xl font-bold text-blue-600"><?= $stats['completed'] ?? 0 ?></p>
    </div>
</div>

<div class="card mb-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Recent Applications</h2>
        <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-indigo-600 hover:underline text-sm">View all jobs</a>
    </div>

    <?php if (empty($recent_apps)): ?>
        <p class="text-gray-500">No applications yet.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-gray-500">
                        <th class="py-2">Job</th>
                        <th class="py-2">Freelancer</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Applied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_apps as $app): ?>
                        <tr class="border-b">
                            <td class="py-2"><?= e($app['title']) ?></td>
                            <td class="py-2"><?= e($app['full_name']) ?></td>
                            <td class="py-2"><?= status_badge($app['status']) ?></td>
                            <td class="py-2"><?= e($app['applied_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-primary">Post a New Job</a>

<?php require __DIR__ . '/../includes/footer.php'; ?>
