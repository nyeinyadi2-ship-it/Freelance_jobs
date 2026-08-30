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
    set_flash('error', 'Post not found.');
    redirect('freelancer/dashboard.php');
}

// Fetch existing submission if any
$submission = null;
$stmt = $conn->prepare("SELECT * FROM proposal_project_submissions WHERE proposal_project_id = ? AND freelancer_id = ? ORDER BY submitted_at DESC LIMIT 1");
$stmt->bind_param('ii', $proposal_id, $freelancer_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

$page_title = 'My Trial Task: ' . $proposal['title'];
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
                    <div class="flex flex-col gap-2">
                        <?php 
                        $attachments = explode(',', $proposal['attachment']);
                        foreach($attachments as $att): 
                            $att = trim($att);
                            if(empty($att)) continue;
                            
                            $file_path = __DIR__ . '/../uploads/attachments/' . basename($att);
                            $file_size = file_exists($file_path) ? filesize($file_path) : 0;
                            
                            // Format size beautifully
                            if ($file_size >= 1048576) {
                                $file_size_formatted = number_format($file_size / 1048576, 2) . ' MB';
                            } elseif ($file_size >= 1024) {
                                $file_size_formatted = number_format($file_size / 1024, 2) . ' KB';
                            } else {
                                $file_size_formatted = $file_size . ' bytes';
                            }
                            
                            $file_ext = strtoupper(pathinfo($att, PATHINFO_EXTENSION));
                            $file_name = basename($att);
                        ?>
                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg border border-gray-200 dark:border-gray-600">
                                <div class="flex flex-col">
                                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate max-w-xs md:max-w-md"><?= e($file_name) ?></span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400"><?= e($file_ext) ?> &bull; <?= $file_size_formatted ?></span>
                                </div>
                                <a href="<?= e(base_url('api/download_trial_attachment.php?id=' . $proposal['id'] . '&file=' . urlencode($att))) ?>" class="inline-flex items-center gap-1.5 text-white bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 rounded-lg text-xs font-semibold shadow-sm transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    Download
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <h3 class="text-lg font-bold mt-6 mb-2">Attachment</h3>
                    <p class="text-sm text-gray-500 italic">No attachment files provided.</p>
                <?php endif; ?>
            </div>
            
            <?php if ($proposal['status'] === 'in_progress'): ?>
                <?php
                $can_submit = true;
                if (!empty($proposal['deadline']) && new DateTime($proposal['deadline']) <= new DateTime()) {
                    $can_submit = false;
                }
                ?>
                <?php if ($can_submit): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 border-t-4 border-t-indigo-500">
                    <h3 class="text-xl font-bold mb-4">Submit Your Work</h3>
                    <form action="<?= e(base_url('api/submit_proposal.php')) ?>" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Upload File <span class="text-red-500">*</span></label>
                            <input type="file" name="file" required oninvalid="this.setCustomValidity('Please upload your completed work before submitting the Trial Task.')" oninput="this.setCustomValidity('')" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700">
                        </div>



                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-1">Comments</label>
                            <textarea name="comment" rows="3" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:border-gray-600" placeholder="Any comments for the company?"></textarea>
                        </div>

                        <button type="submit" class="w-full bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-700 hover:to-purple-700 py-3 rounded-xl font-bold shadow-lg text-white text-center inline-block transition-all">Submit Trial Task</button>
                    </form>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-red-200 dark:border-red-800 p-6 border-t-4 border-t-red-500">
                    <h3 class="text-xl font-bold mb-2 text-red-600">Deadline Passed</h3>
                    <p class="text-gray-600 dark:text-gray-400">The deadline for this trial task has passed and you can no longer submit your work.</p>
                </div>
                <?php endif; ?>
            <?php elseif ($submission): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-xl font-bold mb-4">Your Submission</h3>
                    <p class="text-sm text-gray-500 mb-4">Submitted on <?= e($submission['submitted_at']) ?></p>



                    <?php if (!empty($submission['file'])): ?>
                        <div class="mb-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Uploaded File:</p>
                            <a href="<?= e(base_url($submission['file'])) ?>" target="_blank" class="inline-flex items-center gap-2 text-indigo-600 hover:underline bg-indigo-50 dark:bg-gray-700 px-3 py-1.5 rounded-lg text-sm">Download File</a>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($submission['comment'])): ?>
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
                <h3 class="text-lg font-bold mb-4">Trial Task Details</h3>
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

                <?php if ($proposal['status'] === 'assigned'): ?>
                    <hr class="my-6 border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">You have been invited to complete a trial task for this job. Do you accept?</p>
                    <div class="flex flex-col gap-3">
                        <form action="<?= e(base_url('api/respond_proposal.php')) ?>" method="POST">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="proposal_id" value="<?= $proposal['id'] ?>">
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 rounded-xl transition-colors">Accept Trial Task</button>
                        </form>
                        <form action="<?= e(base_url('api/respond_proposal.php')) ?>" method="POST" onsubmit="return confirm('Are you sure you want to decline this trial task?');">
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
