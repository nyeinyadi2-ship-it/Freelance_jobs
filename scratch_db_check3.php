<?php
require 'config/db.php';
$res = $conn->query("SELECT * FROM users WHERE email = 'admin@platform.com'");
$users = $res->fetch_all(MYSQLI_ASSOC);
echo json_encode($users);
