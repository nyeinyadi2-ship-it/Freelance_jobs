<?php
require_once __DIR__ . '/config/db.php';
$stmt = $conn->query("SELECT * FROM payments");
$rows = [];
while ($row = $stmt->fetch_assoc()) {
    $rows[] = $row;
}
echo json_encode($rows, JSON_PRETTY_PRINT);
