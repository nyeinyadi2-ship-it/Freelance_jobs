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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $error = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $stmt = $conn->prepare('SELECT id, username, email, password, role FROM users WHERE email = ?');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];

                if ($user['role'] === 'company') {
                    $_SESSION['profile_id'] = get_company_id($conn, (int) $user['id']);
                    redirect('company/dashboard.php');
                } elseif ($user['role'] === 'freelancer') {
                    $_SESSION['profile_id'] = get_freelancer_id($conn, (int) $user['id']);
                    redirect('freelancer/dashboard.php');
                } else {
                    $_SESSION['profile_id'] = null;
                    redirect('admin/admin_dashboard.php');
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
}

$page_title = 'Login';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-md mx-auto card">
    <h1 class="text-2xl font-bold text-gray-900 mb-6">Login</h1>

    <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-100 text-red-800 rounded-lg"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
            <input type="email" name="email" required class="form-input" value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
            <input type="password" name="password" required class="form-input">
        </div>

        <button type="submit" class="btn-primary w-full">Login</button>
    </form>

    <p class="mt-4 text-center text-sm text-gray-600">
        Don't have an account? <a href="<?= e(base_url('register.php')) ?>" class="text-indigo-600 hover:underline">Register</a>
    </p>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
