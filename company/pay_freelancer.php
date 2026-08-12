<?php
$page_title = 'Pay Freelancer';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');
$user = current_user();

$assignment_id = (int) ($_GET['assignment_id'] ?? ($_POST['assignment_id'] ?? 0));
$milestone_id = (int) ($_GET['milestone_id'] ?? ($_POST['milestone_id'] ?? 0));

if ($assignment_id === 0 && $milestone_id === 0) {
    set_flash('error', 'Invalid payment request.');
    redirect('company/dashboard.php');
}

$is_milestone = ($milestone_id > 0);
$amount = 0.0;
$freelancer_id = 0;
$job_id = 0;
$title = '';
$company_id = get_company_id($conn, $user['user_id']);

if ($is_milestone) {
    // Fetch Milestone
    $stmt = $conn->prepare("
        SELECT m.id, m.amount, m.status, m.freelancer_id, m.job_id, j.title, m.title as ms_title
        FROM milestones m
        JOIN jobs j ON m.job_id = j.id
        WHERE m.id = ? AND j.company_id = ? AND m.status = 'payment_pending'
    ");
    $stmt->bind_param('ii', $milestone_id, $company_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$item) {
        set_flash('error', 'Milestone not found or not pending payment.');
        redirect('company/dashboard.php');
    }
    $amount = (float) $item['amount'];
    $freelancer_id = (int) $item['freelancer_id'];
    $job_id = (int) $item['job_id'];
    $title = $item['title'] . ' (Milestone: ' . $item['ms_title'] . ')';

    // fallback freelancer_id if not set on milestone (though it should be)
    if ($freelancer_id === 0) {
        $stmt = $conn->prepare("SELECT freelancer_id FROM assignments WHERE job_id = ? LIMIT 1");
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $freelancer_id = (int) ($stmt->get_result()->fetch_assoc()['freelancer_id'] ?? 0);
        $stmt->close();
    }

} else {
    // Fetch Assignment
    $stmt = $conn->prepare("
        SELECT a.id, a.status, a.freelancer_id, a.job_id, j.title, j.budget
        FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        WHERE a.id = ? AND j.company_id = ? AND a.status = 'payment_pending'
    ");
    $stmt->bind_param('ii', $assignment_id, $company_id);
    $stmt->execute();
    $item = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$item) {
        set_flash('error', 'Assignment not found or not pending payment.');
        redirect('company/dashboard.php');
    }
    $amount = (float) $item['budget'];
    $freelancer_id = (int) $item['freelancer_id'];
    $job_id = (int) $item['job_id'];
    $title = $item['title'];
}

// Fetch Freelancer Details & Payment Methods
$stmt = $conn->prepare("SELECT u.id AS user_id, f.full_name FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();
$fl_user_id = $fl['user_id'];
$fl_name = $fl['full_name'];

$payment_methods = [];
$stmt = $conn->prepare("SELECT * FROM freelancer_payment_settings WHERE freelancer_id = ?");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $payment_methods[$row['method']] = $row;
}
$stmt->close();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $payment_method = $_POST['payment_method'] ?? '';
    $transaction_ref = trim($_POST['transaction_ref'] ?? '');

    if (!array_key_exists($payment_method, $payment_methods)) {
        set_flash('error', 'Invalid or missing payment method.');
    } else {
        $conn->begin_transaction();
        try {
            // Deduct from Company Reserved Balance
            $stmt = $conn->prepare("UPDATE users SET reserved_balance = reserved_balance - ? WHERE id = ? AND reserved_balance >= ?");
            $stmt->bind_param('did', $amount, $user['user_id'], $amount);
            $stmt->execute();
            if ($stmt->affected_rows === 0 && $amount > 0) {
                $stmt->close();
                throw new Exception("Error: Reserved funds mismatch. Cannot complete payment.");
            }
            $stmt->close();

            $now = date('Y-m-d H:i:s');

            if ($is_milestone) {
                $stmt = $conn->prepare("UPDATE milestones SET status = 'paid' WHERE id = ?");
                $stmt->bind_param('i', $milestone_id);
                $stmt->execute();
                $stmt->close();

                // Find assignment to link payment
                $stmt = $conn->prepare("SELECT id FROM assignments WHERE job_id = ? AND freelancer_id = ?");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $assign = $stmt->get_result()->fetch_assoc();
                $assignment_id_for_payment = $assign ? $assign['id'] : null;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, milestone_id, company_id, freelancer_id, amount, payment_method, transaction_reference, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?)");
                $stmt->bind_param('iiiidsss', $assignment_id_for_payment, $milestone_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $now);
                $stmt->execute();
                $stmt->close();

                // Insert into wallet_transactions for Unified Transaction History
                $desc = $title;
                $stmt_wt = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, transaction_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, ?, 'completed', ?)");
                $stmt_wt->bind_param('iiiisidsss', $user['user_id'], $user['user_id'], $fl_user_id, $job_id, $milestone_id, $desc, $amount, $payment_method, $transaction_ref, $now);
                $stmt_wt->execute();
                $stmt_wt->close();

                // Check if all milestones are paid
                $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid FROM milestones WHERE job_id = ? AND freelancer_id = ?");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $counts = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($counts && $counts['total'] > 0 && $counts['total'] == $counts['paid']) {
                    $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE job_id = ? AND freelancer_id = ?");
                    $stmt->bind_param('ii', $job_id, $freelancer_id);
                    $stmt->execute();
                    $stmt->close();
                } else if ($assignment_id_for_payment) {
                    $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE id = ?");
                    $stmt->bind_param('i', $assignment_id_for_payment);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, company_id, freelancer_id, amount, payment_method, transaction_reference, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, 'paid', ?)");
                $stmt->bind_param('iiidsss', $assignment_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $now);
                $stmt->execute();
                $stmt->close();

                // Insert into wallet_transactions for Unified Transaction History
                $desc = $title;
                $null_milestone = null;
                $stmt_wt = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, transaction_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, ?, 'completed', ?)");
                $stmt_wt->bind_param('iiiisidsss', $user['user_id'], $user['user_id'], $fl_user_id, $job_id, $null_milestone, $desc, $amount, $payment_method, $transaction_ref, $now);
                $stmt_wt->execute();
                $stmt_wt->close();
            }

            // Check if job is fully completed
            $stmt = $conn->prepare("SELECT freelancers_needed, (SELECT COUNT(*) FROM assignments WHERE job_id = jobs.id AND status = 'completed') as done FROM jobs WHERE id = ?");
            $stmt->bind_param('i', $job_id);
            $stmt->execute();
            $j_prog = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($j_prog && (int)$j_prog['done'] >= (int)$j_prog['freelancers_needed']) {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            create_notification($conn, (int) $fl_user_id, 'payment_released', "Payment of $" . number_format($amount, 2) . " for \"{$title}\" has been transferred via {$payment_method}.", 'freelancer/payment_history.php');
            
            set_flash('success', 'Payment confirmed successfully.');
            redirect('company/manage_jobs.php');

        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto py-8">
    <div class="mb-6">
        <a href="manage_jobs.php" class="text-sm text-indigo-600 hover:text-indigo-800 flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back to Jobs
        </a>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl shadow-sm border border-slate-200 dark:border-slate-700 p-8">
        <h1 class="text-2xl font-bold text-slate-800 dark:text-white mb-2">Complete Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mb-8">Please transfer the funds to the freelancer using one of their provided payment methods below, then confirm the payment.</p>

        <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-6 mb-8 border border-slate-100 dark:border-slate-700">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="block text-slate-500 dark:text-slate-400 mb-1">Project / Task</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e($title) ?></span>
                </div>
                <div>
                    <span class="block text-slate-500 dark:text-slate-400 mb-1">Freelancer</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e($fl_name) ?></span>
                </div>
                <div class="col-span-2 mt-2 pt-4 border-t border-slate-200 dark:border-slate-700 flex justify-between items-center">
                    <span class="text-slate-600 dark:text-slate-400 font-medium">Amount to Pay</span>
                    <span class="text-2xl font-bold text-emerald-600">MMK <?= number_format($amount, 2) ?></span>
                </div>
            </div>
        </div>

        <?php if (empty($payment_methods)): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-4 mb-6">
                <strong>Notice:</strong> The freelancer has not set up any payment methods. Please contact them via Messages to arrange payment.
            </div>
        <?php else: ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <?php if ($is_milestone): ?>
                    <input type="hidden" name="milestone_id" value="<?= $milestone_id ?>">
                <?php else: ?>
                    <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                <?php endif; ?>

                <h3 class="text-lg font-bold text-slate-800 dark:text-white mb-4">Freelancer's Payment Details</h3>
                
                <div class="space-y-4 mb-8">
                    <?php foreach (['kpay', 'wavepay', 'bank_transfer'] as $m): ?>
                        <?php if (isset($payment_methods[$m])): $pm = $payment_methods[$m]; ?>
                            <label class="block relative border rounded-xl p-4 cursor-pointer hover:border-indigo-500 transition-colors bg-white dark:bg-slate-800">
                                <div class="flex items-start gap-4">
                                    <div class="pt-1">
                                        <input type="radio" name="payment_method" value="<?= e($m) ?>" required class="w-4 h-4 text-indigo-600 focus:ring-indigo-500 border-gray-300">
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-2">
                                            <span class="font-bold text-slate-800 dark:text-slate-200">
                                                <?= $m === 'kpay' ? 'KBZPay' : ($m === 'wavepay' ? 'WavePay' : 'Bank Transfer') ?>
                                            </span>
                                            <?php if ($pm['is_default']): ?>
                                                <span class="text-[10px] uppercase font-bold bg-green-100 text-green-700 px-2 py-0.5 rounded">Default</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-sm text-slate-600 dark:text-slate-400 space-y-1">
                                            <?php if ($m === 'bank_transfer'): ?>
                                                <p><strong>Bank:</strong> <?= e($pm['bank_name']) ?></p>
                                            <?php endif; ?>
                                            <p><strong>Account Name:</strong> <?= e($pm['account_name']) ?></p>
                                            <p><strong>Account Number:</strong> <?= e($pm['account_number']) ?></p>
                                        </div>
                                    </div>
                                </div>
                            </label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="mb-8">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Transaction Reference (Optional)</label>
                    <input type="text" name="transaction_ref" placeholder="e.g. Transaction ID from KPay" class="w-full rounded-lg border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                    <button type="submit" onclick="return confirm('Are you sure you have transferred the funds to the freelancer outside the platform? This action cannot be undone.')" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-6 rounded-xl transition-colors shadow-lg shadow-indigo-600/20">
                        I Have Transferred the Payment
                    </button>
                    <p class="text-xs text-center text-slate-500 mt-3">By clicking this button, you confirm that you have successfully transferred the funds to the freelancer's account.</p>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
