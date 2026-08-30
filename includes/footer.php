<?php if ($role === 'admin'): ?>
    </div>
</main>
<?php else: ?>
</main>
<?php endif; ?>
<?php if (empty($hide_navbar)): ?>
<footer style="background:var(--color-footer);border-color:var(--color-border)" class="border-t py-6 mt-auto">
    <div class="container mx-auto px-4 max-w-6xl text-center" style="color:var(--color-text-muted)">
        <p class="text-sm">&copy; <?= date('Y') ?> <?= e('FreelanceHub') ?>. <?= e('All rights reserved.') ?></p>
    </div>
</footer>
<?php endif; ?>
<script>
(function(){
    // Admin sidebar toggle
    var sidebarToggle = document.getElementById('admin-sidebar-toggle');
    var sidebar = document.getElementById('admin-sidebar');
    var sidebarOverlay = document.getElementById('admin-sidebar-overlay');
    function openSidebar() {
        if (sidebar) { sidebar.classList.remove('-translate-x-full'); }
        if (sidebarOverlay) { sidebarOverlay.classList.remove('hidden'); }
    }
    function closeSidebar() {
        if (sidebar) { sidebar.classList.add('-translate-x-full'); }
        if (sidebarOverlay) { sidebarOverlay.classList.add('hidden'); }
    }
    if (sidebarToggle) { sidebarToggle.addEventListener('click', openSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', closeSidebar); }
})();
</script>
</body>
</html>
