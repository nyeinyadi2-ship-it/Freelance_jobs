<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW CREATE TABLE wallet_transactions");
$row = $res->fetch_assoc();
echo $row['Create Table'] . "\n";
?>
