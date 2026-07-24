<?php
require_once __DIR__ . '/../config/db.php';

echo "Fixing escrow payment flow bugs...\n";

// 1. Remove UNIQUE constraint on payments.assignment_id (allows multiple milestone payments per job)
$check = $conn->query("SHOW INDEX FROM payments WHERE Column_name = 'assignment_id' AND Non_unique = 0");
if ($check && $check->num_rows > 0) {
    $conn->query("ALTER TABLE payments DROP INDEX assignment_id");
    echo "1. Removed UNIQUE constraint on payments.assignment_id\n";
} else {
    echo "1. payments.assignment_id UNIQUE constraint already removed\n";
}

// 2. Remove UNIQUE constraint on reviews.assignment_id (allows multiple reviews per job)
$check2 = $conn->query("SHOW INDEX FROM reviews WHERE Column_name = 'assignment_id' AND Non_unique = 0");
if ($check2 && $check2->num_rows > 0) {
    $conn->query("ALTER TABLE reviews DROP INDEX assignment_id");
    echo "2. Removed UNIQUE constraint on reviews.assignment_id\n";
} else {
    echo "2. reviews.assignment_id UNIQUE constraint already removed\n";
}

// 3. Ensure uploads/submissions directory exists
$dir = __DIR__ . '/uploads/submissions';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
    echo "3. Created uploads/submissions/ directory\n";
} else {
    echo "3. uploads/submissions/ directory already exists\n";
}

echo "\nAll fixes applied.\n";
$conn->close();
