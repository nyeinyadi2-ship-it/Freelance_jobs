<?php
require __DIR__ . '/config/db.php';

// Run migration
function col_exists($conn, $col) {
    $r = $conn->query("SHOW COLUMNS FROM companies LIKE '{$col}'");
    return $r && $r->num_rows > 0;
}

$log = [];
foreach (['industry' => 'VARCHAR(100)', 'company_size' => 'VARCHAR(50)'] as $col => $type) {
    if (!col_exists($conn, $col)) {
        $conn->query("ALTER TABLE companies ADD COLUMN {$col} {$type} DEFAULT NULL AFTER established_year");
        $log[] = "Added: {$col}";
    } else {
        $log[] = "Already exists: {$col}";
    }
}

$r = $conn->query("SHOW COLUMNS FROM companies");
$cols = [];
while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];

echo implode("\n", $log) . "\n---\nColumns: " . implode(', ', $cols) . "\n";
