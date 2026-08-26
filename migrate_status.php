<?php
require 'config/db.php';

echo "Starting migration...\n";

// 1. Alter enum to include all old and new values
$sql1 = "ALTER TABLE proposal_projects MODIFY COLUMN status ENUM('pending','accepted','rejected','submitted','reviewed','hired','overdue','cancelled','assigned','in_progress','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'assigned'";
if ($conn->query($sql1)) {
    echo "1. ENUM expanded successfully.\n";
} else {
    die("Error expanding ENUM: " . $conn->error);
}

// 2. Migrate existing data
$queries = [
    "UPDATE proposal_projects SET status = 'assigned' WHERE status = 'pending'",
    "UPDATE proposal_projects SET status = 'in_progress' WHERE status = 'accepted'",
    "UPDATE proposal_projects SET status = 'approved' WHERE status IN ('reviewed', 'hired')"
];

foreach ($queries as $i => $q) {
    if ($conn->query($q)) {
        echo "2.".($i+1)." Update query successful.\n";
    } else {
        die("Error on update query $i: " . $conn->error);
    }
}

// 3. Alter enum to strictly allow only new values
$sql3 = "ALTER TABLE proposal_projects MODIFY COLUMN status ENUM('assigned','in_progress','submitted','approved','rejected','overdue','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'assigned'";
if ($conn->query($sql3)) {
    echo "3. ENUM restricted successfully.\n";
} else {
    die("Error restricting ENUM: " . $conn->error);
}

echo "Migration completed successfully!\n";
