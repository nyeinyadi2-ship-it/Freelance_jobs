<?php
require_once __DIR__ . '/../includes/freelancer_init.php';
require_once __DIR__ . '/../config/upload.php';

$target_uid = isset($_GET['id']) ? (int) $_GET['id'] : $fl_uid;
$is_own_profile = ($target_uid === $fl_uid);
$is_edit = isset($_GET['edit']) && $is_own_profile;

// Fetch profile and skills (moved from init to optimize other pages)
$fl_stmt = $conn->prepare("SELECT f.*, u.email, u.profile_image, u.username, u.created_at, u.security_question FROM freelancers f JOIN users u ON u.id = f.user_id WHERE u.id = ?");
$fl_stmt->bind_param('i', $target_uid); $fl_stmt->execute();
$fl_profile = $fl_stmt->get_result()->fetch_assoc(); $fl_stmt->close();

if (!$fl_profile) {
    set_flash('error', 'Freelancer profile not found.');
    redirect('freelancer/dashboard.php');
}

$target_freelancer_id = $fl_profile['id'];

$fl_skill_names = [];
$r = $conn->query("SELECT id, skill_name FROM skills ORDER BY skill_name");
if ($r) while ($row = $r->fetch_assoc()) $fl_skill_names[$row['id']] = $row['skill_name'];
$fl_profile_skills = [];
$r = $conn->query("SELECT skill_id FROM freelancer_skills WHERE freelancer_id = $target_freelancer_id");
if ($r) while ($row = $r->fetch_assoc()) $fl_profile_skills[] = (int) $row['skill_id'];

