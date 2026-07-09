<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

// Fetch stats
$stats = [
    'freelancers' => 0,
    'companies' => 0,
    'jobs' => 0,
    'completed' => 0,
];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'freelancer'");
$stats['freelancers'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'company'");
$stats['companies'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'approved'");
$stats['jobs'] = (int) $r->fetch_assoc()['cnt'];
$r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'completed'");
$stats['completed'] = (int) $r->fetch_assoc()['cnt'];

// Fetch latest jobs
$latest_jobs = [];
$r = $conn->query("
    SELECT j.id, j.title, j.budget, j.created_at, c.company_name, c.logo_image,
           (SELECT GROUP_CONCAT(s.skill_name) FROM freelancer_skills fs JOIN skills s ON fs.skill_id = s.id WHERE fs.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = c.user_id) LIMIT 3) AS skills
    FROM jobs j
    JOIN companies c ON j.company_id = c.id
    WHERE j.status = 'approved'
    ORDER BY j.created_at DESC
    LIMIT 6
");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $latest_jobs[] = $row;
    }
}

// Fetch skills for categories
$skills_list = [];
$r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $skills_list[] = $row;
    }
}

$page_title = __('app.tagline');
require __DIR__ . '/includes/header.php';
?>

<!-- ===== HERO SECTION ===== -->
<section class="hero-gradient -mx-4 -mt-8 px-4 pt-20 pb-24 md:pt-28 md:pb-32 text-white relative">
    <div class="max-w-6xl mx-auto relative z-10">
        <div class="text-center max-w-3xl mx-auto">
            <div class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm rounded-full px-4 py-1.5 text-sm mb-6 fade-up">
                <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
                <?= e(__('home.trusted_platform')) ?>
            </div>
            <h1 class="text-4xl md:text-6xl font-extrabold mb-6 leading-tight fade-up delay-1">
                <?= e(__('home.hero_title')) ?>
                <span class="block text-yellow-300"><?= e(__('home.hero_highlight')) ?></span>
            </h1>
            <p class="text-lg md:text-xl text-indigo-100 mb-10 fade-up delay-2">
                <?= e(__('home.hero_subtitle')) ?>
            </p>

            <!-- Search Bar -->
            <div class="bg-white rounded-2xl p-2 shadow-2xl max-w-2xl mx-auto flex items-center gap-2 fade-up delay-3">
                <div class="flex-1 flex items-center gap-2 px-4">
                    <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" id="hero-search" placeholder="<?= e(__('home.search_placeholder')) ?>" class="w-full py-3 text-gray-800 placeholder-gray-400 focus:outline-none text-sm">
                </div>
                <a href="<?= e(base_url('register.php')) ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-xl font-semibold text-sm whitespace-nowrap transition-colors btn-shine">
                    <?= e(__('home.find_talent')) ?>
                </a>
            </div>

            <!-- Quick Tags -->
            <div class="flex flex-wrap justify-center gap-2 mt-6 fade-up delay-4">
                <?php
                $quick_tags = ['PHP', 'JavaScript', 'MySQL', 'UI/UX', 'Writing'];
                foreach ($quick_tags as $tag):
                ?>
                    <span class="bg-white/10 backdrop-blur-sm text-white/90 text-xs px-3 py-1 rounded-full hover:bg-white/20 transition-colors cursor-pointer"><?= e($tag) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-16 fade-up delay-4">
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-5 text-center border border-white/10">
                <p class="text-3xl font-extrabold counter-num" data-target="<?= $stats['freelancers'] ?>">0</p>
                <p class="text-indigo-200 text-sm mt-1"><?= e(__('home.stat_freelancers')) ?></p>
            </div>
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-5 text-center border border-white/10">
                <p class="text-3xl font-extrabold counter-num" data-target="<?= $stats['companies'] ?>">0</p>
                <p class="text-indigo-200 text-sm mt-1"><?= e(__('home.stat_companies')) ?></p>
            </div>
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-5 text-center border border-white/10">
                <p class="text-3xl font-extrabold counter-num" data-target="<?= $stats['jobs'] ?>">0</p>
                <p class="text-indigo-200 text-sm mt-1"><?= e(__('home.stat_jobs')) ?></p>
            </div>
            <div class="bg-white/10 backdrop-blur-sm rounded-xl p-5 text-center border border-white/10">
                <p class="text-3xl font-extrabold counter-num" data-target="<?= $stats['completed'] ?>">0</p>
                <p class="text-indigo-200 text-sm mt-1"><?= e(__('home.stat_completed')) ?></p>
            </div>
        </div>
    </div>

    <!-- Decorative shapes -->
    <div class="absolute top-20 left-10 w-20 h-20 bg-white/5 rounded-full float-anim hidden md:block"></div>
    <div class="absolute bottom-20 right-10 w-32 h-32 bg-white/5 rounded-2xl float-anim-delay hidden md:block"></div>
    <div class="absolute top-40 right-20 w-16 h-16 bg-yellow-400/10 rounded-xl float-anim-delay hidden md:block"></div>
