const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {JSDOM} = require('jsdom');
const assets = path.resolve(__dirname, '../../app/src/debug/assets/server-fixture');

exports.fixture = async function fixture(role = 'admin', overrides = {}) {
  const dom = new JSDOM(fs.readFileSync(path.join(assets, 'index.html'), 'utf8'), {
    url: 'https://x86.kz/stock/', runScripts: 'outside-only', pretendToBeVisual: true
  });
  const window = dom.window;
  const calls = [];
  const product = {product_id: 1, name: 'Fixture product', model: 'MODEL-1', sku: 'PN-1',
    barcode: 'CODE-1', quantity: 10, price: 1000, partner_price: 800, cost_price: 400,
    min_stock: 1, stock_status_id: 1, stock_status_name: 'В наличии', stock_statuses: []};
  const payments = [
    {code:'cash',name:'Наличные',rate:0}, {code:'kaspi_gold',name:'Kaspi Gold',rate:4},
    {code:'kaspi_red',name:'Kaspi Red',rate:10}, {code:'kaspi_qr_12',name:'0-0-12',rate:12}
  ];
  window.scrollTo = () => {};
  window.confirm = () => false;
  window.alert = () => {};
  window.fetch = async (url, options = {}) => {
    const parsed = new URL(url, window.location.href);
    const action = parsed.searchParams.get('action');
    const call = {action, query: Object.fromEntries(parsed.searchParams), method: options.method || 'GET',
      body: options.body ? JSON.parse(options.body) : undefined, credentials: options.credentials};
    calls.push(call);
    let payload;
    if (overrides[action]) payload = await overrides[action](call);
    else if (action === 'me') payload = {ok:true, authenticated:false};
    else if (action === 'commissions') payload = {ok:true, items:payments};
    else if (action === 'dashboard') payload = {ok:true, dashboard:{}, recommendations:[], chart:[]};
    else if (action === 'pos_status') payload = {ok:true, bridge:{online:false}};
    else if (action === 'find') payload = {ok:true, product};
    else if (action === 'search') payload = {ok:true, items:[product]};
    else if (action === 'product') payload = {ok:true, product, history:[]};
    else if (action === 'sale') payload = {ok:true, result:{sale_id:101, gross_total:2000}};
    else if (action === 'receive') payload = {ok:true, result:{purchase_id:22,qty_after:12,new_avg_cost:450}, product};
    else throw new Error('Unexpected fixture API call: ' + action);
    return {ok:true,status:200,json:async()=>payload};
  };
  const context = dom.getInternalVMContext();
  const evaluate = code => vm.runInContext(code, context);
  evaluate(fs.readFileSync(path.join(assets,'app.js'),'utf8'));
  await new Promise(resolve => setImmediate(resolve));
  await evaluate(`afterLogin(${JSON.stringify(role)})`);
  return {dom, window, calls, product, evaluate,
    asset(name) { return evaluate(fs.readFileSync(path.resolve(__dirname,'../../app/src/main/assets',name),'utf8')); },
    close() { window.close(); }};
};
