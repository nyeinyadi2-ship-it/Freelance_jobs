<?php
$user = current_user();
$role = $user['role'] ?? null;
?>
<nav class="bg-white shadow-sm border-b border-gray-200">
    <div class="container mx-auto px-4 max-w-6xl">
        <div class="flex items-center justify-between h-16">
            <a href="<?= e(base_url('index.php')) ?>" class="text-xl font-bold text-indigo-600">FreelanceHub</a>
            <div class="flex items-center gap-4">
                <?php if ($role === 'admin'): ?>
                    <a href="<?= e(base_url('admin/admin_dashboard.php')) ?>" class="text-gray-700 hover:text-indigo-600">Dashboard</a>
                    <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="text-gray-700 hover:text-indigo-600">Approve Jobs</a>
                <?php elseif ($role === 'company'): ?>
                    <a href="<?= e(base_url('company/dashboard.php')) ?>" class="text-gray-700 hover:text-indigo-600">Dashboard</a>
                    <a href="<?= e(base_url('company/post_job.php')) ?>" class="text-gray-700 hover:text-indigo-600">Post Job</a>
                    <a href="<?= e(base_url('company/manage_jobs.php')) ?>" class="text-gray-700 hover:text-indigo-600">My Jobs</a>
                <?php elseif ($role === 'freelancer'): ?>
                    <a href="<?= e(base_url('freelancer/dashboard.php')) ?>" class="text-gray-700 hover:text-indigo-600">Dashboard</a>
                    <a href="<?= e(base_url('freelancer/browse_jobs.php')) ?>" class="text-gray-700 hover:text-indigo-600">Browse Jobs</a>
                    <a href="<?= e(base_url('freelancer/my_tasks.php')) ?>" class="text-gray-700 hover:text-indigo-600">My Tasks</a>
                <?php else: ?>
                    <a href="<?= e(base_url('login.php')) ?>" class="text-gray-700 hover:text-indigo-600">Login</a>
                    <a href="<?= e(base_url('register.php')) ?>" class="bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700">Register</a>
                <?php endif; ?>

                <?php if ($role): ?>
                    <span class="text-sm text-gray-500"><?= e($user['username']) ?> (<?= e($role) ?>)</span>
                    <a href="<?= e(base_url('logout.php')) ?>" class="text-red-600 hover:text-red-700">Logout</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
