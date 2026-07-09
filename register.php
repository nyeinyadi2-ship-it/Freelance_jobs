<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/notifications.php';

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    if ($role === 'admin') redirect('admin/admin_dashboard.php');
    if ($role === 'company') redirect('company/dashboard.php');
    if ($role === 'freelancer') redirect('freelancer/dashboard.php');
}

$error = '';
$role = $_POST['role'] ?? 'company';

$skills = [];
$result = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
while ($row = $result->fetch_assoc()) {
    $skills[] = $row;
}

// Industry options
$industries = [
    'Technology & Software',
    'Design & Creative',
    'Marketing & Advertising',
    'Finance & Accounting',
    'Healthcare & Medical',
    'Education & Training',
    'E-commerce & Retail',
    'Construction & Engineering',
    'Legal & Compliance',
    'Media & Entertainment',
    'Human Resources',
    'Logistics & Supply Chain',
    'Food & Hospitality',
    'Real Estate',
    'Non-profit & NGO',
    'Other',
];

// Company size options
$company_sizes = [
    '1–10 employees'    => '1–10',
    '11–50 employees'   => '11–50',
    '51–200 employees'  => '51–200',
    '201–500 employees' => '201–500',
    '500+ employees'    => '500+',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = __('error.invalid_request');
    } else {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';
        $role     = $_POST['role'] ?? '';

        if (!in_array($role, ['company', 'freelancer'], true)) {
            $error = __('error.invalid_role');
        } elseif ($username === '' || $email === '' || $password === '') {
            $error = __('error.required_fields');
        } elseif ($password !== $confirm) {
            $error = __('error.password_mismatch');
        } elseif (strlen($password) < 6) {
            $error = __('error.password_min');
        } else {
            // Check email uniqueness
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = __('error.email_exists');
            }
            $stmt->close();

            if ($error === '') {
                // Handle profile avatar upload
                $profile_image = null;
                if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
                    $profile_image = upload_image($_FILES['profile_photo']);
                    if (!$profile_image) {
                        $error = __('upload.invalid_profile');
                    }
                }

                // Handle company logo upload (separate from avatar — fixes the logo=avatar bug)
                $logo_image = null;
                if ($error === '' && $role === 'company') {
                    if (isset($_FILES['logo_image']) && $_FILES['logo_image']['error'] === UPLOAD_ERR_OK) {
                        $logo_image = upload_image($_FILES['logo_image']);
                        if (!$logo_image) {
                            $error = __('upload.invalid_logo');
                        }
                    }
                }
            }

            if ($error === '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare('INSERT INTO users (username, email, password, profile_image, role) VALUES (?, ?, ?, ?, ?)');
                    $stmt->bind_param('sssss', $username, $email, $hashed, $profile_image, $role);
                    $stmt->execute();
                    $user_id = $stmt->insert_id;
                    $stmt->close();

                    if ($role === 'company') {
                        $company_name    = trim($_POST['company_name'] ?? '');
                        $website         = trim($_POST['website'] ?? '');
                        $phone           = trim($_POST['phone'] ?? '');
                        $location        = trim($_POST['location'] ?? '');
                        $description     = trim($_POST['description'] ?? '');
                        $established_raw = (int) ($_POST['established_year'] ?? 0);
                        $established_year = ($established_raw >= 1800 && $established_raw <= (int) date('Y')) ? $established_raw : null;
                        $industry        = trim($_POST['industry'] ?? '');
                        $company_size    = trim($_POST['company_size'] ?? '');

                        if ($company_name === '') {
                            throw new Exception(__('error.company_name_required'));
                        }

                        // Validate industry is from allowed list
                        if ($industry !== '' && !in_array($industry, $industries, true)) {
                            $industry = '';
                        }

                        // Validate company_size is from allowed list
                        $valid_sizes = array_values($company_sizes);
                        if ($company_size !== '' && !in_array($company_size, $valid_sizes, true)) {
                            $company_size = '';
                        }

                        $stmt = $conn->prepare('INSERT INTO companies (user_id, company_name, website, phone, location, description, established_year, industry, company_size, logo_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                        $stmt->bind_param('isssssisss', $user_id, $company_name, $website, $phone, $location, $description, $established_year, $industry, $company_size, $logo_image);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $full_name       = trim($_POST['full_name'] ?? '');
                        $portfolio_url   = trim($_POST['portfolio_url'] ?? '');
                        $selected_skills = $_POST['skills'] ?? [];

                        if ($full_name === '') {
                            throw new Exception(__('error.full_name_required'));
                        }

                        $stmt = $conn->prepare('INSERT INTO freelancers (user_id, full_name, portfolio_url) VALUES (?, ?, ?)');
                        $stmt->bind_param('iss', $user_id, $full_name, $portfolio_url);
                        $stmt->execute();
                        $freelancer_id = $stmt->insert_id;
                        $stmt->close();

                        if (!empty($selected_skills)) {
                            $skill_stmt = $conn->prepare('INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)');
                            foreach ($selected_skills as $skill_id) {
                                $skill_id = (int) $skill_id;
                                $skill_stmt->bind_param('ii', $freelancer_id, $skill_id);
                                $skill_stmt->execute();
                            }
                            $skill_stmt->close();
                        }
                    }

                    $conn->commit();

                    $admin_id = get_admin_user_id($conn);
                    if ($admin_id) {
                        $role_label = ucfirst($role);
                        create_notification($conn, $admin_id, 'new_registration', "New {$role_label} \"{$username}\" has registered.", null);
                    }

                    set_flash('success', 'Registration successful. Please log in.');
                    redirect('login.php');
                } catch (Exception $e) {
                    $conn->rollback();
                    if ($profile_image) delete_upload($profile_image);
                    if ($logo_image)    delete_upload($logo_image);
                    $error = $e->getMessage() ?: __('error.registration_failed');
                }
            }
        }
    }
}

