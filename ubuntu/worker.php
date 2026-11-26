<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
date_default_timezone_set('Atlantic/Canary');
$base=getenv('FPDUAL_TEST_BASE')?:'/var/www/fpdual';
require $base.'/lib.php';require $base.'/auth.php';require $base.'/campaigns.php';
$cfg=require (getenv('FPDUAL_TEST_CONFIG')?:'/etc/fpdual/config.php');$dir=$cfg['data_dir'];
$lock=fopen($dir.'/lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(0);
$d=load_db($dir);$cfg=runtime_config($cfg,$d);if(($cfg['mail_mode']??'')==='test'&&!($cfg['https_required']??true))$_SERVER['REMOTE_ADDR']='127.0.0.1';ib_tick($d,$dir);camp_tick($d,$cfg,$dir);
