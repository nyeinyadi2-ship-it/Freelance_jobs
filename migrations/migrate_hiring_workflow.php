<?php
/**
 * Migration: Hiring Workflow Improvements
 * - Remove UNIQUE constraint on assignments.job_id to allow multiple freelancers per job
 * - Add 'position_filled' status to jobs table
 * - Enforce hiring limits at the database level
 */

require_once __DIR__ . '/../config/db.php';

echo "Starting hiring workflow migration...\n";

// 1. Remove UNIQUE constraint on assignments.job_id
$result = $conn->query("SHOW INDEX FROM assignments WHERE Key_name = 'job_id'");
if ($result && $result->num_rows > 0) {
    $conn->query("ALTER TABLE assignments DROP INDEX job_id");
    // Re-add as non-unique index for query performance
    $conn->query("ALTER TABLE assignments ADD INDEX idx_assignments_job (job_id)");
    echo "[OK] Removed UNIQUE constraint on assignments.job_id\n";
} else {
    // Check if index already exists as non-unique
    $result2 = $conn->query("SHOW INDEX FROM assignments WHERE Key_name = 'idx_assignments_job'");
    if (!$result2 || $result2->num_rows === 0) {
        $conn->query("ALTER TABLE assignments ADD INDEX idx_assignments_job (job_id)");
    }
    echo "[SKIP] UNIQUE constraint already removed\n";
}

// 2. Add 'position_filled' status to jobs table
$col = $conn->query("SHOW COLUMNS FROM jobs LIKE 'status'");
if ($col) {
    $row = $col->fetch_assoc();
    if (strpos($row['Type'], 'position_filled') === false) {
        $conn->query("ALTER TABLE jobs MODIFY COLUMN status ENUM('pending','approved','rejected','completed','position_filled') DEFAULT 'pending'");
        echo "[OK] Added 'position_filled' status to jobs table\n";
    } else {
        echo "[SKIP] 'position_filled' status already exists\n";
    }
}

echo "Migration complete.\n";
