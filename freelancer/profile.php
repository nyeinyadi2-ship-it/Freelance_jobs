<?php
$page_title = 'My Profile';
require __DIR__ . '/../includes/freelancer_layout.php';
require_once __DIR__ . '/../config/upload.php';

$all_skills = [];
$r = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
if ($r) while ($row = $r->fetch_assoc()) $all_skills[] = $row;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['edit'])) {
    if (!verify_csrf()) { $error = 'Invalid request. Please try again.'; }
    else {
        $full_name = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $experience_years = (int) ($_POST['experience_years'] ?? 0);
        $hourly_rate = (float) ($_POST['hourly_rate'] ?? 0);
        $selected_skills = $_POST['skills'] ?? [];
        $payment_method = trim($_POST['payment_method'] ?? '');
        $payment_account_name = trim($_POST['payment_account_name'] ?? '');
        $payment_account_number = trim($_POST['payment_account_number'] ?? '');
        $payment_bank_name = trim($_POST['payment_bank_name'] ?? '');
        if ($full_name === '') $error = 'Full name is required.';
        elseif ($phone !== '' && !preg_match('/^09[0-9]{9}$/', $phone)) $error = 'Invalid phone number format. Must be an 11-digit Myanmar local number starting with 09 (e.g., 09xxxxxxxxx).';
        elseif ($hourly_rate < 0) $error = 'Hourly rate must be 0 or greater.';
        else {
            $old_img = $fl_profile['profile_image'];
            $new_img = $old_img;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['profile_image'], 10 * 1024 * 1024, $upload_err);
                if ($uploaded) $new_img = $uploaded; else $error = $upload_err ?: 'Invalid profile image. Allowed types: jpg, png, gif, webp. Max size: 10MB.';
            }
            if ($error === '') {
                $conn->begin_transaction();
                try {
                    $st = $conn->prepare("UPDATE users SET profile_image=? WHERE id=?");
                    $st->bind_param('si', $new_img, $fl_uid); $st->execute(); $st->close();
                    $ey = $experience_years > 0 ? $experience_years : null;
                    $hr = $hourly_rate > 0 ? $hourly_rate : null;
                    $st = $conn->prepare("UPDATE freelancers SET full_name=?,title=?,phone=?,location=?,bio=?,experience_years=?,hourly_rate=?,payment_method=?,payment_account_name=?,payment_account_number=?,payment_bank_name=? WHERE id=?");
                    $st->bind_param('sssssidssssi', $full_name, $title, $phone, $location, $bio, $ey, $hr, $payment_method, $payment_account_name, $payment_account_number, $payment_bank_name, $fl_freelancer_id);
                    $st->execute(); $st->close();
                    $st = $conn->prepare("DELETE FROM freelancer_skills WHERE freelancer_id=?");
                    $st->bind_param('i', $fl_freelancer_id); $st->execute(); $st->close();
                    if (!empty($selected_skills)) {
                        $ss = $conn->prepare('INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)');
                        foreach ($selected_skills as $sid) { $sid = (int) $sid; $ss->bind_param('ii', $fl_freelancer_id, $sid); $ss->execute(); }
                        $ss->close();
                    }
                    $conn->commit();
                    if ($new_img !== $old_img && $old_img) delete_upload($old_img);
                    $_SESSION['profile_image'] = $new_img;
                    $success = 'Profile updated successfully.';
                    $fl_profile['full_name']=$full_name; $fl_profile['title']=$title; $fl_profile['phone']=$phone;
                    $fl_profile['location']=$location; $fl_profile['bio']=$bio;
                    $fl_profile['experience_years']=$ey; $fl_profile['hourly_rate']=$hr; $fl_profile['profile_image']=$new_img;
                    $fl_profile['payment_method']=$payment_method; $fl_profile['payment_account_name']=$payment_account_name;
                    $fl_profile['payment_account_number']=$payment_account_number; $fl_profile['payment_bank_name']=$payment_bank_name;
                    $fl_profile_skills = array_map('intval', $selected_skills);
                } catch (Exception $e) { $conn->rollback(); $error = $e->getMessage(); }
            }
        }
    }
}