</section>

<!-- ===== HOW IT WORKS ===== -->
<section class="py-20">
    <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-16 fade-up">
            <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.how_it_works')) ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.how_title')) ?></h2>
            <p class="mt-4 max-w-xl mx-auto" style="color:var(--color-text-muted)"><?= e(__('home.how_subtitle')) ?></p>
        </div>

        <div class="grid md:grid-cols-3 gap-8 relative">
            <!-- Step 1 -->
            <div class="text-center fade-up delay-1">
                <div class="w-16 h-16 bg-indigo-100 dark:bg-indigo-900/30 rounded-2xl flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <div class="w-8 h-8 rounded-full bg-indigo-600 text-white text-sm font-bold flex items-center justify-center mx-auto mb-4">1</div>
                <h3 class="text-xl font-bold mb-3" style="color:var(--color-text-primary)"><?= e(__('home.step1_title')) ?></h3>
                <p style="color:var(--color-text-muted)"><?= e(__('home.step1_desc')) ?></p>
            </div>

            <!-- Connector (desktop) -->
            <div class="hidden md:block absolute top-10 left-1/3 w-1/3 h-0.5 bg-gradient-to-r from-indigo-300 to-purple-300 dark:from-indigo-700 dark:to-purple-700" style="z-index:0"></div>

            <!-- Step 2 -->
            <div class="text-center fade-up delay-2">
                <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900/30 rounded-2xl flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <div class="w-8 h-8 rounded-full bg-purple-600 text-white text-sm font-bold flex items-center justify-center mx-auto mb-4">2</div>
                <h3 class="text-xl font-bold mb-3" style="color:var(--color-text-primary)"><?= e(__('home.step2_title')) ?></h3>
                <p style="color:var(--color-text-muted)"><?= e(__('home.step2_desc')) ?></p>
            </div>

            <!-- Step 3 -->
            <div class="text-center fade-up delay-3">
                <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-900/30 rounded-2xl flex items-center justify-center mx-auto mb-5">
                    <svg class="w-8 h-8 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="w-8 h-8 rounded-full bg-emerald-600 text-white text-sm font-bold flex items-center justify-center mx-auto mb-4">3</div>
                <h3 class="text-xl font-bold mb-3" style="color:var(--color-text-primary)"><?= e(__('home.step3_title')) ?></h3>
                <p style="color:var(--color-text-muted)"><?= e(__('home.step3_desc')) ?></p>
            </div>
        </div>
    </div>
</section>

