<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$proposal_id = (int) ($_GET['id'] ?? 0);

if (!$company_id || $proposal_id <= 0) {
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare("
    SELECT p.*, f.full_name, u.profile_image, j.title AS job_title, j.id AS job_id, ja.id AS application_id
    FROM proposal_projects p
    JOIN freelancers f ON p.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    JOIN jobs j ON p.job_id = j.id
    JOIN job_applications ja ON ja.job_id = p.job_id AND ja.freelancer_id = p.freelancer_id
    WHERE p.id = ? AND p.company_id = ?
");
$stmt->bind_param('ii', $proposal_id, $company_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proposal) {
    set_flash('error', 'Post not found.');
    redirect('company/manage_jobs.php');
}

$submission = null;
if (in_array($proposal['status'], ['submitted', 'reviewed', 'hired'])) {
    $stmt = $conn->prepare("SELECT * FROM proposal_project_submissions WHERE proposal_project_id = ?");
    $stmt->bind_param('i', $proposal_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$page_title = 'Review Trial Task: ' . $proposal['title'];
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto py-8">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <div class="mb-4">
                <button type="button" onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                    Back
                </button>
            </div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white"><?= e($proposal['title']) ?></h1>
            <p class="text-gray-500 mt-1">Freelancer: <?= e($proposal['full_name']) ?></p>
        </div>
        <div>
            <?= status_badge($proposal['status']) ?>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-2 space-y-6">
            <?php if ($submission): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-xl font-bold mb-4">Freelancer Submission</h3>
                    <p class="text-sm text-gray-500 mb-4">Submitted on <?= e($submission['submitted_at']) ?></p>
                    
                    <?php if ($submission['github_link']): ?>
                        <div class="mb-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">External Link:</p>
                            <a href="<?= e($submission['github_link']) ?>" target="_blank" class="text-indigo-600 hover:underline"><?= e($submission['github_link']) ?></a>
                        </div>
                    <?php endif; ?>

                    <?php if ($submission['file']): ?>
                        <div class="mb-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Uploaded File:</p>
                            <a href="<?= e(base_url($submission['file'])) ?>" target="_blank" class="inline-flex items-center gap-2 text-indigo-600 hover:underline bg-indigo-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg text-sm">Download File</a>
                        </div>
                    <?php endif; ?>

                    <?php if ($submission['comment']): ?>
                        <div>
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Comments:</p>
                            <div class="bg-gray-50 dark:bg-gray-900 p-4 rounded-lg text-gray-700 dark:text-gray-300 text-sm whitespace-pre-wrap"><?= e($submission['comment']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($proposal['status'] === 'submitted'): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 border-t-4 border-t-indigo-500">
                        <h3 class="text-xl font-bold mb-4">Evaluation</h3>
                        <div class="flex gap-4">
                            <form action="<?= e(base_url('company/view_applications.php?id=' . $proposal['job_id'])) ?>" method="POST" class="flex-1">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $proposal['job_id'] ?>">
                                <input type="hidden" name="action" value="approve_proposal">
                                <input type="hidden" name="application_id" value="<?= $proposal['application_id'] ?>">
                                <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                                <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-3 rounded-xl transition-colors text-center shadow-lg shadow-green-200 dark:shadow-none">Approve Trial Task</button>
                            </form>
                            <form action="<?= e(base_url('company/view_applications.php?id=' . $proposal['job_id'])) ?>" method="POST" class="flex-1" onsubmit="return confirm('Are you sure you want to reject this applicant?');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $proposal['job_id'] ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="application_id" value="<?= $proposal['application_id'] ?>">
                                <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                                <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl transition-colors text-center shadow-lg shadow-red-200 dark:shadow-none">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <p class="text-gray-500 italic">The freelancer has not submitted the trial task yet.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold mb-4">Trial Task Details</h3>
                <div class="prose dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 text-sm whitespace-pre-wrap mb-4"><?= e($proposal['description']) ?></div>
                
                <ul class="space-y-4 text-sm mt-4">
                    <li>
                        <span class="block text-gray-500 mb-1">Deadline</span>
                        <strong class="text-red-500"><?= e(date('M j, Y', strtotime($proposal['deadline']))) ?></strong>
                    </li>
                    <li>
                        <span class="block text-gray-500 mb-1">Sent On</span>
                        <span class="font-medium"><?= e(date('M j, Y', strtotime($proposal['created_at']))) ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
