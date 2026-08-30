<?php
$page_title = 'Payment Details';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('freelancer');
$user = current_user();

$payment_id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
$error_message = null;

if ($payment_id === 0) {
    $error_message = "Payment details not found. The payment ID is missing or invalid.";
}

$stmt = $conn->prepare("SELECT id FROM freelancers WHERE user_id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();
$freelancer_id = $fl['id'] ?? 0;

if ($freelancer_id === 0) {
    redirect('freelancer/dashboard.php');
}

// Mark notification as read if accessed via notification
if (isset($_GET['notif_id']) && is_numeric($_GET['notif_id'])) {
    $notif_id = (int)$_GET['notif_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $notif_id, $user['user_id']);
    $stmt->execute();
    $stmt->close();
}

$payment = null;
if (!$error_message) {
    // Fetch payment details
    // Note: We get job title from assignments or milestones
    $stmt = $conn->prepare("
        SELECT p.*, c.company_name, m.title as ms_title, 
               COALESCE(j1.title, j2.title) as job_title
        FROM payments p
        LEFT JOIN companies c ON p.company_id = c.id
        LEFT JOIN milestones m ON p.milestone_id = m.id
        LEFT JOIN assignments a ON p.assignment_id = a.id
        LEFT JOIN jobs j1 ON m.job_id = j1.id
        LEFT JOIN jobs j2 ON a.job_id = j2.id
        WHERE p.id = ? AND p.freelancer_id = ?
    ");
    $stmt->bind_param('ii', $payment_id, $freelancer_id);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$payment) {
        $error_message = 'Payment details not found or access denied.';
    }
}

$description = '';
$slip_path = null;
$slip_ext = null;

if ($payment) {
    // Prepare Description
    $description = "Project Payment for " . e($payment['job_title'] ?? 'Job');
    if (!empty($payment['ms_title'])) {
        $description = "Milestone Payment: " . e($payment['ms_title']) . " (Project: " . e($payment['job_title'] ?? 'Job') . ")";
    }

    if (!empty($payment['transaction_slip'])) {
        $slip_path = base_url('api/view_slip.php?payment_id=' . $payment['id']);
        $download_path = base_url('api/download_slip.php?payment_id=' . $payment['id']);
        $slip_ext = strtolower(pathinfo($payment['transaction_slip'], PATHINFO_EXTENSION));
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Payment Details</h1>
        <p class="text-slate-600 dark:text-slate-400">View detailed information about this transaction.</p>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 overflow-hidden">
        <?php if ($error_message): ?>
            <div class="p-8 text-center">
                <svg class="mx-auto h-12 w-12 text-red-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 class="text-lg font-medium text-slate-900 dark:text-white"><?= e($error_message) ?></h3>
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">The payment transaction may have been deleted or you do not have permission to view it.</p>
            </div>
        <?php else: ?>
        <div class="p-6 sm:p-8">
            <div class="flex items-center justify-between mb-8 pb-6 border-b border-slate-200 dark:border-slate-700">
                <div>
                    <h2 class="text-3xl font-bold text-slate-900 dark:text-white">
                        $<?= number_format((float)$payment['amount'], 2) ?>
                    </h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Total Amount Paid</p>
                </div>
                <div>
                    <?php if ($payment['status'] === 'paid'): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                            <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            Completed
                        </span>
                    <?php else: ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                            Pending
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase tracking-wider mb-4">Transaction Info</h3>
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Company</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= e($payment['company_name'] ?? 'Unknown Company') ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Project / Job Title</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= e($payment['job_title'] ?? 'N/A') ?></dd>
                        </div>
                        <?php if (!empty($payment['ms_title'])): ?>
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Milestone</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= e($payment['ms_title']) ?></dd>
                        </div>
                        <?php endif; ?>
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Description / Note</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= $description ?></dd>
                        </div>
                    </dl>
                </div>

                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase tracking-wider mb-4">Payment Details</h3>
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Date & Time</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= date('M j, Y - g:i A', strtotime($payment['paid_at'])) ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Payment Method</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-white"><?= e($payment['payment_method']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500 dark:text-slate-400">Transaction ID / Reference Number</dt>
                            <dd class="mt-1 text-sm font-mono text-slate-900 dark:text-white"><?= e($payment['transaction_reference'] ?: 'N/A') ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <?php if ($slip_path): ?>
                <div class="mt-8 border-t border-slate-200 dark:border-slate-700 pt-8">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Transaction Slip</h3>
                        <div class="flex gap-2">
                            <a href="<?= $slip_path ?>" target="_blank" class="inline-flex items-center px-3 py-1.5 border border-slate-300 dark:border-slate-600 shadow-sm text-sm font-medium rounded-lg text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                </svg>
                                View Full Size
                            </a>
                            <a href="<?= $download_path ?>" download class="inline-flex items-center px-3 py-1.5 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <svg class="-ml-0.5 mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                </svg>
                                Download Slip
                            </a>
                        </div>
                    </div>
                    
                    <div class="bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-xl p-4 flex items-center justify-center min-h-[300px]">
                        <?php if ($slip_ext === 'pdf'): ?>
                            <object data="<?= $slip_path ?>" type="application/pdf" width="100%" height="500px" class="rounded-lg">
                                <p>Your browser does not support PDFs. <a href="<?= $download_path ?>" class="text-indigo-600 hover:underline">Download the PDF</a>.</p>
                            </object>
                        <?php elseif (in_array($slip_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])): ?>
                            <img src="<?= $slip_path ?>" alt="Transaction Slip" class="max-w-full max-h-[600px] object-contain rounded-lg shadow-sm" onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'text-center p-6\'><svg class=\'mx-auto h-12 w-12 text-slate-400 mb-2\' fill=\'none\' viewBox=\'0 0 24 24\' stroke=\'currentColor\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z\'/></svg><h3 class=\'text-sm font-medium text-slate-900 dark:text-white\'>Image Unavailable</h3><p class=\'text-xs text-slate-500 mt-1\'>The slip image could not be loaded or is missing.</p></div>';">
                        <?php else: ?>
                            <div class="text-center">
                                <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <h3 class="mt-2 text-sm font-medium text-slate-900 dark:text-white">File Available</h3>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Click download to view this file.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div class="bg-slate-50 dark:bg-slate-800/50 px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3">
            <a href="javascript:history.back()" class="inline-flex justify-center px-4 py-2 border border-slate-300 dark:border-slate-600 shadow-sm text-sm font-medium rounded-lg text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-700 hover:bg-slate-50 dark:hover:bg-slate-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                Back
            </a>
            <a href="transactions.php" class="inline-flex justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                OK
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
