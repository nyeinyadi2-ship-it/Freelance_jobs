<?php
/**
 * Migration: merge proposal_project_submissions into unified submissions table.
 *
 * Known starting state (from diagnostic):
 *  - submissions.assignment_id  : already nullable, NO FK currently
 *  - submissions.proposal_project_id : does NOT exist yet
 *  - submissions.github_link          : does NOT exist yet
 *  - submission id=1 has orphaned assignment_id=52 (no matching assignment)
 *  - LoggedMysqli throws exceptions on SQL errors — we use try/catch throughout
 */
require_once __DIR__ . '/../config/db.php';

// ── helpers ───────────────────────────────────────────────────────────────────
function step(string $t): void { echo "\n── $t ──\n"; }
function ok(string $m): void   { echo "  OK: $m\n"; }
function info(string $m): void { echo "  $m\n"; }
function err(string $m): void  { echo "  ERROR: $m\n"; }
function warn(string $m): void { echo "  WARN: $m\n"; }

function col_exists(mysqli $db, string $table, string $col): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $s->bind_param('ss', $table, $col);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0] > 0;
}

function idx_exists(mysqli $db, string $table, string $idx): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?");
    $s->bind_param('ss', $table, $idx);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0] > 0;
}

function fk_exists(mysqli $db, string $fk): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                        WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY' AND CONSTRAINT_NAME=?");
    $s->bind_param('s', $fk);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0] > 0;
}

function table_exists(mysqli $db, string $table): bool
{
    $s = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->bind_param('s', $table);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0] > 0;
}

/** Execute a DDL statement, catching LoggedMysqli exceptions. Returns true on success. */
function ddl(mysqli $db, string $sql, string $desc): bool
{
    try {
        $db->query($sql);
        ok($desc);
        return true;
    } catch (Throwable $ex) {
        err("$desc — " . $ex->getMessage());
        return false;
    }
}

// ── begin ─────────────────────────────────────────────────────────────────────
echo "=== Merge Submissions Migration ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n";
$errors = [];

// ── 1. Verify tables ──────────────────────────────────────────────────────────
step("1. Verify required tables");
foreach (['submissions', 'proposal_projects', 'freelancers', 'assignments'] as $t) {
    if (!table_exists($conn, $t)) { err("'$t' missing — cannot continue."); exit(1); }
    ok("'$t' exists");
}
$pps_exists = table_exists($conn, 'proposal_project_submissions');
info("proposal_project_submissions: " . ($pps_exists ? 'EXISTS' : 'GONE (already migrated)'));

// ── 2. Snapshots ──────────────────────────────────────────────────────────────
step("2. Row counts before migration");
$before_sub = (int)$conn->query("SELECT COUNT(*) FROM submissions")->fetch_row()[0];
$before_pps = $pps_exists
    ? (int)$conn->query("SELECT COUNT(*) FROM proposal_project_submissions")->fetch_row()[0]
    : 0;
info("submissions                  : $before_sub rows");
info("proposal_project_submissions : $before_pps rows");

// ── 3. assignment_id nullable ────────────────────────────────────────────────
step("3. Ensure assignment_id is nullable");
$col = $conn->query("SHOW COLUMNS FROM submissions LIKE 'assignment_id'")->fetch_assoc();
if ($col && strtoupper($col['Null']) !== 'YES') {
    if (fk_exists($conn, 'fk_submissions_assignment')) {
        ddl($conn, "ALTER TABLE submissions DROP FOREIGN KEY fk_submissions_assignment",
            "Dropped fk_submissions_assignment");
    }
    if (!ddl($conn, "ALTER TABLE submissions MODIFY COLUMN assignment_id INT NULL",
        "assignment_id set to nullable"))
    { $errors[] = "Step 3"; }
} else {
    ok("assignment_id is already nullable");
}

