<?php
require 'config/db.php';
$res = $conn->query("SHOW TABLES");
$tables = $res->fetch_all(MYSQLI_NUM);
echo json_encode($tables);
