<?php
namespace Repositories;

use Model\ActiveRecord;
use mysqli;

/** Datos privados: los controladores deben validar propietario y permiso antes de consultar. */
class CrecimientoRepository
{
    private mysqli $db;
    /** @param string $propositoConsentimiento propósito cuyas versiones rigen el consentimiento vigente (marketing_email salvo en pruebas) */
    public function __construct(?mysqli $db = null, private string $propositoConsentimiento = ConsentimientoRepository::MARKETING) { $this->db = $db ?? ActiveRecord::getDB(); }
    private function query(string $sql, string $types = '', array $params = []): \mysqli_stmt
    {
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) { throw new PersistenceException('No se pudo preparar la operación.'); }
            if ($types !== '') { $stmt->bind_param($types, ...$params); }
            if (!$stmt->execute()) { $stmt->close(); throw new PersistenceException('No se pudo guardar la operación.'); }
            return $stmt;
        } catch (PersistenceException $e) { throw $e; }
        catch (\Throwable $e) { throw new PersistenceException('No se pudo completar la operación de datos.',0,$e); }
    }
    private function rows(string $sql, string $types = '', array $params = []): array
    { $s=$this->query($sql,$types,$params); $r=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close(); return $r; }
    public function catalogos(): array
    {
        return ['servicios'=>$this->rows('SELECT id,nombre FROM servicios ORDER BY nombre'),
            'profesionales'=>$this->rows('SELECT id,nombre FROM profesionales WHERE activo=1 ORDER BY nombre')];
    }
    public function cliente(int $id): array
    {
        return ['consentimiento'=>$this->rows('SELECT marketing_email aceptado,consentimiento_marketing_at actualizado_en FROM perfiles_usuario WHERE usuarioId=?','i',[$id])[0] ?? ['aceptado'=>0],
            'consentimiento_vista'=>$this->consentimientoVista($id),
            'espera'=>$this->rows('SELECT l.*,s.nombre servicio FROM lista_espera l JOIN servicios s ON s.id=l.servicio_id WHERE usuario_id=? ORDER BY fecha DESC LIMIT 100','i',[$id]),
            'saldo'=>(int)($this->rows('SELECT COALESCE(SUM(puntos),0) saldo FROM fidelidad_movimientos WHERE usuario_id=?','i',[$id])[0]['saldo'] ?? 0),
            'movimientos'=>$this->rows('SELECT puntos,tipo,referencia,beneficio_snapshot,canje_estado,creado_en FROM fidelidad_movimientos WHERE usuario_id=? ORDER BY id DESC LIMIT 100','i',[$id]),
            'formularios'=>$this->rows('SELECT f.*,r.respuestas FROM formularios_cliente f LEFT JOIN formulario_respuestas r ON r.formulario_id=f.id AND r.usuario_id=? WHERE f.activo=1 ORDER BY f.id DESC LIMIT 30','i',[$id])];
    }
    /**
     * Decisión de consentimiento comercial desde «Mi bienestar». Delegada en la fuente única versionada
     * (ConsentimientoRepository::aplicarDecision): guarda el texto que la persona vio y anexa al historial.
     * @param array{origen?:string,canal?:string,actor_id?:?int,ip_hash?:?string,reconfirma?:bool} $meta
     */
    public function consentimiento(int $id, bool $aceptado, mixed $versionVista = null, array $meta = []): void
    {
        (new ConsentimientoRepository($this->db, $this->propositoConsentimiento))->aplicarDecision($id, $aceptado, $versionVista,
            $meta + ['origen' => 'bienestar', 'canal' => 'portal_cliente', 'actor_id' => $id]);
    }
    /** Texto y versión vigentes y la versión que aceptó la persona (para pintar el formulario). @return array{vigente:array,aceptada:?array,anterior:bool} */
    public function consentimientoVista(int $id): array
    {
        $repo = new ConsentimientoRepository($this->db, $this->propositoConsentimiento);
        $vigente = $repo->vigente();
        $p = $this->rows('SELECT marketing_email,consentimiento_marketing_at,consentimiento_marketing_version_id v FROM perfiles_usuario WHERE usuarioId=?','i',[$id])[0] ?? null;
        $acepto = $p !== null && (int)$p['marketing_email'] === 1 && $p['consentimiento_marketing_at'] !== null;
        $aceptada = $acepto ? ($p['v'] !== null ? $repo->porId((int)$p['v']) : $repo->porNumero(1)) : null;
        return ['vigente'=>$vigente,'aceptada'=>$aceptada,'anterior'=>$aceptada !== null && (int)$aceptada['id'] !== (int)$vigente['id']];
    }
    public function agregarEspera(int $id, int $servicio, ?int $profesional, string $fecha): void
    {
        // Validate active professional and skill atomically against the current catalog.
        if ($profesional !== null && !$this->rows('SELECT p.id FROM profesionales p JOIN profesionales_servicios ps ON ps.profesionalId=p.id WHERE p.id=? AND p.activo=1 AND ps.servicioId=?','ii',[$profesional,$servicio])) {
            throw new \InvalidArgumentException('El profesional no ofrece el servicio seleccionado.');
        }
        $this->query("INSERT INTO lista_espera(usuario_id,servicio_id,profesional_id,fecha) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE profesional_id=VALUES(profesional_id),estado='pendiente'",'iiis',[$id,$servicio,$profesional,$fecha])->close();
    }
    public function estadoEspera(int $id, string $estado, ?int $usuario = null): void
    {
        $sql='UPDATE lista_espera SET estado=? WHERE id=?'; $types='si'; $args=[$estado,$id];
        if ($usuario !== null) { $sql.=' AND usuario_id=?'; $types.='i'; $args[]=$usuario; }
        $s=$this->query($sql,$types,$args); if ($s->affected_rows===0) { $s->close(); throw new \InvalidArgumentException('No se encontró una solicitud editable.'); } $s->close();
    }
    public function sincronizarPuntos(int $id): void
    {
        $this->db->begin_transaction();
        try {
            $this->query('SELECT id FROM usuarios WHERE id=? FOR UPDATE','i',[$id])->close();
            $this->query("INSERT INTO fidelidad_movimientos(usuario_id,cita_id,puntos,tipo,referencia) SELECT usuarioId,id,10,'cita',CONCAT('cita:',id) FROM citas WHERE usuarioId=? AND estado='completada' ON DUPLICATE KEY UPDATE referencia=VALUES(referencia)",'i',[$id])->close();
            if (!$this->db->commit()) { throw new PersistenceException('No se pudo confirmar la operación.'); }
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function canjear(int $id, string $clave): void
    {
        $this->db->begin_transaction();
        try {
            $this->query('SELECT id FROM usuarios WHERE id=? FOR UPDATE','i',[$id])->close();
            $ref='canje:'.$id.':'.$clave;
            if ($this->rows('SELECT id FROM fidelidad_movimientos WHERE referencia=?','s',[$ref])) { $this->db->commit(); return; }
            $saldo=(int)$this->rows('SELECT COALESCE(SUM(puntos),0) saldo FROM fidelidad_movimientos WHERE usuario_id=?','i',[$id])[0]['saldo'];
            if ($saldo<100) { throw new \InvalidArgumentException('Necesitas al menos 100 puntos para solicitar el beneficio.'); }
            $beneficio=$this->rows("SELECT valor FROM negocio_ajustes WHERE clave='beneficio_fidelidad'")[0]['valor'] ?? 'Beneficio de cortesía a coordinar con recepción';
            $this->query("INSERT INTO fidelidad_movimientos(usuario_id,puntos,tipo,referencia,beneficio_snapshot) VALUES(?,-100,'canje',?,?)",'iss',[$id,$ref,$beneficio])->close();
            if (!$this->db->commit()) { throw new PersistenceException('No se pudo confirmar el canje.'); }
        } catch (\Throwable $e) { $this->db->rollback(); throw $e; }
    }
    public function crearFormulario(int $autor, string $titulo, array $preguntas): void
    { $this->query('INSERT INTO formularios_cliente(titulo,preguntas,creado_por) VALUES(?,?,?)','ssi',[$titulo,json_encode($preguntas,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$autor])->close(); }
    public function formulario(int $id): ?array
    { return $this->rows('SELECT * FROM formularios_cliente WHERE id=? AND activo=1','i',[$id])[0] ?? null; }
    public function responder(int $id, int $usuario, array $respuestas): void
    { $this->query('INSERT INTO formulario_respuestas(formulario_id,usuario_id,respuestas) VALUES(?,?,?) ON DUPLICATE KEY UPDATE respuestas=VALUES(respuestas),actualizado_en=NOW()','iis',[$id,$usuario,json_encode($respuestas,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)])->close(); }
    public function archivarFormulario(int $id): void
    { $this->query('UPDATE formularios_cliente SET activo=0 WHERE id=?','i',[$id])->close(); }
    public function dashboard(): array
    {
        $metricas=$this->rows("SELECT COUNT(*) citas,SUM(estado='completada') completadas,SUM(estado='no_asistio') ausencias,COUNT(DISTINCT usuarioId) clientes FROM citas WHERE fecha BETWEEN DATE_SUB(CURDATE(),INTERVAL 90 DAY) AND CURDATE()")[0];
        $retencion=$this->rows("SELECT COUNT(*) clientes,SUM(n>=2) recurrentes FROM (SELECT usuarioId,COUNT(*) n FROM citas WHERE estado='completada' AND fecha BETWEEN DATE_SUB(CURDATE(),INTERVAL 90 DAY) AND CURDATE() GROUP BY usuarioId) x")[0];
        return ['metricas'=>$metricas,'retencion'=>$retencion,
            'cohortes'=>$this->rows("SELECT DATE_FORMAT(primera,'%Y-%m') cohorte,COUNT(*) clientes,SUM(n>=2) recurrentes FROM (SELECT usuarioId,MIN(fecha) primera,COUNT(*) n FROM citas WHERE estado='completada' AND fecha<=CURDATE() GROUP BY usuarioId) x GROUP BY cohorte ORDER BY cohorte DESC LIMIT 12"),
            'espera'=>$this->rows('SELECT l.*,u.nombre,u.apellido,s.nombre servicio,p.nombre profesional FROM lista_espera l JOIN usuarios u ON u.id=l.usuario_id JOIN servicios s ON s.id=l.servicio_id LEFT JOIN profesionales p ON p.id=l.profesional_id WHERE l.estado=\'pendiente\' ORDER BY l.fecha,l.id LIMIT 100'),
            'formularios'=>$this->rows('SELECT f.id,f.titulo,f.activo,COUNT(r.id) respuestas FROM formularios_cliente f LEFT JOIN formulario_respuestas r ON r.formulario_id=f.id GROUP BY f.id ORDER BY f.id DESC LIMIT 30'),
            'canjes'=>$this->rows("SELECT m.id,u.nombre,u.apellido,m.referencia,m.creado_en,m.canje_estado,m.beneficio_snapshot FROM fidelidad_movimientos m JOIN usuarios u ON u.id=m.usuario_id WHERE m.tipo='canje' ORDER BY m.id DESC LIMIT 100")];
    }
    public function respuestasPrivadas(int $formulario): array
    { return $this->rows('SELECT u.nombre,u.apellido,r.respuestas,r.actualizado_en,f.preguntas FROM formulario_respuestas r JOIN usuarios u ON u.id=r.usuario_id JOIN formularios_cliente f ON f.id=r.formulario_id WHERE r.formulario_id=? ORDER BY r.id DESC LIMIT 100','i',[$formulario]); }
    /**
     * Audiencia de campaña: personas confirmadas con consentimiento comercial VIGENTE, es decir, aceptado y asociado a la
     * versión de texto en vigor (política documentada: un consentimiento dado a una versión anterior no sirve para campañas
     * hasta que la persona lo reconfirme). Sin versión registrada cuenta como la versión 1 histórica.
     */
    public function segmento(string $segmento): array
    {
        $cond=$segmento==='inactivos' ? "AND NOT EXISTS(SELECT 1 FROM citas c WHERE c.usuarioId=u.id AND c.estado='completada' AND c.fecha>=DATE_SUB(CURDATE(),INTERVAL 90 DAY))" : '';
        $versiones=new ConsentimientoRepository($this->db,$this->propositoConsentimiento);
        $vigente=(int)$versiones->vigente()['id']; $v1=(int)($versiones->porNumero(1)['id'] ?? 0);
        return $this->rows("SELECT u.id,u.nombre,u.apellido,u.email,m.consentimiento_marketing_at consentimiento_en FROM usuarios u JOIN perfiles_usuario m ON m.usuarioId=u.id AND m.marketing_email=1 AND m.consentimiento_marketing_at IS NOT NULL AND COALESCE(m.consentimiento_marketing_version_id,?)=? WHERE u.confirmado=1 $cond ORDER BY u.id LIMIT 1000",'ii',[$v1,$vigente]);
    }
    public function completarCanje(int $id, int $actor): void
    { $s=$this->query("UPDATE fidelidad_movimientos SET canje_estado='entregado',atendido_por=?,atendido_en=NOW() WHERE id=? AND tipo='canje' AND canje_estado='pendiente'",'ii',[$actor,$id]); if ($s->affected_rows!==1) { $s->close(); throw new \InvalidArgumentException('El canje no está pendiente.'); } $s->close(); }
    public function ajustes(): array
    { return array_column($this->rows('SELECT clave,valor FROM negocio_ajustes'),'valor','clave'); }
    public function guardarAjustes(int $id, array $valores): void
    {
        $this->db->begin_transaction();
        try { foreach($valores as $clave=>$valor) { $this->query('INSERT INTO negocio_ajustes(clave,valor,actualizado_por) VALUES(?,?,?) ON DUPLICATE KEY UPDATE valor=VALUES(valor),actualizado_por=VALUES(actualizado_por)','ssi',[$clave,$valor,$id])->close(); } $this->db->commit(); }
        catch(\Throwable $e) { $this->db->rollback(); throw $e; }
    }
}
