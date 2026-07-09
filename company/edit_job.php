<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', __('error.invalid_job'));
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT id, title, description, budget, status FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', __('error.job_not_found'));
    redirect('company/manage_jobs.php');
}

if ($job['status'] === 'completed') {
    set_flash('error', __('error.completed_job_edit'));
    redirect('company/manage_jobs.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = __('error.invalid_request');
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $budget = (float) ($_POST['budget'] ?? 0);

        if ($title === '' || $description === '') {
            $error = __('error.title_desc_required');
        } elseif ($budget <= 0) {
            $error = __('error.budget_min');
        } else {
            $stmt = $conn->prepare('UPDATE jobs SET title = ?, description = ?, budget = ? WHERE id = ? AND company_id = ?');
            $stmt->bind_param('ssdii', $title, $description, $budget, $job_id, $company_id);
            $stmt->execute();
            $stmt->close();

            set_flash('success', __('success.job_updated'));
            redirect('company/manage_jobs.php');
        }
    }
}

$page_title = __('company.edit_job');
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-lg mx-auto card">
    <h1 class="text-2xl font-bold mb-6" style="color:var(--color-text-primary)"><?= __('company.edit_job') ?></h1>

    <?php if ($error): ?>
        <div style="background:var(--color-flash-error-bg);color:var(--color-flash-error-text);border:1px solid var(--color-flash-error-border);border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.job_title') ?></label>
            <input type="text" name="title" required class="form-input" value="<?= e($_POST['title'] ?? $job['title']) ?>">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.job_description') ?></label>
            <textarea name="description" rows="5" required class="form-input"><?= e($_POST['description'] ?? $job['description']) ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.budget') ?></label>
            <input type="number" name="budget" step="0.01" min="0.01" required class="form-input" value="<?= e($_POST['budget'] ?? $job['budget']) ?>">
        </div>

        <div class="flex gap-2">
            <button type="submit" class="btn-primary"><?= __('company.save_changes') ?></button>
            <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="btn-secondary"><?= __('company.cancel') ?></a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
