<?php
/**
 * Migration: Change milestones.deadline to DATETIME
 */
require_once __DIR__ . '/../config/db.php';

echo "Running milestone deadline DATETIME migration...\n";

// Ensure 'deadline' is DATETIME
$sql = "ALTER TABLE milestones MODIFY COLUMN deadline DATETIME DEFAULT NULL";
if ($conn->query($sql)) {
    echo "  OK: milestones.deadline updated to DATETIME\n";
} else {
    echo "  ERROR: " . $conn->error . "\n";
}
