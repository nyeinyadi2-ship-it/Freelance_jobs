<?php
$page_title = 'Company Wallet';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('company');
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

            // Insert into wallet_transactions as completed
            $stmt2 = $conn->prepare("INSERT INTO wallet_transactions (user_id, amount, type, payment_method, status) VALUES (?, ?, 'deposit', 'platform_fund', 'completed')");
            $stmt2->bind_param('id', $user['user_id'], $amount);
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
$stmt = $conn->prepare("SELECT available_balance, reserved_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$available_balance = (float) ($row['available_balance'] ?? 0);
$reserved_balance = (float) ($row['reserved_balance'] ?? 0);
$total_balance = $available_balance + $reserved_balance;
$stmt->close();

// Fetch transaction history
$transactions = [];
$stmt = $conn->prepare("
    SELECT wt.*, u.username as freelancer_name, u.profile_image, j.title as job_title, m.title as ms_title 
    FROM wallet_transactions wt
    LEFT JOIN users u ON wt.receiver_id = u.id
    LEFT JOIN jobs j ON wt.job_id = j.id
    LEFT JOIN milestones m ON wt.milestone_id = m.id
    WHERE wt.user_id = ? OR wt.sender_id = ? 
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
    <p class="text-gray-600 dark:text-gray-400">Manage your Company Fund for future projects.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <!-- Left Column: Balance & Actions -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-1 h-fit">
        <h2 class="text-lg font-semibold mb-4">Fund Overview</h2>
        
        <div class="space-y-4">
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">Total Fund</p>
                <div class="text-2xl font-bold text-slate-800 dark:text-slate-100">
                    <?= number_format($total_balance, 2) ?> MMK
                </div>
            </div>
            <div class="flex justify-between items-center pb-3 border-b border-gray-200 dark:border-gray-700 mt-4">
                <p class="text-sm text-gray-500 dark:text-gray-400">Available Fund</p>
                <div class="text-lg font-bold text-indigo-600 dark:text-indigo-400">
                    <?= number_format($available_balance, 2) ?> MMK
                </div>
            </div>
            <div class="flex justify-between items-center">
                <p class="text-sm text-gray-500 dark:text-gray-400">Reserved Fund</p>
                <div class="text-lg font-bold text-yellow-600 dark:text-yellow-500">
                    <?= number_format($reserved_balance, 2) ?> MMK
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
                            <th class="py-3 px-4 font-medium">Amount</th>
                            <th class="py-3 px-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                        <?php foreach ($transactions as $t): 
                            $is_payment = ($t['type'] === 'payment' && $t['sender_id'] == $user['user_id']);
                            $is_credit = in_array($t['type'], ['deposit', 'refund']) && !$is_payment;
                            
                            $sign = $is_credit ? '+' : '-';
                            $color = $is_credit ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-800 dark:text-red-300';
                            $bg = $is_credit ? 'bg-emerald-100 dark:bg-emerald-500/20' : 'bg-red-100 dark:bg-red-500/20';
                            
                            $label = ucwords(str_replace('_', ' ', $t['type']));
                            if ($is_payment) $label = 'Payment to Freelancer';
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-3 px-4">
                                    <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= date('M j, Y', strtotime($t['created_at'])) ?></div>
                                    <div class="text-xs text-gray-500"><?= date('H:i', strtotime($t['created_at'])) ?></div>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium <?= $bg ?> <?= $color ?>">
                                        <?= e($label) ?>
                                    </span>
                                    <?php if ($is_payment): ?>
                                        <div class="mt-1">
                                            <span class="text-sm text-gray-600 dark:text-gray-300">To: <span class="font-medium text-gray-900 dark:text-white"><?= e($t['freelancer_name'] ?? 'Unknown') ?></span></span>
                                        </div>
                                    <?php elseif ($t['type'] === 'deposit'): ?>
                                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">Add Fund</div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-gray-900 dark:text-gray-100">
                                    <?= $sign ?><?= number_format($t['amount'], 2) ?> MMK
                                </td>
                                <td class="py-3 px-4">
                                    <?php if($t['status'] === 'pending'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 capitalize">Pending</span>
                                    <?php elseif($t['status'] === 'completed'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 capitalize">Completed</span>
                                    <?php elseif($t['status'] === 'rejected' || $t['status'] === 'failed'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 capitalize"><?= e($t['status']) ?></span>
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
    // Close modal when clicking outside
    document.getElementById('addFundsModal').addEventListener('click', function(e) {
        if (e.target === this) this.classList.add('hidden');
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
