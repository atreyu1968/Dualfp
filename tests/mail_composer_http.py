import json, shutil, socket, subprocess, sys, tempfile, time
from pathlib import Path
import requests
from bs4 import BeautifulSoup
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
assert not Path('/etc/fpdual/config.php').exists(),'Ejecute solo en entorno de prueba aislado'
count=0
def check(ok,msg):
 global count
 assert ok,msg
 count+=1;print('OK',msg,flush=True)
with socket.socket() as sock:
 sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
with tempfile.TemporaryDirectory() as td:
 work=Path(td)/'app';shutil.copytree(root,work);data=Path(td)/'data';data.mkdir();rows=[]
 for id,mail,kind,status in [('T1','one@example.org','Empresa','Identificada'),('T2','one@example.org','Empresa','Identificada'),('T3','three@example.org','Empresa','Identificada'),('T4','association@example.org','Asociación','Identificada'),('T5','rejected@example.org','Empresa','Rechazada'),('T6','optout@example.org','Empresa','Identificada')]:
  rows.append(dict(id=id,name='Entidad '+id,zone='Zona',sector='Servicios',kind=kind,emails=mail,phone='',contact='',status=status,requested=0,confirmed=0,notes='',next='',history=[]))
 (data/'seed.json').write_text(json.dumps(rows))
 pw='Test-Password-2026!';ph=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',pw],text=True)
 (work/'config.php').write_text("<?php return ['data_dir'=>"+repr(str(data))+",'admin_email'=>'admin@example.org','admin_password_hash'=>"+repr(ph)+",'public_url'=>'https://example.org','https_required'=>false,'mail_mode'=>'test'];")
 with open(Path(td)/'server.log','w+') as log:
  srv=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=log,stderr=log);time.sleep(.4)
  try:
   base=f'http://127.0.0.1:{port}/index.php';a=requests.Session();p=requests.Session();c='2026-2027'
   def db():return json.loads((data/'data.json').read_text())
   def get(s,v,**kw):return s.get(base,params={'v':v,**kw},timeout=5)
   def post(s,action,expect=200,**kw):
    csrf=BeautifulSoup(get(s,'login').text,'html.parser').select_one('input[name=csrf]')['value'];r=s.post(base,data={'action':action,'csrf':csrf,**kw},timeout=5);check(r.status_code==expect,action+' HTTP '+str(expect));return r
   check(get(p,'mail_preparer',c=c).status_code==403,'preparador privado')
   post(p,'mc_prepare',expect=403,c=c,template='empresa',**{'selected[]':'T1'})
   post(a,'login',email='admin@example.org',password=pw)
   post(a,'camp_suppress',c=c,email='optout@example.org')
   page=get(a,'mail_preparer',c=c).text;check('one@example.org' in page and 'rejected@example.org' not in page and 'optout@example.org' not in page,'excluye bajas y rechazadas')
   post(a,'mc_prepare',expect=400,c=c,template='empresa',**{'selected[]':['T4']})
   post(a,'mc_prepare',expect=400,c=c,template='empresa',**{'selected[]':['T5']})
   post(a,'mc_prepare',expect=400,c=c,template='empresa',**{'selected[]':['T6']})
   r=post(a,'mc_prepare',c=c,template='empresa',**{'selected[]':['T1','T2','T3']});s=BeautifulSoup(r.text,'html.parser');batch=s.select_one('input[name=batch]')['value'];check(s.select_one('#mc-addresses').text=='one@example.org; three@example.org','deduplicación por dirección')
   check(not db()['links'][c]['T1']['history'],'preparar no registra envío')
   check(s.select_one('#mc-body').text.find('https://example.org')>=0,'plantilla incluye enlace público')
   check(not s.select('input[name="sent[]"][checked]'),'registro sin preseleccionar')
   r=post(a,'mc_export',c=c,batch=batch,format='txt');check(r.text.strip()=='one@example.org;\nthree@example.org','TXT correcto')
   r=post(a,'mc_export',c=c,batch=batch,format='csv');check('one@example.org' in r.text and 'three@example.org' in r.text and 'association@example.org' not in r.text,'CSV correcto')
   post(a,'mc_record',expect=400,c=c,batch=batch,subject='Prueba',body='Texto',**{'sent[]':'T1'})
   post(a,'mc_record',expect=400,c=c,batch=batch,subject='Prueba',body='Texto',confirm='yes',**{'sent[]':'T4'})
   post(a,'mc_record',c=c,batch=batch,subject='Prueba',body='Texto',next='2099-01-01',confirm='yes',**{'sent[]':'T1'})
   d=db();check(d['links'][c]['T1']['status']=='Contactada' and d['links'][c]['T1']['next']=='2099-01-01','actualiza estado y agenda')
   check(len(d['links'][c]['T1']['history'])==1 and not d['links'][c]['T3']['history'],'solo registra envío declarado')
   check(not d['campaign_permissions'] and not (data/'mail-test.jsonl').exists(),'no envía ni autoriza campañas')
   post(a,'mc_record',expect=400,c=c,batch=batch,subject='Prueba',body='Texto',confirm='yes',**{'sent[]':'T1'})
   r=post(a,'mc_prepare',c=c,template='empresa',**{'selected[]':'T3'});batch=BeautifulSoup(r.text,'html.parser').select_one('input[name=batch]')['value']
   post(a,'camp_suppress',c=c,email='three@example.org');post(a,'mc_export',expect=409,c=c,batch=batch,format='txt')
   post(a,'pc_contact',expect=410,c=c,e='T1',email='one@example.org',confirm='yes');post(a,'pc_smtp_settings',expect=410);post(a,'pc_smtp_test',expect=410)
   post(a,'settings',center='Centro prueba',contact='admin@example.org',tutor='Tutor',privacy='Aviso ficticio',reference='2025/2026',boc='',external='https://empleo.example.org',active=c,rate='3.5',public='yes')
   landing=get(p,'landing').text;check('personal titulado' in landing and 'portal de empleo especializado' in landing and 'no a la gestión de prácticas ni FEOE' in landing,'portal de empleo diferenciado')
   check('public-hero' in landing and 'public-proof' in landing and 'favicon.svg' in landing,'portada y favicon')
   check(requests.get(base.replace('index.php','mail_composer.php'),timeout=5).status_code==404,'módulo no ejecutable directamente')
   log.seek(0);check(not any(x in log.read() for x in ('PHP Fatal error','PHP Warning','PHP Parse error')),'sin errores PHP')
  finally:srv.terminate();srv.wait(timeout=5)
print('TOTAL',count)
