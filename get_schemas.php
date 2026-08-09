<?php
require 'config/db.php';

$tables = ['freelancers', 'companies', 'users'];

foreach ($tables as $table) {
    $result = $conn->query("SHOW CREATE TABLE $table");
    if ($result) {
        $row = $result->fetch_assoc();
        echo $row['Create Table'] . ";\n\n";
    }
}
