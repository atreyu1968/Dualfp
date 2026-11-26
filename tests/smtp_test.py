import base64, email, json, socket, ssl, subprocess, sys, tempfile, threading
from email import policy
from pathlib import Path
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
count=0
def check(ok,msg):
    global count
    assert ok,msg
    count+=1;print('OK',msg,flush=True)
with tempfile.TemporaryDirectory() as td:
    cert=Path(td)/'cert.pem';key=Path(td)/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost'],check=True,capture_output=True)
    context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(cert,key)
    for mode,trust in [('starttls',True),('tls',True),('tls',False)]:
        data={};errors=[]
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0));listener.listen();listener.settimeout(8);port=listener.getsockname()[1]
            def serve():
                try:
                    conn,_=listener.accept();conn.settimeout(6)
                    with conn:
                        if mode=='tls':conn=context.wrap_socket(conn,server_side=True)
                        f=conn.makefile('rwb',buffering=0)
                        def send(s):f.write(s.encode()+b'\r\n')
                        def line():return f.readline().decode().rstrip('\r\n')
                        send('220 localhost fake SMTP');assert line().startswith('EHLO ')
                        if mode=='starttls':
                            send('250-localhost');send('250 STARTTLS');assert line()=='STARTTLS';send('220 ready');f.close();conn=context.wrap_socket(conn,server_side=True);f=conn.makefile('rwb',buffering=0);assert line().startswith('EHLO ')
                        send('250-localhost');send('250 AUTH LOGIN');assert line()=='AUTH LOGIN';send('334 VXNlcm5hbWU6');assert base64.b64decode(line())==b'usuario';send('334 UGFzc3dvcmQ6');assert base64.b64decode(line())==b'clave';send('235 authenticated');assert line()=='MAIL FROM:<sender@example.org>';send('250 ok');assert line()=='RCPT TO:<recipient@example.org>';send('250 ok');assert line()=='DATA';send('354 send data');rows=[]
                        while True:
                            row=f.readline()
                            if row==b'.\r\n':break
                            if not row:raise RuntimeError('EOF')
                            rows.append(row)
                        data['message']=b''.join(rows);send('250 accepted');assert line()=='QUIT';f.close();conn.close()
                except Exception as e:errors.append(str(e))
            t=threading.Thread(target=serve,daemon=True);t.start()
            cfg={'enabled':True,'host':'localhost','port':port,'mode':mode,'user':'usuario','password':'clave','from':'sender@example.org','reply':'reply@example.org'}
            code="require $argv[1]; $s=json_decode($argv[2],true); echo pc_smtp_send($s,'recipient@example.org','Formación y colaboración','Información de prueba')?'yes':'no';"
            args=['php'];
            if trust:args+=['-d','openssl.cafile='+str(cert)]
            r=subprocess.run(args+['-r',code,str(root/'prospecting.php'),json.dumps(cfg)],capture_output=True,text=True,timeout=20);t.join(timeout=10)
            if trust:
                check(r.stdout=='yes' and not errors and 'message' in data,mode+' autenticado con TLS y certificado confiable')
                m=email.message_from_bytes(data['message'],policy=policy.default);check(str(m['Subject'])=='Formación y colaboración' and m.get_content().strip()=='Información de prueba' and str(m['Reply-To'])=='reply@example.org',mode+' MIME y caracteres correctos')
            else:check(r.stdout=='no' and not data,'certificado no confiable rechazado')
    code="require $argv[1]; try {pc_smtp_message(['from'=>'sender@example.org','reply'=>''],'recipient@example.org',\"Asunto\\r\\nBcc: other@example.org\",'x'); echo 'bad';} catch(InvalidArgumentException $e){echo 'ok';}"
    check(subprocess.check_output(['php','-r',code,str(root/'prospecting.php')],text=True)=='ok','rechaza inyección de cabeceras')
print('TOTAL',count)
