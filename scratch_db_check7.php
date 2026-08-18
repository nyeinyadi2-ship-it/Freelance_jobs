<?php
require 'config/db.php';
$conn->query("UPDATE users SET password='$2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm' WHERE id=1");
echo "Password reset to admin123 hash. Let's verify: \n";
$res = $conn->query("SELECT password FROM users WHERE id=1");
echo json_encode($res->fetch_assoc());
