<?php
$page_title = 'Earnings & Wallet';
require __DIR__ . '/../includes/freelancer_layout.php';
require_once __DIR__ . '/../config/escrow.php';

/**
 * @var mysqli $conn
 * @var int $fl_freelancer_id
 */
$earnings_stats = get_freelancer_earnings_stats($conn, $fl_freelancer_id);

$escrow_active = [];
try {
    $s = $conn->prepare("SELECT p.id, p.amount, p.escrow_status, p.funded_at, p.created_at, a.id AS assignment_id, a.status AS assignment_status, a.submission_link, a.assigned_at, j.title AS job_title, j.budget, c.company_name, c.logo_image FROM payments p JOIN assignments a ON p.assignment_id = a.id JOIN jobs j ON a.job_id = j.id JOIN companies c ON p.company_id = c.id WHERE p.freelancer_id = ? AND p.escrow_status IN ('funded','in_progress','submitted','revision_requested','approved') ORDER BY p.funded_at DESC");
    $s->bind_param('i', $fl_freelancer_id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $escrow_active[] = $row;
    $s->close();
} catch (Exception $e) {}

$escrow_completed = [];
try {
    $s = $conn->prepare("SELECT p.id, p.amount, p.escrow_status, p.paid_at, p.released_at, p.refunded_at, a.id AS assignment_id, j.title AS job_title, j.budget, c.company_name, c.logo_image FROM payments p JOIN assignments a ON p.assignment_id = a.id JOIN jobs j ON a.job_id = j.id JOIN companies c ON p.company_id = c.id WHERE p.freelancer_id = ? AND p.escrow_status IN ('released','refunded') ORDER BY COALESCE(p.released_at, p.refunded_at) DESC");
    $s->bind_param('i', $fl_freelancer_id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $escrow_completed[] = $row;
    $s->close();
} catch (Exception $e) {}

$withdrawals = [];
try {
    $s = $conn->prepare("SELECT * FROM withdraw_requests WHERE freelancer_id = ? ORDER BY created_at DESC");
    $s->bind_param('i', $fl_freelancer_id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) $withdrawals[] = $row;
    $s->close();
} catch (Exception $e) {}

$active_submissions = [];
try {
    $s = $conn->prepare("SELECT s.assignment_id, s.project_url, s.notes, s.status AS sub_status, s.revision_notes, s.version, s.created_at AS submitted_at FROM submissions s JOIN payments p ON s.assignment_id = p.assignment_id WHERE p.freelancer_id = ? AND p.escrow_status IN ('submitted','revision_requested','approved') ORDER BY s.version DESC");
    $s->bind_param('i', $fl_freelancer_id);
    $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) {
        if (!isset($active_submissions[$row['assignment_id']])) {
            $active_submissions[$row['assignment_id']] = $row;
        }
    }
    $s->close();
} catch (Exception $e) {}

function withdraw_status_badge(string $status): string
{
    $map = [
        'pending'   => ['bg-yellow-100 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300', 'Pending'],
        'approved'  => ['bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300', 'Approved'],
        'rejected'  => ['bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300', 'Rejected'],
        'completed' => ['bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300', 'Completed'],
    ];
    $info = $map[$status] ?? ['bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300', ucfirst($status)];
    return '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full ' . $info[0] . '">' . $info[1] . '</span>';
}
?>

<!-- Earnings Hero Card -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-4">
    <div class="rounded-3xl p-6 sm:p-8 text-white relative overflow-hidden reveal" style="background:linear-gradient(135deg,#312e81 0%,#4f46e5 35%,#7c3aed 65%,#a855f7 100%)">
        <div class="absolute top-0 right-0 w-64 h-64 opacity-10 pointer-events-none">
            <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="absolute bottom-0 left-0 w-48 h-48 opacity-10 pointer-events-none rounded-full" style="background:radial-gradient(circle,white 0%,transparent 70%);transform:translate(-30%,30%)"></div>

        <div class="relative z-10">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 rounded-2xl bg-white/15 flex items-center justify-center backdrop-blur-sm">
                    <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight">Earnings & Wallet</h1>
                    <p class="text-sm text-white/70">Track your income and manage withdrawals</p>
                </div>
            </div>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Available Balance</p>
                    <p class="text-2xl sm:text-3xl font-extrabold"><?= number_format($earnings_stats['available_balance'], 2) ?> MMK</p>
                    <p class="text-[10px] text-white/40 mt-1">Ready to withdraw</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Pending Balance</p>
                    <p class="text-2xl sm:text-3xl font-extrabold"><?= number_format($earnings_stats['pending_balance'], 2) ?> MMK</p>
                    <p class="text-[10px] text-white/40 mt-1">In withdrawal review</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Lifetime Earnings</p>
                    <p class="text-2xl sm:text-3xl font-extrabold"><?= number_format($earnings_stats['total_earnings'], 2) ?> MMK</p>
                    <p class="text-[10px] text-white/40 mt-1">Total received</p>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <p class="text-xs text-white/60 font-medium mb-1">Total Withdrawn</p>
                    <p class="text-2xl sm:text-3xl font-extrabold"><?= number_format($earnings_stats['total_withdrawn'], 2) ?> MMK</p>
                    <p class="text-[10px] text-white/40 mt-1">Paid out</p>
                </div>
            </div>

            <?php if ($earnings_stats['available_balance'] > 0): ?>
                <button onclick="openWithdrawModal()" class="inline-flex items-center gap-2 px-6 py-3 text-sm font-semibold rounded-xl bg-white text-indigo-700 hover:bg-gray-50 transition-all shadow-lg hover:shadow-xl hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Request Withdrawal
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tab Navigation -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
    <div class="sticky top-16 z-30 -mx-4 px-4 py-2 overflow-x-auto scrollbar-thin tab-scroll" style="background:var(--color-bg);border-bottom:1px solid var(--color-border)">
        <div class="flex gap-1 min-w-max">
            <?php
            $earnings_tabs = [
                ['id' => 'active', 'label' => 'Active Payments', 'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z', 'badge' => count($escrow_active), 'bc' => 'blue'],
                ['id' => 'completed', 'label' => 'Completed Payments', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['id' => 'withdrawals', 'label' => 'Withdrawal History', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ];
            $first = true;
            foreach ($earnings_tabs as $t): ?>
                <button class="dash-tab <?= $first ? 'active' : '' ?> px-3 sm:px-4 py-2.5 text-sm rounded-t-lg flex items-center gap-1.5" data-tab="<?= $t['id'] ?>" style="color:var(--color-text-muted)">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $t['icon'] ?>"/></svg>
                    <span class="hidden sm:inline"><?= $t['label'] ?></span>
                    <?php if (!empty($t['badge'])): ?>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-<?= $t['bc'] ?>-100 dark:bg-<?= $t['bc'] ?>-900/40 text-<?= $t['bc'] ?>-600 dark:text-<?= $t['bc'] ?>-400"><?= $t['badge'] ?></span>
                    <?php endif; ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
    </div>
</div>

<!-- Tab Content -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">

<!-- Active Payments -->
<div class="dash-section active" id="tab-active">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Active Payments</h2>
    <?php if (empty($escrow_active)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="mb-3">No active escrow payments.</p>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white">Browse Jobs</a>
        </div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($escrow_active as $ep): ?>
                <?php $sub = $active_submissions[$ep['assignment_id']] ?? null; ?>
                <div class="glass rounded-2xl p-5 sm:p-6 hover-lift reveal">
                    <div class="flex flex-col sm:flex-row sm:items-start gap-4">
                        <div class="flex items-center gap-3 flex-shrink-0">
                            <?php if ($ep['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/images/' . $ep['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-contain border" style="border-color:var(--color-border)">
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-lg" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($ep['company_name'], 0, 1)) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <h3 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($ep['job_title']) ?></h3>
                                <?= escrow_status_badge($ep['escrow_status']) ?>
                            </div>
                            <p class="text-sm mb-3" style="color:var(--color-text-muted)"><?= e($ep['company_name']) ?></p>
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-sm" style="color:var(--color-text-secondary)">
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <span class="font-bold text-indigo-600 text-lg"><?= number_format((float) $ep['amount'], 2) ?> MMK</span>
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    Funded <?= date('M j, Y', strtotime($ep['funded_at'])) ?>
                                </span>
                            </div>

                            <?php if ($ep['escrow_status'] === 'revision_requested' && $sub && !empty($sub['revision_notes'])): ?>
                                <div class="mt-4 p-3.5 rounded-xl bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800/40">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <svg class="w-4 h-4 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                        <span class="text-xs font-semibold text-orange-700 dark:text-orange-300">Revision Notes</span>
                                    </div>
                                    <p class="text-sm text-orange-800 dark:text-orange-200"><?= e($sub['revision_notes']) ?></p>
                                </div>
                            <?php endif; ?>

                            <?php if ($sub && in_array($ep['escrow_status'], ['submitted', 'approved'])): ?>
                                <div class="mt-4 p-3.5 rounded-xl bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800/40">
                                    <div class="flex items-center gap-2 mb-1.5">
                                        <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span class="text-xs font-semibold text-purple-700 dark:text-purple-300">Submission (v<?= $sub['version'] ?>)</span>
                                    </div>
                                    <?php if ($sub['project_url']): ?>
                                        <a href="<?= e($sub['project_url']) ?>" target="_blank" rel="noopener" class="text-sm text-purple-600 dark:text-purple-400 hover:underline flex items-center gap-1">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                            <?= e(mb_strimwidth($sub['project_url'], 0, 60, '...')) ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($sub['notes']): ?>
                                        <p class="text-sm mt-1" style="color:var(--color-text-secondary)"><?= e($sub['notes']) ?></p>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Completed Payments -->
<div class="dash-section" id="tab-completed">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Completed Payments</h2>
    <?php if (empty($escrow_completed)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p>No completed payments yet.</p>
        </div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($escrow_completed as $cp): ?>
                <div class="glass rounded-2xl p-5 hover-lift reveal">
                    <div class="flex items-center gap-3 mb-4">
                        <?php if ($cp['logo_image']): ?>
                            <img src="<?= e(base_url('uploads/images/' . $cp['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-sm" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($cp['company_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($cp['job_title']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($cp['company_name']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xl font-extrabold <?= $cp['escrow_status'] === 'released' ? 'text-emerald-600' : 'text-red-500' ?>"><?= number_format((float) $cp['amount'], 2) ?> MMK</span>
                        <?= escrow_status_badge($cp['escrow_status']) ?>
                    </div>
                    <div class="flex items-center justify-between pt-3 border-t" style="border-color:var(--color-border)">
                        <span class="text-xs" style="color:var(--color-text-placeholder)"><?= $cp['escrow_status'] === 'released' ? 'Paid' : 'Refunded' ?> <?= date('M j, Y', strtotime($cp['escrow_status'] === 'released' ? $cp['released_at'] : $cp['refunded_at'])) ?></span>
                        <button onclick="openInvoiceModal(<?= e(json_encode(['id' => $cp['id'], 'amount' => $cp['amount'], 'job_title' => $cp['job_title'], 'company_name' => $cp['company_name'], 'escrow_status' => $cp['escrow_status'], 'date' => $cp['escrow_status'] === 'released' ? $cp['released_at'] : $cp['refunded_at'], 'assignment_id' => $cp['assignment_id']])) ?>)" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            View Invoice
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Withdrawal History -->
<div class="dash-section" id="tab-withdrawals">
    <div class="flex items-center justify-between mb-5">
        <h2 class="text-xl font-bold" style="color:var(--color-text-primary)">Withdrawal History</h2>
        <?php if ($earnings_stats['available_balance'] > 0): ?>
            <button onclick="openWithdrawModal()" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                New Withdrawal
            </button>
        <?php endif; ?>
    </div>
    <?php if (empty($withdrawals)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)">
            <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            <p class="mb-3">No withdrawal history yet.</p>
            <?php if ($earnings_stats['available_balance'] > 0): ?>
                <button onclick="openWithdrawModal()" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white">Request Your First Withdrawal</button>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="glass rounded-2xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b text-left" style="border-color:var(--color-border);color:var(--color-text-muted)">
                        <th class="p-4 font-semibold">Amount</th>
                        <th class="p-4 font-semibold">Method</th>
                        <th class="p-4 font-semibold">Status</th>
                        <th class="p-4 font-semibold hidden sm:table-cell">Requested</th>
                        <th class="p-4 font-semibold hidden sm:table-cell">Processed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($withdrawals as $w): ?>
                        <tr class="border-b transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="border-color:var(--color-border)">
                            <td class="p-4 font-bold text-primary-600"><?= number_format((float) $w['amount'], 2) ?> MMK</td>
                            <td class="p-4" style="color:var(--color-text-secondary)">
                                <span class="flex items-center gap-1.5">
                                    <?php if ($w['payment_method'] === 'Bank Transfer'): ?>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M3 10h18M5 6l7-3 7 3M4 10v11m16-11v11M8 14v3m4-3v3m4-3v3"/></svg>
                                    <?php elseif ($w['payment_method'] === 'PayPal'): ?>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                    <?php else: ?>
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                    <?php endif; ?>
                                    <?= e($w['payment_method'] ?? 'N/A') ?>
                                </span>
                            </td>
                            <td class="p-4"><?= withdraw_status_badge($w['status']) ?></td>
                            <td class="p-4 hidden sm:table-cell" style="color:var(--color-text-placeholder)"><?= date('M j, Y', strtotime($w['created_at'])) ?></td>
                            <td class="p-4 hidden sm:table-cell" style="color:var(--color-text-placeholder)"><?= $w['processed_at'] ? date('M j, Y', strtotime($w['processed_at'])) : '<span class="text-xs italic">Pending</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

</div><!-- end tab content -->

<!-- Withdrawal Modal -->
<div id="withdraw-modal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeWithdrawModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="glass rounded-3xl w-full max-w-lg p-6 sm:p-8 relative shadow-2xl" style="background:var(--color-card)">
            <button onclick="closeWithdrawModal()" class="absolute top-4 right-4 p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                    <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Request Withdrawal</h2>
                    <p class="text-xs" style="color:var(--color-text-muted)">Available: <span class="font-bold text-emerald-600"><?= number_format($earnings_stats['available_balance'], 2) ?> MMK</span></p>
                </div>
            </div>

            <form id="withdraw-form" onsubmit="submitWithdrawal(event)">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="mb-4">
                    <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-primary)">Amount (MMK)</label>
                    <input type="number" name="amount" step="0.01" min="1" max="<?= number_format($earnings_stats['available_balance'], 2, '.', '') ?>" required
                           class="w-full px-4 py-3 rounded-xl border text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                           style="background:var(--color-bg);border-color:var(--color-border);color:var(--color-text-primary)"
                           placeholder="0.00">
                    <p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Minimum: 1.00 MMK &middot; Maximum: <?= number_format($earnings_stats['available_balance'], 2) ?> MMK</p>
                </div>

                <?php if (empty($fl_profile['payment_method'])): ?>
                    <div class="mb-6 p-4 bg-yellow-50 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 rounded-xl text-sm border border-yellow-200 dark:border-yellow-800/40">
                        You haven't configured a payment method yet.<br>
                        <a href="profile.php?edit=1" class="font-bold underline text-indigo-600 dark:text-indigo-400 mt-2 inline-block">Configure Payment Settings</a>
                    </div>
                <?php else: ?>
                    <div class="mb-6">
                        <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-primary)">Saved Payment Method</label>
                        <div class="w-full px-4 py-3 rounded-xl border text-sm font-medium bg-gray-50 dark:bg-gray-800/50" style="border-color:var(--color-border);color:var(--color-text-secondary)">
                            <strong class="text-gray-900 dark:text-gray-100"><?= e(ucwords(str_replace('_', ' ', $fl_profile['payment_method']))) ?></strong><br>
                            <?= e($fl_profile['payment_account_name']) ?> &bull; <?= e($fl_profile['payment_account_number']) ?>
                            <?php if ($fl_profile['payment_method'] === 'bank_transfer' && !empty($fl_profile['payment_bank_name'])): ?>
                                <br><?= e($fl_profile['payment_bank_name']) ?>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Change this in your <a href="profile.php?edit=1" class="text-indigo-500 hover:underline">Profile Settings</a>.</p>
                    </div>
                <?php endif; ?>

                <div id="withdraw-error" class="hidden mb-4 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/40 text-sm text-red-600 dark:text-red-400"></div>
                <div id="withdraw-success" class="hidden mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800/40 text-sm text-emerald-600 dark:text-emerald-400"></div>

                <button type="submit" id="withdraw-submit-btn" <?= empty($fl_profile['payment_method']) ? 'disabled' : '' ?>
                        class="w-full <?= empty($fl_profile['payment_method']) ? 'bg-gray-400 cursor-not-allowed' : 'btn-grad hover:-translate-y-0.5' ?> inline-flex items-center justify-center gap-2 px-6 py-3 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/25 transition-all">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    Submit Withdrawal Request
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div id="invoice-modal" class="fixed inset-0 z-[100] hidden">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeInvoiceModal()"></div>
    <div class="absolute inset-0 flex items-center justify-center p-4">
        <div class="glass rounded-3xl w-full max-w-md p-6 sm:p-8 relative shadow-2xl" style="background:var(--color-card)">
            <button onclick="closeInvoiceModal()" class="absolute top-4 right-4 p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <div class="text-center mb-6">
                <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4" style="background:rgba(99,102,241,0.1)">
                    <svg class="w-8 h-8 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </div>
                <p class="text-xs font-medium uppercase tracking-wider" style="color:var(--color-text-placeholder)">Invoice</p>
                <h3 class="text-lg font-bold mt-1" style="color:var(--color-text-primary)" id="inv-job-title">—</h3>
            </div>
            <div class="space-y-3 mb-6">
                <div class="flex justify-between items-center py-2 border-b" style="border-color:var(--color-border)">
                    <span class="text-sm" style="color:var(--color-text-muted)">Invoice #</span>
                    <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="inv-number">—</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b" style="border-color:var(--color-border)">
                    <span class="text-sm" style="color:var(--color-text-muted)">Company</span>
                    <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="inv-company">—</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b" style="border-color:var(--color-border)">
                    <span class="text-sm" style="color:var(--color-text-muted)">Date</span>
                    <span class="text-sm font-semibold" style="color:var(--color-text-primary)" id="inv-date">—</span>
                </div>
                <div class="flex justify-between items-center py-2 border-b" style="border-color:var(--color-border)">
                    <span class="text-sm" style="color:var(--color-text-muted)">Status</span>
                    <span id="inv-status">—</span>
                </div>
                <div class="flex justify-between items-center py-2">
                    <span class="text-sm font-bold" style="color:var(--color-text-primary)">Total Earned</span>
                    <span class="text-xl font-extrabold text-emerald-600" id="inv-amount">—</span>
                </div>
            </div>
            <button onclick="closeInvoiceModal()" class="w-full px-6 py-3 text-sm font-semibold rounded-xl border transition-colors hover:bg-gray-50 dark:hover:bg-gray-800" style="border-color:var(--color-border);color:var(--color-text-primary)">Close</button>
        </div>
    </div>
</div>

<script>
function switchTab(tabId) {
    document.querySelectorAll('.dash-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.dash-section').forEach(function(s) { s.classList.remove('active'); });
    var at = document.querySelector('.dash-tab[data-tab="' + tabId + '"]');
    var as = document.getElementById('tab-' + tabId);
    if (at) at.classList.add('active');
    if (as) as.classList.add('active');
    var tn = document.querySelector('.dash-tab');
    if (tn) window.scrollTo({ top: tn.offsetTop - 30, behavior: 'smooth' });
}
document.querySelectorAll('.dash-tab').forEach(function(tab) {
    tab.addEventListener('click', function() { switchTab(this.getAttribute('data-tab')); });
});

function openWithdrawModal() {
    document.getElementById('withdraw-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeWithdrawModal() {
    document.getElementById('withdraw-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

function submitWithdrawal(e) {
    e.preventDefault();
    var form = document.getElementById('withdraw-form');
    var errEl = document.getElementById('withdraw-error');
    var successEl = document.getElementById('withdraw-success');
    var btn = document.getElementById('withdraw-submit-btn');

    errEl.classList.add('hidden');
    successEl.classList.add('hidden');

    var data = {
        action: 'request_withdrawal',
        csrf_token: form.csrf_token.value,
        amount: parseFloat(form.amount.value)
    };

    btn.disabled = true;
    btn.innerHTML = '<svg class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Processing...';

    fetch('<?= e(base_url('api/withdraw.php')) ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.success) {
            successEl.textContent = 'Withdrawal request submitted successfully! It will be reviewed by an admin shortly.';
            successEl.classList.remove('hidden');
            form.reset();
            setTimeout(function() { location.reload(); }, 2000);
        } else {
            errEl.textContent = d.error || 'Failed to submit withdrawal request.';
            errEl.classList.remove('hidden');
        }
    })
    .catch(function() {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.remove('hidden');
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg> Submit Withdrawal Request';
    });
}

function openInvoiceModal(data) {
    var invNum = 'INV-' + String(data.id).padStart(6, '0');
    var dateStr = data.date ? new Date(data.date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
    document.getElementById('inv-job-title').textContent = data.job_title;
    document.getElementById('inv-number').textContent = invNum;
    document.getElementById('inv-company').textContent = data.company_name;
    document.getElementById('inv-date').textContent = dateStr;
    document.getElementById('inv-amount').textContent = parseFloat(data.amount).toFixed(2) + ' MMK';
    var statusEl = document.getElementById('inv-status');
    if (data.escrow_status === 'released') {
        statusEl.innerHTML = '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">Paid</span>';
    } else {
        statusEl.innerHTML = '<span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">Refunded</span>';
    }
    document.getElementById('invoice-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}
function closeInvoiceModal() {
    document.getElementById('invoice-modal').classList.add('hidden');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeWithdrawModal();
        closeInvoiceModal();
    }
});
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
