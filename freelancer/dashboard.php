<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('freelancer');

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);

if (!$freelancer_id) {
    set_flash('error', 'Freelancer profile not found.');
    redirect('index.php');
}

$pending_apps = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_applications WHERE freelancer_id = ? AND status = 'pending'");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$pending_apps = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$active_tasks = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ? AND status IN ('assigned', 'submitted')");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$active_tasks = (int) $stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$recent_apps = [];
$stmt = $conn->prepare("
    SELECT ja.status, ja.applied_at, j.title, j.budget
    FROM job_applications ja
    JOIN jobs j ON ja.job_id = j.id
    WHERE ja.freelancer_id = ?
    ORDER BY ja.applied_at DESC
    LIMIT 5
");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $recent_apps[] = $row;
}
$stmt->close();

$page_title = 'Freelancer Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">Freelancer Dashboard</h1>

<div class="grid md:grid-cols-2 gap-4 mb-8">
    <div class="card">
        <p class="text-sm text-gray-500">Pending Applications</p>
        <p class="text-3xl font-bold text-yellow-600"><?= $pending_apps ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Active Tasks</p>
        <p class="text-3xl font-bold text-indigo-600"><?= $active_tasks ?></p>
    </div>
</div>

<div class="card mb-6">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-lg font-semibold">Recent Applications</h2>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-indigo-600 hover:underline text-sm">Browse jobs</a>
    </div>

    <?php if (empty($recent_apps)): ?>
        <p class="text-gray-500">No applications yet. Start browsing jobs!</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-gray-500">
                        <th class="py-2">Job</th>
                        <th class="py-2">Budget</th>
                        <th class="py-2">Status</th>
                        <th class="py-2">Applied</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_apps as $app): ?>
                        <tr class="border-b">
                            <td class="py-2"><?= e($app['title']) ?></td>
                            <td class="py-2">$<?= e(number_format((float) $app['budget'], 2)) ?></td>
                            <td class="py-2"><?= status_badge($app['status']) ?></td>
                            <td class="py-2"><?= e($app['applied_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="btn-primary">View My Tasks</a>

<?php require __DIR__ . '/../includes/footer.php'; ?>
