/**
 * WesTec Eswatini Business Management System (WesTec BMS)
 * Client-Side Application JavaScript
 */

document.addEventListener('DOMContentLoaded', function () {
    // 1. Password Confirmation Match Checker
    const passwordInput = document.getElementById('password');
    const confirmPasswordInput = document.getElementById('confirm_password');
    const passwordMatchFeedback = document.getElementById('password_match_feedback');

    if (passwordInput && confirmPasswordInput && passwordMatchFeedback) {
        function checkPasswordMatch() {
            if (!confirmPasswordInput.value) {
                passwordMatchFeedback.textContent = '';
                confirmPasswordInput.classList.remove('is-valid', 'is-invalid');
                return;
            }

            if (passwordInput.value === confirmPasswordInput.value) {
                passwordMatchFeedback.textContent = 'Passwords match.';
                passwordMatchFeedback.className = 'form-text text-success fw-bold';
                confirmPasswordInput.classList.remove('is-invalid');
                confirmPasswordInput.classList.add('is-valid');
            } else {
                passwordMatchFeedback.textContent = 'Passwords do not match.';
                passwordMatchFeedback.className = 'form-text text-danger fw-bold';
                confirmPasswordInput.classList.remove('is-valid');
                confirmPasswordInput.classList.add('is-invalid');
            }
        }

        passwordInput.addEventListener('input', checkPasswordMatch);
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }

    // 2. Generic Client-Side Table Filter/Search
    const tableSearchInputs = document.querySelectorAll('[data-table-filter]');
    tableSearchInputs.forEach(input => {
        const targetTableId = input.getAttribute('data-table-filter');
        const targetTable = document.getElementById(targetTableId);

        if (targetTable) {
            input.addEventListener('keyup', function () {
                const query = input.value.toLowerCase().trim();
                const rows = targetTable.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const rowText = row.textContent.toLowerCase();
                    if (rowText.includes(query)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        }
    });

    // 3. Confirm Delete Safety Prompt
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function (e) {
            const message = btn.getAttribute('data-confirm-delete') || 'Are you sure you want to permanently delete this record?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // 4. Auto-dismiss flash alerts after 8 seconds
    const autoAlerts = document.querySelectorAll('.alert-dismissible');
    autoAlerts.forEach(alert => {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) {
                bsAlert.close();
            }
        }, 8000);
    });

    // 5. Three-Line (Hamburger) Menu Controller (Opens at top left corner)
    const menuToggleBtn = document.getElementById('menuToggleBtn');
    const menuCloseBtn = document.getElementById('menuCloseBtn');
    const appSidebar = document.getElementById('appSidebar');
    const sidebarBackdrop = document.getElementById('sidebarBackdrop');

    function openMenu() {
        if (appSidebar) {
            appSidebar.classList.add('show');
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.classList.add('show');
        }
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        if (appSidebar) {
            appSidebar.classList.remove('show');
        }
        if (sidebarBackdrop) {
            sidebarBackdrop.classList.remove('show');
        }
        document.body.style.overflow = '';
    }

    if (menuToggleBtn) {
        menuToggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (appSidebar && appSidebar.classList.contains('show')) {
                closeMenu();
            } else {
                openMenu();
            }
        });
    }

    if (menuCloseBtn) {
        menuCloseBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            closeMenu();
        });
    }

    if (sidebarBackdrop) {
        sidebarBackdrop.addEventListener('click', function () {
            closeMenu();
        });
    }

    // Close menu when pressing Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && appSidebar && appSidebar.classList.contains('show')) {
            closeMenu();
        }
    });

    // 6. Lucide Icons Auto-Converter and Initializer
    function renderLucideIcons() {
        const biLucideMap = {
            'bi-shield-check': 'shield-check',
            'bi-shield-lock-fill': 'shield',
            'bi-shield-lock': 'shield',
            'bi-shield-x': 'shield-x',
            'bi-speedometer2': 'layout-dashboard',
            'bi-people-fill': 'users',
            'bi-people': 'users',
            'bi-person-lines-fill': 'users',
            'bi-person-circle': 'circle-user',
            'bi-person-plus-fill': 'user-plus',
            'bi-person-plus': 'user-plus',
            'bi-person-check-fill': 'user-check',
            'bi-person-check': 'user-check',
            'bi-person-badge-fill': 'badge-check',
            'bi-person-vcard-fill': 'contact',
            'bi-person-workspace': 'laptop',
            'bi-person-x': 'user-x',
            'bi-person': 'user',
            'bi-building': 'building-2',
            'bi-building-add': 'building-2',
            'bi-ticket-detailed-fill': 'ticket',
            'bi-box-seam-fill': 'package',
            'bi-box-seam': 'package',
            'bi-boxes': 'boxes',
            'bi-diagram-3-fill': 'workflow',
            'bi-diagram-3': 'workflow',
            'bi-graph-up-arrow': 'trending-up',
            'bi-journal-text': 'book-open',
            'bi-key': 'key',
            'bi-key-fill': 'key',
            'bi-box-arrow-right': 'log-out',
            'bi-box-arrow-in-right': 'log-in',
            'bi-exclamation-triangle-fill': 'alert-triangle',
            'bi-exclamation-triangle': 'alert-triangle',
            'bi-exclamation-circle': 'alert-circle',
            'bi-info-circle': 'info',
            'bi-pencil-square': 'file-edit',
            'bi-pencil-fill': 'pencil',
            'bi-pencil': 'pencil',
            'bi-trash3-fill': 'trash-2',
            'bi-trash': 'trash-2',
            'bi-eye': 'eye',
            'bi-check-circle-fill': 'check-circle-2',
            'bi-check2-circle': 'check-circle-2',
            'bi-check-all': 'check-check',
            'bi-check-lg': 'check',
            'bi-x-circle': 'x-circle',
            'bi-slash-circle': 'ban',
            'bi-lock-fill': 'lock',
            'bi-lock': 'lock',
            'bi-unlock': 'unlock',
            'bi-send-fill': 'send',
            'bi-arrow-left': 'arrow-left',
            'bi-arrow-repeat': 'repeat',
            'bi-arrow-counterclockwise': 'rotate-ccw',
            'bi-arrow-up-right': 'arrow-up-right',
            'bi-arrow-down-left': 'arrow-down-left',
            'bi-arrow-left-right': 'arrow-left-right',
            'bi-clock-history': 'history',
            'bi-database-check': 'database',
            'bi-kanban': 'kanban',
            'bi-list-check': 'list-checks',
            'bi-list-task': 'list-todo',
            'bi-list-ul': 'list',
            'bi-plus-circle': 'plus-circle',
            'bi-save': 'save',
            'bi-floppy': 'save',
            'bi-search': 'search',
            'bi-filter': 'filter',
            'bi-funnel': 'filter',
            'bi-flag-fill': 'flag',
            'bi-flag': 'flag',
            'bi-lightning-charge-fill': 'zap',
            'bi-file-earmark-arrow-down-fill': 'file-down',
            'bi-folder-plus': 'folder-plus',
            'bi-envelope': 'mail',
            'bi-telephone': 'phone',
            'bi-briefcase': 'briefcase',
            'bi-cash-stack': 'banknote',
            'bi-archive': 'archive'
        };

        // Convert any fallback bootstrap icon classes to data-lucide attributes
        document.querySelectorAll('i[class*="bi-"]').forEach(el => {
            const classes = Array.from(el.classList);
            for (const cls of classes) {
                if (biLucideMap[cls]) {
                    el.setAttribute('data-lucide', biLucideMap[cls]);
                    el.classList.remove(cls, 'bi');
                    break;
                }
            }
        });

        if (window.lucide) {
            lucide.createIcons();
        }
    }

    renderLucideIcons();
    window.renderLucideIcons = renderLucideIcons;
});

