<?php
require 'config/db.php';
$res = $conn->query("SELECT id, username, email, password, role FROM users WHERE role = 'admin'");
$users = $res->fetch_all(MYSQLI_ASSOC);
echo json_encode($users);
