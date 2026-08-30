<?php
require_once __DIR__ . '/../config/db.php';

echo "=== STARTING REVERT STANDARDIZED STATUSES MIGRATION ===\n\n";

// 1. Revert JOBS table status ENUM definition
echo "1. Reverting JOBS table status ENUM...\n";
$conn->query("ALTER TABLE jobs MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'open'");
$conn->query("ALTER TABLE jobs MODIFY COLUMN status ENUM('open','in_review','hired','in_progress','completed','cancelled','closed','expired','approved') NOT NULL DEFAULT 'open'");
echo "✓ JOBS table status ENUM reverted.\n";

// 2. Revert ASSIGNMENTS table status ENUM definition
echo "2. Reverting ASSIGNMENTS table status ENUM...\n";
$conn->query("ALTER TABLE assignments MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'assigned'");

$conn->query("UPDATE assignments SET status = 'assigned' WHERE status = 'not_started'");
$conn->query("UPDATE assignments SET status = 'working' WHERE status = 'in_progress'");

$conn->query("ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned','working','submitted','completed','rejected','cancelled','overdue','extended','payment_pending') NOT NULL DEFAULT 'assigned'");
echo "✓ ASSIGNMENTS table status ENUM reverted.\n";

// 3. Revert MILESTONES table status ENUM definition
echo "3. Reverting MILESTONES table status ENUM...\n";
$conn->query("ALTER TABLE milestones MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'draft'");

$conn->query("UPDATE milestones SET status = 'funded' WHERE status = 'in_progress'");
$conn->query("UPDATE milestones SET status = 'paid' WHERE status = 'completed'");
$conn->query("UPDATE milestones SET status = 'draft' WHERE status IN ('pending', '', '0') OR status IS NULL");

$conn->query("ALTER TABLE milestones MODIFY COLUMN status ENUM('draft','funded','in_progress','submitted','approved','revision_requested','overdue','cancelled','payment_pending','paid') NOT NULL DEFAULT 'draft'");
echo "✓ MILESTONES table status ENUM reverted.\n";

echo "\n=== REVERT MIGRATION COMPLETED SUCCESSFULLY! ===\n";
