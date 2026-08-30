<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
if (!$company_id) {
    redirect('auth/login.php');
}

$application_id = (int) ($_REQUEST['application_id'] ?? 0);
$proposal_id = (int) ($_REQUEST['proposal_id'] ?? 0);

if (!$application_id) {
    set_flash('error', 'Missing application details.');
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare("
    SELECT ja.id, ja.job_id, ja.freelancer_id, ja.status,
           j.title, j.payment_type, j.budget,
           f.full_name AS freelancer_name
    FROM job_applications ja
    JOIN jobs j ON ja.job_id = j.id
    JOIN freelancers f ON ja.freelancer_id = f.id
    WHERE ja.id = ? AND j.company_id = ?
");
$stmt->bind_param('ii', $application_id, $company_id);
$stmt->execute();
$app = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$app) {
    set_flash('error', 'Application not found or invalid matching IDs.');
    redirect('company/manage_jobs.php');
}

if ($app['status'] === 'rejected') {
    set_flash('error', 'Cannot hire from a rejected application.');
    redirect('company/view_applications.php?id=' . $app['job_id']);
} elseif ($app['status'] === 'withdrawn') {
    set_flash('error', 'Cannot hire from a withdrawn application.');
    redirect('company/view_applications.php?id=' . $app['job_id']);
} elseif ($app['status'] === 'accepted') {
    set_flash('error', 'This freelancer has already been hired for this application.');
    redirect('company/view_applications.php?id=' . $app['job_id']);
} elseif ($app['status'] !== 'pending') {
    set_flash('error', 'Application is not eligible for hiring.');
    redirect('company/view_applications.php?id=' . $app['job_id']);
}

$job_id = (int) $app['job_id'];

$payment_type = $app['payment_type'];
$budget = (float) $app['budget'];

$conn->begin_transaction();
try {
    // Check if the job already has an active assignment (since we only allow 1 freelancer per job)
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ? AND status NOT IN ('rejected', 'cancelled')");
    $stmt->bind_param('i', $job_id);
    $stmt->execute();
    $existing_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();

    if ($existing_count >= 1) {
        throw new Exception('This job already has a hired freelancer.');
    }

    // Accept application
    $stmt = $conn->prepare("UPDATE job_applications SET status = 'accepted' WHERE id = ?");
    $stmt->bind_param('i', $application_id);
    $stmt->execute();
    $stmt->close();

    $assigned_budget = $budget;

    // Create assignment
    $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, status, payment_type, budget) VALUES (?, ?, 'assigned', ?, ?)");
    $stmt->bind_param('iisd', $job_id, $app['freelancer_id'], $payment_type, $assigned_budget);
    $stmt->execute();
    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        throw new Exception('Failed to create assignment.');
    }
    $assignment_id = $stmt->insert_id;
    $stmt->close();

    // Automatically assign all project milestones to this freelancer
    if ($payment_type === 'milestone') {
        $stmt_ms = $conn->prepare("UPDATE milestones SET freelancer_id = ? WHERE job_id = ? AND freelancer_id IS NULL");
        $stmt_ms->bind_param('ii', $app['freelancer_id'], $job_id);
        $stmt_ms->execute();
        $stmt_ms->close();
    }

    // Reject all other pending applications for this job
    $stmt = $conn->prepare("UPDATE job_applications SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
    $stmt->bind_param('ii', $job_id, $application_id);
    $stmt->execute();
    $stmt->close();

    // Mark job as hired
    $new_status = 'hired';
    $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $new_status, $job_id);
    $stmt->execute();
    $stmt->close();

    if ($proposal_id > 0) {
        $conn->query("UPDATE proposal_projects SET status = 'approved' WHERE id = " . $proposal_id);
    }

    $stmt = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
    $stmt->bind_param('i', $app['freelancer_id']);
    $stmt->execute();
    $fl_user_id = $stmt->get_result()->fetch_assoc()['user_id'];
    $stmt->close();

    if ($fl_user_id) {
        create_notification($conn, (int) $fl_user_id, 'hired', "Hired you for \"{$app['title']}\".", 'freelancer/my_tasks.php', $user_id);
    }

    $conn->commit();
    set_flash('success', 'Freelancer hired successfully.');
    redirect('company/view_job.php?id=' . $job_id);
} catch (Exception $e) {
    $conn->rollback();
    set_flash('error', 'Could not hire freelancer: ' . $e->getMessage());
    redirect('company/view_applications.php?id=' . $job_id);
}
