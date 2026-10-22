const {test} = require('node:test');
const assert = require('node:assert/strict');
const {fixture} = require('./fixture.cjs');
function enable(f, options={}) {
  f.window.__x86MobileOptions={navigation:true,liveSearch:true,itemActions:true,gestures:true,cashier:true,safeRefresh:true,...options};
  assert.equal(f.asset('stock-mobile-v2.js'),true);
}
test('safe refresh recognises real empty Home but blocks cart and pending writes',async t=>{
  const f=await fixture();t.after(()=>f.close());enable(f);
  assert.equal(f.asset('refresh-probe.js'),'safe');
  await f.evaluate("addByCode('CODE-1')");
  assert.equal(f.asset('refresh-probe.js'),'busy');
});
test('last server search response wins without replacing actual search API',async t=>{
  const pending={};
  const f=await fixture('admin',{search:call=>new Promise(resolve=>pending[call.query.q]=resolve)});t.after(()=>f.close());enable(f);
  f.evaluate("document.querySelector('#product-search').value='old'");const first=f.evaluate('searchProducts()');
  f.evaluate("document.querySelector('#product-search').value='new'");const last=f.evaluate('searchProducts()');
  pending.new({ok:true,items:[{...f.product,name:'Newest response'}]});await last;
  pending.old({ok:true,items:[{...f.product,name:'Stale response'}]});await first;
  assert.match(f.window.document.querySelector('#product-list').textContent,/Newest response/);
  assert.doesNotMatch(f.window.document.querySelector('#product-list').textContent,/Stale response/);
  assert.ok(f.calls.filter(c=>c.action==='search').every(c=>c.method==='GET'&&c.credentials==='same-origin'));
});
test('enhanced cashier layout preserves custom-price sale payload and only emits confirmed server result',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());const messages=[];
  f.window.X86Status={postMessage:raw=>messages.push(JSON.parse(raw))};enable(f,{notifications:true});
  await f.evaluate("addByCode('CODE-1')");
  f.evaluate("document.querySelector('[data-p]').value='725';document.querySelector('[data-p]').dispatchEvent(new Event('input',{bubbles:true}));");
  assert.equal(f.calls.filter(c=>c.action==='sale').length,0);
  await f.evaluate("document.querySelector('#checkout').onclick()");
  assert.equal(f.calls.find(c=>c.action==='sale').body.items[0].unit_price,725);
  assert.equal(messages.length,1);assert.equal(messages[0].reference,101);
  assert.equal(f.evaluate('state.role'),'cashier');
});
test('lost sale response keeps cart and never creates success notification or retry',async t=>{
  const f=await fixture('cashier',{sale:async()=>{throw new TypeError('Failed to fetch')}});t.after(()=>f.close());
  const messages=[];f.window.X86Status={postMessage:raw=>messages.push(JSON.parse(raw))};enable(f,{notifications:true});
  await f.evaluate("addByCode('CODE-1')");await f.evaluate("document.querySelector('#checkout').onclick()");
  assert.equal(f.evaluate('state.cart.length'),1);assert.equal(f.calls.filter(c=>c.action==='sale').length,1);
  assert.equal(messages.length,0);assert.match(f.window.document.querySelector('#x86-operation-state').textContent,/Проверьте/);
  assert.equal(f.asset('refresh-probe.js'),'busy');
});
test('cashier Back preserves unfinished checkout rather than reloading or exiting',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());enable(f);
  await f.evaluate("addByCode('CODE-1')");assert.equal(f.asset('enhanced-back.js'),'blocked');
  assert.equal(f.evaluate('state.cart.length'),1);
});
test('classic mode removes enhancements without resetting page or cart',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());enable(f);
  await f.evaluate("addByCode('CODE-1')");
  f.window.__x86MobileOptions={};f.asset('stock-mobile-v2.js');
  assert.equal(f.window.document.querySelector('.x86-cashier-steps'),null);
  assert.equal(f.evaluate('state.cart.length'),1);assert.equal(f.evaluate('state.role'),'cashier');
});
test('dirty product editor is never discarded by Back',async t=>{
  const f=await fixture();t.after(()=>f.close());enable(f);await f.evaluate('openProduct(1)');
  f.evaluate("document.querySelector('#pm-price').value='777'");
  assert.equal(f.asset('enhanced-back.js'),'blocked');
  assert.equal(f.window.document.querySelector('#pm-price').value,'777');
  assert.equal(f.calls.filter(c=>c.method==='POST').length,0);
});
test('unsaved stock-status selection is never discarded by Back',async t=>{
  const f=await fixture();t.after(()=>f.close());
  f.product.stock_statuses=[{stock_status_id:1,name:'В наличии'},{stock_status_id:2,name:'Предзаказ'}];
  enable(f);await f.evaluate('openProduct(1)');
  f.window.document.querySelector('#pm-stock-status').value='2';
  assert.equal(f.asset('enhanced-back.js'),'blocked');
  assert.equal(f.window.document.querySelector('#pm-stock-status').value,'2');
});
test('scan confirmation cancels without adding a product or posting a transaction',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());enable(f,{scanConfirm:true});
  // The supplied scanner is replaced only by a deterministic camera boundary here.
  f.evaluate('navigator.mediaDevices={getUserMedia:async()=>{throw new Error("No fixture camera")}}');
  await f.evaluate('openScanner(code=>addByCode(code))');
  const callback=f.evaluate("(closeScanner(), state.scanCallback('CODE-1'))");
  assert.ok(f.window.document.querySelector('#x86-scan-confirm'));
  f.window.document.querySelector('#x86-scan-cancel').click();await callback;
  assert.equal(f.evaluate('state.cart.length'),0);assert.equal(f.calls.filter(c=>c.method==='POST').length,0);
});
test('confirmed scan adds to existing cart once but never performs a sale',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());enable(f,{scanConfirm:true,scannerFeedback:true});
  const messages=[];f.window.X86Status={postMessage:raw=>messages.push(JSON.parse(raw))};
  f.evaluate('navigator.mediaDevices={getUserMedia:async()=>{throw new Error("No fixture camera")}}');
  await f.evaluate('openScanner(code=>addByCode(code))');const callback=f.evaluate("(closeScanner(), state.scanCallback('CODE-1'))");
  f.window.document.querySelector('#x86-scan-accept').click();await callback;
  assert.equal(f.evaluate('state.cart.length'),1);assert.equal(f.calls.filter(c=>c.action==='sale').length,0);
  assert.deepEqual(messages,[{version:1,type:'scan'}]);
});
test('horizontal gesture opens only a safe menu, vertical movement leaves scrolling alone',async t=>{
  const f=await fixture();t.after(()=>f.close());enable(f);await f.evaluate('searchProducts()');
  const card=f.window.document.querySelector('[data-product]');
  const event=(type,x,y)=>new f.window.MouseEvent(type,{bubbles:true,cancelable:true,clientX:x,clientY:y});
  card.dispatchEvent(event('pointerdown',10,10));card.dispatchEvent(event('pointermove',12,100));card.dispatchEvent(event('pointerup',12,100));
  assert.equal(f.window.document.querySelector('.x86-item-menu'),null);
  card.dispatchEvent(event('pointerdown',10,10));card.dispatchEvent(event('pointerup',130,12));
  assert.ok(f.window.document.querySelector('.x86-item-menu'));
  assert.equal(f.calls.filter(c=>c.method==='POST').length,0);
});
test('cashier item menu never offers admin editor or direct printing',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());enable(f);await f.evaluate('searchProducts()');
  const card=f.window.document.querySelector('[data-product]');
  card.dispatchEvent(new f.window.MouseEvent('pointerdown',{bubbles:true,clientX:10,clientY:10}));
  card.dispatchEvent(new f.window.MouseEvent('pointerup',{bubbles:true,clientX:130,clientY:10}));
  const text=f.window.document.querySelector('.x86-item-menu').textContent;
  assert.doesNotMatch(text,/Изменить|PDF/);assert.equal(f.calls.filter(c=>c.method==='POST').length,0);
});
test('search result markup cannot create active HTML',async t=>{
  const f=await fixture('cashier',{search:async()=>({ok:true,items:[{product_id:3,name:'<img src=x onerror=alert(1)>',model:'test',price:1}]})});
  t.after(()=>f.close());enable(f);await f.evaluate('searchProducts()');
  assert.equal(f.window.document.querySelector('#product-list img'),null);
  assert.match(f.window.document.querySelector('#product-list').textContent,/<img/);
});
test('pending original API call blocks refresh until confirmed completion',async t=>{
  let finish;
  const f=await fixture('admin',{receive:()=>new Promise(resolve=>finish=resolve)});t.after(()=>f.close());enable(f);
  const operation=f.evaluate("api('receive',{body:{product_id:1,quantity:2,unit_cost:700,supplier:'fixture'}})");
  assert.equal(f.asset('refresh-probe.js'),'busy');
  finish({ok:true,result:{purchase_id:1}});await operation;
  assert.equal(f.asset('refresh-probe.js'),'safe');
});

test('pending scan confirmation blocks Home refresh after the camera closes',async t=>{
  const f=await fixture();t.after(()=>f.close());enable(f,{scanConfirm:true});
  f.evaluate('navigator.mediaDevices={getUserMedia:async()=>{throw new Error("No fixture camera")}}');
  await f.evaluate('openScanner(code=>addByCode(code))');
  const pending=f.evaluate("(closeScanner(),state.scanCallback('CODE-1'))");
  assert.equal(f.asset('refresh-probe.js'),'busy');
  assert.equal(f.asset('enhanced-back.js'),'closed');await pending;
  assert.equal(f.evaluate('state.cart.length'),0);
});
