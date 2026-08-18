<?php
require_once __DIR__ . '/config/db.php';

$queries = [
    "ALTER TABLE jobs ADD INDEX idx_jobs_status_cat (status, category)",
    "ALTER TABLE jobs ADD INDEX idx_jobs_created (created_at)",
    "ALTER TABLE jobs ADD INDEX idx_jobs_company (company_id)",
    "ALTER TABLE job_applications ADD INDEX idx_ja_job_freelancer (job_id, freelancer_id)",
    "ALTER TABLE assignments ADD INDEX idx_assignments_job (job_id, status)",
    "ALTER TABLE freelancer_skills ADD INDEX idx_fs_freelancer (freelancer_id)",
    "ALTER TABLE freelancer_skills ADD INDEX idx_fs_skill (skill_id)",
    "ALTER TABLE job_skills ADD INDEX idx_js_job (job_id)",
    "ALTER TABLE job_skills ADD INDEX idx_js_skill (skill_id)",
    "ALTER TABLE reviews ADD INDEX idx_reviews_freelancer (freelancer_id)"
];

foreach ($queries as $q) {
    try {
        $conn->query($q);
        echo "Ran: $q\n";
    } catch (Exception $e) {
        // Ignore if exists
        echo "Skipped: " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
