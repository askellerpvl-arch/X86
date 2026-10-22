const {test} = require('node:test');
const assert = require('node:assert/strict');
const {fixture} = require('./fixture.cjs');

test('supplied cashier flow retains editable price and exact sale payload', async t => {
  const f = await fixture('cashier'); t.after(()=>f.close());
  await f.evaluate("addByCode('CODE-1')");
  f.evaluate("document.querySelector('[data-p]').value='750';document.querySelector('[data-p]').dispatchEvent(new Event('input',{bubbles:true}));");
  await f.evaluate("document.querySelector('#checkout').onclick()");
  const sale = f.calls.find(c=>c.action==='sale');
  assert.equal(sale.credentials,'same-origin'); assert.equal(sale.method,'POST');
  assert.deepEqual(sale.body,{customer_type:'retail',payment_code:'cash',note:'',items:[{product_id:1,quantity:1,unit_price:750}]});
  assert.equal(f.evaluate('state.cart.length'),0);
});
for (const [code,rate] of [['cash',0],['kaspi_gold',4],['kaspi_red',10],['kaspi_qr_12',12]]) {
  test('supplied sale preserves payment option '+code, async t=>{
    const f=await fixture();t.after(()=>f.close());
    await f.evaluate("addByCode('CODE-1')");
    f.evaluate(`document.querySelector('#payment').value=${JSON.stringify(code)};calcCart()`);
    const before=f.evaluate("document.querySelector('#sale-totals').textContent");
    assert.match(before,new RegExp('Комиссия '+rate+'%'));
    await f.evaluate("document.querySelector('#checkout').onclick()");
    assert.equal(f.calls.find(c=>c.action==='sale').body.payment_code,code);
  });
}
test('failed sale retains cart, entered price and never retries', async t=>{
  const f=await fixture('cashier',{sale:async()=>{throw new TypeError('Failed to fetch')}});t.after(()=>f.close());
  await f.evaluate("addByCode('CODE-1')");
  await f.evaluate("document.querySelector('#checkout').onclick()");
  assert.equal(f.evaluate('state.cart.length'),1);assert.equal(f.calls.filter(c=>c.action==='sale').length,1);
});
test('cashier product card has no admin editor and keeps role-aware retail/partner controls',async t=>{
  const f=await fixture('cashier');t.after(()=>f.close());
  await f.evaluate('openProduct(1)');
  assert.equal(f.window.document.querySelector('#pm-save'),null);
  assert.ok(f.window.document.querySelector('#pm-cart-retail'));
  f.evaluate("document.querySelector('#pm-cart-partner').click()");
  assert.equal(f.evaluate('state.cart[0].unit_price'),800);
  assert.equal(f.evaluate('state.role'),'cashier');
});
