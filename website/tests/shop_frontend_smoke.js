/* Mock DOM/network smoke test, not a browser integration test. */
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const script = fs.readFileSync(require('path').join(__dirname, '../js/shop.js'), 'utf8');
async function scenario({buy = false, response, throws = false}) {
    let handler, calls = [], toast;
    const button = {dataset: buy ? {buyNow: '42'} : {addCart: '42'}, disabled: false, addEventListener(_, fn) {handler = fn;}};
    const counter = {dataset: {count:'0'}, style:{}, textContent:''};
    const document = {
        querySelector(selector) {return selector.includes('csrf-token') ? {content:'csrf'} : null;},
        querySelectorAll(selector) {if (selector === '[data-add-cart], [data-buy-now]') return [button]; if (selector === '.cart-count') return [counter]; return [];},
        getElementById(id) {return id === 'shopToast' ? toast : null;},
        createElement() {return {classList:{toggle(){}, add(){}, remove(){}}, textContent:''};},
        body: {appendChild(t) {toast=t;}}
    };
    const window = {location:{href:''}};
    const context = {document, window, URLSearchParams, clearTimeout(){}, setTimeout(){return 1;}, fetch: async (url, opts) => {calls.push({url, opts}); if(throws) throw new Error('offline'); return {ok:response.ok, json:async()=>response.body};}};
    vm.createContext(context); vm.runInContext(script, context); context.showToast = window.showToast;
    await handler();
    assert.equal(calls[0].url, '/shop/api/cart.php');
    assert.equal(calls[0].opts.headers['X-CSRF-Token'], 'csrf');
    assert.equal(new URLSearchParams(calls[0].opts.body).get('id'), '42');
    assert.equal(button.disabled, false);
    return {window, counter, toast};
}
(async()=>{
    let result=await scenario({buy:true,response:{ok:true,body:{success:true,count:3}}});
    assert.equal(result.window.location.href,'/checkout/'); assert.equal(result.counter.textContent,3);
    result=await scenario({buy:true,response:{ok:false,body:{success:false,error:'out'}}});
    assert.equal(result.window.location.href,''); assert.equal(result.toast.textContent,'out');
    result=await scenario({buy:false,throws:true});
    assert.equal(result.window.location.href,''); assert.match(result.toast.textContent,/ارتباط/);
    console.log('PASS cart API path, CSRF, buy-now success/failure and network error');
})().catch(e=>{console.error(e);process.exit(1)});
