(() => {
    'use strict';

    const initializePasswordMatchValidation = () => {
        document.querySelectorAll('input[data-match]').forEach((confirmationInput) => {
            const passwordInput = document.getElementById(confirmationInput.dataset.match);

            if (!passwordInput || passwordInput.form !== confirmationInput.form) {
                return;
            }

            const updateValidity = () => {
                const message = confirmationInput.dataset.matchMessage || 'Passwords do not match.';
                const hasConfirmation = confirmationInput.value !== '';
                const passwordsMatch = confirmationInput.value === passwordInput.value;

                confirmationInput.setCustomValidity(
                    hasConfirmation && !passwordsMatch ? message : ''
                );
            };

            passwordInput.addEventListener('input', updateValidity);
            confirmationInput.addEventListener('input', updateValidity);
            updateValidity();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializePasswordMatchValidation, { once: true });
    } else {
        initializePasswordMatchValidation();
    }
})();