<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';

$res = $conn->query("SHOW COLUMNS FROM milestones LIKE 'status'");
$row = $res->fetch_assoc();
echo "Milestone status: " . $row['Type'] . "\n";

$res = $conn->query("SHOW COLUMNS FROM assignments LIKE 'status'");
$row = $res->fetch_assoc();
echo "Assignment status: " . $row['Type'] . "\n";
?>
