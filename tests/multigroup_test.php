<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';
require dirname(__DIR__).'/auth.php';
require dirname(__DIR__).'/campaigns.php';
require dirname(__DIR__).'/imports.php';
define('FPDUAL_ENTRY',true);
require dirname(__DIR__).'/panels.php';
date_default_timezone_set('Atlantic/Canary');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
function check($ok,$message){if(!$ok)throw new RuntimeException($message);echo "OK $message\n";}
$d=seed(json_decode(file_get_contents(__DIR__.'/fixture.json'),true,512,JSON_THROW_ON_ERROR));camp_init($d);
$c='2026-2027';$e='T1';$g='g1';$m='authorized@example.org';
$d['companies'][$e]['emails']=$m;
$d['campaign_permissions'][$e][$m]=['allowed'=>true,'evidence'=>'fictitious test'];
$d['links'][$c][$e]['confirmed']=1;
$old=$d['links'][$c][$e];
foreach(ag_feoe_groups() as $program=>$name){if($program==='ga:1')continue;$id=str_replace(':','',$program);[$cycle,$level]=explode(':',$program);$d['courses'][$c]['groups'][$id]=['name'=>$name,'cycle'=>$cycle,'level'=>$level,'label'=>'','target'=>2,'hours'=>100,'start'=>'','end'=>''];}
$g2='af1';
$rows=imp_parse("Empresa;Localidad;Correo\nEmpresa de prueba 1;Zona de prueba;authorized@example.org\nEmpresa de prueba 1;Zona de prueba;authorized@example.org\n");
$plan=imp_plan($d,$c,$g2,$rows);
check(array_column($plan,'action')===['link','skip'],'importación reutiliza ficha y detecta duplicado del grupo');
$counts=imp_apply($d,$c,$g2,$rows,$plan);
$id=group_link($d['links'][$c],$e,$g2);
check($counts['link']===1&&count($d['companies'])===2,'no duplica empresa');
check($id!==null&&$id!==$e&&master_id($id)===$e,'identificador de colaboración independiente');
check($d['links'][$c][$e]===$old&&$d['links'][$c][$id]['confirmed']===0,'no copia plazas ni altera la colaboración anterior');
check(array_column(imp_plan($d,$c,$g2,$rows),'action')===['skip','skip'],'reimportación idempotente por grupo');
check(company($d,$id)===company($d,$e),'ficha maestra única');
check(camp_allowed($d,$id,$m),'consentimiento empresarial común');
$cfg=['mail_mode'=>'test','https_required'=>false,'public_url'=>'http://localhost','admin_email'=>'admin@example.org'];
check(camp_tokens($d,$cfg,$c,$id,$m)['{grupo}']===$d['courses'][$c]['groups'][$g2]['name'],'correo identifica el grupo correcto');
check(isset(camp_candidates($d,$c,'','',$g2)[$id]),'campaña filtrada por segundo grupo');
foreach($d['courses'][$c]['groups'] as $gid=>$group){if(group_link($d['links'][$c],$e,$gid)!==null)continue;$key=new_link_key($d['links'][$c],$e,$gid);$d['links'][$c][$key]=imp_link($gid);}
check(count(company_links($d,$c,$e))===6,'una empresa vinculada a los seis grupos');
$all=camp_candidates($d,$c,'','','');
check(count(array_filter(array_keys($all),fn($key)=>master_id($key)===$e))===1,'campaña general sin destinatario empresarial duplicado');
$d['students'][$c]['A01']['company']=$e;$d['students'][$c]['B01']=['company'=>$id,'group'=>$g2];
check(count_assigned($d,$c,$e)===1&&count_assigned($d,$c,$id)===1,'ocupación independiente');
$d['accounts']['teacher']=['grants'=>[$c=>[$g]]];$_SESSION=['role'=>'tutor','account'=>'teacher'];
$doc=['course'=>$c,'company'=>$id,'shared'=>true];
check(!auth_doc($d,$doc),'tutor no accede al documento de la misma empresa en otro grupo');
$_SESSION=['role'=>'company','company'=>$e];
check(auth_doc($d,$doc),'empresa accede a documentación compartida de segundo grupo');
$doc['shared']=false;check(!auth_doc($d,$doc),'empresa no accede a documento interno');
$_SESSION=['role'=>'company','company'=>'T2'];$doc['shared']=true;check(!auth_doc($d,$doc),'otra empresa no accede');
$dir=sys_get_temp_dir().'/fpdual-multi-'.uid();mkdir($dir,0700);
try{save_db($dir,$d);$restored=load_db($dir);check(company_links($restored,$c,$e)===company_links($d,$c,$e),'persistencia sin modificar identificadores históricos');}
finally{foreach(glob($dir.'/*') as $f)unlink($f);rmdir($dir);}
