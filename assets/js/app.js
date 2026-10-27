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
});

