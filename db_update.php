<?php
require 'config/db.php';
$conn->query('ALTER TABLE payments ADD COLUMN recipient_name VARCHAR(255) DEFAULT NULL');
$conn->query('ALTER TABLE payments ADD COLUMN recipient_phone VARCHAR(50) DEFAULT NULL');
echo "DB Updated\n";
