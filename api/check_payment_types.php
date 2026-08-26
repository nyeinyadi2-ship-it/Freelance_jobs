<?php
header('Content-Type: text/plain');
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SELECT DISTINCT payment_type FROM jobs");
while ($row = $res->fetch_assoc()) {
    echo $row['payment_type'] . "\n";
}
?>