<!-- ===== FEATURED CATEGORIES ===== -->
<section class="py-20" style="background:var(--color-card)">
    <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-16 fade-up">
            <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.categories')) ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.categories_title')) ?></h2>
            <p class="mt-4 max-w-xl mx-auto" style="color:var(--color-text-muted)"><?= e(__('home.categories_subtitle')) ?></p>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-5">
            <?php
            $category_icons = [
                'PHP' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>',
                'JavaScript' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
                'MySQL' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>',
                'UI/UX' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>',
                'Writing' => '<path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
            ];
            $category_colors = [
                'PHP' => 'from-blue-500 to-indigo-600',
                'JavaScript' => 'from-yellow-400 to-orange-500',
                'MySQL' => 'from-cyan-500 to-blue-600',
                'UI/UX' => 'from-pink-500 to-rose-600',
                'Writing' => 'from-green-500 to-emerald-600',
            ];

            $shown = 0;
            foreach ($skills_list as $skill):
                if ($shown >= 5) break;
                $shown++;
                $icon = $category_icons[$skill['skill_name']] ?? '<path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/>';
                $color = $category_colors[$skill['skill_name']] ?? 'from-indigo-500 to-purple-600';
                // Count jobs with this skill (approximate)
                $cnt_r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs j JOIN companies c ON j.company_id = c.id JOIN freelancer_skills fs ON fs.freelancer_id IN (SELECT id FROM freelancers WHERE user_id = c.user_id) JOIN skills s ON fs.skill_id = s.id WHERE s.skill_name = '" . $conn->real_escape_string($skill['skill_name']) . "' AND j.status = 'approved'");
                $job_cnt = $cnt_r ? (int) $cnt_r->fetch_assoc()['cnt'] : 0;
            ?>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="category-card bg-gradient-to-br <?= $color ?> rounded-2xl p-6 text-white text-center group cursor-pointer">
                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center mx-auto mb-4 group-hover:scale-110 transition-transform">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $icon ?></svg>
                    </div>
                    <h3 class="font-bold text-lg mb-1"><?= e($skill['skill_name']) ?></h3>
                    <p class="text-white/70 text-sm"><?= $job_cnt ?>+ <?= e(__('home.open_jobs')) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== LATEST JOBS ===== -->
