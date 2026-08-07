<?php
require_once __DIR__ . '/../config/db.php';

try {
    $conn->begin_transaction();

    // 1. Alter jobs table ENUM to include all requested statuses
    $sql = "ALTER TABLE jobs MODIFY COLUMN status ENUM('pending', 'open', 'in_progress', 'submitted', 'completed', 'position_filled', 'expired', 'closed', 'approved', 'rejected') DEFAULT 'pending'";
    if (!$conn->query($sql)) {
        throw new Exception("Error modifying jobs table status ENUM: " . $conn->error);
    }
    echo "Jobs table status ENUM modified successfully.\n";

    // 2. Migrate existing 'approved' jobs to 'open'
    // The previous design used 'approved' to signify a job that is open and available.
    $sql = "UPDATE jobs SET status = 'open' WHERE status = 'approved'";
    if (!$conn->query($sql)) {
        throw new Exception("Error updating existing 'approved' jobs to 'open': " . $conn->error);
    }
    $affected = $conn->affected_rows;
    echo "Migrated $affected existing jobs from 'approved' to 'open'.\n";

    $conn->commit();
    echo "\nMigration completed successfully!\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "\nMigration failed: " . $e->getMessage() . "\n";
}
