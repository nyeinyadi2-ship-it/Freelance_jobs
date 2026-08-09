<?php
require 'config/db.php';
$res = $conn->query("DESCRIBE escrow");
while ($row = $res->fetch_assoc()) {
    echo $row['Field'] . " - " . $row['Type'] . "\n";
}
