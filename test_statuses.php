<?php
require 'config/db.php';
$r = $conn->query("SELECT DISTINCT status FROM jobs");
while ($row = $r->fetch_assoc()) {
    echo $row['status'] . "\n";
}
