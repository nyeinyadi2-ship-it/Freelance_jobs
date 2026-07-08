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
            $status = 'pending';
            $stmt = $conn->prepare('INSERT INTO jobs (company_id, title, description, budget, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issds', $company_id, $title, $description, $budget, $status);
            $stmt->execute();
            $stmt->close();

            set_flash('success', 'Job posted successfully. It is pending admin approval.');
            redirect('company/manage_jobs.php');
        }
    }
}

$page_title = 'Post Job';
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-lg mx-auto card">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Post a Job</h1>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Job Title</label>
            <input type="text" name="title" required class="form-input" value="<?= e($_POST['title'] ?? '') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
            <textarea name="description" rows="5" required class="form-input"><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Budget ($)</label>
            <input type="number" name="budget" step="0.01" min="0.01" required class="form-input" value="<?= e($_POST['budget'] ?? '') ?>">
        </div>

        <button type="submit" class="btn-primary w-full">Submit for Approval</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
