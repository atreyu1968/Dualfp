'use strict';
const $=id=>document.getElementById(id);const amount=$('amount');if(amount){const calc=()=>{const hours=Math.min(2000,Math.max(0,Number($('hours').value)||0));const n=Math.min(100,Math.max(0,Math.floor(Number($('num').value)||0)));amount.textContent=(hours*n*Number(amount.dataset.rate)).toLocaleString('es-ES',{style:'currency',currency:'EUR'});};$('hours').addEventListener('input',calc);$('num').addEventListener('input',calc);calc();}
const cs=$('course-switch');if(cs)cs.addEventListener('change',()=>{location.href='index.php?'+new URLSearchParams({v:cs.dataset.view,c:cs.value});});
const search=$('search'),status=$('status-filter');if(search&&status){const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();const filter=()=>{for(const tr of document.querySelectorAll('#directory tbody tr'))tr.hidden=!(norm(tr.textContent).includes(norm(search.value))&&(!status.value||tr.dataset.status===status.value));};search.addEventListener('input',filter);status.addEventListener('change',filter);}
const menu=document.getElementById('menu-toggle');if(menu){menu.addEventListener('click',()=>document.body.classList.toggle('nav-open'));document.addEventListener('keydown',e=>{if(e.key==='Escape')document.body.classList.remove('nav-open')});document.querySelector('.shell')?.addEventListener('click',e=>{if(e.target!==menu&&document.body.classList.contains('nav-open'))document.body.classList.remove('nav-open')});}

/* Preparación local de correo: no realiza envíos. */
const mcTemplate=$('mc-template');
if(mcTemplate){
 const rows=[...document.querySelectorAll('#mc-table tbody tr')], checks=()=>rows.map(r=>r.querySelector('input[type=checkbox]'));
 const normalize=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 const tally=()=>{$('mc-count').textContent=checks().filter(c=>c.checked&&!c.disabled).length+' seleccionadas';};
 const apply=()=>{const kind=mcTemplate.selectedOptions[0].dataset.kind;
 for(const r of rows){const ok=r.dataset.kind===kind&&(!$('mc-zone').value||r.dataset.zone===$('mc-zone').value)&&(!$('mc-sector').value||r.dataset.sector===$('mc-sector').value)&&(!$('mc-group').value||r.dataset.group===$('mc-group').value)&&(!$('mc-pending').checked||r.dataset.pending==='yes')&&normalize(r.textContent).includes(normalize($('mc-search').value));r.hidden=!ok;const c=r.querySelector('input[type=checkbox]');c.disabled=!ok;if(!ok)c.checked=false;}tally();};
 for(const id of ['mc-template','mc-zone','mc-sector','mc-group','mc-pending'])$(id).addEventListener('change',apply);
 $('mc-search').addEventListener('input',apply);
 $('mc-select').addEventListener('click',()=>{for(const c of checks())if(!c.disabled)c.checked=true;tally();});
 $('mc-reset').addEventListener('click',()=>{for(const c of checks())c.checked=false;tally();});
 for(const c of checks())c.addEventListener('change',tally);apply();
}
for(const btn of document.querySelectorAll('[data-copy]'))btn.addEventListener('click',async()=>{
 const el=$(btn.dataset.copy),status=$('mc-copy-status');if(!el)return;
 try{await navigator.clipboard.writeText(el.value);if(status)status.textContent='Copiado al portapapeles.';}
 catch{el.focus();el.select();if(status)status.textContent='Texto seleccionado. Pulse Ctrl+C para copiar.';}
});
