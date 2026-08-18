<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/job_helpers.php';

// Reset everything to working/in_progress so we can test the check script
$conn->query("UPDATE assignments SET status = 'working' WHERE status = 'overdue'");
$conn->query("UPDATE milestones SET status = 'in_progress' WHERE status = 'overdue'");
$conn->query("UPDATE proposal_projects SET status = 'accepted' WHERE status = 'overdue'");

$conn->query("DELETE FROM notifications WHERE type LIKE 'dl_c_ovr_%' OR type LIKE 'ms_c_ovr_%' OR type LIKE 'pp_c_ovr_%'");
$conn->query("DELETE FROM notifications WHERE type LIKE 'dl_ovr_%' OR type LIKE 'ms_ovr_%' OR type LIKE 'pp_ovr_%'");

echo "\nRunning check_assignment_deadlines()...\n";
check_assignment_deadlines($conn);

echo "\nCompany Overdue Notifications:\n";
$stmt = $conn->prepare("SELECT type, message, user_id FROM notifications WHERE type LIKE '%_c_ovr_%' ORDER BY id DESC LIMIT 10");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    echo "User ID: " . $row['user_id'] . " | Type: " . $row['type'] . " | Msg: " . $row['message'] . "\n";
}
$stmt->close();
