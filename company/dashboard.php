<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/../includes/job_helpers.php';
check_assignment_deadlines($conn);

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

// Fetch company profile
$stmt = $conn->prepare("
    SELECT c.*, u.email, u.profile_image, u.created_at
    FROM companies c
    JOIN users u ON u.id = c.user_id
    WHERE c.id = ?
");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$company = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Stats
$stats = ['active' => 0, 'completed' => 0, 'total' => 0];
$stmt = $conn->prepare('SELECT status, COUNT(*) AS cnt FROM jobs WHERE company_id = ? GROUP BY status');
$stmt->bind_param('i', $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if (in_array($row['status'], ['open', 'hired', 'in_progress', 'in_review'])) {
        $stats['active'] += (int) $row['cnt'];
    } elseif (in_array($row['status'], ['closed', 'completed', 'expired', 'cancelled'])) {
        $stats['completed'] += (int) $row['cnt'];
    }
    $stats['total'] += (int) $row['cnt'];
}
$stmt->close();

// Posts count (Hired Freelancers)
try {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT a.freelancer_id) AS cnt FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        WHERE j.company_id = ? AND a.status NOT IN ('pending', 'rejected', 'cancelled')
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $total_posts = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $total_posts = 0;
}

try {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS cnt FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        WHERE j.company_id = ? AND a.status IN ('assigned', 'working', 'submitted', 'extended', 'not_started', 'in_progress')
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $active_hires = (int) $stmt->get_result()->fetch_assoc()['cnt'];
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $active_hires = 0;
}

