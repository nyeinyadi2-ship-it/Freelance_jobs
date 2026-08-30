<?php
require_once __DIR__ . '/../config/db.php';

echo "=== EXECUTING APPROVED SCHEMA ALIGNMENT ===\n\n";

// 1. proposal_projects
echo "1. Aligning proposal_projects table...\n";
$conn->query("UPDATE proposal_projects SET status = 'accepted' WHERE status = 'approved'");
echo "   - Updated legacy 'approved' rows to 'accepted': " . $conn->affected_rows . " rows.\n";
$conn->query("ALTER TABLE proposal_projects MODIFY COLUMN status ENUM('pending','assigned','accepted','in_progress','submitted','reviewed','rejected','hired','overdue','cancelled') NOT NULL DEFAULT 'pending'");
echo "   - Column ENUM definition updated.\n";

// 2. submissions
echo "2. Aligning submissions table...\n";
$conn->query("UPDATE submissions SET status = 'pending' WHERE status = '' OR status IS NULL");
echo "   - Updated empty status rows to 'pending': " . $conn->affected_rows . " rows.\n";
$conn->query("UPDATE submissions SET status = 'revision_requested' WHERE status = 'rejected'");
echo "   - Updated legacy 'rejected' rows to 'revision_requested': " . $conn->affected_rows . " rows.\n";
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("ALTER TABLE submissions MODIFY COLUMN status ENUM('pending','revision_requested','approved','submitted') NOT NULL DEFAULT 'pending'");
$conn->query("SET FOREIGN_KEY_CHECKS=1");
echo "   - Column ENUM definition updated.\n";

// 3. payments
echo "3. Aligning payments table...\n";
$conn->query("SET FOREIGN_KEY_CHECKS=0");
$conn->query("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','paid') NOT NULL DEFAULT 'pending'");
$conn->query("SET FOREIGN_KEY_CHECKS=1");
echo "   - Column ENUM definition updated.\n";

echo "\n=== APPROVED SCHEMA ALIGNMENT COMPLETED SUCCESSFULLY ===\n";
