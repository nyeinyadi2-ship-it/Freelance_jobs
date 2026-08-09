<?php
require_once __DIR__ . '/../config/db.php';

$tables = ['escrow_transactions', 'notification_reads'];
$backup = "-- Database Backup\n-- Tables: " . implode(', ', $tables) . "\n\n";

foreach ($tables as $table) {
    // Check if table exists
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    if ($res->num_rows === 0) {
        $backup .= "-- Table $table does not exist.\n\n";
        continue;
    }

    // Get CREATE TABLE
    $res = $conn->query("SHOW CREATE TABLE $table");
    $row = $res->fetch_row();
    $backup .= "DROP TABLE IF EXISTS `$table`;\n";
    $backup .= $row[1] . ";\n\n";

    // Get DATA
    $res = $conn->query("SELECT * FROM `$table`");
    if ($res->num_rows > 0) {
        $backup .= "-- Data for $table\n";
        while ($row = $res->fetch_assoc()) {
            $cols = array_keys($row);
            $vals = array_values($row);
            $colsStr = implode('`, `', $cols);
            
            $escapedVals = [];
            foreach ($vals as $val) {
                if ($val === null) {
                    $escapedVals[] = "NULL";
                } else {
                    $escapedVals[] = "'" . $conn->real_escape_string((string)$val) . "'";
                }
            }
            $valsStr = implode(', ', $escapedVals);
            $backup .= "INSERT INTO `$table` (`$colsStr`) VALUES ($valsStr);\n";
        }
        $backup .= "\n";
    } else {
        $backup .= "-- No data found for $table\n\n";
    }
}

file_put_contents(__DIR__ . '/../migrations/backup_before_cleanup.sql', $backup);
echo "Backup successful";
