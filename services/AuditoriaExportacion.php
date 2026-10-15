<?php
namespace Services;

/**
 * Auditoría de una exportación tabular de caja. Contrato del flujo §7: autorizar → generar → AUDITAR (si falla, no se entrega) → entregar.
 *
 * Implementación provisional: `AuditoriaExportacionCaja` (tabla propia `caja_exportaciones_auditoria`, migración 024). Cuando exista la
 * auditoría general de la migración 026 (`exportaciones_auditoria_general`), basta con devolver su implementación desde
 * `CajaController::auditoria()`: el controlador solo depende de esta interfaz.
 */
interface AuditoriaExportacion
{
    /**
     * @param array{origen:string,actor_id:?int,actor_rol:?string,formato:string,resultado:'exitosa'|'denegada'|'fallida',motivo?:?string,filtros?:array<string,mixed>,
     *              filas?:?int,columnas?:?int,truncado?:bool,bytes?:?int,sha256?:?string} $evento
     * @throws \Throwable si no se pudo registrar (el llamador NO debe entregar el archivo)
     */
    public function registrar(array $evento): void;
}
