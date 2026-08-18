<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/notifications.php';

require_role('company');

$user = current_user();
$company_id = get_company_id($conn, (int) $user['user_id']);

if (!$company_id) {
    set_flash('error', 'Company profile not found.');
    redirect('auth/login.php');
}

$page_title = 'About Us';
require __DIR__ . '/../includes/header.php';
?>

<style>
.hero-about {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 50%, #3b82f6 100%);
    position: relative;
    overflow: hidden;
}
.hero-about::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -30%;
    width: 600px;
    height: 600px;
    background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
    border-radius: 50%;
}
.feature-card { transition: all 0.3s ease; }
.feature-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(37, 99, 235, 0.12); }
</style>

<!-- Hero Section -->
<section class="hero-about text-white py-16 md:py-20 mb-10 rounded-2xl mx-4 md:mx-0">
    <div class="relative z-10 max-w-3xl mx-auto text-center px-4">
        <h1 class="text-3xl md:text-4xl lg:text-5xl font-bold mb-4 leading-tight">
            The Platform Where <span class="text-blue-200">Great Work</span> Happens
        </h1>
        <p class="text-blue-100 text-lg md:text-xl max-w-2xl mx-auto">
            Connecting businesses with world-class freelancers to deliver exceptional results on time and on budget.
        </p>
    </div>
</section>

<!-- What We Offer -->
<section class="mb-12">
    <div class="text-center mb-8">
        <h2 class="text-2xl font-bold" style="color:var(--color-text-primary)">What We Offer</h2>
        <p class="text-sm mt-2 max-w-xl mx-auto" style="color:var(--color-text-muted)">Everything you need to find, hire, and manage top freelancers for your projects.</p>
    </div>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(37,99,235,0.1);">
                <svg class="w-6 h-6" style="color:#2563eb" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Smart Talent Search</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Find freelancers by skill, experience, rate, and location. Our filters help you discover the perfect match for your project needs.</p>
        </div>
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(5,150,105,0.1);">
                <svg class="w-6 h-6" style="color:#059669" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Easy Job Posting</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Post jobs in minutes with detailed descriptions, budgets, and requirements. Reach thousands of qualified freelancers instantly.</p>
        </div>
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(124,58,237,0.1);">
                <svg class="w-6 h-6" style="color:#7c3aed" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Verified Freelancers</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Browse profiles with ratings, reviews, and skill verification. Make informed hiring decisions with confidence.</p>
        </div>
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(217,119,6,0.1);">
                <svg class="w-6 h-6" style="color:#d97706" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Secure Payments</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Escrow-based payment system protects both parties. Release funds only when you're satisfied with the delivered work.</p>
        </div>
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(239,68,68,0.1);">
                <svg class="w-6 h-6" style="color:#dc2626" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Real-Time Messaging</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Communicate directly with freelancers through our built-in chat system. Share files, discuss requirements, and stay aligned.</p>
        </div>
        <div class="feature-card card">
            <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4" style="background:rgba(16,185,129,0.1);">
                <svg class="w-6 h-6" style="color:#10b981" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="font-semibold mb-2" style="color:var(--color-text-primary)">Milestone Tracking</h3>
            <p class="text-sm" style="color:var(--color-text-muted)">Break projects into milestones with clear deliverables and deadlines. Track progress and ensure quality at every stage.</p>
        </div>
    </div>
</section>


<!-- Why Choose Us -->
<section class="mb-12">
    <div class="card p-8 md:p-10" style="background:linear-gradient(135deg,#eff6ff,#f5f3ff);border:1px solid rgba(37,99,235,0.1);">
        <div class="text-center mb-8">
            <h2 class="text-2xl font-bold" style="color:var(--color-text-primary)">Why Companies Choose Us</h2>
        </div>
        <div class="grid md:grid-cols-2 gap-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(37,99,235,0.1)">
                    <svg class="w-4 h-4" style="color:#2563eb" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-sm mb-1" style="color:var(--color-text-primary)">Quality Talent Pool</h4>
                    <p class="text-sm" style="color:var(--color-text-muted)">Access thousands of vetted freelancers across diverse skill sets and industries.</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(5,150,105,0.1)">
                    <svg class="w-4 h-4" style="color:#059669" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-sm mb-1" style="color:var(--color-text-primary)">Cost-Effective Solutions</h4>
                    <p class="text-sm" style="color:var(--color-text-muted)">Competitive rates and flexible hiring models to fit any budget.</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(124,58,237,0.1)">
                    <svg class="w-4 h-4" style="color:#7c3aed" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-sm mb-1" style="color:var(--color-text-primary)">Fast Turnaround</h4>
                    <p class="text-sm" style="color:var(--color-text-muted)">Get proposals within hours and hire the right talent in days, not weeks.</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5" style="background:rgba(217,119,6,0.1)">
                    <svg class="w-4 h-4" style="color:#d97706" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div>
                    <h4 class="font-semibold text-sm mb-1" style="color:var(--color-text-primary)">Risk-Free Hiring</h4>
                    <p class="text-sm" style="color:var(--color-text-muted)">Escrow protection and milestone-based payments ensure you only pay for approved work.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="text-center py-10 rounded-2xl mb-8" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
    <h2 class="text-2xl md:text-3xl font-bold text-white mb-3">Ready to Get Started?</h2>
    <p class="text-blue-100 mb-6 max-w-lg mx-auto">Post your first job today and find the perfect freelancer for your project.</p>
    <a href="<?= e(base_url('company/post_job.php')) ?>" class="inline-flex items-center gap-2 bg-white text-blue-700 font-semibold px-6 py-3 rounded-xl hover:bg-blue-50 transition-all shadow-lg">
        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
        </svg>
        Post a Job Now
    </a>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
