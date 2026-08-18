<?php
require 'config/db.php';
$res = $conn->query("SELECT * FROM users WHERE role='admin'");
echo json_encode($res->fetch_all(MYSQLI_ASSOC));
