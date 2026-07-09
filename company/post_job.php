<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', __('error.company_not_found'));
    redirect('index.php');
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
            $status = 'pending';
            $stmt = $conn->prepare('INSERT INTO jobs (company_id, title, description, budget, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issds', $company_id, $title, $description, $budget, $status);
            $stmt->execute();
            $job_id = $stmt->insert_id;
            $stmt->close();

            $admin_id = get_admin_user_id($conn);
            if ($admin_id) {
                create_notification($conn, $admin_id, 'new_job', "New job \"{$title}\" posted by " . e($user['username']) . " and needs approval.", "admin/approve_jobs.php");
            }

            set_flash('success', __('success.job_posted'));
            redirect('company/manage_jobs.php');
        }
    }
}

$page_title = __('company.post_job_title');
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-lg mx-auto card">
    <h1 class="text-2xl font-bold mb-6" style="color:var(--color-text-primary)"><?= __('company.post_job_title') ?></h1>

    <?php if ($error): ?>
        <div style="background:var(--color-flash-error-bg);color:var(--color-flash-error-text);border:1px solid var(--color-flash-error-border);border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1rem"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.job_title') ?></label>
            <input type="text" name="title" required class="form-input" value="<?= e($_POST['title'] ?? '') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.job_description') ?></label>
            <textarea name="description" rows="5" required class="form-input"><?= e($_POST['description'] ?? '') ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)"><?= __('company.budget') ?></label>
            <input type="number" name="budget" step="0.01" min="0.01" required class="form-input" value="<?= e($_POST['budget'] ?? '') ?>">
        </div>

        <button type="submit" class="btn-primary w-full"><?= __('company.submit_approval') ?></button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
