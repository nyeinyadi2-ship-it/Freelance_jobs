<?php
require_once __DIR__ . '/config/db.php';

$queries = [
    "ALTER TABLE jobs ADD INDEX idx_jobs_status (status)",
    "ALTER TABLE jobs ADD INDEX idx_jobs_category (category)",
    "ALTER TABLE job_applications ADD INDEX idx_ja_status (status)",
    "ALTER TABLE assignments ADD INDEX idx_assignments_status (status)"
];

foreach ($queries as $q) {
    try {
        $conn->query($q);
        echo "Successfully ran: $q\n";
    } catch (Exception $e) {
        echo "Failed or already exists: $q - " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
