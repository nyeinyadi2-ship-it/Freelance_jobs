<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$page_title = 'FreelanceHub - Find Work or Hire Talent';
require __DIR__ . '/includes/header.php';
?>

<div class="text-center py-16">
    <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">Connect Companies with Freelancers</h1>
    <p class="text-lg text-gray-600 mb-8 max-w-2xl mx-auto">
        Post jobs, find talented freelancers, and manage projects from start to finish — all in one platform.
    </p>
    <div class="flex flex-wrap justify-center gap-4">
        <a href="<?= e(base_url('register.php')) ?>" class="btn-primary text-lg px-8 py-3">Get Started</a>
        <a href="<?= e(base_url('login.php')) ?>" class="btn-secondary text-lg px-8 py-3">Login</a>
    </div>
</div>

<div class="grid md:grid-cols-3 gap-6 mt-12">
    <div class="card text-center">
        <h2 class="text-xl font-semibold text-indigo-600 mb-2">For Companies</h2>
        <p class="text-gray-600">Post jobs, review applications, hire freelancers, and process payments when work is done.</p>
    </div>
    <div class="card text-center">
        <h2 class="text-xl font-semibold text-indigo-600 mb-2">For Freelancers</h2>
        <p class="text-gray-600">Browse approved jobs, apply to projects, complete tasks, and get paid.</p>
    </div>
    <div class="card text-center">
        <h2 class="text-xl font-semibold text-indigo-600 mb-2">For Admins</h2>
        <p class="text-gray-600">Moderate job postings and keep the platform safe and high-quality.</p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
