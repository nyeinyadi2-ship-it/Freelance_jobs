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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $job_id = (int) ($_POST['job_id'] ?? 0);

    if ($job_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM jobs WHERE id = ? AND status = 'approved'");
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $job = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$job) {
            set_flash('error', 'Job is not available for application.');
        } else {
            $stmt = $conn->prepare('SELECT id FROM assignments WHERE job_id = ?');
            $stmt->bind_param('i', $job_id);
            $stmt->execute();
            $has_assignment = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if ($has_assignment) {
                set_flash('error', 'This job has already been assigned.');
            } else {
                $stmt = $conn->prepare('SELECT id FROM job_applications WHERE job_id = ? AND freelancer_id = ?');
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $existing = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($existing) {
                    set_flash('error', 'You have already applied for this job.');
                } else {
                    $stmt = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
                    $stmt->bind_param('ii', $job_id, $freelancer_id);
                    $stmt->execute();
                    $stmt->close();

                    set_flash('success', 'Application submitted successfully.');
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
        SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name,
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
        SELECT j.id, j.title, j.description, j.budget, j.created_at, c.company_name,
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

$page_title = 'Browse Jobs';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">Browse Jobs</h1>

<form method="GET" class="mb-6 flex gap-2">
    <input type="text" name="q" placeholder="Search by title or description..." class="form-input flex-1" value="<?= e($search) ?>">
    <button type="submit" class="btn-primary">Search</button>
    <?php if ($search !== ''): ?>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-secondary">Clear</a>
    <?php endif; ?>
</form>

<?php if (empty($jobs)): ?>
    <div class="card text-center text-gray-500">
        <?= $search !== '' ? 'No jobs match your search.' : 'No approved jobs available at the moment.' ?>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($jobs as $job): ?>
            <div class="card">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div class="flex-1">
                        <h2 class="text-lg font-semibold text-gray-900"><?= e($job['title']) ?></h2>
                        <p class="text-sm text-gray-500 mb-2">
                            <?= e($job['company_name']) ?> &middot; Budget: $<?= e(number_format((float) $job['budget'], 2)) ?>
                        </p>
                        <p class="text-gray-700"><?= nl2br(e($job['description'])) ?></p>
                        <p class="text-xs text-gray-400 mt-2">Posted: <?= e($job['created_at']) ?></p>
                    </div>
                    <div>
                        <?php if ((int) $job['is_assigned'] > 0): ?>
                            <span class="text-sm text-gray-500">Assigned</span>
                        <?php elseif ($job['my_status']): ?>
                            <?= status_badge($job['my_status']) ?>
                        <?php else: ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <button type="submit" class="btn-primary">Apply</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
