<?php
function check_and_update_expired_jobs($conn) {
    if (!$conn) return;

    try {
        $tz = new DateTimeZone('Asia/Yangon');
        $now = new DateTime('now', $tz);
        $now_str = $now->format('Y-m-d H:i:s');

        // Mark jobs as expired when deadline has passed in Asia/Yangon timezone
        // Handles DATE-only deadlines (00:00:00) by expiring them after 23:59:59 of that date
        $sql = "UPDATE jobs 
                SET status = 'expired' 
                WHERE status IN ('open', 'in_review', 'approved') 
                AND deadline IS NOT NULL 
                AND (
                    (TIME(deadline) = '00:00:00' AND TIMESTAMP(DATE(deadline), '23:59:59') < ?)
                    OR
                    (TIME(deadline) != '00:00:00' AND deadline < ?)
                )";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('ss', $now_str, $now_str);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log("check_and_update_expired_jobs error: " . $e->getMessage());
    }
}

function _dl_notification_exists($conn, $user_id, $type) {
    $stmt = $conn->prepare("SELECT id FROM notifications WHERE user_id = ? AND type = ? LIMIT 1");
    $stmt->bind_param('is', $user_id, $type);
    $stmt->execute();
    $res = $stmt->get_result();
    $exists = $res->num_rows > 0;
    $stmt->close();
    return $exists;
}

function check_assignment_deadlines($conn) {
    require_once __DIR__ . '/../config/notifications.php';
    
    // Target assignments that are active and have a deadline
    $sql = "SELECT a.id, a.deadline, a.status, a.job_id, a.freelancer_id,
                   j.company_id, j.title as job_title,
                   f.user_id as freelancer_user_id,
                   c.user_id as company_user_id
            FROM assignments a
            JOIN jobs j ON a.job_id = j.id
            JOIN freelancers f ON a.freelancer_id = f.id
            JOIN companies c ON j.company_id = c.id
            WHERE a.status IN ('assigned', 'working', 'extended')
            AND a.deadline IS NOT NULL";
            
    $result = $conn->query($sql);
    if (!$result) return;
    
    $today = new DateTime('today');
    
    while ($row = $result->fetch_assoc()) {
        $deadline = new DateTime($row['deadline']);
        $now = new DateTime();
        $is_overdue = $deadline <= $now;

        $deadline_date = clone $deadline;
        $deadline_date->setTime(0, 0, 0); // for daily notifications
        $interval = $today->diff($deadline_date);
        $days_left = (int)$interval->format('%R%a'); // e.g., +3, +1, 0, -1
        
        $freelancer_id = (int)$row['freelancer_user_id'];
        $company_id = (int)$row['company_user_id'];
        $assignment_id = (int)$row['id'];
        $job_title = $row['job_title'];
        
        if ($is_overdue) {
            // Overdue
            $update = $conn->prepare("UPDATE assignments SET status = 'overdue' WHERE id = ?");
            $update->bind_param('i', $assignment_id);
            $update->execute();
            $update->close();
            
            $type = "dl_ovr_" . $assignment_id;
            if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                create_notification($conn, $freelancer_id, $type, "The deadline for your assignment '$job_title' has passed.", "freelancer/my_tasks.php");
            }
            if (!_dl_notification_exists($conn, $company_id, 'dl_c_ovr_' . $assignment_id)) {
                create_notification($conn, $company_id, 'dl_c_ovr_' . $assignment_id, "The deadline for assignment '$job_title' has passed and the freelancer has not submitted the work.", "company/view_applications.php?id=" . $row['job_id']);
            }
        } elseif ($days_left === 0) {
            // Today
            $type = "dl_tdy_" . $assignment_id;
            if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                create_notification($conn, $freelancer_id, $type, "Your project deadline for '$job_title' is today.", "freelancer/my_tasks.php");
            }
        } elseif ($days_left === 1) {
            // Tomorrow
            $type = "dl_1d_" . $assignment_id;
            if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                create_notification($conn, $freelancer_id, $type, "Your project deadline for '$job_title' is tomorrow.", "freelancer/my_tasks.php");
            }
            if (!_dl_notification_exists($conn, $company_id, $type)) {
                create_notification($conn, $company_id, $type, "Project '$job_title' deadline is approaching tomorrow.", "company/view_applications.php?id=" . $row['job_id']);
            }
        } elseif ($days_left === 3) {
            // 3 days
            $type = "dl_3d_" . $assignment_id;
            if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                create_notification($conn, $freelancer_id, $type, "Your project deadline for '$job_title' is approaching in 3 days.", "freelancer/my_tasks.php");
            }
            if (!_dl_notification_exists($conn, $company_id, $type)) {
                create_notification($conn, $company_id, $type, "Project '$job_title' deadline is approaching in 3 days.", "company/view_applications.php?id=" . $row['job_id']);
            }
        }
    }

    check_milestone_overdue($conn);

    // Target proposal projects (trial tasks) that are active and have a deadline
    $sql_pp = "SELECT p.id, p.deadline, p.status, p.job_id, p.freelancer_id,
                      j.company_id, j.title as job_title, f.user_id as freelancer_user_id
               FROM proposal_projects p
               JOIN jobs j ON p.job_id = j.id
               JOIN freelancers f ON p.freelancer_id = f.id
               WHERE p.status = 'accepted'
               AND p.deadline IS NOT NULL";
               
    $result_pp = $conn->query($sql_pp);
    if ($result_pp) {
        $now = new DateTime();
        while ($row = $result_pp->fetch_assoc()) {
            $deadline = new DateTime($row['deadline']);
            $is_overdue = $deadline <= $now;
            
            if ($is_overdue) {
                $proposal_id = (int)$row['id'];
                $freelancer_id = (int)$row['freelancer_user_id'];
                $job_title = $row['job_title'];
                
                $update = $conn->prepare("UPDATE proposal_projects SET status = 'overdue' WHERE id = ?");
                $update->bind_param('i', $proposal_id);
                $update->execute();
                $update->close();
                
                $type = "pp_ovr_" . $proposal_id;
                if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                    create_notification($conn, $freelancer_id, $type, "The deadline for your trial task '$job_title' has passed.", "freelancer/view_proposal.php?id=" . $proposal_id);
                }
                
                $company_id = (int)$row['company_id'];
                $c_type = "pp_c_ovr_" . $proposal_id;
                if (!_dl_notification_exists($conn, $company_id, $c_type)) {
                    create_notification($conn, $company_id, $c_type, "The deadline for trial task '$job_title' has passed and the freelancer has not submitted the work.", "company/view_applications.php?id=" . $row['job_id']);
                }
            }
        }
    }
}

