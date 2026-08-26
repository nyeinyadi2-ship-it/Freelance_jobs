<?php
require_once __DIR__ . '/config/db.php'; 

try {
    $conn->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
    $conn->query('ALTER TABLE job_applications ADD COLUMN rejection_reason TEXT DEFAULT NULL');
    echo "Migration successful\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
}
