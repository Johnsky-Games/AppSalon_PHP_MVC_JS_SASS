<?php
namespace Services;

use Classes\ServiceUnavailableException;
use Model\ActiveRecord;
use mysqli;

/** Roles comprobados en servidor; nunca autoriza con el rol enviado por el navegador. */
class AuthorizationService
{
    public const PERMISOS = ['equipo.gestionar','servicios.gestionar','profesionales.gestionar','perfiles.gestionar','agenda.gestionar','agenda.ver_propia','agenda.regularizar','agenda.solicitar_regularizacion',
        'finanzas.gestionar','inventario.gestionar','marketing.gestionar','ajustes.gestionar','reportes.ver',
        // Fase identidades/CRM: alcance de datos de clientes y gestión de personal (RR. HH.).
        'clientes.ver','clientes.ver_propios','clientes.analitica','clientes.exportar','rh.gestionar',
        'erp.ventas.ver','erp.ventas.crear','erp.cobrar','erp.caja','erp.caja.movimientos','erp.catalogo','erp.configurar','erp.descuento','erp.verificar','erp.devolver','erp.compras','erp.rh','erp.promociones','erp.comisiones','erp.aprobaciones','erp.reportes','erp.consumos.propios','erp.obligaciones.pagar','erp.recursos','erp.consumos','erp.documentos.propios',
        // Contabilidad propia: leer libros y estados, registrar/reversar asientos, y configurar plan, roles y cierres (segregados).
        'contabilidad.ver','contabilidad.registrar','contabilidad.configurar',
        // Nómina propia: leer, preparar, revisar/aprobar y configurar parámetros legales (segregados); el recibo propio lo ve cada colaborador.
        'nomina.ver','nomina.calcular','nomina.aprobar','nomina.configurar','nomina.recibo_propio'];
    public const ROLES = [
        'propietario' => self::PERMISOS,
        'gerente' => ['servicios.gestionar','profesionales.gestionar','perfiles.gestionar','agenda.gestionar','agenda.regularizar','finanzas.gestionar','inventario.gestionar','marketing.gestionar','ajustes.gestionar','reportes.ver','clientes.ver','clientes.analitica','clientes.exportar','erp.ventas.ver','erp.ventas.crear','erp.cobrar','erp.caja','erp.caja.movimientos','erp.catalogo','erp.descuento','erp.verificar','erp.devolver','erp.compras','erp.promociones','erp.comisiones','erp.aprobaciones','erp.reportes','erp.consumos.propios','erp.obligaciones.pagar','erp.recursos','erp.consumos','erp.documentos.propios','contabilidad.ver','nomina.recibo_propio'],
        'recepcion' => ['perfiles.gestionar','agenda.gestionar','clientes.ver','erp.ventas.ver','erp.ventas.crear','erp.cobrar','erp.caja','erp.documentos.propios','nomina.recibo_propio'],
        'profesional' => ['agenda.ver_propia','agenda.solicitar_regularizacion','clientes.ver_propios','erp.consumos.propios','erp.documentos.propios','nomina.recibo_propio'],
        'finanzas' => ['finanzas.gestionar','inventario.gestionar','reportes.ver','clientes.analitica','erp.ventas.ver','erp.ventas.crear','erp.cobrar','erp.caja','erp.caja.movimientos','erp.catalogo','erp.verificar','erp.devolver','erp.compras','erp.comisiones','erp.aprobaciones','erp.reportes','erp.consumos.propios','erp.obligaciones.pagar','erp.recursos','erp.consumos','erp.documentos.propios','contabilidad.ver','contabilidad.registrar','nomina.ver','nomina.calcular','nomina.recibo_propio'],
        'cliente' => [],
    ];
    private ?mysqli $db;
    public function __construct(?mysqli $db = null) { $this->db = $db ?? ActiveRecord::getDB(); }

