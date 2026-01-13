/**
 * Main Application JavaScript
 * Handles dropdowns, modals, and interactive elements
 */

document.addEventListener('DOMContentLoaded', function() {

    // ============================================
    // Company Switcher Dropdown
    // ============================================
    const companySwitcherBtn = document.getElementById('companySwitcherBtn');
    const companySwitcherDropdown = document.getElementById('companySwitcherDropdown');

    if (companySwitcherBtn && companySwitcherDropdown) {
        companySwitcherBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            companySwitcherDropdown.classList.toggle('show');
            // Close user menu if open
            if (userMenuDropdown) {
                userMenuDropdown.classList.remove('show');
            }
        });
    }

    // ============================================
    // User Menu Dropdown
    // ============================================
    const userMenuBtn = document.getElementById('userMenuBtn');
    const userMenuDropdown = document.getElementById('userMenuDropdown');

    if (userMenuBtn && userMenuDropdown) {
        userMenuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            userMenuDropdown.classList.toggle('show');
            // Close company switcher if open
            if (companySwitcherDropdown) {
                companySwitcherDropdown.classList.remove('show');
            }
        });
    }

    // ============================================
    // Close Dropdowns on Outside Click
    // ============================================
    document.addEventListener('click', function() {
        if (companySwitcherDropdown) {
            companySwitcherDropdown.classList.remove('show');
        }
        if (userMenuDropdown) {
            userMenuDropdown.classList.remove('show');
        }
    });

    // ============================================
    // Auto-hide Flash Messages
    // ============================================
    const flashMessages = document.querySelectorAll('.alert');
    flashMessages.forEach(function(alert) {
        // Auto-hide after 5 seconds
        setTimeout(function() {
            alert.style.transition = 'opacity 0.3s ease-in-out';
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.remove();
            }, 300);
        }, 5000);
    });

    // ============================================
    // Form Validation Helpers
    // ============================================
    const forms = document.querySelectorAll('form[data-validate]');
    forms.forEach(function(form) {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('is-invalid');
                } else {
                    field.classList.remove('is-invalid');
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Vyplňte prosím všechna povinná pole.');
            }
        });
    });

    // ============================================
    // Confirm Delete Actions
    // ============================================
    const deleteButtons = document.querySelectorAll('[data-confirm-delete]');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(e) {
            const message = this.dataset.confirmDelete || 'Opravdu chcete smazat tuto položku?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

});
