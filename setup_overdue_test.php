<?php
require 'config/db.php';

// Find a company
$comp_res = $conn->query("SELECT u.id, u.email FROM users u WHERE u.role = 'company' LIMIT 1");
$company = $comp_res->fetch_assoc();
$company_id = $company['id'];
$company_email = $company['email'];

// Find a freelancer
$free_res = $conn->query("SELECT u.id, u.email FROM users u WHERE u.role = 'freelancer' LIMIT 1");
$freelancer = $free_res->fetch_assoc();
$freelancer_id = $freelancer['id'];
$freelancer_email = $freelancer['email'];

// Reset passwords to 'password' to be able to login
$password_hash = password_hash('password', PASSWORD_DEFAULT);
$conn->query("UPDATE users SET password = '$password_hash' WHERE id IN ($company_id, $freelancer_id)");

echo "Company: $company_email / password\n";
echo "Freelancer: $freelancer_email / password\n";

// Create an active job
$conn->query("INSERT INTO jobs (company_id, title, category, description, budget, deadline, experience_level, gender_requirement, visibility, status, duration) VALUES ($company_id, 'Overdue Test Job', 'Development', 'Test Desc', 200000, '2026-12-31', 'any', 'any', 'public', 'active', '1 month')");
$job_id = $conn->insert_id;
echo "Job ID: $job_id\n";

// Set a deadline 1 hour ago
$deadline = date('Y-m-d H:i:s', time() - 3600);

// Create a milestone that is 'in_progress' and has past deadline
$conn->query("INSERT INTO milestones (job_id, title, description, amount, status, sort_order, deadline) VALUES ($job_id, 'Overdue Milestone', 'Testing Overdue', 100000, 'in_progress', 1, '$deadline')");
$milestone_id = $conn->insert_id;
echo "Milestone ID: $milestone_id\n";

// Create assignment record (assuming assignment is required)
$conn->query("INSERT INTO job_assignments (job_id, freelancer_id, assigned_at, status) VALUES ($job_id, $freelancer_id, NOW(), 'active')");
$assignment_id = $conn->insert_id;
echo "Assignment ID: $assignment_id\n";
