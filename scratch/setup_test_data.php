<?php
require 'config/db.php';

// Find a company and freelancer
$comp_res = $conn->query("SELECT id, email FROM users WHERE role = 'company' LIMIT 1");
$company = $comp_res->fetch_assoc();

$free_res = $conn->query("SELECT id, email FROM users WHERE role = 'freelancer' LIMIT 1");
$freelancer = $free_res->fetch_assoc();

// Reset passwords
$password_hash = password_hash('password', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET password = '$password_hash' WHERE id IN ({$company['id']}, {$freelancer['id']})");

// Ensure an active job exists with this company and freelancer
$job_res = $conn->query("SELECT id FROM jobs WHERE company_id = {$company['id']} AND freelancer_id = {$freelancer['id']} AND status = 'active' LIMIT 1");

if ($job_res->num_rows == 0) {
    // create a job
    $conn->query("INSERT INTO jobs (company_id, freelancer_id, title, category, description, budget, deadline, experience_level, gender_requirement, visibility, status, duration) VALUES ({$company['id']}, {$freelancer['id']}, 'Overdue Test Job', 'Development', 'Test Desc', 200000, '2026-12-31', 'any', 'any', 'public', 'active', '1 month')");
    $job_id = $conn->insert_id;
} else {
    $job_id = $job_res->fetch_assoc()['id'];
}

// Ensure an active milestone exists
$deadline = date('Y-m-d H:i:s', time() - 3600); // 1 hour ago
$milestone_res = $conn->query("SELECT id FROM milestones WHERE job_id = $job_id LIMIT 1");
if ($milestone_res->num_rows == 0) {
    $conn->query("INSERT INTO milestones (job_id, title, description, amount, status, sort_order, deadline) VALUES ($job_id, 'Overdue Milestone', 'Testing Overdue', 100000, 'in_progress', 1, '$deadline')");
    $milestone_id = $conn->insert_id;
} else {
    $milestone_id = $milestone_res->fetch_assoc()['id'];
    $conn->query("UPDATE milestones SET status = 'in_progress', deadline = '$deadline' WHERE id = $milestone_id");
}

echo json_encode([
    'company' => $company,
    'freelancer' => $freelancer,
    'job_id' => $job_id,
    'milestone_id' => $milestone_id
]);