$is_edit = isset($_GET['edit']);
$profileImgUrl = profile_image_url($fl_profile['profile_image']);

// Fetch rating stats
$fl_avg_rating = 0;
$fl_total_reviews = 0;
$r = $conn->prepare("SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS total FROM reviews WHERE freelancer_id = ?");
$r->bind_param('i', $fl_freelancer_id);
$r->execute();
$rating_data = $r->get_result()->fetch_assoc();
$r->close();
$fl_avg_rating = round((float) $rating_data['avg_rating'], 1);
$fl_total_reviews = (int) $rating_data['total'];

// Fetch recent reviews
$fl_reviews = [];
$r = $conn->prepare("
    SELECT r.rating, r.comment, r.created_at, c.company_name, u.profile_image AS reviewer_image
    FROM reviews r
    JOIN users u ON r.company_user_id = u.id
    LEFT JOIN companies c ON r.company_user_id = c.user_id
    WHERE r.freelancer_id = ?
    ORDER BY r.created_at DESC
    LIMIT 5
");
$r->bind_param('i', $fl_freelancer_id);
$r->execute();
$rr = $r->get_result();
while ($row = $rr->fetch_assoc()) { $fl_reviews[] = $row; }
$r->close();


?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">
<?php if ($error): ?><div class="mb-6 p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-medium reveal"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm font-medium reveal"><?= e($success) ?></div><?php endif; ?>

<!-- Profile Header -->
<div class="glass rounded-2xl p-6 mb-6 hover-lift reveal">
    <div class="mb-4">
        <button onclick="history.back()" class="inline-flex items-center gap-2 px-3 py-1.5 text-sm font-medium text-gray-600 hover:text-gray-900 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors dark:text-gray-300 dark:hover:text-white dark:bg-slate-800 dark:hover:bg-slate-700">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Back
        </button>
    </div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-4">
            <?php if ($profileImgUrl): ?><img src="<?= e($profileImgUrl) ?>" alt="" class="w-16 h-16 rounded-2xl object-cover border-2" style="border-color:var(--color-border)"><?php else: ?><div class="w-16 h-16 rounded-2xl flex items-center justify-center font-bold text-2xl" style="background:linear-gradient(135deg,#6366f1,#a855f7);color:white"><?= strtoupper(mb_substr($fl_profile['full_name'] ?? $fl_profile['username'] ?? 'U', 0, 1)) ?></div><?php endif; ?>
            <div><h1 class="text-xl font-bold" style="color:var(--color-text-primary)"><?= e($fl_profile['full_name'] ?? $fl_profile['username']) ?></h1><?php if ($fl_profile['title']): ?><p class="text-sm font-medium text-primary-600"><?= e($fl_profile['title']) ?></p><?php endif; ?>
                <div class="flex items-center gap-3 mt-1">
                    <p class="text-xs" style="color:var(--color-text-placeholder)">Joined <?= date('M Y', strtotime($fl_profile['created_at'])) ?></p>
                    <?php if ($fl_total_reviews > 0): ?>
                        <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:var(--color-text-secondary)">
                            <svg class="w-3.5 h-3.5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            <?= $fl_avg_rating ?> (<?= $fl_total_reviews ?> review<?= $fl_total_reviews !== 1 ? 's' : '' ?>)
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="flex gap-2">

            <?php if ($is_edit): ?><a href="javascript:history.back()" class="px-5 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Back</a><?php else: ?><a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-grad px-5 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Edit Profile</a><?php endif; ?>
        </div>
    </div>
</div>

<?php if ($is_edit): ?>
    <form method="POST" class="space-y-6" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="glass rounded-2xl p-6 reveal">
            <h2 class="text-lg font-bold mb-5" style="color:var(--color-text-primary)">Basic Information</h2>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Full Name *</label><input type="text" name="full_name" required class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['full_name']) ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Professional Title</label><input type="text" name="title" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="e.g. Full Stack Developer" value="<?= e($fl_profile['title'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Email</label><input type="email" class="w-full px-4 py-2.5 rounded-xl text-sm opacity-60 cursor-not-allowed" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['email']) ?>" readonly><p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Email cannot be changed.</p></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Phone</label><input type="tel" name="phone" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="09xxxxxxxxx" pattern="^09[0-9]{9}$" maxlength="11" title="Must be an 11-digit Myanmar local number starting with 09 (e.g., 09xxxxxxxxx)" oninvalid="this.setCustomValidity('Must be an 11-digit Myanmar local number starting with 09 (e.g., 09xxxxxxxxx)')" oninput="this.setCustomValidity('')" value="<?= e($fl_profile['phone'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Location</label><input type="text" name="location" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['location'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Experience (Years)</label><input type="number" name="experience_years" min="0" max="100" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['experience_years'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Hourly Rate (MMK)</label><input type="number" name="hourly_rate" min="0" step="0.50" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['hourly_rate'] ?? '') ?>"></div>

            </div>
            <div class="mt-4"><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Bio</label><textarea name="bio" rows="4" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="Tell clients about yourself..."><?= e($fl_profile['bio'] ?? '') ?></textarea></div>
        </div>
        <div class="glass rounded-2xl p-6 reveal reveal-d1">
            <h2 class="text-lg font-bold mb-5" style="color:var(--color-text-primary)">Skills</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                <?php foreach ($all_skills as $skill): ?>
                    <label class="flex items-center gap-2 text-sm p-2.5 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"><input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>" <?= in_array((int) $skill['id'], $fl_profile_skills) ? 'checked' : '' ?> class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"><?= e($skill['skill_name']) ?></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="glass rounded-2xl p-6 reveal reveal-d2">
            <h2 class="text-lg font-bold mb-5" style="color:var(--color-text-primary)">Profile Image</h2>
            <div class="flex items-center gap-4">
                <?php if ($fl_profile['profile_image']): ?><img src="<?= e(profile_image_url($fl_profile['profile_image'])) ?>" class="w-16 h-16 rounded-2xl object-cover border" style="border-color:var(--color-border)" id="profilePreview"><?php else: ?><div class="w-16 h-16 rounded-2xl flex items-center justify-center font-bold text-xl" style="background:linear-gradient(135deg,#6366f1,#a855f7);color:white" id="profilePreviewPlaceholder"><?= strtoupper(mb_substr($fl_profile['full_name'] ?? $fl_profile['username'] ?? 'U', 0, 1)) ?></div><?php endif; ?>
                <div><input type="file" name="profile_image" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm" onchange="previewImage(this, 'profilePreview')"><p class="text-xs mt-1" style="color:var(--color-text-placeholder)">JPG, PNG, GIF, WebP. Max 2MB.</p></div>
            </div>
        </div>
        <div class="glass rounded-2xl p-6 reveal reveal-d3">
            <h2 class="text-lg font-bold mb-5" style="color:var(--color-text-primary)">Payment Settings</h2>
            <div class="mb-5">
                <label class="block text-sm font-medium mb-3" style="color:var(--color-text-secondary)">Preferred Payment Method</label>
                <div class="grid sm:grid-cols-3 gap-4">
                    <label class="flex items-center gap-3 p-4 rounded-xl cursor-pointer border hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors" style="border-color:var(--color-border);background:var(--color-bg)">
                        <input type="radio" name="payment_method" value="kpay" <?= $fl_profile['payment_method'] === 'kpay' ? 'checked' : '' ?> class="w-4 h-4 text-primary-600 focus:ring-primary-500" onchange="togglePaymentFields()">
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)">KPay</span>
                    </label>
                    <label class="flex items-center gap-3 p-4 rounded-xl cursor-pointer border hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors" style="border-color:var(--color-border);background:var(--color-bg)">
                        <input type="radio" name="payment_method" value="wavepay" <?= $fl_profile['payment_method'] === 'wavepay' ? 'checked' : '' ?> class="w-4 h-4 text-primary-600 focus:ring-primary-500" onchange="togglePaymentFields()">
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)">WavePay</span>
                    </label>
                    <label class="flex items-center gap-3 p-4 rounded-xl cursor-pointer border hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors" style="border-color:var(--color-border);background:var(--color-bg)">
                        <input type="radio" name="payment_method" value="bank_transfer" <?= $fl_profile['payment_method'] === 'bank_transfer' ? 'checked' : '' ?> class="w-4 h-4 text-primary-600 focus:ring-primary-500" onchange="togglePaymentFields()">
                        <span class="text-sm font-semibold" style="color:var(--color-text-primary)">Bank Transfer</span>
                    </label>
                </div>
            </div>
            
            <div id="payment-fields-wrapper" class="grid md:grid-cols-2 gap-4 <?= empty($fl_profile['payment_method']) ? 'hidden' : '' ?>">
                <div id="bank-name-field" class="<?= $fl_profile['payment_method'] === 'bank_transfer' ? '' : 'hidden' ?>">
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Bank Name</label>
                    <input type="text" name="payment_bank_name" id="payment_bank_name_input" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" placeholder="e.g. KBZ Bank" value="<?= e($fl_profile['payment_bank_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Account Name</label>
                    <input type="text" name="payment_account_name" id="payment_account_name_input" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['payment_account_name'] ?? '') ?>">
                </div>
                <div>
                    <label id="payment-number-label" class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)"><?= $fl_profile['payment_method'] === 'bank_transfer' ? 'Account Number' : 'Phone Number' ?></label>
                    <input type="text" name="payment_account_number" id="payment_account_number_input" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['payment_account_number'] ?? '') ?>">
                </div>
            </div>
        </div>
        <div class="flex justify-end gap-3">
            <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="px-5 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Cancel</a>
            <button type="submit" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Save Changes</button>
        </div>
    </form>
