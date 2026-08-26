<?php
/**
 * Dedicated cron script for checking overdue milestones.
 * Run via CLI: php cron_overdue_milestones.php
 * Or via web cron service.
 *
 * Uses Asia/Yangon timezone for all deadline comparisons.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/job_helpers.php';

echo "Checking overdue milestones (" . date('Y-m-d H:i:s') . " Asia/Yangon)...\n";
check_milestone_overdue($conn);
echo "Done.\n";
