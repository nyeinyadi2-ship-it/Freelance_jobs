<?php
require __DIR__ . '/config/db.php';

$job_id = 72;
$company_id = 1;

$stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM job_applications WHERE job_id = ?) as apps,
        (SELECT COUNT(*) FROM assignments WHERE job_id = ?) as assignments
");
$stmt->bind_param('ii', $job_id, $job_id);
$stmt->execute();
$counts = $stmt->get_result()->fetch_assoc();
$stmt->close();
echo "Apps: " . $counts['apps'] . "\n";
echo "Ass: " . $counts['assignments'] . "\n";

if ($counts['apps'] > 0 || $counts['assignments'] > 0) {
    echo "Blocked!\n";
} else {
    echo "Proceed!\n";
    $stmt = $conn->prepare("DELETE FROM jobs WHERE id = ?");
    $stmt->bind_param('i', $job_id);
    if($stmt->execute()) {
        echo "Deleted " . $stmt->affected_rows . " rows.\n";
    } else {
        echo "Delete failed: " . $stmt->error . "\n";
    }
}
