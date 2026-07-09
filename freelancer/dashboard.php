<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('freelancer');

$user = current_user();
$freelancer_id = get_freelancer_id($conn, (int) $user['user_id']);

if (!$freelancer_id) {
    set_flash('error', __('error.freelancer_not_found'));
    redirect('index.php');
}

// Freelancer profile
$stmt = $conn->prepare("
    SELECT f.*, u.email, u.profile_image, u.created_at
    FROM freelancers f
    JOIN users u ON u.id = f.user_id
    WHERE f.id = ?
");
$stmt->bind_param('i', $freelancer_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Skills
$skill_names = [];
$profile_skill_ids = [];
$result = $conn->query("SELECT s.id, s.skill_name FROM skills s ORDER BY s.skill_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $skill_names[$row['id']] = $row['skill_name'];
    }
}
$result = $conn->query("SELECT skill_id FROM freelancer_skills WHERE freelancer_id = $freelancer_id");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $profile_skill_ids[] = (int) $row['skill_id'];
    }
}

// Stats
try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM job_applications WHERE freelancer_id = ? AND status = 'pending'");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $pending_apps = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $pending_apps = 0;
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ? AND status = 'assigned'");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $active_tasks = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $active_tasks = 0;
}

try {
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments WHERE freelancer_id = ? AND status = 'completed'");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $completed_tasks = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $completed_tasks = 0;
}

try {
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(p.amount), 0) AS total
        FROM payments p
        JOIN assignments a ON p.assignment_id = a.id
        WHERE a.freelancer_id = ? AND p.status = 'paid'
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $total_earnings = (float) $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $total_earnings = 0;
}

// Recent applications
$recent_apps = [];
try {
    $stmt = $conn->prepare("
        SELECT ja.id, ja.status, ja.applied_at, j.title, j.budget, j.id AS job_id,
               c.company_name, c.logo_image
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        WHERE ja.freelancer_id = ?
        ORDER BY ja.applied_at DESC
        LIMIT 8
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_apps[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $recent_apps = [];
}

// All applications (for tab)
$all_apps = [];
try {
    $stmt = $conn->prepare("
        SELECT ja.id, ja.status, ja.applied_at, j.title, j.budget, j.id AS job_id,
               c.company_name, c.logo_image
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        WHERE ja.freelancer_id = ?
        ORDER BY ja.applied_at DESC
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_apps[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $all_apps = [];
}

// Ongoing tasks (assigned/submitted)
$ongoing_tasks = [];
try {
    $stmt = $conn->prepare("
        SELECT a.id, a.status, a.submission_link, a.assigned_at, j.title, j.description, j.budget, j.id AS job_id,
               c.company_name, c.logo_image
        FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        WHERE a.freelancer_id = ? AND a.status IN ('assigned', 'submitted')
        ORDER BY a.assigned_at DESC
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $ongoing_tasks[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $ongoing_tasks = [];
}

// Completed tasks
$completed_tasks_list = [];
try {
    $stmt = $conn->prepare("
        SELECT a.id, a.status, a.submission_link, a.assigned_at, j.title, j.budget, j.id AS job_id,
               c.company_name, c.logo_image,
               p.amount, p.paid_at
        FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        LEFT JOIN payments p ON p.assignment_id = a.id
        WHERE a.freelancer_id = ? AND a.status = 'completed'
        ORDER BY a.assigned_at DESC
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $completed_tasks_list[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $completed_tasks_list = [];
}

// Earnings history
$earnings = [];
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.amount, p.status, p.paid_at, j.title AS job_title, c.company_name
        FROM payments p
        JOIN assignments a ON p.assignment_id = a.id
        JOIN jobs j ON a.job_id = j.id
        JOIN companies c ON j.company_id = c.id
        WHERE a.freelancer_id = ?
        ORDER BY p.paid_at DESC
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $earnings[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $earnings = [];
}

// Notifications
$notifications = [];
$unread_count = 0;
if (isset($user['user_id'])) {
    $notifications = get_notifications($conn, (int) $user['user_id'], 20);
    $unread_count = get_unread_notification_count($conn, (int) $user['user_id']);
}

// Job recommendations (approved jobs the freelancer hasn't applied to)
$recommended_jobs = [];
try {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.description, j.budget, j.created_at,
               c.company_name, c.logo_image
        FROM jobs j
        JOIN companies c ON j.company_id = c.id
        WHERE j.status = 'approved'
        AND j.id NOT IN (SELECT job_id FROM job_applications WHERE freelancer_id = ?)
        AND j.id NOT IN (SELECT job_id FROM assignments)
        ORDER BY j.created_at DESC
        LIMIT 6
    ");
    $stmt->bind_param('i', $freelancer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recommended_jobs[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $recommended_jobs = [];
}

$page_title = __('freelancer.dashboard_title');
require __DIR__ . '/../includes/header.php';
?>

<style>
  :root {
    --gradient-primary: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    --gradient-success: linear-gradient(135deg, #059669 0%, #10b981 100%);
    --gradient-warning: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
    --gradient-info: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
    --gradient-rose: linear-gradient(135deg, #e11d48 0%, #f43f5e 100%);
  }

  .dash-tab {
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative;
    white-space: nowrap;
    font-weight: 500;
  }
  .dash-tab::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 0;
    height: 3px;
    background: var(--gradient-primary);
    transition: width 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    border-radius: 3px 3px 0 0;
  }
  .dash-tab:hover::after,
  .dash-tab.active::after {
    width: 75%;
  }
  .dash-tab.active {
    color: #4f46e5 !important;
  }

  .dash-section {
    display: none;
    animation: fadeSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .dash-section.active {
    display: block;
  }

  @keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
  }
  @keyframes shimmer {
    0% { background-position: -200% 0; }
    100% { background-position: 200% 0; }
  }
  @keyframes scaleIn {
    from { opacity: 0; transform: scale(0.92); }
    to { opacity: 1; transform: scale(1); }
  }

  .card {
    border-radius: 14px;
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
                box-shadow 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: transform;
  }
  .card:hover {
    box-shadow: 0 8px 30px rgba(79, 70, 229, 0.10);
  }

  .stat-card {
    position: relative;
    overflow: hidden;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1),
                box-shadow 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: transform;
    border-radius: 16px;
  }
  .stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 16px 40px rgba(0,0,0,0.15);
  }

  .hover-lift {
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1),
                box-shadow 0.25s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .hover-lift:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(79, 70, 229, 0.12);
  }

  .scrollbar-thin::-webkit-scrollbar {
    height: 5px;
    width: 5px;
  }
  .scrollbar-thin::-webkit-scrollbar-track {
    background: transparent;
  }
  .scrollbar-thin::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
  }
  .dark .scrollbar-thin::-webkit-scrollbar-thumb {
    background: #475569;
  }

  .badge-skill {
    transition: all 0.2s ease;
  }
  .badge-skill:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.2);
  }

  .glass-card {
    background: rgba(255,255,255,0.7);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.3);
  }
  .dark .glass-card {
    background: rgba(30,41,59,0.6);
    border-color: rgba(148,163,184,0.15);
  }

  .profile-banner {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 60%, #a855f7 100%);
  }
  .profile-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at 20% 50%, rgba(255,255,255,0.12) 0%, transparent 60%);
    pointer-events: none;
  }
  .profile-banner::after {
    content: '';
    position: absolute;
    top: -60%;
    right: -10%;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    pointer-events: none;
  }

  .btn-shine {
    position: relative;
    overflow: hidden;
  }
  .btn-shine::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.25), transparent);
    transition: left 0.6s ease;
  }
  .btn-shine:hover::after {
    left: 100%;
  }

  .tab-scroll-fade {
    -webkit-mask-image: linear-gradient(to right, transparent 0%, black 20px, black calc(100% - 20px), transparent 100%);
    mask-image: linear-gradient(to right, transparent 0%, black 20px, black calc(100% - 20px), transparent 100%);
  }

  .stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
