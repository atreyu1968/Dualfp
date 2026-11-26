import json, re, shutil, socket, subprocess, sys, tempfile, time
from pathlib import Path
import requests
from bs4 import BeautifulSoup
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
assert not Path('/etc/fpdual/config.php').exists(),'Solo entorno de prueba aislado'
count=0
def check(ok,msg):
 global count
 assert ok,msg
 count+=1;print('OK',msg,flush=True)
with socket.socket() as sock:
 sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
with tempfile.TemporaryDirectory() as td:
 work=Path(td)/'app';shutil.copytree(root,work);data=Path(td)/'data';data.mkdir();(data/'seed.json').write_text('[]')
 pw='Test-Password-2026!';ph=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',pw],text=True)
 def config(mode):
  (work/'config.php').write_text("<?php return ['data_dir'=>"+repr(str(data))+",'admin_email'=>'admin@example.org','admin_password_hash'=>"+repr(ph)+",'public_url'=>'https://example.org','https_required'=>false,'mail_mode'=>"+repr(mode)+"];")
 config('resend')
 with open(Path(td)/'server.log','w+') as log:
  srv=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=log,stderr=log);time.sleep(.4)
  try:
   base=f'http://127.0.0.1:{port}/index.php';a=requests.Session();p=requests.Session();c='2026-2027';g='g1'
   def db():return json.loads((data/'data.json').read_text())
   def get(s,v,**kw):return s.get(base,params={'v':v,**kw},timeout=5)
   def post(s,action,expect=200,**kw):
    csrf=BeautifulSoup(get(s,'login').text,'html.parser').select_one('input[name=csrf]')['value'];r=s.post(base,data={'action':action,'csrf':csrf,**kw},timeout=5);check(r.status_code==expect,action+' HTTP '+str(expect));return r
   def settings(privacy,mode='auto',reg='yes',public='yes'):
    return post(a,'settings',center='Centro prueba',contact='admin@example.org',tutor='Tutor',privacy=privacy,reference='2025/2026',boc='',external='',active=c,rate='3.5',public=public,registration=reg,interest_mode=mode)
   def submit(mail,name,expect=200):
    return post(p,'interest',expect=expect,c=c,group=g,name=name,zone='Zona',sector='Servicios',contact='Responsable',email=mail,phone='',requested=2,notes='Consulta',consent='yes',campaign_optin='yes')
   post(a,'login',email='admin@example.org',password=pw)
   settings('');check('Introduzca el aviso de protección de datos' in get(a,'settings').text,'diagnóstico de privacidad ausente')
   check('Formulario temporalmente no habilitado' in get(p,'landing').text,'sin privacidad no se publica')
   submit('manual@example.org','Manual prueba',403)
   settings('Aviso ficticio de prueba');check(db()['settings']['public'] and not db()['settings']['registration'],'publicación independiente del registro de cuentas')
   page=get(p,'landing').text;check('Enviar manifestación de interés' in page and 'Esta solicitud no autoriza campañas' in page,'formulario visible sin Resend')
   check('name="campaign_optin"' not in page,'sin casilla de campañas en modo manual')
   submit('manual@example.org','Manual prueba');rid=next(iter(db()['interest_requests']));r=db()['interest_requests'][rid]
   check(r['manual_review'] and not r['verified_at'] and not r['optin'] and not r['token'],'solicitud manual no simula verificación ni consentimiento')
   check(not (data/'mail-test.jsonl').exists() and not db()['companies'],'sin correo ni ficha creada')
   check('Pendiente de revisión manual' in get(a,'interests',c=c).text,'visible en bandeja administrativa')
   post(a,'pc_approve',expect=400,id=rid,kind='Empresa');post(p,'pc_manual_review',expect=403,id=rid,confirmed='yes',review_note='Ficticio')
   post(a,'pc_manual_review',expect=400,id=rid,confirmed='no',review_note='Ficticio')
   post(a,'pc_manual_review',id=rid,confirmed='yes',review_note='Contacto telefónico ficticio para prueba')
   check(db()['interest_requests'][rid]['reviewed_at'] and not db()['interest_requests'][rid]['verified_at'],'revisión manual separada de verificación')
   post(a,'pc_approve',id=rid,existing='',kind='Empresa');d=db();eid=next(iter(d['companies']))
   check(d['links'][c][eid]['confirmed']==0 and not d['accounts'] and not d['campaign_permissions'],'aprobación sin cuentas plazas ni permisos')
   check(d['interest_evidence'][rid]['reviewed_at'] and not d['interest_evidence'][rid]['optin'],'evidencia manual sin consentimiento de campañas')
   config('test');settings('Aviso ficticio','manual',reg='no');submit('manual2@example.org','Manual segundo');check(not (data/'mail-test.jsonl').exists(),'manual explícito no envía aunque correo disponible')
   settings('Aviso ficticio','auto',reg='no');check('name="campaign_optin"' in get(p,'landing').text,'automático mantiene opción voluntaria')
   submit('auto@example.org','Auto prueba');d=db();rid=next(k for k,v in d['interest_requests'].items() if v['email']=='auto@example.org');msg=json.loads((data/'mail-test.jsonl').read_text().splitlines()[-1]);token=re.search(r'[?&]t=([a-f0-9]+)',msg['body']).group(1)
   check(d['interest_requests'][rid]['token']!=token and not d['interest_requests'][rid]['manual_review'],'token hash y flujo automático')
   post(p,'pc_verify',token=token,campaign_optin='yes');post(a,'pc_approve',id=rid,kind='Empresa',existing='');d=db();eid=next(k for k,v in d['companies'].items() if v['emails']=='auto@example.org');check(d['campaign_permissions'][eid]['auto@example.org']['allowed'],'doble confirmación automática conserva permiso')
   settings('Aviso ficticio',public='no',reg='no');check('Formulario temporalmente no habilitado' in get(p,'landing').text,'desactivar formulario')
   log.seek(0);errors=log.read();check(not any(x in errors for x in ('PHP Fatal error','PHP Warning','PHP Parse error')),'sin errores PHP')
  finally:srv.terminate();srv.wait(timeout=5)
print('TOTAL',count)
