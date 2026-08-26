<?php
require 'config/db.php';
$res = $conn->query("SHOW CREATE TABLE payments");
$row = $res->fetch_assoc();
echo "Payments:\n" . $row['Create Table'] . "\n\n";

$res = $conn->query("SHOW CREATE TABLE milestones");
$row = $res->fetch_assoc();
echo "Milestones:\n" . $row['Create Table'] . "\n\n";
