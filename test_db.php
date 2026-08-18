<?php
require 'config/db.php';
$res = $conn->query('SHOW COLUMNS FROM payments');
while($row = $res->fetch_assoc()) echo $row['Field'].' '.$row['Type']."\n";
echo "---\n";
$res = $conn->query('SHOW COLUMNS FROM milestones');
while($row = $res->fetch_assoc()) echo $row['Field'].' '.$row['Type']."\n";
