(function () {
    if (typeof window.showNotification === 'function') {
        return;
    }

    function ensureNotificationStyles() {
        if (document.querySelector('#notification-styles')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            @keyframes slideInRight {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            @keyframes slideOutRight {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }
            .alert-success {
                background: linear-gradient(135deg, #d4edda, #c3e6cb);
                border: 1px solid #b1dfbb;
                color: #155724;
            }
            .alert-danger {
                background: linear-gradient(135deg, #f8d7da, #f5c6cb);
                border: 1px solid #f1b0b7;
                color: #721c24;
            }
            .alert .btn-close:focus { box-shadow: none; }
        `;
        document.head.appendChild(style);
    }

    window.showNotification = function showNotification(message, type) {
        const normalized = type === 'error' ? 'danger' : (type || 'success');
        const isSuccess = normalized === 'success';
        const alertClass = isSuccess ? 'alert-success' : 'alert-danger';
        const iconClass = isSuccess ? 'bi-check-circle' : 'bi-exclamation-circle';

        ensureNotificationStyles();

        const notification = document.createElement('div');
        notification.className = 'alert ' + alertClass + ' alert-dismissible fade show position-fixed';
        notification.style.cssText = [
            'bottom: 20px',
            'right: 20px',
            'z-index: 9999',
            'min-width: 300px',
            'max-width: 400px',
            'border-radius: 8px',
            'border: none',
            'box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15)',
            'animation: slideInRight 0.3s ease-out',
        ].join(';');

        notification.innerHTML =
            '<div class="d-flex align-items-center">' +
            '<i class="bi ' + iconClass + ' me-2 fs-5"></i>' +
            '<div class="flex-grow-1">' + message + '</div>' +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>' +
            '</div>';

        document.body.appendChild(notification);

        setTimeout(function () {
            if (!notification.parentNode) {
                return;
            }
            notification.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(function () {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 300);
        }, 3000);
    };
})();
