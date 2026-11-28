<?php
declare(strict_types=1);
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
function pc_consent():string{return 'Deseo recibir por correo invitaciones y novedades de colaboración FEOE de Administración y Gestión del centro. Puedo darme de baja en cualquier momento.';}
function pc_blockers(array $d):array{
 $s=$d['settings'];$c=$d['courses'][$s['active']]??[];$errors=[];
 if(empty($s['public']))$errors[]='Marque Habilitar formulario público y guarde.';
 if(trim($s['privacy']??'')==='')$errors[]='Introduzca el aviso de protección de datos validado por el centro.';
 if(!$c||!empty($c['closed']))$errors[]='Seleccione un curso publicado abierto.';
 if(empty($c['groups']))$errors[]='Añada al menos un grupo al curso publicado.';
 return $errors;
}
function pc_auto(array $d,array $cfg):bool{return ($d['settings']['interest_mode']??'auto')==='auto'&&mail_ready($cfg);}
function pc_open(array $d,array $cfg):bool{return !pc_blockers($d);}
function pc_manual_pending(array $r):bool{return !empty($r['manual_review']);}
function pc_reviewed(array $r):bool{return !empty($r['verified_at'])||!empty($r['reviewed_at']);}
function pc_status(array $d,array $cfg):string{
 $errors=pc_blockers($d);if($errors)return 'Formulario no publicado: '.implode(' ',$errors);
 return pc_auto($d,$cfg)?'Formulario publicado · verificación automática por Resend (si falla, revisión manual).':'Formulario publicado · revisión manual de solicitudes. No se envían correos automáticos de verificación.';
}
function pc_prune(array &$d):void{
 foreach($d['interest_requests']??[] as $id=>$r)if(!pc_reviewed($r)&&($r['expires']??0)<time())unset($d['interest_requests'][$id]);
}
function pc_submit(array &$d,array $cfg,string $dir):string{
 if(!pc_open($d,$cfg))fail('Formulario no disponible. Contacte con el centro.',403);
 if(field('website')!==''||field('consent')!=='yes')fail('Solicitud inválida');
 $mail=email('email');auth_limit($d,$dir,'interest_verify',$mail,2);pc_prune($d);
 $cid=field('c');$g=field('group');if($cid!==$d['settings']['active']||!isset($d['courses'][$cid]['groups'][$g]))fail('Grupo no disponible');
 $name=field('name');if(!$name)fail('Nombre obligatorio');
 if(count($d['interest_requests']??[])>=500)fail('El centro debe revisar las solicitudes pendientes. Contacte directamente.',503);
 $auto=pc_auto($d,$cfg);$generic='Solicitud recibida. El centro revisará la información. Si recibe un enlace de verificación, confirme su correo.';
 foreach($d['interest_requests']??[] as $id=>$r)if($r['email']===$mail&&$r['course']===$cid&&$r['group']===$g){
  if(pc_reviewed($r)||pc_manual_pending($r)){flash($generic);return '?v=landing#interes';}
  unset($d['interest_requests'][$id]);
 }
 $id=uid();$t=bin2hex(random_bytes(32));$r=['name'=>$name,'email'=>$mail,'course'=>$cid,'group'=>$g,'requested'=>integer('requested',100),'notes'=>field('notes',3000),'created'=>date(DATE_ATOM),'expires'=>time()+($auto?86400:30*86400),'token'=>$auto?hash('sha256',$t):'','verified_at'=>'','manual_review'=>!$auto,'optin'=>$auto&&field('campaign_optin')==='yes','consent_text'=>pc_consent(),'privacy_text'=>$d['settings']['privacy'],'center'=>$d['settings']['center']];
 foreach(['zone','sector','contact','phone'] as $k)$r[$k]=field($k);
 $r['origin_company']=pc_invite_match($d,field('invite',64),$mail,$cid);$d['interest_requests'][$id]=$r;save_db($dir,$d);
 if($auto&&!send_link($cfg,$dir,$mail,'Confirme su solicitud FEOE','Ha solicitado información sobre colaboración educativa. Confirme el correo mediante el enlace (24 horas). La autorización de campañas es opcional y se confirma en el siguiente paso.','interest_verify',$t)){$d['interest_requests'][$id]['manual_review']=true;$d['interest_requests'][$id]['expires']=time()+30*86400;$d['interest_requests'][$id]['optin']=false;unset($d['interest_requests'][$id]['token']);save_db($dir,$d);}
 flash($generic);return '?v=landing#interes';
}
function pc_find(array $d,string $token):?string{
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;$hash=hash('sha256',$token);
 foreach($d['interest_requests']??[] as $id=>$r)if(!pc_reviewed($r)&&!pc_manual_pending($r)&&$r['expires']>=time()&&hash_equals($r['token']??'',$hash))return (string)$id;return null;
}
function pc_verify(array &$d):string{
 $id=pc_find($d,field('token',64));if(!$id)fail('Enlace inválido o caducado',403);
 $r=&$d['interest_requests'][$id];$r['verified_at']=date(DATE_ATOM);$r['optin']=$r['optin']&&field('campaign_optin')==='yes';unset($r['token']);
 audit($d,'Manifestación de interés verificada',$r['course']);flash('Correo verificado. El centro revisará la solicitud. No se ha creado una cuenta.');return '?v=landing#interes';
}
function pc_manual_review(array &$d):string{
 require_admin();$id=field('id');$r=$d['interest_requests'][$id]??null;
 if(!$r||pc_reviewed($r)||$r['expires']<time())fail('Solicitud no disponible para revisión');
 editable($d,$r['course']);$note=field('review_note',2000);
 if(!$note||field('confirmed')!=='yes')fail('Registre el contacto real y confirme la revisión');
 $r['manual_review']=true;$r['reviewed_at']=date(DATE_ATOM);$r['review_note']=$note;$r['optin']=false;unset($r['token']);
 $d['interest_requests'][$id]=$r;audit($d,'Solicitud de interés revisada manualmente',$r['course']);
 flash('Revisión manual registrada. No acredita verificación automática ni autoriza campañas.');
 return '?v=interests&c='.rawurlencode($r['course']);
}
function pc_approve(array &$d):string{
 require_admin();$id=field('id');$r=$d['interest_requests'][$id]??null;if(!$r||!pc_reviewed($r))fail('Solicitud sin verificar o revisar');
 $cid=$r['course'];editable($d,$cid);if(!isset($d['courses'][$cid]['groups'][$r['group']]))fail('Grupo no disponible');
 $eid=field('existing');if(!empty($r['origin_company'])&&$eid!==$r['origin_company'])fail('La solicitud tiene una ficha de origen identificada; revise esa ficha antes de aprobar',409);$e=['name'=>$r['name'],'nif'=>'','zone'=>$r['zone'],'sector'=>$r['sector'],'kind'=>field('kind'),'emails'=>$r['email'],'phone'=>$r['phone'],'contact'=>$r['contact'],'address'=>'','sites'=>[],'tutors'=>[]];
 if(!in_array($e['kind'],['Empresa','Organismo','Asociación'],true))fail('Tipo inválido');
 if($eid){$eid=master_id($eid);if(!company_active($d,$eid))fail('Empresa retirada',409);$e=company($d,$eid);if(!in_array($r['email'],camp_emails($e),true))fail('El correo declarado no figura en la ficha. Compruebe la identidad y actualice la ficha antes de vincular.');}
 else{if(imp_matches(imp_index($d['companies']),$e))fail('Posible empresa duplicada. Revise el directorio y vincule la ficha existente.');$eid=uid();$d['companies'][$eid]=$e;}
 $lid=group_link($d['links'][$cid]??[],$eid,$r['group']);
 if($lid===null){$lid=new_link_key($d['links'][$cid]??[],$eid,$r['group']);$l=imp_link($r['group']);$l['status']='Interesada';$l['requested']=$r['requested'];$d['links'][$cid][$lid]=$l;}
 if(camp_status_ok($d['links'][$cid][$lid]))$d['links'][$cid][$lid]['status']='Interesada';
 $d['links'][$cid][$lid]['history'][]=['at'=>date(DATE_ATOM),'type'=>!empty($r['verified_at'])?'Interés web verificado':'Interés web revisado manualmente','note'=>'Solicitud revisada; plazas orientativas: '.$r['requested'].'. '.$r['notes'].(!empty($r['review_note'])?' · Revisión: '.$r['review_note']:'')];
 $d['interest_evidence'][$id]=['email'=>$r['email'],'company'=>$eid,'course'=>$cid,'group'=>$r['group'],'created'=>$r['created'],'verified_at'=>$r['verified_at'],'approved_at'=>date(DATE_ATOM),'reviewed_at'=>$r['reviewed_at']??'','review_note'=>$r['review_note']??'','optin'=>!empty($r['verified_at'])&&$r['optin'],'text'=>$r['consent_text'],'privacy'=>$r['privacy_text'],'center'=>$r['center']];
 if(!empty($r['verified_at'])&&$r['optin']&&!isset($d['campaign_optouts'][$r['email']]))$d['campaign_permissions'][$eid][$r['email']]=['allowed'=>true,'evidence'=>'Formulario público verificado '.$r['verified_at'].'. '.$r['consent_text'].' Referencia '.$id,'at'=>date(DATE_ATOM),'source'=>'interest','reference'=>$id];
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
 echo '<section class="card"><h2>Solicitudes de interés empresarial</h2><p>Las solicitudes se pueden aprobar tras verificación por correo o tras una revisión manual documentada. Solo la confirmación expresa por correo puede generar un permiso automático de campañas; las bajas globales se respetan.</p>';
 $selected=(string)($_GET['record']??'');$base='?v=interests&c='.rawurlencode($cid);if($selected!=='')echo '<a class="button secondary" href="'.h($base).'">← Volver a solicitudes</a>';$n=0;foreach($d['interest_requests']??[] as $id=>$r){if($r['course']!==$cid||(!pc_reviewed($r)&&$r['expires']<time()))continue;$n++;if($selected===''){echo '<div class="record-toolbar"><strong>'.h($r['name'].' · '.$r['email']).'</strong><a class="button secondary" href="'.h($base.'&record='.rawurlencode((string)$id)).'">Abrir ficha</a></div>';continue;}if((string)$id!==$selected)continue;
 echo '<div class="record-card"><h2>'.h($r['name'].' · '.$r['email'].' · '.(!empty($r['verified_at'])?'Correo verificado':(!empty($r['reviewed_at'])?'Revisada manualmente':(pc_manual_pending($r)?'Pendiente de revisión manual':'Pendiente de verificar')))).'</h2><p>'.h($r['contact'].' · '.$r['phone'].' · '.$r['zone'].' · '.($d['courses'][$cid]['groups'][$r['group']]['name']??'').' · '.$r['requested'].' plazas orientativas').'</p><p>'.h($r['notes']).'</p><p>Autorización: '.($r['optin']&&$r['verified_at']?'Confirmada': 'No confirmada').'</p>';
 if(!pc_reviewed($r)&&!$d['courses'][$cid]['closed']){
 form_start('pc_manual_review',['id'=>$id]);area('review_note','Evidencia de contacto real (fecha, canal y persona o departamento)','');
 echo '<label class="check"><input type="checkbox" name="confirmed" value="yes" required> He contactado con la entidad y confirmado que la solicitud es auténtica. No concede autorización de campañas.</label>';end_form('Registrar revisión manual');
 }
 if(!empty($r['reviewed_at']))echo '<p>Revisión manual: '.h($r['reviewed_at'].' · '.$r['review_note']).'</p>';
 if(pc_reviewed($r)&&!$d['courses'][$cid]['closed']){
 $opts=[''=>'Crear ficha nueva (si no hay duplicados)'];foreach($d['companies'] as $eid=>$e)if(company_active($d,(string)$eid)&&in_array($r['email'],camp_emails($e),true))$opts[$eid]=$e['name'].' · '.$e['zone'];
 form_start('pc_approve',['id'=>$id]);select_field('existing','Empresa (revise identidad)',$opts,$r['origin_company']??'');if(!empty($r['origin_company']))echo '<p>Ficha de origen identificada mediante invitación individual y coincidencia de correo.</p>';select_field('kind','Tipo para alta nueva',['Empresa'=>'Empresa','Organismo'=>'Organismo','Asociación'=>'Asociación']);end_form('Aprobar solicitud');
 }
 form_start('pc_discard',['id'=>$id]);end_form('Descartar solicitud');echo '</div>';
 }if(!$n)echo '<p>No hay solicitudes pendientes en este curso.</p>';
 echo '<h3>Autorizaciones verificadas</h3>';foreach($d['interest_evidence']??[] as $id=>$r)if($r['course']===$cid&&$r['optin'])echo '<details><summary>'.h($r['email'].' · '.$r['verified_at']).'</summary><p>'.h($r['text']).'</p><p>'.h('Referencia: '.$id.' · '.$r['center'].' · '.(camp_allowed($d,$r['company'],$r['email'])?'Activa':'No activa')).'</p></details>';echo '</section>';
}
function pc_contact_ui(array $d,array $cfg,string $cid,string $eid):void{
 $e=company($d,$eid);if(($d['links'][$cid][$eid]['status']??'')==='Rechazada'){echo '<p>Colaboración rechazada: no se ofrece contacto de captación.</p>';return;}$mails=camp_emails($e);$base=rtrim($cfg['public_url']??'','/');
 echo '<h3>Primer contacto institucional individual</h3><p>Revise la finalidad y el destinatario. No es una campaña ni concede autorización. Use su correo institucional con las instrucciones del centro. No contacte direcciones dadas de baja.</p>';
 foreach($mails as $mail){if(isset($d['campaign_optouts'][$mail]))continue;
 $subject='Propuesta de colaboración educativa FEOE';$body=strtr(camp_default(),camp_tokens($d,$cfg+['public_url'=>$base],$cid,$eid,$mail));$body.="\n\nSi prefiere no recibir nuevas propuestas, comuníquenoslo.";
 echo '<details><summary>'.h($mail).'</summary>';echo '<p><a class="button secondary" href="'.h('mailto:'.$mail.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body)).'">Abrir correo institucional</a></p>';
 echo '<label>Asunto<input readonly value="'.h($subject).'"></label><label>Texto para copiar<textarea readonly rows="8">'.h($body).'</textarea></label>';echo '</details>';
 }
}
function pc_action(string $action,array &$d,array $cfg,string $dir,string $cid,string $eid):string{
 if($action==='pc_verify')return pc_verify($d);if($action==='pc_first_prepare')return pc_first_prepare($d,$cfg,$cid);if($action==='pc_first_queue')return pc_first_queue($d,$cfg,$cid);if($action==='pc_first_send')return pc_first_send($d,$cfg,$dir,$cid,$eid);
 require_admin();
 if($action==='pc_manual_review')return pc_manual_review($d);
 if($action==='pc_approve')return pc_approve($d);
 if($action==='pc_discard'){$id=field('id');if(!isset($d['interest_requests'][$id]))fail('Solicitud inexistente',404);$c=$d['interest_requests'][$id]['course'];unset($d['interest_requests'][$id]);audit($d,'Solicitud de interés descartada',$c);return '?v=interests&c='.rawurlencode($c);}
 if(in_array($action,['pc_smtp_settings','pc_smtp_test','pc_contact'],true))fail('SMTP institucional retirado. Utilice Preparar correo.',410);
 fail('Acción desconocida');
}