<section class="py-20">
    <div class="max-w-6xl mx-auto px-4">
        <div class="flex flex-wrap items-center justify-between mb-12 fade-up">
            <div>
                <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.latest_jobs')) ?></span>
                <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.latest_jobs_title')) ?></h2>
            </div>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary mt-4 md:mt-0">
                <?= e(__('home.view_all_jobs')) ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>

        <?php if (empty($latest_jobs)): ?>
            <div class="text-center py-12" style="color:var(--color-text-muted)">
                <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <p><?= e(__('home.no_jobs_yet')) ?></p>
            </div>
        <?php else: ?>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($latest_jobs as $job): ?>
                    <div class="job-card card group cursor-pointer" onclick="window.location='<?= e(base_url('freelancer/browse_jobs.php')) ?>'">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <?php if ($job['logo_image']): ?>
                                    <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-lg object-cover">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold text-sm">
                                        <?= e(_first_char($job['company_name'] ?? 'C')) ?>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <p class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= e($job['company_name'] ?? 'Company') ?></p>
                                    <p class="text-xs" style="color:var(--color-text-muted)"><?= e(__('home.posted_ago')) ?></p>
                                </div>
                            </div>
                            <?= status_badge('approved') ?>
                        </div>
                        <h3 class="font-bold text-lg mb-2 group-hover:text-indigo-600 transition-colors" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h3>
                        <p class="text-sm mb-4 line-clamp-2" style="color:var(--color-text-muted)"><?= e(mb_strimwidth($job['description'] ?? '', 0, 100, '...')) ?></p>
                        <div class="flex items-center justify-between pt-4" style="border-top:1px solid var(--color-border)">
                            <span class="text-indigo-600 font-bold">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
                            <span class="text-xs" style="color:var(--color-text-muted)"><?= e($job['created_at']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ===== WHY CHOOSE US ===== -->
<section class="py-20" style="background:var(--color-card)">
    <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-16 fade-up">
            <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.why_us')) ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.why_title')) ?></h2>
            <p class="mt-4 max-w-xl mx-auto" style="color:var(--color-text-muted)"><?= e(__('home.why_subtitle')) ?></p>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php
            $features = [
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>', 'title' => 'Secure & Trusted', 'desc' => 'All accounts verified with secure authentication. Your data is protected with industry-standard encryption.', 'color' => 'indigo'],
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>', 'title' => 'Fast & Efficient', 'desc' => 'Post jobs and find talent in minutes. Our streamlined process gets you working faster.', 'color' => 'purple'],
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'title' => 'Secure Payments', 'desc' => 'Escrow-based payment system ensures freelancers get paid and companies get quality work.', 'color' => 'emerald'],
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>', 'title' => 'Built-in Messaging', 'desc' => 'Communicate directly with your team. No need for external tools.', 'color' => 'blue'],
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>', 'title' => 'Job Management', 'desc' => 'Track proposals, assignments, and payments all in one place.', 'color' => 'amber'],
                ['icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>', 'title' => 'Multi-language', 'desc' => 'Available in English and Myanmar. Use the platform in your preferred language.', 'color' => 'teal'],
            ];
            foreach ($features as $i => $f):
            ?>
                <div class="fade-up delay-<?= ($i % 3) + 1 ?>">
                    <div class="card h-full hover:border-<?= $f['color'] ?>-300 dark:hover:border-<?= $f['color'] ?>-700 transition-colors">
                        <div class="w-12 h-12 bg-<?= $f['color'] ?>-100 dark:bg-<?= $f['color'] ?>-900/30 rounded-xl flex items-center justify-center mb-4">
                            <svg class="w-6 h-6 text-<?= $f['color'] ?>-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><?= $f['icon'] ?></svg>
                        </div>
                        <h3 class="text-lg font-bold mb-2" style="color:var(--color-text-primary)"><?= e($f['title']) ?></h3>
                        <p class="text-sm" style="color:var(--color-text-muted)"><?= e($f['desc']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== TESTIMONIALS ===== -->
<section class="py-20">
    <div class="max-w-6xl mx-auto px-4">
        <div class="text-center mb-16 fade-up">
            <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.testimonials')) ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.testimonials_title')) ?></h2>
        </div>

        <div class="grid md:grid-cols-3 gap-8">
            <?php
            $testimonials = [
                ['name' => 'Sarah Chen', 'role' => 'Startup Founder', 'avatar' => 'S', 'text' => 'FreelanceHub helped us find an amazing developer in just 2 days. The platform is intuitive and the payment system gives us peace of mind.', 'stars' => 5, 'color' => 'indigo'],
                ['name' => 'David Park', 'role' => 'Full-Stack Developer', 'avatar' => 'D', 'text' => 'I\'ve earned over $15,000 through this platform. The job matching is great and I love the built-in messaging feature.', 'stars' => 5, 'color' => 'purple'],
                ['name' => 'Emily Rodriguez', 'role' => 'Marketing Agency', 'avatar' => 'E', 'text' => 'We\'ve hired 12 freelancers through FreelanceHub. The quality of talent is outstanding and the admin team keeps everything running smoothly.', 'stars' => 5, 'color' => 'emerald'],
            ];
            foreach ($testimonials as $i => $t):
            ?>
                <div class="testimonial-card card fade-up delay-<?= ($i % 3) + 1 ?>">
                    <div class="flex items-center gap-1 mb-4">
                        <?php for ($s = 0; $s < $t['stars']; $s++): ?>
                            <svg class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                        <?php endfor; ?>
                    </div>
                    <p class="text-sm mb-6 leading-relaxed" style="color:var(--color-text-secondary)">"<?= e($t['text']) ?>"</p>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-<?= $t['color'] ?>-100 dark:bg-<?= $t['color'] ?>-900/30 flex items-center justify-center text-<?= $t['color'] ?>-600 font-bold text-sm">
                            <?= e($t['avatar']) ?>
                        </div>
                        <div>
                            <p class="font-semibold text-sm" style="color:var(--color-text-primary)"><?= e($t['name']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($t['role']) ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== FAQ SECTION ===== -->
<section class="py-20" style="background:var(--color-card)">
    <div class="max-w-3xl mx-auto px-4">
        <div class="text-center mb-16 fade-up">
            <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider"><?= e(__('home.faq')) ?></span>
            <h2 class="text-3xl md:text-4xl font-bold mt-3" style="color:var(--color-text-primary)"><?= e(__('home.faq_title')) ?></h2>
        </div>

        <div class="space-y-4">
            <?php
            $faqs = [
                ['q' => 'How do I get started?', 'a' => 'Simply click "Register" and choose whether you\'re a company or freelancer. Fill in your profile details and start posting or applying for jobs right away.'],
                ['q' => 'Is it free to use?', 'a' => 'Yes! Posting jobs and applying for work is completely free. We only charge a small fee when a payment is processed through the platform.'],
                ['q' => 'How does payment work?', 'a' => 'When a job is completed, the company approves the work and processes payment through our secure system. Freelancers receive their payment directly.'],
                ['q' => 'Can I use the platform in Myanmar?', 'a' => 'Absolutely! FreelanceHub supports both English and Myanmar languages. Simply switch using the language toggle in the navigation bar.'],
            ];
            foreach ($faqs as $i => $faq):
            ?>
                <div class="faq-item card cursor-pointer fade-up delay-<?= ($i % 4) + 1 ?>" onclick="toggleFaq(this)">
                    <div class="flex items-center justify-between">
                        <h3 class="font-semibold pr-4" style="color:var(--color-text-primary)"><?= e($faq['q']) ?></h3>
                        <span class="faq-icon text-2xl font-light flex-shrink-0" style="color:var(--color-text-muted)">+</span>
                    </div>
                    <div class="faq-answer">
                        <p class="pt-4 text-sm" style="color:var(--color-text-muted)"><?= e($faq['a']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== CTA SECTION ===== -->
<section class="py-20">
    <div class="max-w-6xl mx-auto px-4">
        <div class="hero-gradient rounded-3xl p-12 md:p-16 text-center text-white relative overflow-hidden fade-up">
            <div class="relative z-10">
                <h2 class="text-3xl md:text-4xl font-extrabold mb-6"><?= e(__('home.cta_title')) ?></h2>
                <p class="text-lg text-indigo-100 mb-10 max-w-xl mx-auto"><?= e(__('home.cta_subtitle')) ?></p>
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="<?= e(base_url('register.php')) ?>" class="bg-white text-indigo-600 hover:bg-indigo-50 px-8 py-3.5 rounded-xl font-bold text-sm transition-colors btn-shine">
                        <?= e(__('home.cta_companies')) ?>
                    </a>
                    <a href="<?= e(base_url('register.php')) ?>" class="bg-white/10 backdrop-blur-sm text-white border border-white/20 hover:bg-white/20 px-8 py-3.5 rounded-xl font-bold text-sm transition-colors">
                        <?= e(__('home.cta_freelancers')) ?>
                    </a>
                </div>
            </div>
            <div class="absolute top-10 right-10 w-20 h-20 bg-white/5 rounded-full float-anim hidden md:block"></div>
            <div class="absolute bottom-10 left-10 w-32 h-32 bg-white/5 rounded-2xl float-anim-delay hidden md:block"></div>
        </div>
    </div>
</section>

<!-- ===== FOOTER ===== -->
<footer class="py-16" style="background:var(--color-card);border-top:1px solid var(--color-border)">
    <div class="max-w-6xl mx-auto px-4">
        <div class="grid md:grid-cols-4 gap-12 mb-12">
            <!-- Brand -->
            <div>
                <a href="<?= e(base_url('index.php')) ?>" class="text-xl font-bold text-indigo-600"><?= e(__('app.name')) ?></a>
                <p class="mt-4 text-sm" style="color:var(--color-text-muted)"><?= e(__('home.footer_desc')) ?></p>
                <div class="flex gap-3 mt-6">
                    <a href="#" class="w-9 h-9 rounded-lg flex items-center justify-center transition-colors" style="background:var(--color-bg);color:var(--color-text-muted)">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 4.557c-.883.392-1.832.656-2.828.775 1.017-.609 1.798-1.574 2.165-2.724-.951.564-2.005.974-3.127 1.195-.897-.957-2.178-1.555-3.594-1.555-3.179 0-5.515 2.966-4.797 6.045-4.091-.205-7.719-2.165-10.148-5.144-1.29 2.213-.669 5.108 1.523 6.574-.806-.026-1.566-.247-2.229-.616-.054 2.281 1.581 4.415 3.949 4.89-.693.188-1.452.232-2.224.084.626 1.956 2.444 3.379 4.6 3.419-2.07 1.623-4.678 2.348-7.29 2.04 2.179 1.397 4.768 2.212 7.548 2.212 9.142 0 14.307-7.721 13.995-14.646.962-.695 1.797-1.562 2.457-2.549z"/></svg>
                    </a>
                    <a href="#" class="w-9 h-9 rounded-lg flex items-center justify-center transition-colors" style="background:var(--color-bg);color:var(--color-text-muted)">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                    </a>
                </div>
            </div>

            <!-- For Companies -->
            <div>
                <h4 class="font-bold mb-4" style="color:var(--color-text-primary)"><?= e(__('home.footer_for_companies')) ?></h4>
                <ul class="space-y-3 text-sm" style="color:var(--color-text-muted)">
                    <li><a href="<?= e(base_url('register.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('home.post_a_job')) ?></a></li>
                    <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('home.browse_freelancers')) ?></a></li>
                    <li><a href="<?= e(base_url('login.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('nav.login')) ?></a></li>
                </ul>
            </div>

            <!-- For Freelancers -->
            <div>
                <h4 class="font-bold mb-4" style="color:var(--color-text-primary)"><?= e(__('home.footer_for_freelancers')) ?></h4>
                <ul class="space-y-3 text-sm" style="color:var(--color-text-muted)">
                    <li><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('home.find_work')) ?></a></li>
                    <li><a href="<?= e(base_url('register.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('home.create_profile')) ?></a></li>
                    <li><a href="<?= e(base_url('login.php')) ?>" class="hover:text-indigo-600 transition-colors"><?= e(__('nav.login')) ?></a></li>
                </ul>
            </div>

            <!-- Resources -->
            <div>
                <h4 class="font-bold mb-4" style="color:var(--color-text-primary)"><?= e(__('home.footer_resources')) ?></h4>
                <ul class="space-y-3 text-sm" style="color:var(--color-text-muted)">
                    <li><a href="#" class="hover:text-indigo-600 transition-colors"><?= e(__('home.help_center')) ?></a></li>
                    <li><a href="#" class="hover:text-indigo-600 transition-colors"><?= e(__('home.terms')) ?></a></li>
                    <li><a href="#" class="hover:text-indigo-600 transition-colors"><?= e(__('home.privacy')) ?></a></li>
                </ul>
            </div>
        </div>

        <div class="pt-8 text-center text-sm" style="border-top:1px solid var(--color-border);color:var(--color-text-muted)">
            <p>&copy; <?= date('Y') ?> <?= e(__('app.name')) ?>. <?= e(__('footer.rights')) ?></p>
        </div>
    </div>
