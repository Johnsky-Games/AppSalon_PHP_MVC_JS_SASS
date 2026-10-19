<?php
namespace Services;

use Classes\{Email,Transaccion};
use Repositories\CorreoSalidaRepository;
use Services\Fiscal\{SriAutorizacion,SriRide,SriRidePdf};

/** Entrega transaccional: evidencia congelada en la cola existente, sin SMTP en la petición. */
final class ErpFiscalCorreoService extends ErpService
{
    public const TIPO='comprobante_fiscal';
    private const MAX_BYTES=5*1024*1024;
    public static function prefijo(int $documento):string { return 'fiscal:'.$documento.':'; }

    public function programar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','fiscal_correo',$d,function()use($actor,$d){
            $id=$this->id($d['documento_id']??null);
            if(($d['confirmar_destinatario']??null)!=='1')throw new ErpException('Confirma la dirección del destinatario del comprobante.');
            $email=strtolower($this->texto($d['email']??'',120));
            if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new ErpException('Indica un correo válido para entregar el comprobante.');
            $doc=$this->r->uno('SELECT id FROM erp_fiscal_documentos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);
            $clave=self::prefijo($id).substr(hash('sha256',$email),0,48);
            if($this->r->uno('SELECT id FROM correos_salida WHERE clave_unica=? FOR SHARE',[$clave]))return $id;
            [$xml,$ride]=$this->evidencia($id);
            $pdf=SriRidePdf::generar($ride);
            if(strlen($xml)+strlen($pdf)>self::MAX_BYTES)throw new ErpException('Los adjuntos fiscales superan el límite de 5 MB.');
            $nombre=mb_substr($ride['factura']['razonSocialComprador'],0,120);
            $carga=['esquema'=>1,'documento_id'=>$id,'email'=>$email,'nombre'=>$nombre,'xml'=>base64_encode($xml),'pdf'=>base64_encode($pdf),'xml_sha256'=>hash('sha256',$xml),'pdf_sha256'=>hash('sha256',$pdf)];
            $correo=(new CorreoSalidaRepository($this->r->db))->encolar($clave,self::TIPO,$email,$nombre,$carga);
            $this->evento($actor,'fiscal_entrega_programada','fiscal_documento',$id,['correo_id'=>$correo,'destinatario_sha256'=>hash('sha256',$email),'xml_sha256'=>$carga['xml_sha256'],'pdf_sha256'=>$carga['pdf_sha256']]);
            return $id;
        });
    }

    public function reintentar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.cobrar','fiscal_correo_reintentar',$d,function()use($actor,$d){
            $id=$this->id($d['documento_id']??null);$correo=$this->id($d['correo_id']??null);
            $this->evidencia($id);
            $fila=$this->r->uno('SELECT id,estado,carga_cifrada FROM correos_salida WHERE id=? AND tipo=? AND clave_unica LIKE ? FOR UPDATE',[$correo,self::TIPO,self::prefijo($id).'%'])??throw new ErpException('Entrega fiscal no encontrada.',404);
            if($fila['estado']!=='fallido')return $id;
            if($fila['carga_cifrada']===null)throw new ErpException('La entrega no conserva adjuntos recuperables. Revisa la evidencia.',409);
            (new CorreoSalidaRepository($this->r->db))->reintentar($correo);
            $this->evento($actor,'fiscal_entrega_reintentada','fiscal_documento',$id,['correo_id'=>$correo]);return $id;
        });
    }

    public function listar(int $actor,int $documento):array
    {
        $this->permitir($actor,'erp.cobrar');
        return $this->r->q('SELECT id,destinatario_email,estado,intentos,ultimo_error,enviado_en,carga_cifrada IS NOT NULL reintentable FROM correos_salida WHERE tipo=? AND clave_unica LIKE ? ORDER BY id DESC LIMIT 50',[self::TIPO,self::prefijo($documento).'%']);
    }

    /** Worker: verifica el destino y los adjuntos congelados antes de tocar SMTP. No depende del rol actual del solicitante. */
    public function preparar(array $carga,string $email,string $nombre):\PHPMailer\PHPMailer\PHPMailer
    {
        if(Transaccion::nivel($this->r->db)!==0)throw new \LogicException('El despacho fiscal debe ocurrir fuera de la transacción.');
        if(($carga['esquema']??null)!==1||($carga['email']??null)!==$email||($carga['nombre']??null)!==$nombre)throw new ErpException('La carga fiscal no corresponde al destinatario.',409);
        $id=$this->id($carga['documento_id']??null);[$vigente,$ride]=$this->evidencia($id);
        $archivos=[];
        foreach(['xml','pdf'] as $tipo){
            $base=$carga[$tipo]??null;$hash=$carga[$tipo.'_sha256']??null;
            if(!is_string($base)||strlen($base)>7*1024*1024||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new ErpException('Adjunto fiscal ilegible.',409);
            $bin=base64_decode($base,true);
            if($bin===false||$bin===''||strlen($bin)>self::MAX_BYTES||!hash_equals($hash,hash('sha256',$bin)))throw new ErpException('No se pudo verificar el adjunto fiscal.',409);
            $archivos[$tipo]=$bin;
        }
        if(strlen($archivos['xml'])+strlen($archivos['pdf'])>self::MAX_BYTES||!hash_equals($vigente,$archivos['xml'])||!str_starts_with($archivos['pdf'],'%PDF-'))throw new ErpException('Los adjuntos no corresponden a la evidencia fiscal.',409);
        $e=$ride['emisor'];$numero=$e['estab'].'-'.$e['ptoEmi'].'-'.$e['secuencial'];
        $prueba=$e['ambiente']==='1';$titulo=($prueba?'PRUEBAS · ':'').'Factura '.$numero;
        $texto=($prueba?"AMBIENTE DE PRUEBAS · SIN VALIDEZ TRIBUTARIA\n\n":'').$e['razonSocial']."\nRUC: ".$e['ruc']."\n".$titulo."\nComprador: ".$ride['factura']['razonSocialComprador']."\nTotal USD: ".$ride['factura']['importeTotal']."\n\nSe adjuntan el XML autorizado y su representación impresa en PDF. Las formas de pago del comprobante no acreditan un nuevo cobro.\nEste correo es transaccional y no modifica tus preferencias de promociones.";
        $html='<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;padding:24px"><h1 style="font-size:22px">'.htmlspecialchars($titulo,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h1><p style="white-space:pre-line;line-height:1.6">'.nl2br(htmlspecialchars($texto,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p></div>';
        $mail=Email::prepararMailerNotificacionCita($email,$nombre,$titulo,$html,$texto);
        $dominio=substr(strrchr(Email::resolverRemitente()['email'],'@'),1);
        $mail->MessageID='<fiscal.'.hash('sha256',self::prefijo($id).$email).'@'.$dominio.'>';
        $mail->addStringAttachment($archivos['xml'],'factura-'.$numero.'-autorizada.xml','base64','application/xml');
        $mail->addStringAttachment($archivos['pdf'],'factura-'.$numero.'-ride.pdf','base64','application/pdf');
        return $mail;
    }

    private function evidencia(int $id):array
    {
        $doc=$this->r->uno('SELECT * FROM erp_fiscal_documentos WHERE id=? FOR SHARE',[$id])??throw new ErpException('Documento fiscal no encontrado.',404);
        if($doc['estado']!=='autorizada'||!hash_equals($doc['xml_sha256'],hash('sha256',$doc['xml_firmado'])))throw new ErpException('La entrega exige un comprobante autorizado e íntegro.',409);
        try{
            $r=json_decode($doc['respuesta_json']??'null',true,512,JSON_THROW_ON_ERROR);
            if(!is_array($r))throw new \RuntimeException('Falta la evidencia.');
            $xml=SriAutorizacion::exportar($r,$doc['xml_firmado']);return [$xml,SriRide::datos($xml)];
        }catch(\RuntimeException|\InvalidArgumentException|\JsonException){throw new ErpException('La autorización no contiene evidencia verificable.',409);}
    }
}
