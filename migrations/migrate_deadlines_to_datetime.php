<?php
require_once __DIR__ . '/../config/db.php';

echo "Running deadlines to DATETIME migration...\n";

// Alter assignments.deadline
$sql1 = "ALTER TABLE assignments MODIFY COLUMN deadline DATETIME DEFAULT NULL";
if ($conn->query($sql1)) {
    echo "Successfully updated assignments deadline to DATETIME.\n";
} else {
    echo "Error updating assignments deadline: " . $conn->error . "\n";
}

// Alter milestones.deadline
$sql2 = "ALTER TABLE milestones MODIFY COLUMN deadline DATETIME DEFAULT NULL";
if ($conn->query($sql2)) {
    echo "Successfully updated milestones deadline to DATETIME.\n";
} else {
    echo "Error updating milestones deadline: " . $conn->error . "\n";
}

$conn->close();
echo "Migration complete.\n";
