<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('admin');

// Fetch stats
$stats = [
    'hidden_jobs' => 0,
    'total_companies' => 0,
    'total_freelancers' => 0,
    'total_jobs' => 0,
    'total_users' => 0,
    'completed_jobs' => 0,
];

try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'rejected'");
    $stats['hidden_jobs'] = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {}

$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'company'");
$stats['total_companies'] = (int) $r->fetch_assoc()['cnt'];

$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'freelancer'");
$stats['total_freelancers'] = (int) $r->fetch_assoc()['cnt'];

try {
    $r = $conn->query('SELECT COUNT(*) AS cnt FROM jobs');
    $stats['total_jobs'] = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {}

$r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role != 'admin'");
$stats['total_users'] = (int) $r->fetch_assoc()['cnt'];

try {
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'completed'");
    $stats['completed_jobs'] = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {}

// Recent jobs (exclude direct hire)
$recent_jobs = [];
try {
    $r = $conn->query("
        SELECT j.id, j.title, j.budget, j.status, j.created_at, c.company_name
        FROM jobs j JOIN companies c ON j.company_id = c.id
        WHERE j.category != 'Direct Hire'
        ORDER BY j.created_at DESC LIMIT 5
    ");
    if ($r) { while ($row = $r->fetch_assoc()) $recent_jobs[] = $row; }
} catch (mysqli_sql_exception $e) {}

// Recent users
$recent_users = [];
$r = $conn->query("
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.profile_image
    FROM users u WHERE u.role != 'admin'
    ORDER BY u.created_at DESC LIMIT 5
");
if ($r) { while ($row = $r->fetch_assoc()) $recent_users[] = $row; }

$page_title = 'Admin Dashboard';
require __DIR__ . '/includes/admin_header.php';
?>

<!-- Page Header -->
<div class="mb-6 admin-fade">
    <h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e('Admin Dashboard') ?></h1>
    <p class="text-sm mt-0.5" style="color:var(--color-text-muted)"><?= e('Welcome back! Here\'s what\'s happening on your platform.') ?></p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    <!-- Total Users -->
    <div class="card admin-fade delay-1 group hover:border-indigo-200 dark:hover:border-indigo-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold stat-counter" data-target="<?= $stats['total_users'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Total Users') ?></p>
    </div>

    <!-- Freelancers -->
    <div class="card admin-fade delay-2 group hover:border-purple-200 dark:hover:border-purple-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold stat-counter" data-target="<?= $stats['total_freelancers'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Freelancers') ?></p>
    </div>

    <!-- Companies -->
    <div class="card admin-fade delay-3 group hover:border-emerald-200 dark:hover:border-emerald-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold stat-counter" data-target="<?= $stats['total_companies'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Companies') ?></p>
    </div>

    <!-- Active Jobs -->
    <div class="card admin-fade delay-1 group hover:border-blue-200 dark:hover:border-blue-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold stat-counter" data-target="<?= $stats['total_jobs'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Total Jobs') ?></p>
    </div>

    <!-- Completed Jobs -->
    <div class="card admin-fade delay-2 group hover:border-green-200 dark:hover:border-green-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-green-50 dark:bg-green-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold stat-counter" data-target="<?= $stats['completed_jobs'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Completed') ?></p>
    </div>

    <!-- Hidden/Moderated Jobs -->
    <div class="card admin-fade delay-3 group hover:border-red-200 dark:hover:border-red-800">
        <div class="flex items-center gap-2.5 mb-2">
            <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
        </div>
        <p class="text-2xl font-bold text-red-600 stat-counter" data-target="<?= $stats['hidden_jobs'] ?>">0</p>
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Hidden Jobs') ?></p>
    </div>
</div>

<!-- Recent Activity + Quick Actions -->
<div class="grid lg:grid-cols-3 gap-4 mb-6">
    <!-- Recent Jobs -->
    <div class="lg:col-span-2 card admin-fade">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Recent Jobs') ?></h3>
            <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All') ?></a>
        </div>
        <?php if (empty($recent_jobs)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No jobs posted yet.') ?></p>
        <?php else: ?>
            <div class="divide-y" style="border-color:var(--color-border)">
                <?php foreach ($recent_jobs as $job): ?>
                    <div class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="w-7 h-7 rounded-md bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($job['title']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?> &middot; <?= e(number_format((float) $job['budget'], 0)) ?> MMK</p>
                        </div>
                        <?= status_badge($job['status']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="card admin-fade">
        <h3 class="text-sm font-semibold mb-3" style="color:var(--color-text-primary)"><?= e('Quick Actions') ?></h3>
        <div class="space-y-2">
            <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="flex items-center gap-2.5 p-2.5 rounded-lg border hover:border-indigo-200 dark:hover:border-indigo-800 transition-colors" style="border-color:var(--color-border)">
                <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e('Moderate Jobs') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= $admin_hidden_count ?> <?= e('pending review') ?></p>
                </div>
            </a>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="flex items-center gap-2.5 p-2.5 rounded-lg border hover:border-indigo-200 dark:hover:border-indigo-800 transition-colors" style="border-color:var(--color-border)">
                <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e('Manage Users') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= $stats['total_users'] ?> <?= e('total') ?></p>
                </div>
            </a>
            <a href="<?= e(base_url('admin/manage_skills.php')) ?>" class="flex items-center gap-2.5 p-2.5 rounded-lg border hover:border-indigo-200 dark:hover:border-indigo-800 transition-colors" style="border-color:var(--color-border)">
                <div class="w-8 h-8 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a4 4 0 014-4z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e('Manage Skills') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= e('View & edit') ?></p>
                </div>
            </a>
            <a href="<?= e(base_url('admin/categories.php')) ?>" class="flex items-center gap-2.5 p-2.5 rounded-lg border hover:border-indigo-200 dark:hover:border-indigo-800 transition-colors" style="border-color:var(--color-border)">
                <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium" style="color:var(--color-text-primary)"><?= e('Manage Categories') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= e('View & edit') ?></p>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Recent Users + Notifications -->
<div class="grid lg:grid-cols-2 gap-4 mb-6">
    <!-- Recent Users -->
    <div class="card admin-fade">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Recent Users') ?></h3>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All') ?></a>
        </div>
        <?php if (empty($recent_users)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No users registered yet.') ?></p>
        <?php else: ?>
            <div class="divide-y" style="border-color:var(--color-border)">
                <?php foreach ($recent_users as $u): ?>
                    <div class="flex items-center gap-2.5 py-2.5 first:pt-0 last:pb-0">
                        <?php $img = profile_image_url($u['profile_image']); ?>
                        <?php if ($img): ?>
                            <img src="<?= e($img) ?>" alt="" class="w-7 h-7 rounded-full object-cover flex-shrink-0">
                        <?php else: ?>
                            <div class="w-7 h-7 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs flex-shrink-0">
                                <?= e(_first_char($u['username'])) ?>
                            </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($u['username']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($u['email']) ?></p>
                        </div>
                        <?php
                        $rc = $u['role'] === 'company' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400';
                        ?>
                        <span class="inline-flex px-1.5 py-0.5 text-[10px] font-semibold rounded-full <?= $rc ?>"><?= e(ucfirst($u['role'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Notifications -->
    <?php
    $admin_user = current_user();
    $admin_notifs = [];
    if ($admin_user) {
        try {
            $admin_notifs = get_notifications($conn, (int) $admin_user['user_id'], 5);
        } catch (Exception $e) {}
    }
    ?>
    <div class="card admin-fade">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Notifications') ?></h3>
            <a href="<?= e(base_url('admin/notifications.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All') ?></a>
        </div>
        <?php if (empty($admin_notifs)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No notifications yet.') ?></p>
        <?php else: ?>
            <div class="divide-y" style="border-color:var(--color-border)">
                <?php foreach ($admin_notifs as $n): ?>
                    <div class="flex items-start gap-2.5 py-2.5 first:pt-0 last:pb-0 <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/20 -mx-2 px-2 rounded' ?>">
                        <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs truncate <?= $n['is_read'] ? '' : 'font-medium' ?>" style="color:<?= $n['is_read'] ? 'var(--color-text-muted)' : 'var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                            <p class="text-[10px] mt-0.5" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                        </div>
                        <?php if ($n['link']): ?>
                            <a href="<?= e(base_url($n['link'])) ?>" class="text-indigo-600 hover:text-indigo-700 flex-shrink-0">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    // Admin fade-in animations
    var adminFadeEls = document.querySelectorAll('.admin-fade');
    adminFadeEls.forEach(function(el) { el.classList.add('animate'); });
    var fadeObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });
    adminFadeEls.forEach(function(el) { fadeObserver.observe(el); });

    // Stat counter animation
    var counters = document.querySelectorAll('.stat-counter');
    var counterObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                var el = entry.target;
                var target = parseInt(el.getAttribute('data-target')) || 0;
                if (target === 0) { el.textContent = '0'; return; }
                var duration = 1200;
                var startTime = null;
                function step(timestamp) {
                    if (!startTime) startTime = timestamp;
                    var progress = Math.min((timestamp - startTime) / duration, 1);
                    var eased = 1 - Math.pow(1 - progress, 3);
                    el.textContent = Math.floor(eased * target).toLocaleString();
                    if (progress < 1) requestAnimationFrame(step);
                    else el.textContent = target.toLocaleString();
                }
                requestAnimationFrame(step);
                counterObserver.unobserve(el);
            }
        });
    }, { threshold: 0.5 });
    counters.forEach(function(el) { counterObserver.observe(el); });
})();
</script>

<?php require __DIR__ . '/includes/admin_footer.php'; ?>