$page_title = __('register.title');
require __DIR__ . '/includes/header.php';
?>
<style>
  /* ─── Layout ─────────────────────────────────────────── */
  .auth-wrapper {
    min-height: calc(100vh - 4rem);
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2.5rem 1rem 4rem;
    position: relative;
    background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 50%, #ede9fe 100%);
  }
  .dark .auth-wrapper {
    background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #0f172a 100%);
  }
  .auth-wrapper::before {
    content: '';
    position: absolute; top: -20%; right: -5%;
    width: 420px; height: 420px; border-radius: 50%;
    background: radial-gradient(circle, rgba(99,102,241,0.13) 0%, transparent 70%);
    pointer-events: none;
  }
  .auth-wrapper::after {
    content: '';
    position: absolute; bottom: -15%; left: -5%;
    width: 320px; height: 320px; border-radius: 50%;
    background: radial-gradient(circle, rgba(168,85,247,0.1) 0%, transparent 70%);
    pointer-events: none;
  }
  .auth-card {
    width: 100%;
    max-width: 580px;
    position: relative; z-index: 1;
    animation: authEntry 0.5s cubic-bezier(0.16, 1, 0.3, 1);
  }
  @keyframes authEntry {
    from { opacity: 0; transform: translateY(24px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)   scale(1);    }
  }

  /* ─── Inputs ──────────────────────────────────────────── */
  .auth-input {
    width: 100%;
    padding: 0.75rem 1rem;
    border: 2px solid var(--color-input-border);
    border-radius: 12px;
    background: var(--color-input-bg);
    color: var(--color-text-primary);
    font-size: 0.9375rem;
    transition: all 0.2s ease;
    outline: none;
    box-sizing: border-box;
  }
  .auth-input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
  }
  .auth-input.is-invalid {
    border-color: #ef4444;
    box-shadow: 0 0 0 4px rgba(239,68,68,0.1);
  }
  select.auth-input {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.875rem center;
    padding-right: 2.5rem;
  }

  /* ─── Submit button ───────────────────────────────────── */
  .auth-submit {
    width: 100%;
    padding: 0.875rem 1.5rem;
    background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
    color: #fff;
    border: none; border-radius: 12px;
    font-size: 1rem; font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    position: relative; overflow: hidden;
  }
  .auth-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(79,70,229,0.38);
  }
  .auth-submit:active { transform: translateY(0); }
  .auth-submit::after {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
    transform: translateX(-100%);
    transition: transform 0.6s ease;
  }
  .auth-submit:hover::after { transform: translateX(100%); }

  /* ─── Password toggle ─────────────────────────────────── */
  .password-toggle {
    position: absolute; right: 0.875rem; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; cursor: pointer;
    color: var(--color-text-placeholder);
    padding: 0.25rem; border-radius: 4px;
    transition: color 0.2s ease;
  }
  .password-toggle:hover { color: var(--color-text-secondary); }

  /* ─── Role cards ──────────────────────────────────────── */
  .role-card {
    flex: 1; cursor: pointer;
    padding: 1rem 0.75rem;
    border-radius: 14px; text-align: center;
    transition: all 0.25s ease;
    border: 2px solid var(--color-border);
    background: var(--color-card);
    position: relative;
  }
  .role-card:hover { border-color: #a5b4fc; transform: translateY(-2px); }
  .role-card.active {
    border-color: #6366f1;
    background: rgba(99,102,241,0.05);
    box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
  }
  .role-card input { position: absolute; opacity: 0; width: 0; height: 0; }
  .role-card .check {
    position: absolute; top: 0.5rem; right: 0.5rem;
    width: 20px; height: 20px; border-radius: 50%;
    border: 2px solid var(--color-border);
    display: flex; align-items: center; justify-content: center;
    transition: all 0.2s ease;
  }
  .role-card.active .check { border-color: #6366f1; background: #6366f1; }
  .role-card.active .check svg { display: block; }

  /* ─── Role fields (animated) ──────────────────────────── */
  .role-fields { display: none; animation: fadeSlideIn 0.35s cubic-bezier(0.16, 1, 0.3, 1); }
  .role-fields.open { display: block; }
  @keyframes fadeSlideIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* ─── Section box ─────────────────────────────────────── */
  .section-box {
    padding: 1.25rem;
    border-radius: 14px;
    border: 1px solid rgba(99,102,241,0.12);
    background: rgba(99,102,241,0.03);
  }
  .section-box h4 {
    font-size: 0.875rem; font-weight: 700;
    display: flex; align-items: center; gap: 0.5rem;
    margin-bottom: 1rem;
    color: var(--color-text-primary);
    letter-spacing: 0.01em;
  }
  .section-box h4 .badge {
    font-size: 0.7rem; font-weight: 500;
    padding: 0.15rem 0.5rem; border-radius: 99px;
    background: rgba(99,102,241,0.12); color: #6366f1;
  }

  /* ─── Grid helpers ────────────────────────────────────── */
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0.875rem; }
  @media (max-width: 480px) { .grid-2 { grid-template-columns: 1fr; } }

  /* ─── File upload area ────────────────────────────────── */
  .upload-area {
    border: 2px dashed var(--color-border);
    border-radius: 12px;
    padding: 1rem;
    text-align: center;
    cursor: pointer;
    transition: all 0.2s ease;
    background: var(--color-input-bg);
    position: relative;
  }
  .upload-area:hover, .upload-area:focus-within {
    border-color: #6366f1;
    background: rgba(99,102,241,0.02);
  }
  .upload-area input[type="file"] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer;
  }
  .upload-preview {
    display: none; margin-top: 0.75rem;
    justify-content: center; gap: 0.75rem; align-items: center;
  }
  .upload-preview.visible { display: flex; }
  .upload-preview img { border-radius: 10px; object-fit: cover; border: 2px solid var(--color-border); }

  /* ─── Skill chips ─────────────────────────────────────── */
  .skill-checkbox { display: none; }
  .skill-label {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.4rem 0.8rem;
    border-radius: 9999px; font-size: 0.8rem; font-weight: 500;
    cursor: pointer; transition: all 0.18s ease;
    border: 2px solid var(--color-border);
    color: var(--color-text-muted); background: var(--color-card);
    user-select: none;
  }
  .skill-label:hover { border-color: #a5b4fc; transform: translateY(-1px); }
  .skill-checkbox:checked + .skill-label {
    border-color: #6366f1;
    background: rgba(99,102,241,0.08);
    color: #4f46e5;
  }
  .grid-skills { display: flex; flex-wrap: wrap; gap: 0.5rem; }

  /* ─── Password strength ───────────────────────────────── */
  .pw-strength { height: 4px; border-radius: 2px; background: var(--color-border); overflow: hidden; margin-top: 0.5rem; }
  .pw-strength-bar { height: 100%; width: 0; border-radius: 2px; transition: width 0.3s ease, background 0.3s ease; }

  /* ─── Char counter ────────────────────────────────────── */
  .char-counter { font-size: 0.75rem; color: var(--color-text-placeholder); text-align: right; margin-top: 0.25rem; }
  .char-counter.warn { color: #f59e0b; }
  .char-counter.over { color: #ef4444; }

  /* ─── Auth divider / social ───────────────────────────── */
  .auth-divider {
    display: flex; align-items: center; gap: 1rem;
    color: var(--color-text-placeholder); font-size: 0.8125rem;
  }
  .auth-divider::before,.auth-divider::after {
    content: ''; flex: 1; height: 1px; background: var(--color-border);
  }
  .social-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem; border-radius: 10px; font-size: 0.875rem; font-weight: 500;
    cursor: pointer; transition: all 0.2s ease;
    border: 2px solid var(--color-border); background: var(--color-card); color: var(--color-text-secondary);
  }
  .social-btn:hover { border-color: #6366f1; background: rgba(99,102,241,0.04); transform: translateY(-1px); }

  /* ─── Shake animation ─────────────────────────────────── */
  .shake { animation: shake 0.4s ease; }
  @keyframes shake {
    0%,100% { transform: translateX(0); }
    20%      { transform: translateX(-6px); }
    40%      { transform: translateX(6px); }
    60%      { transform: translateX(-4px); }
    80%      { transform: translateX(4px); }
  }

  /* ─── Step indicators ─────────────────────────────────── */
  .step-strip {
    display: flex; align-items: center; gap: 0; margin-bottom: 1.75rem;
  }
  .step-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; gap: 0.375rem;
    position: relative; font-size: 0.75rem; font-weight: 500;
    color: var(--color-text-placeholder);
  }
  .step-item::after {
    content: ''; position: absolute; top: 13px; left: 50%; right: -50%;
    height: 2px; background: var(--color-border); z-index: 0;
  }
  .step-item:last-child::after { display: none; }
  .step-dot {
    width: 28px; height: 28px; border-radius: 50%;
    border: 2px solid var(--color-border); background: var(--color-card);
    display: flex; align-items: center; justify-content: center;
    font-size: 0.7rem; font-weight: 700; position: relative; z-index: 1;
    transition: all 0.25s ease;
  }
  .step-item.active .step-dot {
    border-color: #6366f1; background: #6366f1; color: #fff;
  }
  .step-item.done .step-dot {
    border-color: #10b981; background: #10b981; color: #fff;
  }
  .step-item.active { color: #6366f1; }
  .step-item.done   { color: #10b981; }
  .step-item.done::after { background: #10b981; }
</style>

<div class="auth-wrapper">
  <div class="auth-card">

    <!-- Brand -->
    <div class="text-center mb-8">
      <a href="<?= e(base_url('index.php')) ?>" class="inline-flex items-center gap-2 text-2xl font-bold">
        <span class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-lg" style="background:linear-gradient(135deg, #4f46e5, #7c3aed);">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </span>
        <span class="gradient-text"><?= e(__('app.name')) ?></span>
      </a>
      <p class="mt-2 text-sm" style="color:var(--color-text-muted)"><?= __('register.title') ?></p>
    </div>

    <!-- Card -->
    <div class="rounded-2xl p-6 sm:p-8" style="background:var(--color-card);border:1px solid var(--color-border);box-shadow:0 4px 32px rgba(0,0,0,0.07);">

      <!-- Step strip -->
      <div class="step-strip" id="stepStrip">
        <div class="step-item active" id="step1"><div class="step-dot">1</div><span>Account</span></div>
        <div class="step-item" id="step2"><div class="step-dot">2</div><span>Details</span></div>
        <div class="step-item" id="step3"><div class="step-dot">3</div><span>Done</span></div>
      </div>

      <?php if ($error): ?>
        <div class="shake mb-5 p-3.5 rounded-xl flex items-center gap-2.5 text-sm font-medium" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);">
          <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form method="POST" id="registerForm" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <!-- ══════════════════════════════════════════
             STEP 1: ACCOUNT INFO
        ═══════════════════════════════════════════ -->
        <div id="formStep1">

          <!-- Role Selection -->
          <div class="mb-6">
            <label class="block text-sm font-medium mb-3" style="color:var(--color-text-secondary)"><?= e(__('register.role')) ?></label>
            <div class="flex gap-3">
              <label class="role-card <?= $role === 'company' ? 'active' : '' ?>" onclick="selectRole('company')">
                <input type="radio" name="role" value="company" <?= $role === 'company' ? 'checked' : '' ?>>
                <div class="check">
                  <svg class="w-3 h-3 text-white hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="mb-1.5"><svg class="w-8 h-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:<?= $role === 'company' ? '#4f46e5' : 'var(--color-text-placeholder)' ?>"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg></div>
                <p class="text-sm font-semibold" style="color:<?= $role === 'company' ? '#4f46e5' : 'var(--color-text-primary)' ?>"><?= e(__('role.company')) ?></p>
                <p class="text-xs mt-0.5" style="color:var(--color-text-placeholder)">Post jobs &amp; hire talent</p>
              </label>
              <label class="role-card <?= $role === 'freelancer' ? 'active' : '' ?>" onclick="selectRole('freelancer')">
                <input type="radio" name="role" value="freelancer" <?= $role === 'freelancer' ? 'checked' : '' ?>>
                <div class="check">
                  <svg class="w-3 h-3 text-white hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div class="mb-1.5"><svg class="w-8 h-8 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:<?= $role === 'freelancer' ? '#4f46e5' : 'var(--color-text-placeholder)' ?>"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg></div>
                <p class="text-sm font-semibold" style="color:<?= $role === 'freelancer' ? '#4f46e5' : 'var(--color-text-primary)' ?>"><?= e(__('role.freelancer')) ?></p>
                <p class="text-xs mt-0.5" style="color:var(--color-text-placeholder)">Find work &amp; earn money</p>
              </label>
            </div>
          </div>

          <div class="space-y-4">
            <!-- Username -->
            <div>
              <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.username')) ?> <span style="color:#ef4444">*</span></label>
              <input type="text" name="username" id="regUsername" required class="auth-input" placeholder="johndoe" value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username">
            </div>

            <!-- Email -->
            <div>
              <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.email')) ?> <span style="color:#ef4444">*</span></label>
              <input type="email" name="email" id="regEmail" required class="auth-input" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" autocomplete="email">
            </div>

            <!-- Password -->
            <div>
              <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.password')) ?> <span style="color:#ef4444">*</span></label>
              <div class="relative">
                <input type="password" name="password" id="regPassword" required class="auth-input pr-10" placeholder="Min. 6 characters" minlength="6" autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePw('regPassword', this)" tabindex="-1" aria-label="Toggle password">
                  <svg class="w-5 h-5 eye-open" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  <svg class="w-5 h-5 eye-closed hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </button>
              </div>
              <div class="pw-strength"><div class="pw-strength-bar" id="pwStrengthBar"></div></div>
              <p class="text-xs mt-1.5" id="pwStrengthText" style="color:var(--color-text-placeholder)">Type a password to see strength</p>
            </div>

            <!-- Confirm Password -->
            <div>
              <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.confirm_password')) ?> <span style="color:#ef4444">*</span></label>
              <div class="relative">
                <input type="password" name="confirm_password" id="regConfirm" required class="auth-input pr-10" placeholder="Repeat your password" minlength="6" autocomplete="new-password">
                <button type="button" class="password-toggle" onclick="togglePw('regConfirm', this)" tabindex="-1" aria-label="Toggle password">
                  <svg class="w-5 h-5 eye-open" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  <svg class="w-5 h-5 eye-closed hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                </button>
              </div>
              <p class="text-xs mt-1.5" id="matchText"></p>
            </div>

            <!-- Profile Avatar (optional, both roles) -->
            <div>
              <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('upload.profile_image')) ?></label>
              <div class="upload-area" id="avatarDropzone">
                <input type="file" name="profile_photo" id="profilePhotoInput" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewFile(event, 'avatarPreview', 'avatarImg', 'w-16 h-16 rounded-full')">
                <svg class="w-8 h-8 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <p class="text-xs" style="color:var(--color-text-placeholder)">Click or drag to upload profile photo</p>
                <p class="text-xs mt-0.5" style="color:var(--color-text-placeholder)"><?= e(__('upload.hint')) ?></p>
              </div>
              <div class="upload-preview" id="avatarPreview">
                <img id="avatarImg" src="" alt="Avatar preview" class="w-16 h-16 rounded-full" style="border:2px solid var(--color-border)">
                <button type="button" class="text-xs" style="color:#ef4444" onclick="clearFile('profilePhotoInput','avatarPreview')">Remove</button>
              </div>
            </div>
          </div>

          <!-- Next button -->
          <div class="mt-6">
            <button type="button" id="btnStep1Next" class="auth-submit" onclick="goStep2()">
              <span class="relative z-10 flex items-center justify-center gap-2">
                Continue
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
              </span>
            </button>
          </div>
        </div><!-- /formStep1 -->

        <!-- ══════════════════════════════════════════
             STEP 2: ROLE-SPECIFIC DETAILS
        ═══════════════════════════════════════════ -->
        <div id="formStep2" style="display:none;">

          <!-- ── Company Fields ── -->
          <div class="role-fields <?= $role === 'company' ? 'open' : '' ?>" id="companyFields">
            <div class="section-box">
              <h4>
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                Company Details
                <span class="badge">Step 2 of 2</span>
              </h4>

              <div class="space-y-4">
                <!-- Company Name -->
                <div>
                  <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.company_name')) ?> <span style="color:#ef4444">*</span></label>
                  <input type="text" name="company_name" id="companyNameInput" class="auth-input" placeholder="Acme Inc." value="<?= e($_POST['company_name'] ?? '') ?>">
                </div>

                <!-- Phone + Website (2-col) -->
                <div class="grid-2">
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.phone')) ?></label>
                    <input type="tel" name="phone" class="auth-input" placeholder="+1 234 567 8900" value="<?= e($_POST['phone'] ?? '') ?>">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.website')) ?></label>
                    <input type="url" name="website" class="auth-input" placeholder="<?= e(__('register.website_placeholder')) ?>" value="<?= e($_POST['website'] ?? '') ?>">
                  </div>
                </div>

                <!-- Location + Est. Year (2-col) -->
                <div class="grid-2">
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.location')) ?></label>
                    <input type="text" name="location" class="auth-input" placeholder="City, Country" value="<?= e($_POST['location'] ?? '') ?>">
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.established_year')) ?></label>
                    <input type="number" name="established_year" class="auth-input" min="1800" max="<?= date('Y') ?>" placeholder="<?= date('Y') ?>" value="<?= e($_POST['established_year'] ?? '') ?>">
                  </div>
                </div>

                <!-- Industry + Company Size (2-col) -->
                <div class="grid-2">
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.industry')) ?></label>
                    <select name="industry" class="auth-input">
                      <option value="">— Select Industry —</option>
                      <?php foreach ($industries as $ind): ?>
                        <option value="<?= e($ind) ?>" <?= (($_POST['industry'] ?? '') === $ind) ? 'selected' : '' ?>><?= e($ind) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.company_size')) ?></label>
                    <select name="company_size" class="auth-input">
                      <option value="">— Select Size —</option>
                      <?php foreach ($company_sizes as $label => $val): ?>
                        <option value="<?= e($val) ?>" <?= (($_POST['company_size'] ?? '') === $val) ? 'selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <!-- Description -->
                <div>
                  <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.description')) ?></label>
                  <textarea name="description" id="companyDesc" rows="4" class="auth-input" placeholder="<?= e(__('register.description_placeholder')) ?>" maxlength="800" oninput="charCounter(this,'descCounter',800)"><?= e($_POST['description'] ?? '') ?></textarea>
                  <p class="char-counter" id="descCounter">0 / 800</p>
                </div>

                <!-- Company Logo (separate from profile avatar) -->
                <div>
                  <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.logo_image')) ?></label>
                  <div class="upload-area" id="logoDropzone">
                    <input type="file" name="logo_image" id="logoImageInput" accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewFile(event, 'logoPreview', 'logoImg', 'h-14 w-auto rounded-lg')">
                    <svg class="w-8 h-8 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" style="color:var(--color-text-placeholder)"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="text-xs" style="color:var(--color-text-placeholder)">Click or drag to upload company logo</p>
                    <p class="text-xs mt-0.5" style="color:var(--color-text-placeholder)"><?= e(__('register.logo_hint')) ?></p>
                  </div>
                  <div class="upload-preview" id="logoPreview">
                    <img id="logoImg" src="" alt="Logo preview" class="h-14 w-auto rounded-lg object-contain" style="border:2px solid var(--color-border)">
                    <button type="button" class="text-xs" style="color:#ef4444" onclick="clearFile('logoImageInput','logoPreview')">Remove</button>
                  </div>
                </div>
              </div>
            </div>
          </div><!-- /companyFields -->

          <!-- ── Freelancer Fields ── -->
          <div class="role-fields <?= $role === 'freelancer' ? 'open' : '' ?>" id="freelancerFields">
            <div class="section-box">
              <h4>
                <svg class="w-4 h-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Freelancer Details
                <span class="badge">Step 2 of 2</span>
              </h4>

              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.full_name')) ?> <span style="color:#ef4444">*</span></label>
                  <input type="text" name="full_name" class="auth-input" placeholder="John Doe" value="<?= e($_POST['full_name'] ?? '') ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= e(__('register.portfolio_url')) ?></label>
                  <input type="url" name="portfolio_url" class="auth-input" placeholder="<?= e(__('register.portfolio_placeholder')) ?>" value="<?= e($_POST['portfolio_url'] ?? '') ?>">
                </div>
                <div>
                  <label class="block text-sm font-medium mb-2" style="color:var(--color-text-secondary)"><?= e(__('register.skills')) ?></label>
                  <div class="grid-skills">
                    <?php foreach ($skills as $skill): ?>
                      <div>
                        <input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>" id="skill_<?= (int) $skill['id'] ?>" class="skill-checkbox" <?= in_array((string) $skill['id'], $_POST['skills'] ?? [], true) ? 'checked' : '' ?>>
                        <label for="skill_<?= (int) $skill['id'] ?>" class="skill-label">
                          <svg class="w-3 h-3 check-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                          <?= e($skill['skill_name']) ?>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
          </div><!-- /freelancerFields -->

          <!-- Back + Submit -->
          <div class="mt-6 flex gap-3">
            <button type="button" class="flex-1 py-3 rounded-xl border-2 font-semibold text-sm transition-all hover:bg-gray-50 dark:hover:bg-white/5" style="border-color:var(--color-border);color:var(--color-text-secondary)" onclick="goStep1()">
              ← Back
            </button>
            <button type="submit" class="auth-submit flex-1" id="btnSubmit">
              <span class="relative z-10 flex items-center justify-center gap-2">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                <?= e(__('register.submit')) ?>
              </span>
            </button>
          </div>
        </div><!-- /formStep2 -->

      </form>

      <!-- Social login -->
      <div class="mt-6">
        <div class="auth-divider"><span><?= __('login.or_continue') ?></span></div>
        <div class="flex gap-3 mt-4">
          <button type="button" class="social-btn" onclick="alert('Google registration coming soon.')">
            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/></svg>
            Google
          </button>
          <button type="button" class="social-btn" onclick="alert('GitHub registration coming soon.')">
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0024 12c0-6.63-5.37-12-12-12z"/></svg>
            GitHub
          </button>
        </div>
      </div>
    </div><!-- /card -->

    <!-- Login link -->
    <p class="mt-6 text-center text-sm" style="color:var(--color-text-muted)">
      <?= e(__('register.has_account')) ?>
      <a href="<?= e(base_url('login.php')) ?>" class="font-semibold text-indigo-600 hover:text-indigo-500 transition-colors"><?= e(__('register.login_link')) ?></a>
    </p>
  </div><!-- /auth-card -->
</div><!-- /auth-wrapper -->

<script>
/* ── Helpers ─────────────────────────────────────────────────── */
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  var open = btn.querySelector('.eye-open');
  var closed = btn.querySelector('.eye-closed');
  if (inp.type === 'password') {
    inp.type = 'text';
    open.classList.add('hidden');
    closed.classList.remove('hidden');
  } else {
    inp.type = 'password';
    open.classList.remove('hidden');
    closed.classList.add('hidden');
  }
}

// Fixed: now uses the passed previewId and imgId arguments (was hardcoding 'filePreview')
function previewFile(event, previewId, imgId, sizeClass) {
  var file = event.target.files[0];
  var preview = document.getElementById(previewId);
  var img = document.getElementById(imgId);
  if (file) {
    var reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      img.className = sizeClass + ' object-contain';
      preview.classList.add('visible');
    };
    reader.readAsDataURL(file);
  } else {
    preview.classList.remove('visible');
    img.src = '';
  }
}

function clearFile(inputId, previewId) {
  document.getElementById(inputId).value = '';
  var preview = document.getElementById(previewId);
  preview.classList.remove('visible');
  var img = preview.querySelector('img');
  if (img) img.src = '';
}

/* ── Role selection ──────────────────────────────────────────── */
function selectRole(role) {
  document.querySelectorAll('.role-card').forEach(function(c) { c.classList.remove('active'); });
  document.querySelectorAll('input[name="role"]').forEach(function(r) { if (r.value === role) r.checked = true; });
  document.querySelector('.role-card input[value="' + role + '"]').closest('.role-card').classList.add('active');
  document.getElementById('companyFields').classList.toggle('open', role === 'company');
  document.getElementById('freelancerFields').classList.toggle('open', role === 'freelancer');
  document.querySelectorAll('.role-card svg').forEach(function(svg) { svg.style.color = ''; });
  document.querySelectorAll('.role-card.active svg').forEach(function(svg) { svg.style.color = '#4f46e5'; });
}

/* ── Step navigation ─────────────────────────────────────────── */
function setStep(n) {
  for (var i = 1; i <= 3; i++) {
    var si = document.getElementById('step' + i);
    si.classList.remove('active', 'done');
    if (i < n) si.classList.add('done');
    else if (i === n) si.classList.add('active');
  }
  document.getElementById('formStep1').style.display = n === 1 ? 'block' : 'none';
  document.getElementById('formStep2').style.display = n === 2 ? 'block' : 'none';
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goStep2() {
  // Basic client-side validation for step 1
  var username = document.getElementById('regUsername').value.trim();
  var email    = document.getElementById('regEmail').value.trim();
  var pw       = document.getElementById('regPassword').value;
  var pwc      = document.getElementById('regConfirm').value;

  if (!username) { flashError('Username is required.'); return; }
  if (!email || !email.includes('@')) { flashError('A valid email is required.'); return; }
  if (pw.length < 6) { flashError('Password must be at least 6 characters.'); return; }
  if (pw !== pwc) { flashError('Passwords do not match.'); return; }
  setStep(2);
}

function goStep1() { setStep(1); }

function flashError(msg) {
  var existing = document.getElementById('clientError');
  if (existing) existing.remove();
  var div = document.createElement('div');
  div.id = 'clientError';
  div.className = 'shake mb-4 p-3 rounded-xl flex items-center gap-2 text-sm font-medium';
  div.style.cssText = 'background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.15);';
  div.innerHTML = '<svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><span>' + msg + '</span>';
  document.getElementById('formStep1').prepend(div);
}

/* ── Password strength ───────────────────────────────────────── */
document.getElementById('regPassword').addEventListener('input', function() {
  var val = this.value;
  var bar = document.getElementById('pwStrengthBar');
  var text = document.getElementById('pwStrengthText');
  var strength = 0;
  if (val.length >= 6) strength += 25;
  if (val.length >= 10) strength += 25;
  if (/[A-Z]/.test(val)) strength += 15;
  if (/[a-z]/.test(val)) strength += 10;
  if (/[0-9]/.test(val)) strength += 10;
  if (/[^A-Za-z0-9]/.test(val)) strength += 15;
  bar.style.width = Math.min(strength, 100) + '%';
  if (strength < 30)      { bar.style.background = '#ef4444'; text.textContent = 'Weak'; text.style.color = '#ef4444'; }
  else if (strength < 60) { bar.style.background = '#f59e0b'; text.textContent = 'Fair'; text.style.color = '#f59e0b'; }
  else if (strength < 85) { bar.style.background = '#10b981'; text.textContent = 'Good'; text.style.color = '#10b981'; }
  else                    { bar.style.background = '#059669'; text.textContent = 'Strong'; text.style.color = '#059669'; }
});

/* ── Confirm password ────────────────────────────────────────── */
document.getElementById('regConfirm').addEventListener('input', function() {
  var pw = document.getElementById('regPassword').value;
  var match = document.getElementById('matchText');
  if (this.value.length === 0) {
    match.textContent = '';
  } else if (this.value === pw) {
    match.innerHTML = '<span style="color:#10b981">&#10003; Passwords match</span>';
  } else {
    match.innerHTML = '<span style="color:#ef4444">&#10007; Passwords do not match</span>';
  }
});

/* ── Char counter ────────────────────────────────────────────── */
function charCounter(el, counterId, max) {
  var n = el.value.length;
  var counter = document.getElementById(counterId);
  counter.textContent = n + ' / ' + max;
  counter.classList.remove('warn', 'over');
  if (n > max * 0.85) counter.classList.add('warn');
  if (n >= max)       counter.classList.add('over');
}

// Init char counter if description has pre-filled value (on error re-render)
(function(){
  var desc = document.getElementById('companyDesc');
  if (desc && desc.value.length) charCounter(desc, 'descCounter', 800);
})();

/* ── If server returned error, jump to correct step ──────────── */
(function() {
  <?php if ($error !== ''): ?>
  // If the error is about company/freelancer-specific fields, go to step 2
  var step2Errors = ['<?= e(__('error.company_name_required')) ?>', '<?= e(__('error.full_name_required')) ?>'];
  var msg = '<?= e(addslashes($error)) ?>';
  var inStep2 = step2Errors.indexOf(msg) !== -1;
  if (inStep2) { setStep(2); } else { setStep(1); }
  <?php endif; ?>
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
