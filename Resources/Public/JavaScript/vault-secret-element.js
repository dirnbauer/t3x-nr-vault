/**
 * JavaScript module for vault secret TCA form element.
 */
class VaultSecretElement {
    constructor() {
        this.init();
    }

    init() {
        // Toggle visibility buttons
        document.querySelectorAll('.t3js-vault-toggle-visibility').forEach(button => {
            button.addEventListener('click', this.handleToggleVisibility.bind(this));
        });

        // Clear buttons
        document.querySelectorAll('.t3js-vault-clear').forEach(button => {
            button.addEventListener('click', this.handleClear.bind(this));
        });
    }

    handleToggleVisibility(event) {
        const button = event.currentTarget;
        const inputGroup = button.closest('.input-group');
        const input = inputGroup.querySelector('input[type="password"], input[type="text"]');

        if (input.type === 'password') {
            input.type = 'text';
            button.querySelector('.t3js-icon').classList.replace('icon-actions-eye', 'icon-actions-eye-slash');
        } else {
            input.type = 'password';
            button.querySelector('.t3js-icon').classList.replace('icon-actions-eye-slash', 'icon-actions-eye');
        }
    }

    handleClear(event) {
        const button = event.currentTarget;
        const inputGroup = button.closest('.input-group');
        const input = inputGroup.querySelector('input');

        if (confirm('Are you sure you want to clear this secret? This action cannot be undone.')) {
            input.value = '';
            input.placeholder = '';

            // Mark as cleared by removing the checksum
            const checksumField = input.closest('.formengine-field-item')
                .parentElement.querySelector('input[name$="[_vault_checksum]"]');
            if (checksumField) {
                checksumField.value = '';
            }

            // Remove the clear button
            button.remove();
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => new VaultSecretElement());
} else {
    new VaultSecretElement();
}

export default VaultSecretElement;
