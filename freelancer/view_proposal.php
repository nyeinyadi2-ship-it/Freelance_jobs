<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('freelancer');

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);
$proposal_id = (int) ($_GET['id'] ?? 0);

if (!$freelancer_id || $proposal_id <= 0) {
    redirect('freelancer/dashboard.php');
}

$stmt = $conn->prepare("
    SELECT p.*, j.title AS job_title, c.company_name, c.logo_image
    FROM proposal_projects p
    JOIN jobs j ON p.job_id = j.id
    JOIN companies c ON p.company_id = c.id
    WHERE p.id = ? AND p.freelancer_id = ?
");
$stmt->bind_param('ii', $proposal_id, $freelancer_id);
$stmt->execute();
$proposal = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proposal) {
    set_flash('error', 'Proposal project not found.');
    redirect('freelancer/dashboard.php');
}

// Fetch existing submission if any
$submission = null;
if (in_array($proposal['status'], ['submitted', 'reviewed', 'hired'])) {
    $stmt = $conn->prepare("SELECT * FROM proposal_project_submissions WHERE proposal_project_id = ? AND freelancer_id = ?");
    $stmt->bind_param('ii', $proposal_id, $freelancer_id);
    $stmt->execute();
    $submission = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$page_title = 'Test Assignment: ' . $proposal['title'];
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
            <p class="text-gray-500 mt-1">From <?= e($proposal['company_name']) ?> for Job: <?= e($proposal['job_title']) ?></p>
        </div>
        <div>
            <?= status_badge($proposal['status']) ?>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="md:col-span-2 space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold mb-4">Description</h3>
                <div class="prose dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?= e($proposal['description']) ?></div>
                
                <?php if ($proposal['instructions']): ?>
                    <h3 class="text-lg font-bold mt-6 mb-2">Instructions</h3>
                    <div class="prose dark:prose-invert max-w-none text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?= e($proposal['instructions']) ?></div>
                <?php endif; ?>

                <?php if ($proposal['attachment']): ?>
                    <h3 class="text-lg font-bold mt-6 mb-2">Attachment</h3>
                    <a href="<?= e(base_url($proposal['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-2 text-indigo-600 hover:underline bg-indigo-50 dark:bg-gray-700 px-4 py-2 rounded-lg text-sm font-medium">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        Download Attachment
                    </a>
                <?php endif; ?>
            </div>
            
            <?php if ($proposal['status'] === 'accepted'): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 border-t-4 border-t-indigo-500">
                    <h3 class="text-xl font-bold mb-4">Submit Your Work</h3>
                    <form action="<?= e(base_url('api/submit_proposal.php')) ?>" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">GitHub/External Link (Optional)</label>
                            <input type="url" name="github_link" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600" placeholder="https://github.com/...">
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Upload File (Optional)</label>
                            <input type="file" name="file" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700">
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Comments</label>
                            <textarea name="comment" rows="3" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600" placeholder="Any comments for the company?"></textarea>
                        </div>

                        <button type="submit" class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 py-3 rounded-xl font-bold shadow-lg text-white text-center inline-block transition-all">Submit Assignment</button>
                    </form>
                </div>
            <?php elseif ($submission): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-xl font-bold mb-4">Your Submission</h3>
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
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="text-lg font-bold mb-4">Assignment Details</h3>
                <ul class="space-y-4 text-sm">
                    <li>
                        <span class="block text-gray-500 mb-1">Deadline</span>
                        <strong class="text-red-500"><?= e(date('M j, Y', strtotime($proposal['deadline']))) ?></strong>
                    </li>
                    <li>
                        <span class="block text-gray-500 mb-1">Received On</span>
                        <span class="font-medium"><?= e(date('M j, Y', strtotime($proposal['created_at']))) ?></span>
                    </li>
                </ul>

                <?php if ($proposal['status'] === 'pending'): ?>
                    <hr class="my-6 border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">You have been invited to complete a test assignment for this job. Do you accept?</p>
                    <div class="flex flex-col gap-3">
                        <form action="<?= e(base_url('api/respond_proposal.php')) ?>" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 rounded-xl transition-colors">Accept Assignment</button>
                        </form>
                        <form action="<?= e(base_url('api/respond_proposal.php')) ?>" method="POST" onsubmit="return confirm('Are you sure you want to decline this test assignment?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 font-semibold py-2.5 rounded-xl transition-colors">Decline</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
