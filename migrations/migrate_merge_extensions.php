<?php
/**
 * Migration: Merge milestone_extensions into milestones table.
 *
 * - Adds 6 extension columns to milestones (extension_requested, extension_deadline,
 *   extension_status, extension_requested_at, extension_approved_at, extension_rejected_at)
 * - Drops the milestone_extensions table (it has 0 rows; no data loss)
 *
 * Safe to run multiple times (idempotent checks via SHOW COLUMNS).
 */
require_once __DIR__ . '/../config/db.php';

echo "Running migration: migrate_merge_extensions\n";

$errors = 0;

// ---------------------------------------------------------------------------
// 1. Add extension columns to milestones (if not already present)
// ---------------------------------------------------------------------------
$columns = [
    'extension_requested'    => "TINYINT(1) NOT NULL DEFAULT 0",
    'extension_deadline'     => "DATETIME DEFAULT NULL",
    'extension_status'       => "ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none'",
    'extension_requested_at' => "TIMESTAMP NULL DEFAULT NULL",
    'extension_approved_at'  => "TIMESTAMP NULL DEFAULT NULL",
    'extension_rejected_at'  => "TIMESTAMP NULL DEFAULT NULL",
];

foreach ($columns as $col => $definition) {
    $chk = $conn->query("SHOW COLUMNS FROM milestones LIKE '$col'");
    if ($chk && $chk->num_rows === 0) {
        $sql = "ALTER TABLE milestones ADD COLUMN `$col` $definition";
        if ($conn->query($sql)) {
            echo "  + Added column: $col\n";
        } else {
            echo "  ERROR adding $col: " . $conn->error . "\n";
            $errors++;
        }
    } else {
        echo "  - Column already exists: $col\n";
    }
}

// ---------------------------------------------------------------------------
// 2. Sync existing extension_reason data:
//    The milestones table already has extension_reason. No sync needed since
//    milestone_extensions has 0 rows.
// ---------------------------------------------------------------------------
$check_ext = $conn->query("SHOW TABLES LIKE 'milestone_extensions'");
if ($check_ext && $check_ext->num_rows > 0) {
    $count_res = $conn->query("SELECT COUNT(*) AS cnt FROM milestone_extensions");
    $cnt = (int)($count_res->fetch_assoc()['cnt'] ?? 0);
    echo "\n  milestone_extensions row count: $cnt\n";

    if ($cnt > 0) {
        // Migrate any existing extension requests to the new columns
        // (failsafe — should not happen per our inspection but handle gracefully)
        $migrate = $conn->query("
            SELECT me.milestone_id, me.requested_deadline, me.reason, me.status,
                   me.created_at, me.reviewed_at
            FROM milestone_extensions me
            ORDER BY me.created_at ASC
        ");
        $migrated = 0;
        while ($row = $migrate->fetch_assoc()) {
            $ms_id = (int)$row['milestone_id'];
            $ext_deadline = $row['requested_deadline'];
            $ext_reason = $conn->real_escape_string($row['reason'] ?? '');
            $ext_status = $row['status']; // 'pending','approved','rejected'
            $ext_requested_at = $row['created_at'];
            $ext_approved_at  = ($ext_status === 'approved' && $row['reviewed_at']) ? $row['reviewed_at'] : null;
            $ext_rejected_at  = ($ext_status === 'rejected' && $row['reviewed_at']) ? $row['reviewed_at'] : null;

            $map_status = ($ext_status === 'approved') ? 'approved'
                        : (($ext_status === 'rejected') ? 'rejected' : 'pending');

            $approved_sql  = $ext_approved_at  ? "'$ext_approved_at'"  : 'NULL';
            $rejected_sql  = $ext_rejected_at  ? "'$ext_rejected_at'"  : 'NULL';

            $conn->query("
                UPDATE milestones SET
                    extension_requested    = 1,
                    extension_deadline     = '$ext_deadline',
                    extension_reason       = '$ext_reason',
                    extension_status       = '$map_status',
                    extension_requested_at = '$ext_requested_at',
                    extension_approved_at  = $approved_sql,
                    extension_rejected_at  = $rejected_sql
                WHERE id = $ms_id
                  AND extension_requested = 0
            ");
            $migrated++;
        }
        echo "  Migrated $migrated extension records to milestones table.\n";
    }

    // Drop the milestone_extensions table
    if ($conn->query("DROP TABLE IF EXISTS milestone_extensions")) {
        echo "  Dropped table: milestone_extensions\n";
    } else {
        echo "  ERROR dropping milestone_extensions: " . $conn->error . "\n";
        $errors++;
    }
} else {
    echo "  milestone_extensions table not found — already dropped or never created.\n";
}

// ---------------------------------------------------------------------------
// 3. Final verification
// ---------------------------------------------------------------------------
echo "\n=== Verification ===\n";
$verify = $conn->query("SHOW COLUMNS FROM milestones LIKE 'extension_%'");
while ($row = $verify->fetch_assoc()) {
    echo "  OK: " . $row['Field'] . " — " . $row['Type'] . "\n";
}

$ext_gone = $conn->query("SHOW TABLES LIKE 'milestone_extensions'");
echo "  milestone_extensions table: " . ($ext_gone->num_rows === 0 ? "DROPPED ✓" : "STILL EXISTS!") . "\n";

echo "\nMigration " . ($errors === 0 ? "completed successfully." : "completed WITH $errors error(s).") . "\n";
