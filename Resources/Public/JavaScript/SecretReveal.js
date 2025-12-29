/**
 * Secret reveal functionality for vault view page.
 */
(function() {
    'use strict';

    function initSecretReveal() {
        var revealBtn = document.getElementById('reveal-secret-btn');
        var copyBtn = document.getElementById('copy-secret-btn');
        var secretInput = document.getElementById('secret-value-display');
        var btnText = document.getElementById('reveal-btn-text');

        if (!revealBtn || !secretInput) {
            return;
        }

        var isRevealed = false;
        var secretValue = null;

        revealBtn.addEventListener('click', function() {
            if (isRevealed) {
                // Hide the secret
                secretInput.type = 'password';
                secretInput.value = '••••••••••••••••';
                btnText.textContent = 'Reveal';
                if (copyBtn) {
                    copyBtn.style.display = 'none';
                }
                isRevealed = false;
            } else {
                // Reveal the secret
                if (secretValue !== null) {
                    showSecret();
                } else {
                    var url = revealBtn.dataset.revealUrl + '&identifier=' + encodeURIComponent(revealBtn.dataset.identifier);
                    revealBtn.disabled = true;
                    btnText.textContent = 'Loading...';

                    fetch(url, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            secretValue = data.secret;
                            showSecret();
                        } else {
                            alert('Error: ' + (data.error || 'Unknown error'));
                            btnText.textContent = 'Reveal';
                        }
                    })
                    .catch(function(error) {
                        alert('Error fetching secret: ' + error.message);
                        btnText.textContent = 'Reveal';
                    })
                    .finally(function() {
                        revealBtn.disabled = false;
                    });
                }
            }
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                if (secretValue) {
                    navigator.clipboard.writeText(secretValue).then(function() {
                        var originalHTML = copyBtn.innerHTML;
                        copyBtn.innerHTML = '<span class="text-success">Copied!</span>';
                        setTimeout(function() {
                            copyBtn.innerHTML = originalHTML;
                        }, 2000);
                    }).catch(function() {
                        alert('Failed to copy to clipboard');
                    });
                }
            });
        }

        function showSecret() {
            secretInput.type = 'text';
            secretInput.value = secretValue;
            btnText.textContent = 'Hide';
            if (copyBtn) {
                copyBtn.style.display = 'inline-block';
            }
            isRevealed = true;
        }
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSecretReveal);
    } else {
        initSecretReveal();
    }
})();
