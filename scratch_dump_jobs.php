<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SELECT id, title, status FROM jobs");
while ($row = $res->fetch_assoc()) {
    echo $row['id'] . ' | ' . $row['title'] . ' | ' . $row['status'] . "\n";
}