$all_skills = [];
$r = $conn->query('SELECT id, skill_name FROM skills ORDER BY skill_name');
if ($r) while ($row = $r->fetch_assoc()) $all_skills[] = $row;

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_edit) {
    if (!verify_csrf()) { $error = 'Invalid request. Please try again.'; }
    else {
        $full_name = trim($_POST['full_name'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');        $selected_skills = $_POST['skills'] ?? [];        if ($full_name === '') $error = 'Full name is required.';
        elseif ($phone !== '' && !preg_match('/^09[0-9]{9}$/', $phone)) $error = 'Invalid phone number format. Must be an 11-digit Myanmar local number starting with 09 (e.g., 09xxxxxxxxx).';        else {
            $old_img = $fl_profile['profile_image'];
            $new_img = $old_img;
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = upload_image($_FILES['profile_image'], 10 * 1024 * 1024, $upload_err);
                if ($uploaded) $new_img = $uploaded; else $error = $upload_err ?: 'Invalid profile image. Allowed types: jpg, png, gif, webp. Max size: 10MB.';
            }
            if ($error === '') {
                $conn->begin_transaction();
                try {
                    // Handle verification question
                    $sec_question = trim($_POST['security_question'] ?? '');
                    $sec_answer_raw = trim($_POST['security_answer'] ?? '');

                    if ($sec_question !== '' && $sec_answer_raw !== '') {
                        $sec_answer_hash = password_hash(strtolower($sec_answer_raw), PASSWORD_DEFAULT);
                        $st = $conn->prepare("UPDATE users SET profile_image=?, security_question=?, security_answer_hash=? WHERE id=?");
                        $st->bind_param('sssi', $new_img, $sec_question, $sec_answer_hash, $target_uid);
                    } elseif ($sec_question !== '') {
                        $st = $conn->prepare("UPDATE users SET profile_image=?, security_question=? WHERE id=?");
                        $st->bind_param('ssi', $new_img, $sec_question, $target_uid);
                    } else {
                        $st = $conn->prepare("UPDATE users SET profile_image=? WHERE id=?");
                        $st->bind_param('si', $new_img, $target_uid);
                    }
                    $st->execute(); $st->close();

                    $st = $conn->prepare("UPDATE freelancers SET full_name=?,title=?,phone=?,bio=? WHERE id=?");
                    $st->bind_param('ssssi', $full_name, $title, $phone, $bio, $target_freelancer_id);
                    $st->execute(); $st->close();
                    $st = $conn->prepare("DELETE FROM freelancer_skills WHERE freelancer_id=?");
                    $st->bind_param('i', $target_freelancer_id); $st->execute(); $st->close();
                    if (!empty($selected_skills)) {
                        $ss = $conn->prepare('INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)');
                        foreach ($selected_skills as $sid) { $sid = (int) $sid; $ss->bind_param('ii', $target_freelancer_id, $sid); $ss->execute(); }
                        $ss->close();
                    }
                    $conn->commit();
                    if ($new_img !== $old_img && $old_img) delete_upload($old_img);
                    $_SESSION['profile_image'] = $new_img;
                    $success = 'Profile updated successfully.';
                    $fl_profile['full_name']=$full_name; $fl_profile['title']=$title; $fl_profile['phone']=$phone;
                    $fl_profile['bio']=$bio; $fl_profile['profile_image']=$new_img;
                    if ($sec_question !== '') $fl_profile['security_question'] = $sec_question;
                    $fl_profile_skills = array_map('intval', $selected_skills);
                } catch (Exception $e) { $conn->rollback(); $error = $e->getMessage(); }
            }
        }
    }
}

$profileImgUrl = profile_image_url($fl_profile['profile_image']);

// Fetch rating stats
$fl_avg_rating = 0;
$fl_total_reviews = 0;
$r = $conn->prepare("SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS total FROM reviews WHERE freelancer_id = ?");
$r->bind_param('i', $target_freelancer_id);
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
$r->bind_param('i', $target_freelancer_id);
$r->execute();
$rr = $r->get_result();
while ($row = $rr->fetch_assoc()) { $fl_reviews[] = $row; }
$r->close();

require __DIR__ . '/../includes/freelancer_layout.php';
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

            <?php if ($is_own_profile): ?>
                <?php if ($is_edit): ?><a href="javascript:history.back()" class="px-5 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Back</a><?php else: ?><a href="<?= e(base_url('freelancer/profile.php?edit=1')) ?>" class="btn-grad px-5 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Edit Profile</a><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($is_edit): ?>
    <form method="POST" class="space-y-6" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="glass rounded-2xl p-6 reveal">
            <h2 class="text-lg font-bold mb-5" style="color:var(--color-text-primary)">Basic Information</h2>
            <div class="grid md:grid-cols-2 gap-4">
                
                
                

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

        <div class="glass rounded-2xl p-6 reveal reveal-d3" style="border-top: 3px solid #6366f1;">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center" style="background:rgba(99,102,241,0.1)">
                    <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <div>
                    <h2 class="text-lg font-bold" style="color:var(--color-text-primary)">Verification Question</h2>
                    <p class="text-xs mt-0.5" style="color:var(--color-text-muted)">Used to verify your identity during password recovery.</p>
                </div>
            </div>
            <div class="p-4 rounded-xl mb-4 text-sm" style="background:rgba(99,102,241,0.06);border:1px solid rgba(99,102,241,0.15);color:var(--color-text-secondary)">
                💡 Create a question that only you can answer. Avoid information that can be easily guessed or found online.
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">Verification Question</label>
                    <input type="text" name="security_question" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"
                           placeholder="e.g. What was the name of my first school?"
                           value="<?= e($fl_profile['security_question'] ?? '') ?>">
                    <p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Write your own unique question.</p>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1.5" style="color:var(--color-text-secondary)">
                        Verification Answer
                        <?php if (!empty($fl_profile['security_question'])): ?>
                            <span class="ml-2 text-xs font-normal text-emerald-500">✔ Already set — leave blank to keep unchanged</span>
                        <?php endif; ?>
                    </label>
                    <input type="password" name="security_answer" autocomplete="new-password" class="w-full px-4 py-2.5 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary-500" style="background:var(--color-bg);border:1px solid var(--color-border);color:var(--color-text-primary)"
                           placeholder="<?= !empty($fl_profile['security_question']) ? 'Leave blank to keep current answer' : 'Your secret answer' ?>">
                    <p class="text-xs mt-1" style="color:var(--color-text-placeholder)">Answers are case-insensitive and stored securely.</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-3">
            <a href="<?= e(base_url('freelancer/profile.php')) ?>" class="px-5 py-2.5 text-sm font-semibold rounded-xl border" style="border-color:var(--color-border);color:var(--color-text-primary)">Cancel</a>
            <button type="submit" class="btn-grad px-6 py-2.5 text-sm font-semibold rounded-xl text-white shadow-lg shadow-primary-500/20">Save Changes</button>
        </div>
    </form>
<?php else: ?>
    <!-- Single Full-Card Design for View Mode -->
    <div class="glass rounded-2xl overflow-hidden shadow-sm reveal">
        
        <!-- About Section -->
        <div class="p-6 md:p-8 border-b" style="border-color:var(--color-border)">
            <h2 class="text-xl font-bold mb-4" style="color:var(--color-text-primary)">About Me</h2>
            <?php if ($fl_profile['bio']): ?>
                <p class="text-base leading-relaxed whitespace-pre-wrap" style="color:var(--color-text-secondary)"><?= e($fl_profile['bio']) ?></p>
            <?php else: ?>
                <p class="text-base italic" style="color:var(--color-text-placeholder)">No bio added yet.</p>
            <?php endif; ?>
        </div>

        <!-- Skills Section -->
        <?php if (!empty($fl_profile_skills)): ?>
        <div class="p-6 md:p-8 border-b" style="border-color:var(--color-border)">
            <h2 class="text-xl font-bold mb-4" style="color:var(--color-text-primary)">Skills</h2>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($fl_profile_skills as $sid): ?>
                    <span class="inline-flex px-3.5 py-1.5 text-sm font-medium rounded-xl" style="background:rgba(99,102,241,0.1);color:#4f46e5">
                        <?= e($fl_skill_names[$sid] ?? 'Unknown') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Details Grid -->
        <div class="p-6 md:p-8 border-b" style="border-color:var(--color-border)">
            <h2 class="text-xl font-bold mb-4" style="color:var(--color-text-primary)">Details</h2>
            <div class="grid sm:grid-cols-2 md:grid-cols-3 gap-6">
                <div>
                    <dt class="text-sm font-medium" style="color:var(--color-text-muted)">Full Name</dt>
                    <dd class="mt-1 text-base font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['full_name']) ?></dd>
                </div>
                <?php if ($fl_profile['title']): ?>
                <div>
                    <dt class="text-sm font-medium" style="color:var(--color-text-muted)">Title</dt>
                    <dd class="mt-1 text-base font-semibold text-primary-600"><?= e($fl_profile['title']) ?></dd>
                </div>
                <?php endif; ?>
                <div>
                    <dt class="text-sm font-medium" style="color:var(--color-text-muted)">Email</dt>
                    <dd class="mt-1 text-base font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['email']) ?></dd>
                </div>
                <?php if ($fl_profile['phone']): ?>
                <div>
                    <dt class="text-sm font-medium" style="color:var(--color-text-muted)">Phone</dt>
                    <dd class="mt-1 text-base font-semibold" style="color:var(--color-text-primary)"><?= e($fl_profile['phone']) ?></dd>
                </div>
                <?php endif; ?>

                
                
                <div>
                    <dt class="text-sm font-medium" style="color:var(--color-text-muted)">Joined</dt>
                    <dd class="mt-1 text-base font-semibold" style="color:var(--color-text-primary)"><?= date('F j, Y', strtotime($fl_profile['created_at'])) ?></dd>
                </div>
            </div>
        </div>

        <!-- Reviews Section -->
        <?php if ($fl_total_reviews > 0): ?>
        <div class="p-6 md:p-8">
            <div class="flex items-center justify-between mb-5">
                <h2 class="text-xl font-bold" style="color:var(--color-text-primary)">Reviews</h2>
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <span class="text-base font-semibold" style="color:var(--color-text-primary)"><?= $fl_avg_rating ?> / 5</span>
                    <span class="text-sm" style="color:var(--color-text-muted)">(<?= $fl_total_reviews ?>)</span>
                </div>
            </div>
            <div class="space-y-4">
                <?php foreach ($fl_reviews as $review): ?>
                    <div class="p-5 rounded-xl" style="background:var(--color-bg);border:1px solid var(--color-border)">
                        <div class="flex items-start gap-4">
                            <?php $rev_img = profile_image_url($review['reviewer_image']); ?>
                            <?php if ($rev_img): ?>
                                <img src="<?= e($rev_img) ?>" alt="" class="w-12 h-12 rounded-full object-cover flex-shrink-0">
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-full flex items-center justify-center text-indigo-600 font-bold text-base flex-shrink-0" style="background:rgba(99,102,241,0.1)">
                                    <?= strtoupper(mb_substr($review['company_name'] ?? 'C', 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <p class="text-base font-semibold" style="color:var(--color-text-primary)"><?= e($review['company_name'] ?? 'Company') ?></p>
                                        <div class="flex items-center gap-1 mt-1">
                                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                                <svg class="w-3.5 h-3.5 <?= $s <= $review['rating'] ? 'text-amber-400' : 'text-gray-300 dark:text-gray-600' ?>" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                    <span class="text-sm flex-shrink-0" style="color:var(--color-text-placeholder)"><?= e(date('M j, Y', strtotime($review['created_at']))) ?></span>
                                </div>
                                <?php if ($review['comment']): ?>
                                    <p class="text-base mt-3 leading-relaxed" style="color:var(--color-text-secondary)"><?= nl2br(e($review['comment'])) ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
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
