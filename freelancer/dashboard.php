<?php
$page_title = 'Dashboard';
require __DIR__ . '/../includes/freelancer_layout.php';

// Ongoing tasks
$ongoing_tasks = [];
try { $s = $conn->prepare("SELECT a.id,a.status,a.submission_link,a.assigned_at,j.title,j.description,j.budget,j.id AS job_id,c.company_name,c.logo_image FROM assignments a JOIN jobs j ON a.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE a.freelancer_id=? AND a.status IN ('assigned','working','submitted') ORDER BY a.assigned_at DESC"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $ongoing_tasks[] = $row; $s->close(); } catch(Exception $e) {}

// Completed tasks
$completed_list = [];
try { $s = $conn->prepare("SELECT a.id,a.status,a.submission_link,a.assigned_at,j.title,j.budget,j.id AS job_id,c.company_name,c.logo_image,p.amount,p.paid_at FROM assignments a JOIN jobs j ON a.job_id=j.id JOIN companies c ON j.company_id=c.id LEFT JOIN payments p ON p.assignment_id=a.id WHERE a.freelancer_id=? AND a.status='completed' ORDER BY a.assigned_at DESC"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $completed_list[] = $row; $s->close(); } catch(Exception $e) {}

// Recent applications
$recent_apps = [];
try { $s = $conn->prepare("SELECT ja.id,ja.status,ja.applied_at,j.title,j.budget,j.id AS job_id,c.company_name,c.logo_image FROM job_applications ja JOIN jobs j ON ja.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE ja.freelancer_id=? ORDER BY ja.applied_at DESC LIMIT 6"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $recent_apps[] = $row; $s->close(); } catch(Exception $e) {}

// All applications
$all_apps = [];
try { $s = $conn->prepare("SELECT ja.id,ja.status,ja.applied_at,j.title,j.budget,j.id AS job_id,c.company_name,c.logo_image FROM job_applications ja JOIN jobs j ON ja.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE ja.freelancer_id=? ORDER BY ja.applied_at DESC"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $all_apps[] = $row; $s->close(); } catch(Exception $e) {}

// Earnings
$earnings = [];
try { $s = $conn->prepare("SELECT p.id,p.amount,p.status,p.paid_at,j.title AS job_title,c.company_name FROM payments p JOIN assignments a ON p.assignment_id=a.id JOIN jobs j ON a.job_id=j.id JOIN companies c ON j.company_id=c.id WHERE a.freelancer_id=? ORDER BY p.paid_at DESC"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $earnings[] = $row; $s->close(); } catch(Exception $e) {}

// Recommended jobs
$recommended = [];
try { $s = $conn->prepare("SELECT j.id,j.title,j.description,j.budget,j.created_at,c.company_name,c.logo_image FROM jobs j JOIN companies c ON j.company_id=c.id WHERE j.status='approved' AND j.id NOT IN (SELECT job_id FROM job_applications WHERE freelancer_id=?) AND j.id NOT IN (SELECT job_id FROM assignments) ORDER BY j.created_at DESC LIMIT 6"); $s->bind_param('i', $fl_freelancer_id); $s->execute(); $r = $s->get_result(); while ($row = $r->fetch_assoc()) $recommended[] = $row; $s->close(); } catch(Exception $e) {}

$profileImg = profile_image_url($fl_profile['profile_image']);
$initial = strtoupper(mb_substr($fl_profile['full_name'] ?? $fl_profile['username'] ?? 'U', 0, 1));
?>

<!-- ===== HERO PROFILE CARD ===== -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-4">
    <div class="profile-banner rounded-3xl p-6 sm:p-8 text-white reveal">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-5 relative z-10">
            <div class="flex items-center gap-5">
                <div class="relative flex-shrink-0">
                    <?php if ($profileImg): ?>
                        <img src="<?= e($profileImg) ?>" alt="" class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl object-cover border-4 border-white/30 shadow-2xl">
                    <?php else: ?>
                        <div class="w-20 h-20 sm:w-24 sm:h-24 rounded-2xl flex items-center justify-center font-bold text-3xl border-4 border-white/30 shadow-2xl bg-white/15"><?= $initial ?></div>
                    <?php endif; ?>
                    <span class="absolute -bottom-1 -right-1 w-6 h-6 bg-emerald-400 rounded-full border-3 border-white flex items-center justify-center shadow"><svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
                </div>
                <div>
                    <h1 class="text-xl sm:text-2xl font-extrabold tracking-tight"><?= e($fl_profile['full_name'] ?? $fl_profile['username']) ?></h1>
                    <p class="text-sm sm:text-base flex flex-wrap items-center gap-1.5 mt-1.5 text-white/80">
                        <span class="inline-flex items-center gap-1"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg> <?= e($fl_profile['title'] ?? 'Freelancer') ?></span>
                        <?php if ($fl_profile['location']): ?><span class="w-1 h-1 rounded-full bg-white/40"></span><span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg> <?= e($fl_profile['location']) ?></span><?php endif; ?>
                        <?php if ($fl_profile['hourly_rate'] !== null): ?><span class="w-1 h-1 rounded-full bg-white/40"></span><span class="font-semibold">$<?= number_format((float) $fl_profile['hourly_rate'], 0) ?>/hr</span><?php endif; ?>
                    </p>
                    <div class="flex items-center gap-3 mt-2.5">
                        <span class="inline-flex items-center gap-1.5 text-xs bg-emerald-400/20 px-3 py-1 rounded-lg font-medium"><span class="w-2 h-2 bg-emerald-400 rounded-full animate-pulse"></span>Available for work</span>
                        <span class="text-xs text-white/50">Joined <?= date('M Y', strtotime($fl_profile['created_at'])) ?></span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-indigo-700 bg-white hover:bg-gray-50 transition-all shadow-lg"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>Edit Profile</a>
                <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl bg-white/15 hover:bg-white/25 text-white transition-all border border-white/20"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>Browse Jobs</a>
            </div>
        </div>
    </div>
</div>

<!-- ===== STATISTICS ===== -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="stat-card p-5 text-white reveal reveal-d1" style="background:var(--gw)">
            <div class="flex items-center justify-between mb-3"><span class="text-sm font-medium opacity-90">Pending</span><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div></div>
            <p class="text-3xl font-extrabold"><?= $fl_stats['pending'] ?></p><p class="text-xs mt-1 opacity-70">Awaiting response</p>
        </div>
        <div class="stat-card p-5 text-white reveal reveal-d2" style="background:var(--gi)">
            <div class="flex items-center justify-between mb-3"><span class="text-sm font-medium opacity-90">Active</span><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div></div>
            <p class="text-3xl font-extrabold"><?= $fl_stats['active'] ?></p><p class="text-xs mt-1 opacity-70">In progress</p>
        </div>
        <div class="stat-card p-5 text-white reveal reveal-d3" style="background:var(--gs)">
            <div class="flex items-center justify-between mb-3"><span class="text-sm font-medium opacity-90">Completed</span><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div></div>
            <p class="text-3xl font-extrabold"><?= $fl_stats['completed'] ?></p><p class="text-xs mt-1 opacity-70">Projects delivered</p>
        </div>
        <div class="stat-card p-5 text-white reveal reveal-d4" style="background:var(--gp)">
            <div class="flex items-center justify-between mb-3"><span class="text-sm font-medium opacity-90">Earnings</span><div class="w-10 h-10 rounded-xl bg-white/15 flex items-center justify-center"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg></div></div>
            <p class="text-3xl font-extrabold">$<?= number_format($fl_stats['earnings'], 0) ?></p><p class="text-xs mt-1 opacity-70">Total lifetime</p>
        </div>
    </div>
</div>

<!-- ===== PROFILE COMPLETION ===== -->
<?php if ($fl_completion < 100): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
    <div class="glass rounded-2xl p-5 flex flex-col sm:flex-row items-start sm:items-center gap-4 hover-lift reveal">
        <div class="flex-1 w-full">
            <div class="flex items-center gap-2 mb-2"><svg class="w-5 h-5 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span class="font-semibold text-sm" style="color:var(--color-text-primary)">Complete your profile</span><span class="text-xs font-bold text-primary-600"><?= $fl_completion ?>%</span></div>
            <div class="w-full h-2.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden"><div class="progress-bar h-full" style="width:<?= $fl_completion ?>%"></div></div>
        </div>
        <a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/25 flex-shrink-0">Complete Now <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg></a>
    </div>
</div>
<?php endif; ?>

<!-- ===== TAB NAVIGATION ===== -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-6">
    <div class="sticky top-16 z-30 -mx-4 px-4 py-2 overflow-x-auto scrollbar-thin tab-scroll" style="background:var(--color-bg);border-bottom:1px solid var(--color-border)">
        <div class="flex gap-1 min-w-max">
            <?php $tabs = [
                ['id'=>'overview','label'=>'Overview','icon'=>'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
                ['id'=>'applications','label'=>'Applications','icon'=>'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z','badge'=>$fl_stats['pending'],'bc'=>'yellow'],
                ['id'=>'ongoing','label'=>'Ongoing','icon'=>'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10','badge'=>$fl_stats['active'],'bc'=>'blue'],
                ['id'=>'completed','label'=>'Completed','icon'=>'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['id'=>'earnings','label'=>'Earnings','icon'=>'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1'],
                ['id'=>'portfolio','label'=>'Portfolio','icon'=>'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
                ['id'=>'skills','label'=>'Skills','icon'=>'M13 10V3L4 14h7v7l9-11h-7z'],
                ['id'=>'messages','label'=>'Messages','icon'=>'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z','badge'=>$fl_chat_unread,'bc'=>'green'],
                ['id'=>'notifications','label'=>'Notifications','icon'=>'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9','badge'=>$fl_notif_count,'bc'=>'red'],
                ['id'=>'settings','label'=>'Settings','icon'=>'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ]; $first = true; foreach ($tabs as $t): ?>
                <button class="dash-tab <?= $first?'active':'' ?> px-3 sm:px-4 py-2.5 text-sm rounded-t-lg flex items-center gap-1.5" data-tab="<?= $t['id'] ?>" style="color:var(--color-text-muted)">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $t['icon'] ?>"/></svg>
                    <span class="hidden sm:inline"><?= $t['label'] ?></span>
                    <?php if (!empty($t['badge'])): ?><span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full bg-<?= $t['bc'] ?>-100 dark:bg-<?= $t['bc'] ?>-900/40 text-<?= $t['bc'] ?>-600 dark:text-<?= $t['bc'] ?>-400"><?= $t['badge'] ?></span><?php endif; ?>
                </button>
            <?php $first = false; endforeach; ?>
        </div>
    </div>
</div>

<!-- ===== TAB CONTENT ===== -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-12">

<!-- OVERVIEW -->
<div class="dash-section active" id="tab-overview">
    <div class="grid lg:grid-cols-2 gap-6 mb-6">
        <!-- Recent Applications -->
        <div class="glass rounded-2xl p-6 hover-lift reveal">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)"><svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div><h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Recent Applications</h2></div>
                <button class="text-sm font-medium text-primary-600 hover:text-primary-700" onclick="switchTab('applications')">View All &rarr;</button>
            </div>
            <?php if (empty($recent_apps)): ?>
                <div class="text-center py-10" style="color:var(--color-text-placeholder)"><svg class="w-14 h-14 mx-auto mb-3 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><p class="mb-3">No applications yet.</p><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white">Browse Jobs</a></div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach (array_slice($recent_apps, 0, 5) as $app): ?>
                        <div class="flex items-center gap-3 p-3.5 rounded-xl transition-all hover:bg-gray-50 dark:hover:bg-gray-800/50" style="border:1px solid var(--color-border)">
                            <?php if ($app['logo_image']): ?><img src="<?= e(base_url('uploads/' . $app['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php else: ?><div class="w-10 h-10 rounded-xl flex items-center justify-center text-indigo-600 font-bold text-sm" style="background:rgba(99,102,241,0.1)"><?= strtoupper(mb_substr($app['company_name'], 0, 1)) ?></div><?php endif; ?>
                            <div class="flex-1 min-w-0"><p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($app['title']) ?></p><p class="text-xs" style="color:var(--color-text-muted)"><?= e($app['company_name']) ?> &middot; $<?= number_format((float) $app['budget'], 0) ?></p></div>
                            <div class="text-right flex-shrink-0"><p class="text-xs mb-1" style="color:var(--color-text-placeholder)"><?= date('M j', strtotime($app['applied_at'])) ?></p><?= status_badge($app['status']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <!-- Ongoing Tasks -->
        <div class="glass rounded-2xl p-6 hover-lift reveal reveal-d1">
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(6,182,212,0.1)"><svg class="w-5 h-5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg></div><h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Ongoing Tasks</h2></div>
                <button class="text-sm font-medium text-primary-600 hover:text-primary-700" onclick="switchTab('ongoing')">View All &rarr;</button>
            </div>
            <?php if (empty($ongoing_tasks)): ?>
                <div class="text-center py-10" style="color:var(--color-text-placeholder)"><p>No ongoing tasks.</p></div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach (array_slice($ongoing_tasks, 0, 5) as $task): ?>
                        <div class="p-4 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                            <div class="flex items-center gap-3 mb-2.5">
                                <?php if ($task['logo_image']): ?><img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-8 h-8 rounded-lg object-contain border" style="border-color:var(--color-border)"><?php endif; ?>
                                <div class="flex-1 min-w-0"><p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($task['title']) ?></p><p class="text-xs" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?> &middot; $<?= number_format((float) $task['budget'], 0) ?></p></div>
                                <?= status_badge($task['status']) ?>
                            </div>
                            <div class="w-full h-1.5 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden"><div class="progress-bar h-full" style="width:<?= $task['status']==='submitted'?'75':'25' ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recommended Jobs -->
    <?php if (!empty($recommended)): ?>
    <div class="glass rounded-2xl p-6 reveal">
        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3"><div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(245,158,11,0.1)"><svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Recommended Jobs</h2></div>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-sm font-medium text-primary-600 hover:text-primary-700">Browse All &rarr;</a>
        </div>
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <?php foreach ($recommended as $job): ?>
                <div class="p-4 rounded-xl hover-lift" style="background:var(--color-bg);border:1px solid var(--color-border)">
                    <div class="flex items-center gap-2 mb-2"><?php if ($job['logo_image']): ?><img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-7 h-7 rounded-lg object-contain border" style="border-color:var(--color-border)"><?php endif; ?><span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?></span></div>
                    <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($job['title']) ?></p>
                    <p class="text-xs mt-1 line-clamp-2" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($job['description'] ?? '', 0, 100, '...')) ?></p>
                    <div class="flex items-center justify-between mt-3 pt-3 border-t" style="border-color:var(--color-border)"><span class="text-sm font-bold text-primary-600">$<?= number_format((float) $job['budget'], 0) ?></span><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-xs font-medium text-primary-600 hover:text-primary-700">Apply &rarr;</a></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- APPLICATIONS -->
<div class="dash-section" id="tab-applications">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">My Applications</h2>
    <?php if (empty($all_apps)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg><p class="mb-3">You haven't applied to any jobs yet.</p><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white">Browse Jobs</a></div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($all_apps as $app): ?>
                <div class="glass rounded-2xl p-5 hover-lift">
                    <div class="flex items-center gap-4">
                        <?php if ($app['logo_image']): ?><img src="<?= e(base_url('uploads/' . $app['logo_image'])) ?>" alt="" class="w-12 h-12 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php else: ?><div class="w-12 h-12 rounded-xl flex items-center justify-center text-indigo-600 font-bold border" style="background:rgba(99,102,241,0.1);border-color:var(--color-border)"><?= strtoupper(mb_substr($app['company_name'], 0, 1)) ?></div><?php endif; ?>
                        <div class="flex-1 min-w-0"><p class="font-semibold" style="color:var(--color-text-primary)"><?= e($app['title']) ?></p><p class="text-sm" style="color:var(--color-text-muted)"><?= e($app['company_name']) ?> &middot; $<?= number_format((float) $app['budget'], 2) ?></p><p class="text-xs" style="color:var(--color-text-placeholder)">Applied <?= date('M j, Y', strtotime($app['applied_at'])) ?></p></div>
                        <?= status_badge($app['status']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ONGOING -->
<div class="dash-section" id="tab-ongoing">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Ongoing Projects</h2>
    <?php if (empty($ongoing_tasks)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg><p>No ongoing projects.</p><a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-semibold rounded-xl text-white mt-3">Browse Jobs</a></div>
    <?php else: ?>
        <div class="space-y-4">
            <?php foreach ($ongoing_tasks as $task): ?>
                <div class="glass rounded-2xl p-6 hover-lift">
                    <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                        <div class="flex items-center gap-3"><?php if ($task['logo_image']): ?><img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php endif; ?><div><p class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></p><h3 class="text-lg font-bold" style="color:var(--color-text-primary)"><?= e($task['title']) ?></h3></div></div>
                        <?= status_badge($task['status']) ?>
                    </div>
                    <p class="text-sm mb-4 leading-relaxed" style="color:var(--color-text-secondary)"><?= e(mb_strimwidth($task['description'] ?? '', 0, 200, '...')) ?></p>
                    <div class="flex items-center gap-4 text-sm mb-4"><span style="color:var(--color-text-muted)">Budget: <strong class="text-primary-600">$<?= number_format((float) $task['budget'], 2) ?></strong></span><span style="color:var(--color-text-placeholder)">Assigned <?= date('M j', strtotime($task['assigned_at'])) ?></span></div>
                    <div class="w-full h-2 rounded-full bg-gray-200 dark:bg-gray-700 overflow-hidden mb-4"><div class="progress-bar h-full" style="width:<?= $task['status']==='submitted'?'75':'25' ?>%"></div></div>
                    <?php if ($task['status'] === 'assigned'): ?>
                        <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white">Submit Work</a>
                    <?php elseif ($task['submission_link']): ?>
                        <div class="flex items-center gap-2 text-sm" style="color:var(--color-text-muted)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>Submitted: <a href="<?= e($task['submission_link']) ?>" target="_blank" class="text-primary-600 hover:underline">View Link</a></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- COMPLETED -->
<div class="dash-section" id="tab-completed">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Completed Projects</h2>
    <?php if (empty($completed_list)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><p>No completed projects yet.</p></div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($completed_list as $task): ?>
                <div class="glass rounded-2xl p-5 hover-lift">
                    <div class="flex items-center gap-3 mb-3">
                        <?php if ($task['logo_image']): ?><img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border" style="border-color:var(--color-border)"><?php endif; ?>
                        <div class="flex-1 min-w-0"><p class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($task['title']) ?></p><p class="text-xs" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></p></div>
                        <?= status_badge('completed') ?>
                    </div>
                    <div class="flex items-center justify-between text-sm pt-3 border-t" style="border-color:var(--color-border)"><span style="color:var(--color-text-muted)">Budget: <strong class="text-primary-600">$<?= number_format((float) $task['budget'], 2) ?></strong></span><?php if ($task['paid_at']): ?><span class="flex items-center gap-1 text-emerald-600 font-medium"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>Paid <?= date('M j', strtotime($task['paid_at'])) ?></span><?php endif; ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- EARNINGS -->
<div class="dash-section" id="tab-earnings">
    <div class="glass rounded-2xl p-6 mb-6 relative overflow-hidden text-white" style="background:var(--gp)">
        <div class="absolute top-0 right-0 w-40 h-40 opacity-10"><svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
        <p class="text-sm opacity-80 font-medium">Total Earnings</p><p class="text-4xl font-extrabold mt-1">$<?= number_format($fl_stats['earnings'], 2) ?></p><p class="text-xs opacity-60 mt-1"><?= count($earnings) ?> completed payment<?= count($earnings) !== 1 ? 's' : '' ?></p>
    </div>
    <?php if (empty($earnings)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><p>No earnings recorded yet.</p></div>
    <?php else: ?>
        <div class="glass rounded-2xl overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left" style="border-color:var(--color-border);color:var(--color-text-muted)"><th class="p-4">Job</th><th class="p-4">Company</th><th class="p-4">Amount</th><th class="p-4">Status</th><th class="p-4">Date</th></tr></thead>
                <tbody><?php foreach ($earnings as $e): ?><tr class="border-b transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50" style="border-color:var(--color-border)"><td class="p-4 font-medium" style="color:var(--color-text-primary)"><?= e($e['job_title']) ?></td><td class="p-4" style="color:var(--color-text-muted)"><?= e($e['company_name']) ?></td><td class="p-4 font-bold text-emerald-600">$<?= number_format((float) $e['amount'], 2) ?></td><td class="p-4"><?= status_badge($e['status']) ?></td><td class="p-4" style="color:var(--color-text-placeholder)"><?= e($e['paid_at'] ?? '—') ?></td></tr><?php endforeach; ?></tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- PORTFOLIO -->
<div class="dash-section" id="tab-portfolio">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">My Portfolio</h2>
    <?php $portfolio_items = []; $all_projects = array_merge($ongoing_tasks, $completed_list); foreach ($all_projects as $pj) $portfolio_items[] = ['title'=>$pj['title'],'company'=>$pj['company_name'],'description'=>mb_strimwidth($pj['description'] ?? 'No description.', 0, 120, '...'),'budget'=>$pj['budget'],'completed'=>($pj['status'] ?? '')==='completed','image'=>$pj['logo_image'] ?? '']; ?>
    <?php if (empty($portfolio_items)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg><p>Your portfolio is empty. Complete projects to build it up!</p></div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($portfolio_items as $item): ?>
                <div class="glass rounded-2xl overflow-hidden hover-lift">
                    <?php if ($item['image']): ?><img src="<?= e(base_url('uploads/' . $item['image'])) ?>" alt="" class="w-full h-40 object-cover"><?php else: ?><div class="w-full h-40 flex items-center justify-center" style="background:rgba(99,102,241,0.05)"><svg class="w-12 h-12" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg></div><?php endif; ?>
                    <div class="p-4"><h3 class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($item['title']) ?></h3><p class="text-xs" style="color:var(--color-text-muted)"><?= e($item['company']) ?></p><div class="flex items-center justify-between mt-3 pt-3 border-t text-xs" style="border-color:var(--color-border)"><span class="font-bold text-primary-600">$<?= number_format((float) $item['budget'], 0) ?></span><?php if ($item['completed']): ?><span class="flex items-center gap-1 text-emerald-600 font-medium"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>Completed</span><?php else: ?><span class="flex items-center gap-1 text-amber-500 font-medium">In Progress</span><?php endif; ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- SKILLS -->
<div class="dash-section" id="tab-skills">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Skills & Certificates</h2>
    <?php if (!empty($fl_profile_skills)): ?>
        <div class="glass rounded-2xl p-6 hover-lift">
            <div class="flex items-center gap-3 mb-4"><div class="w-10 h-10 rounded-xl flex items-center justify-center" style="background:rgba(99,102,241,0.1)"><svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></div><h3 class="font-bold" style="color:var(--color-text-primary)">My Skills</h3></div>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($fl_profile_skills as $sid): ?>
                    <span class="badge-skill inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-xl" style="background:rgba(99,102,241,0.1);color:#4f46e5"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg><?= e($fl_skill_names[$sid] ?? 'Unknown') ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><p class="mb-3">No skills added yet.</p><a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white">Add Skills</a></div>
    <?php endif; ?>
</div>

<!-- MESSAGES -->
<div class="dash-section" id="tab-messages">
    <div class="glass rounded-2xl text-center py-16">
        <svg class="w-20 h-20 mx-auto mb-5" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <h3 class="text-xl font-bold mb-2" style="color:var(--color-text-primary)">Messages</h3><p class="mb-6" style="color:var(--color-text-muted)">Chat with companies about your active projects.</p>
        <a href="<?= e(base_url('chat/index.php')) ?>" class="btn-grad inline-flex items-center gap-2 px-6 py-3 text-sm font-semibold rounded-xl text-white shadow-lg"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>Open Messages</a>
    </div>
</div>

<!-- NOTIFICATIONS -->
<div class="dash-section" id="tab-notifications">
    <div class="flex items-center justify-between mb-5"><h2 class="text-xl font-bold" style="color:var(--color-text-primary)">Notifications</h2><?php if ($fl_notif_count > 0): ?><button type="button" onclick="markAllFlNotif()" class="text-sm font-medium text-primary-600 hover:text-primary-700">Mark all as read</button><?php endif; ?></div>
    <?php if (empty($fl_recent_notifs)): ?>
        <div class="glass rounded-2xl text-center py-16" style="color:var(--color-text-placeholder)"><svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg><p><?= __('notif.no_notifications') ?></p></div>
    <?php else: ?>
        <div class="space-y-2"><?php foreach ($fl_recent_notifs as $n): ?>
            <div class="glass rounded-xl p-4 flex items-start gap-3 <?= $n['is_read']?'':'border-l-4 border-l-primary-500' ?>" style="<?= $n['is_read']?'':'background:rgba(99,102,241,0.03)' ?>">
                <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
                <div class="flex-1 min-w-0"><p class="text-sm <?= $n['is_read']?'':'font-semibold' ?>" style="color:var(--color-text-primary)"><?= e($n['message']) ?></p><p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p></div>
                <?php if ($n['link']): ?><a href="<?= e(base_url($n['link'])) ?>" class="text-primary-600 hover:text-primary-700 flex-shrink-0"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg></a><?php endif; ?>
            </div>
        <?php endforeach; ?></div>
    <?php endif; ?>
</div>

<!-- SETTINGS -->
<div class="dash-section" id="tab-settings">
    <h2 class="text-xl font-bold mb-5" style="color:var(--color-text-primary)">Account Settings</h2>
    <div class="max-w-2xl space-y-4">
        <div class="glass rounded-2xl p-6 hover-lift"><h3 class="font-bold mb-2" style="color:var(--color-text-primary)">Profile</h3><p class="text-sm mb-4" style="color:var(--color-text-muted)">Manage your freelancer profile, skills, and portfolio.</p><a href="<?= e(base_url('freelancer/profile.php')) ?>" class="btn-grad inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl text-white">Edit Profile</a></div>
        <div class="glass rounded-2xl p-6 hover-lift"><h3 class="font-bold mb-2" style="color:var(--color-text-primary)">Password</h3><p class="text-sm mb-4" style="color:var(--color-text-muted)">Update your password to keep your account secure.</p><button class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)" onclick="alert('Password change feature coming soon.')">Change Password</button></div>
    </div>
</div>

</div><!-- end tab content -->

<script>
function switchTab(tabId){
    document.querySelectorAll('.dash-tab').forEach(function(t){t.classList.remove('active');});
    document.querySelectorAll('.dash-section').forEach(function(s){s.classList.remove('active');});
    var at=document.querySelector('.dash-tab[data-tab="'+tabId+'"]');
    var as=document.getElementById('tab-'+tabId);
    if(at)at.classList.add('active');
    if(as)as.classList.add('active');
    var tn=document.querySelector('.dash-tab');
    if(tn)window.scrollTo({top:tn.offsetTop-30,behavior:'smooth'});
}
document.querySelectorAll('.dash-tab').forEach(function(tab){tab.addEventListener('click',function(){switchTab(this.getAttribute('data-tab'));});});
function markAllFlNotif(){
    fetch('<?= e(base_url("api/notifications.php")) ?>',{method:'POST',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'mark_all_read',csrf_token:'<?= e(csrf_token()) ?>'})}).then(function(r){return r.json();}).then(function(d){if(d.success){document.querySelectorAll('#tab-notifications .glass').forEach(function(c){c.classList.remove('border-l-4','border-l-primary-500');});}}).catch(function(){});
}
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
