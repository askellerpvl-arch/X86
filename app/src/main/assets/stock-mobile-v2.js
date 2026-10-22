(function () {
  'use strict';
  // This adapter targets the supplied 1.9.3 DOM/functions. Unknown contracts fall back.
  if (typeof state === 'undefined' || !Array.isArray(state.cart) || !Array.isArray(state.labelQueue) ||
      typeof api !== 'function' || typeof go !== 'function' || typeof searchProducts !== 'function' ||
      typeof openProduct !== 'function' || typeof money !== 'function' || typeof stockText !== 'function' ||
      !document.querySelector('#product-search') || !document.querySelector('#checkout')) return false;
  var options = window.__x86MobileOptions || {};
  if (window.__x86MobileV2) { window.__x86MobileV2.configure(options); return true; }
  var native = window.__x86MobileV2 = {inFlight: 0, uncertainWrite: false};
  var originalApi = api, originalSearch = searchProducts, originalGo = go;
  var scanDialog = null;
  var originalScanner = typeof openScanner === 'function' ? openScanner : null;
  function dirty(field) {
    if (field.tagName === 'SELECT') {
      var explicit = Array.from(field.options).some(function(o){return o.defaultSelected;});
      return Array.from(field.options).some(function(o,i){return o.selected !== (o.defaultSelected || (!explicit && !field.multiple && i === 0));});
    }
    return field.value !== field.defaultValue || field.checked !== field.defaultChecked;
  }
  function cancelScan() { if(scanDialog) { scanDialog.cancel(); scanDialog=null; } }
  if (originalScanner) openScanner = function(callback) {
    cancelScan();
    if (!options.scanConfirm && !options.scannerFeedback) return originalScanner.apply(this,arguments);
    var consumed=false;
    return originalScanner.call(this,async function(code){
      if(consumed)return;consumed=true;
      if(options.scanConfirm){
        var accepted=await new Promise(function(resolve){
          var box=document.createElement('div');box.id='x86-scan-confirm';box.className='x86-item-menu';box.setAttribute('role','dialog');
          var title=document.createElement('p');title.textContent='Подтвердить код: '+String(code);box.append(title);
          function finish(value){box.remove();scanDialog=null;resolve(value);}
          [['x86-scan-accept','Добавить',true],['x86-scan-cancel','Отмена',false]].forEach(function(item){
            var b=document.createElement('button');b.id=item[0];b.textContent=item[1];b.onclick=function(){finish(item[2]);};box.append(b);
          });
          scanDialog={cancel:function(){finish(false);}};document.body.append(box);
        });
        if(!accepted)return;
      }
      var result=await callback(code);
      if(options.scannerFeedback&&window.X86Status)try{X86Status.postMessage(JSON.stringify({version:1,type:'scan'}));}catch(ignored){}
      return result;
    });
  };
  var latestSearch = 0, scroll = Object.create(null), menu = null, lastCard = null, muteUntil = 0;
  var writeActions = new Set(['sale','receive','adjust','product_save','pos_payment_start','pos_retry_finalize','ai_apply']);
  var byId = function (id) { return document.getElementById(id); };
  var activePage = function () { var page = document.querySelector('.page.active'); return page ? page.dataset.page : ''; };
  var visible = function (element) { return !!element && !element.classList.contains('hidden') && getComputedStyle(element).display !== 'none'; };
  function banner(text) {
    var box = byId('x86-operation-state');
    if (!box) { box = document.createElement('div'); box.id = 'x86-operation-state'; box.setAttribute('role','status'); byId('app').prepend(box); }
    box.textContent = text;
  }
  api = async function (action, request) {
    native.inFlight++;
    try {
      var result = await originalApi.call(this, action, request);
      if (options.notifications && result && result.ok === true && result.result) {
        var reference = action === 'sale' ? result.result.sale_id : action === 'receive' ? result.result.purchase_id : 0;
        if (Number.isSafeInteger(Number(reference)) && Number(reference) > 0 && window.X86Status) {
          // A confirmed server result only. Bridge delivery must never fail the original sale flow.
          try { X86Status.postMessage(JSON.stringify({version:1,type:'operation',action:action,reference:Number(reference)})); } catch (ignored) {}
        }
      }
      return result;
    } catch (error) {
      if (writeActions.has(action)) {
        native.uncertainWrite = true;
        banner('Результат операции не подтверждён. Проверьте его на сервере перед повторной отправкой.');
      }
      throw error;
    } finally { native.inFlight--; }
  };
  function renderProducts(items) {
    var list = byId('product-list');
    if (!items.length) { list.textContent = 'Ничего не найдено.'; return; }
    var grid = document.createElement('div'); grid.className = 'product-list-grid';
    items.forEach(function (product) {
      var card = document.createElement('div'); card.className = 'product-list-card clickable';
      card.dataset.product = String(product.product_id); card.tabIndex = 0;
      var title = document.createElement('div'); title.className = 'product-card-title'; title.textContent = product.name || '';
      var details = document.createElement('div'); details.className = 'product-card-meta';
      details.textContent = (product.model || '') + ' · ' + stockText(product);
      var code = document.createElement('div'); code.className = 'product-card-code'; code.textContent = product.barcode || ('X86-' + product.product_id);
      var price = document.createElement('div'); price.className = 'product-card-price'; price.textContent = money(product.price);
      var partner = document.createElement('div'); partner.className = 'partner-price'; partner.textContent = 'Партнёр ' + money(product.partner_price || product.price);
      card.append(title,details,code,price,partner);
      card.onclick = function () { openProduct(product.product_id); };
      card.onkeydown = function (event) { if (event.key === 'Enter') { event.preventDefault(); openProduct(product.product_id); } };
      grid.append(card);
    });
    list.replaceChildren(grid);
  }
  searchProducts = async function () {
    if (!options.liveSearch) return originalSearch.apply(this, arguments);
    var query = byId('product-search').value.trim(), ticket = ++latestSearch;
    byId('product-list').setAttribute('aria-busy','true');
    try {
      var result = await api('search',{query:{q:query}});
      if (!options.liveSearch || ticket !== latestSearch || byId('product-search').value.trim() !== query) return;
      if (!result || !Array.isArray(result.items)) throw new Error('Некорректный ответ поиска');
      renderProducts(result.items);
    } catch (error) {
      if (ticket === latestSearch && options.liveSearch) toast(error.message);
    } finally {
      if (ticket === latestSearch) byId('product-list').setAttribute('aria-busy','false');
    }
  };
  go = function (page) {
    cancelScan();
    if (options.navigation) scroll[activePage()] = window.scrollY;
    var result = originalGo.apply(this, arguments);
    if (options.navigation) {
      var target = activePage(), position = scroll[target] || 0;
      requestAnimationFrame(function () { if (activePage() === target) window.scrollTo(0,position); });
    }
    return result;
  };
  native.safeRefresh = function () {
    if (!options.safeRefresh || !visible(byId('app')) || activePage() !== 'home') return 'busy';
    if (scanDialog || native.inFlight || native.uncertainWrite || state.cart.length || state.labelQueue.length || state.stream ||
        state.receiveProduct || state.aiPlan || (state.posJob && !['success','fail'].includes(state.posJob.status))) return 'busy';
    if (Array.from(document.querySelectorAll('.modal')).some(visible)) return 'busy';
    if (window.scrollY > 0) return 'busy';
    var focused = document.activeElement;
    if (focused && focused.matches('input,textarea,select,[contenteditable]')) return 'busy';
    var fields = document.querySelectorAll('#app input,#app textarea,#app select');
    for (var i=0;i<fields.length;i++) {
      var field=fields[i];
      if (dirty(field)) return 'busy';
    }
    return 'safe';
  };
  native.back = function () {
    if(scanDialog){cancelScan();return 'closed';}
    if(menu){closeMenu();return 'closed';}
    if (visible(byId('scanner'))) { byId('scanner-close').click(); return 'closed'; }
    if (visible(byId('pos-payment-modal'))) return 'blocked';
    if (visible(byId('product-modal'))) {
      var changed = Array.from(byId('product-modal').querySelectorAll('input,textarea,select')).some(dirty);
      if (changed) return 'blocked'; byId('product-close').click(); return 'closed';
    }
    if (Array.from(document.querySelectorAll('.modal')).some(visible)) return 'blocked';
    if (state.role === 'cashier' && activePage() !== 'sale') { go('sale'); return 'closed'; }
    if (state.role !== 'cashier' && activePage() !== 'home') { go('home'); return 'closed'; }
    if (state.cart.length || state.labelQueue.length || state.receiveProduct || state.aiPlan || native.inFlight || native.uncertainWrite) return 'blocked';
    return 'home';
  };
  function closeMenu() { if (menu) { menu.remove(); menu=null; } }
  function openMenu(card) {
    if (!options.itemActions || !card) return;
    var id = Number(card.dataset.product || card.dataset.openProduct);
    if (!Number.isSafeInteger(id) || id <= 0) return;
    closeMenu();lastCard=card;muteUntil=Date.now()+500;
    menu=document.createElement('div');menu.className='x86-item-menu';menu.setAttribute('role','dialog');menu.setAttribute('aria-label','Действия с товаром');
    function action(label, run) { var button=document.createElement('button');button.type='button';button.textContent=label;
      button.onclick=function(){closeMenu();run();};menu.append(button); }
    action('Карточка / в продажу',function(){openProduct(id);});
    if (state.role !== 'cashier') {
      action('Изменить / история',function(){openProduct(id);});
      action('Подготовить PDF этикетки',async function(){await openProduct(id);var button=byId('pm-label');if(button)button.scrollIntoView({block:'center'});});
    }
    action('Скопировать код',async function(){var code=card.querySelector('.product-card-code');
      if(!code || !navigator.clipboard) return toast('Копирование недоступно');
      try{await navigator.clipboard.writeText(code.textContent);toast('Код скопирован');}catch(error){toast('Не удалось скопировать код');}});
    action('Закрыть',function(){});document.body.append(menu);menu.querySelector('button').focus();
  }
  var pointer=null, timer=null;
  document.addEventListener('pointerdown',function(event){
    if ((!options.itemActions && !options.gestures) || event.target.closest('input,textarea,select,button,a,[contenteditable]')) return;
    var card=event.target.closest('[data-product],[data-open-product]');if(!card)return;
    pointer={card:card,x:event.clientX,y:event.clientY,id:event.pointerId};
    if(options.itemActions)timer=setTimeout(function(){if(pointer)openMenu(pointer.card);pointer=null;},550);
  });
  document.addEventListener('pointermove',function(event){if(!pointer)return;
    if(Math.abs(event.clientY-pointer.y)>16 || Math.abs(event.clientX-pointer.x)>16){clearTimeout(timer);timer=null;}
    if(Math.abs(event.clientY-pointer.y)>24)pointer=null;
  },{passive:true});
  document.addEventListener('pointerup',function(event){clearTimeout(timer);timer=null;
    if(pointer&&options.gestures&&Math.abs(event.clientX-pointer.x)>80&&Math.abs(event.clientY-pointer.y)<24){openMenu(pointer.card);event.preventDefault();}pointer=null;});
  document.addEventListener('pointercancel',function(){clearTimeout(timer);pointer=null;});
  document.addEventListener('click',function(event){if(lastCard&&Date.now()<muteUntil&&lastCard.contains(event.target)){event.preventDefault();event.stopImmediatePropagation();}},true);
  document.addEventListener('keydown',function(event){if(event.key==='Escape')closeMenu();});
  native.configure = function (next) {
    cancelScan(); options=next||{}; latestSearch++;
    document.body.classList.toggle('x86-mobile-nav',!!options.navigation);
    document.body.classList.toggle('x86-mobile-cashier',!!options.cashier);
    closeMenu(); var row=byId('x86-cashier-steps');if(row)row.remove();
    if(options.cashier){
      row=document.createElement('div');row.id='x86-cashier-steps';row.className='x86-cashier-steps';
      [['Сканировать','sale-scan'],['Корзина','cart'],['Оплата','payment'],['Подтверждение','checkout']].forEach(function(step){
        var button=document.createElement('button');button.type='button';button.textContent=step[0];button.onclick=function(){
          go('sale');if(step[1]==='sale-scan')byId(step[1]).click();else byId(step[1]).scrollIntoView({block:'center'});
        };row.append(button);
      });byId('cart').parentNode.insertBefore(row,byId('cart'));
    }
  };
  native.configure(options);
  return true;
})()