/**
 * Record a milestone history entry
 */
function record_milestone_history($conn, $milestone_id, $freelancer_id, $company_id, $user_id, $prev_status, $new_status, $action_type, $description, $old_deadline = null, $new_deadline = null) {
    $stmt = $conn->prepare("INSERT INTO milestone_history (milestone_id, freelancer_id, company_id, user_id, previous_status, new_status, action_type, description, old_deadline, new_deadline) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('iiiissssss', $milestone_id, $freelancer_id, $company_id, $user_id, $prev_status, $new_status, $action_type, $description, $old_deadline, $new_deadline);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to record milestone history: " . $conn->error);
    }
}

/**
 * Dedicated milestone overdue checker.
 * Uses Asia/Yangon timezone for exact deadline comparison.
 * Can be called independently (e.g., from cron) or from check_assignment_deadlines().
 */
function check_milestone_overdue($conn) {
    require_once __DIR__ . '/../config/notifications.php';

    $tz = new DateTimeZone('Asia/Yangon');
    $now = new DateTime('now', $tz);

    // Only target milestones that are active and not yet submitted/completed
    $sql_ms = "SELECT m.id, m.deadline, m.status, m.job_id, m.freelancer_id, m.title as milestone_title,
                      j.company_id, f.user_id as freelancer_user_id
               FROM milestones m
               JOIN jobs j ON m.job_id = j.id
               JOIN freelancers f ON m.freelancer_id = f.id
               WHERE m.status IN ('draft', 'funded', 'in_progress', 'pending', 'revision_requested')
               AND m.deadline IS NOT NULL";

    $result_ms = $conn->query($sql_ms);
    if (!$result_ms) return;

    while ($row = $result_ms->fetch_assoc()) {
        $deadline = new DateTime($row['deadline'], $tz);
        if ($deadline <= $now) {
            $milestone_id = (int)$row['id'];
            $freelancer_id = (int)$row['freelancer_user_id'];
            $milestone_title = $row['milestone_title'];

            $update = $conn->prepare("UPDATE milestones SET status = 'overdue' WHERE id = ? AND status NOT IN ('overdue', 'cancelled', 'completed', 'submitted')");
            $update->bind_param('i', $milestone_id);
            $update->execute();
            $affected = $update->affected_rows;
            $update->close();

            if ($affected > 0) {
                // Record history
                record_milestone_history(
                    $conn,
                    $milestone_id,
                    (int)$row['freelancer_id'],
                    (int)$row['company_id'],
                    null,
                    $row['status'],
                    'overdue',
                    'OVERDUE',
                    'Milestone deadline passed and is now overdue.',
                    $row['deadline'],
                    null
                );
            }

            $type = "ms_ovr_" . $milestone_id;
            if (!_dl_notification_exists($conn, $freelancer_id, $type)) {
                create_notification($conn, $freelancer_id, $type, "The deadline for your milestone '$milestone_title' has passed.", "freelancer/milestone.php?id=" . $milestone_id);
            }

            $company_id = (int)$row['company_id'];
            $c_type = "ms_c_ovr_" . $milestone_id;
            if (!_dl_notification_exists($conn, $company_id, $c_type)) {
                create_notification($conn, $company_id, $c_type, "The deadline for milestone '$milestone_title' has passed and the freelancer has not submitted the work.", "company/view_applications.php?id=" . $row['job_id']);
            }
        }
    }
}

