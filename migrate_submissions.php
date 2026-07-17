<?php
require_once __DIR__ . '/config/db.php';

// Check if columns already exist
$check = $conn->query("SHOW COLUMNS FROM milestones LIKE 'submission_file'");
$has_file = $check && $check->num_rows > 0;

$check2 = $conn->query("SHOW COLUMNS FROM milestones LIKE 'submission_note'");
$has_note = $check2 && $check2->num_rows > 0;

if (!$has_file) {
    $conn->query("ALTER TABLE milestones ADD COLUMN submission_file VARCHAR(255) DEFAULT NULL AFTER submission_link");
    echo "Added submission_file column.\n";
} else {
    echo "submission_file column already exists.\n";
}

if (!$has_note) {
    $conn->query("ALTER TABLE milestones ADD COLUMN submission_note TEXT DEFAULT NULL AFTER submission_file");
    echo "Added submission_note column.\n";
} else {
    echo "submission_note column already exists.\n";
}

// Also create uploads/submissions directory
$dir = __DIR__ . '/uploads/submissions';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    echo "Created uploads/submissions/ directory.\n";
}

echo "Migration complete.\n";
$conn->close();
