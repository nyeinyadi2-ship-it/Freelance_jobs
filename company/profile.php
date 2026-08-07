<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/upload.php';

// Set CSRF cookie early (before any HTML output)
csrf_cookie();

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

$stmt = $conn->prepare("
    SELECT c.*, u.email, u.profile_image, u.created_at
    FROM companies c
    JOIN users u ON u.id = c.user_id
    WHERE c.id = ?
");
$stmt->bind_param('i', $company_id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$profile) {
    set_flash('error', 'Profile not found.');
    redirect('auth/login.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['edit'])) {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $company_name = trim($_POST['company_name'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $established_year = (int) ($_POST['established_year'] ?? 0);

        if ($company_name === '') {
            $error = 'Company name is required.';
        } else {
            $old_profile_image = $profile['profile_image'];
            $old_logo = $profile['logo_image'];
            $new_profile_image = $old_profile_image;
            $new_logo = $old_logo;

            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['profile_image'], 10 * 1024 * 1024, $upload_err);
                if ($uploaded) {
                    $new_profile_image = $uploaded;
                } else {
                    $error = $upload_err ?: 'Invalid profile image. Allowed types: jpg, png, gif, webp. Max size: 10MB.';
                }
            }

            if ($error === '' && isset($_FILES['logo_image']) && $_FILES['logo_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['logo_image'], 10 * 1024 * 1024, $upload_err);
                if ($uploaded) {
                    $new_logo = $uploaded;
                } else {
                    $error = $upload_err ?: 'Invalid company logo. Allowed types: jpg, png, gif, webp. Max size: 10MB.';
                }
            }

            if ($error === '') {
                $conn->begin_transaction();
                try {
                    $stmt = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                    $stmt->bind_param('si', $new_profile_image, $user['user_id']);
                    $stmt->execute();
                    $stmt->close();

                    $ey = $established_year > 0 ? $established_year : null;
                    $stmt = $conn->prepare("UPDATE companies SET company_name = ?, website = ?, phone = ?, location = ?, description = ?, logo_image = ?, established_year = ? WHERE id = ?");
                    $stmt->bind_param('ssssssii', $company_name, $website, $phone, $location, $description, $new_logo, $ey, $company_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    if ($new_profile_image !== $old_profile_image && $old_profile_image) delete_upload($old_profile_image);
                    if ($new_logo !== $old_logo && $old_logo) delete_upload($old_logo);

                    $_SESSION['profile_image'] = $new_profile_image;
                    $_SESSION['logo_image'] = $new_logo;

                    $success = 'Profile updated successfully.';
                    $profile['company_name'] = $company_name;
                    $profile['website'] = $website;
                    $profile['phone'] = $phone;
                    $profile['location'] = $location;
                    $profile['description'] = $description;
                    $profile['logo_image'] = $new_logo;
                    $profile['profile_image'] = $new_profile_image;
                    $profile['established_year'] = $ey;
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = $e->getMessage();
                }
            }
        }
    }
}

$is_edit = isset($_GET['edit']);
$page_title = $is_edit ? 'Edit Company Profile' : 'Company Profile';
require __DIR__ . '/../includes/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-4">
            <?php if (!empty($profile['logo_image'])): ?>
                <img src="<?= e(base_url('uploads/images/' . $profile['logo_image'])) ?>" alt="" class="w-14 h-14 rounded-xl object-contain border bg-white dark:bg-gray-800" style="border-color:var(--color-border)">
            <?php elseif ($profileImgUrl = profile_image_url($profile['profile_image'])): ?>
                <img src="<?= e($profileImgUrl) ?>" alt="" class="w-14 h-14 rounded-full object-cover border" style="border-color:var(--color-border)">
            <?php else: ?>
                <div class="w-14 h-14 rounded-full flex items-center justify-center text-indigo-600 font-bold text-xl border" style="background:rgba(99,102,241,0.2);border-color:var(--color-border)">
                    <?= e(_first_char($profile['company_name'] ?? $user['username'])) ?>
                </div>
            <?php endif; ?>
            <div>
                <h1 class="text-2xl font-bold" style="color:var(--color-text-primary)"><?= e($profile['company_name']) ?></h1>
                <p class="text-sm" style="color:var(--color-text-muted)"><?= e($user['username']) ?> &middot; <?= 'Joined' ?> <?= e(date('M Y', strtotime($profile['created_at']))) ?></p>
            </div>
        </div>
        <div class="flex gap-2">
            <?php if ($is_edit): ?>
                <a href="<?= e(base_url('company/profile.php')) ?>" class="btn-secondary text-sm">Cancel</a>
            <?php else: ?>
                <a href="<?= e(base_url('company/profile.php?edit=1')) ?>" class="btn-primary text-sm">Edit Profile</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="mb-4 p-3 bg-green-100 text-green-800 rounded-lg"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($is_edit): ?>
        <form method="POST" class="space-y-6" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="card">
                <h2 class="text-lg font-semibold mb-4">Basic Information</h2>
                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Company Name *</label>
                        <input type="text" name="company_name" required class="form-input" value="<?= e($profile['company_name']) ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" class="form-input bg-gray-50" value="<?= e($profile['email']) ?>" readonly>
                        <p class="text-xs text-gray-400 mt-1">Email cannot be changed.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                        <input type="url" name="website" class="form-input" placeholder="https://example.com" value="<?= e($profile['website'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                        <input type="text" name="phone" class="form-input" placeholder="+1 234 567 8900" value="<?= e($profile['phone'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
                        <input type="text" name="location" class="form-input" placeholder="City, Country" value="<?= e($profile['location'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Established Year</label>
                        <input type="number" name="established_year" class="form-input" min="1900" max="<?= date('Y') ?>" placeholder="2020" value="<?= e($profile['established_year'] ?? '') ?>">
                    </div>
                </div>
                <div class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea name="description" rows="4" class="form-input"><?= e($profile['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold mb-4">Profile Image &amp; Logo</h2>
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Profile Image</label>
                        <div class="flex items-center gap-4">
                            <?php if ($profile['profile_image']): ?>
                                <img src="<?= e(profile_image_url($profile['profile_image'])) ?>" class="w-16 h-16 rounded-full object-cover border border-gray-200" id="profilePreview">
                            <?php else: ?>
                                <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold text-xl border border-gray-200" id="profilePreviewPlaceholder">
                                    <?= e(_first_char($profile['company_name'] ?? $user['username'])) ?>
                                </div>
                            <?php endif; ?>
                            <input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm" onchange="previewImage(this, 'profilePreview')">
                        </div>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, GIF, WebP. Max 2MB.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Company Logo</label>
                        <div class="flex items-center gap-4">
                            <?php if ($profile['logo_image']): ?>
                                <img src="<?= e(base_url('uploads/images/' . $profile['logo_image'])) ?>" class="h-12 w-auto object-contain border border-gray-200 rounded-lg p-1" id="logoPreview">
                            <?php endif; ?>
                            <input type="file" name="logo_image" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm" onchange="previewImage(this, 'logoPreview')">
                        </div>
                        <p class="text-xs text-gray-400 mt-1">JPG, PNG, GIF, WebP. Max 2MB.</p>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-3">
                <a href="<?= e(base_url('company/profile.php')) ?>" class="btn-secondary">Cancel</a>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    <?php else: ?>
        <div class="grid md:grid-cols-2 gap-6">
            <div class="card">
                <h2 class="text-lg font-semibold mb-4">Company Details</h2>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Company Name</dt>
                        <dd class="font-medium text-gray-900"><?= e($profile['company_name']) ?></dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Email</dt>
                        <dd class="font-medium text-gray-900"><?= e($profile['email']) ?></dd>
                    </div>
                    <?php if ($profile['website']): ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Website</dt>
                        <dd class="font-medium"><a href="<?= e($profile['website']) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg transition-colors" style="background:rgba(99,102,241,0.1);color:#4f46e5">&#127760; Visit Website</a></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['phone']): ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Phone</dt>
                        <dd class="font-medium text-gray-900"><?= e($profile['phone']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['location']): ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Location</dt>
                        <dd class="font-medium text-gray-900"><?= e($profile['location']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <?php if ($profile['established_year']): ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Established</dt>
                        <dd class="font-medium text-gray-900"><?= e((string) $profile['established_year']) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div class="flex justify-between">
                        <dt class="text-gray-500">Joined</dt>
                        <dd class="font-medium text-gray-900"><?= e(date('F j, Y', strtotime($profile['created_at']))) ?></dd>
                    </div>
                </dl>
            </div>

            <div class="card">
                <h2 class="text-lg font-semibold mb-4">About</h2>
                <?php if ($profile['description']): ?>
                    <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap"><?= e($profile['description']) ?></p>
                <?php else: ?>
                    <p class="text-sm text-gray-400 italic">No description added yet.</p>
                <?php endif; ?>

                <?php if ($profile['logo_image']): ?>
                <div class="mt-6">
                    <h3 class="text-sm font-medium text-gray-500 mb-2">Company Logo</h3>
                    <img src="<?= e(base_url('uploads/images/' . $profile['logo_image'])) ?>" alt="Company Logo" class="h-16 w-auto object-contain border border-gray-200 rounded-lg p-1">
                </div>
                <?php else: ?>
                <div class="mt-6">
                    <h3 class="text-sm font-medium text-gray-500 mb-2">Company Logo</h3>
                    <div class="flex items-center gap-3">
                        <div class="w-16 h-16 rounded-lg bg-gray-100 flex items-center justify-center text-gray-400 border border-gray-200">
                            <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">No logo uploaded yet.</p>
                            <a href="<?= e(base_url('company/profile.php?edit=1')) ?>" class="text-xs text-indigo-600 hover:underline">Upload one →</a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6">
            <?php
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM jobs WHERE company_id = ?");
            $stmt->bind_param('i', $company_id);
            $stmt->execute();
            $total_jobs = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.status = 'completed'");
            $stmt->bind_param('i', $company_id);
            $stmt->execute();
            $hired_count = (int) $stmt->get_result()->fetch_assoc()['cnt'];
            $stmt->close();

            $stmt = $conn->prepare("SELECT COALESCE(SUM(j.budget), 0) AS total FROM assignments a JOIN jobs j ON a.job_id = j.id WHERE j.company_id = ? AND a.status = 'completed'");
            $stmt->bind_param('i', $company_id);
            $stmt->execute();
            $total_paid = (float) $stmt->get_result()->fetch_assoc()['total'];
            $stmt->close();
            ?>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <!-- Total Jobs Posted -->
                <div class="group relative overflow-hidden rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="background:var(--color-card);border:1px solid var(--color-border)">
                    <div class="absolute top-0 right-0 w-24 h-24 rounded-bl-full opacity-[0.07] bg-indigo-500 transition-opacity group-hover:opacity-[0.12]"></div>
                    <div class="relative">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(99,102,241,0.1)">
                            <svg class="w-6 h-6 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <p class="text-3xl font-extrabold tracking-tight" style="color:var(--color-text-primary)"><?= number_format($total_jobs) ?></p>
                        <p class="text-sm mt-1" style="color:var(--color-text-muted)">Total Jobs Posted</p>
                    </div>
                </div>

                <!-- Total Jobs Hired -->
                <div class="group relative overflow-hidden rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="background:var(--color-card);border:1px solid var(--color-border)">
                    <div class="absolute top-0 right-0 w-24 h-24 rounded-bl-full opacity-[0.07] bg-emerald-500 transition-opacity group-hover:opacity-[0.12]"></div>
                    <div class="relative">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(16,185,129,0.1)">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </div>
                        <p class="text-3xl font-extrabold tracking-tight" style="color:var(--color-text-primary)"><?= number_format($hired_count) ?></p>
                        <p class="text-sm mt-1" style="color:var(--color-text-muted)">Total Jobs Hired</p>
                    </div>
                </div>

                <!-- Total Amount Paid -->
                <div class="group relative overflow-hidden rounded-2xl p-6 transition-all duration-300 hover:-translate-y-1 hover:shadow-lg" style="background:var(--color-card);border:1px solid var(--color-border)">
                    <div class="absolute top-0 right-0 w-24 h-24 rounded-bl-full opacity-[0.07] bg-violet-500 transition-opacity group-hover:opacity-[0.12]"></div>
                    <div class="relative">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(139,92,246,0.1)">
                            <svg class="w-6 h-6 text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                            </svg>
                        </div>
                        <p class="text-3xl font-extrabold tracking-tight" style="color:var(--color-text-primary)">$<?= number_format($total_paid, 2) ?></p>
                        <p class="text-sm mt-1" style="color:var(--color-text-muted)">Total Amount Paid</p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function previewImage(input, imgId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById(imgId);
            if (!img) {
                img = new Image();
                img.id = imgId;
                // Use different styles for profile vs logo
                if (imgId === 'logoPreview') {
                    img.className = 'h-12 w-auto object-contain border border-gray-200 rounded-lg p-1';
                } else {
                    img.className = 'w-16 h-16 rounded-full object-cover border border-gray-200';
                }
                input.parentNode.insertBefore(img, input);
                var placeholder = document.getElementById(imgId + 'Placeholder');
                if (placeholder) placeholder.style.display = 'none';
            }
            img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
