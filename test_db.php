<?php
require_once __DIR__ . '/config/db.php';
$output = "";
$res = $conn->query("SHOW TABLES");
if ($res) {
    while($row = $res->fetch_array()) {
        $output .= $row[0] . "\n";
    }
}
file_put_contents(__DIR__ . '/tables_list.txt', $output);
echo "Done";
