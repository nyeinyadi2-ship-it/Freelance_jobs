<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/db.php';

$output = "Running migration...\n";

// 1. Update jobs table status enum
$sql = "ALTER TABLE jobs MODIFY COLUMN status ENUM('pending', 'approved', 'rejected', 'completed', 'position_filled', 'expired', 'closed') DEFAULT 'pending'";
if ($conn->query($sql)) {
    $output .= "Successfully updated jobs status enum.\n";
} else {
    $output .= "Error updating jobs status: " . $conn->error . "\n";
}

// 2. Create proposal_projects table
$sql = "
CREATE TABLE IF NOT EXISTS proposal_projects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    job_id INT NOT NULL,
    company_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    instructions TEXT,
    attachment VARCHAR(255) DEFAULT NULL,
    deadline DATETIME NOT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'submitted', 'reviewed', 'hired') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
);
";
if ($conn->query($sql)) {
    $output .= "Successfully created proposal_projects table.\n";
} else {
    $output .= "Error creating proposal_projects table: " . $conn->error . "\n";
}

// 3. Create proposal_project_submissions table
$sql = "
CREATE TABLE IF NOT EXISTS proposal_project_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    proposal_project_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    file VARCHAR(255) DEFAULT NULL,
    github_link VARCHAR(255) DEFAULT NULL,
    comment TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_project_id) REFERENCES proposal_projects(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancers(id) ON DELETE CASCADE
);
";
if ($conn->query($sql)) {
    $output .= "Successfully created proposal_project_submissions table.\n";
} else {
    $output .= "Error creating proposal_project_submissions table: " . $conn->error . "\n";
}

echo nl2br(htmlspecialchars($output));
