</main>
<footer style="background:var(--color-footer);border-color:var(--color-border)" class="border-t py-6 mt-auto">
    <div class="container mx-auto px-4 max-w-6xl text-center" style="color:var(--color-text-muted)">
        <p class="text-sm">&copy; <?= date('Y') ?> <?= e(__('app.name')) ?>. <?= e(__('footer.rights')) ?></p>
    </div>
</footer>
<script>
(function(){
    var themeToggle = document.getElementById('theme-toggle');
    var html = document.documentElement;
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            var isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }
})();
</script>
</body>
</html>
