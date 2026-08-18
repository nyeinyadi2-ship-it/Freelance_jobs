<?php
require 'config/db.php';
$conn->query('DROP TABLE IF EXISTS escrow_transactions');
$conn->query('DROP TABLE IF EXISTS escrow');
echo "Tables dropped successfully.";
?>
