<?php
$page_title = 'Manage Withdrawals';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_role('admin');

// Handle approve/reject actions
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

            $stmt = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
            $stmt->bind_param('i', $req['freelancer_id']);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($fl_user) {
                $stmt = $conn->prepare("UPDATE wallet_transactions SET status = 'completed' WHERE user_id = ? AND amount = ? AND type = 'withdrawal' AND status = 'pending' LIMIT 1");
                $stmt->bind_param('id', $fl_user['user_id'], $req['amount']);
                $stmt->execute();
                $stmt->close();
            }

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

                $stmt = $conn->prepare("UPDATE wallet_transactions SET status = 'rejected' WHERE user_id = ? AND amount = ? AND type = 'withdrawal' AND status = 'pending' LIMIT 1");
                $stmt->bind_param('id', $fl_user['user_id'], $req['amount']);
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

// Fetch all withdrawal requests
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

// Stats
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
$total_amount = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending') $pending_count++;
    elseif ($r['status'] === 'approved') $approved_count++;
    elseif ($r['status'] === 'rejected') $rejected_count++;
    $total_amount += (float) $r['amount'];
}

require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-8 admin-fade">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)">Manage Withdrawals</h1>
            <p class="mt-1 text-sm" style="color:var(--color-text-muted)">Review and process freelancer withdrawal requests.</p>
        </div>
        <div class="flex items-center gap-2 text-sm" style="color:var(--color-text-muted)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>Total: <strong style="color:var(--color-text-primary)"><?= count($requests) ?></strong> requests</span>
        </div>
    </div>
</div>

<!-- Stats Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <div class="admin-stat-card card admin-fade delay-1">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)">Pending</p>
                <p class="text-3xl font-extrabold mt-1 text-amber-600"><?= $pending_count ?></p>
            </div>
            <div class="stat-icon bg-amber-100 dark:bg-amber-900/30">
                <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>
    <div class="admin-stat-card card admin-fade delay-2">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)">Approved</p>
                <p class="text-3xl font-extrabold mt-1 text-emerald-600"><?= $approved_count ?></p>
            </div>
            <div class="stat-icon bg-emerald-100 dark:bg-emerald-900/30">
                <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>
    <div class="admin-stat-card card admin-fade delay-3">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)">Rejected</p>
                <p class="text-3xl font-extrabold mt-1 text-red-600"><?= $rejected_count ?></p>
            </div>
            <div class="stat-icon bg-red-100 dark:bg-red-900/30">
                <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>
    <div class="admin-stat-card card admin-fade delay-4">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)">Total Amount</p>
                <p class="text-3xl font-extrabold mt-1" style="color:var(--color-text-primary)"><?= number_format($total_amount, 2) ?> MMK</p>
            </div>
            <div class="stat-icon bg-indigo-100 dark:bg-indigo-900/30">
                <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
    </div>
</div>

