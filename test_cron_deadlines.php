<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/job_helpers.php';

// First, create dummy overdue data to test
echo "Setting up dummy data for testing...\n";
$conn->query("UPDATE assignments SET deadline = DATE_SUB(NOW(), INTERVAL 1 MINUTE), status = 'assigned' LIMIT 1");
$conn->query("UPDATE milestones SET deadline = DATE_SUB(NOW(), INTERVAL 1 MINUTE), status = 'in_progress' LIMIT 1");
$conn->query("UPDATE proposal_projects SET deadline = DATE_SUB(NOW(), INTERVAL 1 MINUTE), status = 'accepted' LIMIT 1");

// Then, let's test if the function updates status and generates notifications
echo "Running check_assignment_deadlines()...\n";
check_assignment_deadlines($conn);
echo "Finished.\n";

// Now, verify if notifications were created
echo "\nVerifying Notifications created:\n";
$res = $conn->query("SELECT type, message FROM notifications WHERE type LIKE 'dl_ovr_%' OR type LIKE 'ms_ovr_%' OR type LIKE 'pp_ovr_%' ORDER BY id DESC LIMIT 3");
while($row = $res->fetch_assoc()) {
    echo "Type: " . $row['type'] . " | Message: " . $row['message'] . "\n";
}

// Running again to ensure no duplicates
echo "\nRunning check_assignment_deadlines() again to test deduplication...\n";
$initial_count = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE type LIKE '%ovr_%'")->fetch_assoc()['c'];
check_assignment_deadlines($conn);
$final_count = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE type LIKE '%ovr_%'")->fetch_assoc()['c'];

if ($initial_count === $final_count) {
    echo "Deduplication SUCCESS ($initial_count notifications).\n";
} else {
    echo "Deduplication FAILED (Started with $initial_count, ended with $final_count).\n";
}
