<?php
require_once __DIR__ . '/../config/db.php';

function addColumnIfNotExists($conn, $table, $column, $definition) {
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($result->num_rows == 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

try {
    $conn->begin_transaction();

    // 1. Add reserved_balance to users
    addColumnIfNotExists($conn, 'users', 'reserved_balance', "DECIMAL(10,2) DEFAULT '0.00'");

    // 2. Update assignments status enum
    $conn->query("ALTER TABLE assignments MODIFY COLUMN status ENUM('assigned','working','submitted','completed','rejected', 'pending','accepted','revision_requested','approved','payment_pending','paid','cancelled') DEFAULT 'assigned'");

    // 3. Update milestones status enum
    $conn->query("ALTER TABLE milestones MODIFY COLUMN status ENUM('draft','funded','in_progress','submitted','approved','revision_requested', 'working','payment_pending','paid','completed') DEFAULT 'draft'");

    // 4. Update jobs status enum
    $conn->query("ALTER TABLE jobs MODIFY COLUMN status ENUM('pending','open','in_progress','submitted','completed','position_filled','expired','closed','approved','rejected', 'payment_pending','paid','cancelled') DEFAULT 'pending'");

    // 5. Payments table updates
    addColumnIfNotExists($conn, 'payments', 'company_id', "INT DEFAULT NULL");
    addColumnIfNotExists($conn, 'payments', 'freelancer_id', "INT DEFAULT NULL");
    addColumnIfNotExists($conn, 'payments', 'milestone_id', "INT DEFAULT NULL");
    addColumnIfNotExists($conn, 'payments', 'currency', "VARCHAR(10) DEFAULT 'MMK'");
    addColumnIfNotExists($conn, 'payments', 'payment_method', "VARCHAR(50) DEFAULT NULL");
    addColumnIfNotExists($conn, 'payments', 'transaction_reference', "VARCHAR(100) DEFAULT NULL");
    addColumnIfNotExists($conn, 'payments', 'created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    addColumnIfNotExists($conn, 'payments', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

    $conn->query("ALTER TABLE payments MODIFY COLUMN status ENUM('pending','paid', 'failed') DEFAULT 'pending'");

    // 6. Removed freelancer_payment_settings creation

    $conn->commit();
    echo "Migration completed successfully.\n";
} catch (Exception $e) {
    $conn->rollback();
    echo "Migration failed: " . $e->getMessage() . "\n";
}
