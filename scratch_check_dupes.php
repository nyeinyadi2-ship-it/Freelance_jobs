<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("
    SELECT job_id, freelancer_id, COUNT(*) as cnt
    FROM milestones 
    GROUP BY job_id, freelancer_id, sort_order
    HAVING COUNT(*) > 1
");

if ($res && $res->num_rows > 0) {
    echo "Duplicates by job, freelancer, sort_order:\n";
    while($row = $res->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No duplicates by job, freelancer, sort_order.\n";
}

$res = $conn->query("
    SELECT m1.id as id1, m2.id as id2, m1.job_id, m1.title
    FROM milestones m1
    JOIN milestones m2 ON m1.job_id = m2.job_id 
        AND m1.title = m2.title 
        AND m1.id < m2.id
        AND ((m1.freelancer_id IS NULL AND m2.freelancer_id IS NOT NULL) OR (m1.freelancer_id = m2.freelancer_id))
");
if ($res && $res->num_rows > 0) {
    echo "\nPairs of same title (one template, one assigned OR both same):\n";
    while($row = $res->fetch_assoc()) {
        echo "Job {$row['job_id']}: ID {$row['id1']} & ID {$row['id2']} ({$row['title']})\n";
    }
}
