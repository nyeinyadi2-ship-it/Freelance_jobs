<?php
require_once __DIR__ . '/../config/db.php';

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Escrow Payment System - Database Migration</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #1a1a2e; color: #eee; padding: 30px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { text-align: center; margin-bottom: 30px; color: #e94560; font-size: 1.8rem; }
        .card { background: #16213e; border-radius: 10px; padding: 20px 25px; margin-bottom: 18px; border-left: 4px solid #0f3460; }
        .card h2 { font-size: 1.1rem; margin-bottom: 12px; color: #53d8fb; }
        .step { padding: 8px 0; border-bottom: 1px solid #1a1a3e; font-size: 0.92rem; }
        .step:last-child { border-bottom: none; }
        .success { color: #4caf50; font-weight: bold; }
        .warning { color: #ff9800; font-weight: bold; }
        .error { color: #f44336; font-weight: bold; }
        .info { color: #90caf9; }
        .summary { background: #0f3460; border-radius: 10px; padding: 20px 25px; margin-top: 20px; }
        .summary h2 { color: #53d8fb; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #1a1a3e; font-size: 0.9rem; }
        th { color: #53d8fb; }
        .timestamp { text-align: center; color: #888; margin-top: 20px; font-size: 0.85rem; }
    </style>
</head>
<body>
<div class='container'>
<h1>Escrow Payment System Migration</h1>";

function columnExists($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['cnt'] > 0;
}

function tableExists($conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param("s", $table);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $res['cnt'] > 0;
}

function addColumnIfNotExists($conn, $table, $column, $definition) {
    if (columnExists($conn, $table, $column)) {
        echo "<div class='step'><span class='warning'>⏭ Skipped:</span> <span class='info'>{$table}.{$column}</span> already exists.</div>";
        return false;
    }
    try {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        echo "<div class='step'><span class='success'>✔ Added:</span> <span class='info'>{$table}.{$column}</span></div>";
        return true;
    } catch (Exception $e) {
        echo "<div class='step'><span class='error'>✘ Failed:</span> {$table}.{$column} — {$e->getMessage()}</div>";
        return false;
    }
}

$stats = ['columns_added' => 0, 'tables_created' => 0, 'tables_skipped' => 0, 'columns_skipped' => 0];

// ─── STEP 1: Alter payments table ───────────────────────────────────────────
echo "<div class='card'><h2>Step 1: Modify payments Table</h2>";

$paymentsCols = [
    'company_id'      => 'INT DEFAULT NULL',
    'freelancer_id'   => 'INT DEFAULT NULL',
    'escrow_status'   => "ENUM('pending','funded','in_progress','submitted','revision_requested','approved','released','refunded','cancelled') DEFAULT 'pending'",
    'funded_at'       => 'TIMESTAMP NULL DEFAULT NULL',
    'released_at'     => 'TIMESTAMP NULL DEFAULT NULL',
    'refunded_at'     => 'TIMESTAMP NULL DEFAULT NULL',
    'created_at'      => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
];

foreach ($paymentsCols as $col => $def) {
    $added = addColumnIfNotExists($conn, 'payments', $col, $def);
    if ($added) { $stats['columns_added']++; } else { $stats['columns_skipped']++; }
}

// Add foreign keys (ignore errors if they already exist)
$fks = [
    ['payments', 'fk_payments_company',    'company_id',    'companies(id)'],
    ['payments', 'fk_payments_freelancer', 'freelancer_id', 'freelancers(id)'],
];
foreach ($fks as [$tbl, $fk, $col, $ref]) {
    try {
        $conn->query("ALTER TABLE `{$tbl}` ADD CONSTRAINT `{$fk}` FOREIGN KEY (`{$col}`) REFERENCES `{$ref}` ON DELETE SET NULL");
        echo "<div class='step'><span class='success'>✔ Added FK:</span> <span class='info'>{$fk}</span></div>";
    } catch (Exception $e) {
        if ($e->getCode() == '42S02' || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'does not exist')) {
            echo "<div class='step'><span class='warning'>⏭ FK:</span> <span class='info'>{$fk}</span> skipped (table or constraint issue).</div>";
        } else {
            echo "<div class='step'><span class='error'>✘ FK:</span> {$fk} — {$e->getMessage()}</div>";
        }
    }
}

echo "</div>";

// ─── STEP 2: Create submissions table ───────────────────────────────────────
echo "<div class='card'><h2>Step 2: Create submissions Table</h2>";

if (tableExists($conn, 'submissions')) {
    echo "<div class='step'><span class='warning'>⏭ Skipped:</span> <span class='info'>submissions</span> table already exists.</div>";
    $stats['tables_skipped']++;
} else {
    try {
        $conn->query("CREATE TABLE `submissions` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `assignment_id` INT NOT NULL,
            `freelancer_id` INT NOT NULL,
            `file_path` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT,
            `version` INT DEFAULT 1,
            `status` ENUM('pending','revision_requested','approved') DEFAULT 'pending',
            `revision_notes` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_submissions_assignment` (`assignment_id`),
            FOREIGN KEY (`assignment_id`) REFERENCES `assignments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`freelancer_id`) REFERENCES `freelancers`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "<div class='step'><span class='success'>✔ Created:</span> <span class='info'>submissions</span> table with indexes and foreign keys.</div>";
        $stats['tables_created']++;
    } catch (Exception $e) {
        echo "<div class='step'><span class='error'>✘ Failed:</span> submissions — {$e->getMessage()}</div>";
    }
}

echo "</div>";



// ─── STEP 4: Create payment_history table ────────────────────────────────────
echo "<div class='card'><h2>Step 4: Create payment_history Table</h2>";

if (tableExists($conn, 'payment_history')) {
    echo "<div class='step'><span class='warning'>⏭ Skipped:</span> <span class='info'>payment_history</span> table already exists.</div>";
    $stats['tables_skipped']++;
} else {
    try {
        $conn->query("CREATE TABLE `payment_history` (
            `id` INT PRIMARY KEY AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `related_payment_id` INT DEFAULT NULL,
            `type` ENUM('escrow_fund','release','refund','withdrawal','withdrawal_rejected') NOT NULL,
            `amount` DECIMAL(10,2) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_payment_history_user` (`user_id`),
            INDEX `idx_payment_history_type` (`type`),
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`related_payment_id`) REFERENCES `payments`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "<div class='step'><span class='success'>✔ Created:</span> <span class='info'>payment_history</span> table with indexes and foreign keys.</div>";
        $stats['tables_created']++;
    } catch (Exception $e) {
        echo "<div class='step'><span class='error'>✘ Failed:</span> payment_history — {$e->getMessage()}</div>";
    }
}

echo "</div>";

// ─── Summary ────────────────────────────────────────────────────────────────
echo "<div class='summary'>
<h2>Migration Summary</h2>
<table>
    <tr><th>Metric</th><th>Count</th></tr>
    <tr><td>Columns added to payments</td><td>{$stats['columns_added']}</td></tr>
    <tr><td>Columns skipped (already exist)</td><td>{$stats['columns_skipped']}</td></tr>
    <tr><td>Tables created</td><td>{$stats['tables_created']}</td></tr>
    <tr><td>Tables skipped (already exist)</td><td>{$stats['tables_skipped']}</td></tr>
</table>
</div>";

echo "<div class='timestamp'>Migration ran at: " . date('Y-m-d H:i:s') . "</div>";
echo "</div></body></html>";

$conn = null;
