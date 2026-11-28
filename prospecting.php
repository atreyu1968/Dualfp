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
 $d['interest_requests'][$id]=$r;save_db($dir,$d);
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
 $eid=field('existing');$e=['name'=>$r['name'],'nif'=>'','zone'=>$r['zone'],'sector'=>$r['sector'],'kind'=>field('kind'),'emails'=>$r['email'],'phone'=>$r['phone'],'contact'=>$r['contact'],'address'=>'','sites'=>[],'tutors'=>[]];
 if(!in_array($e['kind'],['Empresa','Organismo','Asociación'],true))fail('Tipo inválido');
 if($eid){$eid=master_id($eid);$e=company($d,$eid);if(!in_array($r['email'],camp_emails($e),true))fail('El correo declarado no figura en la ficha. Compruebe la identidad y actualice la ficha antes de vincular.');}
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
 form_start('pc_approve',['id'=>$id]);select_field('existing','Empresa (revise identidad)',$opts);select_field('kind','Tipo para alta nueva',['Empresa'=>'Empresa','Organismo'=>'Organismo','Asociación'=>'Asociación']);end_form('Aprobar solicitud');
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
 if($action==='pc_verify')return pc_verify($d);
 require_admin();
 if($action==='pc_manual_review')return pc_manual_review($d);
 if($action==='pc_approve')return pc_approve($d);
 if($action==='pc_discard'){$id=field('id');if(!isset($d['interest_requests'][$id]))fail('Solicitud inexistente',404);$c=$d['interest_requests'][$id]['course'];unset($d['interest_requests'][$id]);audit($d,'Solicitud de interés descartada',$c);return '?v=interests&c='.rawurlencode($c);}
 if(in_array($action,['pc_smtp_settings','pc_smtp_test','pc_contact'],true))fail('SMTP institucional retirado. Utilice Preparar correo.',410);
 fail('Acción desconocida');
}

function pc_first_contacts_ui(array $d,string $cid):void{
 $c=course($d,$cid);$closed=$c['closed'];$rows=[];$totals=['Pendiente de primer contacto'=>0,'Contacto registrado'=>0,'Campañas autorizadas'=>0,'Colaboración en curso'=>0,'No contactar'=>0,'Sin correo'=>0];
 foreach(active_company_links($d,$cid) as $eid=>$l){
  $e=$l['snapshot']??company($d,(string)$eid);$mails=camp_emails($e);$available=array_values(array_filter($mails,fn($m)=>!isset($d['campaign_optouts'][$m])));$allowed=array_filter($available,fn($m)=>camp_allowed($d,(string)$eid,$m));
  $status=(!$mails?'Sin correo':(!$available?'No contactar':(in_array($l['status'],['Interesada','En negociación','Confirmada','Convenio formalizado'],true)||$l['confirmed']>0?'Colaboración en curso':($l['status']==='Rechazada'?'No contactar':($allowed?'Campañas autorizadas':($l['history']||in_array($l['status'],['Contactada','Sin respuesta'],true)?'Contacto registrado':'Pendiente de primer contacto'))))));
  $totals[$status]++;$rows[]=[$eid,$e,$l,$status,$available];
 }
 echo '<section class="card"><h2>Prospección institucional</h2><p>No se exige autorización de campañas para gestionar una empresa, llamar o preparar un primer contacto institucional permitido. Esta lista no envía correos automáticamente. Compruebe las instrucciones del centro, la finalidad y las negativas de contacto.</p>';
 if(!$closed)a('mail_preparer','Preparar correo institucional',$cid,'','button');echo '<div class="metrics">';foreach($totals as $label=>$n)panel_metric($label,$n);echo '</div><div class="fields"><label>Buscar<input id="search" placeholder="Empresa, localidad, correo…"></label>';directory_location_filters();echo '<label>Situación<select id="status-filter"><option value="">Todas</option>';foreach($totals as $label=>$n)echo '<option>'.h($label).'</option>';echo '</select></label></div><p id="directory-count" role="status"></p><div class="scroll"><table id="directory"><thead><tr><th>Empresa</th><th>Grupo</th><th>Situación</th><th>Próximo contacto</th><th>Acción</th></tr></thead><tbody>';
 foreach($rows as [$eid,$e,$l,$status,$available]){echo '<tr data-locations="'.directory_location_attrs($e).'" data-status="'.h($status).'"><td>'.h($e['name']).'<small>'.h($e['zone'].' · '.$e['phone']).'</small><small>'.h(implode(', ',$available)).'</small>';directory_location_summary($e);echo '</td><td>'.h($c['groups'][$l['group']]['name']??'').'</td><td>'.h($status).'</td><td>'.h($l['next']).'</td><td>';a('company',$closed?'Consultar':'Abrir ficha y registrar contacto',$cid,(string)$eid);echo '</td></tr>';}
 echo '</tbody></table></div></section>';
}
