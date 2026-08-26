<?php
require_once __DIR__ . '/config/db.php';

$sql = "SELECT id, title, status, category, freelancers_needed, deadline, visibility, (SELECT COUNT(*) FROM assignments WHERE job_id = jobs.id AND status NOT IN ('rejected', 'cancelled')) AS assigned_count FROM jobs WHERE title LIKE '%test%' OR id > 0 ORDER BY id DESC LIMIT 20";
$result = $conn->query($sql);
while($row = $result->fetch_assoc()) {
    print_r($row);
}
