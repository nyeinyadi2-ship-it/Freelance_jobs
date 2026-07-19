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
    if (!verify_csrf()) { $error = __('error.invalid_request'); }
    else {
        $full_name = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $experience_years = (int) ($_POST['experience_years'] ?? 0);
        $hourly_rate = (float) ($_POST['hourly_rate'] ?? 0);
        $selected_skills = $_POST['skills'] ?? [];
        if ($full_name === '') $error = __('profile.name_required');
        elseif ($hourly_rate < 0) $error = __('profile.rate_min');
        else {
            $old_img = $fl_profile['profile_image'];
            $new_img = $old_img;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['profile_image']);
                if ($uploaded) $new_img = $uploaded; else $error = __('upload.invalid_profile');
            }
            if ($error === '') {
                $conn->begin_transaction();
                try {
                    $st = $conn->prepare("UPDATE users SET profile_image=? WHERE id=?");
                    $st->bind_param('si', $new_img, $fl_uid); $st->execute(); $st->close();
                    $ey = $experience_years > 0 ? $experience_years : null;
                    $hr = $hourly_rate > 0 ? $hourly_rate : null;
                    $st = $conn->prepare("UPDATE freelancers SET full_name=?,title=?,phone=?,location=?,bio=?,experience_years=?,hourly_rate=? WHERE id=?");
                    $st->bind_param('sssssidi', $full_name, $title, $phone, $location, $bio, $ey, $hr, $fl_freelancer_id);
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
                    $success = __('profile.updated');
                    $fl_profile['full_name']=$full_name; $fl_profile['title']=$title; $fl_profile['phone']=$phone;
                    $fl_profile['location']=$location; $fl_profile['bio']=$bio;
                    $fl_profile['experience_years']=$ey; $fl_profile['hourly_rate']=$hr; $fl_profile['profile_image']=$new_img;
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

// Fetch portfolio items (limited to 4 for preview)
$fl_portfolio_items = [];
$r = $conn->prepare("SELECT id, title, description, cover_image, project_url FROM portfolio_items WHERE freelancer_id = ? ORDER BY sort_order ASC, id DESC LIMIT 4");
$r->bind_param('i', $fl_freelancer_id);
$r->execute();
$rr = $r->get_result();
while ($row = $rr->fetch_assoc()) { $fl_portfolio_items[] = $row; }
$r->close();
$fl_portfolio_count = 0;
$r = $conn->prepare("SELECT COUNT(*) AS cnt FROM portfolio_items WHERE freelancer_id = ?");
$r->bind_param('i', $fl_freelancer_id);
$r->execute();
$fl_portfolio_count = (int) $r->get_result()->fetch_assoc()['cnt'];
$r->close();
?>

<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-2 pb-12">
<?php if ($error): ?><div class="mb-6 p-4 rounded-2xl bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 text-sm font-medium reveal"><?= e($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-6 p-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 text-sm font-medium reveal"><?= e($success) ?></div><?php endif; ?>

<!-- Profile Header -->
<div class="glass rounded-2xl p-6 mb-6 hover-lift reveal">
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
            <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:-translate-y-0.5 transition-all">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                View Portfolio
            </a>
            <?php if ($is_edit): ?><a href="<?= e(base_url('freelancer/profile.php')) ?>" class="px-5 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Cancel</a><?php else: ?><a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-grad px-5 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Edit Profile</a><?php endif; ?>
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
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Phone</label><input type="text" name="phone" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['phone'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Location</label><input type="text" name="location" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['location'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Experience (Years)</label><input type="number" name="experience_years" min="0" max="100" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['experience_years'] ?? '') ?>"></div>
                <div><label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Hourly Rate ($)</label><input type="number" name="hourly_rate" min="0" step="0.50" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)" value="<?= e($fl_profile['hourly_rate'] ?? '') ?>"></div>

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
                <?php if ($fl_profile['hourly_rate'] !== null): ?><div class="flex justify-between"><dt style="color:var(--color-text-muted)">Hourly Rate</dt><dd class="font-semibold text-emerald-600">$<?= number_format((float) $fl_profile['hourly_rate'], 2) ?> / hr</dd></div><?php endif; ?>

                <div class="flex justify-between"><dt style="color:var(--color-text-muted)">Joined</dt><dd class="font-semibold" style="color:var(--color-text-primary)"><?= date('F j, Y', strtotime($fl_profile['created_at'])) ?></dd></div>
            </dl>
        </div>
    </div>

    <!-- Portfolio Section -->
    <?php if ($fl_portfolio_count > 0): ?>
    <div class="mt-6 glass rounded-2xl p-6 hover-lift reveal">
        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Portfolio</h2>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400"><?= $fl_portfolio_count ?></span>
            </div>
            <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold rounded-xl bg-gradient-to-r from-indigo-500 to-purple-600 text-white shadow-md shadow-indigo-500/25 hover:shadow-lg hover:-translate-y-0.5 transition-all">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                View Portfolio
            </a>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ($fl_portfolio_items as $pi): ?>
                <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="group block rounded-xl overflow-hidden border transition-all hover:shadow-lg hover:-translate-y-1" style="border-color:var(--color-border)">
                    <?php if ($pi['cover_image']): ?>
                        <div class="aspect-video overflow-hidden bg-gray-100 dark:bg-gray-800">
                            <img src="<?= e(base_url('uploads/' . $pi['cover_image'])) ?>" alt="<?= e($pi['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                        </div>
                    <?php else: ?>
                        <div class="aspect-video flex items-center justify-center" style="background:linear-gradient(135deg,rgba(99,102,241,0.08),rgba(168,85,247,0.08))">
                            <svg class="w-10 h-10 text-indigo-300 dark:text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.75 21h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v13.5A1.5 1.5 0 003.75 21z"/></svg>
                        </div>
                    <?php endif; ?>
                    <div class="p-3">
                        <p class="text-sm font-semibold truncate group-hover:text-primary-600 transition-colors" style="color:var(--color-text-primary)"><?= e($pi['title']) ?></p>
                        <?php if ($pi['description']): ?>
                            <p class="text-xs mt-1 line-clamp-2" style="color:var(--color-text-muted)"><?= e(mb_strimwidth($pi['description'], 0, 60, '...')) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($fl_portfolio_count > 4): ?>
            <div class="mt-4 text-center">
                <a href="<?= e(base_url('freelancer/portfolio.php')) ?>" class="inline-flex items-center gap-1.5 text-sm font-semibold text-primary-600 hover:text-primary-700 transition-colors">
                    View all <?= $fl_portfolio_count ?> projects
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

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
</script>

<?php require __DIR__ . '/../includes/freelancer_footer.php'; ?>
