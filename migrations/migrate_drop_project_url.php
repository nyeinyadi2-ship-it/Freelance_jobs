<?php
/**
 * Migration: Drop project_url column from submissions table.
 *
 * project_url was never populated (0 rows with data) and has no active
 * INSERT path. github_link remains untouched.
 *
 * Safe to run multiple times (idempotent via SHOW COLUMNS check).
 */
require_once __DIR__ . '/../config/db.php';

echo "Running migration: migrate_drop_project_url\n";

$chk = $conn->query("SHOW COLUMNS FROM submissions LIKE 'project_url'");
if ($chk && $chk->num_rows > 0) {
    // Confirm no data would be lost
    $cnt = $conn->query("SELECT COUNT(project_url) AS c FROM submissions")->fetch_assoc();
    echo "  Rows with project_url data: " . $cnt['c'] . "\n";

    if ($conn->query("ALTER TABLE submissions DROP COLUMN project_url")) {
        echo "  Dropped column: project_url\n";
    } else {
        echo "  ERROR: " . $conn->error . "\n";
        exit(1);
    }
} else {
    echo "  Column project_url does not exist — already dropped or never created.\n";
}

// Verify
echo "\n=== Verification ===\n";
$cols = $conn->query("SHOW COLUMNS FROM submissions");
while ($row = $cols->fetch_assoc()) {
    echo "  " . $row['Field'] . " | " . $row['Type'] . "\n";
}

$gone = $conn->query("SHOW COLUMNS FROM submissions LIKE 'project_url'");
echo "\nproject_url: " . ($gone->num_rows === 0 ? "DROPPED ✓" : "STILL EXISTS!") . "\n";
$kept = $conn->query("SHOW COLUMNS FROM submissions LIKE 'github_link'");
echo "github_link: " . ($kept->num_rows > 0 ? "PRESENT ✓" : "MISSING!") . "\n";

echo "\nMigration completed successfully.\n";
