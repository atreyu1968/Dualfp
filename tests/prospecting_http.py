import json,re,shutil,socket,subprocess,sys,tempfile,time
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
    work=Path(td)/'app';shutil.copytree(root,work);data=Path(td)/'data';data.mkdir();shutil.copy(work/'tests/fixture.json',data/'seed.json')
    pw='Interest-test-password-2026';ph=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',pw],text=True)
    (work/'config.php').write_text("<?php return ['data_dir'=>"+repr(str(data))+",'admin_email'=>'admin@example.org','admin_password_hash'=>"+repr(ph)+",'public_url'=>'https://example.org','https_required'=>false,'mail_mode'=>'test'];")
    with open(Path(td)/'server.log','w+') as log:
        srv=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=log,stderr=log);time.sleep(.4)
        try:
            base=f'http://127.0.0.1:{port}/index.php';a=requests.Session();p=requests.Session();c='2026-2027';g='g1'
            def db():return json.loads((data/'data.json').read_text())
            def get(s,v,**kw):return s.get(base,params={'v':v,**kw},timeout=5)
            def post(s,action,expect=200,**kw):
                csrf=BeautifulSoup(get(s,'login').text,'html.parser').select_one('input[name=csrf]')['value'];r=s.post(base,data={'action':action,'csrf':csrf,**kw},timeout=5);check(r.status_code==expect,action+' HTTP '+str(expect));return r
            def submit(mail,name,yes=True):
                post(p,'interest',c=c,group=g,name=name,zone='Zona',sector='Servicios',contact='Responsable',email=mail,phone='',requested=2,notes='',consent='yes',campaign_optin='yes' if yes else 'no')
                rid=next(k for k,v in db()['interest_requests'].items() if v['email']==mail);msg=next(x for x in reversed([json.loads(l) for l in (data/'mail-test.jsonl').read_text().splitlines()]) if x['to']==mail);return rid,re.search(r'[?&]t=([a-f0-9]+)',msg['body']).group(1)
            check(get(p,'interests',c=c).status_code==403,'solicitudes privadas')
            check(get(p,'first_contacts',c=c).status_code==403,'primer contacto privado')
            check('favicon.svg?v=39' in get(p,'landing').text,'favicon declarado')
            check(p.get(base.replace('index.php','favicon.svg'),timeout=5).status_code==200,'favicon servido')
            post(a,'login',email='admin@example.org',password=pw)
            post(a,'settings',center='Centro prueba',contact='admin@example.org',tutor='Tutor',privacy='Aviso ficticio',reference='2025/2026',boc='',external='',active=c,rate='3.5',public='yes')
            n=len(db()['companies']);rid,vt=submit('interest@example.org','Interés prueba')
            check(len(db()['companies'])==n,'solicitud no crea empresa')
            check(db()['interest_requests'][rid]['token']!=vt,'token solo almacenado como hash')
            post(a,'pc_approve',expect=400,id=rid,kind='Empresa')
            check(get(p,'interest_verify',t=vt).status_code==200 and not db()['interest_requests'][rid]['verified_at'],'GET no verifica')
            post(p,'pc_verify',token=vt,campaign_optin='yes')
            post(p,'pc_verify',expect=403,token=vt,campaign_optin='yes')
            post(p,'pc_approve',expect=403,id=rid,kind='Empresa')
            post(a,'pc_approve',id=rid,existing='',kind='Empresa')
            eid=next(k for k,v in db()['companies'].items() if v['emails']=='interest@example.org')
            check(db()['campaign_permissions'][eid]['interest@example.org']['allowed'],'permiso automático')
            check(db()['interest_evidence'][rid]['verified_at'] and db()['interest_evidence'][rid]['text'],'evidencia y fecha conservadas')
            check(db()['links'][c][eid]['confirmed']==0,'no confirma plazas')
            check(not any(x['email']=='interest@example.org' for x in db()['accounts'].values()),'no crea cuenta')
            check('interest@example.org' in get(a,'interests',c=c).text,'evidencia consultable')
            rid,vt=submit('without@example.org','Interés sin permiso')
            post(p,'pc_verify',token=vt);post(a,'pc_approve',id=rid,existing='',kind='Empresa')
            eid2=next(k for k,v in db()['companies'].items() if v['emails']=='without@example.org')
            check('without@example.org' not in db()['campaign_permissions'].get(eid2,{}),'sin confirmación expresa no hay permiso')
            post(a,'camp_suppress',c=c,email='interest@example.org')
            rid,vt=submit('interest@example.org','Interés prueba')
            post(p,'pc_verify',token=vt,campaign_optin='yes');post(a,'pc_approve',id=rid,existing=eid,kind='Empresa')
            check('interest@example.org' in db()['campaign_optouts'],'baja global respetada')
            check('mailto:interest@example.org' not in get(a,'company',c=c,e=eid).text,'no ofrece contacto dado de baja')
            check('Primer contacto institucional individual' in get(a,'company',c=c,e=eid2).text,'canal institucional en ficha')
            check('No contactar' in get(a,'first_contacts',c=c).text,'lista de prospección')
            post(a,'pc_contact',expect=410,c=c,e=eid2,email='without@example.org',confirm='yes')
            post(a,'pc_smtp_settings',expect=410,current_password=pw)
            post(a,'pc_smtp_test',expect=410)
            log.seek(0);check(not any(x in log.read() for x in ('PHP Fatal error','PHP Warning','PHP Parse error')),'sin errores PHP')
        finally:srv.terminate();srv.wait(timeout=5)
print('TOTAL',count)
