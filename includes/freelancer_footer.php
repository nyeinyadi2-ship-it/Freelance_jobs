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
                <span class="text-sm font-semibold" style="color:var(--color-text-primary)">HireWork</span>
            </div>
            <p class="text-xs" style="color:var(--color-text-placeholder)">&copy; <?= date('Y') ?> HireWork. All rights reserved.</p>
        </div>
    </div>
</footer>

<!-- Back to top -->
<button id="fl-btt" class="fixed bottom-6 right-6 w-11 h-11 bg-gradient-to-br from-primary-500 to-accent-500 text-white rounded-2xl shadow-lg shadow-primary-500/30 flex items-center justify-center hover:shadow-xl hover:-translate-y-1 transition-all z-50 opacity-0 invisible" aria-label="Back to top">
    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
</button>

<script>
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

    // Mobile menu
    var mt=document.getElementById('fl-mobile-toggle');
    var mm=document.getElementById('fl-mobile-menu');
    if(mt&&mm)mt.addEventListener('click',function(){mm.classList.toggle('hidden');});

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