<?php else: ?>
    <div class="grid md:grid-cols-2 gap-6">
        <div class="glass rounded-2xl p-6 hover-lift reveal">
            <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)">About</h2>
            <?php if ($fl_profile['bio']): ?><p class="text-sm leading-relaxed whitespace-pre-wrap" style="color:var(--color-text-secondary)"><?= e($fl_profile['bio']) ?></p><?php else: ?><p class="text-sm italic" style="color:var(--color-text-placeholder)">No bio added yet.</p><?php endif; ?>
            <?php if (!empty($fl_profile_skills)): ?><div class="mt-5"><h3 class="text-xs font-semibold mb-2 uppercase tracking-wider" style="color:var(--color-text-muted)">Skills</h3><div class="flex flex-wrap gap-1.5"><?php foreach ($fl_profile_skills as $sid): ?><span class="badge-skill inline-flex px-3 py-1 text-xs font-medium rounded-xl" style="background:rgba(99,102,241,0.1);color:#4f46e5"><?= e($fl_skill_names[$sid] ?? 'Unknown') ?></span><?php endforeach; ?></div></div><?php endif; ?>
        </div>
        <div class="glass rounded-2xl p-6 hover-lift reveal reveal-d1">
            <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)">Details</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt style="color:var(--color-text-muted)">Full Name</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['full_name']) ?></dd></div>
                <?php if ($fl_profile['title']): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Title</dt><dd class="font-semibold text-primary-600"><?= e($fl_profile['title']) ?></dd></div><?php endif; ?>
                <div class="flex justify-between"><dt style="color:var(--color-text-muted)">Email</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['email']) ?></dd></div>
                <?php if ($fl_profile['phone']): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Phone</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['phone']) ?></dd></div><?php endif; ?>
                <?php if ($fl_profile['location']): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Location</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['location']) ?></dd></div><?php endif; ?>
                <?php if ($fl_profile['experience_years'] !== null): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Experience</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= (int) $fl_profile['experience_years'] ?> year<?= (int) $fl_profile['experience_years'] !== 1 ? 's' : '' ?></dd></div><?php endif; ?>
                <?php if ($fl_profile['hourly_rate'] !== null): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Hourly Rate</dt><dd class="font-semibold text-emerald-600"><?= number_format((float) $fl_profile['hourly_rate'], 2) ?> MMK / hr</dd></div><?php endif; ?>

                <div class="flex justify-between"><dt style="color:var(--color-text-muted)">Joined</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= date('F j, Y', strtotime($fl_profile['created_at'])) ?></dd></div>
            </dl>
        </div>

        <?php if (!empty($fl_profile['payment_method'])): ?>
        <div class="glass rounded-2xl p-6 hover-lift reveal reveal-d2 md:col-span-2">
            <h2 class="text-lg font-bold mb-4" style="color:var(--color-text-primary)">Payment Settings</h2>
            <div class="flex items-center gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border" style="border-color:var(--color-border)">
                <div class="w-12 h-12 rounded-xl flex items-center justify-center bg-white dark:bg-slate-700 shadow-sm flex-shrink-0">
                    <svg class="w-6 h-6 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold capitalize" style="color:var(--color-text-primary)"><?= e(str_replace('_', ' ', $fl_profile['payment_method'])) ?></h3>
                    <p class="text-sm mt-1" style="color:var(--color-text-secondary)">
                        <?= e($fl_profile['payment_account_name']) ?> &bull; 
                        <?= e($fl_profile['payment_account_number']) ?>
                        <?php if ($fl_profile['payment_method'] === 'bank_transfer' && !empty($fl_profile['payment_bank_name'])): ?>
                            &bull; <?= e($fl_profile['payment_bank_name']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>



    <!-- Reviews Section -->
    <?php if ($fl_total_reviews > 0): ?>
    <div class="mt-6 glass rounded-2xl p-6 hover-lift reveal">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Reviews</h2>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                <span class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= $fl_avg_rating ?> / 5</span>
                <span class="text-xs" style="color:var(--color-text-muted)">(<?= $fl_total_reviews ?>)</span>
            </div>
        </div>
        <div class="space-y-4">
            <?php foreach ($fl_reviews as $review): ?>
                <div class="p-4 rounded-xl" style="background:var(--color-card-hover,rgba(0,0,0,0.03));border:1px solid var(--color-border)">
                    <div class="flex items-start gap-3">
                        <?php $rev_img = profile_image_url($review['reviewer_image']); ?>
                        <?php if ($rev_img): ?>
                            <img src="<?= e($rev_img) ?>" alt="" class="w-10 h-10 rounded-full object-cover flex-shrink-0">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center text-indigo-600 font-bold text-sm flex-shrink-0" style="background:rgba(99,102,241,0.1)">
                                <?= strtoupper(mb_substr($review['company_name'] ?? 'C', 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-semibold" style="color:var(--color-text-primary)"><?= e($review['company_name'] ?? 'Company') ?></p>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <svg class="w-3 h-3 <?= $s <= $review['rating'] ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                <span class="text-xs flex-shrink-0" style="color:var(--color-text-placeholder)"><?= e(date('M j, Y', strtotime($review['created_at']))) ?></span>
                            </div>
                            <?php if ($review['comment']): ?>
                                <p class="text-sm mt-2 leading-relaxed" style="color:var(--color-text-secondary)"><?= nl2br(e($review['comment'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
</div>

<script>
function previewImage(input, imgId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById(imgId);
            if (!img) { img = new Image(); img.id = imgId; img.className = 'w-16 h-16 rounded-2xl object-cover border'; input.parentNode.insertBefore(img, input); var ph = document.getElementById(imgId + 'Placeholder'); if (ph) ph.style.display = 'none'; }
            img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function togglePaymentFields() {
    var method = document.querySelector('input[name="payment_method"]:checked');
    if (!method) return;
    
    document.getElementById('payment-fields-wrapper').classList.remove('hidden');
    var isBank = method.value === 'bank_transfer';
    
    document.getElementById('bank-name-field').classList.toggle('hidden', !isBank);
    document.getElementById('payment-number-label').textContent = isBank ? 'Account Number' : 'Phone Number';
    
    // Add required attributes dynamically
    if (isBank) {
        document.getElementById('payment_bank_name_input').setAttribute('required', 'required');
    } else {
        document.getElementById('payment_bank_name_input').removeAttribute('required');
    }
    document.getElementById('payment_account_name_input').setAttribute('required', 'required');
    document.getElementById('payment_account_number_input').setAttribute('required', 'required');
}

// Initial toggle if editing
if (document.querySelector('input[name="payment_method"]')) {
    togglePaymentFields();
}
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
