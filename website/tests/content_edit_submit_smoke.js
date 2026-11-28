'use strict';
// Executes the actual inline script against a minimal DOM; not a real browser or HTTP test.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');
async function check(kind, prefix, fail) {
    const page = fs.readFileSync(path.join(__dirname, '..', 'admin', `${kind}.php`), 'utf8');
    const script = page.match(/<script>([\s\S]*?)<\/script>/)[1];
    let handler, calls = 0, reloads = 0, message = '', finish;
    const button = {disabled: false};
    const form = {addEventListener: (event, fn) => { assert.strictEqual(event, 'submit'); handler = fn; }, querySelector: () => button};
    const context = {
        document: {querySelector: () => ({content:'csrf'}), getElementById: id => id === `${prefix}Form` ? form : null},
        FormData: class { append() {} }, location: {reload: () => reloads++},
        stsFetch: () => { calls++; return new Promise((resolve, reject) => {finish = fail ? () => reject(new Error('network')) : resolve;}); },
        alert: msg => {message = msg;}, confirm: () => true
    };
    vm.runInNewContext(script, context);
    const event = {target: form, preventDefault() {}};
    const first = handler(event);
    assert.strictEqual(button.disabled, true);
    await handler(event);
    assert.strictEqual(calls, 1);
    finish(); await first;
    if (fail) {
        assert.strictEqual(button.disabled, false);
        assert.ok(message.includes('قبل از تلاش دوباره'));
        const retry = handler(event); assert.strictEqual(calls, 2); finish(); await retry;
    } else { assert.strictEqual(reloads, 1); assert.strictEqual(button.disabled, true); }
}
(async () => {
    for (const [kind, prefix] of [['services','srv'], ['team','team']]) {
        await check(kind, prefix, false); await check(kind, prefix, true);
    }
    console.log('PASS legacy content submit guards prevent concurrent clicks and warn on uncertain result');
})().catch(err => {console.error(err); process.exit(1);});
