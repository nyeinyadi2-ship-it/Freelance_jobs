<?php
/**
 * End-to-end workflow verification script.
 * Run: php tests/verify_workflow.php
 */

require_once __DIR__ . '/../config/db.php';

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    echo "PASS: $msg\n";
}

echo "Starting workflow verification...\n\n";

// Admin exists
$stmt = $conn->prepare("SELECT id FROM users WHERE email = 'admin@platform.com' AND role = 'admin'");
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
assert_true($admin !== null, 'Admin user seeded');

// Skills exist
$result = $conn->query('SELECT COUNT(*) AS cnt FROM skills');
assert_true((int) $result->fetch_assoc()['cnt'] >= 5, 'Sample skills seeded');

// Create test company user
$company_email = 'testco_' . time() . '@test.com';
$pass = password_hash('test1234', PASSWORD_DEFAULT);
$username = 'testco';
$role = 'company';
$stmt = $conn->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
$stmt->bind_param('ssss', $username, $company_email, $pass, $role);
$stmt->execute();
$company_user_id = $stmt->insert_id;
$stmt->close();

$company_name = 'Test Co';
$stmt = $conn->prepare('INSERT INTO companies (user_id, company_name) VALUES (?, ?)');
$stmt->bind_param('is', $company_user_id, $company_name);
$stmt->execute();
$company_id = $stmt->insert_id;
$stmt->close();
assert_true($company_id > 0, 'Company profile created');

// Create test freelancer
$fl_email = 'testfl_' . time() . '@test.com';
$fl_username = 'testfl';
$fl_role = 'freelancer';
$stmt = $conn->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
$stmt->bind_param('ssss', $fl_username, $fl_email, $pass, $fl_role);
$stmt->execute();
$fl_user_id = $stmt->insert_id;
$stmt->close();

$full_name = 'Test Freelancer';
$stmt = $conn->prepare('INSERT INTO freelancers (user_id, full_name) VALUES (?, ?)');
$stmt->bind_param('is', $fl_user_id, $full_name);
$stmt->execute();
$freelancer_id = $stmt->insert_id;
$stmt->close();
assert_true($freelancer_id > 0, 'Freelancer profile created');

// Company posts job
$title = 'Test Job';
$desc = 'Test description';
$budget = 500.00;
$status = 'approved';
$stmt = $conn->prepare('INSERT INTO jobs (company_id, title, description, budget, status) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('issds', $company_id, $title, $desc, $budget, $status);
$stmt->execute();
$job_id = $stmt->insert_id;
$stmt->close();
assert_true($job_id > 0, 'Job posted as approved');

$stmt = $conn->prepare('SELECT status FROM jobs WHERE id = ?');
$stmt->bind_param('i', $job_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
assert_true($row['status'] === 'approved', 'Job is immediately approved');

// Freelancer applies
$stmt = $conn->prepare('INSERT INTO job_applications (job_id, freelancer_id) VALUES (?, ?)');
$stmt->bind_param('ii', $job_id, $freelancer_id);
$stmt->execute();
$app_id = $stmt->insert_id;
$stmt->close();
assert_true($app_id > 0, 'Freelancer applied');

// Company hires
$accepted = 'accepted';
$stmt = $conn->prepare("UPDATE job_applications SET status = ? WHERE id = ?");
$stmt->bind_param('si', $accepted, $app_id);
$stmt->execute();
$stmt->close();

$assigned = 'assigned';
$stmt = $conn->prepare('INSERT INTO assignments (job_id, freelancer_id, status) VALUES (?, ?, ?)');
$stmt->bind_param('iis', $job_id, $freelancer_id, $assigned);
$stmt->execute();
$assignment_id = $stmt->insert_id;
$stmt->close();
assert_true($assignment_id > 0, 'Assignment created');

// Freelancer submits
$link = 'https://example.com/work.pdf';
$submitted = 'submitted';
$stmt = $conn->prepare("UPDATE assignments SET submission_link = ?, status = ? WHERE id = ?");
$stmt->bind_param('ssi', $link, $submitted, $assignment_id);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare('SELECT status FROM assignments WHERE id = ?');
$stmt->bind_param('i', $assignment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
assert_true($row['status'] === 'submitted', 'Work submitted');

// Company completes and pays
$completed = 'completed';
$stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE id = ?");
$stmt->bind_param('si', $completed, $assignment_id);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
$stmt->bind_param('si', $completed, $job_id);
$stmt->execute();
$stmt->close();

$paid = 'paid';
$stmt = $conn->prepare("INSERT INTO payments (assignment_id, amount, status, paid_at) VALUES (?, ?, ?, NOW())");
$stmt->bind_param('ids', $assignment_id, $budget, $paid);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare('SELECT status FROM payments WHERE assignment_id = ?');
$stmt->bind_param('i', $assignment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
assert_true($row['status'] === 'paid', 'Payment processed');

// Cleanup test data
$conn->query("DELETE FROM users WHERE id IN ($company_user_id, $fl_user_id)");

echo "\nAll workflow checks passed!\n";