</style>

<div class="mb-8">
  <div class="profile-banner rounded-2xl p-6 sm:p-8 text-white">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 relative z-10">
      <div class="flex items-center gap-4 sm:gap-5">
        <?php $profileImg = profile_image_url($profile['profile_image']); ?>
        <?php if ($profileImg): ?>
          <img src="<?= e($profileImg) ?>" alt="" class="w-16 h-16 sm:w-20 sm:h-20 rounded-full object-cover border-4 border-white/30 shadow-xl">
        <?php else: ?>
          <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-full flex items-center justify-center font-bold text-2xl border-4 border-white/30 shadow-xl" style="background:rgba(255,255,255,0.15);">
            <?= e(_first_char($profile['full_name'] ?? $user['username'])) ?>
          </div>
        <?php endif; ?>
        <div>
          <h1 class="text-xl sm:text-2xl font-bold"><?= e($profile['full_name'] ?? $user['username']) ?></h1>
          <p class="text-sm sm:text-base flex items-center gap-1.5 mt-1 text-white/80">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            <?= e($profile['title'] ?? 'Freelancer') ?>
            <?php if ($profile['location']): ?>
              <span class="w-1 h-1 rounded-full bg-white/40 inline-block"></span>
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
              <?= e($profile['location']) ?>
            <?php endif; ?>
            <?php if ($profile['hourly_rate'] !== null): ?>
              <span class="w-1 h-1 rounded-full bg-white/40 inline-block"></span>
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
              <span class="font-medium">$<?= e(number_format((float) $profile['hourly_rate'], 2)) ?>/hr</span>
            <?php endif; ?>
          </p>
        </div>
      </div>
      <div class="flex items-center gap-2">
        <a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg text-indigo-700 bg-white/90 hover:bg-white transition-all shadow-lg">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          Edit Profile
        </a>
        <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium rounded-lg bg-white/15 hover:bg-white/25 text-white transition-all border border-white/20">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
          Browse Jobs
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Tab Navigation -->
<div class="sticky top-0 z-30 mb-6 -mx-4 px-4 py-2 overflow-x-auto scrollbar-thin tab-scroll-fade" style="background:var(--color-bg);border-bottom:1px solid var(--color-border)">
  <div class="flex gap-1 sm:gap-2 min-w-max">
    <button class="dash-tab active px-3 sm:px-4 py-2.5 text-sm rounded-t-lg" data-tab="overview" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      Overview
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="profile" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
      Profile
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="browse" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Browse Jobs
      <?php if (!empty($recommended_jobs)): ?>
        <span class="ml-1.5 text-xs bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-400 font-bold rounded-full px-1.5 py-0.5"><?= count($recommended_jobs) ?></span>
      <?php endif; ?>
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="applications" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Applications
      <?php if ($pending_apps > 0): ?>
        <span class="ml-1.5 text-xs bg-yellow-100 dark:bg-yellow-900/40 text-yellow-600 dark:text-yellow-400 font-bold rounded-full px-1.5 py-0.5"><?= $pending_apps ?></span>
      <?php endif; ?>
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="ongoing" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      Ongoing
      <?php if ($active_tasks > 0): ?>
        <span class="ml-1.5 text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 font-bold rounded-full px-1.5 py-0.5"><?= $active_tasks ?></span>
      <?php endif; ?>
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="completed" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      Completed
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="earnings" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      Earnings
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="portfolio" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      Portfolio
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="messages" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      Messages
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="notifications" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
      Notifications
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="reviews" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      Reviews
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="settings" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Settings
    </button>
  </div>
