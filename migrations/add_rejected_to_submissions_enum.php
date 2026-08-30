<?php
require_once __DIR__ . '/../config/db.php';

$conn->query("ALTER TABLE submissions MODIFY COLUMN status ENUM('pending', 'revision_requested', 'approved', 'rejected') DEFAULT 'pending'");
$conn->query("ALTER TABLE milestones MODIFY COLUMN status ENUM('draft','funded','in_progress','submitted','approved','revision_requested','payment_pending','paid','overdue','cancelled','rejected') DEFAULT 'draft'");

echo "Updated submissions and milestones status ENUMs successfully.\n";
