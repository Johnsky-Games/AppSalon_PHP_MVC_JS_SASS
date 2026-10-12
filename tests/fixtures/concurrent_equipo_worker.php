<?php
require __DIR__.'/../bootstrap.php';
$id=(int)($argv[1]??0);
if($id<=0) exit(2);
echo "READY\n";flush();
if(trim((string)fgets(STDIN))!=='GO') exit(3);
try {
    (new \Repositories\EquipoRepository())->actualizar($id,$id,'cliente',false);
    echo json_encode(['resultado'=>'cambiado'])."\n";
} catch(\DomainException $e) { echo json_encode(['resultado'=>'protegido'])."\n"; }
catch(\Throwable $e) { fwrite(STDERR,'Error de worker de permisos');exit(1); }
