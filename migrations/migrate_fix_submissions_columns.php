<?php
require_once __DIR__ . '/../config/db.php';

// Defensive migration: ensures submission_file and submission_note exist on milestones table.
// Safe to run multiple times (idempotent). Fixes DBs where migrate_milestones.php
// dropped the table without these columns.

$columns = [
    'submission_file' => "ALTER TABLE milestones ADD COLUMN submission_file VARCHAR(255) DEFAULT NULL AFTER submission_link",
    'submission_note'  => "ALTER TABLE milestones ADD COLUMN submission_note TEXT DEFAULT NULL AFTER submission_file",
];

$added = 0;
$skipped = 0;

foreach ($columns as $col => $sql) {
    $check = $conn->query("SHOW COLUMNS FROM milestones LIKE '$col'");
    if ($check && $check->num_rows > 0) {
        echo "$col already exists.\n";
        $skipped++;
    } else {
        if ($conn->query($sql)) {
            echo "Added $col column.\n";
            $added++;
        } else {
            echo "Error adding $col: " . $conn->error . "\n";
        }
    }
}

// Also ensure submitted_at exists
$check = $conn->query("SHOW COLUMNS FROM milestones LIKE 'submitted_at'");
if (!$check || $check->num_rows === 0) {
    $conn->query("ALTER TABLE milestones ADD COLUMN submitted_at TIMESTAMP NULL AFTER submission_note");
    echo "Added submitted_at column.\n";
    $added++;
}

echo "Fix migration complete: $added added, $skipped already existed.\n";
$conn->close();
