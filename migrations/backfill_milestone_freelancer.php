<?php
/**
 * Backfill: Set freelancer_id on existing milestones from direct hire assignments.
 * Run once: php backfill_milestone_freelancer.php
 */

require_once __DIR__ . '/../config/db.php';

echo "=== Backfill milestone freelancer_id ===\n\n";

// Backfill direct hire milestones where freelancer_id is NULL
$sql = "UPDATE milestones m
        JOIN assignments a ON a.job_id = m.job_id AND a.assignment_type = 'direct_hire'
        SET m.freelancer_id = a.freelancer_id
        WHERE m.freelancer_id IS NULL";

$conn->query($sql);
$affected = $conn->affected_rows;
echo "[OK] Backfilled {$affected} milestone(s) from direct hire assignments.\n";

// Show remaining unassigned milestones
$result = $conn->query("SELECT COUNT(*) AS cnt FROM milestones WHERE freelancer_id IS NULL");
$row = $result->fetch_assoc();
echo "[INFO] {$row['cnt']} milestone(s) still have NULL freelancer_id (multi-freelancer jobs or legacy data).\n";

echo "\n=== Backfill complete ===\n";
