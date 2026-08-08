<?php
require 'config/db.php';

$queries = [
    "ALTER TABLE messages ADD COLUMN is_edited TINYINT(1) DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0",
    "ALTER TABLE messages ADD COLUMN hidden_for VARCHAR(255) DEFAULT NULL"
];

foreach ($queries as $q) {
    if ($conn->query($q)) {
        echo "Success: $q<br>";
    } else {
        echo "Error or already exists: " . $conn->error . "<br>";
    }
}
