/* Arash Coin reward animation.  It is intentionally dependency-free so it also
   works in the existing PHP pages and on low-end mobile devices. */
(function () {
    'use strict';
    var COIN_SRC = 'assets/icons/arash-coin.png';
    var UI_LANG = (document.documentElement.lang || 'fa').toLowerCase().split('-')[0];
    var COIN_TEXT = {
        fa: {unit: 'آرش‌کوین', done: 'کار انجام شد!', received: 'دریافت کردی!'},
        ar: {unit: 'عملات آرش', done: 'تم إنجاز العمل!', received: 'تم الاستلام!'},
        en: {unit: 'Arash Coin', done: 'Task completed!', received: 'received!'}
    }[UI_LANG] || {unit: 'آرش‌کوین', done: 'کار انجام شد!', received: 'دریافت کردی!'};
    var NUMBER_LOCALE = UI_LANG === 'en' ? 'en-US' : (UI_LANG === 'ar' ? 'ar' : 'fa-IR');

    function coinNumber(value) { return Number(value || 0).toLocaleString(NUMBER_LOCALE); }
    function setHeaderBalance(balance) {
        var places = document.querySelectorAll('[data-arash-coin-balance]');
        places.forEach(function (el) {
            el.textContent = coinNumber(balance);
        });
    }

    window.showArashCoinReward = function (reward) {
        if (!reward || !reward.amount || document.querySelector('.arash-coin-reward-overlay')) return;
        var amount = Number(reward.amount) || 0;
        if (amount <= 0) return;

        var overlay = document.createElement('div');
        overlay.className = 'arash-coin-reward-overlay';
        overlay.innerHTML = '<div class="arash-coin-reward-core">'
            + '<img class="arash-coin-reward-image" src="' + COIN_SRC + '" alt="Arash Coin">'
            + '<div class="arash-coin-reward-text">+' + coinNumber(amount) + ' ' + COIN_TEXT.unit + '</div>'
            + '<div class="arash-coin-reward-sub">🎉 ' + COIN_TEXT.done + (reward.rule_title ? ' — ' + escapeHtml(reward.rule_title) : '') + '</div>'
            + '</div>';
        document.body.appendChild(overlay);

        var centerX = window.innerWidth / 2;
        var centerY = window.innerHeight / 2;
        for (var i = 0; i < 15; i++) {
            var particle = document.createElement('img');
            particle.className = 'arash-coin-particle';
            particle.src = COIN_SRC;
            particle.alt = '';
            var angle = (Math.PI * 2 * i / 15) + (Math.random() * .35);
            var distance = 110 + Math.random() * 190;
            particle.style.left = centerX + 'px';
            particle.style.top = centerY + 'px';
            particle.style.setProperty('--ax', Math.cos(angle) * distance + 'px');
            particle.style.setProperty('--ay', Math.sin(angle) * distance + 'px');
            particle.style.setProperty('--as', (0.55 + Math.random() * .9).toFixed(2));
            particle.style.setProperty('--ar', ((Math.random() * 720) - 360).toFixed(0) + 'deg');
            document.body.appendChild(particle);
            setTimeout((function (node) { return function () { node.remove(); }; })(particle), 1350);
        }

        if (typeof reward.balance !== 'undefined') setHeaderBalance(reward.balance);
        if (typeof window.showToast === 'function') {
            window.showToast('🎉 ' + coinNumber(amount) + ' ' + COIN_TEXT.unit + ' ' + COIN_TEXT.received, 'success');
        }
        setTimeout(function () { overlay.remove(); }, 1200);
    };

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }
})();
