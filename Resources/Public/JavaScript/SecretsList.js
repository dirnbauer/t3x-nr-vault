/**
 * Secrets list AJAX functionality for live toggle and other actions.
 *
 * Uses TYPO3 v14 native modules and patterns.
 */
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';

class SecretsList {
    constructor() {
        this.init();
    }

    init() {
        document.querySelectorAll('[data-vault-toggle]').forEach(button => {
            button.addEventListener('click', this.handleToggle.bind(this));
        });

        // Delete confirmation with TYPO3 Modal
        document.querySelectorAll('.btn-danger[type="submit"]').forEach(button => {
            const form = button.closest('form');
            if (form && form.action.includes('delete')) {
                button.addEventListener('click', this.handleDelete.bind(this));
            }
        });
    }

    handleDelete(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const form = button.closest('form');
        const row = button.closest('tr');
        const identifier = row?.dataset.identifier || form.querySelector('input[name="identifier"]')?.value || 'secret';

        Modal.confirm(
            'Delete Secret',
            'Are you sure you want to delete the secret "' + identifier + '"? This action cannot be undone.',
            Severity.warning,
            [
                {
                    text: 'Cancel',
                    active: true,
                    btnClass: 'btn-default',
                    trigger: () => Modal.dismiss()
                },
                {
                    text: 'Delete',
                    btnClass: 'btn-danger',
                    trigger: () => {
                        Modal.dismiss();
                        form.submit();
                    }
                }
            ]
        );
    }

    async handleToggle(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const form = button.closest('form');
        const identifier = form.querySelector('input[name="identifier"]').value;
        const row = button.closest('tr');
        const url = form.action;

        // Disable button and show loading state
        button.disabled = true;
        const originalHTML = button.innerHTML;
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

        try {
            const formData = new FormData(form);
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Update UI
                this.updateRowState(row, result.hidden);
                this.updateButtonState(button, result.hidden);
                Notification.success('Success', result.message, 3);
            } else {
                Notification.error('Error', result.error || 'Unknown error', 5);
            }
        } catch (error) {
            Notification.error('Error', error.message || 'An error occurred', 5);
        } finally {
            button.disabled = false;
            // Restore button with new state if needed
            if (!button.innerHTML.includes('icon')) {
                button.innerHTML = originalHTML;
            }
        }
    }

    updateRowState(row, hidden) {
        if (hidden) {
            row.classList.add('table-secondary');
        } else {
            row.classList.remove('table-secondary');
        }

        // Update status badge
        const statusCell = row.querySelector('td:nth-child(3)');
        if (statusCell) {
            const badge = statusCell.querySelector('.badge');
            if (badge) {
                if (hidden) {
                    badge.className = 'badge text-bg-secondary';
                    badge.textContent = 'Disabled';
                } else {
                    badge.className = 'badge text-bg-success';
                    badge.textContent = 'Active';
                }
            }

            // Update aria-label
            statusCell.setAttribute('aria-label', 'Status: ' + (hidden ? 'Disabled' : 'Active'));
        }
    }

    updateButtonState(button, hidden) {
        // Update button title and icon
        const iconContainer = button.querySelector('.icon');
        if (iconContainer) {
            // TYPO3 icons are SVG use elements
            const useElement = iconContainer.querySelector('use');
            if (useElement) {
                const currentHref = useElement.getAttribute('href') || useElement.getAttribute('xlink:href');
                if (currentHref) {
                    const newIcon = hidden ? 'actions-toggle-off' : 'actions-toggle-on';
                    const newHref = currentHref.replace(/actions-toggle-(on|off)/, newIcon);
                    useElement.setAttribute('href', newHref);
                    if (useElement.hasAttribute('xlink:href')) {
                        useElement.setAttribute('xlink:href', newHref);
                    }
                }
            }
        }

        // Update title and aria-label
        const identifier = button.closest('tr')?.dataset.identifier || 'secret';
        const newTitle = hidden ? 'Enable secret' : 'Disable secret';
        const newAriaLabel = (hidden ? 'Enable' : 'Disable') + ' secret ' + identifier;
        button.setAttribute('title', newTitle);
        button.setAttribute('aria-label', newAriaLabel);
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new SecretsList());
} else {
    new SecretsList();
}

export default SecretsList;
