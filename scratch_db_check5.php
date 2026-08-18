<?php
require 'config/db.php';
$res = $conn->query("SHOW DATABASES");
echo json_encode($res->fetch_all(MYSQLI_NUM));
