'use strict';
const $=id=>document.getElementById(id);const amount=$('amount');if(amount){const calc=()=>{const hours=Math.min(2000,Math.max(0,Number($('hours').value)||0));const n=Math.min(100,Math.max(0,Math.floor(Number($('num').value)||0)));amount.textContent=(hours*n*Number(amount.dataset.rate)).toLocaleString('es-ES',{style:'currency',currency:'EUR'});};$('hours').addEventListener('input',calc);$('num').addEventListener('input',calc);calc();}
const cs=$('course-switch');if(cs)cs.addEventListener('change',()=>{location.href='index.php?'+new URLSearchParams({v:cs.dataset.view,c:cs.value});});
const search=$('search'),status=$('status-filter');
const municipality=$('municipality-filter'),siteSearch=$('site-search');
function directoryNorm(s){return String(s??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();}
function directorySiteMatch(row){
 if(!municipality&&!siteSearch)return true;
 const m=municipality?.value??'',q=directoryNorm(siteSearch?.value??'');if(!m&&!q)return true;
 let loc=[];try{loc=JSON.parse(row.dataset.locations||'[]');}catch{return false;}
 return loc.some(s=>(!m||(m==='__unknown'?!s.municipality:s.municipality===m))&&(!q||directoryNorm([s.name,s.detail,s.municipality].join(' ')).includes(q)));
}
if(search||status||municipality||siteSearch){
 const rows=[...document.querySelectorAll('#directory tbody tr, #teacher-directory > [data-locations]')];
 const filter=()=>{let n=0;for(const row of rows){row.hidden=!(directoryNorm(row.textContent).includes(directoryNorm(search?.value??''))&&(!status?.value||row.dataset.status===status.value)&&directorySiteMatch(row));if(!row.hidden)n++;}
 const count=$('directory-count');if(count)count.textContent=n+' de '+rows.length+' colaboraciones';};
 for(const el of [search,status,municipality,siteSearch])if(el)el.addEventListener(el.tagName==='SELECT'?'change':'input',filter);filter();
}
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

// Individual record directory filters.
{const table=document.getElementById('record-list');if(table){const q=document.getElementById('record-search'),g=document.getElementById('record-group'),st=document.getElementById('record-state'),count=document.getElementById('record-count');const rows=[...table.querySelectorAll('tbody tr')];const run=()=>{let n=0;for(const row of rows){row.hidden=!(directoryNorm(row.textContent).includes(directoryNorm(q?.value??''))&&(!g?.value||row.dataset.group===g.value)&&(!st?.value||row.dataset.state===st.value));if(!row.hidden)n++;}if(count)count.textContent=n+' de '+rows.length+' registros';};for(const el of [q,g,st])if(el)el.addEventListener(el.tagName==='SELECT'?'change':'input',run);run();}}
