<?php
require 'config/db.php';
echo "COMPANIES:\n";
$res = $conn->query("DESCRIBE companies");
while($row = $res->fetch_assoc()) echo json_encode($row) . "\n";
echo "\nUSERS:\n";
$res = $conn->query("DESCRIBE users");
while($row = $res->fetch_assoc()) echo json_encode($row) . "\n";
