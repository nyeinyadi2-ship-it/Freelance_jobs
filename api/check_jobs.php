<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW COLUMNS FROM jobs");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
?>
