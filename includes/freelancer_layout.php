<?php
/**
 * Shared layout for all freelancer pages.
 * Set $page_title before including. Content goes between this file and freelancer_footer.php.
 */
require_once __DIR__ . '/freelancer_init.php';
?>
<!DOCTYPE html>
<html lang="<?= e('en') ?>" data-theme>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> - FreelanceHub</title>
    <script>(function(){var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme:dark)').matches))document.documentElement.classList.add('dark');})();</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{poppins:['Poppins','sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/custom.css')) ?>">
    <style>
        *,*::before,*::after{font-family:'Poppins',system-ui,sans-serif;}
        ::selection{background:rgba(99,102,241,0.2);}
        :root{--gp:linear-gradient(135deg,#4f46e5,#7c3aed);--gs:linear-gradient(135deg,#059669,#10b981);--gw:linear-gradient(135deg,#d97706,#f59e0b);--gi:linear-gradient(135deg,#0284c7,#0ea5e9);--gr:linear-gradient(135deg,#e11d48,#f43f5e);}
        .glass{background:rgba(255,255,255,0.72);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid rgba(255,255,255,0.5);box-shadow:0 8px 32px rgba(99,102,241,0.06);}
        html.dark .glass{background:rgba(30,41,59,0.72);border-color:rgba(255,255,255,0.08);box-shadow:0 8px 32px rgba(0,0,0,0.3);}
        .stat-card{position:relative;overflow:hidden;border-radius:20px;transition:transform .35s cubic-bezier(.4,0,.2,1),box-shadow .35s ease;}
        .stat-card:hover{transform:translateY(-6px);box-shadow:0 20px 50px rgba(0,0,0,0.15);}
        .hover-lift{transition:transform .3s cubic-bezier(.4,0,.2,1),box-shadow .3s ease;}
        .hover-lift:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(79,70,229,0.12);}
        .badge-skill{transition:all .2s ease;}
        .badge-skill:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(79,70,229,0.25);}
        .dash-tab{cursor:pointer;transition:all .25s ease;position:relative;white-space:nowrap;font-weight:500;}
        .dash-tab::after{content:'';position:absolute;bottom:0;left:50%;transform:translateX(-50%);width:0;height:3px;background:var(--gp);transition:width .3s cubic-bezier(.16,1,.3,1);border-radius:3px 3px 0 0;}
        .dash-tab:hover::after,.dash-tab.active::after{width:80%;}
        .dash-tab.active{color:#4f46e5!important;}
        .dash-section{display:none;animation:fadeSlideIn .45s cubic-bezier(.16,1,.3,1);}
        .dash-section.active{display:block;}
        @keyframes fadeSlideIn{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .profile-banner{position:relative;overflow:hidden;background:linear-gradient(135deg,#312e81 0%,#4f46e5 35%,#7c3aed 65%,#a855f7 100%);}
        .profile-banner::before{content:'';position:absolute;inset:0;background:radial-gradient(ellipse at 20% 50%,rgba(255,255,255,0.12) 0%,transparent 60%);pointer-events:none;}
        .profile-banner::after{content:'';position:absolute;top:-80px;right:-40px;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,rgba(255,255,255,0.08) 0%,transparent 70%);pointer-events:none;}
        .tab-scroll{-webkit-mask-image:linear-gradient(to right,transparent 0%,black 12px,black calc(100% - 12px),transparent 100%);mask-image:linear-gradient(to right,transparent 0%,black 12px,black calc(100% - 12px),transparent 100%);}
        .scrollbar-thin::-webkit-scrollbar{height:4px;width:4px;}
        .scrollbar-thin::-webkit-scrollbar-track{background:transparent;}
        .scrollbar-thin::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:4px;}
        .dark .scrollbar-thin::-webkit-scrollbar-thumb{background:#475569;}
        .progress-bar{background:var(--gp);border-radius:99px;transition:width 1s cubic-bezier(.4,0,.2,1);}
        .reveal{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease;}
        .reveal.visible{opacity:1;transform:translateY(0);}
        .reveal-d1{transition-delay:.08s;}.reveal-d2{transition-delay:.16s;}.reveal-d3{transition-delay:.24s;}.reveal-d4{transition-delay:.32s;}
        .btn-grad{background:linear-gradient(135deg,#6366f1,#8b5cf6);transition:all .3s ease;position:relative;overflow:hidden;}
        .btn-grad::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent);transition:left .5s ease;}
        .btn-grad:hover::before{left:100%;}
        .btn-grad:hover{transform:translateY(-2px);box-shadow:0 12px 35px rgba(99,102,241,0.35);}
    </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-white to-indigo-50/30 dark:from-slate-950 dark:via-slate-900 dark:to-indigo-950/30 min-h-screen" style="color:var(--color-text-primary)">

<?php require __DIR__ . '/navbar.php'; ?>

<!-- Main Content -->
<main class="pt-20 pb-8 min-h-screen">
<div id="fl-page-content">
