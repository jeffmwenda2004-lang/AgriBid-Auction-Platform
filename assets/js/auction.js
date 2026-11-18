(() => {
    'use strict';

    const formatDuration = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;

        return `${days}d ${hours}h ${minutes}m ${seconds}s`;
    };

    const parseTime = (value) => {
        if (!value) {
            return Number.NaN;
        }

        const numericTime = Number(value);
        return Number.isFinite(numericTime) ? numericTime : Date.parse(value);
    };

    const initializeCountdowns = () => {
        let isRefreshingPage = false;

        document.querySelectorAll('.countdown-box, [data-end-time]').forEach((element) => {
            const startTime = parseTime(element.dataset.startTime);
            const endTime = parseTime(element.dataset.endTime);
            const textElement = element.querySelector('.countdown-text');

            if (!textElement) {
                return;
            }

            if (!Number.isFinite(startTime) || !Number.isFinite(endTime) || endTime <= startTime) {
                textElement.textContent = 'Auction time unavailable';
                return;
            }

            const wasScheduled = Date.now() < startTime;
            const wasOpen = Date.now() < endTime;

            const refreshPage = () => {
                if (!isRefreshingPage) {
                    isRefreshingPage = true;
                    window.location.reload();
                }
            };

            const updateCountdown = () => {
                const now = Date.now();

                if (now < startTime) {
                    textElement.textContent = `Starts in ${formatDuration(startTime - now)}`;
                    return;
                }

                if (wasScheduled) {
                    refreshPage();
                    return;
                }

                if (now >= endTime) {
                    if (wasOpen) {
                        refreshPage();
                        return;
                    }

                    textElement.textContent = 'Auction ended';
                    element.classList.add('text-danger');

                    document.querySelectorAll('.bid-form input, .bid-form button').forEach((field) => {
                        field.disabled = true;
                    });
                    return;
                }

                textElement.textContent = `${formatDuration(endTime - now)} remaining`;
            };

            updateCountdown();
            window.setInterval(updateCountdown, 1000);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCountdowns, { once: true });
    } else {
        initializeCountdowns();
    }
})();