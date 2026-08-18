<?php
function check_and_update_expired_jobs($conn) {
    // Throttle: only run once per hour per session to avoid heavy UPDATE on every page load
    $now = time();
    $last_run = $_SESSION['_expired_jobs_check'] ?? 0;
    if ($now - $last_run < 3600) return;
    $_SESSION['_expired_jobs_check'] = $now;

    // Mark jobs as expired when deadline has passed (based on date AND time)
    $sql = "UPDATE jobs j 
            SET j.status = 'expired' 
            WHERE j.status = 'open' 
            AND j.deadline IS NOT NULL 
            AND j.deadline < NOW()
            LIMIT 500";
    $conn->query($sql);
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

    // Target milestones that are active and have a deadline
    $sql_ms = "SELECT m.id, m.deadline, m.status, m.job_id, m.freelancer_id, m.title as milestone_title,
                      j.company_id, f.user_id as freelancer_user_id
               FROM milestones m
               JOIN jobs j ON m.job_id = j.id
               JOIN freelancers f ON m.freelancer_id = f.id
               WHERE m.status IN ('funded', 'in_progress', 'revision_requested')
               AND m.deadline IS NOT NULL";
               
    $result_ms = $conn->query($sql_ms);
    if ($result_ms) {
        $now = new DateTime();
        while ($row = $result_ms->fetch_assoc()) {
            $deadline = new DateTime($row['deadline']);
            $is_overdue = $deadline <= $now;
            
            if ($is_overdue) {
                $milestone_id = (int)$row['id'];
                $freelancer_id = (int)$row['freelancer_user_id'];
                $milestone_title = $row['milestone_title'];
                
                $update = $conn->prepare("UPDATE milestones SET status = 'overdue' WHERE id = ?");
                $update->bind_param('i', $milestone_id);
                $update->execute();
                $update->close();
                
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
