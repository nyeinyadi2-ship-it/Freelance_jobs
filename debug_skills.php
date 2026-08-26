<?php
require 'config/db.php';

echo "--- SKILLS ---\n";
$res = $conn->query("SELECT * FROM skills");
while($row = $res->fetch_assoc()) echo json_encode($row) . "\n";

echo "\n--- JOBS ---\n";
$res = $conn->query("SELECT id, title, status FROM jobs");
while($row = $res->fetch_assoc()) echo json_encode($row) . "\n";

echo "\n--- JOB_SKILLS ---\n";
$res = $conn->query("SELECT * FROM job_skills");
while($row = $res->fetch_assoc()) echo json_encode($row) . "\n";
