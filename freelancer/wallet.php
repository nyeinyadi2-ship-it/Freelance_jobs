<?php
$page_title = 'Freelancer Wallet';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('freelancer');
$user = current_user();

// Get freelancer id
$stmt = $conn->prepare("SELECT id FROM freelancers WHERE user_id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$fl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$fl) {
    set_flash('error', 'Freelancer profile not found.');
    redirect('freelancer/dashboard.php');
}
$freelancer_id = (int) $fl['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'withdraw') {
    $amount = (float) ($_POST['amount'] ?? 0);
    $method = trim($_POST['payment_method'] ?? '');
    $details = trim($_POST['payment_details'] ?? '');

    if ($amount <= 0) {
        set_flash('error', 'Withdrawal amount must be greater than zero.');
    } elseif (empty($method) || empty($details)) {
        set_flash('error', 'Payment method and details are required.');
    } else {
        $conn->begin_transaction();
        try {
            // Deduct from available_balance if enough funds
            $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance - ? WHERE id = ? AND available_balance >= ?");
            $stmt->bind_param('did', $amount, $user['user_id'], $amount);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();

            if ($affected === 0) {
                throw new Exception("Insufficient available balance for this withdrawal.");
            }

            // Create pending withdrawal request
            $stmt = $conn->prepare("INSERT INTO withdraw_requests (freelancer_id, amount, status, payment_method, payment_details) VALUES (?, ?, 'pending', ?, ?)");
            $stmt->bind_param('idss', $freelancer_id, $amount, $method, $details);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
            set_flash('success', "Withdrawal request for $" . number_format($amount, 2) . " submitted successfully.");
        } catch (Exception $e) {
            $conn->rollback();
            if (strpos($e->getMessage(), 'Insufficient') !== false) {
                set_flash('error', $e->getMessage());
            } else {
                set_flash('error', 'Failed to process withdrawal request.');
            }
        }
        redirect('freelancer/wallet.php');
    }
}

// Fetch current balance
$stmt = $conn->prepare("SELECT available_balance FROM users WHERE id = ?");
$stmt->bind_param('i', $user['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$available_balance = (float) ($row['available_balance'] ?? 0);
$stmt->close();

// Fetch withdrawal history
$withdrawals = [];
$stmt = $conn->prepare("SELECT * FROM withdraw_requests WHERE freelancer_id = ? ORDER BY created_at DESC");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$res = $stmt->get_result();
while ($w = $res->fetch_assoc()) {
    $withdrawals[] = $w;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-2">Freelancer Wallet</h1>
    <p class="text-gray-600 dark:text-gray-400">Manage your earnings and request withdrawals.</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-1 h-fit">
        <h2 class="text-lg font-semibold mb-1">Available Balance</h2>
        <div class="text-4xl font-bold text-green-600 dark:text-green-400 mb-6">
            $<?= number_format($available_balance, 2) ?>
        </div>

        <h3 class="text-md font-semibold mb-3 border-t pt-4 dark:border-gray-700">Request Withdrawal</h3>
        <form method="POST" action="wallet.php" class="space-y-4">
            <input type="hidden" name="action" value="withdraw">
            
            <div>
                <label class="block text-sm font-medium mb-1">Amount ($)</label>
                <input type="number" name="amount" step="0.01" min="10" max="<?= $available_balance ?>" placeholder="0.00" required
                       class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-gray-50 dark:bg-gray-900 dark:border-gray-600">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Payment Method</label>
                <select name="payment_method" required class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-gray-50 dark:bg-gray-900 dark:border-gray-600">
                    <option value="">Select a method</option>
                    <option value="paypal">PayPal</option>
                    <option value="bank_transfer">Bank Transfer</option>
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">Payment Details</label>
                <textarea name="payment_details" required placeholder="PayPal Email or Bank Account Info" rows="3"
                          class="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 bg-gray-50 dark:bg-gray-900 dark:border-gray-600"></textarea>
            </div>

            <button type="submit" class="w-full py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition" <?= $available_balance < 10 ? 'disabled' : '' ?>>
                Withdraw Funds
            </button>
            <?php if ($available_balance < 10): ?>
                <p class="text-xs text-red-500 mt-1">Minimum withdrawal amount is $10.00.</p>
            <?php endif; ?>
        </form>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <h2 class="text-lg font-semibold mb-4">Withdrawal History</h2>
        
        <?php if (empty($withdrawals)): ?>
            <div class="text-center py-8 text-gray-500">
                <p>No withdrawal requests found.</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b dark:border-gray-700 text-gray-500">
                            <th class="py-3 px-4 font-medium">Date</th>
                            <th class="py-3 px-4 font-medium">Amount</th>
                            <th class="py-3 px-4 font-medium">Method</th>
                            <th class="py-3 px-4 font-medium">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y dark:divide-gray-700">
                        <?php foreach ($withdrawals as $w): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-3 px-4"><?= date('M j, Y H:i', strtotime($w['created_at'])) ?></td>
                                <td class="py-3 px-4 font-semibold text-gray-900 dark:text-gray-100">
                                    $<?= number_format($w['amount'], 2) ?>
                                </td>
                                <td class="py-3 px-4 capitalize"><?= e(str_replace('_', ' ', $w['payment_method'])) ?></td>
                                <td class="py-3 px-4">
                                    <?php if ($w['status'] === 'pending'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400">Pending</span>
                                    <?php elseif ($w['status'] === 'approved' || $w['status'] === 'completed'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">Approved</span>
                                    <?php elseif ($w['status'] === 'rejected'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">Rejected</span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
