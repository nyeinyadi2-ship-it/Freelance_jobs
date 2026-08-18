<?php $flash = get_flash(); if ($flash): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-2">
    <?php $isError = $flash['type'] === 'error'; ?>
    <div class="flex items-center gap-3 p-4 rounded-xl mb-4 transition-all" id="fl-flash-msg"
         style="background:<?= $isError ? 'rgba(239,68,68,0.08)' : 'rgba(16,185,129,0.08)' ?>;border:1px solid <?= $isError ? 'rgba(239,68,68,0.2)' : 'rgba(16,185,129,0.2)' ?>;color:<?= $isError ? '#dc2626' : '#059669' ?>">
        <?php if ($isError): ?>
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php else: ?>
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?php endif; ?>
        <p class="text-sm font-medium flex-1"><?= e($flash['message']) ?></p>
        <button onclick="this.parentElement.remove()" class="flex-shrink-0 p-1 rounded-lg hover:bg-black/5 transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
</div>
<script>setTimeout(function(){var el=document.getElementById('fl-flash-msg');if(el){el.style.transition='opacity .4s,transform .4s';el.style.opacity='0';el.style.transform='translateY(-8px)';setTimeout(function(){el.remove()},400);}},5000);</script>
<?php endif; ?>

</div><!-- end fl-page-content -->
</main>

<!-- Footer -->
<footer class="py-8" style="background:var(--color-card);border-top:1px solid var(--color-border)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 bg-gradient-to-br from-primary-500 to-accent-500 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <span class="text-sm font-semibold" style="color:var(--color-text-primary)">FreelanceHub</span>
            </div>
            <div class="flex items-center gap-6">
                <?php $admin_chat_id = get_admin_user_id($conn); ?>
                <?php if ($admin_chat_id): ?>
                <a href="<?= e(base_url('chat/index.php?user_id=' . $admin_chat_id)) ?>" class="text-xs font-medium hover:text-primary-500 transition-colors" style="color:var(--color-text-placeholder)">Contact Us</a>
                <?php endif; ?>
                <p class="text-xs" style="color:var(--color-text-placeholder)">&copy; <?= date('Y') ?> FreelanceHub. All rights reserved.</p>
            </div>
        </div>
    </div>
</footer>

<!-- Back to top -->
<button id="fl-btt" class="fixed bottom-6 right-6 w-11 h-11 bg-gradient-to-br from-primary-500 to-accent-500 text-white rounded-2xl shadow-lg shadow-primary-500/30 flex items-center justify-center hover:shadow-xl hover:-translate-y-1 transition-all z-50 opacity-0 invisible" aria-label="Back to top">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
</button>

<script src="<?= e(base_url('assets/js/notification-sse.js')) ?>" defer></script>
<script>
if (typeof NotificationSSE !== 'undefined') {
    NotificationSSE.init({ user_id: <?= (int) ($fl_uid ?? 0) ?> });
}

(function(){
    // Theme
    var tt=document.getElementById('fl-theme-toggle');
    if(tt)tt.addEventListener('click',function(){var d=document.documentElement.classList.toggle('dark');localStorage.setItem('theme',d?'dark':'light');});

    // Sticky nav shadow
    var nav=document.getElementById('fl-nav');
    if(nav)window.addEventListener('scroll',function(){nav.style.boxShadow=window.scrollY>20?'0 4px 30px rgba(0,0,0,0.08)':'none';});

    // Scroll reveal
    var obs=new IntersectionObserver(function(e){e.forEach(function(el){if(el.isIntersecting)el.target.classList.add('visible');});},{threshold:.1,rootMargin:'0px 0px -40px 0px'});
    document.querySelectorAll('.reveal').forEach(function(el){obs.observe(el);});

    // Back to top
    var btt=document.getElementById('fl-btt');
    if(btt){
        window.addEventListener('scroll',function(){btt.style.opacity=window.scrollY>400?'1':'0';btt.style.visibility=window.scrollY>400?'visible':'hidden';});
        btt.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});
    }

    // Close dropdowns on outside click
    document.addEventListener('click',function(e){
        ['fl-notif-dd','fl-user-dd'].forEach(function(id){
            var dd=document.getElementById(id);
            var wrap=document.getElementById(id.replace('-dd','-wrap'));
            if(dd&&wrap&&!wrap.contains(e.target))dd.classList.add('hidden');
        });
    });
})();
</script>
</body>
</html>
