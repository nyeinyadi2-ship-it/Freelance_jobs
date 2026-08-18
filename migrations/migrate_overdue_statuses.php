<?php
require_once __DIR__ . '/../config/db.php';

echo "Running milestones and proposal_projects status enum migration...\n";

// Alter milestones.status
$sql1 = "ALTER TABLE milestones MODIFY COLUMN status ENUM('draft', 'funded', 'in_progress', 'submitted', 'approved', 'revision_requested', 'payment_pending', 'paid', 'overdue') COLLATE utf8mb4_unicode_ci DEFAULT 'draft'";
if ($conn->query($sql1)) {
    echo "Successfully updated milestones status ENUM to include 'overdue'.\n";
} else {
    echo "Error updating milestones status ENUM: " . $conn->error . "\n";
}

// Alter proposal_projects.status
$sql2 = "ALTER TABLE proposal_projects MODIFY COLUMN status ENUM('pending', 'accepted', 'rejected', 'submitted', 'reviewed', 'hired', 'overdue') COLLATE utf8mb4_unicode_ci DEFAULT 'pending'";
if ($conn->query($sql2)) {
    echo "Successfully updated proposal_projects status ENUM to include 'overdue'.\n";
} else {
    echo "Error updating proposal_projects status ENUM: " . $conn->error . "\n";
}

$conn->close();
echo "Migration complete.\n";
