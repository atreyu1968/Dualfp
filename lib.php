<?php
declare(strict_types=1);
function h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function uid(): string {return bin2hex(random_bytes(12));}
function fail(string $m,int $code=400): never {http_response_code($code); echo '<!doctype html><meta charset="utf-8"><h2>'.h($m).'</h2><p><a href="index.php">Volver</a></p>'; exit;}
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
function location_url(string $v):string {
 $v=trim($v);if($v==='')return '';
 $p=parse_url($v);if(strlen($v)>1000||preg_match('/[\\x00-\\x20\\x7f]/',$v)||!filter_var($v,FILTER_VALIDATE_URL)||!is_array($p)||!in_array(strtolower($p['scheme']??''),['http','https'],true)||empty($p['host'])||isset($p['user'])||isset($p['pass']))throw new InvalidArgumentException('URL web o mapa inválida. Use HTTP/HTTPS sin credenciales.');
 return $v;
}
function location_links(array $e,bool $site=false):void {
 $name=trim($e['name']??'');$place=trim($site?($e['detail']??''):($e['address']??''));$zone=trim($e['zone']??'');
 $q=implode(', ',array_filter([$name,$place,$zone,'Tenerife, España']));$map=$e['map']??'';$route=$e['route']??'';
 if($place||($zone&&$zone!=='Por concretar')){$map=$map?:'https://www.google.com/maps/search/?api=1&query='.rawurlencode($q);$route=$route?:'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($q);}
 echo '<div class="actions location-links">';
 foreach(['Web oficial'=>$e['web']??'','Ver mapa'=>$map,'Cómo llegar'=>$route] as $label=>$url){try{$url=location_url($url);}catch(InvalidArgumentException $x){continue;}if($url)echo '<a class="button secondary" href="'.h($url).'" target="_blank" rel="noopener noreferrer">'.h($label).'</a>';}
 echo '</div>';if(!empty($e['location_note']))echo '<small>'.h($e['location_note']).'</small>';if(!empty($e['location_source'])){try{$source=location_url($e['location_source']);if($source)echo '<small><a href="'.h($source).'" target="_blank" rel="noopener noreferrer">Fuente de ubicación</a></small>';}catch(InvalidArgumentException $x){}}
}

function location_municipalities(string $text):array{
 $v=imp_norm($text);$out=[];foreach(['Arona'=>['arona','los cristianos','el fraile','palm mar','guaza','buzanada','valle de san lorenzo','cho'],'Adeje'=>['adeje','costa adeje','torviscas','playa paraiso','la caleta','fanabe'],'Guía de Isora'=>['guia de isora','alcala','playa de san juan','fonsalia','tejina de isora','piedra hincada','himeche'],'Santiago del Teide'=>['santiago del teide','puerto de santiago','los gigantes','tamaimo','acantilado de los gigantes'],'Icod de los Vinos'=>['icod de los vinos'],'Buenavista del Norte'=>['buenavista del norte'],'San Juan de la Rambla'=>['san juan de la rambla']] as $m=>$terms)foreach($terms as $term)if(str_contains(' '.$v.' ',' '.$term.' ')){$out[]=$m;break;}return $out;
}
function location_places(array $e):array{
 $out=[];$base=trim(($e['zone']??'').' '.($e['address']??''));$municipalities=location_municipalities($base);
 foreach($e['sites']??[] as $site){$text=trim(($site['name']??'').' '.($site['detail']??''));$explicit=trim($site['municipality']??'');$m=$explicit?[$explicit]:location_municipalities($site['detail']??'');if(!$m&&count($municipalities)===1)$m=$municipalities;if(count($e['sites']??[])===1&&count($municipalities)===1)$text.=' '.$base;$out[]=['text'=>$text,'municipalities'=>$m];}
 if(!$out)$out[]=['text'=>trim(($e['name']??'').' '.$base),'municipalities'=>$municipalities];return $out;
}
function company_remove_blockers(array $d,string $cid,string $eid,bool $full):array{
 $l=participation($d,$cid,$eid);$master=master_id($eid);$errors=[];
 if(($l['confirmed']??0)>0||($l['requested']??0)>0)$errors[]='Tiene plazas solicitadas o confirmadas.';
 if(!empty($l['history'])||in_array($l['status']??'',['Interesada','En negociación','Confirmada','Convenio formalizado'],true))$errors[]='Tiene seguimiento o colaboración en curso.';
 foreach($d['students'][$cid]??[] as $st)if(($st['company']??'')===$eid){$errors[]='Tiene alumnado asignado.';break;}
 foreach($d['docs']??[] as $doc)if(($doc['course']??'')===$cid&&($doc['company']??'')===$eid){$errors[]='Tiene documentos o expedientes.';break;}
 foreach($d['campaigns']??[] as $p)if(($p['course']??'')===$cid&&isset($p['rows'][$eid])){$errors[]='Figura en una campaña; debe conservarse su referencia.';break;}
 foreach($d['inbox']??[] as $m)if(($m['course']??'')===$cid&&($m['link']??'')===$eid){$errors[]='Tiene mensajes vinculados.';break;}
 foreach($d['interest_evidence']??[] as $x)if(($x['company']??'')===$master&&(($x['course']??'')===$cid&&($x['group']??'')===($l['group']??'')||$full)){$errors[]='Tiene evidencias de interés o autorización.';break;}
 $others=0;foreach($d['links']??[] as $course=>$ls)foreach($ls as $id=>$x)if(master_id((string)$id)===$master&&($course!==$cid||(string)$id!==$eid))$others++;
 if($full&&$others)$errors[]='Está vinculada a otros grupos o cursos.';
 if(!$full&&!$others)$errors[]='Es la última colaboración: utilice Eliminar ficha completa.';
 if($full){
  foreach(['accounts','invites'] as $k)foreach($d[$k]??[] as $a)if(($a['company']??'')===$master){$errors[]='Tiene cuentas o invitaciones empresariales.';break 2;}
  if(!empty($d['campaign_permissions'][$master]))$errors[]='Tiene autorizaciones de correo registradas.';
  foreach($d['students']??[] as $ss)foreach($ss as $st)if(($st['company']??'')!==''&&master_id($st['company'])===$master){$errors[]='Tiene asignaciones históricas.';break 2;}
  foreach($d['docs']??[] as $doc)if(($doc['company']??'')!==''&&master_id($doc['company'])===$master){$errors[]='Tiene documentación histórica.';break;}
  foreach($d['campaigns']??[] as $p)foreach($p['rows']??[] as $id=>$r)if(master_id((string)$id)===$master){$errors[]='Tiene referencias históricas en campañas.';break 2;}
  foreach($d['inbox']??[] as $m)if(($m['link']??'')!==''&&master_id($m['link'])===$master){$errors[]='Tiene mensajes históricos.';break;}
 }
 return array_values(array_unique($errors));
}
function company_remove(array &$d,string $cid,string $eid,bool $full):void{
 require_admin();editable($d,$cid);$errors=company_remove_blockers($d,$cid,$eid,$full);if($errors)fail(implode(' ',$errors),409);
 $master=master_id($eid);unset($d['links'][$cid][$eid]);if($full)unset($d['companies'][$master]);audit($d,$full?'Ficha empresarial eliminada':'Colaboración retirada',$cid,$eid);
}

function set_profile(array &$e): void {foreach(['name','zone','sector','emails','phone','contact','nif','address'] as $k)$e[$k]=field($k,500);if(!$e['name'])fail('Nombre obligatorio');try{foreach(['web','map','route','location_source'] as $k)if(isset($_POST[$k]))$e[$k]=location_url(field($k,1000));}catch(InvalidArgumentException $x){fail($x->getMessage());}if(isset($_POST['location_note']))$e['location_note']=field('location_note',500);$kind=field('kind');if(!in_array($kind,['Empresa','Organismo','Asociación'],true))fail('Tipo incorrecto');$e['kind']=$kind;}

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