<!-- Withdrawals Table -->
<div class="card admin-fade delay-5" style="border-radius:var(--co-radius,0.875rem);overflow:hidden;">
    <?php if (empty($requests)): ?>
        <div class="py-16 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center" style="background:var(--color-border-light,#f1f5f9)">
                <svg class="w-8 h-8" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
            </div>
            <p class="text-lg font-semibold" style="color:var(--color-text-secondary)">No withdrawal requests</p>
            <p class="text-sm mt-1" style="color:var(--color-text-muted)">When freelancers request a withdrawal, they will appear here.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr style="background:var(--color-border-light,#f8fafc);border-bottom:1px solid var(--color-border)">
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--color-text-muted)">Request</th>
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--color-text-muted)">Freelancer</th>
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--color-text-muted)">Amount</th>
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--color-text-muted)">Payment Details</th>
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--color-text-muted)">Status</th>
                        <th class="py-3.5 px-5 font-semibold text-xs uppercase tracking-wider text-right" style="color:var(--color-text-muted)">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                        <tr class="group" style="border-bottom:1px solid var(--color-border);transition:background 0.15s">
                            <td class="py-4 px-5">
                                <div class="text-xs font-mono mb-0.5" style="color:var(--color-text-placeholder)">#<?= $r['id'] ?></div>
                                <div class="font-medium" style="color:var(--color-text-primary)"><?= date('M j, Y', strtotime($r['created_at'])) ?></div>
                                <div class="text-xs" style="color:var(--color-text-muted)"><?= date('g:i A', strtotime($r['created_at'])) ?></div>
                            </td>
                            <td class="py-4 px-5">
                                <div class="flex items-center gap-3">
                                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                        <?= e(mb_strtoupper(mb_substr($r['full_name'], 0, 1))) ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($r['full_name']) ?></div>
                                        <div class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($r['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-4 px-5">
                                <span class="text-lg font-bold text-red-600 dark:text-red-400"><?= number_format($r['amount'], 2) ?> MMK</span>
                            </td>
                            <td class="py-4 px-5">
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold capitalize" style="background:var(--color-border-light,#f1f5f9);color:var(--color-text-secondary)">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                    <?= e(str_replace('_', ' ', $r['payment_method'])) ?>
                                </div>
                                <div class="text-xs mt-1 truncate max-w-[200px]" style="color:var(--color-text-muted)" title="<?= e($r['payment_details']) ?>">
                                    <?= e($r['payment_details']) ?>
                                </div>
                            </td>
                            <td class="py-4 px-5">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <span class="status-badge status-pending">
                                        <span class="status-dot"></span>
                                        Pending
                                    </span>
                                <?php elseif ($r['status'] === 'approved'): ?>
                                    <span class="status-badge status-approved">
                                        <span class="status-dot"></span>
                                        Approved
                                    </span>
                                <?php elseif ($r['status'] === 'rejected'): ?>
                                    <span class="status-badge status-rejected">
                                        <span class="status-dot"></span>
                                        Rejected
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-5 text-right">
                                <?php if ($r['status'] === 'pending'): ?>
                                    <div class="flex items-center justify-end gap-2">
                                        <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to APPROVE this withdrawal?');">
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                                            <button type="submit" class="action-btn action-btn-approve" title="Approve">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </button>
                                        </form>
                                        <button type="button" onclick="openRejectModal(<?= $r['id'] ?>)" class="action-btn action-btn-reject" title="Reject">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs italic" style="color:var(--color-text-placeholder)">Processed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4" style="background:rgba(0,0,0,0.6);backdrop-filter:blur(4px)">
    <div class="relative w-full max-w-md rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border)">
        <div class="px-6 py-4 flex justify-between items-center" style="border-bottom:1px solid var(--color-border);background:var(--color-border-light,#f8fafc)">
            <h3 class="text-lg font-bold" style="color:var(--color-text-primary)">Reject Withdrawal</h3>
            <button type="button" onclick="closeRejectModal()" class="p-1 rounded-lg transition-colors" style="color:var(--color-text-muted)" onmouseover="this.style.background='var(--color-border)'" onmouseout="this.style.background='transparent'">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="withdrawals.php" class="p-6">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" id="reject_tx_id" value="">
            
            <div class="mb-5">
                <div class="rounded-lg p-3 mb-4" style="background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.15)">
                    <div class="flex items-start gap-2">
                        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <p class="text-sm text-red-700 dark:text-red-300 font-medium">Rejecting will refund the amount back to the freelancer's balance.</p>
                    </div>
                </div>
                <label class="block text-sm font-semibold mb-1.5" style="color:var(--color-text-secondary)">Admin Notes <span class="font-normal" style="color:var(--color-text-placeholder)">(Optional)</span></label>
                <textarea name="admin_notes" rows="3" class="form-input w-full" placeholder="e.g., Invalid account details provided..."></textarea>
            </div>
            
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" onclick="closeRejectModal()" class="btn-secondary px-5 py-2.5 rounded-xl text-sm font-semibold">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white shadow-lg transition-all" style="background:#dc2626;box-shadow:0 4px 14px rgba(220,38,38,0.35)" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#dc2626'" onclick="return confirm('Are you sure you want to reject this withdrawal and refund the user?');">Reject & Refund</button>
            </div>
        </form>
    </div>
</div>

<style>
/* Status Badges */
.status-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
}
.status-dot {
    width: 6px; height: 6px; border-radius: 50%;
}
.status-pending {
    background: rgba(245,158,11,0.1); color: #d97706;
}
.status-pending .status-dot { background: #d97706; }
.status-approved {
    background: rgba(16,185,129,0.1); color: #059669;
}
.status-approved .status-dot { background: #059669; }
.status-rejected {
    background: rgba(239,68,68,0.1); color: #dc2626;
}
.status-rejected .status-dot { background: #dc2626; }
html.dark .status-pending { background: rgba(245,158,11,0.15); color: #fbbf24; }
html.dark .status-approved { background: rgba(16,185,129,0.15); color: #34d399; }
html.dark .status-rejected { background: rgba(239,68,68,0.15); color: #f87171; }

/* Action Buttons */
.action-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 8px;
    transition: all 0.15s ease;
}
.action-btn-approve {
    background: rgba(16,185,129,0.1); color: #059669;
}
.action-btn-approve:hover {
    background: rgba(16,185,129,0.2);
    box-shadow: 0 2px 8px rgba(16,185,129,0.2);
}
.action-btn-reject {
    background: rgba(239,68,68,0.1); color: #dc2626;
}
.action-btn-reject:hover {
    background: rgba(239,68,68,0.2);
    box-shadow: 0 2px 8px rgba(239,68,68,0.2);
}
html.dark .action-btn-approve { background: rgba(16,185,129,0.15); color: #34d399; }
html.dark .action-btn-approve:hover { background: rgba(16,185,129,0.25); }
html.dark .action-btn-reject { background: rgba(239,68,68,0.15); color: #f87171; }
html.dark .action-btn-reject:hover { background: rgba(239,68,68,0.25); }

/* Stat icon */
.stat-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

/* Table row hover */
tbody tr:hover {
    background: var(--color-border-light, #f8fafc);
}
html.dark tbody tr:hover {
    background: rgba(99,102,241,0.04);
}
</style>

<script>
function openRejectModal(id) {
    document.getElementById('reject_tx_id').value = id;
    document.getElementById('rejectModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeRejectModal() {
    document.getElementById('rejectModal').classList.add('hidden');
    document.body.style.overflow = '';
}

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeRejectModal();
});
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
