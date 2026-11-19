        </main>
        <footer class="app-footer d-flex justify-content-between align-items-center text-muted mt-auto">
            <span>&copy; <?= date('Y') ?> <strong class="text-dark"><?= APP_NAME ?></strong></span>
            <span>v<?= APP_VERSION ?></span>
        </footer>
    </div>
</div>

<!-- ================= GLOBAL COMMAND PALETTE (CTRL + K) ================= -->
<div class="modal fade command-palette-modal" id="globalCommandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 650px;">
        <div class="modal-content shadow-lg border-0">
            <div class="command-search-header">
                <span class="text-primary fs-5 flex-shrink-0"><?= svg_icon('search', '', 20) ?></span>
                <input type="text" class="command-search-input" id="commandModalInput" placeholder="પાર્ટી, ખરીદી નં, જાંગડ કે કાપન સર્ચ કરો... (Esc = બંધ)" autocomplete="off">
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="command-results-list" id="commandModalResults">
                <!-- Dynamically populated via AJAX -->
            </div>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 bg-light border-top text-muted small" style="font-size: 0.74rem;">
                <div><span class="fw-bold font-mono">↑↓</span> નેવિગેટ • <span class="fw-bold font-mono">Enter</span> સિલેક્ટ • <span class="fw-bold font-mono">Esc</span> બંધ</div>
                <div><strong class="text-dark">Kasyap Diamond ERP</strong> • Enterprise Search</div>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>/assets/vendor/chart.umd.js"></script>
<script src="<?= BASE_URL ?>/assets/vendor/tom-select/tom-select.complete.min.js?v=<?= filemtime(__DIR__ . '/../assets/vendor/tom-select/tom-select.complete.min.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/js/diamond.js?v=<?= filemtime(__DIR__ . '/../assets/css/diamond.css') ?>"></script>

