<?php
$page_title = $page_title ?? 'FreelanceHub';
require_once __DIR__ . '/job_helpers.php';
if (isset($conn)) {
    check_and_update_expired_jobs($conn);
}
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <script>
    (function(){
        var t = localStorage.getItem('theme');
        if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        }
    })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
        darkMode: 'class',
    };
    </script>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/navbar.php'; ?>
<?php if ($role === 'admin'): ?>
<!-- Mobile sidebar overlay -->
<div id="admin-sidebar-overlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:!hidden"></div>
<!-- Sidebar -->
<aside id="admin-sidebar" class="fixed top-16 left-0 z-40 w-64 h-[calc(100vh-4rem)] transform -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-in-out overflow-y-auto flex-shrink-0 border-r" style="background:var(--color-card);border-color:var(--color-border)">
    <div class="flex flex-col h-full">
        <?php require __DIR__ . '/../admin/includes/admin_sidebar.php'; ?>
    </div>
</aside>
<main class="flex-1 lg:ml-64 min-h-[calc(100vh-4rem)]">
    <div class="container mx-auto px-4 py-8 max-w-6xl">
<?php else: ?>
<main class="flex-1 container mx-auto px-4 py-8 max-w-6xl">
<?php endif; ?>
<?php
$flash = get_flash();
if ($flash):
    $isError = $flash['type'] === 'error';
?>
    <div class="toast mb-6 p-4 rounded-lg border"
         style="background:<?= $isError ? 'var(--color-flash-error-bg)' : 'var(--color-flash-success-bg)' ?>;color:<?= $isError ? 'var(--color-flash-error-text)' : 'var(--color-flash-success-text)' ?>;border-color:<?= $isError ? 'var(--color-flash-error-border)' : 'var(--color-flash-success-border)' ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>
