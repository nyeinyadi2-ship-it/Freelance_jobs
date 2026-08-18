<?php
$hash_db = '\/uoDDUM0JqTgzSASK8cyllo58UD1FMR6m';
$hash_db2 = '/uoDDUM0JqTgzSASK8cyllo58UD1FMR6m';
echo "1: " . (password_verify('admin123', $hash_db) ? 'true' : 'false') . "\n";
echo "2: " . (password_verify('admin123', $hash_db2) ? 'true' : 'false') . "\n";
echo "3: " . (password_verify('admin@platform.com', $hash_db) ? 'true' : 'false') . "\n";

// Test if $2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm matches admin123
$correct_hash = '$2y$10$fLhKLQuCby5WGCF3wq4z3e7Lox/Y6xggMUdAWPPmaEp6Ui4QT1Xcm';
echo "4: " . (password_verify('admin123', $correct_hash) ? 'true' : 'false') . "\n";
