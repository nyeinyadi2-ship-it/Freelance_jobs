<?php
require 'config/db.php';
$res = $conn->query("SELECT * FROM milestone_history ORDER BY id DESC");
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
