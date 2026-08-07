<?php
$page_title = 'Freelancer Profile';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '86400');
    ini_set('session.cookie_lifetime', '0');
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/upload.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

$fid = (int) ($_GET['id'] ?? 0);
$viewer_role = $_SESSION['role'] ?? null;
$fallback_url = ($viewer_role === 'company') ? 'company/index.php' : (($viewer_role === 'freelancer') ? 'freelancer/dashboard.php' : 'index.php');

if ($fid <= 0) { redirect($fallback_url); }

// Fetch freelancer data
$st = $conn->prepare("SELECT f.id, f.full_name, f.title, f.location, f.bio, f.experience_years, f.hourly_rate, f.portfolio_url, f.phone, u.id AS user_id, u.profile_image, u.username, u.email, u.created_at, u.is_online, u.last_seen
    FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
$st->bind_param('i', $fid);
$st->execute();
$freelancer = $st->get_result()->fetch_assoc();
$st->close();
if (!$freelancer) { redirect($fallback_url); }

$profileImgUrl = $freelancer['profile_image'] ? base_url('uploads/images/' . $freelancer['profile_image']) : null;

// Skills
$fl_skills = [];
$r = $conn->prepare("SELECT s.skill_name FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE fs.freelancer_id = ?");
$r->bind_param('i', $fid); $r->execute();
$sr = $r->get_result();
while ($row = $sr->fetch_assoc()) $fl_skills[] = $row['skill_name'];
$r->close();

// Portfolio items with images and skills
$portfolio_items = [];
$st = $conn->prepare("SELECT * FROM portfolio_items WHERE freelancer_id = ? ORDER BY sort_order ASC, id DESC");
$st->bind_param('i', $fid);
$st->execute();
$rr = $st->get_result();
while ($row = $rr->fetch_assoc()) { $portfolio_items[] = $row; }
$st->close();
foreach ($portfolio_items as &$item) {
    $item['skills'] = [];
    $item['images'] = [];
    $ps = $conn->prepare("SELECT s.skill_name FROM portfolio_skills ps JOIN skills s ON ps.skill_id = s.id WHERE ps.portfolio_item_id = ?");
    $ps->bind_param('i', $item['id']); $ps->execute();
    $sr = $ps->get_result();
    while ($row = $sr->fetch_assoc()) $item['skills'][] = $row['skill_name'];
    $ps->close();
    $pi = $conn->prepare("SELECT * FROM portfolio_images WHERE portfolio_item_id = ? ORDER BY sort_order ASC");
    $pi->bind_param('i', $item['id']); $pi->execute();
    $ir = $pi->get_result();
    while ($row = $ir->fetch_assoc()) $item['images'][] = $row;
    $pi->close();
}
unset($item);

// Completed projects
$completed_projects = [];
$r = $conn->prepare("SELECT a.id, a.assigned_at, j.title, j.budget, p.amount, p.paid_at, c.company_name
    FROM assignments a JOIN jobs j ON a.job_id = j.id JOIN companies c ON j.company_id = c.id
    LEFT JOIN payments p ON p.assignment_id = a.id AND p.status = 'paid'
    WHERE a.freelancer_id = ? AND a.status = 'completed' ORDER BY a.assigned_at DESC");
$r->bind_param('i', $fid); $r->execute();
$rr2 = $r->get_result();
while ($row = $rr2->fetch_assoc()) $completed_projects[] = $row;
$r->close();
$completed_count = count($completed_projects);

// Companies worked with
$companies_worked = [];
$company_ids_seen = [];
foreach ($completed_projects as $cp) {
    $cj = $conn->prepare("SELECT j.company_id FROM jobs j JOIN assignments a ON a.job_id = j.id WHERE a.id = ?");
    $cj->bind_param('i', $cp['id']);
    $cj->execute();
    $cj_row = $cj->get_result()->fetch_assoc();
    $cj->close();
    if ($cj_row && !in_array($cj_row['company_id'], $company_ids_seen)) {
        $company_ids_seen[] = $cj_row['company_id'];
        $cl = $conn->prepare("SELECT company_name, logo_image FROM companies WHERE id = ?");
        $cl->bind_param('i', $cj_row['company_id']);
        $cl->execute();
        $cl_row = $cl->get_result()->fetch_assoc();
        $cl->close();
        if ($cl_row) {
            $companies_worked[] = [
                'name' => $cl_row['company_name'],
                'logo' => $cl_row['logo_image'] ? base_url('uploads/images/' . $cl_row['logo_image']) : null,
            ];
        }
    }
}

// Reviews
$reviews = [];
$r = $conn->prepare("SELECT r.rating, r.comment, r.created_at, c.company_name, u.profile_image AS reviewer_image
    FROM reviews r LEFT JOIN companies c ON r.company_user_id = c.user_id LEFT JOIN users u ON r.company_user_id = u.id
    WHERE r.freelancer_id = ? ORDER BY r.created_at DESC LIMIT 10");
$r->bind_param('i', $fid); $r->execute();
$rr2 = $r->get_result();
while ($row = $rr2->fetch_assoc()) $reviews[] = $row;
$r->close();
$total_reviews = count($reviews);
$avg_rating = 0;
if ($total_reviews > 0) { $sum = 0; foreach ($reviews as $rv) $sum += $rv['rating']; $avg_rating = round($sum / $total_reviews, 1); }
$rating_dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
foreach ($reviews as $rv) { $rating_dist[$rv['rating']] = ($rating_dist[$rv['rating']] ?? 0) + 1; }

// Earnings
$total_earnings = 0;
$r = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN assignments a ON p.assignment_id = a.id WHERE a.freelancer_id = ? AND p.status = 'paid'");
$r->bind_param('i', $fid); $r->execute();
$er = $r->get_result()->fetch_assoc();
$total_earnings = (float) ($er['total'] ?? 0);
$r->close();

// Success rate
$total_assignments = 0;
$r = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ?");
$r->bind_param('i', $fid); $r->execute();
$total_assignments = (int) $r->get_result()->fetch_assoc()['cnt'];
$r->close();
$success_rate = $total_assignments > 0 ? round(($completed_count / $total_assignments) * 100) : 0;

// Availability (determine from active assignments)
$active_assignments = 0;
$r = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ? AND status IN ('assigned','working')");
$r->bind_param('i', $fid); $r->execute();
$active_assignments = (int) $r->get_result()->fetch_assoc()['cnt'];
$r->close();
$is_available = $active_assignments < 3;

// Viewer role detection
$viewer_role = $_SESSION['role'] ?? null;
$viewer_user_id = (int) ($_SESSION['user_id'] ?? 0);
$is_company = $viewer_role === 'company';
$is_own_freelancer = false;
if ($viewer_role === 'freelancer') {
    $my_fid = get_freelancer_id($conn, $viewer_user_id);
    if ($my_fid && $my_fid == $fid) $is_own_freelancer = true;
}

// Company hire state
$is_hired = false;
$pending_hire = false;
$hire_success = '';
$hire_error = '';
if ($is_company) {
    $logged_in_company = get_company_id($conn, $viewer_user_id);
    if ($logged_in_company) {
        $chk = $conn->prepare("SELECT a.id FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.freelancer_id = ? AND a.freelancer_response != 'rejected'");
        $chk->bind_param('ii', $logged_in_company, $fid);
        $chk->execute();
        $is_hired = $chk->get_result()->num_rows > 0;
        $chk->close();
        if (!$is_hired) {
            $chk2 = $conn->prepare("SELECT id FROM assignments WHERE freelancer_id = ? AND assignment_type = 'direct_hire' AND freelancer_response = 'pending' AND job_id IN (SELECT id FROM jobs WHERE company_id = ?)");
            $chk2->bind_param('ii', $fid, $logged_in_company);
            $chk2->execute();
            $pending_hire = $chk2->get_result()->num_rows > 0;
            $chk2->close();
        }
    }
}

// Handle Direct Hire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'direct_hire') {
    if (!verify_csrf()) { $hire_error = 'Invalid request.'; }
    elseif (!$is_company) { $hire_error = 'You must be logged in as a company.'; }
    else {
        $company_id = get_company_id($conn, $viewer_user_id);
        if (!$company_id) { $hire_error = 'Company profile not found.'; }
        else {
            $title = trim($_POST['project_title'] ?? '');
            $description = trim($_POST['project_description'] ?? '');
            $budget = (float) ($_POST['budget'] ?? 0);
            $deadline = trim($_POST['deadline'] ?? '');
            $payment_type = $_POST['payment_type'] ?? 'fixed';
            $notes = trim($_POST['notes'] ?? '');
            $ms_titles = $_POST['ms_title'] ?? [];
            $ms_descs = $_POST['ms_desc'] ?? [];
            $ms_amounts = $_POST['ms_amount'] ?? [];
            $ms_deadlines = $_POST['ms_deadline'] ?? [];
            $attachment_name = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment_name = upload_attachment($_FILES['attachment']);
                if ($attachment_name === null) { $hire_error = 'Invalid attachment. Allowed: JPG, PNG, PDF, DOCX, ZIP. Max 10MB.'; }
            }
            if ($title === '') { $hire_error = 'Project title is required.'; }
            elseif ($description === '') { $hire_error = 'Project description is required.'; }
            elseif ($budget <= 0) { $hire_error = 'Budget must be greater than zero.'; }
            elseif ($payment_type === 'milestone' && empty($ms_titles)) { $hire_error = 'Please add at least one milestone.'; }
            else {
                $dup_check = $conn->prepare("SELECT id FROM assignments WHERE freelancer_id = ? AND assignment_type = 'direct_hire' AND freelancer_response = 'pending' AND job_id IN (SELECT id FROM jobs WHERE company_id = ?)");
                $dup_check->bind_param('ii', $fid, $company_id);
                $dup_check->execute();
                $has_pending = $dup_check->get_result()->num_rows > 0;
                $dup_check->close();
                if ($has_pending) { $hire_error = 'You already have a pending direct hire request for this freelancer.'; }
                else {
                    if ($payment_type === 'milestone') {
                        $ms_total = 0;
                        foreach ($ms_amounts as $amt) { $ms_total += (float) $amt; }
                        if (abs($ms_total - $budget) > 0.01) { $hire_error = 'Milestone total ($' . number_format($ms_total, 2) . ') must match the budget ($' . number_format($budget, 2) . ').'; }
                    }
                    if (empty($hire_error)) {
                        $stmt = $conn->prepare("INSERT INTO jobs (company_id, title, category, description, budget, deadline, experience_level, gender_requirement, visibility, status, duration) VALUES (?, ?, 'Direct Hire', ?, ?, ?, 'any', 'any', 'private', 'open', '')");
                        $stmt->bind_param('issds', $company_id, $title, $description, $budget, $deadline);
                        $stmt->execute();
                        $job_id = $stmt->insert_id;
                        $stmt->close();
                        if ($job_id > 0) {
                            if ($payment_type === 'milestone' && !empty($ms_titles)) {
                                $ms_stmt = $conn->prepare('INSERT INTO milestones (job_id, freelancer_id, title, description, amount, deadline, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
                                foreach ($ms_titles as $idx => $ms_t) {
                                    $ms_t = trim($ms_t);
                                    $ms_a = (float) ($ms_amounts[$idx] ?? 0);
                                    $ms_d = trim($ms_descs[$idx] ?? '');
                                    $ms_dl = trim($ms_deadlines[$idx] ?? '') !== '' ? trim($ms_deadlines[$idx]) : null;
                                    if ($ms_t !== '' && $ms_a > 0) {
                                        $order = $idx + 1;
                                        $ms_stmt->bind_param('iissdsi', $job_id, $fid, $ms_t, $ms_d, $ms_a, $ms_dl, $order);
                                        $ms_stmt->execute();
                                    }
                                }
                                $ms_stmt->close();
                            }
                            $deadline_val = $deadline !== '' ? $deadline : null;
                            $notes_val = $notes !== '' ? $notes : null;
                            $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, assignment_type, status, freelancer_response, project_title, project_description, budget, deadline, payment_type, notes, attachment) VALUES (?, ?, 'direct_hire', 'assigned', 'pending', ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->bind_param('iisssssss', $job_id, $fid, $title, $description, $budget, $deadline_val, $payment_type, $notes_val, $attachment_name);
                            $stmt->execute();
                            $assignment_id = $stmt->insert_id;
                            $stmt->close();
                            if ($assignment_id > 0) {
                                if ($payment_type === 'milestone') {
                                    $first_ms = $conn->prepare("SELECT id, amount FROM milestones WHERE job_id = ? AND sort_order = 1 LIMIT 1");
                                    $first_ms->bind_param('i', $job_id);
                                    $first_ms->execute();
                                    $ms_row = $first_ms->get_result()->fetch_assoc();
                                    $first_ms->close();
                                    if ($ms_row) {
                                        $conn->begin_transaction();
                                        try {
                                            $up = $conn->prepare("UPDATE milestones SET status = 'funded' WHERE id = ?");
                                            $up->bind_param('i', $ms_row['id']); $up->execute(); $up->close();
                                            $esc = $conn->prepare("INSERT INTO escrow (milestone_id, amount, status) VALUES (?, ?, 'held')");
                                            $esc->bind_param('id', $ms_row['id'], $ms_row['amount']); $esc->execute(); $esc->close();
                                            $conn->commit();
                                        } catch (Exception $e) { $conn->rollback(); }
                                    }
                                }
                                create_notification($conn, (int) $freelancer['user_id'], 'direct_hire', "You have a new direct hire request from a company for: {$title}", "freelancer/dashboard.php");
                                $hire_success = 'Hire request sent successfully! The freelancer will be notified.';
                                $pending_hire = true;
                            } else { $hire_error = 'Failed to create assignment.'; }
                        } else { $hire_error = 'Failed to create job record.'; }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($freelancer['full_name'] ?? 'Freelancer') ?> - Freelancer Profile - HireWork</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config={
        darkMode:'class',
        theme:{extend:{fontFamily:{poppins:['Poppins','system-ui','sans-serif']}}}
    };
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{font-family:'Poppins',system-ui,sans-serif;box-sizing:border-box;}

        /* ===== CUSTOM PROPERTIES ===== */
        :root{
            --gp:linear-gradient(135deg,#6366f1,#8b5cf6);
            --glass-bg:rgba(255,255,255,0.65);
            --glass-border:rgba(255,255,255,0.5);
            --glass-shadow:0 8px 32px rgba(99,102,241,0.07);
        }
        html.dark{
            --glass-bg:rgba(30,41,59,0.65);
            --glass-border:rgba(255,255,255,0.08);
            --glass-shadow:0 8px 32px rgba(0,0,0,0.3);
        }

        /* ===== GLASSMORPHISM ===== */
        .glass{
            background:var(--glass-bg);
            backdrop-filter:blur(20px) saturate(1.8);
            -webkit-backdrop-filter:blur(20px) saturate(1.8);
            border:1px solid var(--glass-border);
            box-shadow:var(--glass-shadow);
        }

        /* ===== HERO ===== */
        .profile-hero{
            background:linear-gradient(135deg,#0f172a 0%,#1e1b4b 30%,#312e81 55%,#4f46e5 80%,#7c3aed 100%);
            position:relative;
            overflow:hidden;
            min-height:340px;
        }
        .profile-hero::before{
            content:'';position:absolute;inset:0;
            background:
                radial-gradient(ellipse 600px 400px at 20% 60%,rgba(129,140,248,0.25) 0%,transparent 70%),
                radial-gradient(ellipse 500px 350px at 80% 30%,rgba(167,139,250,0.2) 0%,transparent 70%),
                radial-gradient(ellipse 300px 300px at 50% 80%,rgba(99,102,241,0.15) 0%,transparent 70%);
        }
        .profile-hero::after{
            content:'';position:absolute;bottom:0;left:0;right:0;height:120px;
            background:linear-gradient(to top,rgba(248,250,252,1) 0%,rgba(248,250,252,0) 100%);
        }
        html.dark .profile-hero::after{
            background:linear-gradient(to top,rgba(15,23,42,1) 0%,rgba(15,23,42,0) 100%);
        }

        /* Floating orbs */
        .hero-orb{
            position:absolute;border-radius:50%;pointer-events:none;
            animation:orbFloat 8s ease-in-out infinite;
        }
        .hero-orb-1{width:200px;height:200px;top:-40px;right:10%;background:radial-gradient(circle,rgba(139,92,246,0.2),transparent 70%);animation-delay:0s;}
        .hero-orb-2{width:150px;height:150px;bottom:20px;left:5%;background:radial-gradient(circle,rgba(99,102,241,0.15),transparent 70%);animation-delay:2s;}
        .hero-orb-3{width:100px;height:100px;top:30%;right:30%;background:radial-gradient(circle,rgba(168,85,247,0.12),transparent 70%);animation-delay:4s;}
        @keyframes orbFloat{
            0%,100%{transform:translate(0,0) scale(1);}
            33%{transform:translate(15px,-20px) scale(1.05);}
            66%{transform:translate(-10px,15px) scale(0.95);}
        }

        /* Grid pattern overlay */
        .hero-grid{
            position:absolute;inset:0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px,transparent 1px),
                linear-gradient(90deg,rgba(255,255,255,0.03) 1px,transparent 1px);
            background-size:60px 60px;
        }

        /* ===== AVATAR ===== */
        .avatar-ring{
            position:relative;display:inline-block;
        }
        .avatar-ring::before{
            content:'';position:absolute;inset:-4px;border-radius:50%;
            background:linear-gradient(135deg,#6366f1,#a855f7,#6366f1);
            animation:ringRotate 4s linear infinite;
        }
        @keyframes ringRotate{
            0%{filter:hue-rotate(0deg);}
            100%{filter:hue-rotate(360deg);}
        }
        .avatar-img{
            position:relative;z-index:1;
            width:140px;height:140px;border-radius:50%;object-fit:cover;
            border:4px solid rgba(255,255,255,0.9);
            box-shadow:0 12px 40px rgba(0,0,0,0.2);
        }
        .avatar-placeholder{
            position:relative;z-index:1;
            width:140px;height:140px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            font-size:52px;font-weight:800;color:#fff;
            background:linear-gradient(135deg,#6366f1,#a855f7);
            border:4px solid rgba(255,255,255,0.9);
            box-shadow:0 12px 40px rgba(0,0,0,0.2);
        }

        /* ===== STAT CARDS ===== */
        .stat-card{
            position:relative;overflow:hidden;
            border-radius:20px;
            transition:transform .35s cubic-bezier(.4,0,.2,1),box-shadow .35s ease;
        }
        .stat-card:hover{
            transform:translateY(-6px);
            box-shadow:0 20px 50px rgba(0,0,0,0.12);
        }
        .stat-card::after{
            content:'';position:absolute;top:-50%;right:-50%;
            width:100%;height:100%;border-radius:50%;
            opacity:0.06;pointer-events:none;
        }
        .stat-card-blue::after{background:#3b82f6;}
        .stat-card-amber::after{background:#f59e0b;}
        .stat-card-emerald::after{background:#10b981;}
        .stat-card-purple::after{background:#8b5cf6;}

        /* ===== SECTION CARDS ===== */
        .section-card{
            background:var(--glass-bg);
            backdrop-filter:blur(16px) saturate(1.6);
            -webkit-backdrop-filter:blur(16px) saturate(1.6);
            border:1px solid var(--glass-border);
            border-radius:20px;
            box-shadow:var(--glass-shadow);
            transition:transform .3s ease,box-shadow .3s ease;
        }
        .section-card:hover{
            transform:translateY(-3px);
            box-shadow:0 16px 48px rgba(99,102,241,0.1);
        }

        /* ===== SKILL TAGS ===== */
        .skill-tag{
            display:inline-flex;align-items:center;gap:6px;
            padding:8px 16px;border-radius:12px;font-size:13px;font-weight:600;
            background:linear-gradient(135deg,rgba(99,102,241,0.08),rgba(139,92,246,0.08));
            color:#6366f1;border:1px solid rgba(99,102,241,0.15);
            transition:all .25s ease;cursor:default;
        }
        .skill-tag:hover{
            transform:translateY(-3px);
            box-shadow:0 8px 20px rgba(99,102,241,0.2);
            background:linear-gradient(135deg,rgba(99,102,241,0.15),rgba(139,92,246,0.15));
        }
        html.dark .skill-tag{
            background:linear-gradient(135deg,rgba(99,102,241,0.12),rgba(139,92,246,0.12));
            color:#a5b4fc;border-color:rgba(99,102,241,0.2);
        }

        /* ===== PORTFOLIO ===== */
        .portfolio-card{
            border-radius:16px;overflow:hidden;
            transition:all .35s cubic-bezier(.4,0,.2,1);
        }
        .portfolio-card:hover{
            transform:translateY(-6px) scale(1.01);
            box-shadow:0 20px 50px rgba(0,0,0,0.12);
        }
        .portfolio-img{
            width:100%;height:200px;object-fit:cover;
            transition:transform .5s cubic-bezier(.4,0,.2,1);
        }
        .portfolio-card:hover .portfolio-img{transform:scale(1.08);}

        /* ===== BUTTONS ===== */
        .btn-glow{
            position:relative;overflow:hidden;
            background:linear-gradient(135deg,#6366f1,#8b5cf6);
            transition:all .3s ease;
        }
        .btn-glow::before{
            content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;
            background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);
            transition:left .5s ease;
        }
        .btn-glow:hover::before{left:100%;}
        .btn-glow:hover{
            transform:translateY(-2px);
            box-shadow:0 12px 35px rgba(99,102,241,0.4);
        }

        .btn-glass{
            background:rgba(255,255,255,0.15);
            backdrop-filter:blur(10px);
            border:1px solid rgba(255,255,255,0.25);
            transition:all .3s ease;
        }
        .btn-glass:hover{
            background:rgba(255,255,255,0.25);
            transform:translateY(-2px);
            box-shadow:0 8px 24px rgba(0,0,0,0.15);
        }

        /* ===== REVIEW BARS ===== */
        .review-bar{
            height:8px;border-radius:4px;overflow:hidden;
            background:rgba(99,102,241,0.1);
        }
        .review-bar-fill{
            height:100%;border-radius:4px;
            background:linear-gradient(90deg,#6366f1,#a855f7);
            transition:width 1.2s cubic-bezier(.4,0,.2,1);
            width:0;
        }

        /* ===== AVAILABILITY PULSE ===== */
        .pulse-dot{
            width:10px;height:10px;border-radius:50%;position:relative;
        }
        .pulse-dot::before{
            content:'';position:absolute;inset:-3px;border-radius:50%;
            animation:pulseRing 2s ease-out infinite;
        }
        .pulse-dot-green{background:#22c55e;}
        .pulse-dot-green::before{background:rgba(34,197,94,0.3);}
        .pulse-dot-amber{background:#f59e0b;}
        .pulse-dot-amber::before{background:rgba(245,158,11,0.3);}
        @keyframes pulseRing{
            0%{transform:scale(1);opacity:1;}
            100%{transform:scale(2.5);opacity:0;}
        }

        /* ===== SCROLL REVEAL ===== */
        .reveal{
            opacity:0;transform:translateY(28px);
            transition:opacity .6s cubic-bezier(.4,0,.2,1),transform .6s cubic-bezier(.4,0,.2,1);
        }
        .reveal.visible{opacity:1;transform:translateY(0);}
        .reveal-d1{transition-delay:.08s;}.reveal-d2{transition-delay:.16s;}
        .reveal-d3{transition-delay:.24s;}.reveal-d4{transition-delay:.32s;}

        /* ===== MODAL ===== */
        .img-modal{
            display:none;position:fixed;inset:0;
            background:rgba(0,0,0,0.9);z-index:200;
            align-items:center;justify-content:center;
            backdrop-filter:blur(8px);
        }
        .img-modal.open{display:flex;}
        .img-modal img{max-width:92vw;max-height:88vh;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.5);}

        /* ===== HIRE MODAL ===== */
        #hireModal{overflow-y:auto;-webkit-overflow-scrolling:touch;}
        @media(max-height:700px){
            #hireModal{align-items:flex-start;padding-top:16px;}
        }

        /* ===== RESPONSIVE ===== */
        @media(max-width:768px){
            .profile-hero{min-height:280px;}
            .avatar-img,.avatar-placeholder{width:110px;height:110px;font-size:40px;}
            .stat-grid{grid-template-columns:1fr 1fr!important;}
        }
        @media(max-width:480px){
            .profile-hero{min-height:240px;}
            .avatar-img,.avatar-placeholder{width:90px;height:90px;font-size:32px;}
            .hero-name{font-size:22px!important;}
        }

        /* ===== DIVIDER ===== */
        .gradient-divider{
            height:2px;
            background:linear-gradient(90deg,transparent,rgba(99,102,241,0.2),rgba(139,92,246,0.2),transparent);
        }

        /* ===== SECTION HEADER ===== */
        .section-header{
            display:flex;align-items:center;gap:12px;margin-bottom:20px;
        }
        .section-icon{
            width:40px;height:40px;border-radius:12px;
            display:flex;align-items:center;justify-content:center;flex-shrink:0;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950/30 min-h-screen">

<?php require __DIR__ . '/../includes/navbar.php'; ?>

<!-- ===== HERO SECTION ===== -->
<div class="profile-hero" style="padding-top:64px;">
    <div class="hero-grid"></div>
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-orb hero-orb-3"></div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-16 relative z-10">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-white/50 text-sm mb-8">
            <a href="<?= e(base_url('company/find_freelancers.php')) ?>" class="hover:text-white/80 transition-colors">Freelancers</a>
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            <span class="text-white/80 font-medium"><?= e($freelancer['full_name'] ?? 'Profile') ?></span>
        </div>

        <!-- Hero Content -->
        <div class="flex flex-col md:flex-row items-center md:items-end gap-8">
            <!-- Avatar -->
            <div class="flex-shrink-0">
                <div class="avatar-ring">
                    <?php if ($profileImgUrl): ?>
                        <img src="<?= e($profileImgUrl) ?>" alt="" class="avatar-img">
                    <?php else: ?>
                        <div class="avatar-placeholder"><?= strtoupper(mb_substr($freelancer['full_name'] ?? $freelancer['username'] ?? 'U', 0, 1)) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info -->
            <div class="flex-1 text-center md:text-left">
                <h1 class="hero-name text-white font-extrabold text-3xl mb-1"><?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h1>
                <p class="text-indigo-200 font-semibold text-lg mb-3"><?= e($freelancer['title'] ?? 'Freelancer') ?></p>

                <div class="flex flex-wrap items-center justify-center md:justify-start gap-4 text-sm text-white/70">
                    <?php if ($freelancer['location']): ?>
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                            <?= e($freelancer['location']) ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($total_reviews > 0): ?>
                        <span class="inline-flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            <span class="text-amber-400 font-semibold"><?= $avg_rating ?></span> (<?= $total_reviews ?> review<?= $total_reviews !== 1 ? 's' : '' ?>)
                        </span>
                    <?php endif; ?>

                    <!-- Availability -->
                    <span class="inline-flex items-center gap-2">
                        <span class="pulse-dot <?= $is_available ? 'pulse-dot-green' : 'pulse-dot-amber' ?>"></span>
                        <span class="font-medium <?= $is_available ? 'text-emerald-300' : 'text-amber-300' ?>"><?= $is_available ? 'Available for hire' : 'Currently busy' ?></span>
                    </span>

                    <?php if ($freelancer['hourly_rate']): ?>
                        <span class="inline-flex items-center gap-1 text-white font-bold">
                            <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            $<?= number_format((float)$freelancer['hourly_rate'], 0) ?><span class="text-white/50 text-sm font-normal">/hr</span>
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Skill badges in hero -->
                <?php if (!empty($fl_skills)): ?>
                <div class="flex flex-wrap justify-center md:justify-start gap-2 mt-4">
                    <?php foreach (array_slice($fl_skills, 0, 5) as $sk): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-white/10 text-white/90 border border-white/15 backdrop-blur-sm"><?= e($sk) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($fl_skills) > 5): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-white/10 text-white/70 border border-white/10">+<?= count($fl_skills) - 5 ?> more</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap gap-3 justify-center md:justify-end flex-shrink-0">
                <?php if ($is_company): ?>
                    <?php if ($is_hired): ?>
                        <span class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30 backdrop-blur-sm">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Already Hired
                        </span>
                    <?php elseif ($pending_hire): ?>
                        <span class="inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold bg-amber-500/20 text-amber-300 border border-amber-400/30 backdrop-blur-sm">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Pending Request
                        </span>
                    <?php else: ?>
                        <button type="button" onclick="document.getElementById('hireModal').classList.remove('hidden')" class="btn-glow inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            Hire Freelancer
                        </button>
                    <?php endif; ?>
                    <a href="<?= e(base_url('chat/index.php?user=' . $freelancer['user_id'])) ?>" class="btn-glass inline-flex items-center gap-2 px-5 py-3 rounded-xl text-sm font-bold text-white">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                        Send Message
                    </a>
                <?php elseif (!$is_own_freelancer && !$is_company): ?>
                    <!-- Logged out or other freelancer -->
                    <a href="<?= e(base_url('freelancer/view_portfolio.php?id=' . $fid)) ?>" class="btn-glass inline-flex items-center gap-2 px-5 py-3 rounded-xl text-sm font-bold text-white">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                        View Portfolio
                    </a>
                <?php endif; ?>
                <!-- Always show View Portfolio for company/freelancer viewers -->
                <?php if ($is_company): ?>
                    <a href="<?= e(base_url('freelancer/view_portfolio.php?id=' . $fid)) ?>" class="btn-glass inline-flex items-center gap-2 px-5 py-3 rounded-xl text-sm font-bold text-white">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                        View Portfolio
                    </a>
                <?php elseif ($is_own_freelancer): ?>
                    <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="btn-glow inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                        Manage Portfolio
                    </a>
                    <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="btn-glass inline-flex items-center gap-2 px-5 py-3 rounded-xl text-sm font-bold text-white">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L6.832 19.82a4.5 4.5 0 01-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 011.13-1.897L16.863 4.487z"/></svg>
                        Edit Profile
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 -mt-4 pb-16 relative z-10">

    <!-- Success/Error Messages -->
    <?php if ($hire_success): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm font-medium reveal"><?= e($hire_success) ?></div>
    <?php endif; ?>
    <?php if ($hire_error): ?>
        <div class="mb-6 p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-medium reveal"><?= e($hire_error) ?></div>
    <?php endif; ?>

    <!-- ===== STATISTICS CARDS ===== -->
    <div class="stat-grid grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <!-- Projects Completed -->
        <div class="stat-card stat-card-blue glass p-5 text-center reveal">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-blue-500/25">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 dark:text-white"><?= $completed_count ?></div>
            <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-1">Projects Completed</div>
        </div>

        <!-- Success Rate -->
        <div class="stat-card stat-card-amber glass p-5 text-center reveal reveal-d1">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-amber-500/25">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 dark:text-white"><?= $success_rate ?>%</div>
            <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-1">Success Rate</div>
        </div>

        <!-- Total Earnings -->
        <div class="stat-card stat-card-emerald glass p-5 text-center reveal reveal-d2">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-emerald-500/25">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div class="text-3xl font-extrabold text-emerald-600 dark:text-emerald-400">$<?= number_format($total_earnings, 0) ?></div>
            <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-1">Total Earnings</div>
        </div>

        <!-- Years of Experience -->
        <div class="stat-card stat-card-purple glass p-5 text-center reveal reveal-d3">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500 to-violet-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-purple-500/25">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
            </div>
            <div class="text-3xl font-extrabold text-slate-900 dark:text-white"><?= $freelancer['experience_years'] ?? 0 ?></div>
            <div class="text-xs font-semibold text-slate-500 dark:text-slate-400 mt-1">Years Experience</div>
        </div>
    </div>

    <!-- ===== ABOUT ME ===== -->
    <?php if ($freelancer['bio']): ?>
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg shadow-indigo-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">About Me</h2>
        </div>
        <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300 whitespace-pre-wrap"><?= nl2br(e($freelancer['bio'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- ===== SKILLS ===== -->
    <?php if (!empty($fl_skills)): ?>
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-blue-500 to-cyan-500 shadow-lg shadow-blue-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Skills</h2>
            <span class="text-xs font-semibold text-slate-400 ml-auto"><?= count($fl_skills) ?> skill<?= count($fl_skills) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="flex flex-wrap gap-2.5">
            <?php foreach ($fl_skills as $sk): ?>
                <span class="skill-tag"><?= e($sk) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== PORTFOLIO GALLERY ===== -->
    <?php if (!empty($portfolio_items)): ?>
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-violet-500 to-fuchsia-500 shadow-lg shadow-violet-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Portfolio</h2>
            <span class="text-xs font-semibold text-slate-400 ml-auto"><?= count($portfolio_items) ?> project<?= count($portfolio_items) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <?php foreach ($portfolio_items as $item):
                $cover = $item['cover_image'] ? base_url('uploads/images/' . $item['cover_image']) : null;
            ?>
            <div class="portfolio-card glass cursor-pointer group" onclick="<?= $cover ? "openModal(this.querySelector('img')?.src)" : '' ?>">
                <?php if ($cover): ?>
                    <div class="overflow-hidden">
                        <img src="<?= e($cover) ?>" alt="" class="portfolio-img">
                    </div>
                <?php else: ?>
                    <div class="portfolio-img flex items-center justify-center" style="background:linear-gradient(135deg,rgba(99,102,241,0.08),rgba(139,92,246,0.08))">
                        <svg class="w-12 h-12 text-indigo-300 dark:text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                    </div>
                <?php endif; ?>
                <div class="p-5">
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors"><?= e($item['title']) ?></h3>
                    <?php if ($item['description']): ?>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1.5 line-clamp-2 leading-relaxed"><?= e(mb_strimwidth($item['description'], 0, 100, '...')) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($item['skills'])): ?>
                        <div class="flex flex-wrap gap-1.5 mt-3">
                            <?php foreach (array_slice($item['skills'], 0, 3) as $sk): ?>
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400"><?= e($sk) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="flex gap-3 mt-3">
                        <?php if ($item['project_url']): ?>
                            <a href="<?= e($item['project_url']) ?>" target="_blank" class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors" onclick="event.stopPropagation()">Live Demo &rarr;</a>
                        <?php endif; ?>
                        <?php if ($item['github_url']): ?>
                            <a href="<?= e($item['github_url']) ?>" target="_blank" class="text-xs font-semibold text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors" onclick="event.stopPropagation()">Source &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($portfolio_items) > 4): ?>
            <div class="mt-5 text-center">
                <a href="<?= e(base_url('freelancer/view_portfolio.php?id=' . $fid)) ?>" class="inline-flex items-center gap-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 transition-colors">
                    View all <?= count($portfolio_items) ?> projects
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ===== COMPLETED PROJECTS / WORK EXPERIENCE ===== -->
    <?php if (!empty($completed_projects)): ?>
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-emerald-500 to-teal-500 shadow-lg shadow-emerald-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Work Experience</h2>
            <span class="text-xs font-semibold text-slate-400 ml-auto"><?= $completed_count ?> completed</span>
        </div>
        <div class="space-y-0">
            <?php foreach (array_slice($completed_projects, 0, 6) as $cp): ?>
            <div class="flex items-center gap-4 py-4 border-b border-slate-100 dark:border-slate-800 last:border-0">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center flex-shrink-0 shadow-md shadow-emerald-500/20">
                    <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white truncate"><?= e($cp['title']) ?></p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5"><?= e($cp['company_name']) ?> &middot; <?= date('M Y', strtotime($cp['assigned_at'])) ?></p>
                </div>
                <?php if ($cp['amount']): ?>
                    <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400 flex-shrink-0">$<?= number_format((float)$cp['amount'], 0) ?></span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== EDUCATION (placeholder section) ===== -->
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-amber-500 to-orange-500 shadow-lg shadow-amber-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Education</h2>
        </div>
        <div class="text-center py-8">
            <svg class="w-12 h-12 mx-auto mb-3 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
            <p class="text-sm text-slate-400 dark:text-slate-500">Education details will appear here once added.</p>
        </div>
    </div>

    <!-- ===== CERTIFICATES (placeholder section) ===== -->
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-rose-500 to-pink-500 shadow-lg shadow-rose-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Certificates</h2>
        </div>
        <div class="text-center py-8">
            <svg class="w-12 h-12 mx-auto mb-3 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
            <p class="text-sm text-slate-400 dark:text-slate-500">Certificates will appear here once added.</p>
        </div>
    </div>

    <!-- ===== REVIEWS & RATINGS ===== -->
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-amber-400 to-yellow-500 shadow-lg shadow-amber-500/20">
                <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Reviews & Ratings</h2>
            <?php if ($total_reviews > 0): ?>
                <span class="text-xs font-semibold text-slate-400 ml-auto"><?= $total_reviews ?> review<?= $total_reviews !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($total_reviews > 0): ?>
        <!-- Rating Overview -->
        <div class="flex flex-col sm:flex-row gap-6 p-5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 mb-6">
            <div class="text-center sm:min-w-[120px]">
                <div class="text-5xl font-black text-slate-900 dark:text-white leading-none"><?= $avg_rating ?></div>
                <div class="flex gap-0.5 justify-center my-2">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <svg class="w-5 h-5" fill="<?= $s <= round($avg_rating) ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <?php endfor; ?>
                </div>
                <div class="text-xs text-slate-500 dark:text-slate-400 font-medium"><?= $total_reviews ?> review<?= $total_reviews !== 1 ? 's' : '' ?></div>
            </div>
            <div class="flex-1 space-y-2">
                <?php for ($star = 5; $star >= 1; $star--):
                    $cnt = $rating_dist[$star] ?? 0;
                    $pct = $total_reviews > 0 ? round(($cnt / $total_reviews) * 100) : 0;
                ?>
                <div class="flex items-center gap-3">
                    <span class="text-xs font-semibold text-slate-400 w-4 text-right"><?= $star ?></span>
                    <div class="review-bar flex-1">
                        <div class="review-bar-fill" data-width="<?= $pct ?>%"></div>
                    </div>
                    <span class="text-xs text-slate-400 w-4"><?= $cnt ?></span>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Review List -->
        <div class="space-y-0">
            <?php foreach ($reviews as $rv): ?>
            <div class="py-5 border-b border-slate-100 dark:border-slate-800 last:border-0">
                <div class="flex items-center gap-3 mb-3">
                    <?php if (!empty($rv['reviewer_image'])): ?>
                        <img src="<?= e(base_url('uploads/images/' . $rv['reviewer_image'])) ?>" alt="" class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-800 shadow-sm">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white bg-gradient-to-br from-indigo-500 to-purple-600 shadow-sm"><?= strtoupper(mb_substr($rv['company_name'] ?? 'C', 0, 1)) ?></div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($rv['company_name'] ?? 'Client') ?></p>
                        <p class="text-[11px] text-slate-400"><?= date('M j, Y', strtotime($rv['created_at'])) ?></p>
                    </div>
                    <div class="flex gap-0.5">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <svg class="w-3.5 h-3.5" fill="<?= $s <= $rv['rating'] ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php if ($rv['comment']): ?>
                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed"><?= nl2br(e($rv['comment'])) ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="text-center py-10">
                <svg class="w-14 h-14 mx-auto mb-3 text-slate-300 dark:text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
                <p class="text-sm text-slate-400 dark:text-slate-500">No reviews yet. Be the first to leave a review!</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- ===== COMPANIES WORKED WITH ===== -->
    <?php if (!empty($companies_worked)): ?>
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-sky-500 to-blue-600 shadow-lg shadow-sky-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Companies Worked With</h2>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-4">
            <?php foreach ($companies_worked as $cw): ?>
            <div class="flex flex-col items-center p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all duration-300 hover:-translate-y-1">
                <?php if ($cw['logo']): ?>
                    <img src="<?= e($cw['logo']) ?>" alt="" class="w-12 h-12 rounded-xl object-contain bg-white dark:bg-slate-700 p-1 shadow-sm mb-2">
                <?php else: ?>
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-lg font-bold text-indigo-600 bg-indigo-50 dark:bg-indigo-900/30 dark:text-indigo-400 mb-2"><?= strtoupper(mb_substr($cw['name'], 0, 1)) ?></div>
                <?php endif; ?>
                <span class="text-[11px] font-semibold text-slate-600 dark:text-slate-300 text-center leading-tight line-clamp-2"><?= e($cw['name']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== CONTACT INFORMATION ===== -->
    <div class="section-card p-6 sm:p-8 mb-6 reveal">
        <div class="section-header">
            <div class="section-icon bg-gradient-to-br from-indigo-500 to-blue-600 shadow-lg shadow-indigo-500/20">
                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
            </div>
            <h2 class="text-lg font-bold text-slate-900 dark:text-white">Contact Information</h2>
        </div>
        <div class="grid sm:grid-cols-2 gap-4">
            <!-- Email -->
            <div class="flex items-center gap-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-100 to-violet-100 dark:from-indigo-900/40 dark:to-violet-900/40 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Email</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($freelancer['email']) ?></p>
                </div>
            </div>

            <?php if ($freelancer['phone']): ?>
            <!-- Phone -->
            <div class="flex items-center gap-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-emerald-100 to-teal-100 dark:from-emerald-900/40 dark:to-teal-900/40 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Phone</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($freelancer['phone']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($freelancer['location']): ?>
            <!-- Location -->
            <div class="flex items-center gap-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-100 to-pink-100 dark:from-rose-900/40 dark:to-pink-900/40 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-rose-600 dark:text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Location</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($freelancer['location']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Member Since -->
            <div class="flex items-center gap-4 p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-100 to-orange-100 dark:from-amber-900/40 dark:to-orange-900/40 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                </div>
                <div>
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Member Since</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= date('F Y', strtotime($freelancer['created_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ===== IMAGE MODAL ===== -->
<div class="img-modal" id="imageModal" onclick="closeModal()">
    <img id="modalImg" src="" alt="">
</div>

<!-- ===== HIRE MODAL ===== -->
<div id="hireModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-lg max-h-[90dvh] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="shrink-0 p-6 pb-4 border-b border-slate-100 dark:border-slate-800">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold text-slate-900 dark:text-white">Hire <?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h3>
                    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Send a direct hire request with project details</p>
                </div>
                <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-slate-600 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <?php if (!$is_hired && !$pending_hire): ?>
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="direct_hire">

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Project Title <span class="text-red-500">*</span></label>
                <input type="text" name="project_title" required maxlength="255" class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="e.g. Website Redesign">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Description <span class="text-red-500">*</span></label>
                <textarea name="project_description" rows="3" required class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none" placeholder="Describe the project requirements..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Budget ($) <span class="text-red-500">*</span></label>
                    <input type="number" name="budget" min="1" step="0.01" required class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Deadline</label>
                    <input type="date" name="deadline" min="<?= date('Y-m-d') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Payment Type <span class="text-red-500">*</span></label>
                <select name="payment_type" id="hirePaymentType" required onchange="toggleMilestones()" class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                    <option value="fixed">Fixed Price</option>
                    <option value="milestone">Milestone-Based</option>
                </select>
            </div>

            <div id="milestonesSection" class="hidden">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">Milestones <span class="text-red-500">*</span></label>
                    <span class="text-xs text-slate-400">Milestone sum must equal budget</span>
                </div>
                <div id="milestonesContainer" class="space-y-3"></div>
                <div class="flex items-center justify-between mt-3 p-2.5 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800/30">
                    <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">Milestone Total</span>
                    <span class="text-sm font-bold text-indigo-700 dark:text-indigo-300" id="milestoneTotal">$0.00</span>
                </div>
                <button type="button" onclick="addMilestone()" class="mt-2 w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border border-dashed border-indigo-300 dark:border-indigo-700 text-indigo-500 dark:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Milestone
                </button>
                <p id="milestoneError" class="text-xs text-red-500 mt-1 hidden"></p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-none" placeholder="Additional notes for the freelancer..."></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Attachment</label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.docx,.zip" class="w-full px-4 py-2.5 rounded-xl text-sm border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-400">
                <p class="text-xs text-slate-400 mt-1">JPG, PNG, PDF, DOCX, ZIP (Max 10MB)</p>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:-translate-y-0.5 transition-all">Send Hire Request</button>
            </div>
        </form>
        <?php else: ?>
        <div class="p-6 text-center overflow-y-auto flex-1 min-h-0">
            <p class="text-slate-500 dark:text-slate-400">
                <?php if ($is_hired): ?>You have already hired this freelancer.
                <?php else: ?>A hire request is already pending for this freelancer.<?php endif; ?>
            </p>
            <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="mt-4 px-6 py-2.5 rounded-xl text-sm font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">Close</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>

<script>
/* Scroll reveal */
var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(el) {
        if (el.isIntersecting) {
            el.target.classList.add('visible');
            el.target.querySelectorAll('[data-width]').forEach(function(bar) {
                setTimeout(function() { bar.style.width = bar.getAttribute('data-width'); }, 300);
            });
            obs.unobserve(el.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
document.querySelectorAll('.reveal').forEach(function(el) { obs.observe(el); });

/* Animate bars already in view */
setTimeout(function() {
    document.querySelectorAll('[data-width]').forEach(function(bar) {
        if (bar.getBoundingClientRect().top < window.innerHeight) {
            bar.style.width = bar.getAttribute('data-width');
        }
    });
}, 500);

/* Image modal */
function openModal(src) {
    if (!src) return;
    document.getElementById('modalImg').src = src;
    document.getElementById('imageModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('imageModal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

/* Hire modal close on backdrop */
document.getElementById('hireModal').addEventListener('click', function(e) {
    if (e.target === this) this.classList.add('hidden');
});

/* Milestone management */
var milestoneCount = 0;
function toggleMilestones() {
    var sel = document.getElementById('hirePaymentType');
    var sec = document.getElementById('milestonesSection');
    if (sel.value === 'milestone') {
        sec.classList.remove('hidden');
        if (milestoneCount === 0) addMilestone();
    } else {
        sec.classList.add('hidden');
    }
}
function addMilestone() {
    milestoneCount++;
    var n = milestoneCount;
    var today = new Date().toISOString().split('T')[0];
    var html = '<div class="p-3 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700" id="ms_' + n + '">' +
        '<div class="flex items-center justify-between mb-2">' +
            '<span class="text-xs font-bold uppercase tracking-wider text-slate-400">Milestone ' + n + '</span>' +
            '<button type="button" onclick="removeMilestone(' + n + ')" class="text-slate-400 hover:text-red-500 transition-colors"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>' +
        '</div>' +
        '<div class="space-y-2">' +
            '<input type="text" name="ms_title[]" required maxlength="200" placeholder="Milestone title" class="w-full px-3 py-2 rounded-lg text-sm border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">' +
            '<textarea name="ms_desc[]" rows="2" placeholder="Brief description" class="w-full px-3 py-2 rounded-lg text-sm border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>' +
            '<div class="grid grid-cols-2 gap-2">' +
                '<div><label class="block text-[11px] font-medium text-slate-400 mb-0.5">Amount ($)</label><input type="number" name="ms_amount[]" min="1" step="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg text-sm border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 ms-amount" oninput="updateMilestoneTotal()"></div>' +
                '<div><label class="block text-[11px] font-medium text-slate-400 mb-0.5">Deadline</label><input type="date" name="ms_deadline[]" min="' + today + '" class="w-full px-3 py-2 rounded-lg text-sm border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></div>' +
            '</div>' +
        '</div>' +
    '</div>';
    document.getElementById('milestonesContainer').insertAdjacentHTML('beforeend', html);
    updateMilestoneTotal();
}
function removeMilestone(n) {
    var el = document.getElementById('ms_' + n);
    if (el) el.remove();
    updateMilestoneTotal();
}
function updateMilestoneTotal() {
    var total = 0;
    document.querySelectorAll('.ms-amount').forEach(function(input) { total += parseFloat(input.value) || 0; });
    document.getElementById('milestoneTotal').textContent = '$' + total.toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    var errEl = document.getElementById('milestoneError');
    var budgetInput = document.querySelector('input[name="budget"]');
    var budget = parseFloat(budgetInput ? budgetInput.value : 0) || 0;
    if (budget > 0 && total > 0 && Math.abs(total - budget) > 0.01) {
        errEl.textContent = 'Milestone total ($' + total.toFixed(2) + ') does not match budget ($' + budget.toFixed(2) + ')';
        errEl.classList.remove('hidden');
    } else {
        errEl.classList.add('hidden');
    }
}
document.querySelector('#hireModal form')?.addEventListener('submit', function(e) {
    if (document.getElementById('hirePaymentType').value === 'milestone') {
        var total = 0;
        document.querySelectorAll('.ms-amount').forEach(function(input) { total += parseFloat(input.value) || 0; });
        var budget = parseFloat(document.querySelector('input[name="budget"]').value) || 0;
        if (total <= 0) { e.preventDefault(); alert('Please add at least one milestone with an amount.'); return false; }
        if (budget > 0 && Math.abs(total - budget) > 0.01) { e.preventDefault(); alert('Milestone total ($' + total.toFixed(2) + ') must match the project budget ($' + budget.toFixed(2) + ').'); return false; }
        var titles = document.querySelectorAll('input[name="ms_title[]"]');
        for (var i = 0; i < titles.length; i++) {
            if (!titles[i].value.trim()) { e.preventDefault(); alert('Please fill in all milestone titles.'); titles[i].focus(); return false; }
        }
    }
});
</script>

</body>
</html>
