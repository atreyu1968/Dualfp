<?php
declare(strict_types=1);
require_once __DIR__.'/inbox.php';
function auth_init(array &$d): void {foreach(['registrations','resets','auth_limits'] as $k)$d[$k]??=[];foreach($d['invites'] as $k=>$r)if($r['expires']<time())unset($d['invites'][$k]);foreach($d['resets'] as $k=>$r)if($r['expires']<time())unset($d['resets'][$k]);foreach($d['registrations'] as $k=>$r)if(!$r['verified']&&$r['expires']<time())unset($d['registrations'][$k]);foreach($d['auth_limits'] as $k=>$r)if($r['until']<time())unset($d['auth_limits'][$k]);}
function auth_limit(array &$d,string $dir,string $action,string $mail,int $limit=4):void {foreach(['ip:'.($_SERVER['REMOTE_ADDR']??'local')=>20,'mail:'.strtolower($mail)=>$limit] as $k=>$max){$k=hash('sha256',$action.':'.$k);$r=$d['auth_limits'][$k]??['until'=>time()+3600,'n'=>0];if($r['until']<time())$r=['until'=>time()+3600,'n'=>0];if($r['n'] >= $max)fail('Demasiadas solicitudes. Inténtelo más tarde.',429);$r['n']++;$d['auth_limits'][$k]=$r;}save_db($dir,$d);}
function new_password():string {$p=$_POST['password']??'';$r=$_POST['repeat']??'';if(!is_string($p)||strlen($p)<14||strlen($p)>72||$p!==$r||str_contains($p,"\0"))fail('Contraseña de 14 a 72 bytes, sin caracteres nulos, y repetición coincidente');return password_hash($p,PASSWORD_DEFAULT);}
function admin_hash(array $d,array $cfg):string {return ($d['admin_override']['email']??'')===strtolower($cfg['admin_email'])?$d['admin_override']['hash']:$cfg['admin_password_hash'];}
function account_by_email(array $d,string $mail):?string {foreach($d['accounts'] as $id=>$a)if(strcasecmp($a['email'],$mail)===0)return (string)$id;return null;}
function auth_url(array $cfg,string $view,string $token=''):string {$base=rtrim($cfg['public_url']??'','/');if(!filter_var($base,FILTER_VALIDATE_URL)||(!str_starts_with($base,'https://')&&($cfg['https_required']??true)))fail('Configure la URL pública del servicio',503);return $base.'/index.php?v='.rawurlencode($view).($token!==''?'&t='.rawurlencode($token):'');}
function resend_mail(array $cfg,string $dir,string $to,string $subject,string $body,string $idempotency="",?string &$providerId=null):bool {
 $providerId=null;
 if(!filter_var($to,FILTER_VALIDATE_EMAIL))return false;
 if(($cfg['mail_mode']??'resend')==='test'&&($cfg['https_required']??true)===false&&in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){ $f=$dir.'/mail-test.jsonl';$ok=file_put_contents($f,json_encode(compact('to','subject','body'),JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX)!==false;@chmod($f,0600);return $ok; }
 $key=$cfg['resend_api_key']??'';$from=$cfg['resend_from']??'';if(!$key||!$from||!function_exists('curl_init')){error_log('FP Dual: Resend no configurado o PHP curl ausente');return false;}
 $payload=['from'=>$from,'to'=>[$to],'subject'=>$subject,'text'=>$body];if(filter_var($cfg['resend_reply_to']??'',FILTER_VALIDATE_EMAIL))$payload['reply_to']=$cfg['resend_reply_to'];
 $ch=curl_init('https://api.resend.com/emails');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json','Idempotency-Key: '.($idempotency?:uid())],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$decoded=is_string($res)?json_decode($res,true):null;$ok=$code>=200&&$code<300&&is_array($decoded)&&is_string($decoded['id']??null)&&$decoded['id']!=='';if($ok)$providerId=$decoded['id'];if(!$ok)error_log('FP Dual: fallo Resend HTTP '.$code);return $ok;
}
function send_link(array $cfg,string $dir,string $mail,string $subject,string $intro,string $view,string $token):bool {if(!mail_ready($cfg))return false;return resend_mail($cfg,$dir,$mail,$subject,$intro."\n\n".auth_url($cfg,$view,$token)."\n\nSi no solicitó este mensaje, ignórelo.\nDualFP Conecta · Administración y Gestión");}
function registration_open(array $d):bool {return !empty($d['settings']['registration'])&&!empty($d['settings']['privacy'])&&!$d['courses'][$d['settings']['active']]['closed']&&!empty($d['courses'][$d['settings']['active']]['groups']);}
function flash(string $m):void {$_SESSION['flash']=$m;}

// Solo se permiten estos parámetros de aplicación; nunca se alteran rutas o seguridad del servidor.
function runtime_config(array $cfg,array $d):array {
 foreach(['public_url','resend_api_key','resend_from','resend_reply_to'] as $k)if(array_key_exists($k,$d['mail_config']??[]))$cfg[$k]=$d['mail_config'][$k];
 if(!empty($d['admin_identity']['email']))$cfg['admin_email']=$d['admin_identity']['email'];return $cfg;
}
function valid_public_url(string $url):bool {if(!filter_var($url,FILTER_VALIDATE_URL))return false;$p=parse_url($url);return ($p['scheme']??'')==='https'&&!empty($p['host'])&&!isset($p['user'])&&!isset($p['pass'])&&!isset($p['query'])&&!isset($p['fragment']);}
function mail_ready(array $cfg):bool {return (($cfg['mail_mode']??'')==='test'&&!($cfg['https_required']??true))||(valid_public_url($cfg['public_url']??'')&&!empty($cfg['resend_api_key'])&&!empty($cfg['resend_from']));}