<script>
    // ----------------------------------------------------
    // Enterprise Table Density Management
    // ----------------------------------------------------
    window.toggleTableDensity = function() {
        const isCompact = document.body.classList.toggle('table-compact');
        localStorage.setItem('erp_table_density', isCompact ? 'compact' : 'comfortable');
        const btn = document.getElementById('densityToggleBtn');
        if (btn) {
            btn.classList.toggle('text-primary', isCompact);
        }
    };

    if (localStorage.getItem('erp_table_density') === 'compact') {
        document.body.classList.add('table-compact');
        document.getElementById('densityToggleBtn')?.classList.add('text-primary');
    }

    // ----------------------------------------------------
    // Global Command Palette / Spotlight Search (Ctrl + K)
    // ----------------------------------------------------
    let commandModalInstance = null;
    let searchDebounceTimer = null;

    window.openGlobalCommandPalette = function() {
        const modalEl = document.getElementById('globalCommandModal');
        if (!modalEl) return;
        if (!commandModalInstance) {
            commandModalInstance = new bootstrap.Modal(modalEl);
        }
        commandModalInstance.show();
        setTimeout(() => {
            const inp = document.getElementById('commandModalInput');
            if (inp) {
                inp.value = '';
                inp.focus();
                loadCommandResults('');
            }
        }, 150);
    };

    function loadCommandResults(query) {
        const container = document.getElementById('commandModalResults');
        if (!container) return;
        container.innerHTML = '<div class="text-center py-4 text-muted small"><span class="spinner-border spinner-border-sm me-2"></span>શોધી રહ્યું છે...</div>';

        fetch(window.BASE_URL + '/ajax/global_search.php?q=' + encodeURIComponent(query))
            .then(res => res.json())
            .then(data => {
                if (!data.success || !data.categories || data.categories.length === 0) {
                    container.innerHTML = '<div class="text-center py-4 text-muted small">કોઈ પરિણામ મળ્યા નથી. કૃપા કરીને અન્ય કીવર્ડથી પ્રયાસ કરો.</div>';
                    return;
                }

                let html = '';
                data.categories.forEach(cat => {
                    html += `<div class="command-category-title">${escapeHtml(cat.name)}</div>`;
                    cat.items.forEach(item => {
                        html += `
                        <a href="${item.url}" class="command-item">
                            <div class="d-flex align-items-center gap-2.5 overflow-hidden">
                                <span class="badge bg-${item.badge_color || 'primary'}-subtle text-${item.badge_color || 'primary'} border border-${item.badge_color || 'primary'}-subtle font-mono command-badge flex-shrink-0">${escapeHtml(item.badge || 'Link')}</span>
                                <div class="overflow-hidden">
                                    <div class="fw-bold text-dark text-truncate command-title fs-7">${escapeHtml(item.title)}</div>
                                    <div class="text-muted small text-truncate" style="font-size: 0.75rem;">${escapeHtml(item.subtitle || '')}</div>
                                </div>
                            </div>
                            <span class="text-muted small ms-2">➔</span>
                        </a>`;
                    });
                });
                container.innerHTML = html;
            })
            .catch(() => {
                container.innerHTML = '<div class="text-center py-4 text-danger small">સર્ચ સર્વર સાથે સંપર્ક થઈ શક્યો નથી.</div>';
            });
    }

    document.getElementById('commandModalInput')?.addEventListener('input', function() {
        clearTimeout(searchDebounceTimer);
        const q = this.value.trim();
        searchDebounceTimer = setTimeout(() => {
            loadCommandResults(q);
        }, 180);
    });

    // ----------------------------------------------------
    // Keyboard Shortcuts & Enter-To-Next-Field Navigation
    // ----------------------------------------------------
    window.addEventListener('keydown', function(e) {
        // Ctrl+K or Cmd+K opens Command Palette
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            openGlobalCommandPalette();
            return;
        }

        // Enter key to Next Field inside data entry forms (ignoring textarea, buttons, search)
        if (e.key === 'Enter' && e.target.matches('input:not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]), select')) {
            if (e.target.closest('#globalCommandModal') || e.target.id === 'globalSearchInput') return;
            const form = e.target.form;
            if (!form) return;

            const focusables = Array.from(form.querySelectorAll('input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([disabled])'));
            const idx = focusables.indexOf(e.target);
            if (idx > -1 && idx < focusables.length - 1) {
                e.preventDefault();
                focusables[idx + 1].focus();
            }
        }
    });

    // Auto-focus on first visible input inside shown modals
    document.addEventListener('shown.bs.modal', function(e) {
        if (e.target.id === 'globalCommandModal') return;
        const firstInput = e.target.querySelector('input:not([type=hidden]):not([readonly]):not([disabled]), select:not([disabled])');
        if (firstInput) firstInput.focus();
    });

    // Auto dismiss Bootstrap floating toasts after 4 seconds
    document.querySelectorAll('.app-floating-toast').forEach(tEl => {
        const toast = new bootstrap.Toast(tEl, { delay: 4000 });
        toast.show();
    });

    // Responsive Sidebar Toggle
    (function() {
        const sidebar = document.getElementById('sidebar-wrapper');
        const toggleBtn = document.getElementById('sidebarToggle');
        if (!sidebar) return;

        let tooltip = document.getElementById('sidebar-flyout-tooltip');
        if (!tooltip) {
            tooltip = document.createElement('div');
            tooltip.id = 'sidebar-flyout-tooltip';
            tooltip.className = 'sidebar-flyout-tooltip';
            document.body.appendChild(tooltip);
        }

        if (toggleBtn) {
            toggleBtn.addEventListener('click', function(e) {
                e.preventDefault();
                if (window.innerWidth < 992) {
                    sidebar.classList.toggle('show-mobile');
                    let backdrop = document.querySelector('.sidebar-backdrop');
                    if (sidebar.classList.contains('show-mobile')) {
                        if (!backdrop) {
                            backdrop = document.createElement('div');
                            backdrop.className = 'sidebar-backdrop';
                            document.body.appendChild(backdrop);
                            backdrop.addEventListener('click', function() {
                                sidebar.classList.remove('show-mobile');
                                backdrop.remove();
                            });
                        }
                    } else if (backdrop) {
                        backdrop.remove();
                    }
                } else {
                    sidebar.classList.toggle('collapsed');
                    localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
                }
                tooltip.classList.remove('show');
            });
        }

        const closeBtn = document.getElementById('sidebarCloseBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function(e) {
                e.preventDefault();
                sidebar.classList.remove('show-mobile');
                const backdrop = document.querySelector('.sidebar-backdrop');
                if (backdrop) backdrop.remove();
                tooltip.classList.remove('show');
            });
        }

        function showFlyout(targetEl) {
            if (!sidebar.classList.contains('collapsed') || window.innerWidth < 992) {
                tooltip.classList.remove('show');
                return;
            }
            const title = targetEl.getAttribute('data-title') || targetEl.querySelector('span')?.textContent?.trim();
            if (!title) return;
            const rect = targetEl.getBoundingClientRect();
            tooltip.textContent = title;
            tooltip.style.top = Math.round(rect.top + (rect.height / 2)) + 'px';
            tooltip.style.left = Math.round(rect.right + 10) + 'px';
            tooltip.classList.add('show');
        }

        function hideFlyout() {
            tooltip.classList.remove('show');
        }

        const navItems = sidebar.querySelectorAll('.nav-link, .brand-icon-box');
        navItems.forEach(item => {
            item.addEventListener('mouseenter', function() { showFlyout(this); });
            item.addEventListener('mouseleave', hideFlyout);
            item.addEventListener('click', hideFlyout);
        });

        const scrollArea = sidebar.querySelector('.sidebar-scroll');
        if (scrollArea) {
            scrollArea.addEventListener('scroll', hideFlyout, { passive: true });
        }
        window.addEventListener('scroll', hideFlyout, { passive: true });
    })();
</script>

</body>
</html>
