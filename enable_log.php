<?php
require_once __DIR__ . '/config/db.php';
$conn->query("SET GLOBAL general_log = 'ON'");
$conn->query("SET GLOBAL log_output = 'TABLE'");
echo "General log enabled.\n";
