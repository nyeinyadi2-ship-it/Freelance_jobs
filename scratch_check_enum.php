<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SHOW COLUMNS FROM jobs WHERE Field = 'status'");
print_r($res->fetch_assoc());
