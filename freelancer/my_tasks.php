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
    $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
    $submission_link = trim($_POST['submission_link'] ?? '');

    if ($assignment_id <= 0 || $submission_link === '') {
        set_flash('error', 'Please provide a submission link.');
    } elseif (!filter_var($submission_link, FILTER_VALIDATE_URL)) {
        set_flash('error', 'Please enter a valid URL.');
    } else {
        $stmt = $conn->prepare("
            UPDATE assignments
            SET submission_link = ?, status = 'submitted'
            WHERE id = ? AND freelancer_id = ? AND status = 'assigned'
        ");
        $stmt->bind_param('sii', $submission_link, $assignment_id, $freelancer_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            set_flash('success', 'Work submitted successfully.');
        } else {
            set_flash('error', 'Could not submit work. Task may already be submitted.');
        }
        $stmt->close();
    }

    redirect('freelancer/my_tasks.php');
}

$tasks = [];
$stmt = $conn->prepare("
    SELECT a.id, a.status, a.submission_link, a.assigned_at, j.title, j.description, j.budget, c.company_name
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

$page_title = 'My Tasks';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">My Tasks</h1>

<?php if (empty($tasks)): ?>
    <div class="card text-center text-gray-500">
        No assigned tasks yet.
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-indigo-600 hover:underline block mt-2">Browse available jobs</a>
    </div>
<?php else: ?>
    <div class="space-y-4">
        <?php foreach ($tasks as $task): ?>
            <div class="card">
                <div class="flex flex-wrap justify-between items-start gap-4 mb-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?= e($task['title']) ?></h2>
                        <p class="text-sm text-gray-500">
                            <?= e($task['company_name']) ?> &middot; Budget: $<?= e(number_format((float) $task['budget'], 2)) ?>
                        </p>
                    </div>
                    <?= status_badge($task['status']) ?>
                </div>

                <p class="text-gray-700 mb-3"><?= nl2br(e($task['description'])) ?></p>
                <p class="text-xs text-gray-400 mb-4">Assigned: <?= e($task['assigned_at']) ?></p>

                <?php if ($task['status'] === 'assigned'): ?>
                    <form method="POST" class="flex flex-wrap gap-2 items-end">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="assignment_id" value="<?= (int) $task['id'] ?>">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Submission Link</label>
                            <input type="url" name="submission_link" required class="form-input" placeholder="https://drive.google.com/...">
                        </div>
                        <button type="submit" class="btn-primary">Submit Work</button>
                    </form>
                <?php elseif ($task['submission_link']): ?>
                    <p class="text-sm">
                        Submitted:
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
