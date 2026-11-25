<?php
// Carga privada de datos iniciales. No ejecutar mediante HTTP.
if(PHP_SAPI!=='cli')exit(1);
try {
    $p=$argv[1]??'';
    if(!is_file($p)||filesize($p)>5*1024*1024)throw new Exception('Archivo no válido o demasiado grande');
    $rows=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($rows)||!array_is_list($rows)||count($rows)>2000)throw new Exception('Se esperaba una lista de hasta 2000 empresas');
    $seen=[];
    foreach($rows as $e){
        if(!is_array($e))throw new Exception('Registro inválido');
        foreach(['id','name','zone','sector','kind','emails','phone','contact','status','notes','next'] as $k)if(!isset($e[$k])||!is_string($e[$k]))throw new Exception('Campo obligatorio no válido: '.$k);
        if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/D',$e['id'])||isset($seen[$e['id']])||trim($e['name'])==='')throw new Exception('Identificador duplicado o nombre inválido');
        $seen[$e['id']]=true;
        foreach(['requested','confirmed'] as $k)if(!isset($e[$k])||!is_int($e[$k])||$e[$k]<0||$e[$k]>1000)throw new Exception('Número de plazas no válido');
        if(!is_array($e['history']??null))throw new Exception('Historial no válido');
        if(!in_array($e['kind'],['Empresa','Organismo','Asociación','Intermediario'],true))throw new Exception('Tipo no válido');
        if(!in_array($e['status'],['Identificada','Contactada','Sin respuesta','En negociación','Interesada','Confirmada','Convenio formalizado','Rechazada'],true))throw new Exception('Estado no válido');
    }
    echo count($rows)." empresas validadas.\n";
} catch(Throwable $e){fwrite(STDERR,'Carga privada rechazada: '.$e->getMessage()."\n");exit(1);}
