/**
 * Secrets list AJAX functionality for live toggle, reveal, rotate, and delete actions.
 *
 * Uses TYPO3 v14 native modules and patterns.
 */
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';

class SecretsList {
    constructor() {
        this.revealedSecrets = new Map();
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

        // Reveal modal triggers
        document.querySelectorAll('[data-vault-reveal]').forEach(button => {
            button.addEventListener('click', this.handleReveal.bind(this));
        });

        // Rotate modal triggers
        document.querySelectorAll('[data-vault-rotate]').forEach(button => {
            button.addEventListener('click', this.handleRotate.bind(this));
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
            'Are you sure you want to delete the secret "' + this.escapeHtml(identifier) + '"? This action cannot be undone.',
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

    /**
     * Handle reveal button click - show modal with secret value.
     */
    async handleReveal(event) {
        const button = event.currentTarget;
        const identifier = button.dataset.vaultReveal;

        if (!identifier) {
            Notification.error('Error', 'No identifier found', 5);
            return;
        }

        // Check cache first
        if (this.revealedSecrets.has(identifier)) {
            this.showRevealModal(identifier, this.revealedSecrets.get(identifier));
            return;
        }

        // Show loading modal
        const loadingModal = Modal.advanced({
            title: 'Loading Secret',
            content: '<div class="text-center p-4"><span class="spinner-border" role="status"></span><p class="mt-2">Fetching secret...</p></div>',
            severity: Severity.info,
            size: Modal.sizes.small,
            buttons: []
        });

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['vault_reveal'], {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ identifier }),
            });

            const data = await response.json();
            loadingModal.hideModal();

            if (data.success && data.secret !== undefined) {
                this.revealedSecrets.set(identifier, data.secret);
                this.showRevealModal(identifier, data.secret);
            } else {
                Notification.error('Error', data.error || 'Failed to reveal secret', 5);
            }
        } catch (error) {
            loadingModal.hideModal();
            Notification.error('Error', error.message || 'Failed to reveal secret', 5);
        }
    }

    /**
     * Show the reveal modal with secret value.
     */
    showRevealModal(identifier, secret) {
        const content = document.createElement('div');
        content.innerHTML = `
            <div class="form-group mb-3">
                <label class="form-label fw-bold">Secret Value</label>
                <div class="input-group">
                    <input type="password" class="form-control font-monospace" id="reveal-modal-secret" value="${this.escapeHtml(secret)}" readonly />
                    <button type="button" class="btn btn-default" id="reveal-modal-toggle" title="Toggle visibility">
                        <span class="icon icon-size-small icon-state-default icon-actions-eye"></span>
                    </button>
                    <button type="button" class="btn btn-default" id="reveal-modal-copy" title="Copy to clipboard">
                        <span class="icon icon-size-small icon-state-default icon-actions-clipboard"></span>
                    </button>
                </div>
            </div>
            <p class="text-muted small mb-0">Secret value for: <code>${this.escapeHtml(identifier)}</code></p>
        `;

        const modal = Modal.advanced({
            title: 'Secret Value',
            content: content,
            severity: Severity.info,
            size: Modal.sizes.default,
            buttons: [
                {
                    text: 'Close',
                    active: true,
                    btnClass: 'btn-default',
                    trigger: () => modal.hideModal()
                }
            ]
        });

        // Add event listeners after modal is shown
        setTimeout(() => {
            const toggleBtn = document.getElementById('reveal-modal-toggle');
            const copyBtn = document.getElementById('reveal-modal-copy');
            const input = document.getElementById('reveal-modal-secret');

            if (toggleBtn && input) {
                toggleBtn.addEventListener('click', () => {
                    if (input.type === 'password') {
                        input.type = 'text';
                    } else {
                        input.type = 'password';
                    }
                });
            }

            if (copyBtn && input) {
                copyBtn.addEventListener('click', async () => {
                    try {
                        await navigator.clipboard.writeText(secret);
                        Notification.success('Copied', 'Secret copied to clipboard', 2);
                    } catch (e) {
                        Notification.error('Error', 'Failed to copy to clipboard', 5);
                    }
                });
            }
        }, 100);
    }

    /**
     * Handle rotate button click - show modal with input for new secret.
     */
    handleRotate(event) {
        const button = event.currentTarget;
        const identifier = button.dataset.vaultRotate;

        if (!identifier) {
            Notification.error('Error', 'No identifier found', 5);
            return;
        }

        const content = document.createElement('div');
        content.innerHTML = `
            <div class="form-group mb-3">
                <label class="form-label fw-bold" for="rotate-modal-secret">New Secret Value</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="rotate-modal-secret" placeholder="Enter new secret value" autocomplete="new-password" />
                    <button type="button" class="btn btn-default" id="rotate-modal-toggle" title="Toggle visibility">
                        <span class="icon icon-size-small icon-state-default icon-actions-eye"></span>
                    </button>
                </div>
                <div class="form-text">Enter the new secret value. This will replace the existing secret.</div>
            </div>
            <p class="text-warning small mb-0">
                <strong>Warning:</strong> Rotating a secret is irreversible. The previous value cannot be recovered.
            </p>
        `;

        const modal = Modal.advanced({
            title: 'Rotate Secret: ' + identifier,
            content: content,
            severity: Severity.warning,
            size: Modal.sizes.default,
            buttons: [
                {
                    text: 'Cancel',
                    active: true,
                    btnClass: 'btn-default',
                    trigger: () => modal.hideModal()
                },
                {
                    text: 'Rotate Secret',
                    btnClass: 'btn-warning',
                    trigger: () => this.performRotate(modal, identifier)
                }
            ]
        });

        // Add toggle visibility event listener
        setTimeout(() => {
            const toggleBtn = document.getElementById('rotate-modal-toggle');
            const input = document.getElementById('rotate-modal-secret');

            if (toggleBtn && input) {
                toggleBtn.addEventListener('click', () => {
                    if (input.type === 'password') {
                        input.type = 'text';
                    } else {
                        input.type = 'password';
                    }
                });

                // Focus the input
                input.focus();
            }
        }, 100);
    }

    /**
     * Perform the actual rotation via AJAX.
     */
    async performRotate(modal, identifier) {
        const input = document.getElementById('rotate-modal-secret');
        const newSecret = input?.value || '';

        if (!newSecret) {
            Notification.error('Error', 'Please enter a new secret value', 5);
            return;
        }

        try {
            const response = await fetch(TYPO3.settings.ajaxUrls['vault_rotate'], {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ identifier, secret: newSecret }),
            });

            const data = await response.json();

            if (data.success) {
                modal.hideModal();
                // Clear cached secret since it's been rotated
                this.revealedSecrets.delete(identifier);
                Notification.success('Success', data.message || 'Secret rotated successfully', 3);
            } else {
                Notification.error('Error', data.error || 'Failed to rotate secret', 5);
            }
        } catch (error) {
            Notification.error('Error', error.message || 'Failed to rotate secret', 5);
        }
    }

    /**
     * Escape HTML to prevent XSS.
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new SecretsList());
} else {
    new SecretsList();
}

export default SecretsList;
