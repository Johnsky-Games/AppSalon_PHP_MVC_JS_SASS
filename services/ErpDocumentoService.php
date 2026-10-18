<?php
namespace Services;
use Classes\Secretos;

/** Documentos versionados y cifrados. La autorización se comprueba también al descargar. */
final class ErpDocumentoService extends ErpService
{
    public function acceso(int $actor,string $entidad,int $id,bool $escribir=false):void
    {
        $tabla=match($entidad){'colaborador'=>'colaboradores','venta'=>'erp_ventas','compra'=>'erp_compras','activo'=>'erp_activos',default=>throw new ErpException('Tipo de expediente no válido.')};
        $f=$this->r->uno("SELECT * FROM $tabla WHERE id=?",[$id])??throw new ErpException('Expediente no encontrado.',404);
        if($entidad==='colaborador'){
            if((new AuthorizationService($this->r->db))->permite($actor,'erp.rh'))return;
            if(!$escribir&&(int)$f['usuario_id']===$actor){$this->permitir($actor,'erp.documentos.propios');return;}
            throw new ErpException('Expediente privado de Recursos Humanos.',403);
        }
        $this->permitir($actor,match($entidad){'venta'=>'erp.ventas.ver','compra'=>'erp.compras','activo'=>'erp.recursos'});
    }
    public function subir(int $actor,array $d):array
    {
        $entidad=is_string($d['entidad']??null)?$d['entidad']:'';$id=$this->id($d['entidad_id']??null);$this->acceso($actor,$entidad,$id,true);
        $bytes=$d['contenido']??null;if(!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>5*1024*1024)throw new ErpException('Selecciona un archivo de hasta 5 MB.');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $ext=match($mime){'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','text/plain'=>'txt',default=>throw new ErpException('Solo se admiten PDF, JPEG, PNG y texto plano.')};
        $hash=hash('sha256',$bytes);$nombre=preg_replace('/[^\pL\pN _.-]/u','',basename((string)($d['nombre_archivo']??'documento')));$nombre=mb_substr(pathinfo($nombre,PATHINFO_FILENAME),0,100).'.'.$ext;
        $entrada=$d;unset($entrada['contenido']);$entrada['archivo_sha256']=$hash;$entrada['nombre_archivo']=$nombre;
        $permiso=match($entidad){'colaborador'=>'erp.rh','venta'=>'erp.ventas.ver','compra'=>'erp.compras','activo'=>'erp.recursos'};
        return $this->ejecutar($actor,$permiso,'documento_subir',$entrada,function()use($actor,$d,$entidad,$id,$bytes,$mime,$nombre,$hash){
            $this->acceso($actor,$entidad,$id,true);$titulo=$this->texto($d['titulo']??'',180);$vence=empty($d['vence_en'])?null:$this->fecha($d['vence_en']);
            $vis=($d['visibilidad']??'privado')==='titular'?'titular':'privado';
            $prev=empty($d['reemplaza_id'])?null:$this->id($d['reemplaza_id']);
            if($prev&&!$this->r->uno('SELECT id FROM erp_documentos WHERE id=? AND entidad=? AND entidad_id=? FOR UPDATE',[$prev,$entidad,$id]))throw new ErpException('La versión anterior pertenece a otro expediente.');
            $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$cifrado=base64_encode($nonce.sodium_crypto_secretbox($bytes,$nonce,Secretos::clave('erp.documentos')));
            $this->r->q('INSERT INTO erp_documentos(entidad,entidad_id,titulo,nombre_archivo,mime,bytes,contenido_cifrado,contenido_sha256,visibilidad,reemplaza_id,vence_en,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$entidad,$id,$titulo,$nombre,$mime,strlen($bytes),$cifrado,$hash,$vis,$prev,$vence,$actor,$this->r::ahora()]);$doc=$this->r->id();
            $this->evento($actor,'documento_incorporado',$entidad,$id,['documento_id'=>$doc,'sha256'=>$hash,'reemplaza_id'=>$prev]);return $id;
        });
    }
    public function listar(int $actor,string $entidad,int $id):array
    {
        $this->acceso($actor,$entidad,$id);$titular=$entidad==='colaborador'&&!(new AuthorizationService($this->r->db))->permite($actor,'erp.rh');
        return $this->r->q('SELECT id,titulo,nombre_archivo,mime,bytes,reemplaza_id,vence_en,creado_en FROM erp_documentos WHERE entidad=? AND entidad_id=?'.($titular?" AND visibilidad='titular'":'').' ORDER BY id DESC LIMIT 200',[$entidad,$id]);
    }
    public function descargar(int $actor,int $id):array
    {
        $doc=$this->r->uno('SELECT * FROM erp_documentos WHERE id=?',[$id])??throw new ErpException('Documento no encontrado.',404);
        $this->acceso($actor,$doc['entidad'],(int)$doc['entidad_id']);
        if($doc['entidad']==='colaborador'&&$doc['visibilidad']!=='titular'&&!(new AuthorizationService($this->r->db))->permite($actor,'erp.rh'))throw new ErpException('Documento privado.',403);
        $raw=base64_decode($doc['contenido_cifrado'],true);$bytes=$raw===false||strlen($raw)<SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES?false:sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),Secretos::clave('erp.documentos'));
        if($bytes===false||!hash_equals($doc['contenido_sha256'],hash('sha256',$bytes)))throw new ErpException('No se pudo verificar la integridad del documento.',409);
        unset($doc['contenido_cifrado']);$doc['contenido']=$bytes;return $doc;
    }
}
