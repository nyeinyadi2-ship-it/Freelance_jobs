<?php
$page_title = 'Manage Withdrawals';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $action = $_POST['action'];
    $request_id = (int) $_POST['request_id'];
    $admin_notes = trim($_POST['admin_notes'] ?? '');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM withdraw_requests WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $request_id);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$req) {
            throw new Exception("Withdrawal request not found.");
        }
        if ($req['status'] !== 'pending') {
            throw new Exception("Request is already processed.");
        }

        $now = date('Y-m-d H:i:s');
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE withdraw_requests SET status = 'approved', admin_notes = ?, processed_at = ? WHERE id = ?");
            $stmt->bind_param('ssi', $admin_notes, $now, $request_id);
            $stmt->execute();
            $stmt->close();
            set_flash('success', "Withdrawal request #{$request_id} approved.");
        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE withdraw_requests SET status = 'rejected', admin_notes = ?, processed_at = ? WHERE id = ?");
            $stmt->bind_param('ssi', $admin_notes, $now, $request_id);
            $stmt->execute();
            $stmt->close();

            // Refund to freelancer
            $stmt = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
            $stmt->bind_param('i', $req['freelancer_id']);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($fl_user) {
                $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
                $stmt->bind_param('di', $req['amount'], $fl_user['user_id']);
                $stmt->execute();
                $stmt->close();
            }

            set_flash('success', "Withdrawal request #{$request_id} rejected and funds refunded.");
        } else {
            throw new Exception("Invalid action.");
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        set_flash('error', $e->getMessage());
    }
    redirect('admin/withdrawals.php');
}

$requests = [];
$stmt = $conn->prepare("
    SELECT w.*, f.full_name, u.email 
    FROM withdraw_requests w 
    JOIN freelancers f ON w.freelancer_id = f.id 
    JOIN users u ON f.user_id = u.id 
    ORDER BY w.created_at DESC
");
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $requests[] = $row;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="flex h-screen bg-gray-50 dark:bg-gray-900 overflow-hidden">
    <!-- Sidebar -->
    <?php require_once __DIR__ . '/includes/admin_sidebar.php'; ?>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
        <div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Manage Withdrawals</h1>
                <p class="text-gray-600 dark:text-gray-400 mt-1">Review and process freelancer withdrawal requests.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <?php if (empty($requests)): ?>
                <div class="py-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                    </svg>
                    <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">No withdrawal requests found.</p>
                    <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">When freelancers request a withdrawal, they will appear here.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm whitespace-nowrap">
                        <thead>
                            <tr class="bg-gray-50 dark:bg-gray-900/50 border-b border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300">
                                <th class="py-3.5 px-4 font-semibold">Requested Date</th>
                                <th class="py-3.5 px-4 font-semibold">Freelancer</th>
                                <th class="py-3.5 px-4 font-semibold">Amount</th>
                                <th class="py-3.5 px-4 font-semibold">Payment Details</th>
                                <th class="py-3.5 px-4 font-semibold">Status</th>
                                <th class="py-3.5 px-4 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($requests as $r): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                    <td class="py-4 px-4 text-gray-500 dark:text-gray-400">
                                        <div class="text-xs font-mono text-gray-400 dark:text-gray-500 mb-0.5">#<?= $r['id'] ?></div>
                                        <?= date('M j, Y H:i', strtotime($r['created_at'])) ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <div class="font-medium text-gray-900 dark:text-white"><?= e($r['full_name']) ?></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400"><?= e($r['email']) ?></div>
                                    </td>
                                    <td class="py-4 px-4 font-bold text-red-600 dark:text-red-400">
                                        $<?= number_format($r['amount'], 2) ?>
                                    </td>
                                    <td class="py-4 px-4">
                                        <div class="font-medium text-gray-900 dark:text-white capitalize">
                                            <?= e(str_replace('_', ' ', $r['payment_method'])) ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[200px]" title="<?= e($r['payment_details']) ?>">
                                            <?= e($r['payment_details']) ?>
                                        </div>
                                    </td>
                                    <td class="py-4 px-4">
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">Approved</span>
                                        <?php elseif ($r['status'] === 'rejected'): ?>
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">Rejected</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-4 px-4 text-right">
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <div class="flex items-center justify-end gap-2">
                                                <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to APPROVE this withdrawal request?');">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                                    <button type="submit" class="p-1.5 bg-emerald-100 text-emerald-700 hover:bg-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:hover:bg-emerald-500/30 rounded-md transition" title="Approve">
                                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                                    </button>
                                                </form>
                                                <button type="button" onclick="openRejectModal(<?= $r['id'] ?>)" class="p-1.5 bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-500/20 dark:text-red-300 dark:hover:bg-red-500/30 rounded-md transition" title="Reject">
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
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Reject Withdrawal Request</h3>
            <button type="button" onclick="closeRejectModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="withdrawals.php" class="p-6">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject_tx_id" value="">
            
            <div class="mb-5">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-100 dark:border-red-800/30 rounded-lg p-3 mb-4">
                    <p class="text-sm text-red-800 dark:text-red-300 font-medium">Rejecting this request will automatically refund the amount back to the freelancer's available balance.</p>
                </div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Admin Notes (Reason) <span class="text-gray-400 font-normal">(Optional)</span></label>
                <textarea name="admin_notes" rows="3" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-shadow" placeholder="e.g., Invalid account details provided..."></textarea>
            </div>
            
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeRejectModal()" class="px-5 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-semibold bg-red-600 hover:bg-red-700 text-white shadow-lg shadow-red-600/30 transition-all" onclick="return confirm('Are you sure you want to reject this withdrawal and refund the user?');">Reject & Refund</button>
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
