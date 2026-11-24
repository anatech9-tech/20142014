/**
 * درخت سپید تجارت پارس — Contact Form Handler
 *
 * ویژگی‌ها:
 *   - Real-time validation (وقتی کاربر تایپ می‌کنه خطاها رفع میشه)
 *   - Submit via fetch() (بدون reload صفحه)
 *   - CSRF token از سرور گرفته میشه
 *   - نمایش پیام موفقیت/خطا به فارسی
 *   - Accessibility (aria-live برای screen readers)
 *   - کاملاً مستقل از ماژول‌های دیگه
 */

(function () {
    'use strict';

    const form = document.getElementById('contactForm');
    if (!form) return;

    const submitBtn = document.getElementById('submitBtn');
    const btnText   = submitBtn?.querySelector('.btn-text');
    const formStatus = document.getElementById('formStatus');

    // === Cache CSRF token ===
    let csrfToken = null;
    let csrfPromise = null;

    function getCsrfToken() {
        if (csrfToken) return Promise.resolve(csrfToken);
        if (csrfPromise) return csrfPromise;

        csrfPromise = fetch('contact.php?action=csrf', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
        })
        .then((r) => {
            if (!r.ok) throw new Error('Failed to fetch CSRF');
            return r.json();
        })
        .then((data) => {
            csrfToken = data.token;
            return csrfToken;
        })
        .catch((err) => {
            console.error('CSRF fetch error:', err);
            // Fallback: try form field
            const hidden = form.querySelector('input[name="csrf_token"]');
            if (hidden && hidden.value) {
                csrfToken = hidden.value;
                return csrfToken;
            }
            return null;
        })
        .finally(() => {
            csrfPromise = null;
        });

        return csrfPromise;
    }

    // === Validation helpers ===
    function showError(field, message) {
        const errorEl = form.querySelector(`[data-for="${field}"]`);
        const inputEl = form.querySelector(`[name="${field}"]`);
        if (errorEl) errorEl.textContent = message || '';
        if (inputEl) inputEl.classList.toggle('invalid', !!message);
    }

    function clearAllErrors() {
        form.querySelectorAll('.field-error').forEach((el) => (el.textContent = ''));
        form.querySelectorAll('.invalid').forEach((el) => el.classList.remove('invalid'));
    }

    function setStatus(message, type) {
        if (!formStatus) return;
        formStatus.className = 'form-status ' + (type || '');
        formStatus.textContent = message;
    }

    function clearStatus() {
        if (!formStatus) return;
        formStatus.className = 'form-status';
        formStatus.textContent = '';
    }

    function validateField(name, value) {
        const v = (value || '').trim();

        if (name === 'name') {
            if (!v) return 'نام الزامی است.';
            if (v.length < 2) return 'نام باید حداقل ۲ کاراکتر باشد.';
            if (v.length > 100) return 'نام نباید بیش از ۱۰۰ کاراکتر باشد.';
        }
        if (name === 'email') {
            if (!v) return 'ایمیل الزامی است.';
            // RFC 5322 simplified
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'ایمیل معتبر نیست.';
        }
        if (name === 'phone' && v) {
            if (!/^[\d\s\+\-\(\)]{5,30}$/.test(v)) return 'شماره تلفن معتبر نیست.';
        }
        if (name === 'message') {
            if (!v) return 'پیام الزامی است.';
            if (v.length < 10) return 'پیام باید حداقل ۱۰ کاراکتر باشد.';
            if (v.length > 5000) return 'پیام نباید بیش از ۵۰۰۰ کاراکتر باشد.';
        }
        return null;
    }

    function validateAll(formData) {
        const errors = {};
        ['name', 'email', 'phone', 'subject', 'message'].forEach((field) => {
            const err = validateField(field, formData.get(field) || '');
            if (err) errors[field] = err;
        });
        return errors;
    }

    // === Real-time validation on blur/typing ===
    form.querySelectorAll('input, textarea').forEach((input) => {
        if (input.name === 'website') return; // honeypot

        input.addEventListener('blur', () => {
            const err = validateField(input.name, input.value);
            showError(input.name, err);
        });

        input.addEventListener('input', () => {
            // فقط وقتی کاربر تایپ می‌کنه، خطا رو پاک کن
            const errEl = form.querySelector(`[data-for="${input.name}"]`);
            if (errEl && errEl.textContent) {
                showError(input.name, null);
            }
        });
    });

    // === Submit handler ===
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        clearStatus();

        const formData = new FormData(form);

        // Client-side validation
        const errors = validateAll(formData);
        if (Object.keys(errors).length > 0) {
            Object.entries(errors).forEach(([field, msg]) => showError(field, msg));
            // Focus on first error
            const firstErrorField = Object.keys(errors)[0];
            const firstInput = form.querySelector(`[name="${firstErrorField}"]`);
            if (firstInput) firstInput.focus();
            return;
        }

        // Get CSRF token
        const token = await getCsrfToken();
        if (!token) {
            setStatus('خطا در تأیید امنیتی. لطفاً صفحه را refresh کنید.', 'error');
            return;
        }

        // Lock UI
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.classList.add('loading');
        }
        if (btnText) btnText.textContent = 'در حال ارسال...';

        try {
            const response = await fetch('contact.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-Token': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            // Parse JSON (حتی اگه status 4xx/5xx باشه)
            let data = {};
            try {
                data = await response.json();
            } catch (_) {
                throw new Error('پاسخ نامعتبر از سرور');
            }

            if (response.ok && data.status === 'ok') {
                setStatus(data.message || 'پیام شما با موفقیت ارسال شد.', 'success');
                form.reset();
                clearAllErrors();

                // Optional: focus back to first field
                setTimeout(() => {
                    const firstInput = form.querySelector('[name="name"]');
                    if (firstInput) firstInput.focus();
                }, 100);
            } else if (data.errors) {
                // Server-side validation errors
                Object.entries(data.errors).forEach(([field, msg]) => showError(field, msg));
                setStatus(data.message || 'لطفاً خطاها را برطرف کنید.', 'error');
            } else if (response.status === 429) {
                setStatus(data.message || 'تعداد درخواست‌های شما زیاد است.', 'error');
            } else if (response.status === 403) {
                setStatus(data.message || 'توکن امنیتی منقضی شده. صفحه را refresh کنید.', 'error');
                // Refresh CSRF
                csrfToken = null;
            } else {
                setStatus(data.message || 'خطایی رخ داد. لطفاً بعداً تلاش کنید.', 'error');
            }
        } catch (err) {
            console.error('Submit error:', err);
            setStatus('خطا در ارتباط با سرور. اتصال اینترنت خود را بررسی کنید.', 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.classList.remove('loading');
            }
            if (btnText) btnText.textContent = 'ارسال پیام';
        }
    });

    // Pre-fetch CSRF token when contact section becomes visible
    if ('IntersectionObserver' in window) {
        const contactSection = document.getElementById('contact');
        if (contactSection) {
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            getCsrfToken();
                            observer.disconnect();
                        }
                    });
                },
                { rootMargin: '200px' }
            );
            observer.observe(contactSection);
        }
    }
})();
