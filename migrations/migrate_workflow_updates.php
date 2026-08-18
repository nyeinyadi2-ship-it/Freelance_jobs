<?php
require_once __DIR__ . '/../config/db.php';

try {
    // Add rejection_reason to assignments
    $res = $conn->query("SHOW COLUMNS FROM assignments LIKE 'rejection_reason'");
    if ($res->num_rows === 0) {
        $conn->query("ALTER TABLE assignments ADD COLUMN rejection_reason TEXT DEFAULT NULL AFTER status");
        echo "Added rejection_reason to assignments.\n";
    } else {
        echo "Column rejection_reason already exists in assignments.\n";
    }

    // Add revision_notes to milestones
    $res2 = $conn->query("SHOW COLUMNS FROM milestones LIKE 'revision_notes'");
    if ($res2->num_rows === 0) {
        $conn->query("ALTER TABLE milestones ADD COLUMN revision_notes TEXT DEFAULT NULL AFTER submission_note");
        echo "Added revision_notes to milestones.\n";
    } else {
        echo "Column revision_notes already exists in milestones.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
