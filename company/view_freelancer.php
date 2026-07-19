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

$st = $conn->prepare("SELECT f.id, f.full_name, f.title, f.location, f.bio, f.experience_years, f.hourly_rate, f.portfolio_url, f.phone, u.profile_image, u.username, u.email, u.created_at
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
if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'company') {
    $logged_in_company = get_company_id($conn, (int) $_SESSION['user_id']);
    if ($logged_in_company) {
        $chk = $conn->prepare("SELECT a.id FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.freelancer_id = ?");
        $chk->bind_param('ii', $logged_in_company, $fid);
        $chk->execute();
        $is_hired = $chk->get_result()->num_rows > 0;
        $chk->close();
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
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{poppins:['Poppins','sans-serif']},colors:{brand:{50:'#eff6ff',100:'#dbeafe',200:'#bfdbfe',300:'#93c5fd',400:'#60a5fa',500:'#3b82f6',600:'#2563eb',700:'#1d4ed8',800:'#1e40af',900:'#1e3a8a'}}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;}
        body{font-family:'Inter',system-ui,-apple-system,sans-serif;background:#f0f4f8;margin:0;color:#1e293b;}

        /* ===== HEADER ===== */
        .hero{background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 50%,#3b82f6 100%);position:relative;overflow:hidden;padding:0 0 80px;}
        .hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");}

        /* ===== PROFILE HEADER CARD ===== */
        .profile-hero{max-width:1100px;margin:-60px auto 0;position:relative;z-index:10;padding:0 24px;}
        .profile-card-main{background:#fff;border-radius:20px;box-shadow:0 10px 40px rgba(0,0,0,0.08);display:flex;align-items:flex-end;gap:28px;padding:32px 36px 28px;}
        .profile-avatar{width:120px;height:120px;border-radius:50%;object-fit:cover;border:4px solid #fff;box-shadow:0 8px 24px rgba(0,0,0,0.12);flex-shrink:0;margin-top:-60px;}
        .profile-avatar-placeholder{width:120px;height:120px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:#fff;background:linear-gradient(135deg,#3b82f6,#8b5cf6);border:4px solid #fff;box-shadow:0 8px 24px rgba(0,0,0,0.12);flex-shrink:0;margin-top:-60px;}
        .profile-head-info{flex:1;min-width:0;padding-bottom:4px;}
        .profile-head-name{font-size:26px;font-weight:800;color:#0f172a;margin:0 0 2px;}
        .profile-head-title{font-size:15px;font-weight:500;color:#3b82f6;margin-bottom:10px;}
        .profile-head-meta{display:flex;flex-wrap:wrap;gap:16px;font-size:13px;color:#64748b;}
        .profile-head-meta span{display:inline-flex;align-items:center;gap:5px;}
        .profile-head-meta svg{width:14px;height:14px;flex-shrink:0;}
        .profile-head-actions{display:flex;gap:10px;align-items:center;padding-bottom:4px;}

        /* ===== BUTTONS ===== */
        .btn-primary{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:12px;font-size:13px;font-weight:700;background:linear-gradient(135deg,#3b82f6,#2563eb);color:#fff;border:none;cursor:pointer;text-decoration:none;box-shadow:0 4px 14px rgba(37,99,235,0.3);transition:all .25s;}
        .btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(37,99,235,0.4);}
        .btn-outline{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:12px;font-size:13px;font-weight:700;background:#fff;color:#3b82f6;border:2px solid #dbeafe;cursor:pointer;text-decoration:none;transition:all .25s;}
        .btn-outline:hover{background:#eff6ff;border-color:#93c5fd;}
        .btn-hired{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border-radius:12px;font-size:13px;font-weight:700;background:#ecfdf5;color:#059669;border:2px solid #d1fae5;cursor:default;}

        /* ===== LAYOUT ===== */
        .page-wrap{max-width:1100px;margin:0 auto;padding:24px;}
        .main-grid{display:grid;grid-template-columns:1fr 340px;gap:24px;align-items:start;}

        /* ===== CARDS ===== */
        .card{background:#fff;border-radius:16px;box-shadow:0 1px 3px rgba(0,0,0,0.04);overflow:hidden;}
        .card-body{padding:24px 28px;}
        .card-title{font-size:16px;font-weight:700;color:#0f172a;margin:0 0 16px;display:flex;align-items:center;gap:8px;}
        .card-title::before{content:'';width:4px;height:18px;border-radius:2px;background:linear-gradient(180deg,#3b82f6,#60a5fa);flex-shrink:0;}

        /* ===== STATS ROW ===== */
        .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
        .stat-item{text-align:center;padding:20px 12px;background:#fff;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,0.04);}
        .stat-value{font-size:28px;font-weight:800;color:#0f172a;line-height:1;}
        .stat-label{font-size:12px;color:#64748b;margin-top:4px;font-weight:500;}

        /* ===== ABOUT ===== */
        .about-text{font-size:14px;color:#475569;line-height:1.8;}

        /* ===== SKILLS ===== */
        .skill-item{margin-bottom:16px;}
        .skill-item:last-child{margin-bottom:0;}
        .skill-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;}
        .skill-name{font-size:13px;font-weight:600;color:#1e293b;}
        .skill-pct{font-size:12px;font-weight:700;color:#3b82f6;}
        .skill-bar{height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
        .skill-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#3b82f6,#60a5fa);transition:width 1.2s cubic-bezier(.4,0,.2,1);}

        /* ===== PORTFOLIO ===== */
        .portfolio-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
        .portfolio-card{border-radius:14px;overflow:hidden;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.04);transition:all .3s;}
        .portfolio-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,0.08);}
        .portfolio-img{width:100%;height:160px;object-fit:cover;}
        .portfolio-body{padding:14px 16px;}
        .portfolio-name{font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px;}
        .portfolio-desc{font-size:12px;color:#64748b;line-height:1.5;margin-bottom:10px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
        .portfolio-tech{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px;}
        .tech-tag{padding:3px 8px;border-radius:6px;font-size:10px;font-weight:600;background:#eff6ff;color:#3b82f6;}
        .portfolio-links{display:flex;gap:10px;}
        .portfolio-links a{font-size:11px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:4px;}
        .portfolio-links a.link-primary{color:#3b82f6;}
        .portfolio-links a.link-muted{color:#94a3b8;}

        /* ===== COMPANIES ===== */
        .companies-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:14px;}
        .company-item{display:flex;flex-direction:column;align-items:center;padding:18px 8px;border-radius:14px;background:#f8fafc;transition:all .3s;}
        .company-item:hover{background:#eff6ff;transform:translateY(-2px);}
        .company-logo{width:48px;height:48px;border-radius:12px;object-fit:contain;background:#fff;padding:4px;box-shadow:0 1px 4px rgba(0,0,0,0.04);margin-bottom:8px;}
        .company-logo-ph{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:800;color:#3b82f6;background:#eff6ff;margin-bottom:8px;}
        .company-label{font-size:11px;font-weight:600;color:#334155;text-align:center;line-height:1.3;}

        /* ===== PROJECTS ===== */
        .project-item{display:flex;align-items:center;gap:14px;padding:14px 0;}
        .project-item+.project-item{border-top:1px solid #f1f5f9;}
        .project-icon-box{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#3b82f6,#60a5fa);flex-shrink:0;}
        .project-icon-box svg{width:18px;height:18px;color:#fff;}
        .project-info{flex:1;min-width:0;}
        .project-title{font-size:14px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .project-meta{font-size:12px;color:#94a3b8;margin-top:1px;}
        .project-amount{font-size:14px;font-weight:700;color:#059669;flex-shrink:0;}

        /* ===== REVIEWS ===== */
        .rating-overview{display:flex;gap:28px;padding:24px;background:#f8fafc;border-radius:14px;margin-bottom:20px;}
        .rating-big{text-align:center;min-width:90px;}
        .rating-num{font-size:48px;font-weight:900;color:#0f172a;line-height:1;}
        .rating-stars{display:flex;gap:2px;justify-content:center;margin:6px 0 4px;}
        .rating-count{font-size:12px;color:#94a3b8;}
        .rating-bars{flex:1;}
        .bar-row{display:flex;align-items:center;gap:8px;margin-bottom:4px;}
        .bar-row:last-child{margin-bottom:0;}
        .bar-num{font-size:11px;font-weight:600;color:#94a3b8;width:10px;text-align:right;}
        .bar-track{flex:1;height:7px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
        .bar-fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#3b82f6,#60a5fa);}
        .bar-cnt{font-size:11px;color:#94a3b8;width:14px;}
        .review-item{padding:16px 0;}
        .review-item+.review-item{border-top:1px solid #f1f5f9;}
        .review-head{display:flex;align-items:center;gap:10px;margin-bottom:8px;}
        .review-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover;}
        .review-avatar-ph{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6);}
        .review-author{font-size:13px;font-weight:600;color:#0f172a;}
        .review-date{font-size:11px;color:#94a3b8;}
        .review-stars{display:flex;gap:1px;margin-bottom:6px;}
        .review-text{font-size:13px;color:#475569;line-height:1.7;}

        /* ===== CONTACT ===== */
        .contact-item{display:flex;align-items:center;gap:12px;padding:12px 0;}
        .contact-item+.contact-item{border-top:1px solid #f1f5f9;}
        .contact-ico{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#eff6ff;flex-shrink:0;}
        .contact-ico svg{width:16px;height:16px;color:#3b82f6;}
        .contact-detail .cl{font-size:10px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;}
        .contact-detail .cv{font-size:13px;font-weight:600;color:#0f172a;}
        .contact-detail .cv a{color:#3b82f6;text-decoration:none;}
        .contact-detail .cv a:hover{text-decoration:underline;}

        /* ===== MODAL ===== */
        .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:100;align-items:center;justify-content:center;backdrop-filter:blur(4px);}
        .modal-bg.open{display:flex;}
        .modal-bg img{max-width:92vw;max-height:88vh;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.4);}

        /* ===== RESPONSIVE ===== */
        @media(max-width:900px){
            .main-grid{grid-template-columns:1fr;}
            .profile-card-main{flex-direction:column;align-items:center;text-align:center;padding:28px 24px 24px;}
            .profile-avatar,.profile-avatar-placeholder{margin-top:-50px;}
            .profile-head-meta{justify-content:center;}
            .profile-head-actions{justify-content:center;}
            .stats-row{grid-template-columns:repeat(2,1fr);}
            .portfolio-grid{grid-template-columns:1fr;}
            .companies-grid{grid-template-columns:repeat(3,1fr);}
            .rating-overview{flex-direction:column;gap:16px;}
        }
        @media(max-width:600px){
            .hero{padding:0 0 60px;}
            .profile-hero{padding:0 16px;}
            .page-wrap{padding:16px;}
            .card-body{padding:20px;}
            .stats-row{grid-template-columns:1fr 1fr;gap:10px;}
            .companies-grid{grid-template-columns:repeat(2,1fr);}
            .profile-head-name{font-size:22px;}
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../includes/navbar.php'; ?>

<!-- Hero Banner -->
<div class="hero" style="padding-top:64px;">
    <div style="height:120px;"></div>
</div>

<!-- Profile Header Card -->
<div class="profile-hero">
    <div class="profile-card-main">
        <?php if ($profileImgUrl): ?>
            <img src="<?= e($profileImgUrl) ?>" alt="" class="profile-avatar">
        <?php else: ?>
            <div class="profile-avatar-placeholder"><?= strtoupper(mb_substr($freelancer['full_name'] ?? $freelancer['username'], 0, 1)) ?></div>
        <?php endif; ?>

        <div class="profile-head-info">
            <h1 class="profile-head-name"><?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h1>
            <p class="profile-head-title"><?= e($freelancer['title'] ?? 'Freelancer') ?></p>
            <div class="profile-head-meta">
                <?php if ($freelancer['location']): ?>
                    <span><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg><?= e($freelancer['location']) ?></span>
                <?php endif; ?>
                <?php if ($total_reviews > 0): ?>
                    <span>
                        <svg fill="#f59e0b" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?= $avg_rating ?> (<?= $total_reviews ?>)
                    </span>
                <?php endif; ?>
                <span><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>Member since <?= date('M Y', strtotime($freelancer['created_at'])) ?></span>
                <?php if ($freelancer['experience_years']): ?>
                    <span><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><?= $freelancer['experience_years'] ?> yr<?= $freelancer['experience_years'] > 1 ? 's' : '' ?> exp</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-head-actions">
            <div style="text-align:right;margin-right:8px;">
                <div style="font-size:11px;color:#94a3b8;font-weight:500;">Hourly Rate</div>
                <div style="font-size:24px;font-weight:800;color:#0f172a;">$<?= e(number_format((float)($freelancer['hourly_rate'] ?? 0), 0)) ?><span style="font-size:13px;font-weight:500;color:#94a3b8;">/hr</span></div>
            </div>
            <?php if ($is_hired): ?>
                <span class="btn-hired"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Already Hired</span>
            <?php else: ?>
                <a href="<?= e(base_url('register.php')) ?>" class="btn-primary"><svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Hire Now</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="page-wrap">

    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-item"><div class="stat-value"><?= $completed_count ?></div><div class="stat-label">Projects Done</div></div>
        <div class="stat-item"><div class="stat-value"><?= $total_reviews ?></div><div class="stat-label">Reviews</div></div>
        <div class="stat-item"><div class="stat-value" style="color:#059669;">$<?= number_format($total_earnings, 0) ?></div><div class="stat-label">Earned</div></div>
        <div class="stat-item"><div class="stat-value"><?= count($fl_skills) ?></div><div class="stat-label">Skills</div></div>
    </div>

    <div class="main-grid">
        <!-- LEFT COLUMN -->
        <div>

            <!-- About -->
            <?php if ($freelancer['bio']): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 class="card-title">About Me</h2>
                    <p class="about-text"><?= nl2br(e($freelancer['bio'])) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Portfolio -->
            <?php if (!empty($portfolio_items)): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 class="card-title">Portfolio <span style="font-size:12px;font-weight:500;color:#94a3b8;margin-left:4px;"><?= count($portfolio_items) ?> projects</span></h2>
                    <div class="portfolio-grid">
                        <?php foreach ($portfolio_items as $item):
                            $cover = $item['cover_image'] ? base_url('uploads/' . $item['cover_image']) : null;
                        ?>
                        <div class="portfolio-card" onclick="<?= $cover ? "openModal(this.querySelector('img')?.src)" : '' ?>">
                            <?php if ($cover): ?>
                                <img src="<?= e($cover) ?>" alt="" class="portfolio-img">
                            <?php else: ?>
                                <div class="portfolio-img" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);display:flex;align-items:center;justify-content:center;">
                                    <svg width="36" height="36" fill="none" viewBox="0 0 24 24" stroke="#93c5fd" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                                </div>
                            <?php endif; ?>
                            <div class="portfolio-body">
                                <p class="portfolio-name"><?= e($item['title']) ?></p>
                                <?php if ($item['description']): ?><p class="portfolio-desc"><?= e($item['description']) ?></p><?php endif; ?>
                                <?php if (!empty($item['skills'])): ?>
                                    <div class="portfolio-tech">
                                        <?php foreach (array_slice($item['skills'], 0, 3) as $sk): ?><span class="tech-tag"><?= e($sk) ?></span><?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="portfolio-links">
                                    <?php if ($item['project_url']): ?><a href="<?= e($item['project_url']) ?>" target="_blank" class="link-primary" onclick="event.stopPropagation()">Live Demo →</a><?php endif; ?>
                                    <?php if ($item['github_url']): ?><a href="<?= e($item['github_url']) ?>" target="_blank" class="link-muted" onclick="event.stopPropagation()">Source →</a><?php endif; ?>
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
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 class="card-title">Completed Projects</h2>
                    <?php foreach (array_slice($completed_projects, 0, 5) as $cp): ?>
                    <div class="project-item">
                        <div class="project-icon-box"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
                        <div class="project-info">
                            <p class="project-title"><?= e($cp['title']) ?></p>
                            <p class="project-meta"><?= e($cp['company_name']) ?> · <?= date('M Y', strtotime($cp['assigned_at'])) ?></p>
                        </div>
                        <?php if ($cp['amount']): ?><span class="project-amount">$<?= number_format((float)$cp['amount'], 0) ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reviews -->
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">Client Reviews</h2>
                    <?php if ($total_reviews > 0): ?>
                    <div class="rating-overview">
                        <div class="rating-big">
                            <div class="rating-num"><?= $avg_rating ?></div>
                            <div class="rating-stars">
                                <?php for ($s = 1; $s <= 5; $s++): ?><svg width="16" height="16" fill="<?= $s <= $avg_rating ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?php endfor; ?>
                            </div>
                            <div class="rating-count"><?= $total_reviews ?> reviews</div>
                        </div>
                        <div class="rating-bars">
                            <?php for ($star = 5; $star >= 1; $star--):
                                $cnt = $rating_dist[$star] ?? 0;
                                $pct = $total_reviews > 0 ? round(($cnt / $total_reviews) * 100) : 0;
                            ?>
                            <div class="bar-row">
                                <span class="bar-num"><?= $star ?></span>
                                <div class="bar-track"><div class="bar-fill" style="width:0%;" data-width="<?= $pct ?>%"></div></div>
                                <span class="bar-cnt"><?= $cnt ?></span>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php foreach ($reviews as $rv): ?>
                    <div class="review-item">
                        <div class="review-head">
                            <?php if (!empty($rv['reviewer_image'])): ?>
                                <img src="<?= e(base_url('uploads/' . $rv['reviewer_image'])) ?>" alt="" class="review-avatar">
                            <?php else: ?>
                                <div class="review-avatar-ph">C</div>
                            <?php endif; ?>
                            <div>
                                <p class="review-author"><?= e($rv['company_name'] ?? 'Client') ?></p>
                                <p class="review-date"><?= date('M j, Y', strtotime($rv['created_at'])) ?></p>
                            </div>
                        </div>
                        <div class="review-stars">
                            <?php for ($s = 1; $s <= 5; $s++): ?><svg width="13" height="13" fill="<?= $s <= $rv['rating'] ? '#f59e0b' : '#e2e8f0' ?>" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?php endfor; ?>
                        </div>
                        <?php if ($rv['comment']): ?><p class="review-text"><?= e($rv['comment']) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                        <p style="text-align:center;color:#94a3b8;padding:20px 0;">No reviews yet.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT SIDEBAR -->
        <div>
            <!-- Skills -->
            <?php if (!empty($fl_skills)): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 class="card-title">Skills</h2>
                    <?php foreach ($fl_skills as $i => $sk):
                        $prof = max(50, 98 - ($i * 7));
                    ?>
                    <div class="skill-item">
                        <div class="skill-top">
                            <span class="skill-name"><?= e($sk) ?></span>
                            <span class="skill-pct"><?= $prof ?>%</span>
                        </div>
                        <div class="skill-bar"><div class="skill-fill" style="width:0%;" data-width="<?= $prof ?>%"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Companies -->
            <?php if (!empty($companies_worked)): ?>
            <div class="card" style="margin-bottom:20px;">
                <div class="card-body">
                    <h2 class="card-title">Companies</h2>
                    <div class="companies-grid">
                        <?php foreach ($companies_worked as $cw): ?>
                        <div class="company-item">
                            <?php if ($cw['logo']): ?>
                                <img src="<?= e($cw['logo']) ?>" alt="" class="company-logo">
                            <?php else: ?>
                                <div class="company-logo-ph"><?= strtoupper(mb_substr($cw['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <span class="company-label"><?= e($cw['name']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Contact -->
            <div class="card">
                <div class="card-body">
                    <h2 class="card-title">Contact</h2>
                    <div class="contact-item">
                        <div class="contact-ico"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg></div>
                        <div class="contact-detail"><p class="cl">Email</p><p class="cv"><?= e($freelancer['email']) ?></p></div>
                    </div>
                    <?php if ($freelancer['phone']): ?>
                    <div class="contact-item">
                        <div class="contact-ico"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z"/></svg></div>
                        <div class="contact-detail"><p class="cl">Phone</p><p class="cv"><?= e($freelancer['phone']) ?></p></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($freelancer['location']): ?>
                    <div class="contact-item">
                        <div class="contact-ico"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg></div>
                        <div class="contact-detail"><p class="cl">Location</p><p class="cv"><?= e($freelancer['location']) ?></p></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($freelancer['portfolio_url']): ?>
                    <div class="contact-item">
                        <div class="contact-ico"><svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg></div>
                        <div class="contact-detail"><p class="cl">Website</p><p class="cv"><a href="<?= e($freelancer['portfolio_url']) ?>" target="_blank"><?= e($freelancer['portfolio_url']) ?></a></p></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal-bg" id="modal" onclick="this.classList.remove('open')">
    <img id="modalImg" src="" alt="">
</div>

<script>
var obs=new IntersectionObserver(function(e){e.forEach(function(el){if(el.isIntersecting){el.target.querySelectorAll('[data-width]').forEach(function(f){setTimeout(function(){f.style.width=f.getAttribute('data-width');},150);});obs.unobserve(el.target);}});},{threshold:.15});
document.querySelectorAll('.card').forEach(function(c){obs.observe(c);});
setTimeout(function(){document.querySelectorAll('[data-width]').forEach(function(f){if(f.getBoundingClientRect().top<window.innerHeight)f.style.width=f.getAttribute('data-width');});},400);
function openModal(s){if(!s)return;document.getElementById('modalImg').src=s;document.getElementById('modal').classList.add('open');}
document.addEventListener('keydown',function(e){if(e.key==='Escape')document.getElementById('modal').classList.remove('open');});
</script>

</body>
</html>
