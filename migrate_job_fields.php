<?php
/**
 * Migration: Enhance jobs table with new fields and create job_skills table.
 * Run once: php migrate_job_fields.php
 */

require_once __DIR__ . '/config/db.php';

$migrations = [];

// 1. Add new columns to jobs table
$columns_to_add = [
    "ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT '' AFTER title",
    "ADD COLUMN experience_level ENUM('beginner','intermediate','expert') DEFAULT 'intermediate' AFTER category",
    "ADD COLUMN gender_requirement ENUM('any','male','female') DEFAULT 'any' AFTER experience_level",
    "ADD COLUMN deadline DATETIME NULL AFTER gender_requirement",
    "ADD COLUMN duration VARCHAR(100) DEFAULT NULL AFTER deadline",
    "ADD COLUMN freelancers_needed INT DEFAULT 1 AFTER duration",
    "ADD COLUMN visibility ENUM('public','private') DEFAULT 'public' AFTER freelancers_needed",
    "ADD COLUMN attachment VARCHAR(255) DEFAULT NULL AFTER visibility",
];

foreach ($columns_to_add as $col) {
    $col_name = preg_match('/ADD COLUMN (\w+)/', $col, $m) ? $m[1] : 'unknown';
    // Check if column already exists
    $check = $conn->query("SHOW COLUMNS FROM jobs LIKE '{$col_name}'");
    if ($check && $check->num_rows > 0) {
        echo "[SKIP] Column '{$col_name}' already exists.\n";
    } else {
        try {
            $conn->query("ALTER TABLE jobs {$col}");
            echo "[OK] Added column '{$col_name}'.\n";
        } catch (mysqli_sql_exception $e) {
            echo "[ERROR] Failed to add '{$col_name}': " . $e->getMessage() . "\n";
        }
    }
}

// 2. Create job_skills table (many-to-many: jobs <-> skills)
$create_job_skills = "
CREATE TABLE IF NOT EXISTS job_skills (
    job_id INT,
    skill_id INT,
    PRIMARY KEY (job_id, skill_id),
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
";

try {
    $conn->query($create_job_skills);
    echo "[OK] job_skills table created/verified.\n";
} catch (mysqli_sql_exception $e) {
    echo "[ERROR] job_skills table: " . $e->getMessage() . "\n";
}

echo "\nMigration complete.\n";
