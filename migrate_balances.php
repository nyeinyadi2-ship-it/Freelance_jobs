<?php
require 'config/db.php';

$queries = [
    "ALTER TABLE users ADD COLUMN available_balance decimal(10,2) DEFAULT 0.00",
    "ALTER TABLE users ADD COLUMN demo_funds decimal(10,2) DEFAULT 0.00"
];

foreach ($queries as $q) {
    if ($conn->query($q)) {
        echo "Success: $q\n";
    } else {
        echo "Error: " . $conn->error . "\n";
    }
}