// ── 4. NULL out orphaned assignment_ids ───────────────────────────────────────
step("4. NULL orphaned assignment_ids (where assignment no longer exists)");
try {
    $res = $conn->query("
        UPDATE submissions s
        LEFT JOIN assignments a ON s.assignment_id = a.id
        SET s.assignment_id = NULL
        WHERE s.assignment_id IS NOT NULL
          AND a.id IS NULL
    ");
    $n = $conn->affected_rows;
    if ($n > 0) {
        info("Nulled $n orphaned assignment_id(s).");
    } else {
        ok("No orphaned rows found.");
    }
} catch (Throwable $ex) {
    warn("Could not null orphaned rows: " . $ex->getMessage());
}

// ── 5. Restore FK fk_submissions_assignment ───────────────────────────────────
step("5. Ensure FK fk_submissions_assignment");
if (!fk_exists($conn, 'fk_submissions_assignment')) {
    ddl($conn,
        "ALTER TABLE submissions ADD CONSTRAINT fk_submissions_assignment
         FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE",
        "Added fk_submissions_assignment");
    // Non-fatal if it fails due to data issues — we still continue
} else {
    ok("fk_submissions_assignment already exists");
}

// ── 6. Add proposal_project_id ────────────────────────────────────────────────
step("6. Add proposal_project_id column");
if (!col_exists($conn, 'submissions', 'proposal_project_id')) {
    if (!ddl($conn,
        "ALTER TABLE submissions ADD COLUMN proposal_project_id INT NULL AFTER assignment_id",
        "Added proposal_project_id"))
    { $errors[] = "Step 6"; }
} else {
    ok("proposal_project_id already exists");
}

// ── 7. Add github_link ────────────────────────────────────────────────────────
step("7. Add github_link column");
if (!col_exists($conn, 'submissions', 'github_link')) {
    if (!ddl($conn,
        "ALTER TABLE submissions ADD COLUMN github_link VARCHAR(500) NULL AFTER file_path",
        "Added github_link"))
    { $errors[] = "Step 7"; }
} else {
    ok("github_link already exists");
}

// ── 8. Indexes ────────────────────────────────────────────────────────────────
step("8. Add indexes");
$indexes = [
    'idx_submissions_proposal'            => "ALTER TABLE submissions ADD INDEX idx_submissions_proposal (proposal_project_id)",
    'idx_submissions_freelancer'          => "ALTER TABLE submissions ADD INDEX idx_submissions_freelancer (freelancer_id)",
    'idx_submissions_assign_freelancer'   => "ALTER TABLE submissions ADD INDEX idx_submissions_assign_freelancer (assignment_id, freelancer_id)",
    'idx_submissions_proposal_freelancer' => "ALTER TABLE submissions ADD INDEX idx_submissions_proposal_freelancer (proposal_project_id, freelancer_id)",
];
foreach ($indexes as $name => $sql) {
    if (!idx_exists($conn, 'submissions', $name)) {
        ddl($conn, $sql, "Added index $name");
    } else {
        ok("$name already exists");
    }
}

// ── 9. FK fk_submissions_proposal ────────────────────────────────────────────
step("9. Add FK fk_submissions_proposal");
if (!fk_exists($conn, 'fk_submissions_proposal')) {
    if (!ddl($conn,
        "ALTER TABLE submissions ADD CONSTRAINT fk_submissions_proposal
         FOREIGN KEY (proposal_project_id) REFERENCES proposal_projects(id) ON DELETE CASCADE",
        "Added fk_submissions_proposal"))
    { $errors[] = "Step 9"; }
} else {
    ok("fk_submissions_proposal already exists");
}

// ── 10. FK fk_submissions_freelancer ─────────────────────────────────────────
step("10. Ensure FK fk_submissions_freelancer");
if (!fk_exists($conn, 'fk_submissions_freelancer')) {
    ddl($conn,
        "ALTER TABLE submissions ADD CONSTRAINT fk_submissions_freelancer
         FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE",
        "Added fk_submissions_freelancer");
} else {
    ok("fk_submissions_freelancer already exists");
}

// ── 11. Migrate proposal_project_submissions ──────────────────────────────────
step("11. Migrate proposal_project_submissions rows");
if (!$pps_exists) {
    ok("Table does not exist — nothing to migrate");
} elseif ($before_pps === 0) {
    ok("No rows to migrate");
} else {
    $already = (int)$conn->query(
        "SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NOT NULL"
    )->fetch_row()[0];
    info("Already migrated: $already / $before_pps");

    if ($already >= $before_pps) {
        ok("All rows already migrated");
    } else {
        try {
            $conn->query("
                INSERT INTO submissions
                    (proposal_project_id, freelancer_id, file_path, github_link,
                     notes, status, version, created_at)
                SELECT
                    pps.proposal_project_id,
                    pps.freelancer_id,
                    pps.file,
                    pps.github_link,
                    pps.comment,
                    'pending',
                    1,
                    COALESCE(pps.submitted_at, NOW())
                FROM proposal_project_submissions pps
                WHERE NOT EXISTS (
                    SELECT 1 FROM submissions s
                    WHERE  s.proposal_project_id = pps.proposal_project_id
                      AND  s.freelancer_id       = pps.freelancer_id
                )
            ");
            info("Inserted {$conn->affected_rows} rows into submissions");
        } catch (Throwable $ex) {
            err("Migration INSERT failed: " . $ex->getMessage());
            $errors[] = "Step 11";
        }
    }
}

// ── 12. Verify ────────────────────────────────────────────────────────────────
step("12. Verification");
$after_total    = (int)$conn->query("SELECT COUNT(*) FROM submissions")->fetch_row()[0];
$after_proposal = (int)$conn->query("SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NOT NULL")->fetch_row()[0];
$after_normal   = (int)$conn->query("SELECT COUNT(*) FROM submissions WHERE proposal_project_id IS NULL")->fetch_row()[0];

info("Total rows in submissions       : $after_total");
info("  → assignment-based (normal)   : $after_normal");
info("  → proposal-based (trial task) : $after_proposal  (pps had $before_pps)");

$migration_ok = !$pps_exists || ($after_proposal >= $before_pps);
if (!$migration_ok) {
    err("Proposal rows migrated ($after_proposal) < original pps count ($before_pps)");
    $errors[] = "Verification failed";
} else {
    ok("Row counts verified");
}

// ── 13. Drop old table ────────────────────────────────────────────────────────
step("13. Drop proposal_project_submissions");
if (!empty($errors)) {
    info("SKIPPED — errors present: " . implode(', ', $errors));
} elseif (!$pps_exists) {
    ok("Already gone");
} elseif (!$migration_ok) {
    info("SKIPPED — verification failed, old table preserved for safety");
} else {
    ddl($conn, "DROP TABLE proposal_project_submissions",
        "Dropped proposal_project_submissions");
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=== Migration " . (empty($errors) ? "SUCCEEDED ✓" : "FAILED ✗") . " ===\n";
foreach ($errors as $e) echo "  • $e\n";
echo "Finished: " . date('Y-m-d H:i:s') . "\n";
