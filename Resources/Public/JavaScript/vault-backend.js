/**
 * JavaScript module for vault backend module.
 */
import Notification from '@typo3/backend/notification.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

class VaultBackend {
    constructor() {
        this.init();
    }

    init() {
        // Verify hash chain button
        document.querySelectorAll('.t3js-vault-verify-chain').forEach(button => {
            button.addEventListener('click', this.handleVerifyChain.bind(this));
        });
    }

    async handleVerifyChain(event) {
        const button = event.currentTarget;
        const originalChildren = Array.from(button.childNodes);

        button.disabled = true;
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm';
        button.replaceChildren(spinner, document.createTextNode(' Verifying...'));

        try {
            const response = await new AjaxRequest(TYPO3.settings.ajaxUrls['system_vault'])
                .withQueryArguments({ action: 'verifyChain' })
                .get();

            const result = await response.resolve();

            if (result.valid) {
                Notification.success('Hash Chain Valid', result.message, 5);
            } else {
                Notification.error('Hash Chain Invalid', result.message, 10);

                // Show details
                if (result.errors && Object.keys(result.errors).length > 0) {
                    const errorList = Object.entries(result.errors)
                        .map(([uid, error]) => `Entry ${uid}: ${error}`)
                        .join('\n');
                    Notification.warning('Verification Errors', errorList, 15);
                }
            }
        } catch (error) {
            Notification.error('Verification Failed', error.message || 'An error occurred', 10);
        } finally {
            button.disabled = false;
            button.replaceChildren(...originalChildren);
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new VaultBackend());
} else {
    new VaultBackend();
}

export default VaultBackend;
