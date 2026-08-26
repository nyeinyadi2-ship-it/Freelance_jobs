<?php
require_once __DIR__ . '/config/db.php'; 

try {
    $conn->report_mode = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;

    // Test the first query
    $rejection_reason = "Test reason";
    $assignment_id = 1;

    $update = $conn->prepare("UPDATE assignments SET status = 'rejected', rejection_reason = ? WHERE id = ?");
    $update->bind_param('si', $rejection_reason, $assignment_id);
    $update->execute();
    echo "Assignments update OK\n";

    // Test the second query
    $job_id = 1;
    $freelancer_id = 1;
    $update_app = $conn->prepare("UPDATE job_applications SET status = 'rejected', rejection_reason = ? WHERE job_id = ? AND freelancer_id = ?");
    $update_app->bind_param('sii', $rejection_reason, $job_id, $freelancer_id);
    $update_app->execute();
    echo "job_applications update OK\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
