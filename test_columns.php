<?php
require 'config/db.php';
$res = $conn->query("SHOW COLUMNS FROM freelancers");
$cols = [];
while($r = $res->fetch_assoc()) {
    $cols[] = $r['Field'];
}
echo "Columns in freelancers: " . implode(", ", $cols) . "\n";

$res2 = $conn->query("SHOW COLUMNS FROM users");
$cols2 = [];
while($r = $res2->fetch_assoc()) {
    $cols2[] = $r['Field'];
}
echo "Columns in users: " . implode(", ", $cols2) . "\n";
?>
