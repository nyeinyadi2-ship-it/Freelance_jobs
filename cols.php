<?php
require_once __DIR__ . '/config/db.php';
$r = $conn->query("SHOW COLUMNS FROM freelancers");
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
