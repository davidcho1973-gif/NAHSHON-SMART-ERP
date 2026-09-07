const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function element() {
    return { value: '', textContent: '', children: [], handlers: {}, focused: false,
        addEventListener(name, callback) { this.handlers[name] = callback; },
        appendChild(child) { this.children.push(child); }, replaceChildren() { this.children = []; },
        focus() { this.focused = true; } };
}
const choice = element(), input = element(), status = element(), pending = [];
const context = { window: {}, document: { createElement: element },
    fetch(url) { return new Promise((resolve, reject) => pending.push({url, resolve, reject})); } };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/js/employee-join-trades.js'), 'utf8'), context);
const picker = context.window.createEmployeeTradePicker({choice, input, status, urls: {a:'/a', b:'/b'}, defaults:['Electrician','공무지원'], initialSite:'', initialTrades:[]});
const labels = {tradePlaceholder:'Choose', tradeOther:'Type', tradeLoading:'Loading', tradeLoadFailed:'Type manually'};
const values = () => choice.children.map(o => o.value);
function resolve(index, trades) { pending[index].resolve({ok:true, json:async()=>({trades})}); }
(async () => {
    await picker.refresh('', labels);
    assert(values().includes('공무지원'));
    choice.value = '공무지원'; choice.handlers.change(); assert.equal(input.value,'공무지원');
    choice.value = '__other__'; choice.handlers.change(); assert.equal(input.value,''); assert(input.focused);
    input.value = '특수 보온'; input.handlers.input(); assert.equal(choice.value,'__other__');
    await picker.refresh('global', labels); assert(values().includes('공무지원')); assert.equal(input.value,'특수 보온');
    const first = picker.refresh('a', labels), second = picker.refresh('b', labels);
    resolve(1,['전기','공무지원']); await second;
    resolve(0,['배관','공무지원']); await first;
    assert(values().includes('전기')); assert(!values().includes('배관')); assert.equal(input.value,'특수 보온');
    const failed = picker.refresh('a',labels); pending[2].reject(new Error('offline')); await failed;
    assert.equal(status.textContent,'Type manually'); assert(values().includes('공무지원'));
    input.value = 'Manual after network failure'; input.handlers.input(); assert.equal(choice.value,'__other__');
    const retry = picker.refresh('a',labels); resolve(3,['배관','공무지원']); await retry;
    assert.equal(status.textContent,''); assert.equal(input.value,'Manual after network failure');
    await picker.refresh('a',{...labels,tradeOther:'직접 입력'});
    assert.equal(choice.children.at(-1).textContent,'직접 입력'); assert.equal(input.value,'Manual after network failure');
    input.value = '배관'; input.handlers.input(); assert.equal(choice.value,'배관');
    console.log('Employee trade picker: defaults, Global, selection, manual input, site race, failure, retry and language passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
