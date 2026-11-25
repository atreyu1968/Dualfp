<?php
declare(strict_types=1);
if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {http_response_code(404);exit;}
// Administrative company import; never grants campaign permission or confirms places.
function imp_norm(string $s): string {
 $s=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s)?:$s;
 return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower($s))??'');
}
function imp_mail(string $s): array {return array_values(array_unique(array_filter(preg_split('/[,;\s]+/',strtolower(trim($s)))?:[],fn($m)=>$m!=='')));}
function imp_nif(string $s): string {return strtoupper(preg_replace('/[^a-zA-Z0-9]/','',$s)??'');}
function imp_index_add(array &$idx,string $id,array $e): void {
 $n=imp_nif($e['nif']??'');if($n!=='')$idx['nif'][$n][$id]=true;
 $name=imp_norm($e['name']??'');$zone=imp_norm($e['zone']??'');if($name!==''){$idx['name'][$name][$id]=true;if($zone!=='')$idx['zone'][$name.'|'.$zone][$id]=true;else $idx['nozone'][$name][$id]=true;}
 foreach(imp_mail($e['emails']??'') as $mail)$idx['mail'][$mail][$id]=true;
}
function imp_index(array $companies): array {$idx=['nif'=>[],'name'=>[],'zone'=>[],'nozone'=>[],'mail'=>[]];foreach($companies as $id=>$e)imp_index_add($idx,(string)$id,$e);return $idx;}
function imp_matches(array $idx,array $e): array {
 $ids=[];$n=imp_nif($e['nif']??'');$name=imp_norm($e['name']??'');$zone=imp_norm($e['zone']??'');
 if($n!=='')$ids+=$idx['nif'][$n]??[];
 $ids+=$zone!==''?($idx['zone'][$name.'|'.$zone]??[]):($idx['name'][$name]??[]);
 // Same name with missing locality on either record merits review, not silent creation.
 if($zone!=='')$ids+=$idx['nozone'][$name]??[];
 foreach(imp_mail($e['emails']??'') as $mail)$ids+=$idx['mail'][$mail]??[];
 return array_keys($ids);
}
function imp_headers(): array {return ['name'=>'Empresa','nif'=>'NIF','zone'=>'Localidad','address'=>'Domicilio','sector'=>'Sector','contact'=>'Responsable','emails'=>'Correo','phone'=>'Teléfono','kind'=>'Tipo'];}
function imp_profile(array $raw): array {
 $e=[];foreach(imp_headers() as $k=>$label){$s=trim((string)($raw[$k]??''));if(strlen($s)>500)throw new InvalidArgumentException($label.': máximo 500 bytes.');if(preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$s))throw new InvalidArgumentException($label.': caracteres no válidos.');$e[$k]=$s;}
 if($e['name']==='')throw new InvalidArgumentException('Nombre de empresa obligatorio.');
 $m=imp_mail($e['emails']);foreach($m as $mail)if(!filter_var($mail,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Correo inválido: '.$mail);$e['emails']=implode(', ',$m);
 $kind=imp_norm($e['kind']);$e['kind']=match($kind){'','empresa','empresa privada','privada','autonomo','cooperativa','pyme'=>'Empresa','organismo','organismo publico','publica','empresa publica','administracion publica','ayuntamiento'=>'Organismo','asociacion','fundacion'=>'Asociación',default=>throw new InvalidArgumentException('Tipo no reconocido: use Empresa, Organismo o Asociación.')};
 return $e+['tutors'=>[],'sites'=>[]];
}
function imp_parse(string $data): array {
 if(strlen($data)>2*1024*1024)throw new InvalidArgumentException('El fichero supera 2 MB.');
 $data=preg_replace('/^\xEF\xBB\xBF/','',$data)??$data;
 if(!preg_match('//u',$data)){$data=iconv('Windows-1252','UTF-8',$data);if($data===false)throw new InvalidArgumentException('Codificación no válida. Guarde en UTF-8.');}
 if(str_contains($data,"\0"))throw new InvalidArgumentException('Formato no válido. Use CSV o TSV de texto, no XLSX.');
 $aliases=['empresa'=>'name','nombre'=>'name','razon social'=>'name','name'=>'name','nif'=>'nif','cif'=>'nif','localidad'=>'zone','municipio'=>'zone','zona'=>'zone','zone'=>'zone','domicilio'=>'address','direccion'=>'address','address'=>'address','sector'=>'sector','actividad'=>'sector','responsable'=>'contact','contacto'=>'contact','contact'=>'contact','correo'=>'emails','correos'=>'emails','email'=>'emails','e mail'=>'emails','correo electronico'=>'emails','emails'=>'emails','telefono'=>'phone','phone'=>'phone','tipo'=>'kind','kind'=>'kind'];
 $f=fopen('php://temp','r+');fwrite($f,$data);rewind($f);$best=[];$delimiter=';';$score=-1;
 foreach(["\t",';',','] as $del){rewind($f);$header=fgetcsv($f,0,$del,'"','');$map=array_map(fn($x)=>$aliases[imp_norm((string)$x)]??'', $header?:[]);$s=count(array_filter($map));if(in_array('name',$map,true)&&$s>$score){$score=$s;$best=$map;$delimiter=$del;}}
 if($score<1)throw new InvalidArgumentException('Cabecera no reconocida. Incluya la columna Empresa o Razón social.');
 $known=array_filter($best);if(count($known)!==count(array_unique($known)))throw new InvalidArgumentException('Hay columnas equivalentes repetidas en la cabecera.');
 rewind($f);fgetcsv($f,0,$delimiter,'"','');$rows=[];$line=1;
 while(($cells=fgetcsv($f,0,$delimiter,'"',''))!==false){$line++;if(!array_filter($cells,fn($v)=>trim((string)$v)!==''))continue;if(count($rows)>=2000)throw new InvalidArgumentException('Máximo 2.000 registros por lote.');
  if(count($cells)!==count($best)){$rows[]=['row'=>$line,'error'=>'Número de columnas distinto de la cabecera.','name'=>(string)($cells[0]??'')];continue;}
  $raw=[];foreach($best as $i=>$key)if($key!=='')$raw[$key]=$cells[$i]??'';
  try{$rows[]=['row'=>$line,'profile'=>imp_profile($raw)];}catch(InvalidArgumentException $ex){$rows[]=['row'=>$line,'error'=>$ex->getMessage(),'name'=>$raw['name']??''];}
 }
 fclose($f);if(!$rows)throw new InvalidArgumentException('No hay registros para importar.');return $rows;
}
function imp_link(string $group): array {return ['status'=>'Identificada','requested'=>0,'confirmed'=>0,'group'=>$group,'notes'=>'','next'=>'','history'=>[],'snapshot'=>null];}
function imp_plan(array $d,string $cid,string $gid,array $rows): array {
 $idx=imp_index($d['companies']);$companies=$d['companies'];$links=$d['links'][$cid]??[];$out=[];
 foreach($rows as $row){$r=['row'=>$row['row'],'name'=>$row['profile']['name']??$row['name']??'','zone'=>$row['profile']['zone']??'','email'=>$row['profile']['emails']??'','action'=>'error','id'=>'','note'=>$row['error']??''];if(isset($row['error'])){$out[]=$r;continue;}
  $e=$row['profile'];$matches=imp_matches($idx,$e);
  if(count($matches)>1){$r['note']='Coincide con varias fichas. Revisar manualmente.';}
  elseif(count($matches)===1){$id=$matches[0];$r['id']=$id;$old=$companies[$id];$a=imp_nif($old['nif']??'');$b=imp_nif($e['nif']);$sameNif=$a!==''&&$a===$b;$sameName=imp_norm($old['name'])===imp_norm($e['name']);$sameZone=imp_norm($old['zone'])===imp_norm($e['zone']);
   if(($a!==''&&$b!==''&&$a!==$b)||(!$sameNif&&!($sameName&&$sameZone))){$r['note']='Coincidencia de contacto o identidad no concluyente. Revisar manualmente.';}
   elseif(str_starts_with($id,'batch:')){$r['action']='skip';$r['note']='Duplicado dentro del lote.';}
   elseif(isset($links[$id])){$r['action']='skip';$r['note']='Ya vinculada al curso; se conserva su grupo, datos y plazas.';}
   else{$r['action']='link';$r['note']='Reutilizar ficha existente, sin modificar sus datos.';$links[$id]=imp_link($gid);}
  }else{$r['action']='create';$r['note']='Nueva empresa identificada, sin plazas confirmadas.';$id='batch:'.$row['row'];$r['id']=$id;$companies[$id]=$e;imp_index_add($idx,$id,$e);$links[$id]=imp_link($gid);}
  $out[]=$r;
 }return $out;
}
function imp_apply(array &$d,string $cid,string $gid,array $rows,array $plan): array {
 $by=[];foreach($rows as $row)$by[$row['row']]=$row;$count=['create'=>0,'link'=>0,'skip'=>0,'error'=>0];
 foreach($plan as $r){$count[$r['action']]++;if(!in_array($r['action'],['create','link'],true))continue;$id=$r['id'];if($r['action']==='create'){$id=uid();$d['companies'][$id]=$by[$r['row']]['profile'];}$d['links'][$cid][$id]=imp_link($gid);audit($d,'Importación: '.($r['action']==='create'?'alta de empresa':'vinculación de empresa'),$cid,$id);}
 return $count;
}
function imp_handle(string $action,array &$d,string $cid): string {
 require_admin();editable($d,$cid);$gid=field('group');if(!isset($d['courses'][$cid]['groups'][$gid]))fail('Grupo inválido');$back='?v=import&c='.rawurlencode($cid);
 if($action==='imp_preview'){
  unset($_SESSION['imp_batch']);$paste=$_POST['paste']??'';if(!is_string($paste))fail('Texto inválido');$file=$_FILES['csvfile']??null;$hasFile=$file&&$file['error']!==UPLOAD_ERR_NO_FILE;
  if($hasFile&&trim($paste)!=='')fail('Elija un archivo o texto pegado, no ambos.');
  if($hasFile){if($file['error']!==UPLOAD_ERR_OK||$file['size']>2*1024*1024||!is_uploaded_file($file['tmp_name'])||!in_array(strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)),['csv','tsv','txt'],true))fail('Seleccione un CSV, TSV o TXT de hasta 2 MB.');$paste=file_get_contents($file['tmp_name']);if($paste===false)fail('No se puede leer el archivo');}
  try{$rows=imp_parse($paste);}catch(InvalidArgumentException $ex){$_SESSION['flash']=$ex->getMessage();return $back;}
  $plan=imp_plan($d,$cid,$gid,$rows);$_SESSION['imp_batch']=['token'=>uid(),'expires'=>time()+1800,'course'=>$cid,'group'=>$gid,'rows'=>$rows,'plan'=>$plan];return $back;
 }
 if($action==='imp_commit'){$b=$_SESSION['imp_batch']??null;if(!$b||$b['expires']<time()||$b['course']!==$cid||$b['group']!==$gid||!hash_equals($b['token'],field('batch')))fail('Vista previa caducada o no válida. Repita la importación.');$plan=imp_plan($d,$cid,$gid,$b['rows']);if($plan!==$b['plan']){$_SESSION['imp_batch']['plan']=$plan;$_SESSION['flash']='El directorio ha cambiado. Revise la vista previa actualizada antes de confirmar.';return $back;}$n=imp_apply($d,$cid,$gid,$b['rows'],$plan);unset($_SESSION['imp_batch']);$_SESSION['flash']='Importación completada: '.$n['create'].' altas, '.$n['link'].' vinculadas, '.$n['skip'].' duplicadas omitidas y '.$n['error'].' filas con incidencias no importadas.';return '?v=directory&c='.rawurlencode($cid);}
 fail('Acción de importación desconocida');
}
function imp_ui(array $d,string $cid,array $gs,bool $ed): void {
 echo '<section class="card"><h2>Importar empresas</h2><p>Importe hasta 2.000 registros por lote (2 MB). Excel, LibreOffice y Google Sheets: guarde como CSV UTF-8 o copie las celdas con cabecera. Se admiten punto y coma, coma o tabulación. Solo Empresa es obligatoria.</p><p>Los duplicados se comprueban en todos los cursos. Las coincidencias ambiguas se omiten para revisión. Se mantienen las fichas existentes, plazas, grupos y permisos de envío. Las nuevas empresas quedan identificadas con 0 plazas confirmadas.</p>';
 a('import_template','Descargar plantilla CSV',$cid,'','button secondary');
 if(!$ed||!$gs){echo '<p>Seleccione un curso abierto con un grupo configurado.</p></section>';return;}
 form_start('imp_preview',['c'=>$cid],true);select_field('group','Grupo de destino',$gs);echo '<label>Archivo CSV o TSV<input type="file" name="csvfile" accept=".csv,.tsv,.txt,text/csv,text/plain"></label>';area('paste','O pegar una tabla con cabecera');end_form('Validar y ver vista previa');echo '</section>';
 $b=$_SESSION['imp_batch']??null;if(!$b||$b['course']!==$cid||$b['expires']<time())return;
 $counts=array_count_values(array_column($b['plan'],'action'));echo '<section class="card"><h2>Vista previa · '.h($gs[$b['group']]??'Grupo').'</h2><p>'.h(($counts['create']??0).' nuevas · '.($counts['link']??0).' reutilizadas · '.($counts['skip']??0).' duplicadas · '.($counts['error']??0).' incidencias').'</p><div class="scroll"><table><thead><tr><th>Registro</th><th>Empresa</th><th>Localidad / correo</th><th>Acción</th><th>Detalle</th></tr></thead><tbody>';
 foreach($b['plan'] as $r){echo '<tr><td>'.h($r['row']).'</td><td>'.h($r['name']).'</td><td>'.h($r['zone']).'<small>'.h($r['email']).'</small></td><td>'.h(['create'=>'Alta','link'=>'Vincular','skip'=>'Omitir','error'=>'Revisar'][$r['action']]).'</td><td>'.h($r['note']).'</td></tr>';}
 echo '</tbody></table></div>';if(($counts['create']??0)+($counts['link']??0)>0){form_start('imp_commit',['c'=>$cid,'group'=>$b['group'],'batch'=>$b['token']]);end_form('Confirmar importación de filas válidas');}echo '<p>Las filas con incidencias y los duplicados no se importarán. La vista previa caduca en 30 minutos.</p></section>';
}
