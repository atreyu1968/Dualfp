<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require dirname(__DIR__).'/imports.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "OK $msg\n";}
$cid='2026-2027';$gid='g1';$d=seed([]);
$old=imp_profile(['name'=>'Hotel de prueba','zone'=>'Adeje','emails'=>'hotel@example.org']);
$d['companies']['T1']=$old;$d['links'][$cid]['T1']=imp_link($gid);
$d['links'][$cid]['T1']['confirmed']=2;$d['links'][$cid]['T1']['history'][]=['note'=>'Reunión previa'];
$d['campaign_permissions']=['T1'=>['hotel@example.org'=>['allowed'=>true]]];
$before=$d['links'];$perm=$d['campaign_permissions'];
$sites=[['name'=>'Hotel A','detail'=>'Referencia de ubicación (confirmar dirección exacta): Adeje','email'=>'','phone'=>''],['name'=>'Hotel B','detail'=>'Calle de prueba 1','email'=>'','phone'=>'']];
$rows=[['row'=>2,'profile'=>imp_profile(['name'=>'Hotel de prueba','zone'=>'Adeje','emails'=>'hotel@example.org','website'=>'https://example.org','sites_json'=>json_encode($sites)])]];
$plan=imp_plan($d,$cid,$gid,$rows);
check($plan[0]['action']==='enrich','empresa existente se amplía');
check(!$d['companies']['T1']['website']&&!$d['companies']['T1']['sites'],'vista previa sin escritura');
$n=imp_apply($d,$cid,$gid,$rows,$plan);
check(($n['enrich']??0)===1&&count($d['companies'])===1,'sin duplicar empresa');
check(count($d['companies']['T1']['sites'])===2&&$d['companies']['T1']['website']==='https://example.org','conserva web y centros');
check($d['links']===$before&&$d['campaign_permissions']===$perm,'plazas, historial y permisos intactos');
check(imp_plan($d,$cid,$gid,$rows)[0]['action']==='skip','reimportación idempotente');
$changed=$rows;$changed[0]['profile']['website']='https://other.example.org';
check(imp_plan($d,$cid,$gid,$changed)[0]['action']==='skip','no sobrescribe web');
$changed=$rows;$changed[0]['profile']['name']='Otro hotel';
check(imp_plan($d,$cid,$gid,$changed)[0]['action']==='error','coincidencia ambigua no se modifica');
foreach(['javascript:alert(1)','https://user:pass@example.org'] as $url)check(!company_web_valid($url),'rechaza URL peligrosa o con credenciales');
foreach(['{}','[{"name":"Falta dirección"}]'] as $json){try{imp_profile(['name'=>'X','sites_json'=>$json]);throw new RuntimeException('JSON incorrectamente aceptado');}catch(InvalidArgumentException $e){check(true,'rechaza JSON inválido');}}
ob_start();company_locations($d['companies']['T1']);$html=ob_get_clean();
check(str_contains($html,'https://example.org')&&str_contains($html,'google.com/maps/dir/')&&str_contains($html,'Ubicación orientativa'),'enlaces visibles y advertencia de ubicación');
