<?php
require __DIR__ . '/config/db.php';
$stmt = $conn->prepare("INSERT INTO jobs (company_id, title, category, budget, status) VALUES (1, 'Test Job Delete', 'Test', 1000, 'open')");
$stmt->execute();
$job_id = $stmt->insert_id;
$stmt->close();
echo $job_id;
