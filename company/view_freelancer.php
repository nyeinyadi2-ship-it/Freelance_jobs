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
$fallback_url = ($viewer_role === 'company') ? 'index.php' : (($viewer_role === 'freelancer') ? 'freelancer/dashboard.php' : 'index.php');

if ($fid <= 0) { redirect($fallback_url); }

// Fetch freelancer data
$st = $conn->prepare("SELECT f.id, f.full_name, f.title, f.location, f.bio, f.experience_years, f.hourly_rate, f.phone, u.id AS user_id, u.profile_image, u.username, u.email, u.created_at, u.is_online, u.last_seen
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



// Completed projects
$completed_projects = [];
$r = $conn->prepare("SELECT a.id, a.assigned_at, j.title, j.budget, p.amount, p.paid_at, c.id AS company_id, c.company_name, c.logo_image
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
    if (!in_array($cp['company_id'], $company_ids_seen)) {
        $company_ids_seen[] = $cp['company_id'];
        $companies_worked[] = [
            'name' => $cp['company_name'],
            'logo' => $cp['logo_image'] ? base_url('uploads/images/' . $cp['logo_image']) : null,
        ];
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
// Removed already hired and pending hire checks to allow multiple projects

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
                $has_pending = false; // Multiple hires allowed
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
                                                $stmt_bal = $conn->prepare("UPDATE users SET available_balance = available_balance - ?, reserved_balance = reserved_balance + ? WHERE id = ? AND available_balance >= ?");
                                                $stmt_bal->bind_param('ddid', $ms_row['amount'], $ms_row['amount'], $user['user_id'], $ms_row['amount']);
                                                $stmt_bal->execute();
                                                if ($stmt_bal->affected_rows === 0) {
                                                    throw new Exception("Insufficient balance to fund the first milestone.");
                                                }
                                                $stmt_bal->close();

                                                $up = $conn->prepare("UPDATE milestones SET status = 'funded' WHERE id = ?");
                                                $up->bind_param('i', $ms_row['id']); $up->execute(); $up->close();
                                                $conn->commit();
                                            } catch (Exception $e) { 
                                                $conn->rollback();
                                                $hire_error = $e->getMessage();
                                                $pending_hire = false;
                                            }
                                        }
                                    } else {
                                        // Reserve funds for Fixed price project
                                        $conn->begin_transaction();
                                        try {
                                            $stmt_bal = $conn->prepare("UPDATE users SET available_balance = available_balance - ?, reserved_balance = reserved_balance + ? WHERE id = ? AND available_balance >= ?");
                                            $stmt_bal->bind_param('ddid', $budget, $budget, $user['user_id'], $budget);
                                            $stmt_bal->execute();
                                            if ($stmt_bal->affected_rows === 0) {
                                                throw new Exception("Insufficient balance to reserve project budget.");
                                            }
                                            $stmt_bal->close();
                                            $conn->commit();
                                        } catch (Exception $e) { 
                                            $conn->rollback();
                                            $hire_error = $e->getMessage();
                                            $pending_hire = false;
                                        }
                                    }
                                if (empty($hire_error)) {
                                    create_notification($conn, (int) $freelancer['user_id'], 'direct_hire', "You have a new direct hire request from a company for: {$title}", "freelancer/dashboard.php");
                                    $hire_success = 'Hire request sent successfully! The freelancer will be notified.';
                                    $pending_hire = true;
                                }
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
    <title><?= e($freelancer['full_name'] ?? 'Freelancer') ?> - Freelancer Profile - FreelanceHub</title>
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