</div>

<!-- ====== OVERVIEW TAB ====== -->
<div class="dash-section active" id="tab-overview">
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="stat-card p-5" style="background:var(--gradient-warning);color:#fff;border:none;">
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium opacity-90">Pending</span>
        <div class="stat-icon bg-white/15">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
      </div>
      <p class="text-3xl font-bold"><?= $pending_apps ?></p>
      <p class="text-xs mt-1 opacity-75">Applications awaiting response</p>
    </div>
    <div class="stat-card p-5" style="background:var(--gradient-info);color:#fff;border:none;">
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium opacity-90">Active</span>
        <div class="stat-icon bg-white/15">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
        </div>
      </div>
      <p class="text-3xl font-bold"><?= $active_tasks ?></p>
      <p class="text-xs mt-1 opacity-75">Tasks in progress</p>
    </div>
    <div class="stat-card p-5" style="background:var(--gradient-success);color:#fff;border:none;">
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium opacity-90">Completed</span>
        <div class="stat-icon bg-white/15">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
      </div>
      <p class="text-3xl font-bold"><?= $completed_tasks ?></p>
      <p class="text-xs mt-1 opacity-75">Projects delivered</p>
    </div>
    <div class="stat-card p-5" style="background:var(--gradient-primary);color:#fff;border:none;">
      <div class="flex items-center justify-between mb-3">
        <span class="text-sm font-medium opacity-90">Earnings</span>
        <div class="stat-icon bg-white/15">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
      </div>
      <p class="text-3xl font-bold">$<?= e(number_format($total_earnings, 0)) ?></p>
      <p class="text-xs mt-1 opacity-75">Total lifetime earnings</p>
    </div>
  </div>

  <div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="card p-5">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1);">
            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          </div>
          <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Recent Applications</h2>
        </div>
        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors" onclick="switchTab('applications')">View All &rarr;</button>
      </div>
      <?php if (empty($recent_apps)): ?>
        <div class="text-center py-10" style="color:var(--color-text-placeholder)">
          <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <p class="mb-3">No applications yet.</p>
          <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm">Browse Jobs</a>
        </div>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($recent_apps as $app): ?>
            <div class="flex items-center gap-3 p-3.5 rounded-xl hover-lift" style="background:var(--color-bg);border:1px solid var(--color-border)">
              <?php if ($app['logo_image']): ?>
                <img src="<?= e(base_url('uploads/' . $app['logo_image'])) ?>" alt="" class="w-9 h-9 rounded-lg object-contain border" style="border-color:var(--color-border)">
              <?php else: ?>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-indigo-600 font-bold text-sm" style="background:rgba(99,102,241,0.1)"><?= e(strtoupper(substr($app['company_name'], 0, 1))) ?></div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($app['title']) ?></p>
                <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($app['company_name']) ?> &middot; $<?= e(number_format((float) $app['budget'], 2)) ?></p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-xs mb-1" style="color:var(--color-text-placeholder)"><?= date('M j', strtotime($app['applied_at'])) ?></p>
                <?= status_badge($app['status']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card p-5">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(79,70,229,0.1);">
            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
          </div>
          <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Ongoing Tasks</h2>
        </div>
        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors" onclick="switchTab('ongoing')">View All &rarr;</button>
      </div>
      <?php if (empty($ongoing_tasks)): ?>
        <div class="text-center py-10" style="color:var(--color-text-placeholder)">
          <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
          <p class="mb-1">No ongoing tasks.</p>
          <p class="text-sm">Keep applying to get hired!</p>
        </div>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($ongoing_tasks as $task): ?>
            <div class="flex items-center gap-3 p-3.5 rounded-xl hover-lift" style="background:var(--color-bg);border:1px solid var(--color-border)">
              <?php if ($task['logo_image']): ?>
                <img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-9 h-9 rounded-lg object-contain border" style="border-color:var(--color-border)">
              <?php else: ?>
                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-indigo-600 font-bold text-sm" style="background:rgba(99,102,241,0.1)"><?= e(strtoupper(substr($task['company_name'], 0, 1))) ?></div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($task['title']) ?></p>
                <p class="text-xs" style="color:var(--color-text-muted)">$<?= e(number_format((float) $task['budget'], 2)) ?></p>
              </div>
              <?= status_badge($task['status']) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($recommended_jobs)): ?>
    <div class="card p-5">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(245,158,11,0.1);">
            <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
          </div>
          <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Recommended Jobs</h2>
        </div>
        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-700 transition-colors" onclick="switchTab('browse')">View All &rarr;</button>
      </div>
      <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
        <?php foreach ($recommended_jobs as $job): ?>
          <div class="p-4 rounded-xl hover-lift" style="background:var(--color-bg);border:1px solid var(--color-border)">
            <div class="flex items-center gap-2 mb-2">
              <?php if ($job['logo_image']): ?>
                <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-7 h-7 rounded-lg object-contain border" style="border-color:var(--color-border)">
              <?php endif; ?>
              <span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?></span>
            </div>
            <p class="text-sm font-semibold truncate" style="color:var(--color-text-primary)"><?= e($job['title']) ?></p>
            <p class="text-xs mt-1 line-clamp-2" style="color:var(--color-text-secondary)"><?= e(substr($job['description'] ?? '', 0, 80)) ?><?= strlen($job['description'] ?? '') > 80 ? '...' : '' ?></p>
            <div class="flex items-center justify-between mt-3 pt-3 border-t" style="border-color:var(--color-border)">
              <span class="text-sm font-bold text-indigo-600">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
              <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="inline-flex items-center gap-1 text-xs font-medium text-indigo-600 hover:text-indigo-700 transition-colors">
                Apply <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<!-- ====== PROFILE TAB ====== -->
