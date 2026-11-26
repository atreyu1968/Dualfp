<?php
declare(strict_types=1);
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
function pc_consent():string{return 'Deseo recibir por correo invitaciones y novedades de colaboración FEOE de Administración y Gestión del centro. Puedo darme de baja en cualquier momento.';}
function pc_open(array $d,array $cfg):bool{
 $s=$d['settings'];$c=$d['courses'][$s['active']]??[];
 return !empty($s['public'])&&!empty($s['privacy'])&&empty($c['closed'])&&!empty($c['groups'])&&mail_ready($cfg);
}
function pc_prune(array &$d):void{
 foreach($d['interest_requests']??[] as $id=>$r)if(empty($r['verified_at'])&&($r['expires']??0)<time())unset($d['interest_requests'][$id]);
}
function pc_submit(array &$d,array $cfg,string $dir):string{
 if(!pc_open($d,$cfg))fail('Formulario no disponible. Contacte con el centro.',403);
 if(field('website')!==''||field('consent')!=='yes')fail('Solicitud inválida');
 $mail=email('email');auth_limit($d,$dir,'interest_verify',$mail,2);pc_prune($d);
 $cid=field('c');$g=field('group');if($cid!==$d['settings']['active']||!isset($d['courses'][$cid]['groups'][$g]))fail('Grupo no disponible');
 $name=field('name');if(!$name)fail('Nombre obligatorio');
 if(count($d['interest_requests']??[])>=500)fail('El centro debe revisar las solicitudes pendientes. Contacte directamente.',503);
 $generic='Si la solicitud puede tramitarse, recibirá un correo de verificación. Compruebe su bandeja.';
 foreach($d['interest_requests']??[] as $id=>$r)if($r['email']===$mail&&$r['course']===$cid&&$r['group']===$g){
  if(!empty($r['verified_at'])){flash($generic);return '?v=landing#interes';}
  unset($d['interest_requests'][$id]);
 }
 $id=uid();$t=bin2hex(random_bytes(32));$r=['name'=>$name,'email'=>$mail,'course'=>$cid,'group'=>$g,'requested'=>integer('requested',100),'notes'=>field('notes',3000),'created'=>date(DATE_ATOM),'expires'=>time()+86400,'token'=>hash('sha256',$t),'verified_at'=>'','optin'=>field('campaign_optin')==='yes','consent_text'=>pc_consent(),'privacy_text'=>$d['settings']['privacy'],'center'=>$d['settings']['center']];
 foreach(['zone','sector','contact','phone'] as $k)$r[$k]=field($k);
 $d['interest_requests'][$id]=$r;save_db($dir,$d);
 send_link($cfg,$dir,$mail,'Confirme su solicitud FEOE','Ha solicitado información sobre colaboración educativa. Confirme el correo mediante el enlace (24 horas). La autorización de campañas es opcional y se confirma en el siguiente paso.','interest_verify',$t);
 flash($generic);return '?v=landing#interes';
}
function pc_find(array $d,string $token):?string{
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;$hash=hash('sha256',$token);
 foreach($d['interest_requests']??[] as $id=>$r)if(empty($r['verified_at'])&&$r['expires']>=time()&&hash_equals($r['token'],$hash))return (string)$id;return null;
}
function pc_verify(array &$d):string{
 $id=pc_find($d,field('token',64));if(!$id)fail('Enlace inválido o caducado',403);
 $r=&$d['interest_requests'][$id];$r['verified_at']=date(DATE_ATOM);$r['optin']=$r['optin']&&field('campaign_optin')==='yes';unset($r['token']);
 audit($d,'Manifestación de interés verificada',$r['course']);flash('Correo verificado. El centro revisará la solicitud. No se ha creado una cuenta.');return '?v=landing#interes';
}
function pc_approve(array &$d):string{
 require_admin();$id=field('id');$r=$d['interest_requests'][$id]??null;if(!$r||!$r['verified_at'])fail('Solicitud sin verificar');
 $cid=$r['course'];editable($d,$cid);if(!isset($d['courses'][$cid]['groups'][$r['group']]))fail('Grupo no disponible');
 $eid=field('existing');$e=['name'=>$r['name'],'nif'=>'','zone'=>$r['zone'],'sector'=>$r['sector'],'kind'=>field('kind'),'emails'=>$r['email'],'phone'=>$r['phone'],'contact'=>$r['contact'],'address'=>'','sites'=>[],'tutors'=>[]];
 if(!in_array($e['kind'],['Empresa','Organismo','Asociación'],true))fail('Tipo inválido');
 if($eid){$eid=master_id($eid);$e=company($d,$eid);if(!in_array($r['email'],camp_emails($e),true))fail('El correo verificado no figura en la ficha. Compruebe la identidad y actualice la ficha antes de vincular.');}
 else{if(imp_matches(imp_index($d['companies']),$e))fail('Posible empresa duplicada. Revise el directorio y vincule la ficha existente.');$eid=uid();$d['companies'][$eid]=$e;}
 $lid=group_link($d['links'][$cid]??[],$eid,$r['group']);
 if($lid===null){$lid=new_link_key($d['links'][$cid]??[],$eid,$r['group']);$l=imp_link($r['group']);$l['status']='Interesada';$l['requested']=$r['requested'];$d['links'][$cid][$lid]=$l;}
 if(camp_status_ok($d['links'][$cid][$lid]))$d['links'][$cid][$lid]['status']='Interesada';
 $d['links'][$cid][$lid]['history'][]=['at'=>date(DATE_ATOM),'type'=>'Interés web verificado','note'=>'Solicitud revisada; plazas orientativas: '.$r['requested'].'. '.$r['notes']];
 $d['interest_evidence'][$id]=['email'=>$r['email'],'company'=>$eid,'course'=>$cid,'group'=>$r['group'],'created'=>$r['created'],'verified_at'=>$r['verified_at'],'approved_at'=>date(DATE_ATOM),'optin'=>$r['optin'],'text'=>$r['consent_text'],'privacy'=>$r['privacy_text'],'center'=>$r['center']];
 if($r['optin']&&!isset($d['campaign_optouts'][$r['email']]))$d['campaign_permissions'][$eid][$r['email']]=['allowed'=>true,'evidence'=>'Formulario público verificado '.$r['verified_at'].'. '.$r['consent_text'].' Referencia '.$id,'at'=>date(DATE_ATOM),'source'=>'interest','reference'=>$id];
 unset($d['interest_requests'][$id]);audit($d,'Interés empresarial aprobado',$cid,$lid);flash('Solicitud incorporada; las plazas confirmadas no se han modificado.');return '?v=interests&c='.rawurlencode($cid);
}
function pc_verify_ui(array $d):void{
 $t=is_string($_GET['t']??null)?$_GET['t']:'';$id=pc_find($d,$t);
 echo '<section class="narrow card"><h1>Confirmar solicitud de información</h1>';
 if(!$id){echo '<p>Enlace inválido o caducado.</p></section>';return;}
 $r=$d['interest_requests'][$id];echo '<p>Confirme su solicitud al centro. No se crea una cuenta ni se confirma ninguna plaza.</p>';
 form_start('pc_verify',['token'=>$t]);
 if($r['optin'])echo '<label class="check"><input name="campaign_optin" type="checkbox" value="yes"> '.h($r['consent_text']).'</label>';
 end_form('Confirmar correo y solicitud');echo '</section>';
}
function pc_interests_ui(array $d,string $cid):void{
 echo '<section class="card"><h2>Solicitudes de interés empresarial</h2><p>Solo las solicitudes verificadas y revisadas pueden vincularse. El permiso de campañas se registra automáticamente si se ha confirmado expresamente; las bajas globales se respetan.</p>';
 $n=0;foreach($d['interest_requests']??[] as $id=>$r){if($r['course']!==$cid||(!$r['verified_at']&&$r['expires']<time()))continue;$n++;
 echo '<details><summary>'.h($r['name'].' · '.$r['email'].' · '.($r['verified_at']?'Verificada':'Pendiente de verificar')).'</summary><p>'.h($r['contact'].' · '.$r['phone'].' · '.$r['zone'].' · '.($d['courses'][$cid]['groups'][$r['group']]['name']??'').' · '.$r['requested'].' plazas orientativas').'</p><p>'.h($r['notes']).'</p><p>Autorización: '.($r['optin']&&$r['verified_at']?'Confirmada': 'No confirmada').'</p>';
 if($r['verified_at']&&!$d['courses'][$cid]['closed']){
 $opts=[''=>'Crear ficha nueva (si no hay duplicados)'];foreach($d['companies'] as $eid=>$e)if(in_array($r['email'],camp_emails($e),true))$opts[$eid]=$e['name'].' · '.$e['zone'];
 form_start('pc_approve',['id'=>$id]);select_field('existing','Empresa (revise identidad)',$opts);select_field('kind','Tipo para alta nueva',['Empresa'=>'Empresa','Organismo'=>'Organismo','Asociación'=>'Asociación']);end_form('Aprobar solicitud');
 }
 form_start('pc_discard',['id'=>$id]);end_form('Descartar solicitud');echo '</details>';
 }if(!$n)echo '<p>No hay solicitudes pendientes en este curso.</p>';
 echo '<h3>Autorizaciones verificadas</h3>';foreach($d['interest_evidence']??[] as $id=>$r)if($r['course']===$cid&&$r['optin'])echo '<details><summary>'.h($r['email'].' · '.$r['verified_at']).'</summary><p>'.h($r['text']).'</p><p>'.h('Referencia: '.$id.' · '.$r['center'].' · '.(camp_allowed($d,$r['company'],$r['email'])?'Activa':'No activa')).'</p></details>';echo '</section>';
}
function pc_smtp_config(array $d):array{return ($d['smtp_config']??[])+['enabled'=>false,'host'=>'','port'=>587,'mode'=>'starttls','user'=>'','password'=>'','from'=>'','reply'=>''];}
function pc_smtp_ready(array $s):bool{return $s['enabled']&&$s['host']&&$s['from']&&$s['user']&&$s['password'];}
function pc_smtp_read($fp,array $codes):string{
 $out='';for($i=0;$i<60;$i++){ $line=fgets($fp,2048);if($line===false||!preg_match('/^(\d{3})([ -])/',$line,$m))throw new RuntimeException('Respuesta SMTP inválida');$out.=$line;if($m[2]===' '){if(!in_array((int)$m[1],$codes,true))throw new RuntimeException('SMTP rechazó la operación');return $out;}}
 throw new RuntimeException('Respuesta SMTP excesiva');
}
function pc_smtp_write($fp,string $s):void{while($s!==''){$n=fwrite($fp,$s);if(!$n)throw new RuntimeException('Fallo de escritura SMTP');$s=substr($s,$n);}}
function pc_smtp_cmd($fp,string $cmd,array $codes):string{pc_smtp_write($fp,$cmd."\r\n");return pc_smtp_read($fp,$codes);}
function pc_smtp_message(array $s,string $to,string $subject,string $body):string{
 foreach([$s['from'],$to] as $mail)if(!filter_var($mail,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$mail))throw new InvalidArgumentException('Correo inválido');
 if($s['reply']&&(!filter_var($s['reply'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$s['reply'])))throw new InvalidArgumentException('Reply-To inválido');
 if(!$subject||strlen($subject)>180||preg_match('/[\r\n\x00]/',$subject)||strlen($body)>12000)throw new InvalidArgumentException('Mensaje inválido');
 $encoded=iconv_mime_encode('Subject',$subject,['scheme'=>'B','input-charset'=>'UTF-8','output-charset'=>'UTF-8','line-length'=>76,'line-break-chars'=>"\r\n"]);if($encoded===false)throw new InvalidArgumentException('Asunto inválido');
 $headers=['Date: '.date(DATE_RFC2822),'From: '.$s['from'],'To: '.$to,$encoded,'MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
 if($s['reply'])$headers[]='Reply-To: '.$s['reply'];
 return implode("\r\n",$headers)."\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
}
function pc_smtp_send(array $s,string $to,string $subject,string $body):bool{
 if(!pc_smtp_ready($s))return false;$fp=null;
 try{
 $msg=pc_smtp_message($s,$to,$subject,$body);
 $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$s['host'],'allow_self_signed'=>false,'SNI_enabled'=>true]]);
 $scheme=$s['mode']==='tls'?'tls':'tcp';$fp=@stream_socket_client($scheme.'://'.$s['host'].':'.$s['port'],$errno,$error,5,STREAM_CLIENT_CONNECT,$ctx);if(!$fp)return false;stream_set_timeout($fp,5);
 pc_smtp_read($fp,[220]);$ehlo=pc_smtp_cmd($fp,'EHLO fpdual.local',[250]);
 if($s['mode']==='starttls'){if(!preg_match('/^250[ -]STARTTLS\b/im',$ehlo))throw new RuntimeException('STARTTLS no disponible');pc_smtp_cmd($fp,'STARTTLS',[220]);if(stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)throw new RuntimeException('TLS no disponible');$ehlo=pc_smtp_cmd($fp,'EHLO fpdual.local',[250]);}
 if(!preg_match('/^250[ -]AUTH[ =].*\bLOGIN\b/im',$ehlo))throw new RuntimeException('AUTH LOGIN no disponible');
 pc_smtp_cmd($fp,'AUTH LOGIN',[334]);pc_smtp_cmd($fp,base64_encode($s['user']),[334]);pc_smtp_cmd($fp,base64_encode($s['password']),[235]);
 pc_smtp_cmd($fp,'MAIL FROM:<'.$s['from'].'>',[250]);pc_smtp_cmd($fp,'RCPT TO:<'.$to.'>',[250,251]);pc_smtp_cmd($fp,'DATA',[354]);pc_smtp_write($fp,$msg.".\r\n");pc_smtp_read($fp,[250]);
 @fwrite($fp,"QUIT\r\n");return true;
 }catch(Throwable $e){error_log('FP Dual: operación SMTP fallida o resultado incierto');return false;}finally{if(is_resource($fp))fclose($fp);}
}
function pc_smtp_settings(array &$d,array $cfg):string{
 require_admin();$pw=field('current_password',72);if(!password_verify($pw,admin_hash($d,$cfg)))fail('Contraseña actual incorrecta',403);
 $s=pc_smtp_config($d);$s['enabled']=field('enabled')==='yes';$s['host']=strtolower(field('host',253));$s['port']=integer('port',65535);$s['mode']=field('mode');$s['from']=strtolower(field('from',254));$s['reply']=strtolower(field('reply',254));$s['user']=field('user',254);$p=field('password',500);
 if(field('clear')==='yes'){if($s['enabled']||$p)fail('Desactive SMTP y deje vacía la clave antes de borrarla');$s['password']='';}elseif($p!=='')$s['password']=$p;
 if(!in_array($s['mode'],['starttls','tls'],true)||!in_array($s['port'],[465,587],true)||($s['mode']==='tls')!==($s['port']===465))fail('Seleccione STARTTLS 587 o TLS 465');
 if($s['host']&&!preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?$/D',$s['host']))fail('Servidor SMTP inválido');
 foreach(['from','reply'] as $k)if($s[$k]&&!filter_var($s[$k],FILTER_VALIDATE_EMAIL))fail('Dirección inválida');
 if($s['enabled']&&!pc_smtp_ready($s))fail('Complete los datos SMTP');
 $d['smtp_config']=$s;audit($d,'Configuración SMTP actualizada');flash('Configuración SMTP guardada. Realice una prueba.');return '?v=settings';
}
function pc_smtp_ui(array $d):void{
 $s=pc_smtp_config($d);echo '<h2>Correo institucional SMTP (individual)</h2><p>Use una cuenta autorizada por el centro y por el proveedor. Sin envíos masivos. El servidor debe admitir SMTP autenticado mediante LOGIN con TLS. OAuth2 no está disponible.</p>';
 form_start('pc_smtp_settings');echo '<div class="fields">';input('host','Servidor SMTP',$s['host']);select_field('port','Puerto',['587'=>'587 STARTTLS','465'=>'465 TLS'],$s['port']);select_field('mode','Cifrado',['starttls'=>'STARTTLS','tls'=>'TLS'],$s['mode']);input('user','Usuario',$s['user']);input('from','Correo remitente',$s['from'],'email');input('reply','Correo de respuesta (opcional)',$s['reply'],'email');input('password','Clave SMTP ('.($s['password']?'guardada; vacío conserva':'sin configurar').')','','password');input('current_password','Contraseña actual del administrador','','password',true);echo '</div><label class="check"><input name="enabled" type="checkbox" value="yes"'.($s['enabled']?' checked':'').'> Habilitar SMTP individual</label><label class="check"><input name="clear" type="checkbox" value="yes"> Eliminar clave (desactivar antes)</label>';end_form('Guardar SMTP');
 if(pc_smtp_ready($s)){form_start('pc_smtp_test');end_form('Enviar prueba SMTP al administrador');}
}
function pc_contact_ui(array $d,array $cfg,string $cid,string $eid):void{
 $e=company($d,$eid);$mails=camp_emails($e);$s=pc_smtp_config($d);$base=rtrim($cfg['public_url']??'','/');
 echo '<h3>Primer contacto institucional individual</h3><p>Revise la finalidad y el destinatario. No es una campaña ni concede autorización. Use su correo institucional con las instrucciones del centro. No contacte direcciones dadas de baja.</p>';
 foreach($mails as $mail){if(isset($d['campaign_optouts'][$mail]))continue;
 $subject='Propuesta de colaboración educativa FEOE';$body=strtr(camp_default(),camp_tokens($d,$cfg+['public_url'=>$base],$cid,$eid,$mail));$body.="\n\nSi prefiere no recibir nuevas propuestas, comuníquenoslo.";
 echo '<details><summary>'.h($mail).'</summary>';echo '<p><a class="button secondary" href="'.h('mailto:'.$mail.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body)).'">Abrir correo institucional</a></p>';
 if(pc_smtp_ready($s)){form_start('pc_contact',['c'=>$cid,'e'=>$eid,'email'=>$mail,'revision'=>(string)count($d['links'][$cid][$eid]['history'])]);input('subject','Asunto',$subject);area('body','Texto a revisar',$body);echo '<label class="check"><input name="confirm" type="checkbox" value="yes" required> He revisado la finalidad, el destinatario y las normas del centro y del proveedor. Autorizo este único envío.</label>';end_form('Enviar correo individual por SMTP');}echo '</details>';
 }
}
function pc_contact(array &$d,array $cfg,string $dir,string $cid,string $eid):string{
 require_admin();editable($d,$cid);participation($d,$cid,$eid);$mail=email('email');$s=pc_smtp_config($d);
 if(!pc_smtp_ready($s)||!in_array($mail,camp_emails(company($d,$eid)),true)||isset($d['campaign_optouts'][$mail])||field('confirm')!=='yes')fail('Envío no permitido',403);
 $hist=&$d['links'][$cid][$eid]['history'];if(field('revision')!==(string)count($hist))fail('La ficha cambió; recargue antes de enviar');
 $day=date('Y-m-d');$counts=$d['smtp_daily']??['day'=>$day,'count'=>0,'mails'=>[]];if($counts['day']!==$day)$counts=['day'=>$day,'count'=>0,'mails'=>[]];if($counts['count']>=10||isset($counts['mails'][$mail]))fail('Límite de primer contacto: diez intentos diarios y uno por dirección. Revise el historial.');
 $subject=field('subject',180);$body=field('body',12000);if(!$body)fail('Mensaje vacío');try{pc_smtp_message($s,$mail,$subject,$body);}catch(InvalidArgumentException $e){fail($e->getMessage());}
 $counts['count']++;$counts['mails'][$mail]=true;$d['smtp_daily']=$counts;
 $hist[]=['at'=>date(DATE_ATOM),'type'=>'SMTP institucional','note'=>'Resultado incierto · '.$mail.' · '.$subject];$i=array_key_last($hist);save_db($dir,$d);
 $ok=pc_smtp_send($s,$mail,$subject,$body);$hist[$i]['note']=($ok?'Aceptado por el servidor SMTP':'Resultado incierto; no reenviar sin verificar').' · '.$mail.' · '.$subject;
 audit($d,'Contacto institucional SMTP',$cid,$eid);flash($ok?'Mensaje aceptado por SMTP (no acredita entrega).':'Resultado incierto. Compruebe su servicio de correo.');return '?v=company&c='.rawurlencode($cid).'&e='.rawurlencode($eid);
}
function pc_action(string $action,array &$d,array $cfg,string $dir,string $cid,string $eid):string{
 if($action==='pc_verify')return pc_verify($d);
 require_admin();
 if($action==='pc_approve')return pc_approve($d);
 if($action==='pc_discard'){$id=field('id');if(!isset($d['interest_requests'][$id]))fail('Solicitud inexistente',404);$c=$d['interest_requests'][$id]['course'];unset($d['interest_requests'][$id]);audit($d,'Solicitud de interés descartada',$c);return '?v=interests&c='.rawurlencode($c);}
 if($action==='pc_smtp_settings')return pc_smtp_settings($d,$cfg);
 if($action==='pc_smtp_test'){$s=pc_smtp_config($d);auth_limit($d,$dir,'smtp_test',$cfg['admin_email'],3);$ok=pc_smtp_send($s,$cfg['admin_email'],'Prueba SMTP FP Dual','Prueba de configuración del canal institucional.');flash($ok?'Prueba aceptada por SMTP.':'No se pudo confirmar la prueba SMTP.');return '?v=settings';}
 if($action==='pc_contact')return pc_contact($d,$cfg,$dir,$cid,$eid);
 fail('Acción desconocida');
}
