/* Admin Panel JavaScript */
(function () {
    'use strict';

    // Mobile sidebar toggle
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebar');
    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 900 &&
                !sidebar.contains(e.target) &&
                !mobileToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        });
    }

    // Auto-dismiss alerts after 5s
    document.querySelectorAll('.alert.success, .alert.info').forEach((el) => {
        setTimeout(() => {
            el.style.transition = 'opacity 0.3s, transform 0.3s';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px)';
            setTimeout(() => el.remove(), 300);
        }, 5000);
    });

    // Confirm before delete
    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('click', (e) => {
            if (!confirm(el.dataset.confirm || 'آیا مطمئن هستید؟')) {
                e.preventDefault();
                e.stopPropagation();
            }
        });
    });

    // Auto-submit on filter change
    document.querySelectorAll('select[data-auto-submit]').forEach((el) => {
        el.addEventListener('change', () => el.form?.submit());
    });

    // Fetch helper with CSRF
    window.stsFetch = async function (url, options = {}) {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const opts = {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrf,
                ...(options.headers || {}),
            },
            ...options,
        };
        if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(opts.body);
        }
        const res = await fetch(url, opts);
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            throw new Error(data.error || data.message || `HTTP ${res.status}`);
        }
        return data;
    };

    // Modal helpers
    window.openModal = function (id) {
        document.getElementById(id)?.classList.add('open');
    };
    window.closeModal = function (id) {
        document.getElementById(id)?.classList.remove('open');
    };
    document.querySelectorAll('[data-close-modal]').forEach((el) => {
        el.addEventListener('click', () => closeModal(el.dataset.closeModal));
    });
    document.querySelectorAll('.modal-backdrop').forEach((bd) => {
        bd.addEventListener('click', (e) => {
            if (e.target === bd) bd.classList.remove('open');
        });
    });

    // Toggle password fields
    document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.togglePassword);
            if (input) {
                input.type = input.type === 'password' ? 'text' : 'password';
            }
        });
    });
})();