</footer>

<?php
// Theme toggle + body close (replaces footer.php for this page)
?>
<script>
(function(){
    var themeToggle = document.getElementById('theme-toggle');
    var html = document.documentElement;
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            var isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // Scroll animations
    var fadeEls = document.querySelectorAll('.fade-up');
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
    fadeEls.forEach(function(el) { observer.observe(el); });

    // Counter animation
    var counters = document.querySelectorAll('.counter-num');
    var counterObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                var el = entry.target;
                var target = parseInt(el.getAttribute('data-target')) || 0;
                var duration = 2000;
                var startTime = null;
                function step(timestamp) {
                    if (!startTime) startTime = timestamp;
                    var progress = Math.min((timestamp - startTime) / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    el.textContent = Math.floor(eased * target).toLocaleString();
                    if (progress < 1) requestAnimationFrame(step);
                    else el.textContent = target.toLocaleString() + '+';
                }
                requestAnimationFrame(step);
                counterObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });
    counters.forEach(function(el) { counterObserver.observe(el); });

    // FAQ toggle
    window.toggleFaq = function(el) {
        var answer = el.querySelector('.faq-answer');
        var isActive = el.classList.contains('active');
        document.querySelectorAll('.faq-item').forEach(function(item) {
            item.classList.remove('active');
            item.querySelector('.faq-answer').classList.remove('open');
        });
        if (!isActive) {
            el.classList.add('active');
            answer.classList.add('open');
        }
    };

    // Hero search
    var heroSearch = document.getElementById('hero-search');
    if (heroSearch) {
        heroSearch.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                window.location = '<?= e(base_url('freelancer/browse_jobs.php')) ?>?q=' + encodeURIComponent(this.value);
            }
        });
    }
})();
</script>
</body>
</html>