// Recent applications
$recent_apps = [];
try {
    $stmt = $conn->prepare("
        SELECT ja.id, ja.status, ja.applied_at, j.title, j.id AS job_id,
               f.full_name, f.id AS freelancer_id, u.profile_image
        FROM job_applications ja
        JOIN jobs j ON ja.job_id = j.id
        JOIN freelancers f ON ja.freelancer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE j.company_id = ?
        ORDER BY ja.applied_at DESC
        LIMIT 8
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_apps[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $recent_apps = [];
}

// Posted jobs
$jobs = [];
try {
    $stmt = $conn->prepare("
        SELECT j.id, j.title, j.budget, j.status, j.created_at, j.description,
               (SELECT COUNT(*) FROM job_applications ja WHERE ja.job_id = j.id) AS app_count
        FROM jobs j
        WHERE j.company_id = ?
        ORDER BY j.created_at DESC
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $jobs[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $jobs = [];
}

// Hired freelancers (assignments)
$hired = [];
try {
    $stmt = $conn->prepare("
        SELECT a.id, a.status, a.submission_link, a.assigned_at, a.job_id,
               j.title AS job_title, j.budget,
               f.full_name, f.id AS freelancer_id, u.profile_image
        FROM assignments a
        JOIN jobs j ON a.job_id = j.id
        JOIN freelancers f ON a.freelancer_id = f.id
        JOIN users u ON f.user_id = u.id
        WHERE j.company_id = ?
        ORDER BY a.assigned_at DESC
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $hired[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $hired = [];
}

// Payment history
$payments = [];
try {
    $stmt = $conn->prepare("
        SELECT p.id, p.amount, p.status, p.paid_at,
               a.id AS assignment_id, a.status AS assignment_status,
               j.title AS job_title, f.full_name
        FROM payments p
        JOIN assignments a ON p.assignment_id = a.id
        JOIN jobs j ON a.job_id = j.id
        JOIN freelancers f ON a.freelancer_id = f.id
        WHERE j.company_id = ?
        ORDER BY p.paid_at DESC
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    $payments = [];
}

// Notifications
$notifications = [];
if (isset($user['user_id'])) {
    $notifications = get_notifications($conn, (int) $user['user_id'], 20);
    $unread_count = get_unread_notification_count($conn, (int) $user['user_id']);
}

$page_title = 'Company Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<style>
  :root {
    --gradient-primary: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    --gradient-success: linear-gradient(135deg, #059669 0%, #10b981 100%);
    --gradient-warning: linear-gradient(135deg, #d97706 0%, #f59e0b 100%);
    --gradient-info: linear-gradient(135deg, #0284c7 0%, #0ea5e9 100%);
  }
  .dash-tab {
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
    white-space: nowrap;
  }
  .dash-tab::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 0;
    height: 2px;
    background: var(--gradient-primary);
    transition: width 0.3s ease;
    border-radius: 1px;
  }
  .dash-tab:hover::after,
  .dash-tab.active::after {
    width: 70%;
  }
  .dash-tab.active {
    color: #4f46e5;
  }
  .dash-section {
    display: none;
    animation: fadeSlideIn 0.35s ease;
  }
  .dash-section.active {
    display: block;
  }
  @keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .stat-card {
    transition: transform 0.25s ease, box-shadow 0.25s ease;
  }
  .stat-card:hover {
    transform: translateY(-4px);
  }
  .gradient-border {
    position: relative;
  }
  .gradient-border::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    padding: 1px;
    background: var(--gradient-primary);
    -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
  }
  .hover-lift {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .hover-lift:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(79, 70, 229, 0.12);
  }
  .pulse-dot {
    animation: pulse 2s infinite;
  }
  @keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
  }
  .scrollbar-thin::-webkit-scrollbar {
    height: 4px;
    width: 4px;
  }
  .scrollbar-thin::-webkit-scrollbar-track {
    background: transparent;
  }
  .scrollbar-thin::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 2px;
  }
  .dark .scrollbar-thin::-webkit-scrollbar-thumb {
    background: #475569;
  }
</style>

<div class="mb-6">
  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="flex items-center gap-3">
      <?php if (!empty($company['logo_image'])): ?>
        <img src="<?= e(base_url('uploads/images/' . $company['logo_image'])) ?>" alt="" class="w-10 h-10 rounded-xl object-contain border bg-white dark:bg-gray-800" style="border-color:var(--color-border)">
      <?php elseif ($dashImg = profile_image_url($user['profile_image'])): ?>
        <img src="<?= e($dashImg) ?>" alt="" class="w-10 h-10 rounded-full object-cover border" style="border-color:var(--color-border)">
      <?php else: ?>
        <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold text-sm border" style="background:rgba(99,102,241,0.2);border-color:var(--color-border)">
          <?= e(_first_char($company['company_name'] ?? $user['username'])) ?>
        </div>
      <?php endif; ?>
      <div>
        <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($company['company_name'] ?? $user['username']) ?></h1>
        <p class="text-sm flex items-center gap-1.5" style="color:var(--color-text-muted)">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
          <?= e($company['location'] ?? 'Location') ?>
          <?php if ($company['established_year']): ?> &middot; Est. <?= e((string) $company['established_year']) ?><?php endif; ?>
        </p>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <a href="<?= e(base_url('company/post_job.php')) ?>" class="btn-primary text-sm btn-shine">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        <?= 'Post a New Job' ?>
      </a>
    </div>
  </div>
</div>

<!-- Tab Navigation -->
<div class="mb-6 overflow-x-auto scrollbar-thin">
  <div class="flex gap-1 sm:gap-2 border-b pb-1 min-w-max" style="border-color:var(--color-border)">
    <button class="dash-tab active px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="overview" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
      Overview
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="proposals" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      Applications
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="hired" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Hired
      <?php if ($active_hires > 0): ?>
        <span class="ml-1.5 text-xs bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 font-bold rounded-full px-1.5 py-0.5"><?= $active_hires ?></span>
      <?php endif; ?>
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="messages" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
      Messages
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="notifications" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
      Notifications
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="payments" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
      Payments
    </button>
    <button class="dash-tab px-3 sm:px-4 py-2.5 text-sm font-medium rounded-t-lg" data-tab="settings" style="color:var(--color-text-muted)">
      <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      Settings
    </button>
  </div>
</div>

<!-- ====== OVERVIEW TAB ====== -->
<div class="dash-section active" id="tab-overview">
  <div class="grid grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
    <div class="card stat-card relative overflow-hidden" style="background:linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);color:#fff;border:none;">
      <div class="absolute top-0 right-0 w-24 h-24 opacity-10">
        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
      </div>
      <p class="text-sm opacity-80 font-medium"><?= 'Active Jobs' ?></p>
      <p class="text-3xl font-bold mt-1"><?= $stats['active'] ?? 0 ?></p>
    </div>
    <div class="card stat-card relative overflow-hidden" style="background:linear-gradient(135deg, #059669 0%, #10b981 100%);color:#fff;border:none;">
      <div class="absolute top-0 right-0 w-24 h-24 opacity-10">
        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      </div>
      <p class="text-sm opacity-80 font-medium"><?= 'Completed Jobs' ?></p>
      <p class="text-3xl font-bold mt-1"><?= $stats['completed'] ?? 0 ?></p>
    </div>
    <div class="card stat-card relative overflow-hidden" style="background:linear-gradient(135deg, #d97706 0%, #f59e0b 100%);color:#fff;border:none;">
      <div class="absolute top-0 right-0 w-24 h-24 opacity-10">
        <svg class="w-full h-full" fill="currentColor" viewBox="0 0 24 24"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      </div>
      <p class="text-sm opacity-80 font-medium">Active Hires</p>
      <p class="text-3xl font-bold mt-1"><?= $active_hires ?></p>
    </div>
  </div>

  <div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)"><?= 'Recent Applications' ?></h2>
        <button class="text-sm font-medium text-indigo-600 hover:text-indigo-700" onclick="switchTab('proposals')">View All &rarr;</button>
      </div>
      <?php if (empty($recent_apps)): ?>
        <div class="text-center py-10" style="color:var(--color-text-placeholder)">
          <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
          <p><?= 'No applications yet.' ?></p>
        </div>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($recent_apps as $app): ?>
            <div class="flex items-center gap-3 p-3 rounded-lg hover-lift" style="background:var(--color-bg);border:1px solid var(--color-border)">
              <?php $appImg = profile_image_url($app['profile_image']); ?>
              <?php if ($appImg): ?>
                <img src="<?= e($appImg) ?>" alt="" class="w-9 h-9 rounded-full object-cover flex-shrink-0">
              <?php else: ?>
                <div class="w-9 h-9 rounded-full flex items-center justify-center text-indigo-600 font-bold text-sm flex-shrink-0" style="background:rgba(99,102,241,0.1)"><?= e(strtoupper(substr($app['full_name'], 0, 1))) ?></div>
              <?php endif; ?>
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($app['full_name']) ?></p>
                <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($app['title']) ?></p>
              </div>
              <div class="text-right flex-shrink-0">
                <p class="text-xs" style="color:var(--color-text-placeholder)"><?= date('M j', strtotime($app['applied_at'])) ?></p>
                <?= status_badge($app['status']) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  </div>

  <?php if (!empty($hired)): ?>
  <div class="card">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-lg font-semibold" style="color:var(--color-text-primary)">Active Assignments</h2>
      <button class="text-sm font-medium text-indigo-600 hover:text-indigo-700" onclick="switchTab('hired')">View All &rarr;</button>
    </div>
    <div class="space-y-3">
      <?php foreach (array_slice($hired, 0, 4) as $h): ?>
        <div class="flex items-center gap-3 p-3 rounded-lg" style="background:var(--color-bg);border:1px solid var(--color-border)">
          <?php $hImg = profile_image_url($h['profile_image']); ?>
          <?php if ($hImg): ?>
            <img src="<?= e($hImg) ?>" alt="" class="w-9 h-9 rounded-full object-cover flex-shrink-0">
          <?php else: ?>
            <div class="w-9 h-9 rounded-full flex items-center justify-center text-indigo-600 font-bold text-sm flex-shrink-0" style="background:rgba(99,102,241,0.1)"><?= e(strtoupper(substr($h['full_name'], 0, 1))) ?></div>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium truncate" style="color:var(--color-text-primary)"><?= e($h['full_name']) ?></p>
            <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($h['job_title']) ?></p>
          </div>
          <div class="text-right flex-shrink-0"><?= status_badge($h['status']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ====== PROPOSALS TAB ====== -->
<div class="dash-section" id="tab-proposals">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Applications</h2>
  <?php if (empty($recent_apps)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
      <p><?= 'No applications yet.' ?></p>
    </div>
  <?php else: ?>
    <div class="space-y-3">
      <?php foreach ($recent_apps as $app): ?>
        <div class="card flex flex-col sm:flex-row sm:items-center gap-4">
          <div class="flex items-center gap-3 flex-1 min-w-0">
            <?php $appImg = profile_image_url($app['profile_image']); ?>
            <?php if ($appImg): ?>
              <img src="<?= e($appImg) ?>" alt="" class="w-11 h-11 rounded-full object-cover flex-shrink-0 border-2" style="border-color:var(--color-border)">
            <?php else: ?>
              <div class="w-11 h-11 rounded-full flex items-center justify-center text-indigo-600 font-bold flex-shrink-0 border-2" style="background:rgba(99,102,241,0.1);border-color:var(--color-border)"><?= e(strtoupper(substr($app['full_name'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="min-w-0">
              <p class="font-medium truncate" style="color:var(--color-text-primary)"><?= e($app['full_name']) ?></p>
              <p class="text-sm truncate" style="color:var(--color-text-muted)">Applied for: <?= e($app['title']) ?></p>
              <p class="text-xs" style="color:var(--color-text-placeholder)"><?= date('M j, Y g:i a', strtotime($app['applied_at'])) ?></p>
            </div>
          </div>
          <div class="flex items-center gap-2 flex-shrink-0">
            <?= status_badge($app['status']) ?>
            <?php if ($app['status'] === 'pending'): ?>
              <a href="<?= e(base_url('company/view_applications.php?id=' . $app['job_id'])) ?>" class="btn-primary text-xs">Review</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== HIRED TAB ====== -->
<div class="dash-section" id="tab-hired">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Hired Freelancers</h2>
  <?php if (empty($hired)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
      <p>No freelancers hired yet. Review posts and hire talent to get started.</p>
    </div>
  <?php else: ?>
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
      <?php foreach ($hired as $h): ?>
        <div class="card hover-lift">
          <div class="flex items-center gap-3 mb-3">
            <?php $hImg = profile_image_url($h['profile_image']); ?>
            <?php if ($hImg): ?>
              <img src="<?= e($hImg) ?>" alt="" class="w-10 h-10 rounded-full object-cover">
            <?php else: ?>
              <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold" style="background:rgba(99,102,241,0.1)"><?= e(strtoupper(substr($h['full_name'], 0, 1))) ?></div>
            <?php endif; ?>
            <div class="min-w-0 flex-1">
              <p class="font-medium truncate" style="color:var(--color-text-primary)"><?= e($h['full_name']) ?></p>
              <p class="text-xs truncate" style="color:var(--color-text-muted)"><?= e($h['job_title']) ?></p>
            </div>
            <?= status_badge($h['status']) ?>
          </div>
          <div class="flex items-center justify-between text-sm pt-3 border-t" style="border-color:var(--color-border)">
            <span style="color:var(--color-text-muted)">Budget: <strong class="text-indigo-600"><?= e(number_format((float) $h['budget'], 2)) ?> MMK</strong></span>
            <span style="color:var(--color-text-placeholder)"><?= date('M j', strtotime($h['assigned_at'])) ?></span>
          </div>
          <?php if ($h['job_id']): ?>
            <a href="<?= e(base_url('company/view_applications.php?id=' . $h['job_id'])) ?>" class="mt-3 block text-center text-xs font-medium py-1.5 rounded-lg border hover-lift" style="border-color:var(--color-border);color:var(--color-text-secondary)">Manage Assignment</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<!-- ====== MESSAGES TAB ====== -->
<div class="dash-section" id="tab-messages">
  <div class="card text-center py-12">
    <svg class="w-16 h-16 mx-auto mb-4" style="color:var(--color-text-placeholder)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
    <h3 class="text-lg font-semibold mb-2" style="color:var(--color-text-primary)"><?= 'Messages' ?></h3>
    <p class="mb-4" style="color:var(--color-text-muted)"><?= 'Select a person to start chatting' ?></p>
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
      <form method="POST" action="<?= e(base_url('api/notifications.php')) ?>" class="inline" id="markAllForm">
        <button type="button" onclick="markAllNotifications()" class="text-sm text-indigo-600 hover:underline font-medium">Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>
  <?php if (empty($notifications)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
      <p><?= 'No notifications yet.' ?></p>
    </div>
  <?php else: ?>
    <div class="space-y-2">
      <?php foreach ($notifications as $n): ?>
        <div class="card flex items-start gap-3 p-4 <?= $n['is_read'] ? '' : 'border-l-4 border-l-indigo-500' ?>" style="<?= $n['is_read'] ? '' : 'background:rgba(99,102,241,0.03)' ?>">
          <?php if (!empty($n['sender_name'])): ?>
            <div class="mt-0.5 flex-shrink-0">
                <?php if (!empty($n['sender_image'])): ?>
                    <img src="<?= e(base_url('uploads/images/' . $n['sender_image'])) ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover shadow-sm" onerror="this.onerror=null; this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold text-sm shadow-sm" style="display:none"><?= e(strtoupper(substr($n['sender_name'], 0, 1))) ?></div>
                <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400 font-bold text-sm shadow-sm"><?= e(strtoupper(substr($n['sender_name'], 0, 1))) ?></div>
                <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="mt-0.5 flex-shrink-0 w-10 h-10 flex items-center justify-center rounded-full bg-gray-50 dark:bg-gray-800">
                <?= notification_icon($n['type']) ?>
            </div>
          <?php endif; ?>
          <div class="flex-1 min-w-0">
            <?php if (!empty($n['sender_name'])): ?>
                <p class="font-semibold text-gray-900 dark:text-white mb-1"><?= e($n['sender_name']) ?></p>
            <?php endif; ?>
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

<!-- ====== PAYMENTS TAB ====== -->
<div class="dash-section" id="tab-payments">
  <h2 class="text-lg font-semibold mb-5" style="color:var(--color-text-primary)">Payment History</h2>
  <?php if (empty($payments)): ?>
    <div class="card text-center py-12" style="color:var(--color-text-placeholder)">
      <svg class="w-16 h-16 mx-auto mb-4 opacity-30" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
      <p>No payments made yet.</p>
    </div>
  <?php else: ?>
    <div class="card overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b text-left" style="border-color:var(--color-border);color:var(--color-text-muted)">
            <th class="py-3 pr-4">Job</th>
            <th class="py-3 pr-4">Freelancer</th>
            <th class="py-3 pr-4">Amount</th>
            <th class="py-3 pr-4">Status</th>
            <th class="py-3">Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payments as $p): ?>
            <tr class="border-b" style="border-color:var(--color-border)">
              <td class="py-3 pr-4 font-medium"><?= e($p['job_title']) ?></td>
              <td class="py-3 pr-4"><?= e($p['full_name']) ?></td>
              <td class="py-3 pr-4 font-semibold text-indigo-600"><?= e(number_format((float) $p['amount'], 2)) ?> MMK</td>
              <td class="py-3 pr-4"><?= status_badge($p['status']) ?></td>
              <td class="py-3" style="color:var(--color-text-placeholder)"><?= e($p['paid_at'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
        <p class="text-sm mb-4" style="color:var(--color-text-muted)">Manage your company profile and public information.</p>
        <a href="<?= e(base_url('company/profile.php')) ?>" class="btn-primary text-sm">
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
      document.getElementById('markAllForm')?.remove();
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
