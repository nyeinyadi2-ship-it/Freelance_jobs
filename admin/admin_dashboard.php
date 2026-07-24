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
    'total_revenue' => 0,
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

try {
    $r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid'");
    $stats['total_revenue'] = (float) $r->fetch_assoc()['total'];
} catch (mysqli_sql_exception $e) {
    $stats['total_revenue'] = 0;
}

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
<div class="mb-8 admin-fade">
    <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e('Admin Dashboard') ?></h1>
    <p class="mt-1 text-sm" style="color:var(--color-text-muted)"><?= e('Welcome back! Here\'s what\'s happening on your platform.') ?></p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <!-- Hidden/Moderated Jobs -->
    <div class="admin-stat-card card admin-fade delay-1">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)">Hidden Jobs</p>
                <p class="text-3xl font-extrabold mt-1 text-red-600 stat-counter" data-target="<?= $stats['hidden_jobs'] ?>">0</p>
            </div>
            <div class="stat-icon bg-red-100 dark:bg-red-900/30">
                <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
            </div>
        </div>
        <div class="mt-3 flex items-center gap-1">
            <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-xs font-medium text-red-600 hover:underline">Review Moderation →</a>
        </div>
        <div class="stat-glow-effect bg-red-400"></div>
    </div>

    <!-- Total Jobs -->
    <div class="admin-stat-card card admin-fade delay-2">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e('Total Jobs') ?></p>
                <p class="text-3xl font-extrabold mt-1 text-indigo-600 stat-counter" data-target="<?= $stats['total_jobs'] ?>">0</p>
            </div>
            <div class="stat-icon bg-indigo-100 dark:bg-indigo-900/30">
                <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
        </div>
        <div class="mt-3">
            <span class="text-xs" style="color:var(--color-text-muted)"><?= $stats['completed_jobs'] ?> <?= e('completed') ?></span>
        </div>
        <div class="stat-glow-effect bg-indigo-400"></div>
    </div>

    <!-- Companies -->
    <div class="admin-stat-card card admin-fade delay-3">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e('Companies') ?></p>
                <p class="text-3xl font-extrabold mt-1 text-emerald-600 stat-counter" data-target="<?= $stats['total_companies'] ?>">0</p>
            </div>
            <div class="stat-icon bg-emerald-100 dark:bg-emerald-900/30">
                <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
        </div>
        <div class="mt-3">
            <span class="text-xs" style="color:var(--color-text-muted)"><?= e('Active accounts') ?></span>
        </div>
        <div class="stat-glow-effect bg-emerald-400"></div>
    </div>

    <!-- Freelancers -->
    <div class="admin-stat-card card admin-fade delay-4">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e('Freelancers') ?></p>
                <p class="text-3xl font-extrabold mt-1 text-purple-600 stat-counter" data-target="<?= $stats['total_freelancers'] ?>">0</p>
            </div>
            <div class="stat-icon bg-purple-100 dark:bg-purple-900/30">
                <svg class="w-6 h-6 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
        </div>
        <div class="mt-3">
            <span class="text-xs" style="color:var(--color-text-muted)"><?= e('Talented professionals') ?></span>
        </div>
        <div class="stat-glow-effect bg-purple-400"></div>
    </div>
</div>

