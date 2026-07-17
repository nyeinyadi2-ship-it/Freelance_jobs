<?php
$page_title = 'Payment History';
require_once __DIR__ . '/../config/escrow.php';
require __DIR__ . '/../includes/freelancer_layout.php';

$payments = [];
$stmt = $conn->prepare("
    SELECT p.*, c.company_name, f.full_name AS freelancer_name, a.job_id, j.title AS job_title
    FROM payments p
    JOIN companies c ON p.company_id = c.id
    JOIN freelancers f ON p.freelancer_id = f.id
    JOIN assignments a ON p.assignment_id = a.id
    JOIN jobs j ON a.job_id = j.id
    WHERE p.freelancer_id = ?
    ORDER BY p.created_at DESC
");
$stmt->bind_param('i', $fl_freelancer_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $payments[] = $row;
}
$stmt->close();

$stats = get_freelancer_earnings_stats($conn, $fl_freelancer_id);
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-4">
    <div class="rounded-3xl p-6 sm:p-8 text-white relative overflow-hidden reveal" style="background:linear-gradient(135deg,#312e81 0%,#4f46e5 35%,#7c3aed 65%,#a855f7 100%)">
        <div class="absolute top-0 right-0 w-64 h-64 opacity-10 pointer-events-none">
            <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="relative z-10">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center backdrop-blur-sm">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight">Payment History</h1>
                    <p class="text-sm text-white/70">Track all your payments received</p>
                </div>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Available Balance</p>
                    <p class="text-2xl sm:text-3xl font-extrabold">$<?= number_format($stats['available_balance'], 2) ?></p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Pending Balance</p>
                    <p class="text-2xl sm:text-3xl font-extrabold">$<?= number_format($stats['pending_balance'], 2) ?></p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Lifetime Earnings</p>
                    <p class="text-2xl sm:text-3xl font-extrabold">$<?= number_format($stats['total_earnings'], 2) ?></p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Total Withdrawn</p>
                    <p class="text-2xl sm:text-3xl font-extrabold">$<?= number_format($stats['total_withdrawn'], 2) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">
    <?php if (empty($payments)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="mb-3">No payments received yet.</p>
        </div>
    <?php else: ?>
        <div class="glass rounded-2xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" style="border-color:var(--color-border);color:var(--color-text-muted)">
                        <th class="p-4 font-semibold">Payment ID</th>
                        <th class="p-4 font-semibold">Project</th>
                        <th class="p-4 font-semibold">Client</th>
                        <th class="p-4 font-semibold">Amount</th>
                        <th class="p-4 font-semibold">Method</th>
                        <th class="p-4 font-semibold">Date</th>
                        <th class="p-4 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $p): ?>
                        <tr class="border-b transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="border-color:var(--color-border)">
                            <td class="p-4 font-mono text-xs" style="color:var(--color-text-muted)">#<?= $p['id'] ?></td>
                            <td class="p-4 font-medium" style="color:var(--color-text-primary)"><?= e($p['job_title'] ?? '—') ?></td>
                            <td class="p-4" style="color:var(--color-text-secondary)"><?= e($p['company_name'] ?? '—') ?></td>
                            <td class="p-4 font-bold text-emerald-600">$<?= number_format((float) $p['amount'], 2) ?></td>
                            <td class="p-4" style="color:var(--color-text-muted)"><?= e(ucwords(str_replace('_', ' ', $p['payment_method'] ?? '—'))) ?></td>
                            <td class="p-4" style="color:var(--color-text-placeholder)"><?= date('M j, Y', strtotime($p['funded_at'] ?? $p['created_at'])) ?></td>
                            <td class="p-4"><?php
                                $s = $p['escrow_status'] ?? $p['status'] ?? 'pending';
                                $colors = [
                                    'funded' => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
                                    'in_progress' => 'bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300',
                                    'submitted' => 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300',
                                    'revision_requested' => 'bg-orange-100 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300',
                                    'approved' => 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
                                    'released' => 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
                                    'refunded' => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
                                ];
                                $c = $colors[$s] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300';
                                echo "<span class=\"inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full {$c}\">" . e(ucwords(str_replace('_', ' ', $s))) . "</span>";
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
