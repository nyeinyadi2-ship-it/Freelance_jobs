<?php
$page_title = 'Portfolio';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../config/chat.php';

$fid = (int) ($_GET['id'] ?? 0);
if ($fid <= 0) { redirect('auth/login.php'); }

// Fetch freelancer info
$st = $conn->prepare("SELECT f.id, f.full_name, f.title, f.location, f.bio, f.experience_years, f.hourly_rate, f.portfolio_url, u.profile_image, u.username
    FROM freelancers f JOIN users u ON f.user_id = u.id WHERE f.id = ?");
$st->bind_param('i', $fid);
$st->execute();
$freelancer = $st->get_result()->fetch_assoc();
$st->close();
if (!$freelancer) { redirect('auth/login.php'); }

$profileImgUrl = $freelancer['profile_image'] ? base_url('uploads/images/' . $freelancer['profile_image']) : null;

// Fetch portfolio items
$portfolio_items = [];
$st = $conn->prepare("SELECT * FROM portfolio_items WHERE freelancer_id = ? ORDER BY sort_order ASC, id DESC");
$st->bind_param('i', $fid);
$st->execute();
$rr = $st->get_result();
while ($row = $rr->fetch_assoc()) { $portfolio_items[] = $row; }
$st->close();

// Fetch skills and images for each item
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

// Fetch freelancer skills
$fl_skills = [];
$r = $conn->prepare("SELECT s.skill_name FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE fs.freelancer_id = ?");
$r->bind_param('i', $fid); $r->execute();
$sr = $r->get_result();
while ($row = $sr->fetch_assoc()) $fl_skills[] = $row['skill_name'];
$r->close();

// Fetch reviews
$reviews = [];
$r = $conn->prepare("SELECT r.rating, r.comment, r.created_at, c.company_name FROM reviews r LEFT JOIN companies c ON r.company_user_id = c.user_id WHERE r.freelancer_id = ? ORDER BY r.created_at DESC LIMIT 5");
$r->bind_param('i', $fid); $r->execute();
$rr2 = $r->get_result();
while ($row = $rr2->fetch_assoc()) $reviews[] = $row;
$r->close();

$avg_rating = 0;
$total_reviews = count($reviews);
if ($total_reviews > 0) { $sum = 0; foreach ($reviews as $rv) $sum += $rv['rating']; $avg_rating = round($sum / $total_reviews, 1); }

$is_own = false;
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'freelancer') {
    $my_fid = get_freelancer_id($conn, (int) $_SESSION['user_id']);
    if ($my_fid && $my_fid == $fid) $is_own = true;
}
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($freelancer['full_name'] ?? $freelancer['username']) ?> - Portfolio - HireWork</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{poppins:['Poppins','sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        *,*::before,*::after{font-family:'Poppins',system-ui,sans-serif;}
        :root{--gp:linear-gradient(135deg,#4f46e5,#7c3aed);--gs:linear-gradient(135deg,#059669,#10b981);--gw:linear-gradient(135deg,#d97706,#f59e0b);--gi:linear-gradient(135deg,#0284c7,#0ea5e9);--gr:linear-gradient(135deg,#e11d48,#f43f5e);}
        .glass{background:rgba(255,255,255,0.72);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.5);box-shadow:0 8px 32px rgba(99,102,241,0.06);}
        html.dark .glass{background:rgba(30,41,59,0.72);border-color:rgba(255,255,255,0.08);box-shadow:0 8px 32px rgba(0,0,0,0.3);}
        .hover-lift{transition:transform .3s cubic-bezier(.4,0,.2,1),box-shadow .3s ease;}
        .hover-lift:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(79,70,229,0.12);}
        .reveal{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease;}
        .reveal.visible{opacity:1;transform:translateY(0);}
        .portfolio-card{transition:all .3s ease;}
        .portfolio-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(79,70,229,0.12);}
        .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:100;align-items:center;justify-content:center;}
        .modal-overlay.active{display:flex;}
        .modal-overlay img{max-width:90vw;max-height:85vh;border-radius:12px;object-fit:contain;}
        .btn-grad{background:linear-gradient(135deg,#6366f1,#8b5cf6);transition:all .3s ease;position:relative;overflow:hidden;}
        .btn-grad:hover{transform:translateY(-2px);box-shadow:0 12px 35px rgba(99,102,241,0.35);}
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950/30 min-h-screen" style="color:var(--color-text-primary)">

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-6 pb-12">

<!-- Back link -->
<a href="javascript:history.back()" class="inline-flex items-center gap-1.5 text-sm font-medium mb-6 transition-colors" style="color:var(--color-text-muted)">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Back
    <?php if ($is_own): ?> | <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="ml-2 btn-grad px-4 py-1.5 text-xs font-semibold rounded-xl text-white">Manage Portfolio</a><?php endif; ?>
</a>

<!-- Freelancer Header -->
<div class="glass rounded-2xl p-6 mb-8 reveal">
    <div class="flex flex-col sm:flex-row sm:items-center gap-5">
        <?php if ($profileImgUrl): ?>
            <img src="<?= e($profileImgUrl) ?>" alt="" class="w-20 h-20 rounded-2xl object-cover border-2 flex-shrink-0" style="border-color:var(--color-border)">
        <?php else: ?>
            <div class="w-20 h-20 rounded-2xl flex items-center justify-center font-bold text-3xl flex-shrink-0" style="background:linear-gradient(135deg,#6366f1,#a855f7);color:white"><?= strtoupper(mb_substr($freelancer['full_name'] ?? $freelancer['username'], 0, 1)) ?></div>
        <?php endif; ?>
        <div class="flex-1">
            <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($freelancer['full_name'] ?? $freelancer['username']) ?></h1>
            <?php if ($freelancer['title']): ?><p class="text-sm font-medium text-primary-600 mt-0.5"><?= e($freelancer['title']) ?></p><?php endif; ?>
            <div class="flex flex-wrap items-center gap-3 mt-2 text-xs" style="color:var(--color-text-muted)">
                <?php if ($freelancer['location']): ?><span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg><?= e($freelancer['location']) ?></span><?php endif; ?>
                <?php if ($freelancer['experience_years']): ?><span><?= $freelancer['experience_years'] ?> yr<?= $freelancer['experience_years'] > 1 ? 's' : '' ?> exp</span><?php endif; ?>
                <?php if ($freelancer['hourly_rate']): ?><span style="color:#f59e0b;font-weight:600">$<?= number_format((float)$freelancer['hourly_rate'], 2) ?>/hr</span><?php endif; ?>
                <?php if ($total_reviews > 0): ?>
                    <span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg><?= $avg_rating ?> (<?= $total_reviews ?>)</span>
                <?php endif; ?>
            </div>
            <?php if ($freelancer['bio']): ?><p class="text-sm mt-3 leading-relaxed" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($freelancer['bio'], 0, 300, '...')) ?></p><?php endif; ?>
        </div>
    </div>
</div>

<!-- Skills -->
<?php if (!empty($fl_skills)): ?>
<div class="mb-8 reveal">
    <h2 class="text-lg font-bold mb-3" style="color:var(--color-text-primary)">Skills</h2>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($fl_skills as $sk): ?>
            <span class="px-3 py-1.5 rounded-full text-xs font-semibold" style="background:rgba(99,102,241,0.1);color:#6366f1"><?= e($sk) ?></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Portfolio Section -->
<div class="mb-8">
    <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)">Portfolio (<?= count($portfolio_items) ?> project<?= count($portfolio_items) !== 1 ? 's' : '' ?>)</h2>

    <?php if (empty($portfolio_items)): ?>
        <div class="glass rounded-2xl text-center py-12">
            <svg class="w-16 h-16 mx-auto mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
            <p class="text-sm" style="color:var(--color-text-muted)">No portfolio items yet.</p>
        </div>
    <?php else: ?>
        <?php foreach ($portfolio_items as $item):
            $coverUrl = $item['cover_image'] ? base_url('uploads/images/' . $item['cover_image']) : null;
        ?>
        <div class="glass rounded-2xl overflow-hidden mb-6 portfolio-card reveal">
            <!-- Cover Image -->
            <?php if ($coverUrl): ?>
                <div class="relative h-64 sm:h-80 overflow-hidden cursor-pointer" onclick="openModal(this.querySelector('img').src)">
                    <img src="<?= e($coverUrl) ?>" alt="<?= e($item['title']) ?>" class="w-full h-full object-cover hover:scale-105 transition-transform duration-500">
                </div>
            <?php endif; ?>

            <div class="p-6">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($item['title']) ?></h3>
                        <?php if ($item['completion_date']): ?>
                            <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= date('F Y', strtotime($item['completion_date'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($item['project_url']): ?>
                            <a href="<?= e($item['project_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white" style="background:linear-gradient(135deg,#6366f1,#8b5cf6)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                Live Demo
                            </a>
                        <?php endif; ?>
                        <?php if ($item['github_url']): ?>
                            <a href="<?= e($item['github_url']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border" style="border-color:var(--color-border);color:var(--color-text-secondary)">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                                Source Code
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($item['description']): ?>
                    <p class="text-sm mb-4 leading-relaxed" style="color:var(--color-text-secondary)"><?= nl2br(e($item['description'])) ?></p>
                <?php endif; ?>

                <!-- Skills -->
                <?php if (!empty($item['skills'])): ?>
                    <div class="flex flex-wrap gap-1.5 mb-4">
                        <?php foreach ($item['skills'] as $sk): ?>
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold" style="background:rgba(99,102,241,0.1);color:#6366f1"><?= e($sk) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Gallery Thumbnails -->
                <?php if (!empty($item['images'])): ?>
                    <div class="flex gap-2 overflow-x-auto pb-2 scrollbar-thin">
                        <?php foreach ($item['images'] as $img): ?>
                            <img src="<?= e(base_url('uploads/images/' . $img['image_path'])) ?>" alt="" class="w-24 h-18 rounded-lg object-cover cursor-pointer border flex-shrink-0 hover:opacity-80 transition-opacity" style="border-color:var(--color-border)" onclick="openModal(this.src)">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Attachment -->
                <?php if ($item['attachment']): ?>
                    <div class="mt-3 pt-3 border-t" style="border-color:var(--color-border)">
                        <a href="<?= e(attachment_url($item['attachment'])) ?>" target="_blank" class="inline-flex items-center gap-1.5 text-xs font-semibold" style="color:var(--color-text-muted)">
                            <?= attachment_icon($item['attachment_original_name'] ?? $item['attachment']) ?>
                            <?= e($item['attachment_original_name'] ?? 'Download Attachment') ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Reviews -->
<?php if (!empty($reviews)): ?>
<div class="glass rounded-2xl p-6 reveal">
    <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)">Reviews</h2>
    <div class="space-y-4">
        <?php foreach ($reviews as $rv): ?>
        <div class="p-4 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
            <div class="flex items-center gap-2 mb-2">
                <div class="flex items-center gap-0.5">
                    <?php for ($s = 1; $s <= 5; $s++): ?>
                        <svg class="w-3.5 h-3.5 <?= $s <= $rv['rating'] ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <?php endfor; ?>
                </div>
                <span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e($rv['company_name'] ?? 'Client') ?> &middot; <?= date('M j, Y', strtotime($rv['created_at'])) ?></span>
            </div>
            <?php if ($rv['comment']): ?><p class="text-sm" style="color:var(--color-text-secondary)"><?= e($rv['comment']) ?></p><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

</div>

<!-- Image Modal -->
<div class="modal-overlay" id="imageModal" onclick="closeModal()">
    <img id="modalImg" src="" alt="">
</div>

<script>
function openModal(src) {
    document.getElementById('modalImg').src = src;
    document.getElementById('imageModal').classList.add('active');
}
function closeModal() {
    document.getElementById('imageModal').classList.remove('active');
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });
document.querySelectorAll('.reveal').forEach(function(el) { el.classList.add('visible'); });
</script>

</body>
</html>