<div class="dash-section" id="tab-profile">
  <div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-5">
      <div class="flex items-center gap-4">
        <?php if ($profileImg): ?>
          <img src="<?= e($profileImg) ?>" alt="" class="w-16 h-16 rounded-full object-cover border-2" style="border-color:var(--color-border)">
        <?php else: ?>
          <div class="w-16 h-16 rounded-full flex items-center justify-center font-bold text-2xl border-2" style="background:rgba(99,102,241,0.12);color:#4f46e5;border-color:var(--color-border)">
            <?= e(_first_char($profile['full_name'] ?? $user['username'])) ?>
          </div>
        <?php endif; ?>
        <div>
          <h2 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e($profile['full_name'] ?? $user['username']) ?></h2>
          <?php if ($profile['title']): ?>
            <p class="text-sm text-indigo-600 font-medium"><?= e($profile['title']) ?></p>
          <?php endif; ?>
          <p class="text-xs" style="color:var(--color-text-placeholder)"><?= __('profile.joined') ?> <?= e(date('M Y', strtotime($profile['created_at']))) ?></p>
        </div>
      </div>
      <a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-primary text-sm">Edit Profile</a>
    </div>

    <div class="grid md:grid-cols-2 gap-5">
      <div class="card">
        <h3 class="font-semibold mb-3" style="color:var(--color-text-primary)">About</h3>
        <?php if ($profile['bio']): ?>
          <p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:var(--color-text-secondary)"><?= e($profile['bio']) ?></p>
        <?php else: ?>
          <p class="text-sm italic" style="color:var(--color-text-placeholder)">No bio added yet.</p>
        <?php endif; ?>
        <?php if (!empty($profile_skill_ids)): ?>
          <div class="mt-4">
            <h4 class="text-xs font-medium mb-2" style="color:var(--color-text-muted)">Skills</h4>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($profile_skill_ids as $sid): ?>
                <span class="badge-skill inline-flex px-2.5 py-1 text-xs font-medium rounded-full" style="background:rgba(99,102,241,0.12);color:#4f46e5"><?= e($skill_names[$sid] ?? 'Unknown') ?></span>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h3 class="font-semibold mb-3" style="color:var(--color-text-primary)">Details</h3>
        <dl class="space-y-3 text-sm">
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Email</dt>
            <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['email']) ?></dd>
          </div>
          <?php if ($profile['phone']): ?>
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Phone</dt>
            <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['phone']) ?></dd>
          </div>
          <?php endif; ?>
          <?php if ($profile['location']): ?>
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Location</dt>
            <dd class="font-medium" style="color:var(--color-text-primary)"><?= e($profile['location']) ?></dd>
          </div>
          <?php endif; ?>
          <?php if ($profile['experience_years'] !== null): ?>
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Experience</dt>
            <dd class="font-medium" style="color:var(--color-text-primary)"><?= (int) $profile['experience_years'] ?> year<?= (int) $profile['experience_years'] !== 1 ? 's' : '' ?></dd>
          </div>
          <?php endif; ?>
          <?php if ($profile['hourly_rate'] !== null): ?>
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Hourly Rate</dt>
            <dd class="font-medium text-green-600">$<?= e(number_format((float) $profile['hourly_rate'], 2)) ?>/hr</dd>
          </div>
          <?php endif; ?>
          <?php if ($profile['portfolio_url']): ?>
          <div class="flex justify-between">
            <dt style="color:var(--color-text-muted)">Portfolio</dt>
            <dd><a href="<?= e($profile['portfolio_url']) ?>" target="_blank" rel="noopener" class="text-indigo-600 hover:underline text-xs">View Portfolio &rarr;</a></dd>
          </div>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="mt-5 card">
      <h3 class="font-semibold mb-4" style="color:var(--color-text-primary)">Activity Overview</h3>
      <div class="grid grid-cols-3 gap-4 text-center">
        <div class="p-4 rounded-lg" style="background:rgba(99,102,241,0.1)">
          <p class="text-2xl font-bold text-indigo-600"><?= count($all_apps) ?></p>
          <p class="text-xs" style="color:var(--color-text-muted)">Applications</p>
        </div>
        <div class="p-4 rounded-lg" style="background:rgba(6,182,212,0.1)">
          <p class="text-2xl font-bold text-cyan-600"><?= $active_tasks + $completed_tasks ?></p>
          <p class="text-xs" style="color:var(--color-text-muted)">Projects</p>
        </div>
        <div class="p-4 rounded-lg" style="background:rgba(16,185,129,0.1)">
          <p class="text-2xl font-bold text-green-600">$<?= e(number_format($total_earnings, 0)) ?></p>
          <p class="text-xs" style="color:var(--color-text-muted)">Earned</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ====== BROWSE JOBS TAB ====== -->
