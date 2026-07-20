<?php
require_once __DIR__ . '/config/db.php';

// Diagnostic: show existing tables
$tables = $conn->query("SHOW TABLES");
$existing = [];
while ($row = $tables->fetch_row()) { $existing[] = $row[0]; }
echo "Existing tables: " . implode(', ', $existing) . "\n";

// Drop broken tables if they exist
$conn->query("DROP TABLE IF EXISTS escrow");
$conn->query("DROP TABLE IF EXISTS milestones");

$queries = [

"CREATE TABLE milestones (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    amount DECIMAL(10,2) NOT NULL,
    deadline DATE DEFAULT NULL,
    status ENUM('draft','funded','in_progress','submitted','approved','revision_requested') DEFAULT 'draft',
    submission_link VARCHAR(255) DEFAULT NULL,
    submission_file VARCHAR(255) DEFAULT NULL,
    submission_note TEXT DEFAULT NULL,
    submitted_at TIMESTAMP NULL,
    approved_at TIMESTAMP NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_milestones_job (job_id),
    INDEX idx_milestones_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

"CREATE TABLE escrow (
    id INT PRIMARY KEY AUTO_INCREMENT,
    milestone_id INT UNIQUE NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('held','released','refunded') DEFAULT 'held',
    funded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    released_at TIMESTAMP NULL,
    INDEX idx_escrow_milestone (milestone_id),
    INDEX idx_escrow_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

];

$success = 0;
$errors = 0;
foreach ($queries as $sql) {
    if ($conn->query($sql)) {
        $success++;
    } else {
        echo "Error: " . $conn->error . "\n";
        $errors++;
    }
}

echo "Migration complete: {$success} created, {$errors} errors.\n";
$conn->close();
