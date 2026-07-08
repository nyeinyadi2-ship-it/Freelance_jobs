<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('admin');

$stats = [
    'pending_jobs' => 0,
    'total_companies' => 0,
    'total_freelancers' => 0,
    'total_jobs' => 0,
];

$result = $conn->query("SELECT COUNT(*) AS cnt FROM jobs WHERE status = 'pending'");
$stats['pending_jobs'] = (int) $result->fetch_assoc()['cnt'];

$result = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'company'");
$stats['total_companies'] = (int) $result->fetch_assoc()['cnt'];

$result = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'freelancer'");
$stats['total_freelancers'] = (int) $result->fetch_assoc()['cnt'];

$result = $conn->query('SELECT COUNT(*) AS cnt FROM jobs');
$stats['total_jobs'] = (int) $result->fetch_assoc()['cnt'];

$page_title = 'Admin Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="text-2xl font-bold text-gray-900 mb-6">Admin Dashboard</h1>

<div class="grid md:grid-cols-4 gap-4 mb-8">
    <div class="card">
        <p class="text-sm text-gray-500">Pending Jobs</p>
        <p class="text-3xl font-bold text-yellow-600"><?= $stats['pending_jobs'] ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Total Jobs</p>
        <p class="text-3xl font-bold text-indigo-600"><?= $stats['total_jobs'] ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Companies</p>
        <p class="text-3xl font-bold text-green-600"><?= $stats['total_companies'] ?></p>
    </div>
    <div class="card">
        <p class="text-sm text-gray-500">Freelancers</p>
        <p class="text-3xl font-bold text-purple-600"><?= $stats['total_freelancers'] ?></p>
    </div>
</div>

<div class="card">
    <h2 class="text-lg font-semibold mb-4">Quick Actions</h2>
    <a href="<?= e(base_url('admin/approve_jobs.php')) ?>" class="btn-primary inline-block">Review Pending Jobs</a>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
