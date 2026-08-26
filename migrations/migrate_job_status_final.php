<?php
require_once 'c:/wamp64/www/freelancer_job/config/db.php';

echo "Starting DB Migration...\n";

// 1. Temporarily change column to VARCHAR to avoid enum strict mode errors
$conn->query("ALTER TABLE jobs MODIFY COLUMN status VARCHAR(50) DEFAULT 'open'");
echo "Changed status column to VARCHAR.\n";

// 2. Perform data mapping updates
$mappings = [
    'open' => ['pending', 'approved', 'open'],
    'hired' => ['position_filled'],
    'in_progress' => ['in_progress'],
    'completed' => ['submitted', 'completed', 'payment_pending', 'paid'],
    'cancelled' => ['cancelled'],
    'closed' => ['expired', 'closed', 'rejected'],
];

foreach ($mappings as $new_status => $old_statuses) {
    $in_clause = "'" . implode("','", $old_statuses) . "'";
    $sql = "UPDATE jobs SET status = '$new_status' WHERE status IN ($in_clause)";
    if ($conn->query($sql)) {
        echo "Mapped " . implode(", ", $old_statuses) . " to $new_status: " . $conn->affected_rows . " rows updated.\n";
    } else {
        echo "Error mapping to $new_status: " . $conn->error . "\n";
    }
}

// 3. Any stragglers get mapped to open
$conn->query("UPDATE jobs SET status = 'open' WHERE status NOT IN ('open', 'in_review', 'hired', 'in_progress', 'completed', 'cancelled', 'closed')");

// 4. Change column to the new restricted ENUM
$new_enum = "ENUM('open', 'in_review', 'hired', 'in_progress', 'completed', 'cancelled', 'closed')";
if ($conn->query("ALTER TABLE jobs MODIFY COLUMN status $new_enum DEFAULT 'open'")) {
    echo "Successfully updated ENUM constraint!\n";
} else {
    echo "Error updating ENUM: " . $conn->error . "\n";
}

echo "Migration complete.\n";
