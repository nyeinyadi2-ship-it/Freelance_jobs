<?php
require 'config/db.php';
$stmt = $conn->query("SELECT freelancers_needed FROM jobs WHERE id = 52");
$results = $stmt->fetch_all(MYSQLI_ASSOC);
echo json_encode($results, JSON_PRETTY_PRINT);
