<?php
require_once __DIR__ . '/config/db.php';

// Fix jobs that were incorrectly marked as completed
$sql = "SELECT j.id, j.status, j.freelancers_needed, 
        (SELECT COUNT(*) FROM assignments WHERE job_id = j.id AND status NOT IN ('rejected', 'cancelled')) AS total_assigned,
        (SELECT COUNT(*) FROM assignments WHERE job_id = j.id AND status = 'completed') AS done_assigned
        FROM jobs j WHERE j.status = 'completed'";

$result = $conn->query($sql);
while($row = $result->fetch_assoc()) {
    $needed = (int)$row['freelancers_needed'];
    $assigned = (int)$row['total_assigned'];
    $done = (int)$row['done_assigned'];
    
    if ($done < $needed) {
        $new_status = ($assigned >= $needed) ? 'position_filled' : 'open';
        echo "Job {$row['id']} needs {$needed}, has {$assigned} assigned, {$done} done. Changing from 'completed' to '{$new_status}'.\n";
        $conn->query("UPDATE jobs SET status = '{$new_status}' WHERE id = " . $row['id']);
    } else {
        echo "Job {$row['id']} is legitimately completed.\n";
    }
}
