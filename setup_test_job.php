<?php
require 'config/db.php';

// Create a job for Company (user_id=12, company_id=1)
$conn->query("INSERT INTO jobs (company_id, title, category, description, budget, deadline, experience_level, gender_requirement, visibility, status, duration) VALUES (1, 'Test Milestone Job', 'Development', 'Test Desc', 200000, '2026-12-31', 'any', 'any', 'public', 'open', '1 month')");
$job_id = $conn->insert_id;

// Create template milestones
$conn->query("INSERT INTO milestones (job_id, title, description, amount, status, sort_order) VALUES ($job_id, 'Milestone 1', 'Desc 1', 100000, 'draft', 1)");
$conn->query("INSERT INTO milestones (job_id, title, description, amount, status, sort_order) VALUES ($job_id, 'Milestone 2', 'Desc 2', 100000, 'draft', 2)");

// Create freelancer application (freelancer_id=13, user_id=13)
$conn->query("INSERT INTO job_applications (job_id, freelancer_id, cover_letter, bid_amount, payment_type, status, match_score) VALUES ($job_id, 13, 'Hire me', 200000, 'milestone', 'pending', 90)");
$application_id = $conn->insert_id;

echo "Job ID: $job_id, Application ID: $application_id\n";
