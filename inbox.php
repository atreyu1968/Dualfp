<?php
declare(strict_types=1);
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
// Included only by auth.php. All content is treated as untrusted text.
function ib_cut(string $s,int $max):string {
 $s=substr($s,0,$max);while($s!==''&&!preg_match('//u',$s))$s=substr($s,0,-1);return $s;
}
function ib_address(mixed $v):string {
 if(!is_string($v))return '';if(preg_match('/<([^<>]+)>\s*$/D',$v,$m))$v=$m[1];
 $v=strtolower(trim($v));return filter_var($v,FILTER_VALIDATE_EMAIL)?$v:'';
}
function ib_config(array $d):array {return ($d['inbox_config']??[])+['enabled'=>false,'address'=>'','secret'=>'','key'=>''];}
function ib_secret(string $s):string|false {
 if(!str_starts_with($s,'whsec_'))return false;
 $k=base64_decode(substr($s,6),true);return is_string($k)&&strlen($k)>=16?$k:false;
}
function ib_signature(string $raw,string $id,string $ts,string $sig,string $secret,?int $now=null):bool {
 $k=ib_secret($secret);if($k===false||!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id)||!preg_match('/^\d{1,12}$/D',$ts)||abs(($now??time())-(int)$ts)>300||strlen($sig)>4096)return false;
 $expected=base64_encode(hash_hmac('sha256',$id.'.'.$ts.'.'.$raw,$k,true));
 foreach(explode(' ',$sig) as $part)if(str_starts_with($part,'v1,')&&hash_equals($expected,substr($part,3)))return true;return false;
}
function ib_valid_id(mixed $id):bool{return is_string($id)&&preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di',$id)===1;}
function ib_matching(array $d,string $from):array {
 if(!$from)return [];$matches=[];foreach($d['companies'] as $id=>$e)if(in_array($from,camp_emails($e),true))$matches[]=(string)$id;return $matches;
}
function ib_ingest(array &$d,array $event):int {
 $cfg=ib_config($d);if(!$cfg['enabled'])return 503;
 if(($event['type']??'')!=='email.received')return ib_outbound_event($d,$event);
 $x=$event['data']??null;if(!is_array($x)||!ib_valid_id($x['email_id']??null)||!is_array($x['to']??null))return 400;
 $id=strtolower($x['email_id']);$to=array_map('ib_address',$x['to']);if(!in_array($cfg['address'],$to,true))return 200;
 if(isset($d['inbox'][$id])||isset($d['inbox_deleted'][$id]))return 200;
 if(count($d['inbox']??[])>=500||count($d['inbox_deleted']??[])>=20000)return 503;
 $from=ib_address($x['from']??null);$subject=is_string($x['subject']??null)?ib_cut($x['subject'],500):'';
 $d['inbox'][$id]=['from'=>$from,'subject'=>$subject,'at'=>date(DATE_ATOM),'state'=>'new','fetch'=>'pending','attempts'=>0,'next'=>0,'body'=>'','dmarc'=>'unknown','attachments'=>[],'course'=>'','link'=>'','replies'=>[]];return 200;
}
function ib_webhook(array $d,string $dir):never {
 header('Content-Type: application/json; charset=UTF-8');$cfg=ib_config($d);$status=400;
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$status=405;
 elseif(!$cfg['enabled'])$status=503;
 else{
  $raw=file_get_contents('php://input',false,null,0,131073);
  if(!is_string($raw)||strlen($raw)>131072)$status=413;
  elseif(!ib_signature($raw,$_SERVER['HTTP_SVIX_ID']??'',$_SERVER['HTTP_SVIX_TIMESTAMP']??'',$_SERVER['HTTP_SVIX_SIGNATURE']??'',$cfg['secret']))$status=401;
  else{$event=json_decode($raw,true);if(is_array($event)){$status=ib_ingest($d,$event);if($status===200)save_db($dir,$d);}}
 }
 http_response_code($status);echo json_encode(['ok'=>$status===200]);exit;
}
function ib_fetch_api(string $id,string $key):?array {
 if(!ib_valid_id($id)||!$key||!function_exists('curl_init'))return null;
 $raw='';$ch=curl_init('https://api.resend.com/emails/receiving/'.rawurlencode($id).'?html_format=cid');
 curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}]);
 $ok=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if(!$ok||$code!==200)return null;
 $r=json_decode($raw,true);return is_array($r)&&($r['id']??null)===$id?$r:null;
}
function ib_store_body(array &$m,array $r):bool {
 if(ib_address($r['from']??null)!==$m['from'])return false;
 $text=$r['text']??null;if(!is_string($text)||$text==='')$text=is_string($r['html']??null)?html_entity_decode(strip_tags($r['html']),ENT_QUOTES|ENT_HTML5,'UTF-8'):'';
 $m['body']=ib_cut($text,24000);$m['truncated']=strlen($text)>strlen($m['body']);
 $m['dmarc']=in_array($r['authentication']['dmarc']??'',['pass','fail','gray'],true)?$r['authentication']['dmarc']:'unknown';
 $m['attachments']=[];foreach(array_slice(is_array($r['attachments']??null)?$r['attachments']:[],0,30) as $a)if(is_array($a)&&is_string($a['filename']??null))$m['attachments'][]=ib_cut($a['filename'],250);
 $m['fetch']='ready';return true;
}
function ib_tick(array &$d,string $dir):void {
 $cfg=ib_config($d);if(!$cfg['enabled']||!$cfg['key'])return;
 foreach($d['inbox']??[] as $id=>$m){if($m['fetch']!=='pending'||$m['next']>time())continue;
  $m['attempts']++;$r=ib_fetch_api($id,$cfg['key']);
  if(!$r||!ib_store_body($m,$r)){$m['fetch']=$m['attempts']>=5?'error':'pending';$m['next']=time()+min(3600,60*2**$m['attempts']);}
  $d['inbox'][$id]=$m;save_db($dir,$d);break;
 }
}
function ib_hold(array $d,string $mail,string $eid):bool {
 if(!$mail)return false;foreach($d['inbox']??[] as $m)if($m['state']==='new'&&$m['from']===$mail&&in_array($mail,camp_emails($d['companies'][master_id($eid)]??[]),true))return true;return false;
}
function ib_action(string $action,array &$d,array $cfg,string $dir):string {
 require_admin();$back='?v=inbox';
 if($action==='ib_settings'){
  $pw=field('current_password',72);if(!password_verify($pw,admin_hash($d,$cfg)))fail('Contraseña actual incorrecta',403);
  $ic=ib_config($d);$address=ib_address(field('address',254));$key=field('key',500);$secret=field('secret',500);$enabled=field('enabled')==='yes';
  if($key&&!preg_match('/^re_[A-Za-z0-9_-]+$/D',$key))fail('Clave de recepción inválida');
  if($secret&&ib_secret($secret)===false)fail('Secreto de firma inválido');
  if(field('clear')==='yes'){if($key||$secret||$enabled)fail('Desactive la recepción antes de borrar las claves');$ic['key']=$ic['secret']='';}
  else{$ic['key']=$key?:$ic['key'];$ic['secret']=$secret?:$ic['secret'];}
  if($enabled&&(!$address||!$ic['key']||!$ic['secret']||!valid_public_url($cfg['public_url']??'')))fail('Configure la dirección, las claves y la URL pública HTTPS');
  $ic['address']=$address;$ic['enabled']=$enabled;$d['inbox_config']=$ic;audit($d,'Configuración del buzón actualizada');flash('Configuración guardada.');return $back;
 }
 $id=field('id');if(!isset($d['inbox'][$id]))fail('Mensaje no encontrado',404);$m=&$d['inbox'][$id];
 if($action==='ib_retry'){if($m['fetch']!=='ready'){$m['fetch']='pending';$m['attempts']=0;$m['next']=0;}return $back.'&id='.rawurlencode($id);}
 if($action==='ib_archive'){if($m['state']==='new')$m['state']='archived';return $back;}
 if($action==='ib_reopen'){if($m['state']==='archived')$m['state']='new';return $back.'&id='.rawurlencode($id);}
 if($action==='ib_delete'){if(field('confirm')!=='yes')fail('Confirme la eliminación');$d['inbox_deleted'][$id]=time();unset($d['inbox'][$id]);audit($d,'Mensaje del buzón eliminado');return $back;}
 if($action==='ib_link'){
  if($m['state']==='linked')fail('Mensaje ya vinculado');$cid=field('course');$eid=field('link');editable($d,$cid);participation($d,$cid,$eid);
  $note=field('note',2000);if(!$note)fail('Escriba una nota revisada para el historial');
  $d['links'][$cid][$eid]['history'][]=['at'=>date(DATE_ATOM),'type'=>'Respuesta empresarial revisada','note'=>$note];$m['state']='linked';$m['course']=$cid;$m['link']=$eid;audit($d,'Respuesta vinculada',$cid,$eid);flash('Respuesta vinculada. No se han confirmado plazas.');return $back.'&id='.rawurlencode($id);
 }
 if($action==='ib_reply'){
  if($m['state']!=='linked'||!$m['from']||$m['dmarc']!=='pass'||field('confirm')!=='yes'||!mail_ready($cfg))fail('Vincule y revise el mensaje autenticado antes de responder');
  if(count($m['replies'])>=5||field('reply_revision')!==(string)count($m['replies']))fail('Límite de respuestas o formulario ya utilizado. Recargue el mensaje.');$body=field('body',10000);$subject=field('subject',180);if(!$body||!$subject||preg_match('/[\r\n]/',$subject))fail('Complete asunto y texto');
  $ic=ib_config($d);if(!$ic['enabled']||!$ic['address'])fail('Active el buzón antes de responder');
  $cfg['resend_reply_to']=$ic['address'];$rid=uid();$m['replies'][$rid]=['at'=>date(DATE_ATOM),'subject'=>$subject,'body'=>$body,'state'=>'uncertain'];save_db($dir,$d);
  $ok=resend_mail($cfg,$dir,$m['from'],$subject,$body,'inbox-'.$rid);$m['replies'][$rid]['state']=$ok?'sent':'uncertain';audit($d,'Respuesta manual al correo',$m['course'],$m['link']);flash($ok?'Respuesta aceptada por Resend.':'Resultado incierto: consulte Resend antes de reenviar.');return $back.'&id='.rawurlencode($id);
 }
 fail('Acción no disponible');
}
function ib_ui(array $d,array $cfg):void {
 $ic=ib_config($d);$all=$d['inbox']??[];$new=count(array_filter($all,fn($m)=>$m['state']==='new'));
 echo '<section class="card"><h2>Buzón de empresas · '.h($new).' pendientes</h2><p>Los mensajes requieren revisión. Los seguimientos pendientes al mismo remitente quedan retenidos mientras haya mensajes nuevos. No se confirman plazas automáticamente.</p><details><summary>Configuración de recepción Resend</summary>';
 echo '<p>Webhook: <code>'.h(rtrim($cfg['public_url']??'','/').'/index.php?v=inbound_webhook').'</code> · eventos email.received, email.sent, email.delivered, email.delivery_delayed, email.bounced, email.complained, email.failed y contact.updated</p>';
 form_start('ib_settings');input('address','Dirección exacta de recepción',$ic['address'],'email');input('key','API key de recepción (vacío conserva; '.($ic['key']?'configurada':'sin configurar').')','','password');input('secret','Secreto whsec_ (vacío conserva; '.($ic['secret']?'configurado':'sin configurar').')','','password');
 echo '<label class="check"><input type="checkbox" name="enabled" value="yes"'.($ic['enabled']?' checked':'').'> Activar recepción</label><label class="check"><input type="checkbox" name="clear" value="yes"> Borrar ambas claves (desactivar primero)</label>';input('current_password','Contraseña actual de administración','','password',true);end_form('Guardar recepción');echo '</details></section>';
 $id=is_string($_GET['id']??null)?$_GET['id']:'';$m=$all[$id]??null;
 if($m){
  echo '<section class="card"><a href="?v=inbox">← Bandeja</a><h2>'.h($m['subject']).'</h2><p>'.h($m['from'].' · '.$m['at'].' · DMARC: '.$m['dmarc'].' · '.$m['state']).'</p><p>Recuperación del contenido: '.h($m['fetch']).'</p>';
  if($m['fetch']==='ready'){echo '<pre class="pre">'.h($m['body']).'</pre>';if(!empty($m['truncated']))echo '<p>Texto truncado: consulte el original en Resend.</p>';}else{form_start('ib_retry',['id'=>$id]);end_form('Reintentar recuperación');}
  if($m['attachments'])echo '<p>Adjuntos (solo nombres; consultar original en Resend): '.h(implode(', ',$m['attachments'])).'</p>';
  $match=ib_matching($d,$m['from']);echo '<p>Coincidencias del remitente (no acreditan identidad): '.h(implode(', ',array_map(fn($e)=>$d['companies'][$e]['name'],$match))?:'Ninguna').'</p>';
  if($m['state']!=='linked'){
   foreach($d['courses'] as $cid=>$c){if($c['closed'])continue;$opts=[''=>'Seleccione una colaboración'];foreach($d['links'][$cid]??[] as $eid=>$l)$opts[$eid]=collaboration_label($d,$cid,(string)$eid);if(count($opts)<2)continue;echo '<details><summary>Vincular al curso '.h($c['name']).'</summary>';form_start('ib_link',['id'=>$id,'course'=>$cid]);select_field('link','Colaboración',$opts);area('note','Nota revisada para el tutor (no se copia el correo completo)','');end_form('Vincular respuesta');echo '</details>';}
   form_start($m['state']==='archived'?'ib_reopen':'ib_archive',['id'=>$id]);end_form($m['state']==='archived'?'Reabrir':'Archivar sin vincular');
  }else{
   echo '<p>Vinculado a: '.h(collaboration_label($d,$m['course'],$m['link'])).'</p>';
   if($m['dmarc']==='pass'&&$ic['enabled']&&mail_ready($cfg)&&count($m['replies'])<5){form_start('ib_reply',['id'=>$id,'reply_revision'=>(string)count($m['replies'])]);input('subject','Asunto',ib_cut('Re: '.$m['subject'],180));area('body','Respuesta manual','');echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> He comprobado el destinatario y el contenido.</label>';end_form('Enviar respuesta');}
   foreach($m['replies'] as $r)echo '<details><summary>'.h($r['at'].' · '.$r['state'].' · '.$r['subject']).'</summary><pre>'.h($r['body']).'</pre></details>';
  }
  form_start('ib_delete',['id'=>$id]);echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Eliminar contenido local (no elimina el original en Resend ni el historial ya vinculado)</label>';end_form('Eliminar mensaje');echo '</section>';return;
 }
 $filter=is_string($_GET['filter']??null)?$_GET['filter']:'new';if(!in_array($filter,['new','linked','archived','all'],true))$filter='new';
 echo '<section class="card"><div class="actions">';foreach(['new'=>'Pendientes','linked'=>'Vinculados','archived'=>'Archivados','all'=>'Todos'] as $k=>$label)echo '<a class="button secondary" href="?v=inbox&amp;filter='.h($k).'">'.h($label).'</a>';echo '</div><div class="scroll"><table><tr><th>Fecha</th><th>Remitente</th><th>Asunto</th><th>Estado</th></tr>';
 uasort($all,fn($a,$b)=>strcmp($b['at'],$a['at']));foreach($all as $id=>$m){if($filter!=='all'&&$filter!==$m['state'])continue;echo '<tr><td>'.h($m['at']).'</td><td>'.h($m['from']?:'No identificado').'</td><td><a href="?v=inbox&amp;id='.h($id).'">'.h($m['subject']?:'(Sin asunto)').'</a></td><td>'.h($m['state'].' / '.$m['fetch']).'</td></tr>';}echo '</table></div></section>';
}

function ib_outbound_event(array &$d,array $event):int{
 $type=$event['type']??'';$x=$event['data']??null;if(!is_array($x))return 400;
 if($type==='contact.updated'){
  $mail=ib_address($x['email']??null);
  if($mail&&($x['unsubscribed']??false)===true){$d['campaign_optouts'][$mail]=date(DATE_ATOM);$d['campaign_block_reasons'][$mail]='Baja comunicada por Resend';audit($d,'Baja recibida de Resend');}
  return 200;
 }
 if(!in_array($type,['email.sent','email.delivered','email.delivery_delayed','email.bounced','email.complained','email.failed'],true))return 200;
 $id=$x['email_id']??null;if(!ib_valid_id($id))return 400;
 foreach($d['first_contact']??[] as $eid=>$r){if(($r['provider_id']??'')!==$id)continue;
 $old=$r['delivery']??'';$terminal=['email.bounced','email.complained','email.failed'];
 if(in_array($old,$terminal,true)&&$type!=='email.complained')return 200;
 if($old==='email.delivered'&&in_array($type,['email.sent','email.delivery_delayed'],true))return 200;
 if($old===$type)return 200;
 $d['first_contact'][$eid]['delivery']=$type;$d['first_contact'][$eid]['delivery_at']=date(DATE_ATOM);
 if(in_array($type,['email.bounced','email.complained'],true)){$d['campaign_optouts'][$r['email']]=date(DATE_ATOM);$d['campaign_block_reasons'][$r['email']]=$type==='email.bounced'?'Rebote de correo':'Queja de correo no deseado';}
 audit($d,'Evento Resend: '.$type,$r['course']??'',(string)$eid);return 200;
 }return 200;
}
