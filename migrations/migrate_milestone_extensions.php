<?php
/**
 * Migration: Create milestone_extensions table for tracking deadline extension requests.
 * Also adds rejection_reason column to milestones if not present.
 */
require_once __DIR__ . '/../config/db.php';

echo "Running migration: migrate_milestone_extensions\n";

// Create milestone_extensions table (without foreign keys for safety)
$conn->query("
    CREATE TABLE IF NOT EXISTS milestone_extensions (
        id INT NOT NULL AUTO_INCREMENT,
        milestone_id INT NOT NULL,
        freelancer_id INT NOT NULL,
        current_deadline DATETIME NOT NULL,
        requested_deadline DATETIME NOT NULL,
        reason TEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        company_note TEXT DEFAULT NULL,
        reviewed_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ext_milestone (milestone_id),
        KEY idx_ext_freelancer (freelancer_id),
        KEY idx_ext_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
");
echo "  - milestone_extensions table created (or already exists)\n";

// Add rejection_reason to milestones if not present
$check = $conn->query("SHOW COLUMNS FROM milestones LIKE 'rejection_reason'");
if ($check->num_rows === 0) {
    $conn->query("ALTER TABLE milestones ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER submission_note");
    echo "  - Added rejection_reason column to milestones\n";
} else {
    echo "  - rejection_reason column already exists\n";
}

// Add extension_reason to milestones if not present
$check2 = $conn->query("SHOW COLUMNS FROM milestones LIKE 'extension_reason'");
if ($check2->num_rows === 0) {
    $conn->query("ALTER TABLE milestones ADD COLUMN extension_reason TEXT DEFAULT NULL AFTER rejection_reason");
    echo "  - Added extension_reason column to milestones\n";
} else {
    echo "  - extension_reason column already exists\n";
}

echo "Migration complete.\n";
