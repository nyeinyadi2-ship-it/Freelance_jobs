<?php
$page_title = $page_title ?? 'Freelance Platform';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
<?php require __DIR__ . '/navbar.php'; ?>
<main class="flex-1 container mx-auto px-4 py-8 max-w-6xl">
<?php
$flash = get_flash();
if ($flash):
    $flashClass = $flash['type'] === 'error' ? 'bg-red-100 text-red-800 border-red-200' : 'bg-green-100 text-green-800 border-green-200';
?>
    <div class="mb-6 p-4 rounded-lg border <?= $flashClass ?>">
        <?= e($flash['message']) ?>
    </div>
<?php endif; ?>
