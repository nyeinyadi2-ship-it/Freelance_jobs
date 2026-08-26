<?php
$page_title = 'Transactions';
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
$freelancer_id = $fl['id'] ?? 0;

if ($freelancer_id === 0) {
    redirect('freelancer/dashboard.php');
}

// Fetch payments
$transactions = [];
$stmt = $conn->prepare("
    SELECT p.id as payment_id, p.amount, p.paid_at as created_at, p.status, p.transaction_slip,
           p.payment_method, p.transaction_reference,
           c.company_name, j.title as job_title, m.title as ms_title
    FROM payments p
    LEFT JOIN companies c ON p.company_id = c.id
    LEFT JOIN milestones m ON p.milestone_id = m.id
    LEFT JOIN jobs j ON m.job_id = j.id OR p.assignment_id = j.id
    WHERE p.freelancer_id = ?
    ORDER BY p.paid_at DESC
");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $transactions[] = $row;
}
$stmt->close();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold mb-2">Transactions</h1>
    <p class="text-gray-600 dark:text-gray-400">View all your received payments and transaction history.</p>
</div>

<div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6">
    <h2 class="text-lg font-semibold mb-4">Payment History</h2>
    
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
                        <th class="py-3 px-4 font-medium">Project</th>
                        <th class="py-3 px-4 font-medium">Milestone</th>
                        <th class="py-3 px-4 font-medium">Payment Method</th>
                        <th class="py-3 px-4 text-right font-medium">Amount</th>
                        <th class="py-3 px-4 font-medium">Transaction ID</th>
                        <th class="py-3 px-4 font-medium">View Slip</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-700">
                    <?php foreach ($transactions as $t): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-3 px-4">
                                <div class="text-sm font-medium text-gray-900 dark:text-gray-100"><?= date('j M Y', strtotime($t['created_at'])) ?></div>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-sm text-gray-900 dark:text-gray-100 font-medium"><?= e($t['job_title'] ?? 'Unknown Project') ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-sm text-gray-900 dark:text-gray-100"><?= e($t['ms_title'] ?? 'N/A') ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-sm text-gray-900 dark:text-gray-100"><?= e($t['payment_method'] ?? 'N/A') ?></span>
                            </td>
                            <td class="py-3 px-4 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                +<?= number_format((float) $t['amount'], 2) ?> MMK
                            </td>
                            <td class="py-3 px-4">
                                <span class="text-sm font-mono text-gray-900 dark:text-gray-100"><?= e($t['transaction_reference'] ?? 'N/A') ?></span>
                            </td>
                            <td class="py-3 px-4">
                                <?php if (!empty($t['transaction_slip']) && !empty($t['payment_id'])): ?>
                                    <a href="<?= base_url('api/view_slip.php?payment_id=' . $t['payment_id']) ?>" target="_blank" class="inline-flex items-center gap-1 text-sm font-semibold text-indigo-600 hover:text-indigo-800 transition-colors">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                        View Slip
                                    </a>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400 italic">Unavailable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
