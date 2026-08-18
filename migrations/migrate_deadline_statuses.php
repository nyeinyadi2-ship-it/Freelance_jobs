<?php
require_once __DIR__ . '/../config/db.php';

echo "Running assignments status enum migration...\n";

$sql = "ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned', 'working', 'submitted', 'completed', 'rejected', 'overdue', 'extended', 'cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'assigned'";

if ($conn->query($sql)) {
    echo "Successfully updated assignments status ENUM to include 'overdue', 'extended', 'cancelled'.\n";
} else {
    echo "Error updating assignments status ENUM: " . $conn->error . "\n";
}

$conn->close();
echo "Migration complete.\n";
