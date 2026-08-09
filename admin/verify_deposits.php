<?php
$page_title = 'Verify Deposits';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $transaction_id = (int)($_POST['transaction_id'] ?? 0);
    $action = $_POST['action'];
    
    // Fetch transaction details
    $stmt = $conn->prepare("SELECT user_id, amount, status FROM wallet_transactions WHERE id = ?");
    $stmt->bind_param('i', $transaction_id);
    $stmt->execute();
    $tx = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$tx) {
        set_flash('error', 'Transaction not found.');
    } elseif ($tx['status'] !== 'pending') {
        set_flash('error', 'Transaction is no longer pending.');
    } else {
        $conn->begin_transaction();
        try {
            if ($action === 'approve') {
                // Update transaction
                $stmt = $conn->prepare("UPDATE wallet_transactions SET status = 'completed' WHERE id = ? AND status = 'pending'");
                $stmt->bind_param('i', $transaction_id);
                $stmt->execute();
                
                if ($stmt->affected_rows === 1) {
                    // Update user balance
                    $stmt2 = $conn->prepare("UPDATE users SET demo_funds = demo_funds + ? WHERE id = ?");
                    $stmt2->bind_param('di', $tx['amount'], $tx['user_id']);
                    $stmt2->execute();
                    $stmt2->close();
                    
                    // Notify user
                    $msg = "Your deposit of $" . number_format($tx['amount'], 2) . " has been approved and added to your wallet.";
                    $type = 'deposit_approved';
                    $link = 'company/wallet.php';
                    $stmt3 = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
                    $stmt3->bind_param('isss', $tx['user_id'], $type, $msg, $link);
                    $stmt3->execute();
                    $stmt3->close();
                    
                    set_flash('success', 'Deposit approved and balance updated successfully.');
                } else {
                    throw new Exception("Transaction status changed unexpectedly.");
                }
                $stmt->close();
                
            } elseif ($action === 'reject') {
                $reason = trim($_POST['rejection_reason'] ?? '');
                if (empty($reason)) {
                    throw new Exception("Rejection reason is required.");
                }
                
                // Update transaction
                $stmt = $conn->prepare("UPDATE wallet_transactions SET status = 'rejected', rejection_reason = ? WHERE id = ? AND status = 'pending'");
                $stmt->bind_param('si', $reason, $transaction_id);
                $stmt->execute();
                
                if ($stmt->affected_rows === 1) {
                    // Notify user
                    $msg = "Your deposit of $" . number_format($tx['amount'], 2) . " was rejected. Reason: " . $reason;
                    $type = 'deposit_rejected';
                    $link = 'company/wallet.php';
                    $stmt3 = $conn->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, ?, ?, ?)");
                    $stmt3->bind_param('isss', $tx['user_id'], $type, $msg, $link);
                    $stmt3->execute();
                    $stmt3->close();
                    
                    set_flash('success', 'Deposit rejected successfully.');
                } else {
                    throw new Exception("Transaction status changed unexpectedly.");
                }
                $stmt->close();
            }
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            set_flash('error', $e->getMessage());
        }
    }
    redirect('admin/verify_deposits.php');
}

// Fetch all deposits
$deposits = [];
$query = "
    SELECT wt.*, u.username, c.company_name 
    FROM wallet_transactions wt
    JOIN users u ON wt.user_id = u.id
    LEFT JOIN companies c ON u.id = c.user_id
    WHERE wt.type = 'deposit'
    ORDER BY wt.created_at DESC
";
$res = $conn->query($query);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $deposits[] = $row;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 dark:bg-gray-900 overflow-hidden">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Verify Deposits</h1>
                <p class="text-gray-600 dark:text-gray-400 mt-1">Review and approve simulated company deposit requests.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <?php if (empty($deposits)): ?>
                <div class="py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No deposit requests found.</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">When companies request a deposit, they will appear here.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">
                                <th class="py-3.5 px-4 font-semibold">Deposit Date</th>
                                <th class="py-3.5 px-4 font-semibold">Company</th>
                                <th class="py-3.5 px-4 font-semibold">Amount</th>
                                <th class="py-3.5 px-4 font-semibold">Method & Ref</th>
                                <th class="py-3.5 px-4 font-semibold">Proof</th>
                                <th class="py-3.5 px-4 font-semibold">Status</th>
                                <th class="py-3.5 px-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($deposits as $d): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="py-4 px-4 text-gray-500 dark:text-gray-400">
                                        <div class="text-xs font-mono text-gray-400 dark:text-gray-500 mb-0.5">#<?= $d['id'] ?></div>
                                        <?= date('M j, Y H:i', strtotime($d['created_at'])) ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <div class="font-medium text-gray-900 dark:text-white"><?= e($d['company_name'] ?: $d['username']) ?></div>
                                    </td>
                                    <td class="py-4 px-4 font-bold text-green-600 dark:text-green-400">
                                        $<?= number_format($d['amount'], 2) ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <div class="font-medium text-gray-900 dark:text-white capitalize">
                                            <?= e(str_replace('_', ' ', $d['payment_method'])) ?>
                                        </div>
                                        <?php if (!empty($d['transaction_id'])): ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[200px]" title="<?= e($d['transaction_id']) ?>">
                                                Ref: <?= e($d['transaction_id']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <?php if (!empty($d['proof_image'])): ?>
                                            <a href="<?= e(base_url($d['proof_image'])) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 font-medium underline flex items-center gap-1">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                View Proof
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500 text-xs italic">No proof provided</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <?php if($d['status'] === 'pending'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>
                                        <?php elseif($d['status'] === 'completed'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">Approved</span>
                                        <?php elseif($d['status'] === 'rejected'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4 text-right">
                                        <?php if ($d['status'] === 'pending'): ?>
                                            <div class="flex items-center justify-end gap-2">
                                                <form method="POST" action="verify_deposits.php" onsubmit="return confirm('Are you sure you want to APPROVE this deposit request?');">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="transaction_id" value="<?= $d['id'] ?>">
                                                    <button type="submit" class="p-1.5 bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:hover:bg-emerald-500/30 rounded-md transition" title="Approve">
                                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                    </button>
                                                </form>
                                                <button type="button" onclick="openRejectModal(<?= $d['id'] ?>)" class="p-1.5 bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-500/20 dark:text-red-300 dark:hover:bg-red-500/30 rounded-md transition" title="Reject">
                                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 dark:text-gray-500 font-medium italic">Processed</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto pt-20 pb-10">
    <div class="relative w-full max-w-md bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col my-auto overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-800 flex justify-between items-center bg-gray-50 dark:bg-slate-900/50">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Reject Deposit Request</h3>
            <button type="button" onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="verify_deposits.php" class="p-6">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="transaction_id" id="reject_tx_id" value="">
            
            <div class="mb-5">
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Rejection Reason <span class="text-red-500">*</span></label>
                <textarea name="rejection_reason" required rows="3" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow" placeholder="e.g., Payment screenshot is invalid or missing..."></textarea>
            </div>
            
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeRejectModal()" class="px-5 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-red-600 hover:bg-red-700 text-white shadow-lg shadow-red-600/30 transition-all" onclick="return confirm('Are you sure you want to reject this deposit request?');">Reject Request</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openRejectModal(id) {
        document.getElementById('reject_tx_id').value = id;
        document.getElementById('rejectModal').classList.remove('hidden');
    }
    
    function closeRejectModal() {
        document.getElementById('rejectModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
