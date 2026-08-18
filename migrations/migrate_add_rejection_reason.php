<?php
require_once __DIR__ . '/../config/db.php';

try {
    $conn->query("ALTER TABLE assignments ADD COLUMN rejection_reason TEXT DEFAULT NULL");
    echo "Added rejection_reason to assignments table successfully.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column rejection_reason already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
