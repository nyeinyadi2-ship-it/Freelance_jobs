<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/job_helpers.php';

echo "Checking assignment deadlines...\n";
check_assignment_deadlines($conn);
echo "Done.\n";
