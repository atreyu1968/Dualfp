import base64, hashlib, hmac, json, shutil, socket, subprocess, sys, tempfile, time
from pathlib import Path
import requests
from bs4 import BeautifulSoup
root = Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
assert not Path('/etc/fpdual/config.php').exists(), 'Ejecutar solo en entorno de prueba aislado'
count=0
def check(ok,msg):
    global count
    assert ok,msg
    count+=1; print('OK',msg,flush=True)
with socket.socket() as sock:
    sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
with tempfile.TemporaryDirectory() as td:
    work=Path(td)/'app';shutil.copytree(root,work);data=Path(td)/'data';data.mkdir();shutil.copy(work/'tests/fixture.json',data/'seed.json')
    pw='Inbox-test-password-2026';ph=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',pw],text=True)
    (work/'config.php').write_text("<?php return ['data_dir'=>"+repr(str(data))+",'admin_email'=>'admin@example.org','admin_password_hash'=>"+repr(ph)+",'public_url'=>'https://example.org','https_required'=>false,'mail_mode'=>'test'];")
    with open(Path(td)/'server.log','w+') as log:
        srv=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(work)],stdout=log,stderr=log);time.sleep(.4)
        try:
            base=f'http://127.0.0.1:{port}/index.php';webhook=base+'?v=inbound_webhook';s=requests.Session();key=b'a'*32;secret='whsec_'+base64.b64encode(key).decode();eid='12345678-abcd-4321-aaaa-123456789abc';address='dual@example.org'
            def db():return json.loads((data/'data.json').read_text())
            def post(session,action,**kw):
                page=session.get(base+'?v=login',timeout=5);csrf=BeautifulSoup(page.text,'html.parser').select_one('input[name=csrf]')['value'];return session.post(base,data={'action':action,'csrf':csrf,**kw},timeout=5)
            def send(payload,valid=True,stamp=None):
                raw=json.dumps(payload).encode();ts=str(stamp or int(time.time()));sid='msg_test';sig=base64.b64encode(hmac.new(key,(sid+'.'+ts+'.').encode()+raw,hashlib.sha256).digest()).decode() if valid else 'invalid';return requests.post(webhook,data=raw,headers={'Content-Type':'application/json','svix-id':sid,'svix-timestamp':ts,'svix-signature':'v1,'+sig},timeout=5)
            check(s.get(base+'?v=inbox',timeout=5).status_code==403,'buzón requiere administrador')
            event={'type':'email.received','data':{'email_id':eid,'from':'Empresa <contact@example.org>','to':[address],'subject':'<script>alert(1)</script>'}}
            check(send(event).status_code==503,'recepción deshabilitada por defecto')
            check(post(s,'login',email='admin@example.org',password=pw).status_code==200,'sesión administrador')
            check(post(s,'ib_settings',current_password='incorrecta',enabled='yes',address=address,key='re_test',secret=secret).status_code==403,'configuración requiere contraseña actual')
            check(post(s,'ib_settings',current_password=pw,enabled='yes',address=address,key='re_test',secret=secret).status_code==200,'habilita buzón desde panel')
            page=s.get(base+'?v=inbox',timeout=5).text;check(secret not in page and 're_test' not in page,'claves no se muestran')
            check(requests.get(webhook,timeout=5).status_code==405,'webhook solo POST')
            check(send(event,False).status_code==401,'firma HTTP inválida rechazada')
            check(send(event,stamp=int(time.time())-600).status_code==401,'evento antiguo rechazado')
            wrong=json.loads(json.dumps(event));wrong['data']['to']=['other@example.org'];check(send(wrong).status_code==200 and not db().get('inbox'),'ignora otros destinatarios')
            check(send(event).status_code==200 and len(db()['inbox'])==1,'recepción HTTP firmada')
            check(send(event).status_code==200 and len(db()['inbox'])==1,'duplicado HTTP no crea otro mensaje')
            page=s.get(base+'?v=inbox&id='+eid,timeout=5).text;check('&lt;script&gt;alert(1)&lt;/script&gt;' in page and '<script>alert(1)</script>' not in page,'asunto no ejecuta HTML')
            check(requests.get(base+'?v=inbox&id='+eid,timeout=5).status_code==403,'mensaje privado')
            check(post(requests.Session(),'ib_archive',id=eid).status_code==403,'anónimo no modifica mensajes')
            check(s.post(base,data={'action':'ib_archive','id':eid,'csrf':'invalid'},timeout=5).status_code==403,'CSRF bloqueado')
            check(post(s,'ib_archive',id=eid).status_code==200 and db()['inbox'][eid]['state']=='archived','archivo desde panel')
            check(post(s,'ib_reopen',id=eid).status_code==200 and db()['inbox'][eid]['state']=='new','reapertura desde panel')
            c='2026-2027';company=next(iter(db()['links'][c]));old=db()['links'][c][company]['confirmed'];check(post(s,'ib_link',id=eid,course=c,link=company,note='Revisado').status_code==200,'asocia respuesta a colaboración');check(db()['links'][c][company]['confirmed']==old,'plazas intactas')
            check(post(s,'ib_reply',id=eid,confirm='yes',reply_revision='0',subject='Re: prueba',body='Hola').status_code==400,'remitente no autenticado no recibe respuesta')
            check(post(s,'ib_delete',id=eid,confirm='yes').status_code==200 and not db()['inbox'],'elimina contenido local')
            check(send(event).status_code==200 and not db()['inbox'],'no reaparece mensaje eliminado')
            check(requests.get(f'http://127.0.0.1:{port}/inbox.php',timeout=5).status_code==404,'módulo no ejecutable directamente')
            log.seek(0);check(not any(x in log.read() for x in ('PHP Fatal error','PHP Warning','PHP Parse error')),'sin errores PHP')
        finally:srv.terminate();srv.wait(timeout=5)
print('TOTAL',count)
