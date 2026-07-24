<?php
/**
 * Migration: Add Direct Hire columns to assignments table
 */
require_once __DIR__ . '/../config/db.php';

echo "Running Direct Hire migration...\n";

// Check existing columns
$result = $conn->query("SHOW COLUMNS FROM assignments");
$existing = [];
while ($row = $result->fetch_assoc()) {
    $existing[] = $row['Field'];
}

$columns_to_add = [
    'assignment_type' => "ALTER TABLE assignments ADD COLUMN assignment_type ENUM('job_apply','direct_hire') DEFAULT 'job_apply' AFTER freelancer_id",
    'freelancer_response' => "ALTER TABLE assignments ADD COLUMN freelancer_response ENUM('pending','accepted','rejected') DEFAULT NULL AFTER status",
    'project_title' => "ALTER TABLE assignments ADD COLUMN project_title VARCHAR(255) DEFAULT NULL AFTER freelancer_response",
    'project_description' => "ALTER TABLE assignments ADD COLUMN project_description TEXT DEFAULT NULL AFTER project_title",
    'budget' => "ALTER TABLE assignments ADD COLUMN budget DECIMAL(10,2) DEFAULT NULL AFTER project_description",
    'deadline' => "ALTER TABLE assignments ADD COLUMN deadline DATE DEFAULT NULL AFTER budget",
    'payment_type' => "ALTER TABLE assignments ADD COLUMN payment_type ENUM('fixed','milestone') DEFAULT 'fixed' AFTER deadline",
];

$success = 0;
$skipped = 0;

foreach ($columns_to_add as $col => $sql) {
    if (in_array($col, $existing)) {
        echo "  SKIP: $col (already exists)\n";
        $skipped++;
        continue;
    }
    try {
        $conn->query($sql);
        echo "  OK: Added $col\n";
        $success++;
    } catch (Exception $e) {
        echo "  ERROR on $col: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete: $success added, $skipped already existed\n";
$conn->close();
?>
