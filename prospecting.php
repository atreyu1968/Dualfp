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
 if($eid){$eid=master_id($eid);if(!company_active($d,$eid))fail('Empresa retirada',409);$e=company($d,$eid);if(!in_array($r['email'],camp_emails($e),true)){if(field('add_contact')!=='yes')fail('Confirme la identidad y la incorporación del nuevo correo profesional');$d['companies'][$eid]['emails']=implode('; ',array_unique(array_merge(camp_emails($e),[$r['email']])));$e=$d['companies'][$eid];}}
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
 $opts=[''=>'Crear ficha nueva (si no hay duplicados)'];$matches=[];$others=[];foreach($d['companies'] as $eid=>$e)if(company_active($d,(string)$eid)){$label=$e['name'].' · '.$e['zone'];if(in_array($r['email'],camp_emails($e),true)||imp_norm($r['name'])===imp_norm($e['name'])||(!empty($r['phone'])&&preg_replace('/[^0-9]/','',$r['phone'])===preg_replace('/[^0-9]/','',$e['phone']??'')))$matches[$eid]='Posible coincidencia: '.$label;else $others[$eid]=$label;}asort($matches);asort($others);$opts+=$matches+$others;
 form_start('pc_approve',['id'=>$id]);select_field('existing','Empresa (revise identidad)',$opts,$r['origin_company']??'');if(!empty($r['origin_company']))echo '<p>Ficha de origen identificada mediante invitación individual y coincidencia de correo.</p>';select_field('kind','Tipo para alta nueva',['Empresa'=>'Empresa','Organismo'=>'Organismo','Asociación'=>'Asociación']);echo '<label class="check"><input type="checkbox" name="add_contact" value="yes"> He comprobado la identidad y autorizo incorporar el correo declarado si es nuevo (no concede permiso de campaña).</label>';end_form('Aprobar solicitud');
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
 if($action==='pc_verify')return pc_verify($d);if(in_array($action,['pc_first_settings','pc_first_cancel','pc_first_stop','pc_first_follow'],true))return pc_first_control($action,$d,$cfg,$cid,$eid);if($action==='pc_first_prepare')return pc_first_prepare($d,$cfg,$cid);if($action==='pc_first_queue')return pc_first_queue($d,$cfg,$cid);if($action==='pc_first_send')return pc_first_send($d,$cfg,$dir,$cid,$eid);
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
 $status=match($state){'accepted'=>match($r['delivery']??''){'email.delivered'=>'Entregado','email.bounced'=>'Rebotado','email.complained'=>'Queja de spam','email.delivery_delayed'=>'Entrega demorada','email.failed'=>'Fallido',default=>'Aceptado por Resend'},'queued'=>'En cola','attempted','uncertain'=>'Revisar resultado','cancelled'=>'Cancelado',default=>$blocked?'No elegible':(!$mails?'Sin correo disponible':'Pendiente de autorización')};
 $rows[$id]=['name'=>$e['name'],'entity'=>$e,'mails'=>$mails,'status'=>!camp_emails($e)?'Sin correo válido':(camp_block_reasons($d,$e)&&!array_diff(camp_emails($e),array_keys(camp_block_reasons($d,$e)))?'Correo bloqueado':$status),'no_email'=>!camp_emails($e),'blocked'=>camp_block_label($d,$e)];
 }uasort($rows,fn($a,$b)=>strnatcasecmp(imp_norm($a['name']),imp_norm($b['name'])));return $rows;
}
function pc_first_contacts_ui(array $d,array $cfg,string $cid):void{
 $rows=pc_first_candidates($d,$cid);$ready=mail_ready($cfg)&&pc_open($d,$cfg)&&$cid===$d['settings']['active']&&!course($d,$cid)['closed'];
 $totals=array_count_values(array_column($rows,'status'));
 echo '<section class="card"><h2>Centro de primer contacto</h2><p>1. Seleccionar destinatarios autorizados · 2. Revisar mensajes · 3. Confirmar cola · 4. Consultar resultados.</p><div class="metrics">';foreach(['Pendiente de autorización','En cola','Aceptado por Resend','Entregado','Rebotado','Queja de spam','Revisar resultado','Sin correo válido'] as $st)panel_metric($st,$totals[$st]??0);echo '</div>';
 if(!$ready)echo '<p class="notice">Para enviar, configure Resend, publique el formulario y seleccione el curso activo abierto.</p>';
 if(isset($_GET['review'])){$b=$_SESSION['pc_first_batch']??null;if(!$b||$b['course']!==$cid||$b['expires']<time()){echo '<p>La preparación ha caducado.</p></section>';return;}
 echo '<h3>2. Revisar mensajes personalizados</h3><p>Compruebe que cada destinatario ha autorizado realmente esta invitación. No se concede autorización de seguimiento.</p>';
 form_start('pc_first_queue',['c'=>$cid,'batch'=>$b['token']]);foreach($b['rows'] as $id=>$mail){$e=company($d,$id);$t=pc_first_template($d,$cfg,$cid,$e);echo '<details><summary>'.h($e['name'].' · '.$mail).'</summary><p>'.h($t['subject']).'</p><pre class="pre">'.h($t['body']."\n\n[Enlace individual generado al enviar]").'</pre></details><label class="check"><input type="checkbox" name="authorized[]" value="'.h($id).'" checked> Incluir invitación a '.h($mail).'</label>';}
 echo '<p>Desmarque los destinatarios que no deban recibir la invitación.</p><label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo que todos los destinatarios que permanecen seleccionados han autorizado expresamente recibir esta primera invitación. No autoriza comunicaciones posteriores.</label><label class="check"><input type="checkbox" name="weekly" value="yes"> Confirmo adicionalmente que todos los seleccionados han autorizado recordatorios cada siete días hasta que respondan o soliciten el cese.</label>';end_form('3. Confirmar y poner en cola');echo '</section>';return;}
 echo '<h3>Control de envíos</h3>';form_start('pc_first_settings',['c'=>$cid]);select_field('paused','Estado de la cola',['yes'=>'Pausada','no'=>'En funcionamiento'],!empty($d['first_settings']['paused'])?'yes':'no');input('daily_limit','Máximo diario de invitaciones (1–150)',$d['first_settings']['daily_limit']??150,'number',true);input('contact_phone','Teléfono de contacto en la plantilla',$d['settings']['first_contact_phone']??'','tel');end_form('Guardar control de cola');echo '<p>Intervalo mínimo: 60 segundos entre intentos. El límite diario debe ajustarse a la cuota disponible de Resend. La pausa afecta solo a la cola de primer contacto.</p>';foreach($d['first_contact']??[] as $id=>$r)if(($r['state']??'')==='queued'&&($r['course']??'')===$cid){form_start('pc_first_cancel',['c'=>$cid,'e'=>$id]);echo '<p>'.h($d['companies'][$id]['name']??$id).' · '.h($r['email']).'</p>';echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Cancelar esta invitación pendiente</label>';end_form('Cancelar');}echo '<h3>1. Seleccionar empresas</h3>';form_start('pc_first_prepare',['c'=>$cid]);echo '<div class="actions"><button type="button" class="secondary" id="pc-select-visible">Seleccionar visibles (máximo 50)</button><button type="button" class="secondary" id="pc-clear-selection">Limpiar selección</button><span id="pc-selection-count" role="status"></span></div>';echo '<div class="fields"><label>Buscar<input id="search" placeholder="Empresa, localidad, sector o correo"></label>';directory_location_filters();echo '<label>Estado<select id="status-filter"><option value="">Todos</option>';foreach(array_keys($totals) as $st)echo '<option>'.h($st).'</option>';echo '</select></label></div><p id="directory-count" role="status"></p><div class="scroll"><table id="directory"><thead><tr><th>Seleccionar</th><th>Empresa</th><th>Correo de destino</th><th>Estado</th></tr></thead><tbody>';
 foreach($rows as $id=>$r){$e=$r['entity'];echo '<tr class="'.($r['blocked']?'blocked-email':($r['no_email']?'missing-email':'')).'" data-locations="'.directory_location_attrs($e).'" data-status="'.h($r['status']).'"><td>';if($ready&&$r['status']==='Pendiente de autorización')echo '<input type="checkbox" name="selected[]" value="'.h($id).'">';echo '</td><td><a href="'.h('?v=directory&mode=master&c='.$cid.'&e='.$id).'">'.h($r['name']).'</a><small>'.h(($e['zone']??'').' · '.($e['sector']??'')).'</small></td><td>';if($r['status']==='Pendiente de autorización') {echo '<select name="recipient['.h($id).']">';foreach($r['mails'] as $m)echo '<option>'.h($m).'</option>';echo '</select>';}else if($r['no_email'])echo '<span class="email-warning">Sin correo válido · completar ficha</span>';else echo h($d['first_contact'][$id]['email']??'—');if($r['blocked'])echo '<span class="email-block-warning">Correo bloqueado: '.h($r['blocked']).'</span>';echo '</td><td>'.h($r['status']);$fc=$d['first_contact'][$id]??[];if(!empty($fc['attempts']))echo '<small>'.h($fc['attempts'].' intentos · último '.$fc['result_at']).'</small>';if(!empty($fc['stopped']))echo '<small>'.h($fc['stopped']).'</small>';elseif(($fc['scope']??'')==='weekly_invitation'&&($fc['state']??'')==='accepted'&&!empty($fc['next_at']))echo '<small>'.h('Próximo: '.date('d/m/Y H:i',$fc['next_at'])).'</small>';echo '</td></tr>';}
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
 $b=$_SESSION['pc_first_batch']??null;$ids=$_POST['authorized']??[];if(field('confirm')!=='yes')fail('Confirme la autorización real de los destinatarios seleccionados',403);if(!$b||$b['course']!==$cid||$b['expires']<time()||!hash_equals($b['token'],field('batch'))||!is_array($ids)||!$ids||count($ids)>50)fail('Preparación no válida o sin autorizaciones');
 $candidates=pc_first_candidates($d,$cid);$seen=[];foreach(array_unique($ids) as $id){if(!is_string($id)||!isset($b['rows'][$id])||($candidates[$id]['status']??'')!=='Pendiente de autorización'||!in_array($b['rows'][$id],$candidates[$id]['mails'],true)||isset($seen[$b['rows'][$id]]))fail('Destinatario no disponible');$seen[$b['rows'][$id]]=true;}
 foreach(array_unique($ids) as $id){$d['first_contact'][$id]=['email'=>$b['rows'][$id],'course'=>$cid,'state'=>'queued','at'=>date(DATE_ATOM),'authorized_by'=>'admin','scope'=>field('weekly')==='yes'?'weekly_invitation':'one_invitation'];audit($d,'Autorización individual confirmada y en cola',$cid,$id);}
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
 $e=company($d,$eid);$r=$d['first_contact'][$eid]??[];if(!camp_emails($e)){echo '<p class="email-warning">Sin correo válido. Añada y guarde una dirección para habilitar el primer contacto.</p>';return;}
 echo '<section class="card"><h3>Invitación individual personalizada por Resend</h3>';
 if(!empty($r['state']))echo '<p>Estado: '.h($r['state'].' · '.($r['at']??'')).'</p>';if(($r['state']??'')==='accepted'){if(($r['scope']??'')==='weekly_invitation'&&empty($r['stopped'])){echo '<p>Próximo recordatorio: '.h(date('d/m/Y H:i',(int)($r['next_at']??time()))).'</p>';form_start('pc_first_stop',['c'=>$cid,'e'=>$eid]);end_form('Detener recordatorios');}elseif(empty($r['stopped'])){form_start('pc_first_follow',['c'=>$cid,'e'=>$eid]);echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo autorización expresa de recordatorios semanales hasta respuesta o baja.</label>';end_form('Activar recordatorios');}}
 if(in_array($r['state']??'',['accepted','attempted','uncertain','queued'],true)){echo '<p>La invitación inicial no se duplica. Los recordatorios requieren autorización específica; las campañas requieren consentimiento del formulario.</p></section>';return;}
 $mails=array_values(array_filter(camp_emails($e),fn($m)=>!isset($d['campaign_optouts'][$m])));
 if(!$mails||!mail_ready($cfg)||!pc_open($d,$cfg)){echo '<p>Requiere correo válido, Resend configurado y formulario publicado.</p></section>';return;}
 form_start('pc_first_send',['c'=>$cid,'e'=>$eid]);select_field('email','Destinatario',array_combine($mails,$mails));
 echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo que este destinatario ha autorizado expresamente recibir esta invitación individual. No autoriza comunicaciones posteriores.</label><label class="check"><input type="checkbox" name="weekly" value="yes"> Confirmo adicionalmente autorización expresa de recordatorios semanales hasta respuesta o baja.</label>';
 end_form('Autorizar y poner en cola una invitación individual');echo '</section>';
}
function pc_first_send(array &$d,array $cfg,string $dir,string $cid,string $eid):string{
 require_admin();editable($d,$cid);$eid=master_id($eid);
 if(!company_active($d,$eid)||!mail_ready($cfg)||!pc_open($d,$cfg)||$cid!==$d['settings']['active']||field('confirm')!=='yes')fail('Invitación no autorizada o servicio no disponible',403);
 $e=company($d,$eid);$mail=email('email');
 if(!in_array($mail,camp_emails($e),true)||isset($d['campaign_optouts'][$mail])||in_array($d['first_contact'][$eid]['state']??'',['accepted','attempted','uncertain','queued'],true))fail('Destinatario no disponible o invitación ya utilizada',409);
 foreach(company_links($d,$cid,$eid) as $l)if(($l['status']??'')==='Rechazada')fail('Colaboración rechazada',409);
 foreach($d['first_contact']??[] as $old)if(($old['email']??'')===$mail&&in_array($old['state']??'',['accepted','attempted','uncertain','queued'],true))fail('Este correo ya recibió una invitación o tiene un envío pendiente de revisión',409);
 $candidates=pc_first_candidates($d,$cid);if(($candidates[$eid]['status']??'')!=='Pendiente de autorización'||!in_array($mail,$candidates[$eid]['mails'],true))fail('Destinatario no disponible',409);
 $d['first_contact'][$eid]=['email'=>$mail,'course'=>$cid,'state'=>'queued','at'=>date(DATE_ATOM),'authorized_by'=>'admin','scope'=>field('weekly')==='yes'?'weekly_invitation':'one_invitation'];audit($d,'Invitación individual autorizada y en cola',$cid,$eid);
 flash('Invitación incorporada a la cola. Se respetarán el intervalo y el límite diario.');return '?v=directory&mode=master&c='.rawurlencode($cid).'&e='.rawurlencode($eid);
}

function pc_first_tick(array &$d,array $cfg,string $dir):bool{
 if(!mail_ready($cfg)||!pc_open($d,$cfg)||!empty($d['first_settings']['paused']))return false;
 if(time()-(int)($d['first_last_attempt']??0)<60)return false;
 $today=date('Y-m-d');$count=0;foreach($d['first_contact']??[] as $r)if(($r['attempt_day']??'')===$today)$count++;
 if($count>=max(1,min(150,(int)($d['first_settings']['daily_limit']??150))))return false;
 foreach($d['first_contact']??[] as $eid=>$r){$repeat=($r['state']??'')==='accepted'&&($r['scope']??'')==='weekly_invitation'&&empty($r['stopped'])&&!empty($r['next_at'])&&$r['next_at']<=time();if(!empty($r['stopped'])||(($r['state']??'')!=='queued'&&!$repeat))continue;
 $eid=(string)$eid;$cid=$r['course'];$mail=$r['email'];
 $valid=company_active($d,$eid)&&$cid===$d['settings']['active']&&empty($d['courses'][$cid]['closed'])&&!isset($d['campaign_optouts'][$mail]);
 if($valid){$e=company($d,$eid);$valid=in_array($mail,camp_emails($e),true);foreach(company_links($d,$cid,$eid) as $l)if(in_array($l['status']??'',['Rechazada','Interesada','En negociación','Confirmada','Convenio formalizado'],true)||!empty($l['confirmed']))$valid=false;}
 if($valid&&pc_first_has_response($d,$eid,$mail,$cid)){$d['first_contact'][$eid]['stopped']='Respuesta registrada';if(!$repeat)$d['first_contact'][$eid]['state']='cancelled';save_db($dir,$d);continue;}if(!$valid){$d['first_contact'][$eid]['state']='cancelled';save_db($dir,$d);continue;}
 $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);$base=rtrim($cfg['public_url'],'/').'/index.php?v=landing';$url=$base.'&invite='.$token.'#interes';
 $t=pc_first_template($d,$cfg,$cid,$e);$body=str_replace($base.'#interes',$url,$t['body']);$body.="\n\nEnlace individual para manifestar su interés: ".$url."\nEste enlace no concede acceso privado ni confirma plazas.";$opt=camp_opt_token($d,$mail);$body.="\n\nPara no recibir más invitaciones: ".rtrim($cfg['public_url'],'/').'/index.php?v=unsubscribe&m='.rawurlencode($mail).'&t='.$opt;
 $d['first_invites'][$hash]=['company'=>$eid,'email'=>$mail,'course'=>$cid,'expires'=>time()+30*86400];
 if(!empty($r['provider_id']))$d['first_message_history'][$r['provider_id']]=['company'=>$eid,'email'=>$mail,'course'=>$cid];unset($d['first_contact'][$eid]['provider_id'],$d['first_contact'][$eid]['delivery'],$d['first_contact'][$eid]['delivery_at']);$d['first_contact'][$eid]['reference']=$hash;$d['first_contact'][$eid]['state']='attempted';$d['first_contact'][$eid]['attempt_day']=$today;$d['first_last_attempt']=time();save_db($dir,$d);
 $ok=resend_mail($cfg,$dir,$mail,$t['subject'],$body,'first-'.hash('sha256',$eid.$mail.$hash),$providerId);if($providerId)$d['first_contact'][$eid]['provider_id']=$providerId;
 $d['first_contact'][$eid]['state']=$ok?'accepted':'uncertain';$d['first_contact'][$eid]['result_at']=date(DATE_ATOM);$d['first_contact'][$eid]['attempts']=($r['attempts']??0)+1;$d['first_contact'][$eid]['next_at']=$ok?time()+7*86400:0;audit($d,$ok?'Invitación individual aceptada por Resend':'Resultado incierto de invitación individual',$cid,$eid);save_db($dir,$d);return true;
 }return false;
}

function pc_first_control(string $action,array &$d,array $cfg,string $cid,string $eid):string{
 require_admin();editable($d,$cid);
 if($action==='pc_first_settings'){$n=integer('daily_limit',150);if($n<1)fail('Límite inválido');$phone=field('contact_phone',60);if($phone!==''&&!preg_match('/^[0-9+().\s-]{5,60}$/D',$phone))fail('Teléfono inválido');$d['settings']['first_contact_phone']=$phone;$d['first_settings']=['paused'=>field('paused')==='yes','daily_limit'=>$n];audit($d,'Control de cola actualizado',$cid);}
 elseif($action==='pc_first_stop'||$action==='pc_first_follow'){if(!isset($d['first_contact'][$eid])||($d['first_contact'][$eid]['course']??'')!==$cid)fail('Invitación no disponible');$r=&$d['first_contact'][$eid];if($action==='pc_first_stop'){$r['stopped']='Detenido por administrador';}else{if(field('confirm')!=='yes'||$r['state']!=='accepted'||!company_active($d,$eid)||isset($d['campaign_optouts'][$r['email']])||pc_first_has_response($d,$eid,$r['email'],$cid))fail('Recordatorio no autorizado');$r['scope']='weekly_invitation';unset($r['stopped']);$r['next_at']=time()+7*86400;}audit($d,'Control de recordatorios actualizado',$cid,$eid);}else{if(field('confirm')!=='yes'||($d['first_contact'][$eid]['state']??'')!=='queued'||($d['first_contact'][$eid]['course']??'')!==$cid)fail('Invitación no cancelable');$d['first_contact'][$eid]['state']='cancelled';audit($d,'Invitación cancelada',$cid,$eid);}
 return '?v=first_contacts&c='.rawurlencode($cid);
}

function pc_first_has_response(array $d,string $eid,string $mail,string $cid):bool{foreach($d['interest_requests']??[] as $r)if(($r['course']??'')===$cid&&(($r['origin_company']??'')===$eid||($r['email']??'')===$mail))return true;foreach($d['interest_evidence']??[] as $r)if(($r['course']??'')===$cid&&(($r['company']??'')===$eid||($r['email']??'')===$mail))return true;foreach($d['inbox']??[] as $r)if(($r['from']??'')===$mail)return true;return false;}
