<?php
require_once __DIR__ . '/config/db.php';

// Check if enum contains cancelled
$res = $conn->query("SHOW COLUMNS FROM milestones LIKE 'status'");
$row = $res->fetch_assoc();

if (strpos($row['Type'], "'cancelled'") === false) {
    $enum = str_replace("enum(", "", $row['Type']);
    $enum = str_replace(")", "", $enum);
    $arr = explode(",", $enum);
    $arr[] = "'cancelled'";
    $arr = array_unique($arr);
    $new_enum = "enum(" . implode(",", $arr) . ")";
    $conn->query("ALTER TABLE milestones MODIFY status $new_enum DEFAULT 'draft'");
    echo "Added cancelled to enum. ";
}

// Check if cancel_reason exists
$res2 = $conn->query("SHOW COLUMNS FROM milestones LIKE 'cancel_reason'");
if ($res2->num_rows === 0) {
    $conn->query("ALTER TABLE milestones ADD COLUMN cancel_reason TEXT DEFAULT NULL");
    echo "Added cancel_reason column. ";
}

echo "Done.";
