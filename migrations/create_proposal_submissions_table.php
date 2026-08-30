<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/db.php';

// Disable strict exception mode so we can handle errors gracefully
mysqli_report(MYSQLI_REPORT_OFF);

$errors = [];

// Step 1: Create proposal_projects (parent table)
$sql1 = "CREATE TABLE IF NOT EXISTS proposal_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    company_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    instructions TEXT,
    attachment VARCHAR(255) DEFAULT NULL,
    deadline DATETIME NOT NULL,
    status ENUM('pending','accepted','rejected','in_progress','submitted','reviewed','hired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql1)) {
    echo "✔ proposal_projects table OK.<br>";
} else {
    echo "✘ proposal_projects error: " . $conn->error . "<br>";
    $errors[] = 'proposal_projects';
}

// Step 2: Create proposal_project_submissions (child table)
$sql2 = "CREATE TABLE IF NOT EXISTS proposal_project_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proposal_project_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    file VARCHAR(255) DEFAULT NULL,
    github_link VARCHAR(255) DEFAULT NULL,
    comment TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_project_id) REFERENCES proposal_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql2)) {
    echo "✔ proposal_project_submissions table OK.<br>";
} else {
    echo "✘ proposal_project_submissions error: " . $conn->error . "<br>";
    $errors[] = 'proposal_project_submissions';
}

if (empty($errors)) {
    echo "<br><strong>All done! Both tables are ready.</strong>";
} else {
    echo "<br><strong>Some tables had errors: " . implode(', ', $errors) . "</strong>";
}
