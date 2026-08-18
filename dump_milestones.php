<?php
require 'config/db.php';
$stmt = $conn->query("SELECT * FROM milestones");
$results = $stmt->fetch_all(MYSQLI_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
