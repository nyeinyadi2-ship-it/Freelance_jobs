<?php
/**
 * Migration: Add deadline column to milestones table
 */
require_once __DIR__ . '/config/db.php';

echo "Running milestone deadline migration...\n";

// Check if deadline column exists
$result = $conn->query("SHOW COLUMNS FROM milestones LIKE 'deadline'");
if ($result && $result->num_rows > 0) {
    echo "  SKIP: deadline column already exists\n";
} else {
    try {
        $conn->query("ALTER TABLE milestones ADD COLUMN deadline DATE DEFAULT NULL AFTER amount");
        echo "  OK: Added deadline column to milestones\n";
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}

echo "Migration complete.\n";
$conn->close();
?>
