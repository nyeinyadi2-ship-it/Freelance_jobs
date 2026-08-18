<?php
require_once __DIR__ . '/config/db.php';
$conn->query("ALTER TABLE jobs ADD COLUMN payment_type enum('fixed','milestone') DEFAULT 'fixed' AFTER budget");
file_put_contents(__DIR__.'/alter_result.txt', $conn->error ? "Error: " . $conn->error : "Success");
