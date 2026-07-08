<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($job_id > 0 && in_array($action, ['approve', 'reject'], true)) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('si', $status, $job_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            set_flash('success', 'Job has been ' . $status . '.');
        } else {
            set_flash('error', 'Could not update job status.');
        }
        $stmt->close();
    }

    redirect('admin/approve_jobs.php');
}

$jobs = [];
$stmt = $conn->prepare("
    SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name
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

$page_title = 'Approve Jobs';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">Approve Jobs</h1>

<?php if (empty($jobs)): ?>
    <div class="card text-center text-gray-500">No pending jobs to review.</div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $job): ?>
            <div class="card">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-gray-900"><?= e($job['title']) ?></h2>
                        <p class="text-sm text-gray-500 mb-2">Posted by <?= e($job['company_name']) ?> &middot; Budget: $<?= e(number_format((float) $job['budget'], 2)) ?></p>
                        <p class="text-gray-700"><?= nl2br(e($job['description'])) ?></p>
                        <p class="text-xs text-gray-400 mt-2"><?= e($job['created_at']) ?></p>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-primary">Approve</button>
                        </form>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn-danger">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
