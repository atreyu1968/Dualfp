<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib.php';require dirname(__DIR__).'/imports.php';
function check($ok,$msg){if(!$ok)throw new RuntimeException($msg);echo "OK $msg\n";}
$_SESSION=['role'=>'admin'];$d=seed([]);$cid='2026-2027';$gid='g1';
$e=imp_profile(['name'=>'Cadena prueba','zone'=>'Arona / Adeje','website'=>'https://example.org','sites_json'=>json_encode([['name'=>'Sede A','detail'=>'Los Cristianos, Arona'],['name'=>'Sede B','detail'=>'Costa Adeje'],['name'=>'Sede C','detail'=>'Playa de las Américas']])]);
$loc=directory_locations($e);check(array_column($loc,'municipality')===['Arona','Adeje',''],'municipio por sede y ambigüedad sin resolver');
check(site_municipality(['detail'=>'Avenida de prueba','municipality'=>'Santiago del Teide'],'Adeje')==='Santiago del Teide','municipio explícito prioritario');
check(site_municipality(['detail'=>'Alcalá'])==='Guía de Isora','localidad deducida');
check(site_municipality(['detail'=>'Torviscas'])==='Adeje','barrio deducido');
check(site_municipality(['detail'=>'Playa de las Américas'],'Arona / Adeje')==='','sin adivinar municipio compartido');
check(str_contains(directory_location_attrs($e),'&quot;municipality&quot;'),'JSON seguro en atributo');
$d['companies']['T1']=$e;$d['links'][$cid]['T1']=imp_link($gid);$d['links'][$cid]['T1~2']=imp_link('g2');
check(!company_delete_blockers($d,'T1'),'empresa nueva eliminable');
foreach(['confirmed'=>1,'requested'=>1,'history'=>[['note'=>'contacto']],'notes'=>'nota','next'=>'2099-01-01','status'=>'Contactada'] as $k=>$v){$x=$d;$x['links'][$cid]['T1'][$k]=$v;check((bool)company_link_delete_blockers($x,$cid,'T1'),'protege '.$k);try{company_remove($x,$cid,'T1',true);throw new RuntimeException('borrado no bloqueado');}catch(InvalidArgumentException $ex){check(isset($x['companies']['T1']),'no elimina '.$k);}}
foreach(['students','docs','campaigns','inbox','accounts','invites','interest_evidence','campaign_permissions','closed'] as $k){$x=$d;switch($k){case 'students':$x['students'][$cid]['A01']['company']='T1';break;case 'docs':$x['docs']['doc']=['course'=>$cid,'company'=>'T1'];break;case 'campaigns':$x['campaigns']['c']=['course'=>$cid,'rows'=>['T1'=>[]]];break;case 'inbox':$x['inbox']['m']=['course'=>$cid,'link'=>'T1'];break;case 'campaign_permissions':$x[$k]['T1']=['x'=>['allowed'=>true]];break;case 'closed':$x['courses'][$cid]['closed']=true;break;default:$x[$k]['x']=['company'=>'T1'];}check((bool)company_delete_blockers($x,'T1'),'protege '.$k);}
company_remove($d,$cid,'T1~2',false);check(isset($d['companies']['T1'],$d['links'][$cid]['T1'])&&!isset($d['links'][$cid]['T1~2']),'desvinculación conserva ficha y otros grupos');
$d['links'][$cid]['T1~2']=imp_link('g2');company_remove($d,$cid,'T1',true);check(!isset($d['companies']['T1'],$d['links'][$cid]['T1'],$d['links'][$cid]['T1~2'])&&count($d['audit'])===2,'eliminación global de ficha sin actividad y auditoría');
