<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);
$job_id = (int) ($_GET['id'] ?? $_POST['job_id'] ?? 0);

if (!$company_id || $job_id <= 0) {
    set_flash('error', 'Invalid job.');
    redirect('company/manage_jobs.php');
}

$stmt = $conn->prepare('SELECT id, title, budget, status, freelancers_needed, deadline FROM jobs WHERE id = ? AND company_id = ?');
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    set_flash('error', 'Job not found.');
    redirect('company/manage_jobs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'accept') {
        $application_id = (int) ($_POST['application_id'] ?? 0);

        // Check hiring limit: count current assigned freelancers
        $stmt = $conn->prepare("SELECT freelancers_needed FROM jobs WHERE id = ? AND company_id = ?");
        $stmt->bind_param('ii', $job_id, $company_id);
        $stmt->execute();
        $job_info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $freelancers_needed = max(1, (int) ($job_info['freelancers_needed'] ?? 1));

        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ?");
        $stmt->bind_param('i', $job_id);
        $stmt->execute();
        $current_assigned = (int) $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($current_assigned >= $freelancers_needed) {
            set_flash('error', "This job only allows {$freelancers_needed} freelancer(s). All positions have been filled.");
            redirect('company/view_applications.php?id=' . $job_id);
        }

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

        $proposal_id = isset($_POST['proposal_id']) ? (int) $_POST['proposal_id'] : 0;

        if ($application) {
            $conn->begin_transaction();
            try {
                $freelancer_id = (int) $application['freelancer_id'];

                $stmt = $conn->prepare("UPDATE job_applications SET status = 'accepted' WHERE id = ?");
                $stmt->bind_param('i', $application_id);
                $stmt->execute();
                $stmt->close();

                // Create the assignment
                $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, status) VALUES (?, ?, 'assigned')");
                $stmt->bind_param('ii', $job_id, $freelancer_id);
                $stmt->execute();

                if ($stmt->affected_rows <= 0) {
                    $stmt->close();
                    throw new Exception('Failed to create assignment. The database may need migration (run migrate_hiring_workflow.php).');
                }
                $assignment_id = $stmt->insert_id;
                $stmt->close();
                // Count active assignments AFTER the insert to get accurate count
                $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE job_id = ?");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $new_assigned_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                // If all positions filled, reject remaining pending and mark job as position_filled
                if ($new_assigned_count >= $freelancers_needed) {
                    $stmt = $conn->prepare("UPDATE job_applications SET status = 'rejected' WHERE job_id = ? AND id != ? AND status = 'pending'");
                    $stmt->bind_param('ii', $job_id, $application_id);
                    $stmt->execute();
                    $stmt->close();

                    $new_status = 'position_filled';
                    $stmt = $conn->prepare("UPDATE jobs SET status = ? WHERE id = ?");
                    $stmt->bind_param('si', $new_status, $job_id);
                    $stmt->execute();
                    $stmt->close();
                }

                if ($proposal_id > 0) {
                    $conn->query("UPDATE proposal_projects SET status = 'hired' WHERE id = " . $proposal_id);
                }

                $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                $stmt->bind_param('i', $freelancer_id);
                $stmt->execute();
                $fl_user = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($fl_user) {
                    create_notification($conn, (int) $fl_user['id'], 'hired', "You have been hired for \"{$job['title']}\". Check your tasks.", 'freelancer/my_tasks.php');
                }

                $conn->commit();
                $remaining = $freelancers_needed - $new_assigned_count;
                $msg = $new_assigned_count >= $freelancers_needed
                    ? 'Freelancer hired! All positions are now filled.'
                    : "Freelancer hired successfully. {$remaining} position(s) remaining.";
                set_flash('success', $msg);
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Could not hire freelancer: ' . $e->getMessage());
            }
        } else {
            set_flash('error', 'Application not found or already processed.');
        }
    } elseif ($action === 'reject') {
        $application_id = (int) ($_POST['application_id'] ?? 0);
        $proposal_id = isset($_POST['proposal_id']) ? (int) $_POST['proposal_id'] : 0;

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
            if ($proposal_id > 0) {
                $conn->query("UPDATE proposal_projects SET status = 'rejected' WHERE id = " . $proposal_id);
            }

            $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
            $stmt->bind_param('i', $rejected_app['freelancer_id']);
            $stmt->execute();
            $fl_user = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($fl_user) {
                create_notification($conn, (int) $fl_user['id'], 'rejected', "Your application for \"{$job['title']}\" has been rejected.", 'freelancer/browse_jobs.php');
            }
        }

        set_flash('success', 'Application rejected.');
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

                // Only mark job as completed when ALL required positions are filled and completed
                $stmt = $conn->prepare("SELECT j.freelancers_needed, SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS done FROM jobs j LEFT JOIN assignments a ON j.id = a.job_id WHERE j.id = ? GROUP BY j.id");
                $stmt->bind_param('i', $job_id);
                $stmt->execute();
                $progress = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($progress && (int)$progress['done'] >= (int)$progress['freelancers_needed']) {
                    $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                    $stmt->bind_param('i', $job_id);
                    $stmt->execute();
                    $stmt->close();
                }

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
                set_flash('success', 'Work approved and payment processed.');
            } catch (Exception $e) {
                $conn->rollback();
                set_flash('error', 'Could not process payment.');
            }
        } else {
            set_flash('error', 'Assignment not found or not ready for payment.');
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

        if ($ms_action === 'create_milestone') {
            $ms_title = trim($_POST['ms_title'] ?? '');
            $ms_description = trim($_POST['ms_description'] ?? '');
            $ms_amount = (float) ($_POST['ms_amount'] ?? 0);
            $ms_deadline = trim($_POST['ms_deadline'] ?? '');
            $ms_freelancer_id = (int) ($_POST['ms_freelancer_id'] ?? 0);

            if ($ms_title === '') {
                set_flash('error', 'Milestone title is required.');
            } elseif ($ms_amount <= 0) {
                set_flash('error', 'Milestone amount must be greater than 0.');
            } elseif ($ms_freelancer_id <= 0) {
                set_flash('error', 'Please select a freelancer to assign this milestone.');
            } else {
                // Verify freelancer is assigned to this job
                $chk = $conn->prepare("SELECT id FROM assignments WHERE job_id = ? AND freelancer_id = ? AND status != 'completed'");
                $chk->bind_param('ii', $job_id, $ms_freelancer_id);
                $chk->execute();
                $valid_assignment = $chk->get_result()->fetch_assoc();
                $chk->close();

                if (!$valid_assignment) {
                    set_flash('error', 'Selected freelancer is not assigned to this job.');
                } else {
                    // Check budget limits
                    $stmt_budget = $conn->prepare("SELECT budget FROM jobs WHERE id = ?");
                    $stmt_budget->bind_param('i', $job_id);
                    $stmt_budget->execute();
                    $job_budget = (float) $stmt_budget->get_result()->fetch_assoc()['budget'];
                    $stmt_budget->close();

                    $stmt_sum = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM milestones WHERE job_id = ? AND freelancer_id = ?");
                    $stmt_sum->bind_param('ii', $job_id, $ms_freelancer_id);
                    $stmt_sum->execute();
                    $current_total = (float) $stmt_sum->get_result()->fetch_assoc()['total'];
                    $stmt_sum->close();

                    if (($current_total + $ms_amount) > $job_budget) {
                        set_flash('error', 'Milestone total cannot exceed the job budget of $' . number_format($job_budget, 2) . '.');
                    } else {
                    // Get next sort order
                    $so = $conn->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM milestones WHERE job_id = ?");
                    $so->bind_param('i', $job_id);
                    $so->execute();
                    $next_order = (int) $so->get_result()->fetch_assoc()['next_order'];
                    $so->close();

                    $ms_deadline_val = $ms_deadline !== '' ? $ms_deadline : null;
                    $ms_desc_val = $ms_description !== '' ? $ms_description : null;

                    $stmt = $conn->prepare("INSERT INTO milestones (job_id, freelancer_id, title, description, amount, deadline, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, 'draft', ?)");
                    $stmt->bind_param('iissdsi', $job_id, $ms_freelancer_id, $ms_title, $ms_desc_val, $ms_amount, $ms_deadline_val, $next_order);
                    $stmt->execute();
                    $stmt->close();

                    set_flash('success', 'Milestone created and assigned successfully!');
                }
            }
            }
            redirect('company/view_applications.php?id=' . $job_id);
        } elseif ($ms_action === 'fund' && $milestone_id > 0) {
            $stmt = $conn->prepare("SELECT m.id, m.amount, m.status FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'draft'");
            $stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
            $stmt->execute();
            $ms = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ms) {
                $conn->begin_transaction();
                try {
                    // Check and deduct demo funds
                    $stmt = $conn->prepare("UPDATE users SET demo_funds = demo_funds - ? WHERE id = ? AND demo_funds >= ?");
                    $stmt->bind_param('did', $ms['amount'], $user['user_id'], $ms['amount']);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();

                    if ($affected === 0) {
                        throw new Exception("Insufficient demo funds to fund this milestone.");
                    }

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
                    if (strpos($e->getMessage(), 'Insufficient') !== false) {
                        set_flash('error', $e->getMessage());
                    } else {
                        set_flash('error', 'Failed to fund milestone.');
                    }
                }
            } else {
                set_flash('error', 'Milestone not found or already funded.');
            }
        } elseif ($ms_action === 'approve' && $milestone_id > 0) {
            $stmt = $conn->prepare("SELECT m.id, m.amount, m.status, m.freelancer_id FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'submitted'");
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
                    
                    if ($stmt->affected_rows === 0) {
                        $stmt->close();
                        throw new Exception("Escrow already released or not found.");
                    }
                    $stmt->close();

                    // Credit freelancer's available balance
                    $stmt = $conn->prepare("SELECT user_id FROM freelancers WHERE id = ?");
                    $stmt->bind_param('i', $ms['freelancer_id']);
                    $stmt->execute();
                    $fl_user_id = (int) $stmt->get_result()->fetch_assoc()['user_id'];
                    $stmt->close();
                    
                    if ($fl_user_id > 0) {
                        $stmt = $conn->prepare("UPDATE users SET available_balance = available_balance + ? WHERE id = ?");
                        $stmt->bind_param('di', $ms['amount'], $fl_user_id);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Create payment record for the specific freelancer's assignment
                    $ms_freelancer_id = (int) ($ms['freelancer_id'] ?? 0);
                    if ($ms_freelancer_id > 0) {
                        $stmt = $conn->prepare("SELECT a.id FROM assignments a WHERE a.job_id = ? AND a.freelancer_id = ?");
                        $stmt->bind_param('ii', $job_id, $ms_freelancer_id);
                    } else {
                        $stmt = $conn->prepare("SELECT a.id FROM assignments a WHERE a.job_id = ?");
                        $stmt->bind_param('i', $job_id);
                    }
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

                    // Check if all milestones for this freelancer are approved
                    if ($ms_freelancer_id > 0) {
                        $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved FROM milestones WHERE job_id = ? AND freelancer_id = ?");
                        $stmt->bind_param('ii', $job_id, $ms_freelancer_id);
                    } else {
                        $stmt = $conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved FROM milestones WHERE job_id = ?");
                        $stmt->bind_param('i', $job_id);
                    }
                    $stmt->execute();
                    $counts = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if ($counts && $counts['total'] == $counts['approved']) {
                        // All milestones for this freelancer done — complete their assignment
                        if ($ms_freelancer_id > 0) {
                            $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE job_id = ? AND freelancer_id = ?");
                            $stmt->bind_param('ii', $job_id, $ms_freelancer_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE job_id = ?");
                            $stmt->bind_param('i', $job_id);
                        }
                        $stmt->execute();
                        $stmt->close();
                        
                        // Check if ALL required positions for the job are filled and completed
                        $stmt = $conn->prepare("SELECT j.freelancers_needed, SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS done FROM jobs j LEFT JOIN assignments a ON j.id = a.job_id WHERE j.id = ? GROUP BY j.id");
                        $stmt->bind_param('i', $job_id);
                        $stmt->execute();
                        $ac = $stmt->get_result()->fetch_assoc();
                        $stmt->close();
                        if ($ac && (int)$ac['done'] >= (int)$ac['freelancers_needed']) {
                            $stmt = $conn->prepare("UPDATE jobs SET status = 'completed' WHERE id = ?");
                            $stmt->bind_param('i', $job_id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        // Not all done yet — set assignment back to working for next milestone
                        if ($ms_freelancer_id > 0) {
                            $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND freelancer_id = ? AND status = 'submitted'");
                            $stmt->bind_param('ii', $job_id, $ms_freelancer_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND status = 'submitted'");
                            $stmt->bind_param('i', $job_id);
                        }
                        $stmt->execute();
                        $stmt->close();
                    }

                    $conn->commit();

                    // Notify the specific freelancer
                    try {
                        if ($ms_freelancer_id > 0) {
                            $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                            $stmt->bind_param('i', $ms_freelancer_id);
                        } else {
                            $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.job_id = ?");
                            $stmt->bind_param('i', $job_id);
                        }
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
            $stmt = $conn->prepare("SELECT m.id, m.status, m.freelancer_id FROM milestones m JOIN jobs j ON m.job_id = j.id WHERE m.id = ? AND m.job_id = ? AND j.company_id = ? AND m.status = 'submitted'");
            $stmt->bind_param('iii', $milestone_id, $job_id, $company_id);
            $stmt->execute();
            $ms = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($ms) {
                $ms_freelancer_id = (int) ($ms['freelancer_id'] ?? 0);
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

                    // Update only the specific freelancer's assignment
                    if ($ms_freelancer_id > 0) {
                        $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND freelancer_id = ? AND status = 'submitted'");
                        $stmt->bind_param('ii', $job_id, $ms_freelancer_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE assignments SET status = 'working' WHERE job_id = ? AND status = 'submitted'");
                        $stmt->bind_param('i', $job_id);
                    }
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    // Notify the specific freelancer
                    try {
                        if ($ms_freelancer_id > 0) {
                            $stmt = $conn->prepare("SELECT u.id FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
                            $stmt->bind_param('i', $ms_freelancer_id);
                        } else {
                            $stmt = $conn->prepare("SELECT u.id FROM assignments a JOIN freelancers f ON a.freelancer_id = f.id JOIN users u ON f.user_id = u.id WHERE a.job_id = ?");
                            $stmt->bind_param('i', $job_id);
                        }
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
    SELECT ja.id, ja.status, ja.applied_at, f.full_name, u.email, u.profile_image, f.id AS freelancer_id
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

$proposal_projects = [];
$stmt = $conn->prepare("SELECT * FROM proposal_projects WHERE job_id = ? AND company_id = ?");
$stmt->bind_param('ii', $job_id, $company_id);
$stmt->execute();
$res = $stmt->get_result();
while($row = $res->fetch_assoc()) {
    $proposal_projects[$row['freelancer_id']] = $row;
}
$stmt->close();

$assignment = null;
$assignments = [];
$payment = null;
$stmt = $conn->prepare("
    SELECT a.id, a.status, a.submission_link, a.assigned_at, f.full_name, f.id AS freelancer_id
    FROM assignments a
    JOIN freelancers f ON a.freelancer_id = f.id
    WHERE a.job_id = ?
    ORDER BY a.assigned_at DESC
");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$ar = $stmt->get_result();
while ($row = $ar->fetch_assoc()) { $assignments[] = $row; }
$stmt->close();
$assignment = $assignments[0] ?? null; // Keep for backward compatibility

// Position tracking
$freelancers_needed = (int) ($job['freelancers_needed'] ?? 1);
$positions_filled = count($assignments);
$positions_available = max(0, $freelancers_needed - $positions_filled);
$all_positions_filled = $positions_filled >= $freelancers_needed;

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

// Fetch milestones with assigned freelancer name
$milestones = [];
$stmt = $conn->prepare("SELECT m.*, e.status AS escrow_status, e.funded_at, e.released_at, f.full_name AS assigned_freelancer_name FROM milestones m LEFT JOIN escrow e ON e.milestone_id = m.id LEFT JOIN freelancers f ON f.id = m.freelancer_id WHERE m.job_id = ? ORDER BY m.sort_order ASC");
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

// Fetch accepted freelancers for milestone assignment dropdown
$accepted_freelancers = [];
$stmt = $conn->prepare("SELECT a.freelancer_id, f.full_name, u.profile_image, (SELECT COALESCE(SUM(amount), 0) FROM milestones WHERE job_id = a.job_id AND freelancer_id = a.freelancer_id) AS current_milestone_total FROM assignments a JOIN freelancers f ON f.id = a.freelancer_id JOIN users u ON f.user_id = u.id WHERE a.job_id = ? AND a.status != 'completed' ORDER BY f.full_name");
$stmt->bind_param('i', $job_id);
$stmt->execute();
$af = $stmt->get_result();
while ($row = $af->fetch_assoc()) { $accepted_freelancers[] = $row; }
$stmt->close();

$page_title = 'Applications';
require __DIR__ . '/../includes/header.php';
?>

<div class="flex flex-col lg:flex-row gap-6 items-start mb-8">
    <!-- LEFT SIDEBAR -->
    <div class="w-full lg:w-2/3 space-y-6">
        <div class="card">
            <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-indigo-600 hover:underline text-sm font-medium">&larr; <?= 'Back to jobs' ?></a>
            <h1 class="text-2xl font-extrabold mt-3 text-gray-900 dark:text-white"><?= e($job['title']) ?></h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400 font-medium"><?= 'Budget' ?>: $<?= e(number_format((float) $job['budget'], 2)) ?> &middot; <?= status_badge($job['status']) ?> &middot; Positions: <?= $positions_filled ?>/<?= $freelancers_needed ?> filled</p>
        </div>

        <?php if (!empty($assignments)): ?>
        <div class="card">
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
                                <p class="text-[11px] mt-0.5" style="color:var(--color-text-muted)">
                                    <?php if (!empty($ms['deadline'])): ?>Deadline: <?= date('M j, Y', strtotime($ms['deadline'])) ?> &middot; <?php endif; ?>
                                    Assigned to: <strong><?= e($ms['assigned_freelancer_name'] ?? 'Unassigned') ?></strong>
                                </p>
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
                        <!-- Submission Preview (compact) -->
                        <div class="mt-2 p-3 rounded-lg" style="background:rgba(139,92,246,0.04);border:1px solid rgba(139,92,246,0.12)">
                            <div class="flex items-center justify-between gap-2 mb-2">
                                <div class="flex items-center gap-2">
                                    <div class="w-2 h-2 rounded-full bg-purple-500 animate-pulse"></div>
                                    <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">Work Submitted</span>
                                </div>
                                <?php if ($ms['submitted_at']): ?>
                                    <span class="text-[11px]" style="color:var(--color-text-muted)"><?= date('M j, Y g:ia', strtotime($ms['submitted_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all hover:-translate-y-0.5" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);box-shadow:0 2px 8px rgba(139,92,246,0.3)">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    View Submission
                                </button>
                            </div>
                        </div>

                        <!-- Submission Detail Modal -->
                        <div id="submissionModal-<?= $ms['id'] ?>" class="fixed inset-0 z-50 flex items-center justify-center p-4 hidden">
                            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.add('hidden')"></div>
                            <div class="relative w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden" style="background:var(--color-card);border:1px solid var(--color-border);max-height:85vh;display:flex;flex-direction:column">
                                <!-- Modal Header -->
                                <div class="flex items-center justify-between p-5 border-b" style="border-color:var(--color-border)">
                                    <div>
                                        <h3 class="text-base font-bold" style="color:var(--color-text-primary)">Submission Details</h3>
                                        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Milestone: <?= e($ms['title']) ?></p>
                                    </div>
                                    <button type="button" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                                        <svg class="w-5 h-5" style="color:var(--color-text-muted)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                                <!-- Modal Body -->
                                <div class="p-5 space-y-4 overflow-y-auto flex-1">
                                    <!-- Submission Date -->
                                    <?php if ($ms['submitted_at']): ?>
                                    <div class="flex items-center gap-2.5 p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(139,92,246,0.1)">
                                            <svg class="w-4 h-4 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Submitted</p>
                                            <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= date('F j, Y \a\t g:ia', strtotime($ms['submitted_at'])) ?></p>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Submission Link -->
                                    <?php if ($ms['submission_link']): ?>
                                    <div class="p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="flex items-center gap-2 mb-2">
                                            <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                                            <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Submission Link</span>
                                        </div>
                                        <a href="<?= e($ms['submission_link']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold w-full transition-all hover:-translate-y-0.5" style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.15);color:#6366f1">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                            <span class="truncate"><?= e($ms['submission_link']) ?></span>
                                        </a>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Submission File -->
                                    <?php if (!empty($ms['submission_file'])):
                                        $file_ext = strtolower(pathinfo($ms['submission_file'], PATHINFO_EXTENSION));
                                        $file_icons = ['pdf'=>'text-red-500','doc'=>'text-blue-500','docx'=>'text-blue-600','zip'=>'text-yellow-600','rar'=>'text-purple-600','jpg'=>'text-green-500','jpeg'=>'text-green-500','png'=>'text-green-500','gif'=>'text-green-500','webp'=>'text-green-500'];
                                        $file_color = $file_icons[$file_ext] ?? 'text-gray-500';
                                        $is_image = in_array($file_ext, ['jpg','jpeg','png','gif','webp']);
                                    ?>
                                    <div class="p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="flex items-center gap-2 mb-2">
                                            <svg class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Uploaded File</span>
                                        </div>
                                        <?php if ($is_image): ?>
                                            <div class="rounded-xl overflow-hidden mb-3 border" style="border-color:var(--color-border)">
                                                <img src="<?= e(base_url('uploads/attachments/' . $ms['submission_file'])) ?>" alt="Submission preview" class="w-full max-h-48 object-contain bg-gray-50 dark:bg-gray-900">
                                            </div>
                                        <?php endif; ?>
                                        <div class="flex items-center gap-3 p-3 rounded-xl" style="background:var(--color-card);border:1px solid var(--color-border)">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style="background:rgba(16,185,129,0.1)">
                                                <svg class="w-5 h-5 <?= $file_color ?>" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e(basename($ms['submission_file'])) ?></p>
                                                <p class="text-[11px] uppercase font-semibold" style="color:var(--color-text-muted)"><?= e($file_ext) ?> file</p>
                                            </div>
                                            <a href="<?= e(base_url('uploads/attachments/' . $ms['submission_file'])) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#10b981,#059669)">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                                Download
                                            </a>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Submission Note -->
                                    <?php if (!empty($ms['submission_note'])): ?>
                                    <div class="p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                                        <div class="flex items-center gap-2 mb-2">
                                            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.087.16 2.185.283 3.293.369V21l4.076-4.076a1.526 1.526 0 011.037-.443 48.282 48.282 0 005.68-.494c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                                            <span class="text-xs font-semibold uppercase tracking-wider" style="color:var(--color-text-muted)">Freelancer's Note</span>
                                        </div>
                                        <div class="p-3 rounded-lg text-sm leading-relaxed whitespace-pre-wrap" style="background:var(--color-card);border:1px solid var(--color-border);color:var(--color-text-secondary)"><?= nl2br(e($ms['submission_note'])) ?></div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (empty($ms['submission_link']) && empty($ms['submission_file']) && empty($ms['submission_note'])): ?>
                                        <div class="text-center py-6" style="color:var(--color-text-muted)">
                                            <svg class="w-10 h-10 mx-auto mb-2 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            <p class="text-xs">No submission details provided.</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <!-- Modal Footer: Approve & Revision -->
                                <div class="p-5 border-t flex flex-wrap gap-3" style="border-color:var(--color-border)">
                                    <form method="POST" class="flex-1">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                        <input type="hidden" name="ms_action" value="approve">
                                        <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all" style="background:linear-gradient(135deg,#10b981,#059669);box-shadow:0 2px 8px rgba(16,185,129,0.3)" onclick="return confirm('Approve this milestone and release $<?= number_format((float) $ms['amount'], 2) ?> payment?')">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            Approve & Pay $<?= number_format((float) $ms['amount'], 2) ?>
                                        </button>
                                    </form>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                        <input type="hidden" name="ms_action" value="revision">
                                        <input type="hidden" name="milestone_id" value="<?= (int) $ms['id'] ?>">
                                        <button type="submit" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold transition-all" style="border:1.5px solid var(--color-border);color:var(--color-text-secondary)" onclick="return confirm('Request revision for this milestone?')">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            Revision
                                        </button>
                                    </form>
                                </div>
                            </div>
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
                            <button type="button" onclick="document.getElementById('submissionModal-<?= $ms['id'] ?>').classList.remove('hidden')" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white transition-all" style="background:linear-gradient(135deg,#8b5cf6,#6366f1);box-shadow:0 2px 8px rgba(139,92,246,0.3)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                View Submission & Review
                            </button>
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

        <!-- Create Milestone Form -->
        <?php if (!empty($accepted_freelancers)): ?>
        <div class="mt-4 p-4 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03));border:1px solid var(--color-border)">
            <h3 class="text-sm font-bold mb-3" style="color:var(--color-text-primary)">Add New Milestone</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                <input type="hidden" name="ms_action" value="create_milestone">
                <div class="grid sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="text-xs font-medium" style="color:var(--color-text-secondary)">Title <span class="text-red-500">*</span></label>
                        <input type="text" name="ms_title" required maxlength="200" placeholder="e.g. Design Phase" class="w-full px-3 py-2 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                    </div>
                    <div>
                        <label class="text-xs font-medium" style="color:var(--color-text-secondary)">Amount ($) <span class="text-red-500">*</span></label>
                        <input type="number" name="ms_amount" step="0.01" min="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="text-xs font-medium" style="color:var(--color-text-secondary)">Description</label>
                    <textarea name="ms_description" rows="2" placeholder="Brief description of this milestone..." class="w-full px-3 py-2 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"></textarea>
                </div>
                <div class="grid sm:grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="text-xs font-medium" style="color:var(--color-text-secondary)">Deadline</label>
                        <input type="date" name="ms_deadline" class="w-full px-3 py-2 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                    </div>
                    <div>
                        <label class="text-xs font-medium" style="color:var(--color-text-secondary)">Assign to Freelancer <span class="text-red-500">*</span></label>
                        <select name="ms_freelancer_id" required class="w-full px-3 py-2 rounded-lg text-sm" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)">
                            <option value="">Select a freelancer</option>
                            <?php foreach ($accepted_freelancers as $af): ?>
                                <?php $rem = max(0, $job['budget'] - $af['current_milestone_total']); ?>
                                <option value="<?= (int) $af['freelancer_id'] ?>" data-remaining="<?= (float) $rem ?>"><?= e($af['full_name']) ?> (Remaining Budget: $<?= number_format($rem, 2) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <p class="text-xs text-red-500 font-medium" id="ms_amount_error" style="display:none;"></p>
                </div>
                <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold text-white" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);box-shadow:0 2px 8px rgba(79,70,229,0.3)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Create Milestone
                </button>
            </form>
        </div>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const select = document.querySelector('select[name="ms_freelancer_id"]');
    const amountInput = document.querySelector('input[name="ms_amount"]');
    const errorMsg = document.getElementById('ms_amount_error');
    if (select && amountInput) {
        function validateAmount() {
            const selected = select.options[select.selectedIndex];
            if (selected && selected.value) {
                const remaining = parseFloat(selected.getAttribute('data-remaining') || 0);
                const currentVal = parseFloat(amountInput.value || 0);
                
                amountInput.max = remaining;
                amountInput.title = "Maximum allowed: $" + remaining.toFixed(2);
                
                if (currentVal > remaining) {
                    errorMsg.textContent = "Milestone total cannot exceed the job budget. You can add at most $" + remaining.toFixed(2) + " more.";
                    errorMsg.style.display = 'block';
                    amountInput.setCustomValidity("Milestone total cannot exceed the job budget.");
                } else if (remaining === 0) {
                    errorMsg.textContent = "The job budget is fully exhausted for this freelancer.";
                    errorMsg.style.display = 'block';
                    amountInput.setCustomValidity("Budget exhausted.");
                } else {
                    errorMsg.style.display = 'none';
                    amountInput.setCustomValidity("");
                }
            } else {
                amountInput.removeAttribute('max');
                amountInput.removeAttribute('title');
                errorMsg.style.display = 'none';
                amountInput.setCustomValidity("");
            }
        }
        
        select.addEventListener('change', validateAmount);
        amountInput.addEventListener('input', validateAmount);
    }
});
</script>

        <div class="card" id="applications-section">
            <h2 class="text-lg font-semibold mb-4"><?= 'Applications' ?></h2>

    <?php if (empty($applications)): ?>
        <p style="color:var(--color-text-muted)"><?= 'No applications for this job yet.' ?></p>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php foreach ($applications as $app): ?>
                <div class="rounded-lg p-4 flex flex-col h-full gap-4" style="border:1px solid var(--color-border); background:var(--color-bg)">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-start gap-3 min-w-0">
                            <?php $appImg = profile_image_url($app['profile_image']); ?>
                            <a href="<?= e(base_url('company/view_freelancer.php?id=' . $app['freelancer_id'])) ?>" class="block flex-shrink-0 transition-transform duration-200 hover:scale-105 hover:opacity-90 cursor-pointer" title="View Profile">
                                <?php if ($appImg): ?>
                                    <img src="<?= e($appImg) ?>" alt="" class="w-10 h-10 rounded-full object-cover border block" style="border-color:var(--color-border)">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold" style="background:rgba(99,102,241,0.1)">
                                        <?= e(strtoupper(substr($app['full_name'], 0, 1))) ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                            <div class="min-w-0">
                                <p class="font-medium truncate" title="<?= e($app['full_name']) ?>"><?= e($app['full_name']) ?></p>
                                <p class="text-sm truncate" style="color:var(--color-text-muted)" title="<?= e($app['email']) ?>"><?= e($app['email']) ?></p>
                                <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= 'Applied' ?>: <?= e($app['applied_at']) ?></p>

                            </div>
                        </div>
                        <div class="flex-shrink-0">
                            <?= status_badge($app['status']) ?>
                        </div>
                    </div>
                    
                    <?php if ($app['status'] === 'pending' && $positions_available > 0): ?>
                    <div class="mt-auto flex flex-col gap-2 pt-3 border-t" style="border-color:var(--color-border)">
                        <div class="grid grid-cols-2 gap-2 w-full">
                            <form method="POST" class="w-full">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="accept">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-primary text-sm w-full py-2 justify-center"><?= 'Accept (Hire)' ?></button>
                            </form>
                            <form method="POST" class="w-full">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="job_id" value="<?= $job_id ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="application_id" value="<?= (int) $app['id'] ?>">
                                <button type="submit" class="btn-danger text-sm w-full py-2 justify-center"><?= 'Reject' ?></button>
                            </form>
                        </div>
                        
                        <!-- Proposal Project Actions -->
                        <div class="mt-1 w-full flex justify-center">
                            <?php if (isset($proposal_projects[$app['freelancer_id']])): ?>
                                <?php $prop = $proposal_projects[$app['freelancer_id']]; ?>
                                <div class="flex flex-col items-center gap-1 bg-gray-50 dark:bg-gray-800 p-2 rounded-lg w-full text-center">
                                    <span class="text-xs font-semibold text-gray-500 uppercase">Test Assignment</span>
                                    <?= status_badge($prop['status']) ?>
                                    <?php if(in_array($prop['status'], ['submitted', 'reviewed'])): ?>
                                        <a href="<?= e(base_url('company/review_proposal.php?id=' . $prop['id'])) ?>" class="text-indigo-600 hover:underline text-sm font-medium mt-1">Review Submission</a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <button type="button" onclick="openProposalModal(<?= $app['freelancer_id'] ?>, '<?= e(addslashes($app['full_name'])) ?>')" class="inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium transition-all w-full" style="background:rgba(99,102,241,0.08);color:#6366f1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Send Test Assignment
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
        </div>
    </div>
    
    <!-- RIGHT SIDEBAR -->
    <div class="w-full lg:w-1/3 space-y-6 sticky top-6">
        <div class="card">
            <h2 class="text-lg font-bold mb-4 text-gray-900 dark:text-white"><?= 'Assignments' ?> (<?= count($assignments) ?>/<?= $freelancers_needed ?>)</h2>
            <div class="space-y-3 mb-4">
                <?php if (!empty($assignments)): ?>
                    <?php foreach ($assignments as $asgn): ?>
                    <div class="flex justify-between items-center gap-2 p-3 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                        <p class="text-sm font-bold text-gray-900 dark:text-white break-words"><?= e($asgn['full_name']) ?></p>
                        <div class="flex-shrink-0"><?= status_badge($asgn['status']) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No freelancers assigned yet.</p>
                <?php endif; ?>
            </div>
            
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4"><?= $positions_available ?> position<?= $positions_available !== 1 ? 's' : '' ?> remaining</p>
            
            <a href="#applications-section" class="w-full inline-flex justify-center items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition-all shadow-md hover:-translate-y-0.5" style="background:linear-gradient(135deg,#4f46e5,#7c3aed)">
                View Applications
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
            </a>
        </div>

        <div class="card">
            <h3 class="text-lg font-bold mb-4 text-gray-900 dark:text-white">Project Summary</h3>
            <div class="space-y-3">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Budget</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white">$<?= number_format((float) $job['budget'], 2) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Deadline</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= $job['deadline'] ? date('Y-m-d', strtotime($job['deadline'])) : 'N/A' ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Status</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= ucfirst(e($job['status'])) ?></span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Positions</span>
                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?= $positions_filled ?>/<?= $freelancers_needed ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Send Proposal Project Modal -->
<div id="proposalModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm transition-opacity">
    <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-lg w-full shadow-2xl overflow-hidden transform transition-all">
        <form action="<?= e(base_url('api/send_proposal_project.php')) ?>" method="POST" enctype="multipart/form-data" class="p-6 max-h-[90vh] overflow-y-auto">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="job_id" value="<?= $job_id ?>">
            <input type="hidden" name="freelancer_id" id="proposal_freelancer_id" value="">
            
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-xl font-bold" style="color:var(--color-text-primary)">Send Test Assignment to <span id="proposal_freelancer_name" class="text-indigo-600"></span></h3>
                <button type="button" onclick="closeProposalModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Assignment Title</label>
                <input type="text" name="title" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow" placeholder="e.g., Build a quick prototype">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Description</label>
                <textarea name="description" rows="3" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow" placeholder="Describe the test assignment briefly..."></textarea>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Instructions (Optional)</label>
                <textarea name="instructions" rows="2" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow" placeholder="Any specific requirements?"></textarea>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Deadline</label>
                <input type="date" name="deadline" required min="<?= date('Y-m-d') ?>" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white transition-shadow">
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium mb-1" style="color:var(--color-text-secondary)">Attachment (Optional)</label>
                <input type="file" name="attachment" class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-gray-700 dark:file:text-indigo-400">
            </div>
            
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeProposalModal()" class="px-5 py-2.5 rounded-xl font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 transition-colors">Cancel</button>
                <button type="submit" class="px-5 py-2.5 rounded-xl font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-200 dark:shadow-none">Send Assignment</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>

<script>
function openProposalModal(freelancerId, freelancerName) {
    document.getElementById('proposal_freelancer_id').value = freelancerId;
    document.getElementById('proposal_freelancer_name').innerText = freelancerName;
    document.getElementById('proposalModal').classList.remove('hidden');
}
function closeProposalModal() {
    document.getElementById('proposalModal').classList.add('hidden');
}

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
