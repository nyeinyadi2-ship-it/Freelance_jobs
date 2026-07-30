<?php
/**
 * Migration: Fix assignments table to support multiple freelancers per job.
 * The original schema had job_id UNIQUE which only allows one assignment per job.
 * This migration removes the UNIQUE constraint to support freelancers_needed > 1.
 * 
 * Run once: php migrate_assignments_multi.php
 */

require_once __DIR__ . '/../config/db.php';

echo "=== Migration: Fix assignments table for multiple freelancers ===\n\n";

// 1. Check if the UNIQUE constraint exists on job_id
$result = $conn->query("SHOW INDEX FROM assignments WHERE Column_name = 'job_id' AND Non_unique = 0");
$has_unique = $result && $result->num_rows > 0;

if ($has_unique) {
    echo "[INFO] Found UNIQUE constraint on job_id. Removing it...\n";
    
    // Find the index name
    $result->data_seek(0);
    $index_row = $result->fetch_assoc();
    $index_name = $index_row['Key_name'];
    
    try {
        $conn->query("ALTER TABLE assignments DROP INDEX `{$index_name}`");
        echo "[OK] Removed UNIQUE constraint '{$index_name}' from job_id.\n";
    } catch (mysqli_sql_exception $e) {
        echo "[ERROR] Failed to drop index: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] No UNIQUE constraint found on job_id. Already fixed.\n";
}

// 2. Add a regular index on job_id for better query performance (if not exists)
$result = $conn->query("SHOW INDEX FROM assignments WHERE Column_name = 'job_id' AND Key_name != 'PRIMARY'");
$has_index = $result && $result->num_rows > 0;

if (!$has_index) {
    try {
        $conn->query("ALTER TABLE assignments ADD INDEX idx_assignments_job_id (job_id)");
        echo "[OK] Added index idx_assignments_job_id on job_id.\n";
    } catch (mysqli_sql_exception $e) {
        echo "[ERROR] Failed to add index: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] Index on job_id already exists.\n";
}

// 3. Also add index on freelancer_id for better query performance
$result = $conn->query("SHOW INDEX FROM assignments WHERE Column_name = 'freelancer_id' AND Key_name != 'PRIMARY'");
$has_index = $result && $result->num_rows > 0;

if (!$has_index) {
    try {
        $conn->query("ALTER TABLE assignments ADD INDEX idx_assignments_freelancer_id (freelancer_id)");
        echo "[OK] Added index idx_assignments_freelancer_id on freelancer_id.\n";
    } catch (mysqli_sql_exception $e) {
        echo "[ERROR] Failed to add index: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] Index on freelancer_id already exists.\n";
}

// 4. Add UNIQUE constraint to prevent duplicate assignments (same freelancer to same job)
$result = $conn->query("SHOW INDEX FROM assignments WHERE Column_name = 'job_id' AND Column_name = 'freelancer_id'");
// Check for composite unique index
$check = $conn->query("SHOW INDEX FROM assignments WHERE Key_name = 'uq_assignment_job_freelancer'");
$has_composite = $check && $check->num_rows > 0;

if (!$has_composite) {
    try {
        $conn->query("ALTER TABLE assignments ADD UNIQUE INDEX uq_assignment_job_freelancer (job_id, freelancer_id)");
        echo "[OK] Added composite unique index to prevent duplicate assignments.\n";
    } catch (mysqli_sql_exception $e) {
        echo "[ERROR] Failed to add composite index: " . $e->getMessage() . "\n";
    }
} else {
    echo "[SKIP] Composite unique index already exists.\n";
}

echo "\n=== Migration complete ===\n";
echo "The assignments table now supports multiple freelancers per job.\n";
echo "Each freelancer can only be assigned once to the same job.\n";
