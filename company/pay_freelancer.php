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
            // Deduct from Company Reserved Balance removed
            // The money was already held by the platform at the time of funding.

            // Handle Transaction Slip Upload
            $transaction_slip = null;
            if (isset($_FILES['transaction_slip']) && $_FILES['transaction_slip']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['transaction_slip'];
                $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed_exts)) {
                    throw new Exception("Invalid transaction slip format. Only JPG, PNG, and WEBP are allowed.");
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    throw new Exception("Transaction slip exceeds 5MB limit.");
                }
                
                $slip_filename = uniqid('slip_', true) . '.' . $ext;
                $dest = __DIR__ . '/../uploads/slips/' . $slip_filename;
                
                if (!move_uploaded_file($file['tmp_name'], $dest)) {
                    throw new Exception("Failed to upload transaction slip.");
                }
                $transaction_slip = $slip_filename;
            } else {
                throw new Exception("Please attach the transaction slip before completing the payment.");
            }

            $now = date('Y-m-d H:i:s');

            if ($is_milestone) {
                $stmt = $conn->prepare("UPDATE milestones SET status = 'paid' WHERE id = ? AND status = 'payment_pending'");
                $stmt->bind_param('i', $milestone_id);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    throw new Exception("Milestone is not pending payment or has already been paid.");
                }
                $stmt->close();

                // Find assignment to link payment
                $stmt = $conn->prepare("SELECT id FROM assignments WHERE job_id = ? AND freelancer_id = ?");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $assign = $stmt->get_result()->fetch_assoc();
                $assignment_id_for_payment = $assign ? $assign['id'] : null;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, milestone_id, company_id, freelancer_id, amount, payment_method, transaction_reference, transaction_slip, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?)");
                $stmt->bind_param('iiiidssss', $assignment_id_for_payment, $milestone_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $transaction_slip, $now);
                $stmt->execute();
                $stmt->close();


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
                $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ? AND status = 'payment_pending'");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    throw new Exception("Assignment is not pending payment or has already been paid.");
                }
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, company_id, freelancer_id, amount, payment_method, transaction_reference, transaction_slip, status, paid_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?)");
                $stmt->bind_param('iiidssss', $assignment_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $transaction_slip, $now);
                $stmt->execute();
                $stmt->close();

            }

            // Credit Freelancer Wallet
            if ($fl_user_id > 0 && $amount > 0) {
                $stmt_cred = $conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
                $stmt_cred->bind_param('di', $amount, $fl_user_id);
                $stmt_cred->execute();
                $stmt_cred->close();
            }

            // Insert into wallet_transactions
            $desc = $is_milestone ? "Milestone Payment: " . ($title ?? 'Job') : "Project Payment: " . ($title ?? 'Job');
            $ms_id_for_wt = $is_milestone ? $milestone_id : null;
            $sender_id_for_wt = $is_milestone ? null : $user['user_id'];
            $stmt_wt = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, 'completed', ?)");
            $stmt_wt->bind_param('iiiiisdss', $fl_user_id, $sender_id_for_wt, $fl_user_id, $job_id, $ms_id_for_wt, $desc, $amount, $payment_method, $now);
            $stmt_wt->execute();
            $stmt_wt->close();

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
            <form method="POST" action="" enctype="multipart/form-data">
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

                <div class="mb-8">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Transaction Slip <span class="text-red-500">*</span></label>
                    <div id="slipUploadContainer" class="border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl p-6 text-center cursor-pointer hover:border-indigo-500 transition-colors bg-slate-50 dark:bg-slate-800/50">
                        <input type="file" name="transaction_slip" id="transaction_slip" accept=".jpg,.jpeg,.png,.webp" class="hidden" required>
                        <div id="slipUploadPrompt">
                            <svg class="mx-auto h-10 w-10 text-slate-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z" />
                            </svg>
                            <span class="block text-sm font-semibold text-indigo-600">Attach Transaction Slip</span>
                            <span class="block text-xs text-slate-500 mt-1">JPG, PNG, WEBP (Max 5MB)</span>
                        </div>
                        <div id="slipPreviewContainer" class="hidden">
                            <img id="slipPreview" src="" alt="Slip Preview" class="mx-auto h-32 object-contain rounded border border-slate-200 dark:border-slate-700 mb-3">
                            <span id="slipFileName" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2"></span>
                            <button type="button" id="removeSlipBtn" class="text-xs font-bold text-red-600 hover:text-red-800 bg-red-50 dark:bg-red-900/30 px-3 py-1.5 rounded-lg transition-colors">
                                Remove
                            </button>
                        </div>
                    </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const slipInput = document.getElementById('transaction_slip');
    const uploadContainer = document.getElementById('slipUploadContainer');
    const uploadPrompt = document.getElementById('slipUploadPrompt');
    const previewContainer = document.getElementById('slipPreviewContainer');
    const slipPreview = document.getElementById('slipPreview');
    const slipFileName = document.getElementById('slipFileName');
    const removeBtn = document.getElementById('removeSlipBtn');

    uploadContainer.addEventListener('click', function(e) {
        if (e.target !== removeBtn) {
            slipInput.click();
        }
    });

    slipInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            slipFileName.textContent = file.name;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                slipPreview.src = e.target.result;
                uploadPrompt.classList.add('hidden');
                previewContainer.classList.remove('hidden');
            }
            reader.readAsDataURL(file);
        }
    });

    removeBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        slipInput.value = '';
        slipPreview.src = '';
        slipFileName.textContent = '';
        previewContainer.classList.add('hidden');
        uploadPrompt.classList.remove('hidden');
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
