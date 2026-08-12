<?php
require_once __DIR__ . '/config/db.php';

$sql = "
    ALTER TABLE freelancers
    ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL,
    ADD COLUMN payment_account_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN payment_account_number VARCHAR(50) DEFAULT NULL,
    ADD COLUMN payment_bank_name VARCHAR(100) DEFAULT NULL
";

if ($conn->query($sql)) {
    echo "Columns added successfully.\n";
} else {
    echo "Error adding columns: " . $conn->error . "\n";
}
