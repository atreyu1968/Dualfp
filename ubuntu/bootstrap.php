<?php
// CLI only: credentials read from stdin, never passed as command-line arguments.
if(PHP_SAPI!=='cli')exit(1);
$email=strtolower(trim(fgets(STDIN)?:''));$pw=rtrim(fgets(STDIN)?:'',"\r\n");
if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pw)<14||strlen($pw)>72||str_contains($pw,"\0")){fwrite(STDERR,"Correo o contraseña inválidos.\n");exit(1);}
$path='/etc/fpdual/config.php';$cfg=['data_dir'=>'/var/lib/fpdual','admin_email'=>$email,'admin_password_hash'=>password_hash($pw,PASSWORD_DEFAULT),'public_url'=>'','https_required'=>true,'resend_api_key'=>'','resend_from'=>'','resend_reply_to'=>'','mail_mode'=>'resend'];
// Exclusive creation: never replace a live installation.
umask(0027);$f=@fopen($path,'x');if(!$f){fwrite(STDERR,"Configuración ya existente o no escribible.\n");exit(1);}if(fwrite($f,"<?php\nreturn ".var_export($cfg,true).";\n")===false){fclose($f);unlink($path);exit(1);}fclose($f);chmod($path,0640);chgrp($path,'www-data');
