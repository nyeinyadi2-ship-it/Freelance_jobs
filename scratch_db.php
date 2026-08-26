<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SELECT * FROM wallet_transactions WHERE job_id IN (SELECT id FROM jobs WHERE title LIKE '%Digital Marketing%')");
$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
}
echo json_encode($rows, JSON_PRETTY_PRINT);
