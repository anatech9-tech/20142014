/* فروشگاه درخت سپید — جاوااسکریپت مشترک */
(function () {
    'use strict';

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    window.showToast = function (msg, isError = false) {
        let t = document.getElementById('shopToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'shopToast';
            t.className = 'toast';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.classList.toggle('error', isError);
        t.classList.add('show');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.classList.remove('show'), 3200);
    };

    async function api(url, data) {
        try {
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': CSRF,
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: new URLSearchParams(data).toString(),
            });
            const result = await res.json().catch(() => ({}));
            return res.ok ? result : { ...result, success: false };
        } catch (_) {
            return { success: false, error: 'ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.' };
        }
    }

    function refreshCartCount() {
        document.querySelectorAll('.cart-count').forEach(el => {
            const n = parseInt(el.dataset.count, 10) || 0;
            el.textContent = n;
            el.style.display = n > 0 ? 'flex' : 'none';
        });
    }

    // ---------- افزودن به سبد ----------
    document.querySelectorAll('[data-add-cart], [data-buy-now]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const pid = btn.dataset.addCart || btn.dataset.buyNow;
            const qty = parseInt(document.getElementById('qtyInput')?.value || '1', 10) || 1;
            btn.disabled = true;
            const r = await api('/shop/api/cart.php', { action: 'add', id: pid, qty });
            btn.disabled = false;
            if (r.success) {
                document.querySelectorAll('.cart-count').forEach(el => el.dataset.count = r.count);
                refreshCartCount();
                if (btn.dataset.buyNow) window.location.href = '/checkout/';
                else showToast('✅ به سبد خرید اضافه شد');
            } else {
                showToast(r.error || 'خطا در افزودن به سبد', true);
            }
        });
    });

    // ---------- کنترل تعداد ----------
    document.querySelectorAll('[data-qty-plus]').forEach(b => b.addEventListener('click', () => {
        const inp = document.getElementById('qtyInput');
        if (inp) inp.value = Math.min(999, parseInt(inp.value || 1, 10) + 1);
    }));
    document.querySelectorAll('[data-qty-minus]').forEach(b => b.addEventListener('click', () => {
        const inp = document.getElementById('qtyInput');
        if (inp) inp.value = Math.max(1, parseInt(inp.value || 1, 10) - 1);
    }));

    // ---------- گالری تصویر ----------
    const mainImg = document.getElementById('galleryMain');
    if (mainImg) {
        document.querySelectorAll('[data-thumb]').forEach(b => {
            b.addEventListener('click', () => {
                mainImg.src = b.dataset.thumb;
                document.querySelectorAll('[data-thumb]').forEach(x => x.classList.remove('active'));
                b.classList.add('active');
            });
        });
    }

    // ---------- تب‌های صفحه محصول ----------
    document.querySelectorAll('[data-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.closest('.p-tabs');
            group.querySelectorAll('.tab-btns button').forEach(b => b.classList.remove('active'));
            group.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab)?.classList.add('active');
        });
    });

    // ---------- ستاره‌های امتیاز ----------
    const starsInput = document.getElementById('starsInput');
    if (starsInput) {
        const rating = document.getElementById('reviewRating');
        starsInput.querySelectorAll('button').forEach((b, i) => {
            b.addEventListener('click', () => {
                rating.value = i + 1;
                starsInput.querySelectorAll('button').forEach((x, j) => x.classList.toggle('on', j <= i));
            });
        });
    }

    // ---------- فرم دیدگاه ----------
    const reviewForm = document.getElementById('reviewForm');
    if (reviewForm) {
        reviewForm.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(reviewForm).entries());
            const btn = reviewForm.querySelector('button[type="submit"]');
            btn.disabled = true;
            const r = await api('/shop/api/reviews.php', data);
            btn.disabled = false;
            if (r.success) {
                window.location.reload();
            } else {
                showToast(r.error || 'خطا', true);
            }
        });
    }

    // ---------- سبد خرید: تغییر مقدار / حذف ----------
    document.querySelectorAll('[data-cart-qty]').forEach(inp => {
        inp.addEventListener('change', async () => {
            const pid = inp.dataset.cartQty;
            const r = await api('/shop/api/cart.php', { action: 'set', id: pid, qty: inp.value });
            if (r.success) window.location.reload();
            else showToast(r.error || 'خطا در به‌روزرسانی سبد', true);
        });
    });
    document.querySelectorAll('[data-cart-remove]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const r = await api('/shop/api/cart.php', { action: 'remove', id: btn.dataset.cartRemove });
            if (r.success) window.location.reload();
            else showToast(r.error || 'خطا در به‌روزرسانی سبد', true);
        });
    });

    // ---------- کوپن ----------
    const couponForm = document.getElementById('couponForm');
    if (couponForm) {
        couponForm.addEventListener('submit', async e => {
            e.preventDefault();
            const code = couponForm.querySelector('input[name="code"]').value;
            const r = await api('/shop/api/cart.php', { action: 'coupon', code });
            if (r.success) window.location.reload();
            else showToast(r.error || 'کد معتبر نیست', true);
        });
    }
    const couponRemove = document.getElementById('couponRemove');
    if (couponRemove) {
        couponRemove.addEventListener('click', async () => {
            const r = await api('/shop/api/cart.php', { action: 'coupon_remove' });
            if (r.success) window.location.reload();
            else showToast(r.error || 'خطا در حذف کد تخفیف', true);
        });
    }

    // ---------- فرم‌های حساب کاربری ----------
    document.querySelectorAll('form[data-ajax-submit]').forEach(form => {
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const data = Object.fromEntries(new FormData(form).entries());
            const btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            const r = await api(form.dataset.ajaxSubmit, data);
            if (btn) btn.disabled = false;
            if (r.success) {
                if (r.redirect) window.location.href = r.redirect;
                else window.location.reload();
            } else {
                const box = document.getElementById(form.dataset.errorsInto || 'formErrors');
                if (box) {
                    box.replaceChildren(...(r.errors || [r.error || 'خطا']).map(x => {
                        const item = document.createElement('div');
                        item.className = 'alert error';
                        item.textContent = x;
                        return item;
                    }));
                    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                } else {
                    showToast(r.error || 'خطا', true);
                }
            }
        });
    });

    refreshCartCount();
})();
