<?php
$page_title = 'Freelancer Profile';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';
require_once __DIR__ . '/../config/upload.php';

$fid = (int) ($_GET['id'] ?? 0);
if ($fid <= 0) { redirect('index.php'); }

$st = $conn->prepare("SELECT f.id, f.full_name, f.title, f.location, f.bio, f.experience_years, f.hourly_rate, f.portfolio_url, f.phone, u.id AS user_id, u.profile_image, u.username, u.email, u.created_at
    FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
$st->bind_param('i', $fid);
$st->execute();
$freelancer = $st->get_result()->fetch_assoc();
$st->close();
if (!$freelancer) { redirect('index.php'); }

$profileImgUrl = $freelancer['profile_image'] ? base_url('uploads/' . $freelancer['profile_image']) : null;

$fl_skills = [];
$r = $conn->prepare("SELECT s.skill_name FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE fs.freelancer_id = ?");
$r->bind_param('i', $fid); $r->execute();
$sr = $r->get_result();
while ($row = $sr->fetch_assoc()) $fl_skills[] = $row['skill_name'];
$r->close();

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
                'logo' => $cl_row['logo_image'] ? base_url('uploads/' . $cl_row['logo_image']) : null,
            ];
        }
    }
}

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

$total_earnings = 0;
$r = $conn->prepare("SELECT COALESCE(SUM(p.amount), 0) AS total FROM payments p JOIN assignments a ON p.assignment_id = a.id WHERE a.freelancer_id = ? AND p.status = 'paid'");
$r->bind_param('i', $fid); $r->execute();
$er = $r->get_result()->fetch_assoc();
$total_earnings = (float) ($er['total'] ?? 0);
$r->close();

$is_hired = false;
$pending_hire = false;
$hire_success = '';
$hire_error = '';

if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'company') {
    $logged_in_company = get_company_id($conn, (int) $_SESSION['user_id']);
    if ($logged_in_company) {
        $chk = $conn->prepare("SELECT a.id FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.freelancer_id = ?");
        $chk->bind_param('ii', $logged_in_company, $fid);
        $chk->execute();
        $is_hired = $chk->get_result()->num_rows > 0;
        $chk->close();

        // Check for pending direct hire
        if (!$is_hired) {
            $chk2 = $conn->prepare("SELECT id FROM assignments WHERE freelancer_id = ? AND assignment_type = 'direct_hire' AND freelancer_response = 'pending' AND job_id IN (SELECT id FROM jobs WHERE company_id = ?)");
            $chk2->bind_param('ii', $fid, $logged_in_company);
            $chk2->execute();
            $pending_hire = $chk2->get_result()->num_rows > 0;
            $chk2->close();
        }
    }
}

