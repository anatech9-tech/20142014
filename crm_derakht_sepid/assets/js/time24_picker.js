(function () {
    'use strict';
    if (!document.getElementById('time24-picker-style')) {
        var style = document.createElement('style');
        style.id = 'time24-picker-style';
        style.textContent = '.time24-host{position:relative;display:block}.time24-host>input{padding-inline-end:42px!important;direction:ltr;text-align:center}.time24-icon{position:absolute;inset-inline-end:7px;top:50%;transform:translateY(-50%);width:30px;height:30px;display:flex;align-items:center;justify-content:center;border:0;border-radius:8px;background:transparent;color:#64748b;cursor:pointer;z-index:2}.time24-icon:hover,.time24-icon:focus-visible{background:rgba(99,102,241,.12);color:#4f46e5;outline:none}.time24-icon svg{width:17px;height:17px;pointer-events:none}.time24-picker{position:fixed;z-index:2147483000;width:280px;background:#fff;color:#1e293b;border:1px solid #cbd5e1;border-radius:16px;box-shadow:0 12px 38px rgba(0,0,0,.35);padding:12px;direction:ltr;font-family:Arial,Tahoma,sans-serif}.time24-picker .t24-title{font-size:14px;font-weight:800;text-align:center;color:#4f46e5;margin-bottom:8px}.time24-picker .t24-face{position:relative;width:230px;height:230px;margin:auto;border-radius:50%;background:radial-gradient(circle,#c7d2fe 0 4px,transparent 5px),linear-gradient(#f8fafc,#eef2ff);border:2px solid #818cf8}.time24-picker .t24-hand{position:absolute;left:50%;top:50%;width:3px;height:88px;background:#4f46e5;transform-origin:50% 100%;transform:translate(-50%,-100%);z-index:1}.time24-picker .t24-center{position:absolute;left:50%;top:50%;width:11px;height:11px;border-radius:50%;background:#4f46e5;transform:translate(-50%,-50%);z-index:2}.time24-picker .t24-num{position:absolute;width:30px;height:30px;border:0;border-radius:50%;background:transparent;color:#1e293b;font:bold 11px Arial;transform:translate(-50%,-50%);cursor:pointer;z-index:3}.time24-picker .t24-num:hover,.time24-picker .t24-num.active{background:#4f46e5;color:#fff}.time24-picker .t24-actions{display:flex;justify-content:center;margin-top:8px}.time24-picker .t24-actions button{border:1px solid #a5b4fc;border-radius:7px;background:#eef2ff;color:#3730a3;padding:5px 14px;cursor:pointer;font:bold 11px Arial}';
        document.head.appendChild(style);
        var layoutStyle = document.createElement('style');
        layoutStyle.textContent = '.time24-host{position:relative;display:inline-block;width:100%;max-width:100%}.time24-host>input{width:100%;box-sizing:border-box;padding-inline-end:42px!important}.time24-icon{position:absolute;inset-inline-end:6px;top:50%;transform:translateY(-50%);z-index:2}.time24-picker .t24-face{touch-action:none;user-select:none}.time24-picker .t24-hand,.time24-picker .t24-center{pointer-events:none}.time24-picker .t24-hand-tip{position:absolute;left:50%;top:0;width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#4f46e5;color:#fff;font:bold 10px Arial;line-height:1;box-shadow:0 2px 7px rgba(79,70,229,.45);pointer-events:none;white-space:nowrap}';
        document.head.appendChild(layoutStyle);
    }
    var popup, input, hour = null;
    function close() { if (popup) popup.remove(); popup = null; input = null; }
    function open(el) {
        close(); input = el;
        var m = String(el.value || '').match(/^(\d{1,2}):(\d{2})$/);
        hour = m ? +m[1] : null;
        popup = document.createElement('div');
        popup.className = 'time24-picker';
        popup.innerHTML = '<div class="t24-title">انتخاب ساعت</div><div class="t24-face"><div class="t24-hand"></div><div class="t24-center"></div></div><div class="t24-actions"><button type="button" class="t24-close">بستن</button></div>';
        var face = popup.querySelector('.t24-face');
        var hand = popup.querySelector('.t24-hand');
        var handTip = document.createElement('span');
        handTip.className = 't24-hand-tip';
        face.appendChild(handTip);
        function updateHandTip(value, step) {
            var n = String(value).padStart(2, '0');
            handTip.textContent = n;
            var angle = value * step * Math.PI / 180 - Math.PI / 2;
            handTip.style.left = (115 + 88 * Math.cos(angle)) + 'px';
            handTip.style.top = (115 + 88 * Math.sin(angle)) + 'px';
            handTip.style.transform = 'translate(-50%,-50%)';
        }
        function finish(min) {
            el.value = String(hour === null ? 0 : hour).padStart(2, '0') + ':' + String(min).padStart(2, '0');
            el.dispatchEvent(new Event('change', { bubbles: true })); close();
        }
        function renderMinutes() {
            face.querySelectorAll('.t24-num').forEach(function (x) { x.remove(); });
            popup.querySelector('.t24-title').textContent = 'انتخاب دقیقه';
            for (var mm = 0; mm < 60; mm += 5) (function (min) {
                var b = document.createElement('button'); b.type = 'button'; b.className = 't24-num'; b.textContent = String(min).padStart(2, '0');
                var a = min * Math.PI / 30 - Math.PI / 2;
                b.style.left = (115 + 91 * Math.cos(a)) + 'px'; b.style.top = (115 + 91 * Math.sin(a)) + 'px';
                b.onclick = function () { finish(min); }; face.appendChild(b);
            })(mm);
            var minute = m ? +m[2] : 0, dragging = false;
            function setMinuteFromPointer(e) {
                var rr = face.getBoundingClientRect();
                var x = e.clientX - (rr.left + rr.width / 2);
                var y = e.clientY - (rr.top + rr.height / 2);
                var angle = Math.atan2(y, x) + Math.PI / 2;
                if (angle < 0) angle += Math.PI * 2;
                minute = Math.round((angle / (Math.PI * 2)) * 60) % 60;
                hand.style.transform = 'translate(-50%,-100%) rotate(' + (minute * 6) + 'deg)';
                updateHandTip(minute, 6);
            }
            hand.style.transform = 'translate(-50%,-100%) rotate(' + (minute * 6) + 'deg)';
            updateHandTip(minute, 6);
            face.onpointerdown = function (e) {
                if (e.target !== face && e.target !== hand) return;
                dragging = true;
                if (face.setPointerCapture) face.setPointerCapture(e.pointerId);
                setMinuteFromPointer(e);
                e.preventDefault();
            };
            face.onpointermove = function (e) { if (dragging) setMinuteFromPointer(e); };
            face.onpointerup = function (e) {
                if (!dragging) return;
                setMinuteFromPointer(e);
                dragging = false;
                finish(minute);
            };
            face.onpointercancel = function () { dragging = false; };
        }
        for (var i = 0; i < 24; i++) {
            (function (h) {
                var b = document.createElement('button'); b.type = 'button'; b.className = 't24-num'; b.textContent = String(h).padStart(2, '0');
                var a = h * Math.PI / 12 - Math.PI / 2;
                b.style.left = (115 + 91 * Math.cos(a)) + 'px'; b.style.top = (115 + 91 * Math.sin(a)) + 'px';
                b.onclick = function () {
                    hour = h; hand.style.transform = 'translate(-50%,-100%) rotate(' + (h * 15) + 'deg)';
                    updateHandTip(h, 15);
                    b.classList.add('active'); renderMinutes();
                };
                if (h === hour) b.classList.add('active'); face.appendChild(b);
            })(i);
        }
        if (hour !== null) {
            hand.style.transform = 'translate(-50%,-100%) rotate(' + (hour * 15) + 'deg)';
            updateHandTip(hour, 15);
        }
        popup.querySelector('.t24-close').onclick = close;
        document.body.appendChild(popup);
        var r = el.getBoundingClientRect();
        popup.style.left = Math.min(Math.max(8, r.left), innerWidth - 296) + 'px';
        popup.style.top = Math.min(r.bottom + 6, innerHeight - 410) + 'px';
    }
    function init() {
        document.querySelectorAll('input[type="time"], input[data-hour24]').forEach(function (el) {
            if (el.dataset.time24Ready) return;
            el.dataset.time24Ready = '1';
            try { el.type = 'text'; } catch (e) {}
            el.inputMode = 'numeric'; el.placeholder = '13:00'; el.maxLength = 5;
            var host = el.parentElement;
            if (!host || !host.classList.contains('time24-host')) {
                if (host) {
                    var wrapper = document.createElement('span');
                    wrapper.className = 'time24-host';
                    host.insertBefore(wrapper, el);
                    wrapper.appendChild(el);
                    host = wrapper;
                    var icon = document.createElement('button');
                    icon.type = 'button';
                    icon.className = 'time24-icon';
                    icon.setAttribute('aria-label', 'باز کردن انتخاب‌گر ساعت');
                    icon.title = 'انتخاب ساعت';
                    icon.innerHTML = '<i data-lucide="clock-3"></i>';
                    host.appendChild(icon);
                    icon.addEventListener('mousedown', function (e) { e.preventDefault(); });
                    icon.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        open(el);
                    });
                    icon.addEventListener('keydown', function (e) {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            open(el);
                        }
                    });
                    if (window.lucide && window.lucide.createIcons) {
                        try { window.lucide.createIcons({ nodes: [icon] }); } catch (e) {}
                    }
                }
            }
            el.addEventListener('input', function () {
                var v = this.value.replace(/[^\d]/g, '').slice(0, 4);
                this.value = v.length > 2 ? v.slice(0, 2) + ':' + v.slice(2) : v;
            });
        });
    }
    init();
    new MutationObserver(init).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('mousedown', function (e) { if (popup && e.target !== input && !popup.contains(e.target)) close(); });
})();
