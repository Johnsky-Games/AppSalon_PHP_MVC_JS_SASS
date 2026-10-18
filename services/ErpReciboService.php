<?php
namespace Services;
use Repositories\ComprobanteRepository;
final class ErpReciboService extends ErpService
{
    public function emitir(int $actor,int $venta,int $evento):int
    {
        $d=(new ErpVentaService($this->r))->detalle($actor,$venta);
        $d['empresa']=$this->r->uno('SELECT nombre,identificacion,direccion FROM erp_empresa WHERE id=1');
        $d['movimiento']=$this->r->uno('SELECT tipo,datos_json,creado_en FROM erp_eventos WHERE id=?',[$evento]);
        $d['leyenda']=ComprobanteService::LEYENDA;unset($d['eventos']);
        $json=json_encode($d,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->r->q("INSERT IGNORE INTO comprobantes_secuencia(serie,ultimo) SELECT 'PV',COALESCE(MAX(CAST(SUBSTRING(codigo,4) AS UNSIGNED)),0) FROM erp_recibos");
        $numero=(new ComprobanteRepository($this->r))->siguienteNumero('PV');$codigo='PV-'.str_pad((string)$numero,8,'0',STR_PAD_LEFT);
        $this->r->q('INSERT INTO erp_recibos(codigo,venta_id,evento_id,contenido_json,contenido_sha256,creado_en) VALUES(?,?,?,?,?,?)',[$codigo,$venta,$evento,$json,hash('sha256',$json),$this->r::ahora()]);return $this->r->id();
    }
    public function obtener(int $actor,int $id):array
    {
        $this->permitir($actor,'erp.ventas.ver');$r=$this->r->uno('SELECT * FROM erp_recibos WHERE id=?',[$id])??throw new ErpException('Recibo no encontrado.',404);
        if(!hash_equals($r['contenido_sha256'],hash('sha256',$r['contenido_json'])))throw new ErpException('No se pudo verificar la integridad del recibo.',409);
        $r['contenido']=json_decode($r['contenido_json'],true,512,JSON_THROW_ON_ERROR);return $r;
    }
}