// Handle Direct Hire form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'direct_hire') {
    if (!verify_csrf()) {
        $hire_error = 'Invalid request.';
    } elseif (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'company') {
        $hire_error = 'You must be logged in as a company.';
    } else {
        $company_id = get_company_id($conn, (int) $_SESSION['user_id']);
        if (!$company_id) {
            $hire_error = 'Company profile not found.';
        } else {
            $title = trim($_POST['project_title'] ?? '');
            $description = trim($_POST['project_description'] ?? '');
            $budget = (float) ($_POST['budget'] ?? 0);
            $deadline = trim($_POST['deadline'] ?? '');
            $payment_type = $_POST['payment_type'] ?? 'fixed';
            $notes = trim($_POST['notes'] ?? '');

            // Milestone data
            $ms_titles = $_POST['ms_title'] ?? [];
            $ms_descs = $_POST['ms_desc'] ?? [];
            $ms_amounts = $_POST['ms_amount'] ?? [];
            $ms_deadlines = $_POST['ms_deadline'] ?? [];

            // Handle attachment upload
            $attachment_name = null;
            if (!empty($_FILES['attachment']['name'])) {
                $attachment_name = upload_attachment($_FILES['attachment']);
                if ($attachment_name === null) {
                    $hire_error = 'Invalid attachment. Allowed: JPG, PNG, PDF, DOCX, ZIP. Max 10MB.';
                }
            }

            if ($title === '') {
                $hire_error = 'Project title is required.';
            } elseif ($description === '') {
                $hire_error = 'Project description is required.';
            } elseif ($budget <= 0) {
                $hire_error = 'Budget must be greater than zero.';
            } elseif ($payment_type === 'milestone' && empty($ms_titles)) {
                $hire_error = 'Please add at least one milestone.';
            } else {
                // Check for existing pending direct hire request
                $dup_check = $conn->prepare("SELECT id FROM assignments WHERE freelancer_id = ? AND assignment_type = 'direct_hire' AND freelancer_response = 'pending' AND job_id IN (SELECT id FROM jobs WHERE company_id = ?)");
                $dup_check->bind_param('ii', $fid, $company_id);
                $dup_check->execute();
                $has_pending = $dup_check->get_result()->num_rows > 0;
                $dup_check->close();

                if ($has_pending) {
                    $hire_error = 'You already have a pending direct hire request for this freelancer.';
                } else {
                    // Validate milestone totals if milestone-based
                    if ($payment_type === 'milestone') {
                    $ms_total = 0;
                    foreach ($ms_amounts as $amt) {
                        $ms_total += (float) $amt;
                    }
                    if (abs($ms_total - $budget) > 0.01) {
                        $hire_error = 'Milestone total ($' . number_format($ms_total, 2) . ') must match the budget ($' . number_format($budget, 2) . ').';
                    }
                }

                if (empty($hire_error)) {
                    // Create a job record for this direct hire
                    $stmt = $conn->prepare("INSERT INTO jobs (company_id, title, category, description, budget, deadline, experience_level, gender_requirement, visibility, status, duration) VALUES (?, ?, 'Direct Hire', ?, ?, ?, 'any', 'any', 'private', 'approved', '')");
                    $stmt->bind_param('issds', $company_id, $title, $description, $budget, $deadline);
                    $stmt->execute();
                    $job_id = $stmt->insert_id;
                    $stmt->close();

                    if ($job_id > 0) {
                        // Create milestones if milestone-based
                        if ($payment_type === 'milestone' && !empty($ms_titles)) {
                            $ms_stmt = $conn->prepare('INSERT INTO milestones (job_id, title, description, amount, deadline, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                            foreach ($ms_titles as $idx => $ms_t) {
                                $ms_t = trim($ms_t);
                                $ms_a = (float) ($ms_amounts[$idx] ?? 0);
                                $ms_d = trim($ms_descs[$idx] ?? '');
                                $ms_dl = trim($ms_deadlines[$idx] ?? '') !== '' ? trim($ms_deadlines[$idx]) : null;
                                if ($ms_t !== '' && $ms_a > 0) {
                                    $order = $idx + 1;
                                    $ms_stmt->bind_param('issdsi', $job_id, $ms_t, $ms_d, $ms_a, $ms_dl, $order);
                                    $ms_stmt->execute();
                                }
                            }
                            $ms_stmt->close();
                        }

                        // Create assignment
                        $deadline_val = $deadline !== '' ? $deadline : null;
                        $notes_val = $notes !== '' ? $notes : null;
                        $stmt = $conn->prepare("INSERT INTO assignments (job_id, freelancer_id, assignment_type, status, freelancer_response, project_title, project_description, budget, deadline, payment_type, notes, attachment) VALUES (?, ?, 'direct_hire', 'assigned', 'pending', ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->bind_param('iisssssss', $job_id, $fid, $title, $description, $budget, $deadline_val, $payment_type, $notes_val, $attachment_name);
                        $stmt->execute();
                        $assignment_id = $stmt->insert_id;
                        $stmt->close();

                        if ($assignment_id > 0) {
                            // Auto-fund the first milestone (create escrow record)
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
                                        $up->bind_param('i', $ms_row['id']);
                                        $up->execute();
                                        $up->close();

                                        $esc = $conn->prepare("INSERT INTO escrow (milestone_id, amount, status) VALUES (?, ?, 'held')");
                                        $esc->bind_param('id', $ms_row['id'], $ms_row['amount']);
                                        $esc->execute();
                                        $esc->close();

                                        $conn->commit();
                                    } catch (Exception $e) {
                                        $conn->rollback();
                                    }
                                }
                            }

                            // Notify freelancer
                            create_notification($conn, (int) $freelancer['user_id'], 'direct_hire', "You have a new direct hire request from a company for: {$title}", "freelancer/dashboard.php");
                            $hire_success = 'Hire request sent successfully! The freelancer will be notified.';
                            $pending_hire = true;
                        } else {
                            $hire_error = 'Failed to create assignment.';
                        }
                    } else {
                        $hire_error = 'Failed to create job record.';
                    }
                }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($freelancer['full_name'] ?? 'Freelancer') ?> - Freelancer Profile - HireWork</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{inter:['Inter','system-ui','sans-serif']},colors:{brand:{50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'}}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;}
        body{font-family:'Inter',system-ui,-apple-system,sans-serif;margin:0;color:#1e293b;}
        html.dark body{background:#0f172a;color:#e2e8f0;}

        /* ===== HERO ===== */
        .fl-hero{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 40%,#2563eb 100%);position:relative;overflow:hidden;}
        .fl-hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M20 20.5V18H0v-2h20v-2l4 3.5-4 3z'/%3E%3C/g%3E%3C/svg%3E");}
        .fl-hero::after{content:'';position:absolute;bottom:0;left:0;right:0;height:80px;background:linear-gradient(to top,#f8fafc,transparent);}

        /* ===== PROFILE CARD ===== */
        .fl-profile-card{max-width:1100px;margin:-72px auto 0;position:relative;z-index:10;padding:0 24px;}
        .fl-card{background:#fff;border-radius:20px;box-shadow:0 1px 3px rgba(0,0,0,0.04),0 10px 40px rgba(0,0,0,0.06);overflow:visible;}
        .fl-card-body{padding:28px 32px;}
        html.dark .fl-card{background:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,0.2),0 10px 40px rgba(0,0,0,0.3);}

        /* ===== AVATAR ===== */
        .fl-avatar{width:128px;height:128px;border-radius:50%;object-fit:cover;border:5px solid #fff;box-shadow:0 8px 30px rgba(0,0,0,0.12);flex-shrink:0;margin-top:-64px;position:relative;z-index:2;}
        .fl-avatar-ph{width:128px;height:128px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:44px;font-weight:800;color:#fff;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border:5px solid #fff;box-shadow:0 8px 30px rgba(0,0,0,0.12);flex-shrink:0;margin-top:-64px;position:relative;z-index:2;}

        /* ===== BUTTONS ===== */
        .fl-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;border-radius:12px;font-size:13.5px;font-weight:700;border:none;cursor:pointer;text-decoration:none;transition:all .25s cubic-bezier(.4,0,.2,1);}
        .fl-btn-primary{background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;box-shadow:0 4px 16px rgba(37,99,235,0.3);}
        .fl-btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(37,99,235,0.4);}
        .fl-btn-outline{background:#fff;color:#3b82f6;border:2px solid #e0e7ff;}
        html.dark .fl-btn-outline{background:#1e293b;color:#60a5fa;border-color:#334155;}
        .fl-btn-outline:hover{background:#eff6ff;border-color:#93c5fd;}
        .fl-btn-hired{background:#ecfdf5;color:#059669;border:2px solid #d1fae5;}

        /* ===== STATS ===== */
        .fl-stat{text-align:center;padding:22px 16px;background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);transition:all .3s;}
        html.dark .fl-stat{background:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
        .fl-stat:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,0.08);}

        /* ===== SECTION TITLE ===== */
        .fl-section-title{font-size:17px;font-weight:700;color:#0f172a;margin:0 0 20px;display:flex;align-items:center;gap:10px;}
        html.dark .fl-section-title{color:#f1f5f9;}
        .fl-section-title::before{content:'';width:4px;height:20px;border-radius:2px;background:linear-gradient(180deg,#3b82f6,#60a5fa);flex-shrink:0;}

        /* ===== SKILLS ===== */
        .fl-skill-tag{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:600;background:#f0f7ff;color:#2563eb;border:1px solid #dbeafe;transition:all .2s;}
        .fl-skill-tag:hover{background:#dbeafe;transform:translateY(-1px);}
        html.dark .fl-skill-tag{background:rgba(59,130,246,0.1);color:#60a5fa;border-color:rgba(59,130,246,0.2);}

        /* ===== PORTFOLIO ===== */
        .fl-portfolio-card{border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.04);transition:all .35s cubic-bezier(.4,0,.2,1);}
        html.dark .fl-portfolio-card{background:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,0.2);}
        .fl-portfolio-card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(0,0,0,0.1);}
        .fl-portfolio-img{width:100%;height:180px;object-fit:cover;transition:transform .5s;}
        .fl-portfolio-card:hover .fl-portfolio-img{transform:scale(1.05);}

        /* ===== PROJECT ===== */
        .fl-project{display:flex;align-items:center;gap:14px;padding:16px 0;}
        .fl-project+.fl-project{border-top:1px solid #f1f5f9;}
        html.dark .fl-project+.fl-project{border-color:#334155;}

        /* ===== REVIEWS ===== */
        .fl-review{padding:20px 0;}
        .fl-review+.fl-review{border-top:1px solid #f1f5f9;}
        html.dark .fl-review+.fl-review{border-color:#334155;}

        /* ===== COMPANIES ===== */
        .fl-company{display:flex;flex-direction:column;align-items:center;padding:20px 10px;border-radius:14px;background:#f8fafc;transition:all .3s;}
        html.dark .fl-company{background:#1e293b;}
        .fl-company:hover{background:#eff6ff;transform:translateY(-3px);}

        /* ===== CONTACT ===== */
        .fl-contact{display:flex;align-items:center;gap:14px;padding:14px 0;}
        .fl-contact+.fl-contact{border-top:1px solid #f1f5f9;}
        html.dark .fl-contact+.fl-contact{border-color:#334155;}

        /* ===== MODAL ===== */
        .fl-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.85);z-index:200;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
        .fl-modal.open{display:flex;}
        .fl-modal img{max-width:92vw;max-height:88vh;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,0.5);}

        /* ===== HIRE MODAL SCROLL ===== */
        #hireModal{overflow-y:auto;-webkit-overflow-scrolling:touch;}
        #hireModal .relative{margin:auto;}
        #hireModal .relative form,
        #hireModal .relative > div:last-child{scrollbar-width:thin;scrollbar-color:rgba(156,163,175,0.3) transparent;}
        @media(max-height:700px){
            #hireModal{align-items:flex-start;padding-top:16px;}
            #hireModal .relative{margin-top:auto;margin-bottom:auto;}
        }
        @media(max-width:480px){
            #hireModal{padding:8px;}
            #hireModal .relative{max-width:100%;border-radius:16px;}
        }

        /* ===== ANIMATIONS ===== */
        .fl-reveal{opacity:0;transform:translateY(24px);transition:opacity .6s cubic-bezier(.4,0,.2,1),transform .6s cubic-bezier(.4,0,.2,1);}
        .fl-reveal.visible{opacity:1;transform:translateY(0);}

        /* ===== RESPONSIVE ===== */
        @media(max-width:900px){
            .fl-grid{grid-template-columns:1fr!important;}
            .fl-card-main{flex-direction:column;align-items:center;text-align:center;padding:28px 24px 24px;}
            .fl-avatar,.fl-avatar-ph{margin-top:-56px;}
            .fl-meta{justify-content:center;}
            .fl-actions{justify-content:center;}
        }
        @media(max-width:640px){
            .fl-hero{padding-bottom:60px;}
            .fl-profile-card{padding:0 16px;margin-top:-56px;}
            .fl-card-body{padding:20px;}
            .fl-stat-grid{grid-template-columns:1fr 1fr!important;gap:12px!important;}
            .fl-name{font-size:24px!important;}
        }
    </style>
</head>
<body class="bg-[#f8fafc] dark:bg-[#0f172a]">

<?php require __DIR__ . '/../includes/navbar.php'; ?>

<!-- ===== HERO ===== -->
<div class="fl-hero" style="padding-top:64px;padding-bottom:100px;">
    <div class="max-w-7xl mx-auto px-6 pt-12 pb-8">
        <div class="flex items-center gap-3 text-white/60 text-sm">
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="hover:text-white transition-colors">Browse</a>
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            <span class="text-white/90 font-medium">Freelancer Profile</span>
        </div>
    </div>
</div>

<!-- ===== PROFILE HEADER ===== -->
<div class="fl-profile-card">
    <div class="fl-card fl-card-main" style="display:flex;align-items:flex-end;gap:28px;padding:32px 36px 28px;">
        <?php if ($profileImgUrl): ?>
            <img src="<?= e($profileImgUrl) ?>" alt="" class="fl-avatar">
        <?php else: ?>
            <div class="fl-avatar-ph"><?= strtoupper(mb_substr($freelancer['full_name'] ?? $freelancer['username'], 0, 1)) ?></div>
        <?php endif; ?>

        <div style="flex:1;min-width:0;padding-bottom:4px;">
            <div class="flex items-center gap-3 flex-wrap">
                <h1 class="fl-name" style="font-size:28px;font-weight:800;color:#0f172a;margin:0;"><?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h1>
                <?php if ($is_hired): ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                        <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>Hired
                    </span>
                <?php endif; ?>
            </div>
            <p style="font-size:15px;font-weight:600;color:#3b82f6;margin:4px 0 12px;"><?= e($freelancer['title'] ?? 'Freelancer') ?></p>
            <div class="fl-meta flex flex-wrap gap-4 text-sm" style="color:#64748b;">
                <?php if ($freelancer['location']): ?>
                    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg><?= e($freelancer['location']) ?></span>
                <?php endif; ?>
                <?php if ($total_reviews > 0): ?>
                    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?= $avg_rating ?> (<?= $total_reviews ?> reviews)</span>
                <?php endif; ?>
                <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>Member since <?= date('M Y', strtotime($freelancer['created_at'])) ?></span>
                <?php if ($freelancer['experience_years']): ?>
                    <span class="inline-flex items-center gap-1.5"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><?= $freelancer['experience_years'] ?> yr<?= $freelancer['experience_years'] > 1 ? 's' : '' ?> exp</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="fl-actions" style="display:flex;gap:12px;align-items:center;padding-bottom:4px;flex-shrink:0;">
            <div style="text-align:right;margin-right:8px;">
                <div style="font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Hourly Rate</div>
                <div style="font-size:26px;font-weight:800;color:#0f172a;">$<?= e(number_format((float)($freelancer['hourly_rate'] ?? 0), 0)) ?><span style="font-size:13px;font-weight:500;color:#94a3b8;">/hr</span></div>
            </div>
            <?php if ($is_hired): ?>
                <span class="fl-btn fl-btn-hired"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Already Hired</span>
            <?php elseif ($pending_hire): ?>
                <span class="fl-btn" style="background:#fef3c7;color:#d97706;border:2px solid #fde68a;cursor:default;"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>Pending Request</span>
            <?php else: ?>
                <button type="button" onclick="document.getElementById('hireModal').classList.remove('hidden')" class="fl-btn fl-btn-primary"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Hire Now</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ===== MAIN CONTENT ===== -->
<div class="max-w-[1100px] mx-auto px-6 py-8">

    <!-- Stats -->
    <div class="fl-stat-grid grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="fl-stat fl-reveal">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-blue-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div style="font-size:28px;font-weight:800;color:#0f172a;line-height:1;" class="dark:text-white"><?= $completed_count ?></div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;">Projects Done</div>
        </div>
        <div class="fl-stat fl-reveal" style="transition-delay:.1s">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-amber-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"/></svg>
            </div>
            <div style="font-size:28px;font-weight:800;color:#0f172a;line-height:1;" class="dark:text-white"><?= $total_reviews ?></div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;">Reviews</div>
        </div>
        <div class="fl-stat fl-reveal" style="transition-delay:.2s">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-emerald-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div style="font-size:28px;font-weight:800;color:#059669;line-height:1;">$<?= number_format($total_earnings, 0) ?></div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;">Total Earned</div>
        </div>
        <div class="fl-stat fl-reveal" style="transition-delay:.3s">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-br from-purple-500 to-purple-600 flex items-center justify-center mx-auto mb-3 shadow-lg shadow-purple-500/20">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/></svg>
            </div>
            <div style="font-size:28px;font-weight:800;color:#0f172a;line-height:1;" class="dark:text-white"><?= count($fl_skills) ?></div>
            <div style="font-size:12px;color:#64748b;margin-top:4px;font-weight:600;">Skills</div>
        </div>
    </div>

    <!-- Grid: Main + Sidebar -->
    <div class="fl-grid grid gap-6" style="grid-template-columns:1fr 340px;align-items:start;">

        <!-- ===== LEFT COLUMN ===== -->
        <div class="space-y-6">

            <!-- About -->
            <?php if ($freelancer['bio']): ?>
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">About Me</h2>
                    <p style="font-size:14.5px;color:#475569;line-height:1.85;" class="dark:text-gray-300"><?= nl2br(e($freelancer['bio'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Portfolio -->
            <?php if (!empty($portfolio_items)): ?>
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Portfolio <span class="text-sm font-medium text-gray-400 ml-1"><?= count($portfolio_items) ?> projects</span></h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php foreach ($portfolio_items as $item):
                            $cover = $item['cover_image'] ? base_url('uploads/' . $item['cover_image']) : null;
                        ?>
                        <div class="fl-portfolio-card group cursor-pointer" onclick="<?= $cover ? "openModal(this.querySelector('img')?.src)" : '' ?>">
                            <?php if ($cover): ?>
                                <div class="overflow-hidden">
                                    <img src="<?= e($cover) ?>" alt="" class="fl-portfolio-img">
                                </div>
                            <?php else: ?>
                                <div class="fl-portfolio-img" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);display:flex;align-items:center;justify-content:center;">
                                    <svg width="40" height="40" fill="none" viewBox="0 0 24 24" stroke="#93c5fd" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                                </div>
                            <?php endif; ?>
                            <div class="p-4">
                                <p class="text-sm font-bold text-gray-900 dark:text-white mb-1"><?= e($item['title']) ?></p>
                                <?php if ($item['description']): ?><p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2 leading-relaxed mb-2"><?= e($item['description']) ?></p><?php endif; ?>
                                <?php if (!empty($item['skills'])): ?>
                                    <div class="flex flex-wrap gap-1 mb-2">
                                        <?php foreach (array_slice($item['skills'], 0, 3) as $sk): ?><span class="px-2 py-0.5 rounded-md text-[10px] font-semibold bg-blue-50 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400"><?= e($sk) ?></span><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="flex gap-3">
                                    <?php if ($item['project_url']): ?><a href="<?= e($item['project_url']) ?>" target="_blank" class="text-xs font-semibold text-blue-600 hover:text-blue-700" onclick="event.stopPropagation()">Live Demo →</a><?php endif; ?>
                                    <?php if ($item['github_url']): ?><a href="<?= e($item['github_url']) ?>" target="_blank" class="text-xs font-semibold text-gray-400 hover:text-gray-600" onclick="event.stopPropagation()">Source →</a><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Completed Projects -->
            <?php if (!empty($completed_projects)): ?>
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Completed Projects</h2>
                    <?php foreach (array_slice($completed_projects, 0, 5) as $cp): ?>
                    <div class="fl-project">
                        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center flex-shrink-0 shadow-md shadow-blue-500/20">
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white truncate"><?= e($cp['title']) ?></p>
                            <p class="text-xs text-gray-400 mt-0.5"><?= e($cp['company_name']) ?> · <?= date('M Y', strtotime($cp['assigned_at'])) ?></p>
                        </div>
                        <?php if ($cp['amount']): ?><span class="text-sm font-bold text-emerald-600 flex-shrink-0">$<?= number_format((float)$cp['amount'], 0) ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reviews -->
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Client Reviews</h2>
                    <?php if ($total_reviews > 0): ?>
                    <!-- Rating Overview -->
                    <div class="flex gap-7 p-5 bg-gray-50 dark:bg-slate-800/50 rounded-2xl mb-6">
                        <div class="text-center min-w-[100px]">
                            <div style="font-size:52px;font-weight:900;color:#0f172a;line-height:1;" class="dark:text-white"><?= $avg_rating ?></div>
                            <div class="flex gap-0.5 justify-center my-2">
                                <?php for ($s = 1; $s <= 5; $s++): ?><svg class="w-4 h-4" fill="<?= $s <= round($avg_rating) ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?php endfor; ?>
                            </div>
                            <div class="text-xs text-gray-400 font-medium"><?= $total_reviews ?> reviews</div>
                        </div>
                        <div class="flex-1">
                            <?php for ($star = 5; $star >= 1; $star--):
                                $cnt = $rating_dist[$star] ?? 0;
                                $pct = $total_reviews > 0 ? round(($cnt / $total_reviews) * 100) : 0;
                            ?>
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-[11px] font-semibold text-gray-400 w-3 text-right"><?= $star ?></span>
                                <div class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-blue-400 transition-all duration-1000" style="width:0%;" data-width="<?= $pct ?>%"></div>
                                </div>
                                <span class="text-[11px] text-gray-400 w-3"><?= $cnt ?></span>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Review List -->
                    <?php foreach ($reviews as $rv): ?>
                    <div class="fl-review">
                        <div class="flex items-center gap-3 mb-2">
                            <?php if (!empty($rv['reviewer_image'])): ?>
                                <img src="<?= e(base_url('uploads/' . $rv['reviewer_image'])) ?>" alt="" class="w-9 h-9 rounded-full object-cover">
                            <?php else: ?>
                                <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold text-white bg-gradient-to-br from-indigo-500 to-purple-600">C</div>
                            <?php endif; ?>
                            <div>
                                <p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($rv['company_name'] ?? 'Client') ?></p>
                                <p class="text-[11px] text-gray-400"><?= date('M j, Y', strtotime($rv['created_at'])) ?></p>
                            </div>
                        </div>
                        <div class="flex gap-0.5 mb-2">
                            <?php for ($s = 1; $s <= 5; $s++): ?><svg class="w-3.5 h-3.5" fill="<?= $s <= $rv['rating'] ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?php endfor; ?>
                        </div>
                        <?php if ($rv['comment']): ?><p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed"><?= e($rv['comment']) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-center text-gray-400 py-10 text-sm">No reviews yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ===== RIGHT SIDEBAR ===== -->
        <div class="space-y-6">

            <!-- Skills -->
            <?php if (!empty($fl_skills)): ?>
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Skills</h2>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($fl_skills as $sk): ?>
                            <span class="fl-skill-tag"><?= e($sk) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Companies -->
            <?php if (!empty($companies_worked)): ?>
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Companies</h2>
                    <div class="grid grid-cols-3 gap-3">
                        <?php foreach ($companies_worked as $cw): ?>
                        <div class="fl-company">
                            <?php if ($cw['logo']): ?>
                                <img src="<?= e($cw['logo']) ?>" alt="" class="w-12 h-12 rounded-xl object-contain bg-white p-1 shadow-sm mb-2">
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-xl flex items-center justify-center text-lg font-bold text-blue-600 bg-blue-50 dark:bg-blue-900/30 dark:text-blue-400 mb-2"><?= strtoupper(mb_substr($cw['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <span class="text-[11px] font-semibold text-gray-600 dark:text-gray-300 text-center leading-tight"><?= e($cw['name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contact -->
            <div class="fl-card fl-reveal">
                <div class="fl-card-body">
                    <h2 class="fl-section-title">Contact</h2>
                    <div class="fl-contact">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        </div>
                        <div><p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Email</p><p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($freelancer['email']) ?></p></div>
                    </div>
                    <?php if ($freelancer['phone']): ?>
                    <div class="fl-contact">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg>
                        </div>
                        <div><p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Phone</p><p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($freelancer['phone']) ?></p></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($freelancer['location']): ?>
                    <div class="fl-contact">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        </div>
                        <div><p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Location</p><p class="text-sm font-semibold text-gray-900 dark:text-white"><?= e($freelancer['location']) ?></p></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($portfolio_items)): ?>
                    <div class="fl-contact" style="flex-direction:column;align-items:stretch;gap:12px;">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                            </div>
                            <div><p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Portfolios</p><p class="text-sm font-semibold text-gray-900 dark:text-white"><?= count($portfolio_items) ?> project<?= count($portfolio_items) !== 1 ? 's' : '' ?></p></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach (array_slice($portfolio_items, 0, 3) as $pi_item): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300">
                                    <?php if ($pi_item['cover_image']): ?>
                                        <img src="<?= e(base_url('uploads/' . $pi_item['cover_image'])) ?>" alt="" class="w-5 h-5 rounded object-cover">
                                    <?php else: ?>
                                        <svg class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                                    <?php endif; ?>
                                    <?= e($pi_item['title']) ?>
                                </span>
                            <?php endforeach; ?>
                            <?php if (count($portfolio_items) > 3): ?>
                                <span class="inline-flex items-center px-2.5 py-1.5 rounded-lg text-xs font-medium bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">+<?= count($portfolio_items) - 3 ?> more</span>
                            <?php endif; ?>
                        </div>
                        <a href="<?= e(base_url('freelancer/view_portfolio.php?id=' . $fid)) ?>" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-md shadow-indigo-500/25 hover:shadow-lg hover:-translate-y-0.5 transition-all text-center justify-center">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            View Portfolios
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="fl-modal" id="modal" onclick="this.classList.remove('open')">
    <img id="modalImg" src="" alt="">
</div>

<!-- Hire Modal -->
<div id="hireModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="relative w-full max-w-lg max-h-[90dvh] max-h-[90vh] bg-white dark:bg-gray-900 rounded-2xl shadow-2xl flex flex-col overflow-hidden" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="shrink-0 p-6 pb-4 border-b border-gray-100 dark:border-gray-800 bg-white dark:bg-gray-900 rounded-t-2xl">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Hire <?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Send a direct hire request with project details</p>
                </div>
                <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($hire_success): ?>
            <div class="mx-6 mt-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-400 text-sm font-medium">
                <?= e($hire_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($hire_error): ?>
            <div class="mx-6 mt-4 p-3 rounded-xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 text-sm font-medium">
                <?= e($hire_error) ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <?php if (!$is_hired && !$pending_hire): ?>
        <form method="POST" enctype="multipart/form-data" class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0" style="-webkit-overflow-scrolling:touch;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="direct_hire">

            <div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Project Title <span class="text-red-500">*</span></label>
                <input type="text" name="project_title" required maxlength="255" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g. Website Redesign">
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Description <span class="text-red-500">*</span></label>
                <textarea name="project_description" rows="3" required class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none" placeholder="Describe the project requirements..."></textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Budget ($) <span class="text-red-500">*</span></label>
                    <input type="number" name="budget" min="1" step="0.01" required class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0.00">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Deadline</label>
                    <input type="date" name="deadline" min="<?= date('Y-m-d') ?>" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Payment Type <span class="text-red-500">*</span></label>
                <select name="payment_type" id="hirePaymentType" required onchange="toggleMilestones()" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="fixed">Fixed Price</option>
                    <option value="milestone">Milestone-Based</option>
                </select>
            </div>

            <!-- Milestones Section (shown when Milestone-Based is selected) -->
            <div id="milestonesSection" class="hidden">
                <div class="flex items-center justify-between mb-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Milestones <span class="text-red-500">*</span></label>
                    <span class="text-xs text-gray-400">Milestone sum must equal budget</span>
                </div>
                <div id="milestonesContainer" class="space-y-3"></div>
                <div class="flex items-center justify-between mt-3 p-2.5 rounded-xl bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800/30">
                    <span class="text-xs font-semibold text-blue-600 dark:text-blue-400">Milestone Total</span>
                    <span class="text-sm font-bold text-blue-700 dark:text-blue-300" id="milestoneTotal">$0.00</span>
                </div>
                <button type="button" onclick="addMilestone()" class="mt-2 w-full flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold border border-dashed border-blue-300 dark:border-blue-700 text-blue-500 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Add Milestone
                </button>
                <p id="milestoneError" class="text-xs text-red-500 mt-1 hidden"></p>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Notes</label>
                <textarea name="notes" rows="2" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none" placeholder="Additional notes for the freelancer..."></textarea>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1.5 text-gray-700 dark:text-gray-300">Attachment</label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.pdf,.docx,.zip" class="w-full px-4 py-2.5 rounded-xl text-sm border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 dark:file:bg-blue-900/30 dark:file:text-blue-400">
                <p class="text-xs text-gray-400 mt-1">JPG, PNG, PDF, DOCX, ZIP (Max 10MB)</p>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-blue-500 to-blue-600 text-white shadow-lg shadow-blue-500/25 hover:shadow-xl hover:-translate-y-0.5 transition-all">Send Hire Request</button>
            </div>
        </form>
        <?php else: ?>
        <div class="p-6 text-center overflow-y-auto flex-1 min-h-0">
            <p class="text-gray-500 dark:text-gray-400">
                <?php if ($is_hired): ?>
                    You have already hired this freelancer.
                <?php else: ?>
                    A hire request is already pending for this freelancer.
                <?php endif; ?>
            </p>
            <button type="button" onclick="document.getElementById('hireModal').classList.add('hidden')" class="mt-4 px-6 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">Close</button>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
/* Scroll reveal */
var obs=new IntersectionObserver(function(e){e.forEach(function(el){if(el.isIntersecting){el.target.classList.add('visible');el.target.querySelectorAll('[data-width]').forEach(function(f){setTimeout(function(){f.style.width=f.getAttribute('data-width');},200);});obs.unobserve(el.target);}});},{threshold:.1});
document.querySelectorAll('.fl-reveal').forEach(function(c){obs.observe(c);});
setTimeout(function(){document.querySelectorAll('[data-width]').forEach(function(f){if(f.getBoundingClientRect().top<window.innerHeight)f.style.width=f.getAttribute('data-width');});},500);

/* Modal */
function openModal(s){if(!s)return;document.getElementById('modalImg').src=s;document.getElementById('modal').classList.add('open');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.getElementById('modal').classList.remove('open');});

/* Hire Modal - close on backdrop click */
document.getElementById('hireModal').addEventListener('click',function(e){if(e.target===this)this.classList.add('hidden');});

/* Milestone Management */
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
    var html = '<div class="p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700" id="ms_' + n + '">' +
        '<div class="flex items-center justify-between mb-2">' +
            '<span class="text-xs font-bold uppercase tracking-wider text-gray-400">Milestone ' + n + '</span>' +
            '<button type="button" onclick="removeMilestone(' + n + ')" class="text-gray-400 hover:text-red-500 transition-colors">' +
                '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>' +
            '</button>' +
        '</div>' +
        '<div class="space-y-2">' +
            '<input type="text" name="ms_title[]" required maxlength="200" placeholder="Milestone title" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">' +
            '<textarea name="ms_desc[]" rows="2" placeholder="Brief description" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"></textarea>' +
            '<div class="grid grid-cols-2 gap-2">' +
                '<div>' +
                    '<label class="block text-[11px] font-medium text-gray-400 mb-0.5">Amount ($)</label>' +
                    '<input type="number" name="ms_amount[]" min="1" step="0.01" required placeholder="0.00" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 ms-amount" oninput="updateMilestoneTotal()">' +
                '</div>' +
                '<div>' +
                    '<label class="block text-[11px] font-medium text-gray-400 mb-0.5">Deadline</label>' +
                    '<input type="date" name="ms_deadline[]" min="' + today + '" class="w-full px-3 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">' +
                '</div>' +
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
    document.querySelectorAll('.ms-amount').forEach(function(input) {
        total += parseFloat(input.value) || 0;
    });
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

/* Validate milestone total before submit */
document.querySelector('#hireModal form').addEventListener('submit', function(e) {
    if (document.getElementById('hirePaymentType').value === 'milestone') {
        var total = 0;
        document.querySelectorAll('.ms-amount').forEach(function(input) {
            total += parseFloat(input.value) || 0;
        });
        var budget = parseFloat(document.querySelector('input[name="budget"]').value) || 0;
        if (total <= 0) {
            e.preventDefault();
            alert('Please add at least one milestone with an amount.');
            return false;
        }
        if (budget > 0 && Math.abs(total - budget) > 0.01) {
            e.preventDefault();
            alert('Milestone total ($' + total.toFixed(2) + ') must match the project budget ($' + budget.toFixed(2) + ').');
            return false;
        }
        var titles = document.querySelectorAll('input[name="ms_title[]"]');
        for (var i = 0; i < titles.length; i++) {
            if (!titles[i].value.trim()) {
                e.preventDefault();
                alert('Please fill in all milestone titles.');
                titles[i].focus();
                return false;
            }
        }
    }
});
</script>

</body>
</html>
