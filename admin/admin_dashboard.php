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
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'closed'");
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
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status IN ('completed', 'closed')");
    $stats['completed_jobs'] = (int) $r->fetch_assoc()['cnt'];
} catch (mysqli_sql_exception $e) {}

// Recent jobs
$recent_jobs = [];
try {
    $r = $conn->query("
        SELECT j.id, j.title, j.budget, j.status, j.created_at, c.company_name
        FROM jobs j JOIN companies c ON j.company_id = c.id
        ORDER BY j.created_at DESC LIMIT 5
    ");
    if ($r) { while ($row = $r->fetch_assoc()) $recent_jobs[] = $row; }
} catch (mysqli_sql_exception $e) {}

// Recent users
$has_status_col = has_account_status_column();
$status_sel = $has_status_col ? 'u.account_status,' : "'active' AS account_status,";
$recent_users = [];
$r = $conn->query("
    SELECT u.id, u.username, u.email, u.role, {$status_sel} u.created_at, u.profile_image
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
        <p class="text-xs mt-0.5" style="color:var(--color-text-muted)"><?= e('Closed Jobs') ?></p>
    </div>
</div>

<!-- Recent Notifications -->
<div class="mb-6">
    <?php
    $admin_user = current_user();
    $admin_notifs = [];
    if ($admin_user) {
        try {
            $admin_notifs = get_notifications($conn, (int) $admin_user['user_id'], 100);
        } catch (Exception $e) {}
    }
    ?>
    <div class="card admin-fade flex flex-col w-full" style="max-height: 600px;">
        <div class="flex items-center justify-between mb-3 flex-shrink-0">
            <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Notifications') ?></h3>
            <a href="<?= e(base_url('admin/notifications.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All Notifications') ?></a>
        </div>
        <?php if (empty($admin_notifs)): ?>
            <p class="text-sm text-center py-8 flex-1" style="color:var(--color-text-muted)"><?= e('No notifications yet.') ?></p>
        <?php else: ?>
            <div class="divide-y overflow-y-auto pr-2 flex-1 custom-scrollbar" style="border-color:var(--color-border)">
                <?php foreach ($admin_notifs as $n): ?>
                    <div class="flex items-start gap-3 py-3 first:pt-0 last:pb-0 <?= $n['is_read'] ? '' : 'bg-indigo-50/30 dark:bg-indigo-900/10 -mx-2 px-2 rounded-lg' ?>">
                        <div class="mt-1 flex-shrink-0 relative">
                            <?= notification_icon($n['type']) ?>
                            <?php if (!$n['is_read']): ?>
                                <span class="absolute -top-1 -right-1 w-2.5 h-2.5 bg-red-500 border-2 border-white dark:border-slate-800 rounded-full" title="Unread"></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-start justify-between gap-2">
                                <p class="text-sm <?= $n['is_read'] ? '' : 'font-semibold' ?>" style="color:<?= $n['is_read'] ? 'var(--color-text-primary)' : 'var(--color-text-primary)' ?>"><?= e($n['message']) ?></p>
                                <span class="text-[10px] font-medium px-1.5 py-0.5 rounded flex-shrink-0 <?= $n['is_read'] ? 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' : 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400' ?>">
                                    <?= $n['is_read'] ? 'Read' : 'Unread' ?>
                                </span>
                            </div>
                            <p class="text-[11px] mt-1" style="color:var(--color-text-muted)"><?= date('M j, Y, g:i a', strtotime($n['created_at'])) ?></p>
                        </div>
                        <?php if ($n['link']): ?>
                            <a href="<?= e(base_url($n['link'])) ?>" class="mt-1 flex-shrink-0 text-indigo-600 hover:text-indigo-700 bg-indigo-50 hover:bg-indigo-100 p-1.5 rounded-lg transition-colors dark:bg-indigo-900/30 dark:hover:bg-indigo-900/50" title="View details">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Activity + Quick Actions -->
<div class="grid lg:grid-cols-2 gap-4 mb-6">
    <!-- Recent Jobs -->
    <div class="card admin-fade">
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

    <!-- Recent Users Table -->
    <div class="card admin-fade delay-4">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e('Recent Users') ?></h3>
            <a href="<?= e(base_url('admin/manage_users.php')) ?>" class="text-xs text-indigo-600 hover:underline"><?= e('View All Users') ?></a>
        </div>
        
        <?php if (empty($recent_users)): ?>
            <p class="text-sm text-center py-8" style="color:var(--color-text-muted)"><?= e('No users registered yet.') ?></p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b" style="border-color:var(--color-border)">
                            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">User</th>
                            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Role</th>
                            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Joined Date</th>
                            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500">Status</th>
                            <th class="py-3 px-4 text-xs font-semibold uppercase tracking-wider text-slate-500 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y" style="border-color:var(--color-border)">
                        <?php foreach ($recent_users as $u): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-3">
                                        <?php $img = profile_image_url($u['profile_image']); ?>
                                        <?php if ($img): ?>
                                            <img src="<?= e($img) ?>" alt="" class="w-8 h-8 rounded-full object-cover flex-shrink-0">
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-xs flex-shrink-0">
                                                <?= e(_first_char($u['username'])) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div>
                                            <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e($u['username']) ?></p>
                                            <p class="text-xs" style="color:var(--color-text-muted)"><?= e($u['email']) ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-4">
                                    <?php
                                    $rc = $u['role'] === 'company' ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400' : 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400';
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-[10px] font-bold uppercase rounded-md <?= $rc ?>"><?= e($u['role']) ?></span>
                                </td>
                                <td class="py-3 px-4 text-sm" style="color:var(--color-text-primary)">
                                    <?= date('M j, Y', strtotime($u['created_at'])) ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php 
                                        $status = $u['account_status'] ?? 'active';
                                        $status_classes = [
                                            'active' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
                                            'suspended' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
                                            'blocked' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
                                        ];
                                        $sc = $status_classes[$status] ?? $status_classes['active'];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-[10px] font-semibold rounded-md <?= $sc ?>"><?= e(ucfirst($status)) ?></span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <a href="<?= e(base_url('admin/view_user.php?id=' . $u['id'])) ?>" class="inline-flex items-center justify-center px-3 py-1.5 text-xs font-semibold rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-400 dark:hover:bg-indigo-900/50 transition-colors">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
