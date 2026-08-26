<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query("SHOW COLUMNS FROM milestones LIKE 'status'");
$row = $res->fetch_assoc();
echo "Current enum: " . $row['Type'] . "\n";

if (strpos($row['Type'], "'cancelled'") === false) {
    $enum = str_replace("enum(", "", $row['Type']);
    $enum = str_replace(")", "", $enum);
    $arr = explode(",", $enum);
    $arr[] = "'cancelled'";
    $arr[] = "'payment_pending'";
    $arr[] = "'paid'";
    $arr[] = "'overdue'";
    $arr = array_unique($arr);
    $new_enum = "enum(" . implode(",", $arr) . ")";
    $conn->query("ALTER TABLE milestones MODIFY status $new_enum DEFAULT 'draft'");
    echo "Updated to: $new_enum\n";
} else {
    echo "cancelled already exists.\n";
}
