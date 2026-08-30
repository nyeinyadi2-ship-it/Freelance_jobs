<?php
require_once __DIR__ . '/../config/db.php';

echo "=== STARTING STANDARDIZED STATUSES MIGRATION ===\n\n";

// 1. Migrate JOBS table
echo "1. Migrating JOBS table status...\n";
$conn->query("ALTER TABLE jobs MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'open'");

$conn->query("UPDATE jobs SET status = 'hired' WHERE status IN ('in_progress')");
$conn->query("UPDATE jobs SET status = 'closed' WHERE status IN ('completed', 'expired', 'cancelled')");

$conn->query("ALTER TABLE jobs MODIFY COLUMN status ENUM('open', 'hired', 'closed') NOT NULL DEFAULT 'open'");
echo "✓ JOBS status migration complete.\n";

// 2. Migrate ASSIGNMENTS table
echo "2. Migrating ASSIGNMENTS table status...\n";
$conn->query("ALTER TABLE assignments MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'not_started'");

$conn->query("UPDATE assignments SET status = 'not_started' WHERE status IN ('assigned', '', 'pending')");
$conn->query("UPDATE assignments SET status = 'in_progress' WHERE status IN ('working', 'overdue', 'extended')");

// Handle submitted assignments individually based on milestone completion
$res_sub = $conn->query("SELECT id, job_id FROM assignments WHERE status = 'submitted'");
if ($res_sub) {
    while ($asgn = $res_sub->fetch_assoc()) {
        $asgn_id = (int)$asgn['id'];
        $job_id = (int)$asgn['job_id'];
        
        $ms_res = $conn->query("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status IN ('approved', 'paid', 'payment_pending', 'completed') THEN 1 ELSE 0 END) AS done
            FROM milestones WHERE job_id = {$job_id}
        ");
        $ms_info = $ms_res ? $ms_res->fetch_assoc() : ['total' => 0, 'done' => 0];
        $total = (int)($ms_info['total'] ?? 0);
        $done = (int)($ms_info['done'] ?? 0);
        
        $new_st = ($total > 0 && $done === $total) ? 'completed' : 'in_progress';
        $conn->query("UPDATE assignments SET status = '{$new_st}' WHERE id = {$asgn_id}");
    }
}

$conn->query("ALTER TABLE assignments MODIFY COLUMN status ENUM('not_started', 'in_progress', 'completed', 'cancelled', 'rejected') NOT NULL DEFAULT 'not_started'");
echo "✓ ASSIGNMENTS status migration complete.\n";

// 3. Migrate MILESTONES table
echo "3. Migrating MILESTONES table status...\n";
$conn->query("ALTER TABLE milestones MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'pending'");

$conn->query("UPDATE milestones SET status = 'pending' WHERE status IN ('draft', '')");
$conn->query("UPDATE milestones SET status = 'in_progress' WHERE status IN ('funded')");
$conn->query("UPDATE milestones SET status = 'completed' WHERE status IN ('approved', 'payment_pending', 'paid')");

$conn->query("ALTER TABLE milestones MODIFY COLUMN status ENUM('pending', 'in_progress', 'submitted', 'revision_requested', 'completed', 'overdue', 'cancelled', 'rejected') NOT NULL DEFAULT 'pending'");
echo "✓ MILESTONES status migration complete.\n";

echo "\n=== MIGRATION COMPLETED SUCCESSFULLY! ===\n";
