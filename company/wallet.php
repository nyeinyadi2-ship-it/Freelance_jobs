<?php
$page_title = 'Company Wallet';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_login();

if (($_SESSION['role'] ?? '') !== 'company') {
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="max-w-4xl mx-auto mt-10"><div class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-6 py-4 rounded-xl shadow-sm text-center font-medium">You do not have permission to access this page.</div></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_funds') {
    $amount = (float) ($_POST['amount'] ?? 0);
    
    if ($amount <= 0) {
        set_flash('error', 'Amount must be greater than zero.');
    } else {
        try {
            $conn->begin_transaction();
            // Add to available balance
            $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
            $stmt->bind_param('di', $amount, $user['user_id']);
            $stmt->execute();
            $stmt->close();

            // Insert into wallet_transactions as completed deposit
            $stmt2 = $conn->prepare("INSERT INTO wallet_transactions (user_id, sender_id, receiver_id, amount, type, payment_method, status, description) VALUES (?, ?, ?, ?, 'deposit', 'platform_fund', 'completed', 'Deposit to Company Fund')");
            $stmt2->bind_param('iiid', $user['user_id'], $user['user_id'], $user['user_id'], $amount);
            $stmt2->execute();
            $stmt2->close();

            $conn->commit();
            set_flash('success', "Successfully added " . number_format($amount, 2) . " MMK to your Company Fund.");
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', 'Failed to add funds: ' . $e->getMessage());
        }
    }
    redirect('company/wallet.php');
}

