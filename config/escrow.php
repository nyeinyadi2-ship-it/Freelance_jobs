<?php

/**
 * Get earnings statistics for a freelancer.
 */
function get_freelancer_earnings_stats(mysqli $conn, int $freelancer_id): array
{
    $stats = [
        'total_earned' => 0,
        'total_pending' => 0,
        'total_released' => 0,
        'total_refunded' => 0,
        'available_balance' => 0,
        'pending_balance' => 0,
        'total_earnings' => 0,
        'total_withdrawn' => 0,
    ];

    $stmt = $conn->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN p.escrow_status = 'released' THEN p.amount ELSE 0 END), 0) AS total_released,
            COALESCE(SUM(CASE WHEN p.escrow_status IN ('funded','in_progress','submitted','revision_requested','approved') THEN p.amount ELSE 0 END), 0) AS total_pending,
            COALESCE(SUM(CASE WHEN p.escrow_status = 'refunded' THEN p.amount ELSE 0 END), 0) AS total_refunded,
            COALESCE(SUM(p.amount), 0) AS total_earned
        FROM payments p
        WHERE p.freelancer_id = ?
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $stats['total_earned'] = (float) $row['total_earned'];
        $stats['total_pending'] = (float) $row['total_pending'];
        $stats['total_released'] = (float) $row['total_released'];
        $stats['total_refunded'] = (float) $row['total_refunded'];
        $stats['total_earnings'] = (float) $row['total_released']; // Alias for earnings.php "Total received"
    }

    $stmt2 = $conn->prepare("SELECT COALESCE(available_balance, 0) FROM freelancers WHERE id = ?");
    $stmt2->bind_param('i', $freelancer_id);
    $stmt2->execute();
    $bal = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    if ($bal) {
        $stats['available_balance'] = (float) ($bal['available_balance'] ?? 0);
    }
    
    // Get withdrawal stats
    $stmt3 = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_balance,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0) AS total_withdrawn
        FROM withdraw_requests 
        WHERE freelancer_id = ?
    ");
    $stmt3->bind_param('i', $freelancer_id);
    $stmt3->execute();
    $w = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();
    if ($w) {
        $stats['pending_balance'] = (float) $w['pending_balance'];
        $stats['total_withdrawn'] = (float) $w['total_withdrawn'];
    }

    return $stats;
}

/**
 * Render a styled badge for an escrow status.
 */
function escrow_status_badge(string $status): string
{
    $map = [
        'pending' => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-600 dark:text-slate-300', 'label' => 'Pending'],
        'funded' => ['bg' => 'bg-blue-50 dark:bg-blue-900/30', 'text' => 'text-blue-700 dark:text-blue-400', 'label' => 'Funded'],
        'in_progress' => ['bg' => 'bg-indigo-50 dark:bg-indigo-900/30', 'text' => 'text-indigo-700 dark:text-indigo-400', 'label' => 'In Progress'],
        'submitted' => ['bg' => 'bg-purple-50 dark:bg-purple-900/30', 'text' => 'text-purple-700 dark:text-purple-400', 'label' => 'Submitted'],
        'revision_requested' => ['bg' => 'bg-amber-50 dark:bg-amber-900/30', 'text' => 'text-amber-700 dark:text-amber-400', 'label' => 'Revision Requested'],
        'approved' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/30', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Approved'],
        'released' => ['bg' => 'bg-emerald-50 dark:bg-emerald-900/30', 'text' => 'text-emerald-700 dark:text-emerald-400', 'label' => 'Released'],
        'refunded' => ['bg' => 'bg-red-50 dark:bg-red-900/30', 'text' => 'text-red-700 dark:text-red-400', 'label' => 'Refunded'],
        'cancelled' => ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'label' => 'Cancelled'],
    ];

    $info = $map[$status] ?? ['bg' => 'bg-slate-100 dark:bg-slate-800', 'text' => 'text-slate-500 dark:text-slate-400', 'label' => ucfirst(str_replace('_', ' ', $status))];

    return '<span class="inline-flex px-2.5 py-1 text-xs font-semibold rounded-full ' . $info['bg'] . ' ' . $info['text'] . '">' . e($info['label']) . '</span>';
}
