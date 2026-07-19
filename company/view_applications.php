<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? $_POST['job_id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', __('error.invalid_job'));
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT id, title, budget, status FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', __('error.job_not_found'));
    redirect('company/manage_jobs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept') {
        $application_id = (int) ($_POST['application_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT ja.id, ja.freelancer_id
            FROM job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $application = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($application) {
            $conn->begin_transaction();
            try {
                $freelancer_id = (int) $application['freelancer_id'];

                $stmt = $conn->prepare("UPDATE job_applications SET status = 'accepted' WHERE id = ?");
                $stmt->bind_param('i', $application_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE job_applications SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
                $stmt->bind_param('ii', $job_id, $application_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, status) VALUES (?, ?, 'assigned')");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();
                $assignment_id = $stmt->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                $stmt->bind_param('i', $freelancer_id);
                $stmt->execute();
                $fl_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($fl_user) {
                    create_notification($conn, (int) $fl_user['id'], 'hired', "You have been hired for \"{$job['title']}\". Check your tasks.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                set_flash('success', __('success.freelancer_hired'));
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', __('error.could_not_hire'));
            }
        } else {
            set_flash('error', __('error.application_not_found'));
        }
    } elseif ($action === 'reject') {
        $application_id = (int) ($_POST['application_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT ja.freelancer_id
            FROM job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $rejected_app = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE job_applications ja
            JOIN jobs j ON ja.job_id = j.id
            SET ja.status = 'rejected'
            WHERE ja.id = ? AND ja.job_id = ? AND j.company_id = ? AND ja.status = 'pending'
        ");
        $stmt->bind_param('iii', $application_id, $job_id, $company_id);
        $stmt->execute();
        $stmt->close();

        if ($rejected_app) {
            $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
            $stmt->bind_param('i', $rejected_app['freelancer_id']);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($fl_user) {
                create_notification($conn, (int) $fl_user['id'], 'rejected', "Your application for \"{$job['title']}\" has been rejected.", 'freelancer/browse_jobs.php');
            }
        }

        set_flash('success', __('success.application_rejected'));
    } elseif ($action === 'complete_payment') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT a.id, a.status, j.budget
            FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.id = ? AND a.job_id = ? AND j.company_id = ? AND a.status = 'submitted'
        ");
        $stmt->bind_param('iii', $assignment_id, $job_id, $company_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($assignment) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $stmt->close();

                $amount = (float) $assignment['budget'];
                $paid_status = 'paid';
                $stmt = $conn->prepare("INSERT INTO payments (assignment_id, amount, status, paid_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param('ids', $assignment_id, $amount, $paid_status);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $fl_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($fl_user) {
                    create_notification($conn, (int) $fl_user['id'], 'work_approved', "Your work for \"{$job['title']}\" has been approved.", 'freelancer/my_tasks.php');
                    create_notification($conn, (int) $fl_user['id'], 'payment_released', "Payment of \${$amount} for \"{$job['title']}\" has been released.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                set_flash('success', __('success.work_paid'));
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', __('error.could_not_pay'));
            }
        } else {
            set_flash('error', __('error.assignment_not_ready'));
        }
    } elseif ($action === 'request_revision') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);

        $stmt = $conn->prepare("
            SELECT a.id, a.status, j.title
            FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            WHERE a.id = ? AND a.job_id = ? AND j.company_id = ? AND a.status = 'submitted'
        ");
        $stmt->bind_param('iii', $assignment_id, $job_id, $company_id);
        $stmt->execute();
        $assignment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($assignment) {
            $stmt = $conn->prepare("UPDATE assignments SET status = 'assigned', submission_link = NULL WHERE id = ?");
            $stmt->bind_param('i', $assignment_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.id = ?");
            $stmt->bind_param('i', $assignment_id);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($fl_user) {
                create_notification($conn, (int) $fl_user['id'], 'revision_requested', "Revision requested for \"{$job['title']}\". Please update and resubmit your work.", 'freelancer/my_tasks.php');
            }

            set_flash('success', 'Revision requested. Freelancer has been notified.');
        } else {
            set_flash('error', 'Assignment is not in submitted status.');
        }
    } elseif ($action === 'submit_review') {
        $assignment_id = (int) ($_POST['assignment_id'] ?? 0);
        $rating = (int) ($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');

        if ($rating < 1 || $rating > 5) {
            set_flash('error', 'Please select a valid rating (1-5).');
        } else {
            $stmt = $conn->prepare("
                SELECT a.id, a.freelancer_id
                FROM assignments a
                JOIN jobs j ON a.job_id = j.id
                WHERE a.id = ? AND a.job_id = ? AND j.company_id = ? AND a.status = 'completed'
            ");
            $stmt->bind_param('iii', $assignment_id, $job_id, $company_id);
            $stmt->execute();
            $assignment_data = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($assignment_data) {
                $stmt = $conn->prepare("SELECT id FROM reviews WHERE assignment_id = ?");
                $stmt->bind_param('i', $assignment_id);
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($existing) {
                    set_flash('error', 'You have already reviewed this project.');
                } else {
                    $fl_id = (int) $assignment_data['freelancer_id'];
                    $uid = (int) $user['user_id'];
                    $stmt = $conn->prepare("INSERT INTO reviews (assignment_id, freelancer_id, company_user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param('iiiss', $assignment_id, $fl_id, $uid, $rating, $comment);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                    $stmt->bind_param('i', $fl_id);
                    $stmt->execute();
                    $fl_user = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($fl_user) {
                        create_notification($conn, (int) $fl_user['id'], 'review_received', "You received a {$rating}-star review for \"{$job['title']}\".", 'freelancer/profile.php');
                    }

                    set_flash('success', 'Review submitted successfully!');
                }
            } else {
                set_flash('error', 'Assignment not found or not completed.');
            }
        }
    } elseif (isset($_POST['ms_action'])) {
        // Milestone actions
        $ms_action = $_POST['ms_action'];
        $milestone_id = (int) ($_POST['milestone_id'] ?? 0);

        if ($ms_action === 'fund' && $milestone_id > 0) {
            $stmt = $conn->prepare("SELECT m.id, m.amount, m.status FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'draft'");
            $stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
            $stmt->execute();
            $ms = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ms) {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE milestones SET status = 'funded' WHERE id = ?");
                    $stmt->bind_param('i', $milestone_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO escrow (milestone_id, amount, status) VALUES (?, ?, 'held')");
                    $stmt->bind_param('id', $milestone_id, $ms['amount']);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();
                    set_flash('success', 'Milestone funded successfully! Escrow is active.');
                } catch (Exception $e) {
                    $conn->rollback();
                    set_flash('error', 'Failed to fund milestone.');
                }
            } else {
                set_flash('error', 'Milestone not found or already funded.');
            }
        } elseif ($ms_action === 'approve' && $milestone_id > 0) {
            $stmt = $conn->prepare("SELECT m.id, m.amount, m.status FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'submitted'");
            $stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
            $stmt->execute();
            $ms = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ms) {
                $conn->begin_transaction();
                try {
                    $now = date('Y-m-d H:i:s');
                    $stmt = $conn->prepare("UPDATE milestones SET status = 'approved', approved_at = ? WHERE id = ?");
                    $stmt->bind_param('si', $now, $milestone_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE escrow SET status = 'released', released_at = ? WHERE milestone_id = ? AND status = 'held'");
                    $stmt->bind_param('si', $now, $milestone_id);
                    $stmt->execute();
                    $stmt->close();

                    // Create payment record (skip if one already exists for this assignment)
                    $stmt = $conn->prepare("SELECT a.id FROM assignments a WHERE a.job_id = ?");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $assignment_row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($assignment_row) {
                        $chk = $conn->prepare("SELECT id FROM payments WHERE assignment_id = ? AND status = 'paid' LIMIT 1");
                        $chk->bind_param('i', $assignment_row['id']);
                        $chk->execute();
                        $existing = $chk->get_result()->fetch_assoc();
                        $chk->close();
                        if (!$existing) {
                            $stmt = $conn->prepare("INSERT INTO payments (assignment_id, amount, status, paid_at) VALUES (?, ?, 'paid', ?)");
                            $stmt->bind_param('ids', $assignment_row['id'], $ms['amount'], $now);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }

                    // Check if all milestones are approved → complete job
                    $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved FROM milestones WHERE job_id = ?");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $counts = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    if ($counts && $counts['total'] == $counts['approved']) {
                        $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $stmt->close();
                        $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE job_id = ?");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        // Not all done yet — set assignment back to working for next milestone
                        $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND status = 'submitted'");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();

                    // Notify freelancer (after commit so notification failure doesn't roll back approval)
                    try {
                        $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.job_id = ?");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $fl_user = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($fl_user) {
                            create_notification($conn, (int) $fl_user['id'], 'payment_released', "Milestone payment of \${$ms['amount']} has been released.", 'freelancer/my_tasks.php');
                        }
                    } catch (Exception $ne) {
                        error_log("Notification failed after milestone approval: " . $ne->getMessage());
                    }

                    set_flash('success', 'Milestone approved and payment released!');
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Milestone approve failed: " . $e->getMessage());
                    set_flash('error', 'Failed to approve milestone.');
                }
            } else {
                set_flash('error', 'Milestone not found or not submitted.');
            }
        } elseif ($ms_action === 'revision' && $milestone_id > 0) {
            $stmt = $conn->prepare("SELECT m.id, m.status FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'submitted'");
            $stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
            $stmt->execute();
            $ms = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ms) {
                $conn->begin_transaction();
                try {
                    $del_stmt = $conn->prepare("SELECT submission_file FROM milestones WHERE id = ?");
                    $del_stmt->bind_param('i', $milestone_id);
                    $del_stmt->execute();
                    $del_row = $del_stmt->get_result()->fetch_assoc();
                    $del_stmt->close();

                    $stmt = $conn->prepare("UPDATE milestones SET status = 'revision_requested', submission_link = NULL, submission_file = NULL, submission_note = NULL, submitted_at = NULL WHERE id = ?");
                    $stmt->bind_param('i', $milestone_id);
                    $stmt->execute();
                    $stmt->close();

                    if ($del_row && !empty($del_row['submission_file'])) {
                        delete_attachment($del_row['submission_file']);
                    }

                    $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND status = 'submitted'");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    // Notify freelancer (after commit so notification failure doesn't roll back)
                    try {
                        $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.job_id = ?");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $fl_user = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($fl_user) {
                            create_notification($conn, (int) $fl_user['id'], 'revision_requested', "Revision requested for a milestone in \"{$job['title']}\".", 'freelancer/my_tasks.php');
                        }
                    } catch (Exception $ne) {
                        error_log("Notification failed after revision request: " . $ne->getMessage());
                    }

                    set_flash('success', 'Revision requested. Freelancer has been notified.');
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Milestone revision failed: " . $e->getMessage());
                    set_flash('error', 'Failed to request revision.');
                }
            } else {
                set_flash('error', 'Milestone not found or not submitted.');
            }
        }

        redirect('company/view_applications.php?id=' . $job_id);
    }

    redirect('company/view_applications.php?id=' . $job_id);
}

$applications = [];
$stmt = $conn->prepare("
    SELECT ja.id, ja.status, ja.applied_at, f.full_name, f.portfolio_url, u.email, u.profile_image
    FROM job_applications ja
    JOIN freelancers f ON ja.freelancer_id = f.id
    JOIN users u ON f.user_id = u.id
    WHERE ja.job_id = ?
    ORDER BY ja.applied_at DESC
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $applications[] = $row;
}
$stmt->close();

$assignment = null;
$payment = null;
$stmt = $conn->prepare("
    SELECT a.id, a.status, a.submission_link, a.assigned_at, f.full_name
    FROM assignments a
    JOIN freelancers f ON a.freelancer_id = f.id
    WHERE a.job_id = ?
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($assignment) {
    $stmt = $conn->prepare('SELECT id, amount, status, paid_at FROM payments WHERE assignment_id = ?');
    $stmt->bind_param('i', $assignment['id']);
    $stmt->execute();
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare('SELECT id, rating, comment, created_at FROM reviews WHERE assignment_id = ?');
    $stmt->bind_param('i', $assignment['id']);
    $stmt->execute();
    $existing_review = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Fetch milestones
$milestones = [];
$stmt = $conn->prepare("SELECT m.*, e.status AS escrow_status, e.funded_at, e.released_at FROM milestones m LEFT JOIN escrow e ON e.milestone_id = m.id WHERE m.job_id = ? ORDER BY m.sort_order ASC");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$mr = $stmt->get_result();
while ($row = $mr->fetch_assoc()) { $milestones[] = $row; }
$stmt->close();

$total_milestones = count($milestones);
$approved_count = 0;
$funded_count = 0;
$total_milestone_amount = 0;
foreach ($milestones as $m) {
    if ($m['status'] === 'approved') $approved_count++;
    if ($m['status'] === 'funded' || $m['status'] === 'in_progress' || $m['status'] === 'submitted' || $m['status'] === 'approved') $funded_count++;
    $total_milestone_amount += (float) $m['amount'];
}
$all_approved = $total_milestones > 0 && $approved_count === $total_milestones;

$page_title = __('company.applications');
require __DIR__ . '/../includes/header.php';
?>

<div class="mb-6">
    <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-indigo-600 hover:underline text-sm">&larr; <?= __('company.back_jobs') ?></a>
    <h1 class="text-2xl font-bold mt-2" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h1>
    <p style="color:var(--color-text-muted)"><?= __('company.table.budget') ?>: $<?= e(number_format((float) $job['budget'], 2)) ?> &middot; <?= status_badge($job['status']) ?></p>
</div>

<?php if ($assignment): ?>
    <div class="card mb-6">
        <h2 class="text-lg font-semibold mb-3"><?= __('company.assignment') ?></h2>
        <p class="text-sm mb-2" style="color:var(--color-text-secondary)"><?= __('company.assigned_to') ?>: <strong><?= e($assignment['full_name']) ?></strong></p>
        <p class="mb-3">Status: <?= status_badge($all_approved ? 'completed' : $assignment['status']) ?></p>

        <!-- Milestones Section -->
        <?php if (!empty($milestones)): ?>
        <div class="mt-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-bold" style="color:var(--color-text-primary)">Project Milestones</h3>
                <span class="text-xs font-semibold" style="color:var(--color-text-muted)"><?= $approved_count ?>/<?= $total_milestones ?> completed &middot; $<?= number_format($total_milestone_amount, 2) ?> total</span>
            </div>

            <!-- Progress bar -->
            <div class="mb-4">
                <div class="w-full h-2 rounded-full" style="background:var(--color-border)">
                    <div class="h-2 rounded-full transition-all duration-500" style="width:<?= $total_milestones > 0 ? round(($approved_count / $total_milestones) * 100) : 0 ?>%;background:linear-gradient(135deg,#10b981,#34d399)"></div>
                </div>
            </div>

            <div class="space-y-3">
                <?php foreach ($milestones as $ms): ?>
                <div class="p-4 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                    <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                        <div class="flex items-center gap-2">
                            <?php if ($ms['status'] === 'approved'): ?>
                                <div class="w-6 h-6 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                </div>
                            <?php else: ?>
                                <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-bold" style="background:var(--color-card);border:1.5px solid var(--color-border);color:var(--color-text-muted)"><?= $ms['sort_order'] ?></div>
                            <?php endif; ?>
                            <div>
                                <p class="text-sm font-bold" style="color:var(--color-text-primary)"><?= e($ms['title']) ?></p>
                                <?php if ($ms['description']): ?><p class="text-xs" style="color:var(--color-text-muted)"><?= e($ms['description']) ?></p><?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-bold" style="color:#f59e0b">$<?= number_format((float) $ms['amount'], 2) ?></span>
                            <?php
                            $ms_labels = ['draft'=>'Draft','funded'=>'Funded','in_progress'=>'In Progress','submitted'=>'Submitted','approved'=>'Approved','revision_requested'=>'Revision'];
                            $ms_colors = ['draft'=>'#6b7280','funded'=>'#f59e0b','in_progress'=>'#6366f1','submitted'=>'#8b5cf6','approved'=>'#10b981','revision_requested'=>'#ef4444'];
                            $ms_label = $ms_labels[$ms['status']] ?? $ms['status'];
                            $ms_color = $ms_colors[$ms['status']] ?? '#6b7280';
                            ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold" style="background:<?= $ms_color ?>15;color:<?= $ms_color ?>"><?= $ms_label ?></span>
                        </div>
                    </div>

                    <?php if ($ms['status'] === 'submitted'): ?>
                        <div class="mt-2 space-y-1.5">
                            <?php if ($ms['submission_link']): ?>
                                <div class="flex items-center gap-2 text-xs" style="color:var(--color-text-muted)">
                                    <svg class="w-3.5 h-3.5 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    Link: <a href="<?= e($ms['submission_link']) ?>" target="_blank" class="text-indigo-600 hover:underline">View Submission</a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($ms['submission_file'])): ?>
                                <div class="flex items-center gap-2 text-xs" style="color:var(--color-text-muted)">
                                    <svg class="w-3.5 h-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    File: <a href="<?= e(base_url('uploads/attachments/' . $ms['submission_file'])) ?>" target="_blank" class="text-emerald-600 hover:underline"><?= e($ms['submission_file']) ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($ms['submission_note'])): ?>
                                <div class="mt-2 p-2.5 rounded-lg text-xs leading-relaxed" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-secondary)">
                                    <?= nl2br(e($ms['submission_note'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Action buttons per milestone -->
                    <div class="mt-3 flex flex-wrap gap-2">
                        <?php if ($ms['status'] === 'draft'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="ms_action" value="fund">
                                <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#f59e0b,#d97706);box-shadow:0 2px 8px rgba(245,158,11,0.3)" onclick="return confirm('Fund this milestone with $<?= number_format((float) $ms['amount'], 2) ?> via Escrow?')">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                                    Fund Escrow
                                </button>
                            </form>
                        <?php elseif ($ms['status'] === 'funded'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#f59e0b">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                                Escrow funded — awaiting freelancer
                            </span>
                        <?php elseif ($ms['status'] === 'in_progress'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#6366f1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Freelancer working — awaiting submission
                            </span>
                        <?php elseif ($ms['status'] === 'submitted'): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="ms_action" value="approve">
                                <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 2px 8px rgba(16,185,129,0.3)" onclick="return confirm('Approve this milestone and release $<?= number_format((float) $ms['amount'], 2) ?> payment?')">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    Approve & Pay
                                </button>
                            </form>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="ms_action" value="revision">
                                <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all" style="border:1.5px solid var(--color-border);color:var(--color-text-secondary)" onclick="return confirm('Request revision for this milestone?')">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    Request Revision
                                </button>
                            </form>
                        <?php elseif ($ms['status'] === 'approved'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#10b981">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Payment released <?= $ms['approved_at'] ? date('M j', strtotime($ms['approved_at'])) : '' ?>
                            </span>
                        <?php elseif ($ms['status'] === 'revision_requested'): ?>
                            <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:#ef4444">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                Awaiting resubmission
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm" style="color:var(--color-text-muted)">No milestones defined for this project.</p>
        <?php endif; ?>

        <!-- Review section (after all milestones approved) -->
        <?php if ($all_approved && $assignment['status'] === 'completed'): ?>
            <?php if (!empty($existing_review)): ?>
                <div class="mt-4 p-4 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03));border:1px solid var(--color-border)">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <p class="text-sm font-semibold" style="color:var(--color-text-primary)">Your Review</p>
                        <span class="text-xs" style="color:var(--color-text-muted)"><?= e($existing_review['created_at']) ?></span>
                    </div>
                    <div class="flex items-center gap-1 mb-2">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <svg class="w-4 h-4 <?= $s <= $existing_review['rating'] ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                        <span class="text-sm font-semibold ml-1" style="color:var(--color-text-primary)"><?= $existing_review['rating'] ?>/5</span>
                    </div>
                    <?php if ($existing_review['comment']): ?>
                        <p class="text-sm" style="color:var(--color-text-secondary)"><?= nl2br(e($existing_review['comment'])) ?></p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="mt-4 p-5 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03));border:1px solid var(--color-border)">
                    <h3 class="text-sm font-bold mb-3 flex items-center gap-2" style="color:var(--color-text-primary)">
                        <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        Rate this Freelancer
                    </h3>
                    <form method="POST" id="reviewForm">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="job_id" value="<?= $job_id ?>">
                        <input type="hidden" name="action" value="submit_review">
                        <input type="hidden" name="assignment_id" value="<?= (int) $assignment['id'] ?>">
                        <input type="hidden" name="rating" id="reviewRating" value="0">
                        <div class="flex items-center gap-1 mb-4" id="starRating">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <button type="button" class="star-btn transition-colors" data-star="<?= $s ?>" onclick="setRating(<?= $s ?>)">
                                    <svg class="w-7 h-7 text-gray-300 dark:text-gray-600 hover:text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                </button>
                            <?php endfor; ?>
                            <span class="text-sm font-medium ml-2" style="color:var(--color-text-muted)" id="ratingLabel">Select a rating</span>
                        </div>
                        <textarea name="comment" rows="3" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500 mb-3" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Write your review about this freelancer..."></textarea>
                        <button type="submit" class="btn-primary text-sm" onclick="return validateReview()">Submit Review</button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="text-lg font-semibold mb-4"><?= __('company.applications_list') ?></h2>

    <?php if (empty($applications)): ?>
        <p style="color:var(--color-text-muted)"><?= __('company.no_job_applications') ?></p>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($applications as $app): ?>
                <div class="rounded-lg p-4 flex flex-wrap justify-between items-start gap-4" style="border:1px solid var(--color-border)">
                    <div class="flex items-start gap-3">
                        <?php $appImg = profile_image_url($app['profile_image']); ?>
                        <?php if ($appImg): ?>
                            <img src="<?= e($appImg) ?>" alt="" class="w-10 h-10 rounded-full object-cover border flex-shrink-0" style="border-color:var(--color-border)">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold flex-shrink-0" style="background:rgba(99,102,241,0.1)">
                                <?= e(strtoupper(substr($app['full_name'], 0, 1))) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="font-medium"><?= e($app['full_name']) ?></p>
                            <p class="text-sm" style="color:var(--color-text-muted)"><?= e($app['email']) ?></p>
                            <?php if ($app['portfolio_url']): ?>
                                <a href="<?= e($app['portfolio_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors" style="background:rgba(99,102,241,0.1);color:#4f46e5">&#128193; View Portfolio</a>
                            <?php endif; ?>
                            <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= __('company.applied') ?>: <?= e($app['applied_at']) ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?= status_badge($app['status']) ?>
                        <?php if ($app['status'] === 'pending' && !$assignment): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-primary text-sm"><?= __('company.accept') ?></button>
                            </form>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-danger text-sm"><?= __('admin.reject') ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<script>
function setRating(stars) {
    document.getElementById('reviewRating').value = stars;
    var btns = document.querySelectorAll('.star-btn');
    var labels = ['', 'Poor', 'Fair', 'Good', 'Very Good', 'Excellent'];
    btns.forEach(function(btn, i) {
        var svg = btn.querySelector('svg');
        if (i < stars) {
            svg.classList.remove('text-gray-300', 'dark:text-gray-600');
            svg.classList.add('text-amber-400');
        } else {
            svg.classList.remove('text-amber-400');
            svg.classList.add('text-gray-300', 'dark:text-gray-600');
        }
    });
    document.getElementById('ratingLabel').textContent = labels[stars] || 'Select a rating';
}

function validateReview() {
    if (document.getElementById('reviewRating').value === '0') {
        alert('Please select a rating before submitting.');
        return false;
    }
    return true;
}
</script>
