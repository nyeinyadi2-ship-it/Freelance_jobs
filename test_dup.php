<?php
require 'config/db.php';

// Check the query used in view_applications.php
$job_id = 52;
$stmt = $conn->prepare("
    SELECT m.id, m.title, m.freelancer_id, m.status 
    FROM milestones m 
    WHERE m.job_id = ? 
      AND (m.freelancer_id IS NOT NULL 
           OR NOT EXISTS (
               SELECT 1 FROM milestones m2 
               WHERE m2.job_id = m.job_id 
                 AND m2.title = m.title 
                 AND m2.freelancer_id IS NOT NULL
           ))
    ORDER BY m.sort_order ASC, m.id ASC
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$res = $stmt->get_result();
$milestones = [];
while ($row = $res->fetch_assoc()) {
    $milestones[] = $row;
}
$stmt->close();
print_r($milestones);