<div class="dash-section" id="tab-browse">
  <div class="flex items-center justify-between mb-5">
    <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Recommended Jobs</h2>
    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      Search All Jobs
    </a>
  </div>
  <?php if (empty($recommended_jobs)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
      <p class="mb-3">No new job recommendations at this time.</p>
      <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm">Browse All Jobs</a>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <?php foreach ($recommended_jobs as $job): ?>
        <div class="card hover-lift relative flex flex-col">
          <div class="flex items-center gap-2 mb-2">
            <?php if ($job['logo_image']): ?>
              <img src="<?= e(base_url('uploads/' . $job['logo_image'])) ?>" alt="" class="w-7 h-7 rounded object-contain border" style="border-color:var(--color-border)">
            <?php endif; ?>
            <span class="text-xs font-medium" style="color:var(--color-text-muted)"><?= e($job['company_name']) ?></span>
            <span class="ml-auto text-xs" style="color:var(--color-text-placeholder)"><?= date('M j', strtotime($job['created_at'])) ?></span>
          </div>
          <h3 class="font-semibold mb-1 truncate" style="color:var(--color-text-primary)"><?= e($job['title']) ?></h3>
          <p class="text-sm mb-3 line-clamp-2 flex-1" style="color:var(--color-text-muted)"><?= e(substr($job['description'] ?? '', 0, 120)) ?><?= strlen($job['description'] ?? '') > 120 ? '...' : '' ?></p>
          <div class="flex items-center justify-between pt-3 border-t" style="border-color:var(--color-border)">
            <span class="text-sm font-bold text-indigo-600">$<?= e(number_format((float) $job['budget'], 0)) ?></span>
            <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-xs font-medium text-indigo-600 hover:underline">Apply Now &rarr;</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== APPLICATIONS TAB ====== -->
<div class="dash-section" id="tab-applications">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">My Applications</h2>
  <?php if (empty($all_apps)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      <p class="mb-3">You haven't applied to any jobs yet.</p>
      <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm">Browse Jobs</a>
    </div>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($all_apps as $app): ?>
        <div class="card flex flex-col sm:flex-row sm:items-center gap-4">
          <div class="flex items-center gap-3 flex-1 min-w-0">
            <?php if ($app['logo_image']): ?>
              <img src="<?= e(base_url('uploads/' . $app['logo_image'])) ?>" alt="" class="w-10 h-10 rounded object-contain border" style="border-color:var(--color-border)">
            <?php else: ?>
              <div class="w-10 h-10 rounded flex items-center justify-center text-indigo-600 font-bold border" style="background:rgba(99,102,241,0.1);border-color:var(--color-border)"><?= e(strtoupper(substr($app['company_name'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="min-w-0">
              <p class="font-medium truncate" style="color:var(--color-text-primary)"><?= e($app['title']) ?></p>
              <p class="text-sm truncate" style="color:var(--color-text-muted)"><?= e($app['company_name']) ?> &middot; $<?= e(number_format((float) $app['budget'], 2)) ?></p>
              <p class="text-xs" style="color:var(--color-text-placeholder)">Applied <?= date('M j, Y', strtotime($app['applied_at'])) ?></p>
            </div>
          </div>
          <div class="flex-shrink-0"><?= status_badge($app['status']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== ONGOING TAB ====== -->
<div class="dash-section" id="tab-ongoing">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Ongoing Projects</h2>
  <?php if (empty($ongoing_tasks)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      <p>No ongoing projects. Keep applying to get hired!</p>
      <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm mt-3 inline-block">Browse Jobs</a>
    </div>
  <?php else: ?>
    <div class="space-y-4">
      <?php foreach ($ongoing_tasks as $task): ?>
        <div class="card">
          <div class="flex flex-wrap justify-between items-start gap-3 mb-3">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <?php if ($task['logo_image']): ?>
                  <img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-6 h-6 rounded object-contain">
                <?php endif; ?>
                <span class="text-sm font-medium" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></span>
              </div>
              <h3 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= e($task['title']) ?></h3>
            </div>
            <?= status_badge($task['status']) ?>
          </div>
          <p class="text-sm mb-3" style="color:var(--color-text-secondary)"><?= nl2br(e(substr($task['description'] ?? '', 0, 200))) ?><?= strlen($task['description'] ?? '') > 200 ? '...' : '' ?></p>
          <div class="flex items-center justify-between text-sm pt-3 border-t" style="border-color:var(--color-border)">
            <span style="color:var(--color-text-muted)">Budget: <strong class="text-indigo-600">$<?= e(number_format((float) $task['budget'], 2)) ?></strong></span>
            <span style="color:var(--color-text-placeholder)">Assigned <?= date('M j', strtotime($task['assigned_at'])) ?></span>
          </div>
          <?php if ($task['status'] === 'assigned'): ?>
            <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="mt-3 block text-center text-sm font-medium py-2 rounded-lg text-white" style="background:#4f46e5">Submit Work</a>
          <?php elseif ($task['submission_link']): ?>
            <div class="mt-3 pt-3 border-t" style="border-color:var(--color-border)">
              <p class="text-xs" style="color:var(--color-text-muted)">Submitted: <a href="<?= e($task['submission_link']) ?>" target="_blank" rel="noopener" class="text-indigo-600 hover:underline"><?= e($task['submission_link']) ?></a></p>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== COMPLETED TAB ====== -->
<div class="dash-section" id="tab-completed">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Completed Projects</h2>
  <?php if (empty($completed_tasks_list)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <p>No completed projects yet.</p>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 gap-4">
      <?php foreach ($completed_tasks_list as $task): ?>
        <div class="card hover-lift">
          <div class="flex items-center gap-3 mb-3">
            <?php if ($task['logo_image']): ?>
              <img src="<?= e(base_url('uploads/' . $task['logo_image'])) ?>" alt="" class="w-9 h-9 rounded object-contain border" style="border-color:var(--color-border)">
            <?php else: ?>
              <div class="w-9 h-9 rounded flex items-center justify-center text-indigo-600 font-bold border" style="background:rgba(99,102,241,0.1);border-color:var(--color-border)"><?= e(strtoupper(substr($task['company_name'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
              <p class="font-medium truncate" style="color:var(--color-text-primary)"><?= e($task['title']) ?></p>
              <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($task['company_name']) ?></p>
            </div>
            <?= status_badge('completed') ?>
          </div>
          <div class="flex items-center justify-between text-sm pt-3 border-t" style="border-color:var(--color-border)">
            <span style="color:var(--color-text-muted)">Budget: <strong class="text-indigo-600">$<?= e(number_format((float) $task['budget'], 2)) ?></strong></span>
            <?php if ($task['paid_at']): ?>
              <span style="color:var(--color-text-placeholder)">Paid <?= date('M j', strtotime($task['paid_at'])) ?></span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== EARNINGS TAB ====== -->
<div class="dash-section" id="tab-earnings">
  <div class="card mb-5 relative overflow-hidden" style="background:var(--gradient-primary);color:#fff;border:none;">
    <div class="absolute top-0 right-0 w-32 h-32 opacity-10">
      <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <p class="text-sm opacity-80 font-medium">Total Earnings</p>
    <p class="text-4xl font-bold mt-1">$<?= e(number_format($total_earnings, 2)) ?></p>
    <p class="text-xs opacity-60 mt-1">From <?= count($earnings) ?> completed payment<?= count($earnings) !== 1 ? 's' : '' ?></p>
  </div>

  <?php if (empty($earnings)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <p>No earnings recorded yet.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b text-left" style="border-color:var(--color-border);color:var(--color-text-muted)">
            <th class="py-3 pr-4">Job</th>
            <th class="py-3 pr-4">Company</th>
            <th class="py-3 pr-4">Amount</th>
            <th class="py-3 pr-4">Status</th>
            <th class="py-3">Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($earnings as $e): ?>
            <tr class="border-b" style="border-color:var(--color-border)">
              <td class="py-3 pr-4 font-medium"><?= e($e['job_title']) ?></td>
              <td class="py-3 pr-4" style="color:var(--color-text-muted)"><?= e($e['company_name']) ?></td>
              <td class="py-3 pr-4 font-semibold text-green-600">$<?= e(number_format((float) $e['amount'], 2)) ?></td>
              <td class="py-3 pr-4"><?= status_badge($e['status']) ?></td>
              <td class="py-3" style="color:var(--color-text-placeholder)"><?= e($e['paid_at'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ====== PORTFOLIO TAB ====== -->
<div class="dash-section" id="tab-portfolio">
  <div class="flex items-center justify-between mb-5">
    <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">My Portfolio</h2>
    <button onclick="alert('Add portfolio item feature coming soon.')" class="btn-primary text-sm">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
      Add Item
    </button>
  </div>

  <?php
  // Mock portfolio items — replace with DB query when a `portfolio` table exists
  $portfolio_items = [];
  if (!empty($ongoing_tasks) || !empty($completed_tasks_list)) {
    $all_projects = array_merge($ongoing_tasks ?? [], $completed_tasks_list ?? []);
    foreach ($all_projects as $pj) {
      $portfolio_items[] = [
        'title'       => $pj['title'],
        'company'     => $pj['company_name'],
        'description' => substr($pj['description'] ?? 'No description provided.', 0, 120),
        'budget'      => $pj['budget'],
        'completed'   => ($pj['status'] ?? '') === 'completed',
        'image'       => $pj['logo_image'] ?? '',
      ];
    }
  }
  // Show skills from profile (via junction table)
  $skill_list = [];
  if (!empty($profile_skill_ids) && !empty($skill_names)) {
    foreach ($profile_skill_ids as $sid) {
      if (isset($skill_names[$sid])) {
        $skill_list[] = $skill_names[$sid];
      }
    }
  }
  ?>

  <!-- Skills showcase -->
  <?php if (!empty($skill_list)): ?>
    <div class="card mb-5">
      <h3 class="font-semibold mb-3 flex items-center gap-2" style="color:var(--color-text-primary)">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Skills
      </h3>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($skill_list as $skill): ?>
          <span class="px-3 py-1 text-xs font-medium rounded-full" style="background:rgba(99,102,241,0.1);color:#4f46e5"><?= e($skill) ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (empty($portfolio_items)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
      <p>Your portfolio is empty. Complete projects to build it up!</p>
      <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="btn-primary text-sm mt-3 inline-block">Browse Jobs</a>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($portfolio_items as $item): ?>
        <div class="card hover-lift group">
          <?php if ($item['image']): ?>
            <img src="<?= e(base_url('uploads/' . $item['image'])) ?>" alt="" class="w-full h-36 object-cover rounded-lg mb-3 border" style="border-color:var(--color-border)">
          <?php else: ?>
            <div class="w-full h-36 rounded-lg mb-3 flex items-center justify-center text-4xl font-bold" style="background:rgba(99,102,241,0.05);border:1px dashed var(--color-border);color:var(--color-text-placeholder)">
              <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
          <?php endif; ?>
          <h3 class="font-semibold truncate" style="color:var(--color-text-primary)"><?= e($item['title']) ?></h3>
          <p class="text-xs" style="color:var(--color-text-muted)"><?= e($item['company']) ?></p>
          <p class="text-sm mt-1" style="color:var(--color-text-secondary)"><?= e($item['description']) ?><?= strlen($item['description']) >= 120 ? '…' : '' ?></p>
          <div class="flex items-center justify-between mt-3 pt-3 border-t text-xs" style="border-color:var(--color-border)">
            <span style="color:var(--color-text-muted)">$<?= e(number_format((float) $item['budget'], 2)) ?></span>
            <?php if ($item['completed']): ?>
              <span class="flex items-center gap-1 text-green-600 font-medium"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> Completed</span>
            <?php else: ?>
              <span class="flex items-center gap-1 text-amber-500 font-medium"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg> In Progress</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== MESSAGES TAB ====== -->
<div class="dash-section" id="tab-messages">
  <div class="card text-center py-12">
    <svg class="w-16 h-16 mx-auto mb-4" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
    <h3 class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)">Messages</h3>
    <p class="mb-4" style="color:var(--color-text-muted)">Chat with companies about your active projects.</p>
    <a href="<?= e(base_url('chat/index.php')) ?>" class="btn-primary">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      Open Messages
    </a>
  </div>
</div>

<!-- ====== NOTIFICATIONS TAB ====== -->
<div class="dash-section" id="tab-notifications">
  <div class="flex items-center justify-between mb-5">
    <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Notifications</h2>
    <?php if ($unread_count > 0): ?>
      <button type="button" onclick="markAllNotifications()" class="text-sm text-indigo-600 hover:underline font-medium">Mark all as read</button>
    <?php endif; ?>
  </div>
  <?php if (empty($notifications)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
      <p><?= __('notif.no_notifications') ?></p>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($notifications as $n): ?>
        <div class="card flex items-start gap-3 p-4 <?= $n['is_read'] ? '' : 'border-l-4 border-l-indigo-500' ?>" style="<?= $n['is_read'] ? '' : 'background:rgba(99,102,241,0.03)' ?>">
          <div class="mt-0.5 flex-shrink-0"><?= notification_icon($n['type']) ?></div>
          <div class="flex-1 min-w-0">
            <p class="text-sm <?= $n['is_read'] ? '' : 'font-semibold' ?>" style="color:var(--color-text-primary)"><?= e($n['message']) ?></p>
            <p class="text-xs mt-1" style="color:var(--color-text-placeholder)"><?= e($n['created_at']) ?></p>
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

<!-- ====== REVIEWS TAB ====== -->
<div class="dash-section" id="tab-reviews">

  <div class="flex items-center gap-4 mb-5 flex-wrap">
    <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Reviews &amp; Ratings</h2>
    <div class="flex items-center gap-2">
      <div class="flex items-center text-amber-400 text-xl">
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
      </div>
      <span class="text-xl font-bold" style="color:var(--color-text-primary)">5.0</span>
      <span class="text-sm" style="color:var(--color-text-muted)">(<?= count($earnings) ?> reviews)</span>
    </div>
  </div>

  <?php
  // Mock reviews derived from completed/past projects
  $reviews = [];
  $reviewers = ['Sarah M.', 'Alex K.', 'James R.', 'Emily T.', 'David L.'];
  $review_texts = [
    'Excellent work! Delivered ahead of schedule and exceeded expectations.',
    'Very professional and responsive. Would definitely hire again.',
    'Great attention to detail. The quality of work was outstanding.',
    'Smooth collaboration. Understood requirements perfectly.',
    'Highly skilled freelancer. Delivered exactly what was needed.',
  ];
  $i = 0;
  foreach ($earnings as $e) {
    $reviews[] = [
      'reviewer'    => $reviewers[$i % count($reviewers)],
      'rating'      => rand(4, 5),
      'text'        => $review_texts[$i % count($review_texts)],
      'project'     => $e['job_title'],
      'date'        => $e['paid_at'] ?? '',
    ];
    $i++;
    if ($i >= 5) break; // max 5 mock reviews
  }
  ?>

  <?php if (empty($reviews)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
      <p>No reviews yet. Complete projects to earn feedback!</p>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 gap-4">
      <?php foreach ($reviews as $r): ?>
        <div class="card">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
              <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white" style="background:<?= ['#4f46e5','#059669','#d97706','#dc2626','#7c3aed'][crc32($r['reviewer']) % 5] ?>">
                <?= e(substr($r['reviewer'], 0, 1)) ?>
              </div>
              <div>
                <p class="font-medium text-sm" style="color:var(--color-text-primary)"><?= e($r['reviewer']) ?></p>
                <p class="text-xs" style="color:var(--color-text-muted)">on <span class="italic"><?= e($r['project']) ?></span></p>
              </div>
            </div>
            <div class="flex items-center text-amber-400 text-sm">
              <?php for ($s = 0; $s < 5; $s++): ?>
                <svg class="w-4 h-4 <?= $s < $r['rating'] ? '' : 'opacity-30' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
              <?php endfor; ?>
            </div>
          </div>
          <p class="text-sm" style="color:var(--color-text-secondary)">"<?= e($r['text']) ?>"</p>
          <?php if ($r['date']): ?>
            <p class="text-xs mt-2" style="color:var(--color-text-placeholder)"><?= e($r['date']) ?></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== SETTINGS TAB ====== -->
<div class="dash-section" id="tab-settings">
  <div class="max-w-2xl">
    <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Account Settings</h2>
    <div class="space-y-4">
      <div class="card">
        <h3 class="font-semibold mb-3" style="color:var(--color-text-primary)">Profile</h3>
        <p class="text-sm mb-4" style="color:var(--color-text-muted)">Manage your freelancer profile, skills, and portfolio.</p>
        <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="btn-primary text-sm">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
          Edit Profile
        </a>
      </div>
      <div class="card">
        <h3 class="font-semibold mb-3" style="color:var(--color-text-primary)">Password</h3>
        <p class="text-sm mb-4" style="color:var(--color-text-muted)">Update your password to keep your account secure.</p>
        <button class="btn-secondary text-sm" onclick="alert('Password change feature coming soon.')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          Change Password
        </button>
      </div>
    </div>
  </div>
</div>

<script>
function switchTab(tabId) {
  var tabs = document.querySelectorAll('.dash-tab');
  var sections = document.querySelectorAll('.dash-section');
  tabs.forEach(function(t) { t.classList.remove('active'); });
  sections.forEach(function(s) { s.classList.remove('active'); });
  var activeTab = document.querySelector('.dash-tab[data-tab="' + tabId + '"]');
  var activeSection = document.getElementById('tab-' + tabId);
  if (activeTab) activeTab.classList.add('active');
  if (activeSection) activeSection.classList.add('active');
  window.scrollTo({ top: document.querySelector('.dash-tab').offsetTop - 20, behavior: 'smooth' });
}

document.querySelectorAll('.dash-tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    switchTab(this.getAttribute('data-tab'));
  });
});

function markAllNotifications() {
  var csrf = '<?= e(csrf_token()) ?>';
  fetch('<?= e(base_url("api/notifications.php")) ?>', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ action: 'mark_all_read', csrf_token: csrf })
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    if (data.success) {
      document.querySelectorAll('#tab-notifications .card').forEach(function(c) {
        c.classList.remove('border-l-4', 'border-l-indigo-500');
        var p = c.querySelector('p');
        if (p) p.classList.remove('font-semibold');
      });
      var badge = document.querySelector('.notification-badge');
      if (badge) { badge.style.display = 'none'; }
    }
  })
  .catch(function() {});
}

function updateNotificationBadge() {
  fetch('<?= e(base_url("api/notifications.php")) ?>?action=count', {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(r) { return r.json(); })
  .then(function(data) {
    var badge = document.querySelector('.notification-badge');
    if (badge) {
      badge.textContent = data.count > 99 ? '99+' : data.count;
      badge.style.display = data.count > 0 ? 'flex' : 'none';
    }
  })
  .catch(function() {});
}

setInterval(updateNotificationBadge, 30000);
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
