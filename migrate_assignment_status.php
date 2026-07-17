<?php
require_once __DIR__ . '/config/db.php';

echo "Adding 'working' status to assignments table...\n";

// Check current ENUM values
$result = $conn->query("SHOW COLUMNS FROM assignments LIKE 'status'");
if ($result) {
    $row = $result->fetch_assoc();
    $type = $row['Type'] ?? '';
    echo "Current type: $type\n";

    if (strpos($type, 'working') === false) {
        $conn->query("ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned', 'working', 'submitted', 'completed') DEFAULT 'assigned'");
        echo "Added 'working' status to assignments ENUM.\n";
    } else {
        echo "'working' status already exists.\n";
    }
}

echo "Migration complete.\n";
$conn->close();
