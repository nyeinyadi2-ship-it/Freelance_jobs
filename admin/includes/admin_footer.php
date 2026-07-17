    </div>
</main>

</div><!-- /admin-layout -->

<script src="<?= e(base_url('assets/js/notification-sse.js')) ?>"></script>
<script>
(function() {
    // Theme toggle
    var themeToggle = document.getElementById('theme-toggle');
    var html = document.documentElement;
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            var isDark = html.classList.toggle('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
        });
    }

    // Sidebar toggle (mobile)
    var sidebarToggle = document.getElementById('admin-sidebar-toggle');
    var sidebar = document.getElementById('admin-sidebar');
    var sidebarOverlay = document.getElementById('admin-sidebar-overlay');

    function openMobileSidebar() {
        if (sidebar) { sidebar.classList.add('mobile-open'); }
        if (sidebarOverlay) { sidebarOverlay.classList.add('active'); }
        document.body.style.overflow = 'hidden';
    }
    function closeMobileSidebar() {
        if (sidebar) { sidebar.classList.remove('mobile-open'); }
        if (sidebarOverlay) { sidebarOverlay.classList.remove('active'); }
        document.body.style.overflow = '';
    }
    if (sidebarToggle) { sidebarToggle.addEventListener('click', openMobileSidebar); }
    if (sidebarOverlay) { sidebarOverlay.addEventListener('click', closeMobileSidebar); }

    // Sidebar collapse (desktop)
    var collapseBtn = document.getElementById('sidebar-collapse-btn');
    var layout = document.querySelector('.admin-layout');
    if (collapseBtn && layout) {
        var collapsed = localStorage.getItem('admin-sidebar-collapsed') === 'true';
        if (collapsed) { layout.classList.add('sidebar-collapsed'); }
        collapseBtn.addEventListener('click', function() {
            layout.classList.toggle('sidebar-collapsed');
            localStorage.setItem('admin-sidebar-collapsed', layout.classList.contains('sidebar-collapsed'));
        });
    }

    // Language dropdown
    document.addEventListener('click', function(e) {
        var langSwitcher = document.getElementById('lang-switcher');
        var langDropdown = document.getElementById('lang-dropdown');
        if (langSwitcher && langDropdown && !langSwitcher.contains(e.target)) {
            langDropdown.classList.add('hidden');
        }
    });

    // Notification dropdowns
    var containers = document.querySelectorAll('.notification-container');
    containers.forEach(function(container) {
        var toggle = container.querySelector('.notification-toggle');
        var dropdown = container.querySelector('.notification-dropdown');
        if (toggle && dropdown) {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                var isOpen = dropdown.style.display === 'block';
                document.querySelectorAll('.notification-dropdown').forEach(function(d) { d.style.display = 'none'; });
                if (!isOpen) dropdown.style.display = 'block';
            });
        }
    });
    document.addEventListener('click', function() {
        document.querySelectorAll('.notification-dropdown').forEach(function(d) { d.style.display = 'none'; });
    });

    // Mark all as read
    document.querySelectorAll('.notification-mark-all').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            fetch('<?= e(base_url("api/notifications.php")) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'mark_all_read', csrf_token: this.getAttribute('data-csrf') })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    document.querySelectorAll('.notification-item').forEach(function(item) {
                        item.classList.remove('bg-indigo-50/50', 'dark:bg-indigo-900/20');
                        var msg = item.querySelector('p');
                        if (msg) { msg.classList.remove('font-medium'); msg.style.color = ''; }
                    });
                    var badge = document.querySelector('.notification-badge');
                    if (badge) { badge.style.display = 'none'; }
                    var markAllBtn = document.querySelector('.notification-mark-all');
                    if (markAllBtn) { markAllBtn.style.display = 'none'; }
                }
            })
            .catch(function() {});
        });
    });

    // Delete notification
    document.querySelectorAll('.notification-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var id = this.getAttribute('data-id');
            var csrf = this.getAttribute('data-csrf');
            var item = this.closest('.notification-item');
            fetch('<?= e(base_url("api/notifications.php")) ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ action: 'delete', notification_id: parseInt(id), csrf_token: csrf })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && item) {
                    item.style.transition = 'opacity 0.2s, max-height 0.2s';
                    item.style.opacity = '0';
                    item.style.maxHeight = '0';
                    item.style.overflow = 'hidden';
                    setTimeout(function() { item.remove(); }, 200);
                    var badge = document.querySelector('.notification-badge');
                    if (badge) {
                        badge.textContent = data.count > 99 ? '99+' : data.count;
                        badge.style.display = data.count > 0 ? 'flex' : 'none';
                    }
                }
            })
            .catch(function() {});
        });
    });

    // SSE real-time notification badge updates
    if (typeof NotificationSSE !== 'undefined') {
        NotificationSSE.init({ user_id: <?= (int) ($admin_user['user_id'] ?? 0) ?> });
    }

    // Fallback polling every 15s
    setInterval(function() {
        fetch('<?= e(base_url("api/notifications.php")) ?>?action=count', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var badge = document.querySelector('.notification-badge');
            if (badge) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.style.display = data.count > 0 ? 'flex' : 'none';
            }
        })
        .catch(function() {});
    }, 15000);

    // ===== ADMIN SEARCH =====
    var searchInput = document.getElementById('admin-search-input');
    var searchResults = document.getElementById('admin-search-results');
    var searchKbd = document.getElementById('admin-search-kbd');
    var searchTimer = null;
    var activeIndex = -1;
    var currentResults = [];
    var baseUrl = <?= json_encode(base_url('')) ?>;

    var badgeColors = {
        green:  'background:#dcfce7;color:#166534;',
        red:    'background:#fef2f2;color:#991b1b;',
        amber:  'background:#fef9c3;color:#a16207;',
        blue:   'background:#dbeafe;color:#1e40af;',
        purple: 'background:#f3e8ff;color:#7e22ce;',
        emerald:'background:#d1fae5;color:#065f46;',
        indigo: 'background:#e0e7ff;color:#3730a3;',
        slate:  'background:#f1f5f9;color:#475569;',
    };

    var iconBgColors = {
        green:  'rgba(34,197,94,0.12)',
        red:    'rgba(239,68,68,0.12)',
        amber:  'rgba(245,158,11,0.12)',
        blue:   'rgba(59,130,246,0.12)',
        purple: 'rgba(168,85,247,0.12)',
        emerald:'rgba(16,185,129,0.12)',
        indigo: 'rgba(99,102,241,0.12)',
        slate:  'rgba(100,116,139,0.12)',
    };

    var iconTextColors = {
        green:'#16a34a', red:'#dc2626', amber:'#d97706', blue:'#2563eb',
        purple:'#9333ea', emerald:'#059669', indigo:'#4f46e5', slate:'#475569',
    };

    function renderSearchResults(data) {
        if (!data.results || data.results.length === 0) {
            searchResults.innerHTML = '<div class="admin-search-empty">No results found</div>';
            searchResults.classList.add('show');
            return;
        }

        // Group by type
        var groups = {};
        var typeLabels = {
            job: 'Jobs', company: 'Clients', freelancer: 'Freelancers', category: 'Skills',
            payment: 'Payments', message: 'Messages', application: 'Applications', assignment: 'Assignments', user: 'Users'
        };
        var typeOrder = ['job', 'company', 'freelancer', 'category', 'payment', 'message', 'application', 'assignment', 'user'];

        data.results.forEach(function(r) {
            if (!groups[r.type]) groups[r.type] = [];
            groups[r.type].push(r);
        });

        var html = '';
        activeIndex = -1;
        currentResults = [];

        typeOrder.forEach(function(type) {
            if (!groups[type]) return;
            html += '<div class="admin-search-group">';
            html += '<div class="admin-search-group-label">' + (typeLabels[type] || type) + '</div>';
            groups[type].forEach(function(item) {
                var idx = currentResults.length;
                currentResults.push(item);
                var bc = badgeColors[item.badge_color] || badgeColors.slate;
                var ibg = iconBgColors[item.badge_color] || iconBgColors.slate;
                var itc = iconTextColors[item.badge_color] || iconTextColors.slate;
                html += '<a href="' + baseUrl + item.url + '" class="admin-search-item" data-index="' + idx + '">';
                html += '<div class="admin-search-item-icon" style="background:' + ibg + '">';
                html += '<svg style="color:' + itc + '" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="' + item.icon + '"/></svg>';
                html += '</div>';
                html += '<div class="admin-search-item-text">';
                html += '<div class="admin-search-item-title">' + escapeHtml(item.title) + '</div>';
                html += '<div class="admin-search-item-subtitle">' + escapeHtml(item.subtitle) + '</div>';
                html += '</div>';
                html += '<span class="admin-search-item-badge" style="' + bc + '">' + escapeHtml(item.badge) + '</span>';
                html += '</a>';
            });
            html += '</div>';
        });

        searchResults.innerHTML = html;
        searchResults.classList.add('show');

        // Bind click handlers
        searchResults.querySelectorAll('.admin-search-item').forEach(function(el) {
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                var idx = parseInt(this.getAttribute('data-index'));
                if (currentResults[idx]) {
                    window.location.href = baseUrl + currentResults[idx].url;
                }
            });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function doSearch(query) {
        if (query.length < 2) {
            searchResults.classList.remove('show');
            searchResults.innerHTML = '';
            currentResults = [];
            return;
        }

        searchResults.innerHTML = '<div class="admin-search-loading"></div>';
        searchResults.classList.add('show');

        fetch(baseUrl + 'api/admin_search.php?q=' + encodeURIComponent(query), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) { renderSearchResults(data); })
        .catch(function() {
            searchResults.innerHTML = '<div class="admin-search-empty">Search failed</div>';
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value.trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { doSearch(val); }, 250);
        });

        searchInput.addEventListener('focus', function() {
            if (this.value.trim().length >= 2) {
                doSearch(this.value.trim());
            }
        });

        // Keyboard navigation
        searchInput.addEventListener('keydown', function(e) {
            var items = searchResults.querySelectorAll('.admin-search-item');
            if (!items.length) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                items.forEach(function(el, i) { el.classList.toggle('active', i === activeIndex); });
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                items.forEach(function(el, i) { el.classList.toggle('active', i === activeIndex); });
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (activeIndex >= 0 && currentResults[activeIndex]) {
                    window.location.href = baseUrl + currentResults[activeIndex].url;
                }
            } else if (e.key === 'Escape') {
                searchResults.classList.remove('show');
                searchInput.blur();
            }
        });
    }

    // Global keyboard shortcut: "/" to focus search
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey) {
            var tag = (e.target.tagName || '').toLowerCase();
            if (tag !== 'input' && tag !== 'textarea' && tag !== 'select' && !e.target.isContentEditable) {
                e.preventDefault();
                if (searchInput) searchInput.focus();
            }
        }
    });

    // Close search on outside click
    document.addEventListener('click', function(e) {
        var searchEl = document.querySelector('.admin-search');
        if (searchEl && !searchEl.contains(e.target)) {
            searchResults.classList.remove('show');
        }
    });

    // Hide "/" kbd when typing
    if (searchInput && searchKbd) {
        searchInput.addEventListener('input', function() {
            searchKbd.style.display = this.value ? 'none' : '';
        });
        searchInput.addEventListener('focus', function() {
            searchKbd.style.display = this.value ? 'none' : 'none';
        });
        searchInput.addEventListener('blur', function() {
            if (!this.value) searchKbd.style.display = '';
        });
    }
})();
</script>
</body>
</html>
