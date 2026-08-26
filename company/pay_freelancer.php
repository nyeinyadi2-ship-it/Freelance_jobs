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
        WHERE a.id = ? AND j.company_id = ? AND a.status = 'submitted'
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

// Fetch Company Balance for frontend validation
$stmt_check = $conn->prepare("SELECT available_balance FROM users WHERE id = ?");
$stmt_check->bind_param('i', $user['user_id']);
$stmt_check->execute();
$comp_user = $stmt_check->get_result()->fetch_assoc();
$stmt_check->close();
$available_balance = (float) $comp_user['available_balance'];

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $payment_method = $_POST['payment_method'] ?? '';
    $transaction_ref = trim($_POST['transaction_ref'] ?? '');
    $recipient_name = trim($_POST['recipient_name'] ?? '');
    $recipient_phone = trim($_POST['recipient_phone'] ?? '');

    if (!in_array($payment_method, ['kpay', 'wavepay'])) {
        set_flash('error', 'Invalid payment method.');
    } elseif ($recipient_name === '') {
        set_flash('error', 'Recipient Name is required.');
    } elseif ($recipient_phone === '') {
        set_flash('error', 'Recipient Phone Number is required.');
    } elseif ($transaction_ref === '') {
        set_flash('error', 'Transaction ID is required.');
    } else {
        $conn->begin_transaction();
        try {
            if (!$is_milestone) {
                // Check Company Balance for non-milestones
                $stmt_check = $conn->prepare("SELECT available_balance FROM users WHERE id = ?");
                $stmt_check->bind_param('i', $user['user_id']);
                $stmt_check->execute();
                $comp_user = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                
                if ($comp_user['available_balance'] < $amount) {
                    throw new Exception("Insufficient Total Fund. Please add funds to continue the payment.");
                }

                // Deduct from Company Balance
                $stmt_deduct = $conn->prepare("UPDATE users SET available_balance = available_balance - ? WHERE id = ?");
                $stmt_deduct->bind_param('di', $amount, $user['user_id']);
                $stmt_deduct->execute();
                $stmt_deduct->close();
            }

            // Handle Transaction Slip Upload
            $transaction_slip = null;
            if (isset($_FILES['transaction_slip']) && $_FILES['transaction_slip']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['transaction_slip'];
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if (!in_array($ext, $allowed_exts)) {
                    throw new Exception("Invalid transaction slip format. Only JPG, PNG, and PDF are allowed.");
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
                throw new Exception("Please upload the payment slip.");
            }

            $now = date('Y-m-d H:i:s');

            if ($is_milestone) {
                $stmt = $conn->prepare("UPDATE milestones SET status = 'paid' WHERE id = ? AND status = 'payment_pending'");
                $stmt->bind_param('i', $milestone_id);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    throw new Exception("Milestone is not approved for payment or has already been paid.");
                }
                $stmt->close();

                // Find assignment to link payment
                $stmt = $conn->prepare("SELECT id FROM assignments WHERE job_id = ? AND freelancer_id = ?");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $assign = $stmt->get_result()->fetch_assoc();
                $assignment_id_for_payment = $assign ? $assign['id'] : null;
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, milestone_id, company_id, freelancer_id, amount, payment_method, transaction_reference, transaction_slip, status, paid_at, recipient_name, recipient_phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?)");
                $stmt->bind_param('iiiidssssss', $assignment_id_for_payment, $milestone_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $transaction_slip, $now, $recipient_name, $recipient_phone);
                $stmt->execute();
                $payment_id = $conn->insert_id;
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
                $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ? AND status = 'submitted'");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                if ($stmt->affected_rows === 0) {
                    $stmt->close();
                    throw new Exception("Assignment is not ready for payment or has already been paid.");
                }
                $stmt->close();

                // Also approve the submission
                $stmt = $conn->prepare("UPDATE submissions SET status = 'approved' WHERE assignment_id = ? AND status = 'pending'");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, company_id, freelancer_id, amount, payment_method, transaction_reference, transaction_slip, status, paid_at, recipient_name, recipient_phone) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?, ?)");
                $stmt->bind_param('iiidssssss', $assignment_id, $company_id, $freelancer_id, $amount, $payment_method, $transaction_ref, $transaction_slip, $now, $recipient_name, $recipient_phone);
                $stmt->execute();
                $payment_id = $conn->insert_id;
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
            $sender_id_for_wt = $user['user_id'];
            $stmt_wt = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, job_id, milestone_id, description, amount, type, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment', ?, 'completed', ?)");
            $stmt_wt->bind_param('iiiiisdss', $fl_user_id, $sender_id_for_wt, $fl_user_id, $job_id, $ms_id_for_wt, $desc, $amount, $payment_method, $now);
            $stmt_wt->execute();
            $stmt_wt->close();

            // Check if job is fully completed
            $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM assignments WHERE job_id = jobs.id AND status = 'completed') as done FROM jobs WHERE id = ?");
            $stmt->bind_param('i', $job_id);
            $stmt->execute();
            $j_prog = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($j_prog && (int)$j_prog['done'] >= 1) {
                $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $stmt->close();
            }

            $conn->commit();
            create_notification($conn, (int) $fl_user_id, 'payment_released', "Transferred payment of $" . number_format($amount, 2) . " for \"{$title}\" via {$payment_method}.", 'freelancer/view_payment.php?id=' . $payment_id, $user_id);
            
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

        <div class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800 rounded-xl p-6 mb-8 flex items-center justify-between">
            <div>
                <p class="text-sm text-indigo-600 dark:text-indigo-400 font-semibold uppercase tracking-wider mb-1">Paying for</p>
                <h2 class="text-lg font-bold text-slate-800 dark:text-white"><?= e($title) ?></h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Freelancer: <span class="font-semibold text-slate-800 dark:text-slate-200"><?= e($fl_name) ?></span></p>
            </div>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" id="paymentForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <?php if ($is_milestone): ?>
                <input type="hidden" name="milestone_id" value="<?= $milestone_id ?>">
            <?php else: ?>
                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
            <?php endif; ?>

            <div class="mb-8">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-4 uppercase tracking-wider border-b border-slate-100 dark:border-slate-700 pb-2">Payment Details</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Payment Method <span class="text-red-500">*</span></label>
                        <div class="flex gap-4">
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="payment_method" value="kpay" required class="peer hidden">
                                <div class="flex items-center justify-center p-3 border-2 border-slate-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-900/30 transition-all text-center hover:bg-slate-50 dark:hover:bg-slate-700">
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-300 peer-checked:text-indigo-600 dark:peer-checked:text-indigo-400">KPay</span>
                                </div>
                            </label>
                            <label class="flex-1 cursor-pointer">
                                <input type="radio" name="payment_method" value="wavepay" required class="peer hidden">
                                <div class="flex items-center justify-center p-3 border-2 border-slate-200 dark:border-slate-600 rounded-xl bg-white dark:bg-slate-800 peer-checked:border-indigo-600 peer-checked:bg-indigo-50 dark:peer-checked:bg-indigo-900/30 transition-all text-center hover:bg-slate-50 dark:hover:bg-slate-700">
                                    <span class="text-sm font-bold text-slate-700 dark:text-slate-300 peer-checked:text-indigo-600 dark:peer-checked:text-indigo-400">WavePay</span>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Amount (MMK) <span class="text-red-500">*</span></label>
                        <input type="text" value="<?= number_format($amount, 2) ?>" readonly class="w-full rounded-xl border-slate-200 dark:border-slate-600 bg-slate-100 dark:bg-slate-700/50 text-emerald-600 dark:text-emerald-400 font-bold text-xl focus:ring-0 focus:border-slate-200 py-3 px-4 cursor-not-allowed text-right">
                        <p class="text-xs text-slate-500 mt-1 text-right">Amount is determined automatically.</p>
                    </div>
                </div>
            </div>

            <div class="mb-8">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-4 uppercase tracking-wider border-b border-slate-100 dark:border-slate-700 pb-2">Account & Transaction Info</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Account Name <span class="text-red-500">*</span></label>
                        <input type="text" name="recipient_name" required placeholder="Name on the payment account" class="w-full rounded-xl border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500 py-3 px-4 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Phone Number <span class="text-red-500">*</span></label>
                        <input type="tel" name="recipient_phone" required placeholder="e.g. 09..." pattern="^09[0-9]{7,9}$" title="Phone number must start with 09 and be 9 to 11 digits long" class="w-full rounded-xl border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500 py-3 px-4 shadow-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Transaction ID <span class="text-red-500">*</span></label>
                        <input type="text" name="transaction_ref" required placeholder="e.g. Transaction ID from app" class="w-full rounded-xl border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500 py-3 px-4 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Payment Note <span class="text-xs font-normal text-slate-400">(Optional)</span></label>
                        <input type="text" name="payment_note" placeholder="Any additional note..." class="w-full rounded-xl border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-900 text-sm focus:ring-indigo-500 focus:border-indigo-500 py-3 px-4 shadow-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Transaction Slip <span class="text-red-500">*</span></label>
                    <div id="slipUploadContainer" class="border-2 border-dashed border-slate-300 dark:border-slate-600 rounded-xl p-8 text-center cursor-pointer hover:border-indigo-500 dark:hover:border-indigo-400 transition-colors bg-slate-50 dark:bg-slate-800/50 group">
                        <input type="file" name="transaction_slip" id="transaction_slip" accept=".jpg,.jpeg,.png,.pdf" class="hidden" required>
                        <div id="slipUploadPrompt">
                            <div class="mx-auto h-12 w-12 rounded-full bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center mb-3 group-hover:bg-indigo-100 dark:group-hover:bg-indigo-900/50 transition-colors">
                                <svg class="h-6 w-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                </svg>
                            </div>
                            <span class="block text-sm font-bold text-slate-700 dark:text-slate-300">Click to upload your transaction slip</span>
                            <span class="block text-xs text-slate-500 mt-1">JPG, PNG, PDF (Max 5MB)</span>
                        </div>
                        <div id="slipPreviewContainer" class="hidden">
                            <img id="slipPreview" src="" alt="Slip Preview" class="mx-auto h-40 object-contain rounded-lg border border-slate-200 dark:border-slate-700 mb-3 shadow-sm">
                            <span id="slipFileName" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-3"></span>
                            <button type="button" id="removeSlipBtn" class="text-xs font-bold text-red-600 hover:text-red-800 bg-red-50 hover:bg-red-100 dark:bg-red-900/30 dark:hover:bg-red-900/50 px-4 py-2 rounded-lg transition-colors border border-red-100 dark:border-red-800/50">
                                Remove & Reselect
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pt-6 border-t border-slate-200 dark:border-slate-700">
                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <button type="button" onclick="history.back()" class="w-full sm:w-1/3 bg-white hover:bg-slate-50 text-slate-700 font-bold py-3.5 px-6 rounded-xl border border-slate-200 shadow-sm transition-colors dark:bg-slate-800 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-700">
                        Cancel
                    </button>
                    <button type="submit" id="submitPaymentBtn" class="w-full sm:w-2/3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3.5 px-6 rounded-xl transition-colors shadow-lg shadow-indigo-600/20 flex items-center justify-center">
                        Submit Payment
                    </button>
                </div>
            </div>
        </form>
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
        if (e.target !== removeBtn && !removeBtn.contains(e.target)) {
            slipInput.click();
        }
    });

    slipInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const file = this.files[0];
            const sizeKB = (file.size / 1024).toFixed(1);
            slipFileName.textContent = file.name + ' (' + sizeKB + ' KB)';
            
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

    const form = document.getElementById('paymentForm');
    const btn = document.getElementById('submitPaymentBtn');
    
    if (form) {
        form.addEventListener('submit', function(e) {
            const amount = <?= $amount ?>;
            const balance = <?= $available_balance ?>;
            const isMilestone = <?= $is_milestone ? 'true' : 'false' ?>;
            
            if (!isMilestone && (balance <= 0 || balance < amount)) {
                e.preventDefault();
                alert("Insufficient Total Fund. Please add funds to continue the payment.");
                return false;
            }

            if (!slipInput.files || slipInput.files.length === 0) {
                e.preventDefault();
                alert('Please upload the payment slip.');
                return false;
            }
            if (btn.disabled) {
                e.preventDefault();
                return false;
            }
            btn.disabled = true;
            btn.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2 inline-block text-white" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path></svg> Submitting...';
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