<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8" style="padding-top:88px;">

    <!-- Success/Error Messages -->
    <?php if ($hire_success): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm font-medium reveal"><?= e($hire_success) ?></div>
    <?php endif; ?>
    <?php if ($hire_error): ?>
        <div class="mb-6 p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-medium reveal"><?= e($hire_error) ?></div>
    <?php endif; ?>

    <div class="mb-4">
        <button onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-gray-800 dark:hover:bg-gray-700">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </button>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- LEFT COLUMN: Profile info -->
        <div class="space-y-6 reveal">
            <div class="section-card p-6 text-center">
                <!-- Avatar -->
                <div class="mb-4 flex justify-center">
                    <?php if ($profileImgUrl): ?>
                        <img src="<?= e($profileImgUrl) ?>" alt="" class="w-32 h-32 rounded-full object-cover border-4 border-white dark:border-slate-800 shadow-lg">
                    <?php else: ?>
                        <div class="w-32 h-32 rounded-full flex items-center justify-center text-4xl font-bold text-white bg-gradient-to-br from-indigo-500 to-purple-600 border-4 border-white dark:border-slate-800 shadow-lg">
                            <?= strtoupper(mb_substr($freelancer['full_name'] ?? $freelancer['username'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info -->
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-1"><?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h1>
                <p class="text-sm font-semibold text-indigo-600 dark:text-indigo-400 mb-4"><?= e($freelancer['title'] ?? 'Freelancer') ?></p>
                
                <div class="flex flex-col gap-2 text-sm text-slate-600 dark:text-slate-400 mb-6 border-t border-slate-100 dark:border-slate-800 pt-4">
                    <?php if ($freelancer['location']): ?>
                    <div class="flex items-center gap-2 justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        <span><?= e($freelancer['location']) ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="flex items-center gap-2 justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>Member since <?= date('M Y', strtotime($freelancer['created_at'])) ?></span>
                    </div>

                    <div class="flex items-center gap-2 justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        <span class="truncate"><?= e($freelancer['email']) ?></span>
                    </div>
                    
                    <?php if ($freelancer['phone']): ?>
                    <div class="flex items-center gap-2 justify-center">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <span><?= e($freelancer['phone']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="flex flex-col gap-3">
                    <?php if ($is_company): ?>
                            <button type="button" onclick="document.getElementById('hireModal').classList.remove('hidden')" class="btn-glow w-full justify-center inline-flex items-center gap-2 px-6 py-3 rounded-xl text-sm font-bold text-white">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Hire Freelancer
                            </button>
                        <a href="<?= e(base_url('chat/index.php?user=' . $freelancer['user_id'])) ?>" class="w-full inline-flex justify-center items-center gap-2 px-5 py-3 rounded-xl text-sm font-bold border border-slate-200 text-slate-700 hover:bg-slate-50 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                            Send Message
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Skills List in Left Sidebar -->
            <?php if (!empty($fl_skills)): ?>
            <div class="section-card p-6 reveal">
                <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-4 uppercase tracking-wider">Skills</h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($fl_skills as $sk): ?>
                        <span class="skill-tag"><?= e($sk) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- MAIN COLUMN: Content -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- ===== ABOUT ME ===== -->
            <?php if ($freelancer['bio']): ?>
            <div class="section-card p-6 sm:p-8 reveal reveal-d1">
                <div class="section-header mb-4 border-b border-slate-100 dark:border-slate-800 pb-4">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                        About Me
                    </h2>
                </div>
                <p class="text-sm leading-relaxed text-slate-600 dark:text-slate-300 whitespace-pre-wrap"><?= nl2br(e($freelancer['bio'])) ?></p>
            </div>
            <?php endif; ?>

            <!-- ===== COMPLETED PROJECTS / WORK EXPERIENCE ===== -->
            <?php if (!empty($completed_projects)): ?>
            <div class="section-card p-6 sm:p-8 reveal reveal-d2">
                <div class="section-header mb-4 border-b border-slate-100 dark:border-slate-800 pb-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                        Work Experience
                    </h2>
                    <span class="text-xs font-semibold text-slate-400 bg-slate-100 dark:bg-slate-800 px-3 py-1 rounded-full"><?= $completed_count ?> completed</span>
                </div>
                <div class="space-y-4">
                    <?php foreach (array_slice($completed_projects, 0, 6) as $cp): ?>
                    <div class="flex items-start gap-4">
                        <div class="w-10 h-10 rounded-full bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0 text-emerald-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div class="flex-1 min-w-0 pt-0.5">
                            <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($cp['title']) ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?= e($cp['company_name']) ?> &middot; <?= date('M Y', strtotime($cp['assigned_at'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ===== EDUCATION ===== -->
            <div class="section-card p-6 sm:p-8 reveal reveal-d3">
                <div class="section-header mb-4 border-b border-slate-100 dark:border-slate-800 pb-4">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342M6.75 15a.75.75 0 100-1.5.75.75 0 000 1.5zm0 0v-3.675A55.378 55.378 0 0112 8.443m-7.007 11.55A5.981 5.981 0 006.75 15.75v-1.5"/></svg>
                        Education
                    </h2>
                </div>
                <div class="text-center py-6">
                    <p class="text-sm text-slate-400 dark:text-slate-500">Education details will appear here once added.</p>
                </div>
            </div>

            <!-- ===== REVIEWS & RATINGS ===== -->
            <div class="section-card p-6 sm:p-8 reveal reveal-d4">
                <div class="section-header mb-4 border-b border-slate-100 dark:border-slate-800 pb-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-slate-900 dark:text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        Reviews & Ratings
                    </h2>
                    <?php if ($total_reviews > 0): ?>
                        <span class="text-xs font-semibold text-slate-400"><?= $total_reviews ?> review<?= $total_reviews !== 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($total_reviews > 0): ?>
                <div class="space-y-4">
                    <?php foreach ($reviews as $rv): ?>
                    <div class="py-4 border-b border-slate-100 dark:border-slate-800 last:border-0 last:pb-0">
                        <div class="flex flex-col sm:flex-row gap-3">
                            <div class="flex-shrink-0">
                                <?php if (!empty($rv['reviewer_image'])): ?>
                                    <img src="<?= e(base_url('uploads/images/' . $rv['reviewer_image'])) ?>" alt="" class="w-10 h-10 rounded-full object-cover shadow-sm">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white bg-slate-300 dark:bg-slate-700 shadow-sm"><?= strtoupper(mb_substr($rv['company_name'] ?? 'C', 0, 1)) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                                    <p class="text-sm font-semibold text-slate-900 dark:text-white"><?= e($rv['company_name'] ?? 'Client') ?></p>
                                    <div class="flex gap-0.5">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <svg class="w-3.5 h-3.5" fill="<?= $s <= $rv['rating'] ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <?php if ($rv['comment']): ?>
                                    <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed mt-2"><?= nl2br(e($rv['comment'])) ?></p>
                                <?php endif; ?>
                                <p class="text-[11px] text-slate-400 mt-2"><?= date('M j, Y', strtotime($rv['created_at'])) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="text-center py-6">
                        <p class="text-sm text-slate-400 dark:text-slate-500">No reviews yet.</p>
                    </div>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
</div>
<!-- ===== IMAGE MODAL ===== -->
<div class="img-modal" id="imageModal" onclick="closeModal()">
    <img id="modalImg" src="" alt="">
</div>

<!-- ===== HIRE MODAL ===== -->
<div id="hireModal" class="hidden fixed inset-0 z-[105] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="relative z-[110] pointer-events-auto w-full max-w-lg max-h-[90dvh] bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden">
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

        <!-- Modal conditional removed -->
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
                    <label class="block text-sm font-medium mb-1.5 text-slate-700 dark:text-slate-300">Budget (MMK) <span class="text-red-500">*</span></label>
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
                    <span class="text-sm font-bold text-indigo-700 dark:text-indigo-300" id="milestoneTotal">0.00 MMK</span>
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
                '<div><label class="block text-[11px] font-medium text-slate-400 mb-0.5">Amount (MMK)</label><input type="number" name="ms_amount[]" min="1" step="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg text-sm border border-slate-200 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 ms-amount" oninput="updateMilestoneTotal()"></div>' +
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
    document.getElementById('milestoneTotal').textContent = total.toLocaleString('en', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' MMK';
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
