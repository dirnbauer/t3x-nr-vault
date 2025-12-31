/**
 * Secret reveal and delete functionality for vault view page.
 *
 * Uses TYPO3 v14 native modules: Modal and Notification APIs.
 */
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';

class SecretView {
    constructor() {
        this.revealBtn = document.getElementById('reveal-secret-btn');
        this.copyBtn = document.getElementById('copy-secret-btn');
        this.secretInput = document.getElementById('secret-value-display');
        this.btnText = document.getElementById('reveal-btn-text');
        this.btnIcon = document.getElementById('reveal-btn-icon');
        this.btnSpinner = document.getElementById('reveal-btn-spinner');
        this.isRevealed = false;
        this.secretValue = null;

        this.init();
    }

    init() {
        if (this.revealBtn && this.secretInput) {
            this.revealBtn.addEventListener('click', () => this.handleReveal());
        }

        if (this.copyBtn) {
            this.copyBtn.addEventListener('click', () => this.handleCopy());
        }

        // Delete confirmation with TYPO3 Modal
        document.querySelectorAll('[data-vault-delete]').forEach(button => {
            button.addEventListener('click', (event) => this.handleDelete(event));
        });
    }

    handleReveal() {
        if (this.isRevealed) {
            this.hideSecret();
        } else if (this.secretValue !== null) {
            this.showSecret();
        } else {
            this.fetchAndReveal();
        }
    }

    hideSecret() {
        this.secretInput.type = 'password';
        this.secretInput.value = '••••••••••••••••';
        this.btnText.textContent = 'Reveal';
        if (this.copyBtn) {
            this.copyBtn.style.display = 'none';
        }
        this.isRevealed = false;
    }

    showSecret() {
        this.secretInput.type = 'text';
        this.secretInput.value = this.secretValue;
        this.btnText.textContent = 'Hide';
        if (this.copyBtn) {
            this.copyBtn.style.display = 'inline-block';
        }
        this.isRevealed = true;
    }

    async fetchAndReveal() {
        const url = this.revealBtn.dataset.revealUrl + '&identifier=' + encodeURIComponent(this.revealBtn.dataset.identifier);
        this.revealBtn.disabled = true;
        this.showLoading(true);

        try {
            const response = await fetch(url, {
                method: 'GET',
                headers: { 'Accept': 'application/json' }
            });
            const data = await response.json();

            if (data.success) {
                this.secretValue = data.secret;
                this.showSecret();
            } else {
                Notification.error('Error', data.error || 'Unknown error', 5);
                this.btnText.textContent = 'Reveal';
            }
        } catch (error) {
            Notification.error('Error', 'Error fetching secret: ' + error.message, 5);
            this.btnText.textContent = 'Reveal';
        } finally {
            this.revealBtn.disabled = false;
            this.showLoading(false);
        }
    }

    showLoading(loading) {
        if (this.btnIcon && this.btnSpinner) {
            if (loading) {
                this.btnIcon.classList.add('d-none');
                this.btnSpinner.classList.remove('d-none');
                this.btnText.textContent = 'Loading...';
            } else {
                this.btnIcon.classList.remove('d-none');
                this.btnSpinner.classList.add('d-none');
            }
        }
    }

    async handleCopy() {
        if (!this.secretValue) return;

        try {
            await navigator.clipboard.writeText(this.secretValue);
            const originalHTML = this.copyBtn.innerHTML;
            this.copyBtn.innerHTML = '<span class="text-success">Copied!</span>';
            setTimeout(() => {
                this.copyBtn.innerHTML = originalHTML;
            }, 2000);
            Notification.success('Copied', 'Secret copied to clipboard', 2);
        } catch {
            Notification.error('Error', 'Failed to copy to clipboard', 5);
        }
    }

    handleDelete(event) {
        event.preventDefault();
        const button = event.currentTarget;
        const form = button.closest('form');
        const identifier = button.dataset.identifier;

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
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new SecretView());
} else {
    new SecretView();
}

export default SecretView;
