<?php
declare(strict_types=1);
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){http_response_code(404);exit;}
function mc_rows(array $d,string $cid):array {
 $out=[];foreach($d['links'][$cid]??[] as $id=>$l){
  if($l['status']==='Rechazada')continue;$e=company($d,(string)$id);
  $m=array_values(array_filter(camp_emails($e),fn($v)=>!isset($d['campaign_optouts'][$v])));if(!$m)continue;
  $out[(string)$id]=['name'=>$e['name'],'kind'=>$e['kind'],'zone'=>$e['zone'],'sector'=>$e['sector'],'group'=>$l['group'],'status'=>$l['status'],'email'=>$m[0],'mails'=>$m,'pending'=>camp_status_ok($l)&&!$l['history'],'revision'=>count($l['history'])];
 }return $out;
}
function mc_templates(array $d,array $cfg,string $cid):array{
 $s=$d['settings'];$center=$s['center'];$year=$d['courses'][$cid]['name'];$url=rtrim($cfg['public_url']??'','/');$sign=$s['tutor']."\nFamilia Profesional de Administración y Gestión\n".$center."\n".$s['contact'];
 $closing="\n\nSi desean recibir información adicional, respondan a este correo o manifiesten su interés en ".$url."/index.php?v=landing#interes, sin compromiso y sin necesidad de crear una cuenta. Si autorizan futuras comunicaciones sobre FEOE, pueden indicarlo expresamente en su respuesta o en el formulario. Si no desean nuevas propuestas, comuníquennoslo.\n\nAtentamente,\n".$sign;
 return [
 'empresa'=>['label'=>'Empresas privadas · invitación FEOE','kind'=>'Empresa','subject'=>'Invitación a colaborar en la Formación Profesional Dual · '.$center,'body'=>"Estimado/a responsable:\n\nDesde la Familia Profesional de Administración y Gestión de ".$center." buscamos empresas colaboradoras para la Formación en Empresa u Organismo Equiparado (FEOE) del curso ".$year.".\n\nNuestro alumnado puede desarrollar, según su nivel y plan formativo, tareas de gestión administrativa, atención al cliente, facturación, contabilidad, tesorería, compras, almacén, recursos humanos y digitalización.\n\nEl centro coordina la documentación, el plan formativo y el seguimiento. La colaboración permite conocer talento local y contribuir a la formación de futuros profesionales. Las posibles ayudas económicas dependen de la convocatoria aplicable y de sus requisitos.\n\n¿Podrían estudiar la posibilidad de acoger a uno o varios estudiantes?".$closing],
 'organismo'=>['label'=>'Organismos públicos · colaboración educativa','kind'=>'Organismo','subject'=>'Propuesta de colaboración educativa FEOE · '.$center,'body'=>"Estimado/a responsable:\n\nDesde ".$center." deseamos estudiar la colaboración de su organismo con la Familia Profesional de Administración y Gestión durante el curso ".$year.". El alumnado podría desarrollar tareas administrativas acordes con su plan formativo, con tutorización y seguimiento del centro.\n\nAgradeceríamos conocer el departamento competente y la posibilidad de acoger alumnado en Formación en Empresa u Organismo Equiparado.".$closing],
 'asociacion'=>['label'=>'Asociaciones · difusión entre asociados','kind'=>'Asociación','subject'=>'Solicitud de difusión entre empresas asociadas · Formación Profesional Dual','body'=>"Estimados/as representantes:\n\nDesde ".$center." estamos ampliando la red de entidades colaboradoras de Administración y Gestión en el curso ".$year.". Les solicitamos colaboración para difundir esta iniciativa entre sus empresas asociadas, especialmente aquellas con tareas administrativas, contables, comerciales o de gestión.\n\nLa información y el formulario de interés se encuentran en nuestra plataforma. Agradeceríamos que trasladaran la propuesta a sus asociados mediante los canales que consideren oportunos.".$closing]
 ];
}
function mc_select(array $d,string $cid,array $ids,string $kind):array{
 $rows=mc_rows($d,$cid);$out=[];$seen=[];$masters=[];
 foreach($ids as $id){if(!is_string($id)||!isset($rows[$id])||$rows[$id]['kind']!==$kind)fail('Selección no disponible o de otro tipo de entidad. Revise las fichas.',400);
 $r=$rows[$id];$master=master_id($id);if(isset($masters[$master])||isset($seen[$r['email']]))continue;
 $masters[$master]=true;$seen[$r['email']]=true;$out[$id]=$r;}
 return $out;
}
function mc_batch(array $d,string $cid):array{
 $b=$_SESSION['mc_batch']??null;if(!$b||$b['course']!==$cid||$b['expires']<time())fail('Preparación caducada. Genere una nueva.',400);
 $current=mc_rows($d,$cid);foreach($b['rows'] as $id=>$r)if(!isset($current[$id])||$current[$id]['email']!==$r['email']||$current[$id]['kind']!==$r['kind'])fail('Ha cambiado un destinatario o existe una baja. Prepare de nuevo el listado.',409);
 return $b;
}
function mc_action(string $action,array &$d,array $cfg,string $cid):string{
 require_admin();
 if($action==='mc_clear_smtp'){if(!password_verify(field('current_password',72),admin_hash($d,$cfg)))fail('Contraseña actual incorrecta',403);unset($d['smtp_config']);audit($d,'Credenciales SMTP retiradas');flash('Credenciales SMTP retiradas del almacén activo.');return '?v=settings';}
 editable($d,$cid);$back='?v=mail_preparer&c='.rawurlencode($cid);
 if($action==='mc_prepare'){
  $templates=mc_templates($d,$cfg,$cid);$key=field('template');if(!isset($templates[$key]))fail('Plantilla no válida');$ids=$_POST['selected']??[];if(!is_array($ids)||!$ids||count($ids)>2000)fail('Seleccione entre 1 y 2000 colaboraciones');
  $t=$templates[$key];$rows=mc_select($d,$cid,$ids,$t['kind']);if(!$rows)fail('No hay destinatarios');
  $_SESSION['mc_batch']=['id'=>uid(),'course'=>$cid,'expires'=>time()+1800,'rows'=>$rows,'template'=>$key,'subject'=>$t['subject'],'body'=>$t['body']];return $back.'&draft=1';
 }
 $b=mc_batch($d,$cid);if(field('batch')!==$b['id'])fail('Preparación sustituida. Recargue la página.',409);
 if($action==='mc_export'){
  $format=field('format');if($format==='txt')download('destinatarios-'.$cid.'.txt',implode(";\n",array_column($b['rows'],'email'))."\n",'text/plain; charset=UTF-8');
  if($format==='csv'){$rows=[['Empresa','Correo','Localidad','Sector','Grupo']];foreach($b['rows'] as $r)$rows[]=[$r['name'],$r['email'],$r['zone'],$r['sector'],$d['courses'][$cid]['groups'][$r['group']]['name']??''];download('destinatarios-'.$cid.'.csv',csv($rows),'text/csv; charset=UTF-8');}fail('Formato inválido');
 }
 if($action==='mc_record'){
  $ids=$_POST['sent']??[];if(!is_array($ids)||!$ids||field('confirm')!=='yes')fail('Confirme qué correos se enviaron realmente');
  $subject=field('subject',500);$body=field('body',12000);if(!$subject||!$body||preg_match('/[\r\n]/',$subject))fail('Asunto y mensaje obligatorios');
  $date=field('next',10);if($date&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))||$date<date('Y-m-d')))fail('Fecha de seguimiento inválida');
  $ids=array_unique($ids,SORT_REGULAR);foreach($ids as $id){if(!is_string($id)||!isset($b['rows'][$id]))fail('Destinatario no preparado');if(count($d['links'][$cid][$id]['history'])!==$b['rows'][$id]['revision'])fail('Una ficha ha cambiado. Prepare nuevamente antes de registrar.',409);}
  foreach($ids as $id){$l=&$d['links'][$cid][$id];$l['history'][]=['at'=>date(DATE_ATOM),'type'=>'Correo institucional · registrado por administrador','note'=>'Envío externo declarado a '.$b['rows'][$id]['email'].' · '.$subject."\n".$body];if($l['status']==='Identificada')$l['status']='Contactada';if($date)$l['next']=$date;unset($l);audit($d,'Envío institucional registrado',$cid,$id);}
  unset($_SESSION['mc_batch']);flash(count($ids).' envíos registrados manualmente. No se ha remitido correo desde FP Dual ni se han concedido permisos de campaña.');return $back;
 }
 fail('Acción desconocida');
}
function mc_ui(array $d,array $cfg,string $cid,bool $ed):void{
 echo '<section class="card"><h2>Preparador de correo institucional</h2><p>Seleccione únicamente contactos cuya comunicación esté permitida por el centro y el proveedor. La aplicación prepara los mensajes; el envío lo realiza usted en su gestor institucional. Use preferentemente mensajes individuales. Si se permite un envío colectivo, utilice CCO. No se autorizan campañas Resend por preparar o registrar correos.</p>';
 if(!$ed){echo '<p>Curso cerrado: solo consulta.</p></section>';return;}
 if(isset($_GET['draft'])){
  $b=mc_batch($d,$cid);$mails=implode('; ',array_column($b['rows'],'email'));echo '<p>'.count($b['rows']).' destinatarios únicos. Revise las direcciones antes de utilizar el listado.</p>';
  echo '<label>Destinatarios (separados por punto y coma)<textarea id="mc-addresses" readonly rows="4">'.h($mails).'</textarea></label><button type="button" class="secondary" data-copy="mc-addresses">Copiar destinatarios</button>';
  foreach(['txt'=>'TXT','csv'=>'CSV'] as $k=>$label){form_start('mc_export',['c'=>$cid,'batch'=>$b['id'],'format'=>$k]);end_form('Descargar '.$label);}
  form_start('mc_record',['c'=>$cid,'batch'=>$b['id']]);echo '<label>Asunto<input id="mc-subject" name="subject" required value="'.h($b['subject']).'"></label><button type="button" class="secondary" data-copy="mc-subject">Copiar asunto</button><label>Mensaje<textarea id="mc-body" name="body" rows="14" required>'.h($b['body']).'</textarea></label><button type="button" class="secondary" data-copy="mc-body">Copiar mensaje</button><p class="muted" id="mc-copy-status" role="status"></p><h3>Registrar después del envío</h3><p>Marque solo los destinatarios a los que realmente haya enviado el mensaje. Ninguna casilla viene marcada.</p>';
  foreach($b['rows'] as $id=>$r){echo '<label class="check"><input type="checkbox" name="sent[]" value="'.h($id).'">'.h($r['name'].' · '.$r['email']).'</label>';}
  input('next','Próximo contacto (opcional)','','date');echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo que los mensajes seleccionados se enviaron desde mi gestor institucional y que el asunto y texto son los registrados aquí.</label>';end_form('Registrar envíos realizados');a('mail_preparer','Preparar otro listado',$cid);echo '</section>';return;
 }
 $rows=mc_rows($d,$cid);$templates=mc_templates($d,$cfg,$cid);$zones=$sectors=[];foreach($rows as $r){if($r['zone'])$zones[$r['zone']]=$r['zone'];if($r['sector'])$sectors[$r['sector']]=$r['sector'];}ksort($zones);ksort($sectors);
 form_start('mc_prepare',['c'=>$cid]);echo '<div class="fields"><label>Plantilla<select name="template" id="mc-template">';foreach($templates as $key=>$t)echo '<option value="'.h($key).'" data-kind="'.h($t['kind']).'">'.h($t['label']).'</option>';echo '</select></label><label>Buscar<input id="mc-search" placeholder="Empresa o correo"></label><label>Localidad<select id="mc-zone"><option value="">Todas</option>';foreach($zones as $v)echo '<option>'.h($v).'</option>';echo '</select></label><label>Sector<select id="mc-sector"><option value="">Todos</option>';foreach($sectors as $v)echo '<option>'.h($v).'</option>';echo '</select></label><label>Grupo<select id="mc-group"><option value="">Todos</option>';foreach(groups(course($d,$cid)) as $k=>$v)echo '<option value="'.h($k).'">'.h($v).'</option>';echo '</select></label><label class="check"><input type="checkbox" id="mc-pending" checked> Solo pendientes de primer contacto</label></div><div class="actions"><button type="button" class="secondary" id="mc-select">Seleccionar visibles</button><button type="button" class="secondary" id="mc-reset">Desmarcar todo</button><span id="mc-count" role="status"></span></div><div class="scroll"><table id="mc-table"><thead><tr><th>Seleccionar</th><th>Entidad / correo principal</th><th>Grupo</th><th>Estado</th></tr></thead><tbody>';
 foreach($rows as $id=>$r){echo '<tr data-kind="'.h($r['kind']).'" data-zone="'.h($r['zone']).'" data-sector="'.h($r['sector']).'" data-group="'.h($r['group']).'" data-pending="'.($r['pending']?'yes':'no').'"><td><input aria-label="Seleccionar '.h($r['name']).'" type="checkbox" name="selected[]" value="'.h($id).'"></td><td>'.h($r['name']).'<small>'.h($r['email'].' · '.$r['zone'].' · '.$r['sector']).'</small></td><td>'.h($d['courses'][$cid]['groups'][$r['group']]['name']??'').'</td><td>'.h($r['status']).'</td></tr>';}
 echo '</tbody></table></div>';end_form('Preparar destinatarios y plantilla');echo '</section>';
}
