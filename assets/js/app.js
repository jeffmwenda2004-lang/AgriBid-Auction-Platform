(() => {
    'use strict';

    const initializeApp = () => {
        document.querySelectorAll('form[data-confirm]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                const message = form.dataset.confirm;

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        if (!window.bootstrap) {
            return;
        }

        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((element) => {
            window.bootstrap.Tooltip.getOrCreateInstance(element);
        });

        document.querySelectorAll('[data-bs-toggle="popover"]').forEach((element) => {
            window.bootstrap.Popover.getOrCreateInstance(element);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeApp, { once: true });
    } else {
        initializeApp();
    }
})();