// Fetch current balance
$stmt = $conn->prepare("SELECT available_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$total_balance = max(0, (float)($row['available_balance'] ?? 0));

// Fetch transaction history
$transactions = [];
$stmt = $conn->prepare("
    SELECT wt.*, 
           COALESCE(fl.full_name, u_rec.username) as freelancer_name, 
           j.title as job_title, 
           m.title as ms_title,
           p.id as payment_id, 
           p.transaction_slip
    FROM wallet_transactions wt
    LEFT JOIN users u_rec ON wt.receiver_id = u_rec.id
    LEFT JOIN freelancers fl ON fl.user_id = wt.receiver_id
    LEFT JOIN jobs j ON wt.job_id = j.id
    LEFT JOIN milestones m ON wt.milestone_id = m.id
    LEFT JOIN payments p ON (p.milestone_id = wt.milestone_id AND wt.milestone_id IS NOT NULL AND p.company_id = wt.sender_id) OR (p.assignment_id IS NOT NULL AND p.company_id = wt.sender_id AND p.freelancer_id = fl.id AND ABS(TIMESTAMPDIFF(SECOND, p.paid_at, wt.created_at)) < 5) OR (p.paid_at = wt.created_at AND p.amount = wt.amount)
    WHERE (wt.user_id = ? OR wt.sender_id = ?)
      AND wt.type NOT IN ('funding', 'escrow_hold', 'escrow_refund')
    ORDER BY wt.created_at DESC
");
$stmt->bind_param('ii', $user['user_id'], $user['user_id']);
$stmt->execute();
$res = $stmt->get_result();
while ($t = $res->fetch_assoc()) {
    $transactions[] = $t;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-2">Company Wallet</h1>
    <p class="text-gray-600 dark:text-gray-400">Manage your Company Fund and view transaction history.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Left Column: Balance & Actions -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-1 h-fit">
        <h2 class="text-lg font-semibold mb-4">Fund Overview</h2>
        
        <div class="space-y-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Fund</p>
                <div class="text-3xl font-bold text-slate-800 dark:text-slate-100 mt-1">
                    <?= number_format($total_balance, 2) ?> MMK
                </div>
            </div>
        </div>
        
        <button type="button" onclick="document.getElementById('addFundsModal').classList.remove('hidden')" class="mt-6 w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition shadow-lg shadow-indigo-600/30 flex items-center justify-center gap-2">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            Add Fund
        </button>
    </div>

    <!-- Right Column: Transaction History -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold mb-4">Transaction History</h2>
        
        <?php if (empty($transactions)): ?>
            <div class="text-center py-8 text-gray-500">
                <p>No transactions found.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b dark:border-gray-700 text-gray-500">
                            <th class="py-3 px-4 font-medium">Date</th>
                            <th class="py-3 px-4 font-medium">Type</th>
                            <th class="py-3 px-4 font-medium">Freelancer</th>
                            <th class="py-3 px-4 font-medium">Project</th>
                            <th class="py-3 px-4 font-medium">Amount</th>
                            <th class="py-3 px-4 font-medium">Status</th>
                            <th class="py-3 px-4 font-medium">Slip</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                        <?php foreach ($transactions as $t): 
                            $is_deposit = ($t['type'] === 'deposit');
                            $is_debit = in_array($t['type'], ['payment', 'milestone_payment']);
                            
                            $label = $is_deposit ? 'Deposit' : ($t['type'] === 'milestone_payment' ? 'Milestone Payment' : 'Project Payment');
                            $sign = $is_deposit ? '+' : '-';
                            $color = $is_deposit ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300';
                            $bg = $is_deposit ? 'bg-emerald-100 dark:bg-emerald-500/20' : 'bg-red-100 dark:bg-red-500/20';
                            $status_str = ucfirst($t['status'] ?? 'completed');
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-3 px-4">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= date('M j, Y', strtotime($t['created_at'])) ?></div>
                                    <div class="text-xs text-gray-500"><?= date('H:i', strtotime($t['created_at'])) ?></div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-semibold <?= $bg ?> <?= $color ?>">
                                        <?= e($label) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-sm text-gray-900 dark:text-gray-100">
                                    <?= $is_debit ? e($t['freelancer_name'] ?? 'Freelancer') : '-' ?>
                                </td>
                                <td class="py-3 px-4 text-sm text-gray-900 dark:text-gray-100">
                                    <?php if ($t['job_title']): ?>
                                        <div class="font-medium"><?= e($t['job_title']) ?></div>
                                        <?php if ($t['ms_title']): ?>
                                            <div class="text-xs text-gray-500 mt-0.5">Milestone: <?= e($t['ms_title']) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="text-sm font-bold <?= $color ?>">
                                        <?= $sign . number_format((float)$t['amount'], 2) ?> MMK
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                                        <?= e($status_str) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if (!empty($t['transaction_slip']) && !empty($t['payment_id'])): ?>
                                        <a href="<?= base_url('api/view_slip.php?payment_id=' . $t['payment_id']) ?>" target="_blank" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                            View Slip
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Funds Modal -->
<div id="addFundsModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto">
    <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col my-auto overflow-hidden">
        
        <!-- Header -->
        <div class="px-6 py-5 border-b border-slate-100 dark:border-slate-800 flex items-start justify-between bg-slate-50 dark:bg-slate-900/50">
            <div>
                <h3 class="text-xl font-bold text-slate-900 dark:text-white">Add Funds</h3>
                <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Add funds to your company account for future projects.</p>
            </div>
            <button type="button" onclick="document.getElementById('addFundsModal').classList.add('hidden')" class="p-2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-800 rounded-full transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="p-6">
            <form method="POST" action="wallet.php">
                <input type="hidden" name="action" value="add_funds">
                
                <div class="mb-5">
                    <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Amount (MMK) <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <span class="text-slate-500 text-sm font-medium">MMK</span>
                        </div>
                        <input type="number" name="amount" step="1" min="1" placeholder="100000" required
                               class="w-full pl-14 pr-4 py-3 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow">
                    </div>
                </div>

                <div class="flex gap-4 mt-6 border-t border-slate-100 dark:border-slate-800 pt-5">
                    <button type="button" onclick="document.getElementById('addFundsModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold border-2 border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Cancel</button>
                    <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-600/30 transition-all">Add Fund</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('addFundsModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
