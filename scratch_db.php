<?php
require 'config/db.php';
$res = $conn->query("SHOW TABLES");
while($r = $res->fetch_array()) { echo $r[0] . "\n"; }
echo "\n--- COLUMNS IN FREELANCERS ---\n";
$res2 = $conn->query("SHOW COLUMNS FROM freelancers");
while($r = $res2->fetch_assoc()) { echo $r['Field'] . "\n"; }
