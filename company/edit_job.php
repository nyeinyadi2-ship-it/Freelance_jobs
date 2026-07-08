<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', 'Invalid job.');
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT id, title, description, budget, status FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('company/manage_jobs.php');
}

if ($job['status'] === 'completed') {
    set_flash('error', 'Completed jobs cannot be edited.');
    redirect('company/manage_jobs.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $budget = (float) ($_POST['budget'] ?? 0);

        if ($title === '' || $description === '') {
            $error = 'Title and description are required.';
        } elseif ($budget <= 0) {
            $error = 'Budget must be greater than zero.';
        } else {
            $stmt = $conn->prepare('UPDATE jobs SET title = ?, description = ?, budget = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('ssdii', $title, $description, $budget, $job_id, $company_id);
            $stmt->execute();
            $stmt->close();

            set_flash('success', 'Job updated successfully.');
            redirect('company/manage_jobs.php');
        }
    }
}

$page_title = 'Edit Job';
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-lg mx-auto card">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Edit Job</h1>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
            <input type="text" name="title" required class="form-input" value="<?= e($_POST['title'] ?? $job['title']) ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="5" required class="form-input"><?= e($_POST['description'] ?? $job['description']) ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Budget ($)</label>
            <input type="number" name="budget" step="0.01" min="0.01" required class="form-input" value="<?= e($_POST['budget'] ?? $job['budget']) ?>">
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn-primary">Save Changes</button>
            <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="btn-secondary">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
