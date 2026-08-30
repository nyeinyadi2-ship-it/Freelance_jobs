<?php
/**
 * Fix-up script: adds FKs that failed due to MySQL internal table cache issue
 * after repeated failed ALTER TABLE operations.
 * Run this after merge_submissions.php succeeds.
 */
require_once __DIR__ . '/../config/db.php';

function fk_exists(mysqli $db, string $fk): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                        WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY' AND CONSTRAINT_NAME=?");
    $s->bind_param('s', $fk);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0] > 0;
}

echo "=== FK Fix-up ===\n";

// Reset FK checks to clear any stale MySQL internal state
$conn->query("SET FOREIGN_KEY_CHECKS=0");

// Ensure freelancers table uses InnoDB engine so InnoDB FK references work properly
$conn->query("ALTER TABLE freelancers ENGINE=InnoDB");

$fks = [
    'fk_submissions_proposal'   => "ALTER TABLE submissions ADD CONSTRAINT fk_submissions_proposal FOREIGN KEY (proposal_project_id) REFERENCES proposal_projects(id) ON DELETE CASCADE",
    'fk_submissions_freelancer' => "ALTER TABLE submissions ADD CONSTRAINT fk_submissions_freelancer FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE",
];

foreach ($fks as $name => $sql) {
    if (fk_exists($conn, $name)) {
        echo "  $name: already exists\n";
        continue;
    }
    try {
        $conn->query($sql);
        echo "  $name: ADDED OK\n";
    } catch (Throwable $ex) {
        echo "  $name: ERROR — " . $ex->getMessage() . "\n";
    }
}

$conn->query("SET FOREIGN_KEY_CHECKS=1");

// Now drop proposal_project_submissions if the data migration is complete
echo "\n=== Drop proposal_project_submissions ===\n";

$pps_count = 0;
$check = $conn->query("SHOW TABLES LIKE 'proposal_project_submissions'");
if ($check && $check->num_rows > 0) {
    $pps_count = (int)$conn->query("SELECT COUNT(*) FROM proposal_project_submissions")->fetch_row()[0];
}

$migrated = (int)$conn->query("SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NOT NULL")->fetch_row()[0];

echo "  proposal_project_submissions rows : $pps_count\n";
echo "  submissions with proposal_project_id: $migrated\n";

if ($check && $check->num_rows > 0) {
    if ($migrated >= $pps_count) {
        try {
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $conn->query("DROP TABLE proposal_project_submissions");
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            echo "  DROPPED proposal_project_submissions OK\n";
        } catch (Throwable $ex) {
            echo "  ERROR dropping: " . $ex->getMessage() . "\n";
        }
    } else {
        echo "  SKIPPED drop — migrated count ($migrated) < pps count ($pps_count)\n";
    }
} else {
    echo "  Already dropped\n";
}

// Final verification
echo "\n=== Final State ===\n";
$r = $conn->query("SHOW COLUMNS FROM submissions");
echo "Columns:\n";
while ($row = $r->fetch_assoc()) {
    echo "  {$row['Field']}: {$row['Type']}  NULL={$row['Null']}\n";
}

$r2 = $conn->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='submissions' AND REFERENCED_TABLE_NAME IS NOT NULL");
echo "Foreign keys:\n";
while ($row = $r2->fetch_assoc()) {
    echo "  {$row['CONSTRAINT_NAME']}: {$row['COLUMN_NAME']} -> {$row['REFERENCED_TABLE_NAME']}\n";
}

$t = $conn->query("SELECT COUNT(*) FROM submissions")->fetch_row()[0];
$p = $conn->query("SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NOT NULL")->fetch_row()[0];
$a = $conn->query("SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NULL")->fetch_row()[0];
echo "Row counts: total=$t  assignment=$a  proposal=$p\n";
