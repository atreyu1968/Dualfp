<?php
declare(strict_types=1);
function h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function uid(): string {return bin2hex(random_bytes(12));}
function fail(string $m,int $code=400): never {
 if(ob_get_level()>0)ob_clean();
 http_response_code($code);header('Content-Type: text/html; charset=UTF-8');header('Cache-Control: no-store');
 $role=$_SESSION['role']??'';$title=match($code){403=>'Acceso no autorizado',404=>'Página no encontrada',409=>'Operación no disponible',default=>$code>=500?'Servicio no disponible':'No se ha podido completar la operación'};
 $home=match($role){'admin'=>'dashboard','tutor'=>'teacher','company'=>'portal',default=>'login'};
 echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · FP Dual Conecta</title><link rel="icon" href="favicon.svg"><link rel="stylesheet" href="style.css?v=3131"></head><body class="error-ui"><header class="error-header"><a class="brand" href="index.php?v=landing"><span>FP</span><div>Dual Conecta · A&amp;G<small>Administración y Gestión</small></div></a></header><main class="error-main"><section class="error-card" aria-labelledby="error-title"><div class="error-number">'.h($code).'</div><p class="eyebrow">FP DUAL CONECTA</p><h1 id="error-title">'.h($title).'</h1><p>'.h($m).'</p>';
 if($code===403)echo '<p class="muted">Compruebe que ha iniciado sesión con el perfil adecuado. Si necesita permisos adicionales, contacte con la administración del centro.</p>';
 echo '<div class="error-actions"><a class="button" href="index.php?v='.h($home).'">'.($role?'Volver a mi panel':'Iniciar sesión').'</a><a class="button secondary" href="index.php?v=landing">Página principal</a>';
 if($role&&!empty($_SESSION['csrf']))echo '<form method="post" action="index.php">'.post_hidden('logout').'<button class="secondary" type="submit">Cerrar sesión</button></form>';
 echo '</div></section></main></body></html>';exit;
}
function go(string $q=''): never {header('Location: index.php'.$q);exit;}
function field(string $k,int $max=500): string {$v=$_POST[$k]??'';if(!is_string($v)||strlen($v)>$max)fail('Campo inválido: '.$k);return trim($v);}
function integer(string $k,int $max=500): int {$v=filter_var($_POST[$k]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>$max]]);if($v===false)fail('Cantidad inválida');return $v;}
function email(string $k): string {$v=strtolower(field($k,254));if(!filter_var($v,FILTER_VALIDATE_EMAIL))fail('Correo no válido');return $v;}
function require_admin(): void {if(($_SESSION['role']??'')!=='admin')fail('Acceso no autorizado',403);}
function require_company(): string {if(($_SESSION['role']??'')!=='company')fail('Acceso no autorizado',403);return $_SESSION['company'];}
function course(array $d,string $id): array {if(!isset($d['courses'][$id]))fail('Curso inexistente',404);return $d['courses'][$id];}
function editable(array $d,string $id): void {if(course($d,$id)['closed'])fail('Curso cerrado: solo consulta',403);}
function company(array $d,string $id): array {$id=master_id($id);if(!isset($d['companies'][$id]))fail('Empresa inexistente',404);return $d['companies'][$id];}
function participation(array $d,string $cid,string $eid): array {if(!isset($d['links'][$cid][$eid]))fail('Empresa no vinculada a este curso',404);return $d['links'][$cid][$eid];}
function audit(array &$d,string $event,string $cid='',string $eid=''): void {$d['audit'][]=['at'=>date(DATE_ATOM),'role'=>$_SESSION['role']??'public','event'=>$event,'course'=>$cid,'company'=>$eid];}
function seed(?array $initialCompanies=null): array {$d=['settings'=>['center'=>'IES Manuel Martín González','contact'=>'38001553@gobiernodecanarias.org','tutor'=>'','external'=>'','privacy'=>'','public'=>false,'active'=>'2026-2027','rate'=>3.5,'reference'=>'2025/2026','boc'=>'https://www.gobiernodecanarias.org/boc/2026/230/3988.html'],'courses'=>[],'companies'=>[],'links'=>[],'students'=>[],'docs'=>[],'accounts'=>[],'invites'=>[],'audit'=>[],'attempts'=>[]];$c='2026-2027';$d['courses'][$c]=['name'=>'2026/2027','closed'=>false,'groups'=>['g1'=>['cycle'=>'ga','level'=>'1','label'=>'','name'=>'1.º CFGM Gestión Administrativa','target'=>28,'hours'=>0,'start'=>'','end'=>'']]];$d['links'][$c]=[];foreach(($initialCompanies??json_decode(file_get_contents(__DIR__.'/seed.json'),true,512,JSON_THROW_ON_ERROR)) as $e){$id=$e['id'];$d['companies'][$id]=['name'=>$e['name'],'zone'=>$e['zone'],'sector'=>$e['sector'],'kind'=>$e['kind'],'emails'=>$e['emails'],'phone'=>$e['phone'],'contact'=>$e['contact'],'nif'=>'','address'=>'','tutors'=>[],'sites'=>[]];$d['links'][$c][$id]=['status'=>$e['status'],'requested'=>$e['requested'],'confirmed'=>$e['confirmed'],'group'=>'g1','notes'=>$e['notes'],'next'=>$e['next'],'history'=>$e['history'],'snapshot'=>null];}for($i=1;$i<=28;$i++)$d['students'][$c]['A'.str_pad((string)$i,2,'0',STR_PAD_LEFT)]=['name'=>'','group'=>'g1','zone'=>'','company'=>'','site'=>'','tutor'=>'','notes'=>''];return $d;}
function load_db(string $dir): array {$file=$dir.'/data.json';return is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):seed(is_file($dir.'/seed.json')?json_decode(file_get_contents($dir.'/seed.json'),true,512,JSON_THROW_ON_ERROR):null);}
function save_db(string $dir,array $d): void {$tmp=tempnam($dir,'db-');chmod($tmp,0600);$s=json_encode($d,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$s)!==strlen($s)||!rename($tmp,$dir.'/data.json')){ @unlink($tmp);fail('Error al guardar',500);}}
function auth_doc(array $d,array $doc): bool {if(($_SESSION['role']??'')==='admin')return true;if(($_SESSION['role']??'')==='tutor')return isset($d['links'][$doc['course']][$doc['company']])&&isset(tutor_groups($d,$doc['course'])[$d['links'][$doc['course']][$doc['company']]['group']]);return ($_SESSION['role']??'')==='company'&&master_id($doc['company'])===$_SESSION['company']&&$doc['shared']&&isset($d['links'][$doc['course']][$doc['company']]);}
function csv(array $rows): string {$f=fopen('php://temp','r+');fwrite($f,"\xEF\xBB\xBF");foreach($rows as $row){$row=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/',(string)$v)?"'".$v:$v,$row);fputcsv($f,$row,';','"','');}rewind($f);return stream_get_contents($f);}
function safe_name(string $n): string {return substr(preg_replace('/[^a-zA-Z0-9._-]/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$n)?:'documento'),0,120);}
function download(string $name,string $data,string $type): never {header('Content-Type: '.$type);header('Content-Disposition: attachment; filename="'.safe_name($name).'"');header('Content-Length: '.strlen($data));echo $data;exit;}
// ZIP sin extensión ZipArchive; tamaño total limitado antes de construirlo.
function zip_bytes(array $files): string {$out='';$central='';$count=0;foreach($files as $name=>$data){$n=strlen($name);$len=strlen($data);$crc=crc32($data);$offset=strlen($out);$out.=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,33,$crc,$len,$len,$n,0).$name.$data;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,33,$crc,$len,$len,$n,0,0,0,0,0,$offset).$name;$count++;}return $out.$central.pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),strlen($out),0);}
function post_hidden(string $action,array $params=[]): string {$s='<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.h($action).'">';foreach($params as $k=>$v)$s.='<input type="hidden" name="'.h($k).'" value="'.h($v).'">';return $s;}
function input(string $k,string $label,$v='',string $type='text',bool $req=false): void {echo '<label>'.h($label).'<input name="'.h($k).'" type="'.h($type).'" value="'.h($v).'" '.($req?'required':'').'></label>';}
function area(string $k,string $label,$v=''): void {echo '<label>'.h($label).'<textarea name="'.h($k).'" rows="3">'.h($v).'</textarea></label>';}
function select_field(string $k,string $label,array $opts,$v=''): void {echo '<label>'.h($label).'<select name="'.h($k).'">';foreach($opts as $id=>$n)echo '<option value="'.h($id).'"'.((string)$id===(string)$v?' selected':'').'>'.h($n).'</option>';echo '</select></label>';}
function groups(array $c): array {return array_map(fn($g)=>$g['name'],$c['groups']);}
function count_assigned(array $d,string $cid,string $eid): int {return count(array_filter($d['students'][$cid]??[],fn($s)=>$s['company']===$eid));}
function set_profile(array &$e): void {foreach(['name','zone','sector','emails','phone','contact','nif','address'] as $k)$e[$k]=field($k,500);if(array_key_exists('website',$_POST)){$web=field('website',500);if($web&&!company_web_valid($web))fail('Web: introduzca una URL HTTP o HTTPS válida');$e['website']=$web;}foreach(['map','route','location_source'] as $k)if(array_key_exists($k,$_POST)){$v=field($k,1000);if($v&&!location_safe_url($v))fail('URL de ubicación inválida');$e[$k]=$v;}if(array_key_exists('location_note',$_POST))$e['location_note']=field('location_note',500);if(!$e['name'])fail('Nombre obligatorio');$kind=field('kind');if(!in_array($kind,['Empresa','Organismo','Asociación'],true))fail('Tipo incorrecto');$e['kind']=$kind;}

function ag_cycles(): array {return ['sa'=>'CFGB Servicios Administrativos','ga'=>'CFGM Gestión Administrativa','af'=>'CFGS Administración y Finanzas','ad'=>'CFGS Asistencia a la Dirección'];}
function ag_activities(): array {return ['sa'=>'Archivo, digitalización, comunicación básica y apoyo de oficina.','ga'=>'Facturación, documentación comercial, contabilidad auxiliar, atención al cliente y apoyo administrativo.','af'=>'Gestión contable y financiera, fiscalidad, aprovisionamiento, tesorería y recursos humanos.','ad'=>'Organización de agendas, comunicación profesional, documentación, eventos y apoyo a dirección.'];}

// Oferta FEOE del centro: seis combinaciones de ciclo y nivel.
function ag_feoe_groups(): array {
    $cycles=ag_cycles(); $allowed=['sa'=>['2'],'ga'=>['1','2'],'af'=>['1','2'],'ad'=>['2']]; $out=[];
    foreach($allowed as $cycle=>$levels)foreach($levels as $level)$out[$cycle.':'.$level]=$level.'.º '.$cycles[$cycle];
    return $out;
}
function ag_feoe_allowed(string $cycle,string $level): bool {return isset(ag_feoe_groups()[$cycle.':'.$level]);}

/** Stable collaboration references: legacy IDs stay unchanged; extra links use master~random. */
function master_id(string $id): string {return explode('~',$id,2)[0];}
function company_links(array $d,string $cid,string $master): array {
    return array_filter($d['links'][$cid]??[],fn($l,$id)=>master_id((string)$id)===$master,ARRAY_FILTER_USE_BOTH);
}
function group_link(array $links,string $master,string $group): ?string {
    foreach($links as $id=>$l)if(master_id((string)$id)===$master&&$l['group']===$group)return (string)$id;
    return null;
}
function new_link_key(array $links,string $master,string $group): string {
    if(group_link($links,$master,$group)!==null)throw new InvalidArgumentException('La empresa ya está vinculada a este grupo.');
    if(!isset($links[$master]))return $master;
    do{$id=$master.'~'.uid();}while(isset($links[$id]));
    return $id;
}
function company_link_scope(array $d,string $cid,string $id): array {
    $master=require_company();
    if(master_id($id)!==$master)fail('Colaboración no autorizada',403);
    return participation($d,$cid,$id);
}
function collaboration_label(array $d,string $cid,string $id): string {
    $l=participation($d,$cid,$id);$e=$l['snapshot']??company($d,$id);
    return $e['name'].' · '.($d['courses'][$cid]['groups'][$l['group']]['name']??$l['group']);
}

function location_safe_url(string $url):bool{
 if(strlen($url)>1000||preg_match('/[\x00-\x20\x7f]/',$url)||!filter_var($url,FILTER_VALIDATE_URL))return false;
 $p=parse_url($url);return is_array($p)&&in_array(strtolower($p['scheme']??''),['http','https'],true)&&!empty($p['host'])&&!isset($p['user'])&&!isset($p['pass']);
}
function company_web_valid(string $url):bool{return strlen($url)<=500&&location_safe_url($url);}
function location_anchor(string $url,string $label):void{
 if($url!==''&&location_safe_url($url))echo '<a class="button secondary" href="'.h($url).'" target="_blank" rel="noopener noreferrer">'.h($label).' ↗</a>';
}
function company_web_link(array $e):void{
 location_anchor($e['website']??'','Web oficial');
 location_anchor($e['map']??'','Mapa de empresa');
 location_anchor($e['route']??'','Cómo llegar a empresa');
 if(!empty($e['location_note']))echo '<small>'.h($e['location_note']).'</small>';
 if(!empty($e['location_source']))location_anchor($e['location_source'],'Fuente de ubicación');
}
function site_map_links(string $name,string $detail,string $zone='',array $site=[]):void{
 $approx=str_starts_with($detail,'Referencia de ubicación (confirmar dirección exacta):');
 if($approx)$detail=trim(substr($detail,strlen('Referencia de ubicación (confirmar dirección exacta):')));
 $hasPlace=trim($detail)!==''||trim($zone)!=='';
 $q=rawurlencode(implode(', ',array_filter([$name,$detail,$zone,'Tenerife, España'])));
 if($approx)echo '<p class="muted">Ubicación orientativa: confirme la dirección antes de desplazarse.</p>';
 echo '<div class="actions location-links">';
 location_anchor($site['web']??'','Web del centro');
 $map=$site['map']??'';$route=$site['route']??'';
 if(!$map&&$hasPlace)$map='https://www.google.com/maps/search/?api=1&query='.$q;
 if(!$route&&$hasPlace)$route='https://www.google.com/maps/dir/?api=1&destination='.$q;
 location_anchor($map,'Ver mapa');location_anchor($route,'Cómo llegar');echo '</div>';
}
function company_location_fallback(array $e):void{
 // Explicit corporate links already appear through company_web_link.
 if(!empty($e['map'])&&!empty($e['route']))return;
 if(!empty($e['address'])){site_map_links($e['name'],$e['address'],$e['zone']??'');return;}
 $zone=trim($e['zone']??'');if(!$zone||in_array(location_norm($zone),['por concretar','tenerife','tenerife sur'],true))return;
 $q=rawurlencode($e['name'].', '.$zone.', Tenerife, España');
 echo '<p class="muted">Centro de trabajo por confirmar.</p>';location_anchor('https://www.google.com/maps/search/?api=1&query='.$q,'Buscar ubicación');
}
function company_locations(array $e):void{
 company_web_link($e);
 if(!empty($e['sites'])){foreach($e['sites'] as $s){echo '<p><strong>'.h($s['name']).'</strong><small>'.h($s['detail']).'</small></p>';site_map_links($s['name'],$s['detail'],site_municipality($s,$e['zone']??''),$s);}}
 else company_location_fallback($e);
}

function location_norm(string $s):string{
 $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower($s))??'');
}
function location_municipalities():array{
 return ['Adeje','Arafo','Arico','Arona','Buenavista del Norte','Candelaria','El Rosario','El Sauzal','El Tanque','Fasnia','Garachico','Granadilla de Abona','Guía de Isora','Güímar','Icod de los Vinos','La Guancha','La Matanza de Acentejo','La Orotava','La Victoria de Acentejo','Los Realejos','Los Silos','Puerto de la Cruz','San Cristóbal de La Laguna','San Juan de la Rambla','San Miguel de Abona','Santa Cruz de Tenerife','Santa Úrsula','Santiago del Teide','Tacoronte','Tegueste','Vilaflor de Chasna'];
}
function location_matches(string $s):array{
 $norm=' '.location_norm($s).' ';$out=[];
 foreach(location_municipalities() as $m)if(str_contains($norm,' '.location_norm($m).' '))$out[]=$m;
 if(preg_match('/\b(al cala|alcala|playa de san juan|fonsalia|tejina de isora|piedra hincada|himeche|ch io|chio)\b/',$norm))$out[]='Guía de Isora';
 if(preg_match('/\b(los cristianos|el fraile|las galletas|valle de san lorenzo|buzanada|guaza|palm mar)\b/',$norm))$out[]='Arona';
 if(preg_match('/\b(los gigantes|puerto de santiago|tamaimo|argen guicho|arguayo)\b/',$norm))$out[]='Santiago del Teide';
 if(preg_match('/\b(costa adeje|torviscas|playa paraiso|callao salvaje)\b/',$norm))$out[]='Adeje';
 return array_values(array_unique($out));
}
function site_municipality(array $s,string $zone=''):string{
 $explicit=trim($s['municipality']??'');if($explicit!=='')return $explicit;
 $found=location_matches($s['detail']??'');if(count($found)===1)return $found[0];if($found)return '';
 $found=location_matches($zone);return count($found)===1?$found[0]:'';
}
function directory_locations(array $e):array{
 $out=[];foreach($e['sites']??[] as $s)$out[]=['name'=>$s['name'],'detail'=>$s['detail'],'municipality'=>site_municipality($s,$e['zone']??'')];
 if(!$out)$out[]=['name'=>$e['name'],'detail'=>trim(($e['address']??'').' '.($e['zone']??'')),'municipality'=>site_municipality(['detail'=>$e['address']??''],$e['zone']??'')];
 return $out;
}
function directory_location_attrs(array $e):string{return h(json_encode(directory_locations($e),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));}
function directory_location_filters():void{
 echo '<label>Municipio del centro de trabajo<select id="municipality-filter"><option value="">Todos los municipios</option>';
 foreach(location_municipalities() as $m)echo '<option>'.h($m).'</option>';
 echo '<option value="__unknown">Sin municipio determinado</option></select></label><label>Centro de trabajo o lugar<input id="site-search" placeholder="Hotel, barrio, calle, polígono…"></label>';
}
function directory_location_summary(array $e):void{
 foreach(directory_locations($e) as $s)echo '<small>'.h($s['name'].' · '.($s['municipality']?:'Municipio por confirmar').' · '.$s['detail']).'</small>';
}
// Only remove unused records. Historical references must not be broken.
function company_link_delete_blockers(array $d,string $cid,string $eid):array{
 $l=$d['links'][$cid][$eid]??null;if(!$l)return ['Colaboración inexistente'];
 $reasons=[];
 if(!empty($d['courses'][$cid]['closed']))$reasons[]='Curso cerrado';
 if(!empty($l['confirmed'])||!empty($l['requested']))$reasons[]='Plazas solicitadas o confirmadas';
 if(!empty($l['history'])||!empty($l['notes'])||!empty($l['next'])||!in_array($l['status']??'',['Identificada','Rechazada'],true))$reasons[]='Seguimiento o actividad registrada';
 foreach($d['students'][$cid]??[] as $s)if(($s['company']??'')===$eid){$reasons[]='Alumnado asignado';break;}
 foreach($d['docs']??[] as $doc)if($doc['course']===$cid&&$doc['company']===$eid){$reasons[]='Documentos asociados';break;}
 foreach($d['campaigns']??[] as $campaign)if($campaign['course']===$cid&&isset($campaign['rows'][$eid])){$reasons[]='Campañas vinculadas';break;}
 foreach($d['inbox']??[] as $m)if(($m['course']??'')===$cid&&($m['link']??'')===$eid){$reasons[]='Mensajes vinculados';break;}
 return $reasons;
}
function company_delete_blockers(array $d,string $master):array{
 $reasons=[];$e=$d['companies'][$master]??null;if(!$e)return ['Ficha inexistente'];
 foreach($d['links']??[] as $cid=>$links)foreach($links as $id=>$l)if(master_id((string)$id)===$master)$reasons=array_merge($reasons,company_link_delete_blockers($d,(string)$cid,(string)$id));
 foreach(['accounts','invites','interest_evidence'] as $k)foreach($d[$k]??[] as $v)if(($v['company']??'')===$master){$reasons[]='Cuenta, invitación o evidencia de interés asociada';break;}
 if(!empty($d['campaign_permissions'][$master]))$reasons[]='Permisos de comunicaciones registrados';
 foreach($d['students']??[] as $students)foreach($students as $s)if(($s['company']??'')!==''&&master_id($s['company'])===$master)$reasons[]='Alumnado asignado';
 foreach($d['docs']??[] as $doc)if(master_id((string)$doc['company'])===$master)$reasons[]='Documentos asociados';
 foreach($d['campaigns']??[] as $campaign)foreach($campaign['rows']??[] as $id=>$row)if(master_id((string)$id)===$master)$reasons[]='Campañas vinculadas';
 foreach($d['inbox']??[] as $m)if(($m['link']??'')!==''&&master_id($m['link'])===$master)$reasons[]='Mensajes vinculados';
 return array_values(array_unique($reasons));
}
function company_remove(array &$d,string $cid,string $eid,bool $global):void{
 require_admin();editable($d,$cid);participation($d,$cid,$eid);$master=master_id($eid);company($d,$master);
 $reasons=$global?company_delete_blockers($d,$master):company_link_delete_blockers($d,$cid,$eid);
 if($reasons)throw new InvalidArgumentException(implode(' · ',$reasons));
 if($global){
  foreach($d['links'] as $c=>&$links)foreach(array_keys($links) as $id)if(master_id((string)$id)===$master)unset($links[$id]);unset($links);
  unset($d['companies'][$master],$d['campaign_permissions'][$master]);audit($d,'Ficha empresarial eliminada',$cid,$master);
 }else{unset($d['links'][$cid][$eid]);audit($d,'Colaboración empresarial desvinculada',$cid,$eid);}
 unset($_SESSION['imp_batch'],$_SESSION['mc_batch']);
}
function company_remove_ui(array $d,string $cid,string $eid):void{
 $e=company($d,$eid);echo '<section class="card"><h2>Eliminar o desvincular ficha</h2><p>Solo administración. La eliminación es irreversible en los datos activos; las copias de seguridad pueden conservarla. No se eliminan expedientes con actividad o referencias históricas.</p>';
 foreach(['unlink_company'=>'Desvincular de este grupo','delete_company'=>'Eliminar ficha de todos los grupos y cursos'] as $action=>$label){
  $reasons=$action==='delete_company'?company_delete_blockers($d,master_id($eid)):company_link_delete_blockers($d,$cid,$eid);
  echo '<details><summary>'.h($label).'</summary>';if($reasons)echo '<p>No disponible: '.h(implode(' · ',$reasons)).'.</p>';
  else{form_start($action,['c'=>$cid,'e'=>$eid]);input('current_password','Contraseña actual de administración','','password',true);echo '<label class="check"><input type="checkbox" name="confirm" value="yes" required> Confirmo: '.h($label.' · '.$e['name']).'.</label>';end_form($label);}echo '</details>';
 }echo '</section>';
}
