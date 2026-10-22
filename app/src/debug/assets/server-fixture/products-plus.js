(function(){
'use strict';
const $=s=>document.querySelector(s);
const $$=s=>Array.from(document.querySelectorAll(s));
function runSearch(value){
  const input=$('#product-search'), btn=$('#product-search-btn');
  if(!input||!btn)return;
  input.value=value||'';
  input.dispatchEvent(new Event('input',{bubbles:true}));
  setTimeout(()=>btn.click(),30);
}
function bind(){
  const strip=$('#product-category-strip');
  if(!strip)return;
  strip.addEventListener('click',e=>{
    const b=e.target.closest('[data-product-category]'); if(!b)return;
    $$('#product-category-strip [data-product-category]').forEach(x=>x.classList.toggle('active',x===b));
    runSearch(b.dataset.productCategory||'');
  });
  const input=$('#product-search');
  if(input)input.addEventListener('input',()=>{
    const val=input.value.trim();
    if(!val){const all=strip.querySelector('[data-product-category=""]'); if(all)$$('#product-category-strip [data-product-category]').forEach(x=>x.classList.toggle('active',x===all));}
    else $$('#product-category-strip [data-product-category]').forEach(x=>{if((x.dataset.productCategory||'')!==val)x.classList.remove('active');});
  });
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind);else bind();
})();