function pc_first_candidates(array $d,string $cid):array{
 $rows=[];foreach($d['companies'] as $id=>$e){$id=(string)$id;if(!company_active($d,$id))continue;
 $mails=array_values(array_filter(camp_emails($e),fn($m)=>!isset($d['campaign_optouts'][$m])));
 $r=$d['first_contact'][$id]??[];$state=$r['state']??'';$links=company_links($d,$cid,$id);
 $blocked=false;foreach($links as $l)if(in_array($l['status']??'',['Rechazada','Interesada','En negociación','Confirmada','Convenio formalizado'],true)||!empty($l['confirmed']))$blocked=true;
 $used=[];foreach($d['first_contact']??[] as $old)if(in_array($old['state']??'',['accepted','attempted','uncertain','queued'],true))$used[$old['email']]=true;
 $mails=array_values(array_filter($mails,fn($m)=>!isset($used[$m])));
 $status=match($state){'accepted'=>'Aceptado por Resend','queued'=>'En cola','attempted','uncertain'=>'Revisar resultado','cancelled'=>'Cancelado',default=>$blocked?'No elegible':(!$mails?'Sin correo disponible':'Pendiente de autorización')};
 $rows[$id]=['name'=>$e['name'],'entity'=>$e,'mails'=>$mails,'status'=>$status];
 }uasort($rows,fn($a,$b)=>strnatcasecmp(imp_norm($a['name']),imp_norm($b['name'])));return $rows;
}
function pc_first_contacts_ui(array $d,array $cfg,string $cid):void{
 $rows=pc_first_candidates($d,$cid);$ready=mail_ready($cfg)&&pc_open($d,$cfg)&&$cid===$d['settings']['active']&&!course($d,$cid)['closed'];
 $totals=array_count_values(array_column($rows,'status'));
 echo '<section class="card"><h2>Centro de primer contacto</h2><p>1. Seleccionar destinatarios autorizados · 2. Revisar mensajes · 3. Confirmar cola · 4. Consultar resultados.</p><div class="metrics">';foreach(['Pendiente de autorización','En cola','Aceptado por Resend','Revisar resultado'] as $st)panel_metric($st,$totals[$st]??0);echo '</div>';
 if(!$ready)echo '<p class="notice">Para enviar, configure Resend, publique el formulario y seleccione el curso activo abierto.</p>';
 if(isset($_GET['review'])){$b=$_SESSION['pc_first_batch']??null;if(!$b||$b['course']!==$cid||$b['expires']<time()){echo '<p>La preparación ha caducado.</p></section>';return;}
 echo '<h3>2. Revisar mensajes personalizados</h3><p>Compruebe que cada destinatario ha autorizado realmente esta invitación. No se concede autorización de seguimiento.</p>';
 form_start('pc_first_queue',['c'=>$cid,'batch'=>$b['token']]);foreach($b['rows'] as $id=>$mail){$e=company($d,$id);$t=pc_first_template($d,$cfg,$cid,$e);echo '<details><summary>'.h($e['name'].' · '.$mail).'</summary><p>'.h($t['subject']).'</p><pre class="pre">'.h($t['body']."\n\n[Enlace individual generado al enviar]").'</pre></details><label class="check"><input type="checkbox" name="authorized[]" value="'.h($id).'"> Confirmo autorización de '.h($mail).'</label>';}
 echo '<p>Solo se pondrán en cola las invitaciones confirmadas individualmente.</p>';end_form('3. Confirmar y poner en cola');echo '</section>';return;}
 echo '<h3>1. Seleccionar empresas</h3>';form_start('pc_first_prepare',['c'=>$cid]);echo '<div class="fields"><label>Buscar<input id="search" placeholder="Empresa, localidad, sector o correo"></label>';directory_location_filters();echo '<label>Estado<select id="status-filter"><option value="">Todos</option>';foreach(array_keys($totals) as $st)echo '<option>'.h($st).'</option>';echo '</select></label></div><p id="directory-count" role="status"></p><div class="scroll"><table id="directory"><thead><tr><th>Seleccionar</th><th>Empresa</th><th>Correo de destino</th><th>Estado</th></tr></thead><tbody>';
 foreach($rows as $id=>$r){$e=$r['entity'];echo '<tr data-locations="'.directory_location_attrs($e).'" data-status="'.h($r['status']).'"><td>';if($ready&&$r['status']==='Pendiente de autorización')echo '<input type="checkbox" name="selected[]" value="'.h($id).'">';echo '</td><td><a href="'.h('?v=directory&mode=master&c='.$cid.'&e='.$id).'">'.h($r['name']).'</a><small>'.h(($e['zone']??'').' · '.($e['sector']??'')).'</small></td><td>';if($r['status']==='Pendiente de autorización') {echo '<select name="recipient['.h($id).']">';foreach($r['mails'] as $m)echo '<option>'.h($m).'</option>';echo '</select>';}else echo h($d['first_contact'][$id]['email']??'—');echo '</td><td>'.h($r['status']).'</td></tr>';}
 echo '</tbody></table></div>';if($ready)end_form('2. Revisar invitaciones seleccionadas');else echo '</form>';echo '<p>Los envíos se realizan individualmente por el trabajador de la aplicación. Un resultado incierto no se reintenta automáticamente.</p></section>';
}
function pc_first_template(array $d,array $cfg,string $cid,array $e):array{
 $key=match($e['kind']??'Empresa'){'Organismo'=>'organismo','Asociación'=>'asociacion',default=>'empresa'};$t=mc_templates($d,$cfg,$cid)[$key];return ['subject'=>$t['subject'],'body'=>"A la atención de ".$e['name']."\n\n".$t['body']];
}
function pc_first_prepare(array &$d,array $cfg,string $cid):string{
 require_admin();editable($d,$cid);if(!mail_ready($cfg)||!pc_open($d,$cfg)||$cid!==$d['settings']['active'])fail('Servicio no disponible');
 $ids=$_POST['selected']??[];$mails=$_POST['recipient']??[];if(!is_array($ids)||!is_array($mails)||!$ids||count($ids)>50)fail('Seleccione de 1 a 50 empresas');
 $candidates=pc_first_candidates($d,$cid);$rows=[];$seen=[];foreach(array_unique($ids) as $id){if(!is_string($id)||!isset($candidates[$id])||$candidates[$id]['status']!=='Pendiente de autorización')fail('Empresa no disponible');$m=$mails[$id]??'';if(!is_string($m)||!in_array($m,$candidates[$id]['mails'],true)||isset($seen[$m]))fail('Correo no disponible o duplicado');$seen[$m]=true;$rows[$id]=$m;}
 $_SESSION['pc_first_batch']=['token'=>uid(),'course'=>$cid,'expires'=>time()+1800,'rows'=>$rows];return '?v=first_contacts&c='.rawurlencode($cid).'&review=1';
}
function pc_first_queue(array &$d,array $cfg,string $cid):string{
 require_admin();editable($d,$cid);if(!mail_ready($cfg)||!pc_open($d,$cfg)||$cid!==$d['settings']['active'])fail('Servicio no disponible');
 $b=$_SESSION['pc_first_batch']??null;$ids=$_POST['authorized']??[];if(!$b||$b['course']!==$cid||$b['expires']<time()||!hash_equals($b['token'],field('batch'))||!is_array($ids)||!$ids||count($ids)>50)fail('Preparación no válida o sin autorizaciones');
 $candidates=pc_first_candidates($d,$cid);$seen=[];foreach(array_unique($ids) as $id){if(!is_string($id)||!isset($b['rows'][$id])||($candidates[$id]['status']??'')!=='Pendiente de autorización'||!in_array($b['rows'][$id],$candidates[$id]['mails'],true)||isset($seen[$b['rows'][$id]]))fail('Destinatario no disponible');$seen[$b['rows'][$id]]=true;}
 foreach(array_unique($ids) as $id){$d['first_contact'][$id]=['email'=>$b['rows'][$id],'course'=>$cid,'state'=>'queued','at'=>date(DATE_ATOM),'authorized_by'=>'admin','scope'=>'one_invitation'];audit($d,'Autorización individual confirmada y en cola',$cid,$id);}
 unset($_SESSION['pc_first_batch']);flash('Invitaciones autorizadas en cola.');return '?v=first_contacts&c='.rawurlencode($cid);
}
/* One authorized invitation per company; independent of campaign consent. */
function pc_invite_match(array $d,string $token,string $mail,string $cid):string{
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))return '';
 $r=$d['first_invites'][hash('sha256',$token)]??null;
 return $r&&($r['expires']??0)>=time()&&$r['course']===$cid&&$r['email']===$mail&&company_active($d,$r['company'])?$r['company']:'';
}
function pc_first_ui(array $d,array $cfg,string $cid,string $eid):void{
 $eid=master_id($eid);if(!company_active($d,$eid))return;
 $e=company($d,$eid);$r=$d['first_contact'][$eid]??[];
 echo '<section class="card"><h3>Invitación individual personalizada por Resend</h3>';
 if(!empty($r['state']))echo '<p>Estado: '.h($r['state'].' · '.($r['at']??'')).'</p>';
 if(in_array($r['state']??'',['accepted','attempted','uncertain','queued'],true)){echo '<p>No se permite repetir esta invitación. La autorización posterior se obtiene en el formulario.</p></section>';return;}
 $mails=array_values(array_filter(camp_emails($e),fn($m)=>!isset($d['campaign_optouts'][$m])));
 if(!$mails||!mail_ready($cfg)||!pc_open($d,$cfg)){echo '<p>Requiere correo válido, Resend configurado y formulario publicado.</p></section>';return;}
 form_start('pc_first_send',['c'=>$cid,'e'=>$eid]);select_field('email','Destinatario',array_combine($mails,$mails));
 echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo que este destinatario ha autorizado expresamente recibir esta invitación individual. No autoriza comunicaciones posteriores.</label>';
 end_form('Autorizar y enviar una invitación individual');echo '</section>';
}
function pc_first_send(array &$d,array $cfg,string $dir,string $cid,string $eid):string{
 require_admin();editable($d,$cid);$eid=master_id($eid);
 if(!company_active($d,$eid)||!mail_ready($cfg)||!pc_open($d,$cfg)||$cid!==$d['settings']['active']||field('confirm')!=='yes')fail('Invitación no autorizada o servicio no disponible',403);
 $e=company($d,$eid);$mail=email('email');
 if(!in_array($mail,camp_emails($e),true)||isset($d['campaign_optouts'][$mail])||in_array($d['first_contact'][$eid]['state']??'',['accepted','attempted','uncertain','queued'],true))fail('Destinatario no disponible o invitación ya utilizada',409);
 foreach(company_links($d,$cid,$eid) as $l)if(($l['status']??'')==='Rechazada')fail('Colaboración rechazada',409);
 foreach($d['first_contact']??[] as $old)if(($old['email']??'')===$mail&&in_array($old['state']??'',['accepted','attempted','uncertain','queued'],true))fail('Este correo ya recibió una invitación o tiene un envío pendiente de revisión',409);
 $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$url=rtrim($cfg['public_url'],'/').'/index.php?v=landing&invite='.$token.'#interes';
 $key=match($e['kind']??'Empresa'){'Organismo'=>'organismo','Asociación'=>'asociacion',default=>'empresa'};
 $t=mc_templates($d,$cfg,$cid)[$key];
 $subject=$t['subject'];$body="A la atención de ".$e['name']."\n\n".$t['body'];
 $body=str_replace(rtrim($cfg['public_url'],'/').'/index.php?v=landing#interes',$url,$body);
 $body.="\n\nEnlace individual para manifestar su interés: ".$url."\nEste enlace no concede acceso privado ni confirma plazas.";
 $d['first_invites'][$hash]=['company'=>$eid,'email'=>$mail,'course'=>$cid,'expires'=>time()+30*86400];
 $d['first_contact'][$eid]=['email'=>$mail,'state'=>'attempted','at'=>date(DATE_ATOM),'authorized_by'=>'admin','scope'=>'one_invitation','reference'=>$hash];
 audit($d,'Autorización individual de primer contacto confirmada',$cid,$eid);save_db($dir,$d);
 $ok=resend_mail($cfg,$dir,$mail,$subject,$body,'first-'.hash('sha256',$eid.$mail));
 $d['first_contact'][$eid]['state']=$ok?'accepted':'uncertain';audit($d,$ok?'Invitación individual aceptada por Resend':'Resultado de invitación incierto; no repetir automáticamente',$cid,$eid);
 flash($ok?'Invitación aceptada por Resend. No autoriza campañas posteriores.':'Envío no confirmado. Revise Resend antes de cualquier nuevo contacto.');
 return '?v=directory&mode=master&c='.rawurlencode($cid).'&e='.rawurlencode($eid);
}