/**
 * Check if ALL required milestones for an assignment/job are completed.
 * Automatically updates assignment status to 'completed' ONLY when all milestones are done.
 */
function check_and_update_assignment_completion($conn, int $assignment_id): bool {
    if (!$conn || $assignment_id <= 0) return false;

    $stmt = $conn->prepare("SELECT id, job_id, status FROM assignments WHERE id = ?");
    $stmt->bind_param('i', $assignment_id);
    $stmt->execute();
    $asgn = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$asgn) return false;

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status IN ('approved', 'paid', 'payment_pending', 'completed') THEN 1 ELSE 0 END) AS done
        FROM milestones WHERE job_id = ? AND status NOT IN ('cancelled', 'rejected')
    ");
    $stmt->bind_param('i', $asgn['job_id']);
    $stmt->execute();
    $ms_info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int) ($ms_info['total'] ?? 0);
    $done = (int) ($ms_info['done'] ?? 0);

    if ($total > 0 && $done === $total) {
        $upd = $conn->prepare("UPDATE assignments SET status = 'completed' WHERE id = ?");
        $upd->bind_param('i', $assignment_id);
        $upd->execute();
        $upd->close();
        return true;
    }

    return false;
}

/**
 * Helper to check if a deadline has passed.
 * - Handles DATE strings e.g. "2026-08-28" or "2026-08-28 00:00:00" as valid until 23:59:59 of that date.
 * - Handles DATETIME strings e.g. "2026-08-28 17:00:00".
 * - Uses application configured timezone (Asia/Yangon).
 * - Returns true if the deadline has passed, false if still valid.
 */
function is_deadline_passed(?string $deadline_str): bool {
    if (empty($deadline_str)) {
        return false;
    }

    try {
        $tz = new DateTimeZone('Asia/Yangon');
        $now = new DateTime('now', $tz);
        
        $dl_str = trim($deadline_str);
        
        if (preg_match('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/', $dl_str)) {
            $date_part = substr($dl_str, 0, 10);
            $deadline = new DateTime($date_part . ' 23:59:59', $tz);
        } else {
            $deadline = new DateTime($dl_str, $tz);
        }

        return $deadline < $now;
    } catch (Throwable $e) {
        error_log("is_deadline_passed parse error for '{$deadline_str}': " . $e->getMessage());
        return false;
    }
}