    /**
     * Contexto de acceso de una cuenta.
     *
     * Devuelve null ÚNICAMENTE cuando la cuenta no existe. Un fallo técnico (conexión, consulta, esquema
     * desactualizado) NUNCA se confunde con una cuenta inexistente o inactiva: lanza
     * ServiceUnavailableException para responder 503 y registrar un diagnóstico sanitizado.
     * La autorización sigue fallando cerrada: sin contexto verificado no se concede nada.
     *
     * Campos: activo=0 cubre acceso laboral desactivado y membresía cliente suspendida;
     * cliente_estado es el estado de la membresía cliente (null si aún no está materializada).
     */
    public function contexto(int $id): ?array
    {
        if ($id <= 0) { return null; }
        if (!$this->db) { throw new ServiceUnavailableException('Autorización no disponible: sin conexión.'); }
        $rolEfectivo = "COALESCE(a.rol,IF(u.admin=1,'propietario','cliente'))";
        // Cada membresía tiene su propio estado. `activo` describe el acceso PROPIO del rol efectivo:
        //  · rol laboral → acceso laboral vigente (acceso activo y colaborador activo); la suspensión de su
        //    membresía de cliente no lo afecta;
        //  · rol cliente → acceso activo y membresía de cliente no suspendida.
        // La vigencia de una sesión concreta depende de su portal: ver sesionVigente().
        $activoLaboral = "(COALESCE(a.activo,1)=1 AND COALESCE(col.estado,'activo')='activo')";
        $activoCliente = "(COALESCE(a.activo,1)=1 AND COALESCE(c.estado,'')<>'suspendido')";
        $sql = "SELECT u.id,u.admin,u.confirmado,u.version_sesion,{$rolEfectivo} rol,"
            . "IF({$rolEfectivo}='cliente',IF({$activoCliente},1,0),IF({$activoLaboral},1,0)) activo,"
            . "c.estado cliente_estado,col.estado colaborador_estado,p.id profesional_id "
            . "FROM usuarios u LEFT JOIN usuario_accesos a ON a.usuario_id=u.id LEFT JOIN clientes c ON c.usuario_id=u.id "
            . "LEFT JOIN colaboradores col ON col.usuario_id=u.id "
            . "LEFT JOIN profesionales p ON p.usuarioId=u.id WHERE u.id=?";
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new \RuntimeException('prepare', (int)$this->db->errno); }
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) { $errno = (int)$stmt->errno; $stmt->close(); throw new \RuntimeException('execute', $errno); }
            $res = $stmt->get_result();
            if ($res === false) { $errno = (int)$stmt->errno; $stmt->close(); throw new \RuntimeException('result', $errno); }
            $row = $res->fetch_assoc(); $stmt->close();
            return $row ?: null;
        } catch (\Throwable $e) {
            // Códigos MySQL 1054/1146 = columna/tabla ausente: instalación sin migrar (p. ej. falta la 010 o la 016).
            $errno = $e->getCode() ?: (int)($this->db->errno ?? 0);
            $pista = in_array($errno, [1054, 1146], true) ? ' ESQUEMA DESACTUALIZADO: ejecuta "php database/migrador.php up" con respaldo previo.' : '';
            error_log('[AuthorizationService] contexto no disponible errno=' . $errno . ' etapa=' . $e->getMessage() . '.' . $pista);
            throw new ServiceUnavailableException('Autorización no disponible.', 0, $e);
        }
    }

    /**
     * Materializa la membresía cliente de una cuenta legada (sin acceso laboral) la primera vez que ingresa.
     * Idempotente y nunca reactiva: una membresía suspendida existente se conserva.
     */
    public function asegurarMembresiaCliente(int $id): void
    {
        if ($id <= 0 || !$this->db) { return; }
        $stmt = $this->db->prepare("INSERT IGNORE INTO clientes(usuario_id,estado,origen) VALUES (?,'activo','migracion')");
        if (!$stmt) { throw new ServiceUnavailableException('Membresía de cliente no disponible.'); }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute(); $stmt->close();
        if (!$ok) { throw new ServiceUnavailableException('Membresía de cliente no disponible.'); }
    }

    /**
     * ¿Sigue vigente una sesión abierta en `$portal` para este contexto?
     *  · equipo: rol laboral con acceso vigente;
     *  · cliente: membresía de cliente no suspendida (una cuenta solo laboral sin membresía no tiene portal de clientes).
     */
    public static function sesionVigente(array $contexto, string $portal): bool
    {
        if ($portal === 'equipo') {
            return $contexto['rol'] !== 'cliente' && (int)$contexto['activo'] === 1;
        }
        if ($contexto['rol'] === 'cliente') {
            return (int)$contexto['activo'] === 1;
        }
        return ($contexto['cliente_estado'] ?? null) !== null && $contexto['cliente_estado'] !== 'suspendido';
    }

    public function permite(int $id, string $permiso): bool
    {
        $c = $this->contexto($id);
        return $c !== null && (int)$c['activo'] === 1 && (int)$c['confirmado'] === 1
            && in_array($permiso,self::ROLES[$c['rol']] ?? [],true);
    }
}
