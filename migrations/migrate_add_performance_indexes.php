<?php
/**
 * Migration: Add performance indexes for slow page navigation
 * 
 * Adds indexes on frequently queried columns that are currently causing
 * full table scans on every page load:
 * - jobs(status, deadline) for expired job checks and status filters
 * - jobs(status, company_id) for company job listings
 * - assignments(status) for status-based queries
 * - submissions(assignment_id, version) for latest submission lookups
 */

require_once __DIR__ . '/../config/db.php';

$indexes = [
    // jobs table: critical for check_and_update_expired_jobs() and status filters
    "CREATE INDEX idx_jobs_status_deadline ON jobs (status, deadline)",
    "CREATE INDEX idx_jobs_status_company ON jobs (status, company_id)",
    
    // assignments table: for status-based queries
    "CREATE INDEX idx_assignments_status ON assignments (status)",
    
    // submissions table: for latest version lookups (ORDER BY version DESC LIMIT 1)
    "CREATE INDEX idx_submissions_assignment_version ON submissions (assignment_id, version)",
];

echo "Adding performance indexes...\n";

foreach ($indexes as $sql) {
    // Extract index name for display
    preg_match('/INDEX (\w+)/', $sql, $m);
    $name = $m[1] ?? 'unknown';
    
    // Check if index already exists
    $check = $conn->query("SHOW INDEX FROM " . preg_replace('/.*ON (\w+).*/', '$1', $sql) . " WHERE Key_name = '$name'");
    if ($check && $check->num_rows > 0) {
        echo "  SKIP: $name (already exists)\n";
        continue;
    }
    
    echo "  CREATE: $name ... ";
    if ($conn->query($sql)) {
        echo "OK\n";
    } else {
        echo "FAILED: " . $conn->error . "\n";
    }
}

echo "Done.\n";
