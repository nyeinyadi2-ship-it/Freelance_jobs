<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $role = $_POST['role'] ?? '';

        if (!in_array($role, ['company', 'freelancer'], true)) {
            $error = 'Please select a valid role.';
        } elseif ($username === '' || $email === '' || $password === '') {
            $error = 'All required fields must be filled.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            $stmt = $conn->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'Email is already registered.';
            }
            $stmt->close();

            if ($error === '') {
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $conn->begin_transaction();

                try {
                    $stmt = $conn->prepare('INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)');
                    $stmt->bind_param('ssss', $username, $email, $hashed, $role);
                    $stmt->execute();
                    $user_id = $stmt->insert_id;
                    $stmt->close();

                    if ($role === 'company') {
                        $company_name = trim($_POST['company_name'] ?? '');
                        $website = trim($_POST['website'] ?? '');
                        $description = trim($_POST['description'] ?? '');

                        if ($company_name === '') {
                            throw new Exception('Company name is required.');
                        }

                        $stmt = $conn->prepare('INSERT INTO companies (user_id, company_name, website, description) VALUES (?, ?, ?, ?)');
                        $stmt->bind_param('isss', $user_id, $company_name, $website, $description);
                        $stmt->execute();
                        $stmt->close();
                    } else {
                        $full_name = trim($_POST['full_name'] ?? '');
                        $portfolio_url = trim($_POST['portfolio_url'] ?? '');
                        $selected_skills = $_POST['skills'] ?? [];

                        if ($full_name === '') {
                            throw new Exception('Full name is required.');
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
                    set_flash('success', 'Registration successful. Please log in.');
                    redirect('login.php');
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = $e->getMessage() ?: 'Registration failed. Please try again.';
                }
            }
        }
    }
}

$page_title = 'Register';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-lg mx-auto card">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Create Account</h1>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4" id="registerForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">I am a</label>
            <select name="role" id="roleSelect" class="form-input" onchange="toggleRoleFields()">
                <option value="company" <?= $role === 'company' ? 'selected' : '' ?>>Company</option>
                <option value="freelancer" <?= $role === 'freelancer' ? 'selected' : '' ?>>Freelancer</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Username</label>
            <input type="text" name="username" required class="form-input" value="<?= e($_POST['username'] ?? '') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" required class="form-input" value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" required class="form-input" minlength="6">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
            <input type="password" name="confirm_password" required class="form-input" minlength="6">
        </div>

        <div id="companyFields" class="space-y-4 <?= $role !== 'company' ? 'hidden' : '' ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                <input type="text" name="company_name" class="form-input" value="<?= e($_POST['company_name'] ?? '') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Website</label>
                <input type="url" name="website" class="form-input" placeholder="https://example.com" value="<?= e($_POST['website'] ?? '') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" rows="3" class="form-input"><?= e($_POST['description'] ?? '') ?></textarea>
            </div>
        </div>

        <div id="freelancerFields" class="space-y-4 <?= $role !== 'freelancer' ? 'hidden' : '' ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Full Name</label>
                <input type="text" name="full_name" class="form-input" value="<?= e($_POST['full_name'] ?? '') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Portfolio URL</label>
                <input type="url" name="portfolio_url" class="form-input" placeholder="https://portfolio.com" value="<?= e($_POST['portfolio_url'] ?? '') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Skills</label>
                <div class="grid grid-cols-2 gap-2">
                    <?php foreach ($skills as $skill): ?>
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="skills[]" value="<?= (int) $skill['id'] ?>"
                                <?= in_array((string) $skill['id'], $_POST['skills'] ?? [], true) ? 'checked' : '' ?>>
                            <?= e($skill['skill_name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn-primary w-full">Register</button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-600">
        Already have an account? <a href="<?= e(base_url('login.php')) ?>" class="text-indigo-600 hover:underline">Login</a>
    </p>
</div>

<script>
function toggleRoleFields() {
    const role = document.getElementById('roleSelect').value;
    document.getElementById('companyFields').classList.toggle('hidden', role !== 'company');
    document.getElementById('freelancerFields').classList.toggle('hidden', role !== 'freelancer');
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
