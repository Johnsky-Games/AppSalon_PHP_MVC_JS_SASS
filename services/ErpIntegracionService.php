<?php
namespace Services;
use Interfaces\IntegracionErpInterface;

/** Entrega como mínimo una vez, con referencia estable y conciliación del proveedor; sin llamadas externas en transacción SQL. */
final class ErpIntegracionService extends ErpService
{
    public function procesar(array $adaptadores,int $limite=20):array
    {
        if(PHP_SAPI!=='cli')throw new ErpException('Procesamiento reservado al supervisor.',403);
        $resultado=['confirmados'=>0,'fallidos'=>0];
        foreach($adaptadores as $destino=>$adaptador){
            if(!in_array($destino,['fiscal','contabilidad','nomina'],true)||!$adaptador instanceof IntegracionErpInterface)throw new \InvalidArgumentException('Adaptador ERP no válido.');
            for($i=0;$i<max(1,min(100,$limite));$i++){
                $fila=\Classes\Transaccion::ejecutar($this->r->db,function()use($destino){
                    $f=$this->r->uno("SELECT * FROM erp_integraciones_outbox WHERE destino=? AND (estado='sin_configurar' OR (estado IN ('pendiente','fallido') AND (proximo_intento IS NULL OR proximo_intento<=?)) OR (estado='enviando' AND lease_hasta<?)) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED",[$destino,$this->r::ahora(),$this->r::ahora()]);
                    if(!$f)return null;$f['lease_token']=bin2hex(random_bytes(16));
                    $this->r->q("UPDATE erp_integraciones_outbox SET estado='enviando',lease_token=?,lease_hasta=DATE_ADD(?,INTERVAL 5 MINUTE),intentos=intentos+1,actualizado_en=? WHERE id=?",[$f['lease_token'],$this->r::ahora(),$this->r::ahora(),(int)$f['id']]);return $f;
                });
                if(!$fila)break;
                try{
                    $r=$adaptador->consultar($fila['referencia']);
                    if($r===null)$r=$adaptador->enviar($fila['referencia'],json_decode($fila['payload_json'],true,512,JSON_THROW_ON_ERROR));
                    if(($r['estado']??'')!=='confirmado'||!is_string($r['referencia_externa']??null)||$r['referencia_externa']==='')throw new \RuntimeException('Respuesta no confirmada.');
                    // Persistir solo referencia y metadatos permitidos, nunca credenciales o cargas arbitrarias del proveedor.
                    $respuesta=json_encode(['estado'=>'confirmado','referencia_externa'=>mb_substr($r['referencia_externa'],0,180)],JSON_THROW_ON_ERROR);
                    $this->r->q("UPDATE erp_integraciones_outbox SET estado='confirmado',respuesta_json=?,lease_token=NULL,lease_hasta=NULL,actualizado_en=? WHERE id=? AND lease_token=?",[$respuesta,$this->r::ahora(),(int)$fila['id'],$fila['lease_token']]);$resultado['confirmados']++;
                }catch(\Throwable $e){
                    $espera=min(86400,60*(2**min(10,(int)$fila['intentos'])));
                    $this->r->q("UPDATE erp_integraciones_outbox SET estado='fallido',respuesta_json=?,lease_token=NULL,lease_hasta=NULL,proximo_intento=DATE_ADD(?,INTERVAL ? SECOND),actualizado_en=? WHERE id=? AND lease_token=?",[json_encode(['error'=>'Requiere reintento o conciliación','clase'=>get_class($e)]),$this->r::ahora(),$espera,$this->r::ahora(),(int)$fila['id'],$fila['lease_token']]);$resultado['fallidos']++;
                }
            }
        }return $resultado;
    }
}
