<?php
$page_title = $page_title ?? __('app.name');
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" data-theme>
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
<main class="flex-1 container mx-auto px-4 py-8 max-w-6xl">
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
