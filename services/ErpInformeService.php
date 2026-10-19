<?php
namespace Services;

/** BI operativo: importes en centavos; cada agregado se calcula antes de combinar entidades. */
final class ErpInformeService extends ErpService
{
    public function rango(array $d):array
    {
        $d=\Classes\PeriodoFiltro::aplicar($d);$hoy=substr($this->r::ahora(),0,10);
        $desde=$this->fecha($d['desde']??substr($hoy,0,8).'01');$hasta=$this->fecha($d['hasta']??$hoy);
        if($hasta<$desde||(new \DateTimeImmutable($desde))->diff(new \DateTimeImmutable($hasta))->days>366)throw new ErpException('Consulta un período de hasta 366 días.');
        return compact('desde','hasta');
    }
    public function obtener(int $actor,array $d):array
    {
        $this->permitir($actor,'erp.reportes');$f=$this->rango($d);$p=[$f['desde'],$f['hasta']];
        $ventas=$this->r->uno("SELECT COUNT(*) documentos,COALESCE(SUM(total_centavos),0) bruto,COALESCE(SUM(impuesto_centavos),0) impuesto FROM erp_ventas WHERE estado='confirmada' AND confirmado_en>=? AND confirmado_en<DATE_ADD(?,INTERVAL 1 DAY)",$p);
        $creditos=$this->r->uno('SELECT COALESCE(SUM(monto_centavos),0) total FROM erp_devoluciones WHERE creado_en>=? AND creado_en<DATE_ADD(?,INTERVAL 1 DAY)',$p);
        // Caja es el libro ya existente, incluidas ventas históricas. Excluye la pasarela de pruebas que no mueve dinero.
        $caja=$this->r->uno("SELECT COALESCE(SUM(IF(tipo='ingreso',monto_centavos,0)),0) ingresos,COALESCE(SUM(IF(tipo='egreso',monto_centavos,0)),0) egresos FROM caja_movimientos WHERE registrado_en>=? AND registrado_en<DATE_ADD(?,INTERVAL 1 DAY)",$p);
        $diario=$this->r->q("SELECT DATE(registrado_en) fecha,SUM(IF(tipo='ingreso',monto_centavos,0)) ingresos_centavos,SUM(IF(tipo='egreso',monto_centavos,0)) egresos_centavos,SUM(IF(tipo='ingreso',monto_centavos,-monto_centavos)) neto_centavos FROM caja_movimientos WHERE registrado_en>=? AND registrado_en<DATE_ADD(?,INTERVAL 1 DAY) GROUP BY DATE(registrado_en) ORDER BY fecha",$p);
        $cxp=$this->r->uno('SELECT COALESCE(SUM(GREATEST(0,monto_centavos-ajuste_centavos-pagado_centavos)),0) saldo,COALESCE(SUM(IF(vence_en<CURRENT_DATE(),GREATEST(0,monto_centavos-ajuste_centavos-pagado_centavos),0)),0) vencido FROM erp_obligaciones');
        $cxc=$this->r->uno("SELECT COALESCE(SUM(GREATEST(0,v.total_centavos-COALESCE(d.credito,0)-COALESCE(p.neto,0))),0) saldo FROM erp_ventas v LEFT JOIN(SELECT venta_id,SUM(monto_centavos) credito FROM erp_devoluciones GROUP BY venta_id)d ON d.venta_id=v.id LEFT JOIN(SELECT a.venta_id,SUM(IF(p.estado='completado',a.importe_centavos-COALESCE(r.devuelto,0),0)) neto FROM erp_pago_aplicaciones a JOIN pagos p ON p.id=a.pago_id LEFT JOIN(SELECT pago_id,SUM(monto_centavos) devuelto FROM cobros_devoluciones GROUP BY pago_id)r ON r.pago_id=p.id GROUP BY a.venta_id)p ON p.venta_id=v.id WHERE v.estado='confirmada'");
        $inventario=$this->r->q("SELECT p.id,p.nombre,p.unidad,p.stock_milesimas,p.minimo_milesimas,p.costo_centavos,COALESCE(r.reservado,0) reservado FROM inventario_productos p LEFT JOIN(SELECT producto_id,SUM(cantidad_milesimas) reservado FROM erp_stock_reservas WHERE estado='activa' GROUP BY producto_id)r ON r.producto_id=p.id WHERE p.activo=1 ORDER BY (p.stock_milesimas-COALESCE(r.reservado,0)<=p.minimo_milesimas) DESC,p.nombre LIMIT 500");
        $prepagos=$this->r->uno("SELECT COALESCE(SUM(monto_centavos),0) saldo FROM erp_prepago_movimientos");
        $integraciones=$this->r->q('SELECT destino,estado,COUNT(*) cantidad FROM erp_integraciones_outbox GROUP BY destino,estado');
        return $f+compact('ventas','creditos','caja','diario','cxp','cxc','inventario','integraciones','prepagos');
    }
}
