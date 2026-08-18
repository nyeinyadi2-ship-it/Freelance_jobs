<?php
require_once __DIR__ . '/../config/db.php';

try {
    $conn->query("ALTER TABLE payments ADD COLUMN transaction_slip VARCHAR(255) DEFAULT NULL AFTER transaction_reference");
    echo "Added transaction_slip to payments table successfully.\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column transaction_slip already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
