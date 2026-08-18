<?php
require_once __DIR__ . '/config/db.php';

$now = new DateTime();
echo "Current Time (Asia/Yangon): " . $now->format('Y-m-d H:i:s') . "\n\n";

$tests = [
    '3 days future' => (clone $now)->modify('+3 days'),
    '1 day future' => (clone $now)->modify('+1 day'),
    'Later today' => (clone $now)->modify('+2 hours'),
    'Exact time' => (clone $now),
    '1 min ago' => (clone $now)->modify('-1 minute'),
    'Yesterday' => (clone $now)->modify('-1 day'),
    'Several days ago' => (clone $now)->modify('-3 days')
];

foreach ($tests as $name => $deadline) {
    // Re-instantiating as if coming from DB
    $dl_date = new DateTime($deadline->format('Y-m-d H:i:s'));
    
    $is_passed = $dl_date <= new DateTime();
    $status = $is_passed ? "BLOCKED" : "ALLOWED";
    
    echo sprintf("%-20s : %-20s -> %s\n", $name, $dl_date->format('Y-m-d H:i:s'), $status);
}
