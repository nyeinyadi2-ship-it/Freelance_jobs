<?php
require 'config/db.php';
$hash = '$2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm';
$stmt = $conn->prepare('UPDATE users SET password=? WHERE id=1');
$stmt->bind_param('s', $hash);
$stmt->execute();
echo "Updated to: " . $hash;
