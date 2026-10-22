(function(){
'use strict';
const $=s=>document.querySelector(s);
function open(){const m=$('#label-settings-modal');if(m)m.classList.remove('hidden');}
function close(){const m=$('#label-settings-modal');if(m)m.classList.add('hidden');}
function bind(){
  $('#label-settings-open')?.addEventListener('click',open);
  $('#label-settings-close')?.addEventListener('click',close);
  $('#label-settings-done')?.addEventListener('click',close);
  $('#label-settings-modal')?.addEventListener('click',e=>{if(e.target.id==='label-settings-modal')close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('#label-settings-modal')?.classList.contains('hidden'))close();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',bind);else bind();
})();
