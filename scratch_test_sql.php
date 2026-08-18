<?php
require_once __DIR__ . '/config/db.php';
$stmt = $conn->prepare("
    SELECT * FROM milestones m1
    WHERE job_id = ? 
      AND (freelancer_id = ? OR freelancer_id IS NULL)
      AND (
          freelancer_id IS NOT NULL 
          OR NOT EXISTS (
              SELECT 1 FROM milestones m2 
              WHERE m2.job_id = m1.job_id 
                AND m2.freelancer_id = ? 
                AND m2.sort_order = m1.sort_order
          )
      )
    ORDER BY sort_order ASC
");
$job_id = 52;
$fl_freelancer_id = 123;
$stmt->bind_param('iii', $job_id, $fl_freelancer_id, $fl_freelancer_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    echo $row['title'] . ' (FL: ' . $row['freelancer_id'] . ")\n";
}