<!-- Quick Actions + Revenue -->
<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <!-- Quick Actions -->
    <div class="lg:col-span-1 card admin-fade">
        <h3 class="font-bold mb-4" style="color:var(--color-text-primary)"><?= e('Quick Actions') ?></h3>
        <div class="space-y-3">
            <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="quick-action-card">
                <div class="w-10 h-10 rounded-lg bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--color-text-primary)">Moderate Jobs</p>
                    <p class="text-xs" style="color:var(--color-text-muted)">Review & hide inappropriate posts</p>
                </div>
            </a>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="quick-action-card">
                <div class="w-10 h-10 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Manage Users') ?></p>
                    <p class="text-xs" style="color:var(--color-text-muted)"><?= $stats['total_users'] ?> <?= e('Total Users') ?></p>
                </div>
            </a>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="quick-action-card">
                <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold" style="color:var(--color-text-primary)">Platform Stats</p>
                    <p class="text-xs" style="color:var(--color-text-muted)">View platform overview</p>
                </div>
            </a>
        </div>
    </div>

    <!-- Revenue Card -->
    <div class="lg:col-span-2 card admin-fade" style="background:linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);color:white;border:none">
        <div class="flex items-start justify-between mb-6">
            <div>
                <p class="text-indigo-200 text-sm font-medium"><?= e('Total Revenue') ?></p>
                <p class="text-4xl font-extrabold mt-1">$<?= e(number_format($stats['total_revenue'], 2)) ?></p>
            </div>
            <div class="w-12 h-12 bg-white/10 rounded-xl flex items-center justify-center">
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        <div class="grid grid-cols-3 gap-4">
            <div class="bg-white/10 rounded-xl p-4">
                <p class="text-indigo-200 text-xs"><?= e('completed') ?></p>
                <p class="text-xl font-bold mt-1"><?= $stats['completed_jobs'] ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-4">
                <p class="text-indigo-200 text-xs"><?= e('Total Users') ?></p>
                <p class="text-xl font-bold mt-1"><?= $stats['total_users'] ?></p>
            </div>
            <div class="bg-white/10 rounded-xl p-4">
                <p class="text-indigo-200 text-xs"><?= e('Jobs Created') ?></p>
                <p class="text-xl font-bold mt-1"><?= $stats['total_jobs'] ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity + Recent Users -->
<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <!-- Recent Jobs -->
    <div class="card admin-fade">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold" style="color:var(--color-text-primary)"><?= e('Recent Jobs') ?></h3>
            <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All') ?> →</a>
        </div>
        <?php if (empty($recent_jobs)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No jobs posted yet.') ?></p>
        <?php else: ?>
            <div class="space-y-0">
                <?php foreach ($recent_jobs as $job): ?>
                    <div class="activity-item">
                        <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($job['title']) ?></p>
                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?> · $<?= e(number_format((float) $job['budget'], 0)) ?></p>
                        </div>
                        <?= status_badge($job['status']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Users -->
    <div class="card admin-fade">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold" style="color:var(--color-text-primary)"><?= e('Recent Users') ?></h3>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All') ?> →</a>
        </div>
        <?php if (empty($recent_users)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No users registered yet.') ?></p>
        <?php else: ?>
            <div class="space-y-0">
                <?php foreach ($recent_users as $u): ?>
                    <div class="activity-item">
                        <?php $img = profile_image_url($u['profile_image']); ?>
                        <?php if ($img): ?>
                            <img src="<?= e($img) ?>" alt="" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs flex-shrink-0">
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
                        <span class="inline-flex px-2 py-0.5 text-xs font-semibold rounded-full <?= $rc ?>"><?= e(ucfirst($u['role'])) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
// Recent notifications
$admin_user = current_user();
$admin_notifs = [];
if ($admin_user) {
    try {
        $admin_notifs = get_notifications($conn, (int) $admin_user['user_id'], 5);
    } catch (Exception $e) {}
}
?>
<div class="card admin-fade mb-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="font-bold" style="color:var(--color-text-primary)">Recent Notifications</h3>
        <a href="<?= e(base_url('admin/notifications.php')) ?>" class="text-xs text-indigo-600 hover:underline">View All →</a>
    </div>
    <?php if (empty($admin_notifs)): ?>
        <p class="text-sm text-center py-8" style="color:var(--color-text-muted)">No notifications yet.</p>
    <?php else: ?>
        <div class="space-y-0">
            <?php foreach ($admin_notifs as $n): ?>
                <div class="activity-item <?= $n['is_read'] ? '' : 'bg-indigo-50/50 dark:bg-indigo-900/20' ?>">
                    <div class="flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm truncate <?= $n['is_read'] ? '' : 'font-semibold' ?>" style="color:var(--color-text-primary)"><?= e($n['message']) ?></p>
                        <p class="text-xs" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
                    </div>
                    <?php if ($n['link']): ?>
                        <a href="<?= e(base_url($n['link'])) ?>" class="text-indigo-600 hover:text-indigo-700 flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
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
                var duration = 1500;
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
