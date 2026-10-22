const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../app/src/main/java/kz/x86/stock/MainActivity.java'), 'utf8');
const section = source.slice(source.indexOf('private static final String HOME_BACK_JS =')).split(/;\s*\n/)[0];
const script = [...section.matchAll(/"(?:\\.|[^"\\])*"/g)].map(match => JSON.parse(match[0])).join('');
assert(script.startsWith('(function()'), 'actual production script must be extracted');
function run(active, present = true) {
    let clicked = false;
    const home = {textContent: 'Главная', className: active ? 'active' : '', parentElement: null,
        getAttribute: key => key === 'data-page' ? 'home' : null,
        getBoundingClientRect: () => ({width: 100, height: 40}),
        click: () => { clicked = true; }};
    const context = {document: {querySelectorAll: () => present ? [home] : []},
        getComputedStyle: () => ({display: 'block', visibility: 'visible'})};
    return {result: vm.runInNewContext(script, context), clicked};
}
assert.deepEqual(run(true), {result: 'home', clicked: false});
assert.deepEqual(run(false), {result: 'navigated', clicked: true});
assert.deepEqual(run(false, false), {result: 'unknown', clicked: false});
console.log('PASS: actual legacy Home/Back script, 3 scenarios');
