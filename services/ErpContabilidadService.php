<?php
namespace Services;

use Classes\Transaccion;

/**
 * Contabilidad por partida doble. El libro es de solo anexar: un asiento no se edita ni se borra, se corrige con un reverso.
 * Cada hecho económico tiene una identidad permanente (`origen`): repetirlo no duplica el asiento y reutilizar el origen con otros datos se rechaza.
 * Los servicios de negocio (cobro, entrega, compra, pago, costo, devolución) no escriben aquí: el adaptador contable traduce sus eventos confirmados.
 */
final class ErpContabilidadService extends ErpService
{
    /** Tipo de cuenta que debe tener la cuenta asignada a cada rol. */
    public const ROLES=[
        'caja'=>'activo','banco'=>'activo','cxc'=>'activo','inventario'=>'activo','iva_compras'=>'activo','activos_fijos'=>'activo',
        'proveedores'=>'pasivo','recepciones_por_facturar'=>'pasivo','iva_ventas'=>'pasivo','anticipos_clientes'=>'pasivo','comisiones_por_pagar'=>'pasivo','sueldos_por_pagar'=>'pasivo','iess_por_pagar'=>'pasivo',
        'resultado_ejercicio'=>'patrimonio','resultados_acumulados'=>'patrimonio',
        'ingreso_servicios'=>'ingreso','ingreso_productos'=>'ingreso','devoluciones_ventas'=>'ingreso',
        'costo_ventas'=>'costo','consumo_insumos'=>'costo',
        'gasto_comisiones'=>'gasto','gasto_sueldos'=>'gasto','gasto_aportes'=>'gasto','gasto_beneficios'=>'gasto','gasto_operativo'=>'gasto','diferencias_caja'=>'gasto',
    ];
    private function hoy():string {return (new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->format('Y-m-d');}

    // ---------------------------------------------------------------- configuración
    public function config():array {return $this->r->uno('SELECT * FROM erp_cont_config WHERE id=1')??[];}
    public function rolCuenta(string $rol):int
    {
        if(!isset(self::ROLES[$rol]))throw new \InvalidArgumentException('Rol contable desconocido.');
        $f=$this->r->uno('SELECT r.cuenta_id FROM erp_cont_roles r JOIN erp_cont_cuentas c ON c.id=r.cuenta_id WHERE r.rol=? AND c.activa=1 AND c.imputable=1',[$rol]);
        return (int)($f['cuenta_id']??throw new ErpException('Falta asignar una cuenta activa al rol contable «'.$rol.'».',409));
    }
    /** Fecha desde la cual existe el libro. Solo se define una vez, antes del primer asiento. */
    public function inicio(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_inicio',$d,function()use($actor,$d){
            $fecha=$this->fecha($d['fecha']??null);$c=$this->r->uno('SELECT * FROM erp_cont_config WHERE id=1 FOR UPDATE');
            if($this->r->uno('SELECT id FROM erp_cont_asientos LIMIT 1 FOR SHARE'))throw new ErpException('Ya existen asientos: el inicio contable no cambia.',409);
            if($fecha>$this->hoy())throw new ErpException('El inicio contable no puede ser futuro.');
            $this->r->q('UPDATE erp_cont_config SET inicio_contable=?,actualizado_por=?,actualizado_en=? WHERE id=1',[$fecha,$actor,$this->r::ahora()]);
            $this->evento($actor,'cont_inicio_definido','contabilidad',1,['anterior'=>$c['inicio_contable'],'inicio'=>$fecha]);return 1;
        });
    }
    public function cuenta(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_cuenta',$d,function()use($actor,$d){
            $codigo=is_string($d['codigo']??null)?trim($d['codigo']):'';if(!preg_match('/^[0-9]+(\.[0-9]+)*$/D',$codigo)||strlen($codigo)>20)throw new ErpException('El código usa números separados por puntos (p. ej. 1.1.07).');
            $nombre=$this->texto($d['nombre']??'',120);$tipo=$d['tipo']??'';if(!in_array($tipo,['activo','pasivo','patrimonio','ingreso','costo','gasto'],true))throw new ErpException('Tipo de cuenta no válido.');
            $imputable=($d['imputable']??'1')==='0'?0:1;$padre=null;
            if(($d['padre']??'')!==''){
                $p=$this->r->uno('SELECT * FROM erp_cont_cuentas WHERE codigo=? FOR UPDATE',[(string)$d['padre']])??throw new ErpException('La cuenta padre no existe.',404);
                if((int)$p['imputable']===1)throw new ErpException('La cuenta padre debe ser de agrupación (no imputable).');
                if($p['tipo']!==$tipo)throw new ErpException('La cuenta hereda el tipo de su padre.');
                if(!str_starts_with($codigo,$p['codigo'].'.'))throw new ErpException('El código debe continuar el de su padre ('.$p['codigo'].'.…).');$padre=(int)$p['id'];
            }
            if($this->r->uno('SELECT id FROM erp_cont_cuentas WHERE codigo=? FOR UPDATE',[$codigo]))throw new ErpException('Ya existe una cuenta con ese código.',409);
            $this->r->q('INSERT INTO erp_cont_cuentas(codigo,nombre,tipo,padre_id,imputable,creado_por,creado_en) VALUES(?,?,?,?,?,?,?)',[$codigo,$nombre,$tipo,$padre,$imputable,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'cont_cuenta_creada','cuenta',$id,['codigo'=>$codigo,'nombre'=>$nombre,'tipo'=>$tipo,'imputable'=>$imputable]);return $id;
        });
    }
    public function cuentaEstado(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_cuenta_estado',$d,function()use($actor,$d){
            $id=$this->id($d['cuenta_id']??null);$activa=($d['activa']??'')==='1'?1:0;$c=$this->r->uno('SELECT * FROM erp_cont_cuentas WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Cuenta no encontrada.',404);
            if(!$activa){
                if($this->r->uno('SELECT rol FROM erp_cont_roles WHERE cuenta_id=? LIMIT 1 FOR SHARE',[$id]))throw new ErpException('La cuenta está asignada a un rol contable: reasigna el rol primero.',409);
                $s=$this->r->uno('SELECT COALESCE(SUM(debe_centavos),0) d,COALESCE(SUM(haber_centavos),0) h FROM erp_cont_lineas WHERE cuenta_id=? FOR SHARE',[$id]);
                if((int)$s['d']!==(int)$s['h'])throw new ErpException('Una cuenta con saldo no se desactiva.',409);
            }
            $this->r->q('UPDATE erp_cont_cuentas SET activa=? WHERE id=?',[$activa,$id]);
            $this->evento($actor,'cont_cuenta_estado','cuenta',$id,['codigo'=>$c['codigo'],'activa'=>$activa]);return $id;
        });
    }
    public function rol(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_rol',$d,function()use($actor,$d){
            $rol=is_string($d['rol']??null)?$d['rol']:'';if(!isset(self::ROLES[$rol]))throw new ErpException('Rol contable desconocido.');
            $c=$this->r->uno('SELECT * FROM erp_cont_cuentas WHERE codigo=? FOR SHARE',[is_string($d['cuenta']??null)?$d['cuenta']:''])??throw new ErpException('La cuenta no existe.',404);
            if((int)$c['imputable']!==1||(int)$c['activa']!==1||$c['tipo']!==self::ROLES[$rol])throw new ErpException('El rol «'.$rol.'» exige una cuenta imputable, activa y de tipo '.self::ROLES[$rol].'.');
            $anterior=$this->r->uno('SELECT cuenta_id FROM erp_cont_roles WHERE rol=? FOR UPDATE',[$rol]);
            $this->r->q('INSERT INTO erp_cont_roles(rol,cuenta_id,actualizado_por,actualizado_en) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE cuenta_id=VALUES(cuenta_id),actualizado_por=VALUES(actualizado_por),actualizado_en=VALUES(actualizado_en)',[$rol,(int)$c['id'],$actor,$this->r::ahora()]);
            $this->evento($actor,'cont_rol_asignado','contabilidad',1,['rol'=>$rol,'cuenta'=>$c['codigo'],'anterior'=>$anterior['cuenta_id']??null]);return (int)$c['id'];
        });
    }

    // ---------------------------------------------------------------- asientos
    /**
     * Núcleo del libro. Requiere transacción. $a: fecha, tipo, origen (identidad permanente), concepto, lineas [cuenta_id,debe,haber(centavos),contacto_id?,referencia?], reversa_de_id?
     * Repetir el mismo origen con el mismo contenido devuelve el asiento existente; con otro contenido, 409.
     */
    public function publicar(int $actor,array $a):int
    {
        if(Transaccion::nivel($this->r->db)===0)throw new \LogicException('El asiento requiere una transacción.');
        $fecha=$this->fecha($a['fecha']??null);$tipo=$a['tipo']??'';if(!in_array($tipo,['apertura','automatico','manual','reverso','cierre'],true))throw new \InvalidArgumentException('Tipo de asiento no válido.');
        $origen=$a['origen']??'';if(!is_string($origen)||!preg_match('/^[A-Za-z0-9:_#.-]{3,120}$/D',$origen))throw new \InvalidArgumentException('Origen contable no válido.');
        $concepto=$this->texto($a['concepto']??'',240);$lineas=$a['lineas']??null;
        if(!is_array($lineas)||!array_is_list($lineas)||count($lineas)<2||count($lineas)>200)throw new ErpException('Un asiento lleva de 2 a 200 líneas.');
        $debe=0;$haber=0;$canon=[];
        foreach($lineas as $i=>$l){
            $cuenta=$l['cuenta_id']??null;$d=$l['debe']??0;$h=$l['haber']??0;
            if(!is_int($cuenta)||!is_int($d)||!is_int($h)||$d<0||$h<0||($d>0)===($h>0)||$d>99999999999||$h>99999999999)throw new ErpException('Cada línea lleva un valor positivo en el debe o en el haber, nunca en ambos ni en ninguno.');
            $debe+=$d;$haber+=$h;$canon[]=[$cuenta,$d,$h,isset($l['contacto_id'])?(int)$l['contacto_id']:null,isset($l['referencia'])&&$l['referencia']!==''?mb_substr((string)$l['referencia'],0,80):null];
        }
        if($debe!==$haber)throw new ErpException('El asiento no está balanceado: debe '.number_format($debe/100,2,'.','').' y haber '.number_format($haber/100,2,'.','').'.');
        if($debe<1||$debe>99999999999)throw new ErpException('Total del asiento fuera de rango.');
        $reversa=isset($a['reversa_de_id'])?(int)$a['reversa_de_id']:null;
        $huella=hash('sha256',json_encode([$fecha,$tipo,$concepto,$canon,$reversa],JSON_THROW_ON_ERROR));
        $previo=$this->r->uno('SELECT id,huella FROM erp_cont_asientos WHERE origen=? FOR SHARE',[$origen]);
        if($previo){if(!hash_equals($previo['huella'],$huella))throw new ErpException('Ese hecho económico ya tiene un asiento con otro contenido; corrígelo con un reverso.',409);return (int)$previo['id'];}
        $cfg=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
        if(!$cfg||!$cfg['inicio_contable'])throw new ErpException('Define primero la fecha de inicio contable.',409);
        if($fecha<$cfg['inicio_contable'])throw new ErpException('La fecha es anterior al inicio contable ('.$cfg['inicio_contable'].').',409);
        $anio=(int)substr($fecha,0,4);$mes=(int)substr($fecha,5,2);
        $this->r->q('INSERT IGNORE INTO erp_cont_periodos(anio,mes) VALUES(?,?)',[$anio,$mes]);
        $p=$this->r->uno('SELECT * FROM erp_cont_periodos WHERE anio=? AND mes=? FOR SHARE',[$anio,$mes]);
        if($p['estado']!=='abierto')throw new ErpException('El período '.sprintf('%04d-%02d',$anio,$mes).' está cerrado. Registra el asiento en un período abierto.',409);
        // Con el ejercicio cerrado (asiento de cierre sin reverso) nada más se registra en ese año: el resultado trasladado quedaría desactualizado.
        $cierreAnio=$this->r->uno("SELECT a.id FROM erp_cont_asientos a WHERE a.tipo='cierre' AND a.fecha=? AND NOT EXISTS(SELECT 1 FROM erp_cont_asientos r WHERE r.reversa_de_id=a.id) LIMIT 1 FOR SHARE",[$anio.'-12-31']);
        if($cierreAnio&&$tipo!=='cierre'&&!($tipo==='reverso'&&$reversa===(int)$cierreAnio['id']))throw new ErpException('El ejercicio '.$anio.' está cerrado. Revierte primero su asiento de cierre o registra el hecho en un ejercicio abierto.',409);
        $ids=array_values(array_unique(array_column($canon,0)));
        $cuentas=$this->r->q('SELECT id,imputable,activa FROM erp_cont_cuentas WHERE id IN('.implode(',',array_fill(0,count($ids),'?')).') FOR SHARE',$ids);
        if(count($cuentas)!==count($ids))throw new ErpException('Alguna cuenta no existe.',404);
        foreach($cuentas as $c)if((int)$c['imputable']!==1||(int)$c['activa']!==1)throw new ErpException('Las líneas solo pueden usar cuentas imputables y activas.');
        $s=$this->r->uno('SELECT ultimo FROM erp_cont_secuencia WHERE id=1 FOR UPDATE');$numero=(int)$s['ultimo']+1;$this->r->q('UPDATE erp_cont_secuencia SET ultimo=? WHERE id=1',[$numero]);
        $this->r->q('INSERT INTO erp_cont_asientos(numero,fecha,periodo_id,tipo,origen,reversa_de_id,huella,concepto,total_centavos,n_lineas,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$numero,$fecha,(int)$p['id'],$tipo,$origen,$reversa,$huella,$concepto,$debe,count($canon),$actor,$this->r::ahora()]);$id=$this->r->id();
        foreach($canon as $n=>[$cuenta,$dd,$hh,$contacto,$ref])$this->r->q('INSERT INTO erp_cont_lineas(asiento_id,nro,cuenta_id,debe_centavos,haber_centavos,contacto_id,referencia) VALUES(?,?,?,?,?,?,?)',[$id,$n+1,$cuenta,$dd,$hh,$contacto,$ref]);
        $this->evento($actor,'cont_asiento_registrado','asiento',$id,['numero'=>$numero,'tipo'=>$tipo,'origen'=>$origen,'fecha'=>$fecha,'total'=>$debe]);return $id;
    }
    /** Asiento manual (o de apertura) escrito por una persona. Líneas: cuenta (código), debe, haber (USD), tercero opcional. */
    public function asiento(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','cont_asiento',$d,function()use($actor,$d){
            $lineas=$d['lineas']??null;if(!is_array($lineas)||count($lineas)>200)throw new ErpException('Indica las líneas del asiento.');$cuentas=[];$salida=[];
            foreach(array_values($lineas) as $l){
                if(!is_array($l)||(($l['cuenta']??'')===''&&($l['debe']??'')===''&&($l['haber']??'')===''))continue;
                $codigo=is_string($l['cuenta']??null)?trim($l['cuenta']):'';
                $c=$cuentas[$codigo]??=($this->r->uno('SELECT id FROM erp_cont_cuentas WHERE codigo=? FOR SHARE',[$codigo])??throw new ErpException('La cuenta «'.$codigo.'» no existe.',404));
                $dd=trim((string)($l['debe']??''))===''?0:$this->importe($l['debe']);$hh=trim((string)($l['haber']??''))===''?0:$this->importe($l['haber']);
                $contacto=trim((string)($l['contacto_id']??''))===''?null:$this->id($l['contacto_id']);
                if($contacto!==null&&!$this->r->uno('SELECT id FROM erp_contactos WHERE id=? FOR SHARE',[$contacto]))throw new ErpException('El tercero no existe.',404);
                $salida[]=['cuenta_id'=>(int)$c['id'],'debe'=>$dd,'haber'=>$hh,'contacto_id'=>$contacto,'referencia'=>isset($l['referencia'])?trim((string)$l['referencia']):null];
            }
            $apertura=($d['apertura']??'')==='1';
            if($apertura){
                $cfg=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
                if($this->r->uno("SELECT id FROM erp_cont_asientos WHERE tipo='apertura' LIMIT 1 FOR SHARE"))throw new ErpException('Ya existe el asiento de apertura; corrígelo con un reverso.',409);
                if(($cfg['inicio_contable']??null)!==$this->fecha($d['fecha']??null))throw new ErpException('El asiento de apertura lleva la fecha de inicio contable.');
            }
            return $this->publicar($actor,['fecha'=>$d['fecha']??null,'tipo'=>$apertura?'apertura':'manual','origen'=>'manual:'.hash('sha256',$actor.':'.$d['idem_key']),'concepto'=>$d['concepto']??'','lineas'=>$salida]);
        });
    }
    /** Corrige un asiento con otro de signo contrario; el original permanece. Un reverso no se revierte: el hecho se vuelve a registrar. */
    public function reversar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','cont_reverso',$d,function()use($actor,$d){
            $id=$this->id($d['asiento_id']??null);$motivo=$this->texto($d['motivo']??'',160);$fecha=isset($d['fecha'])&&$d['fecha']!==''?$this->fecha($d['fecha']):$this->hoy();
            $a=$this->r->uno('SELECT * FROM erp_cont_asientos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Asiento no encontrado.',404);
            if($a['tipo']==='cierre')$fecha=$a['fecha'];// El cierre se revierte en su propio ejercicio, nunca en el siguiente.
            if($a['tipo']==='reverso')throw new ErpException('Un reverso no se revierte: registra de nuevo el hecho.',409);
            $previo=$this->r->uno('SELECT id FROM erp_cont_asientos WHERE reversa_de_id=? FOR SHARE',[$id]);if($previo)return (int)$previo['id'];
            $lineas=[];foreach($this->r->q('SELECT * FROM erp_cont_lineas WHERE asiento_id=? ORDER BY nro FOR SHARE',[$id]) as $l)$lineas[]=['cuenta_id'=>(int)$l['cuenta_id'],'debe'=>(int)$l['haber_centavos'],'haber'=>(int)$l['debe_centavos'],'contacto_id'=>$l['contacto_id'],'referencia'=>$l['referencia']];
            return $this->publicar($actor,['fecha'=>$fecha,'tipo'=>'reverso','origen'=>'reverso:'.$id,'concepto'=>'Reverso del asiento '.$a['numero'].': '.$motivo,'lineas'=>$lineas,'reversa_de_id'=>$id]);
        });
    }

    // ---------------------------------------------------------------- períodos y cierre
    private function periodoBloqueado(int $anio,int $mes):array
    {
        $this->r->q('INSERT IGNORE INTO erp_cont_periodos(anio,mes) VALUES(?,?)',[$anio,$mes]);
        return $this->r->uno('SELECT * FROM erp_cont_periodos WHERE anio=? AND mes=? FOR UPDATE',[$anio,$mes]);
    }
    private function anioMes(array $d):array
    {
        $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);$mes=filter_var($d['mes']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);
        if($anio===false||$mes===false)throw new ErpException('Indica año y mes del período.');return [$anio,$mes];
    }
    public function cerrarPeriodo(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_periodo_cerrar',$d,function()use($actor,$d){
            [$anio,$mes]=$this->anioMes($d);$cfg=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
            if(!$cfg['inicio_contable'])throw new ErpException('Define primero la fecha de inicio contable.',409);
            $p=$this->periodoBloqueado($anio,$mes);if($p['estado']==='cerrado')return (int)$p['id'];
            // Todo mes desde el inicio contable es predecesor, tenga o no movimientos: se materializa y debe estar cerrado.
            $ini=new \DateTimeImmutable(substr($cfg['inicio_contable'],0,7).'-01');$objetivo=new \DateTimeImmutable(sprintf('%04d-%02d-01',$anio,$mes));
            if($objetivo<$ini)throw new ErpException('El período es anterior al inicio contable ('.$cfg['inicio_contable'].').',409);
            for($m=$ini;$m<$objetivo;$m=$m->modify('first day of next month')){
                $pm=$this->periodoBloqueado((int)$m->format('Y'),(int)$m->format('n'));
                if($pm['estado']!=='cerrado')throw new ErpException('Cierra primero el período '.$m->format('Y-m').'.',409);
            }
            $fin=(new \DateTimeImmutable(sprintf('%04d-%02d-01',$anio,$mes)))->modify('first day of next month')->format('Y-m-d');
            $pend=(int)$this->r->uno("SELECT COUNT(*) n FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.creado_en>=? AND e.creado_en<?",[$cfg['inicio_contable'].' 00:00:00',$fin.' 00:00:00'])['n'];
            if($pend>0)throw new ErpException('Hay '.$pend.' hecho(s) económicos sin contabilizar hasta el fin del período. Procésalos o concílialos antes de cerrar.',409);
            $sinPrestar=$this->prestacionesPendientes($cfg['inicio_contable'],$fin);
            if($sinPrestar>0)throw new ErpException('Hay '.$sinPrestar.' servicio(s) prestado(s) sin contabilizar hasta el fin del período. Ejecuta el supervisor contable antes de cerrar.',409);
            $problemas=$this->integridad();if($problemas)throw new ErpException('El libro tiene inconsistencias: '.$problemas[0],409);
            $this->r->q("UPDATE erp_cont_periodos SET estado='cerrado',cerrado_por=?,cerrado_en=? WHERE id=?",[$actor,$this->r::ahora(),(int)$p['id']]);
            $this->evento($actor,'cont_periodo_cerrado','periodo',(int)$p['id'],['anio'=>$anio,'mes'=>$mes]);return (int)$p['id'];
        });
    }
    /** Servicios completados desde el inicio contable (y antes de $antesDe, si se indica) que todavía no tienen su asiento de prestación. */
    private function prestacionesPendientes(string $inicio,?string $antesDe=null):int
    {
        $fecha='COALESCE(DATE(c.atencion_fin_at),DATE(c.atencion_efectiva_at),c.fecha)';$a=[$inicio];$tope='';if($antesDe!==null){$tope=" AND $fecha<?";$a[]=$antesDe;}
        return (int)($this->r->uno("SELECT COUNT(*) n FROM erp_ventas v JOIN citas c ON c.id=v.cita_id WHERE v.estado='confirmada' AND c.estado='completada' AND $fecha>=?$tope AND EXISTS(SELECT 1 FROM erp_venta_lineas l WHERE l.venta_id=v.id AND l.servicio_id IS NOT NULL) AND NOT EXISTS(SELECT 1 FROM erp_cont_asientos a WHERE a.origen=CONCAT('prestacion:v',v.id))",$a)['n']??0);
    }
    public function reabrirPeriodo(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_periodo_reabrir',$d,function()use($actor,$d){
            [$anio,$mes]=$this->anioMes($d);$motivo=$this->texto($d['motivo']??'',200);$p=$this->periodoBloqueado($anio,$mes);
            if($p['estado']!=='cerrado')throw new ErpException('El período ya está abierto.',409);
            if($this->r->uno("SELECT id FROM erp_cont_periodos WHERE estado='cerrado' AND anio*100+mes>? LIMIT 1 FOR SHARE",[$anio*100+$mes]))throw new ErpException('Reabre primero los períodos posteriores.',409);
            $this->r->q("UPDATE erp_cont_periodos SET estado='abierto',cerrado_por=NULL,cerrado_en=NULL WHERE id=?",[(int)$p['id']]);
            $this->evento($actor,'cont_periodo_reabierto','periodo',(int)$p['id'],['anio'=>$anio,'mes'=>$mes,'motivo'=>$motivo]);return (int)$p['id'];
        });
    }
    /** Cierra el ejercicio: traslada ingresos, costos y gastos del año al resultado del ejercicio con un asiento de cierre fechado el 31 de diciembre. */
    public function cerrarEjercicio(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','cont_ejercicio_cerrar',$d,function()use($actor,$d){
            $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);if($anio===false)throw new ErpException('Indica el año.');
            $cierres=$this->r->q("SELECT a.id,(SELECT r.id FROM erp_cont_asientos r WHERE r.reversa_de_id=a.id) reversado FROM erp_cont_asientos a WHERE a.tipo='cierre' AND a.fecha=? ORDER BY a.id FOR SHARE",[$anio.'-12-31']);
            if($cierres&&!end($cierres)['reversado'])return (int)end($cierres)['id'];
            $origen='cierre:'.$anio.($cierres?':'.(count($cierres)+1):'');
            $dic=$this->periodoBloqueado($anio,12);if($dic['estado']!=='abierto')throw new ErpException('El período de diciembre debe estar abierto para registrar el cierre.',409);
            $abierto=$this->r->uno("SELECT mes FROM erp_cont_periodos WHERE anio=? AND mes<12 AND estado='abierto' LIMIT 1 FOR SHARE",[$anio]);if($abierto)throw new ErpException('Cierra primero los períodos de enero a noviembre (falta '.sprintf('%04d-%02d',$anio,$abierto['mes']).').',409);
            // Un cierre sobre hechos incompletos dejaría ingresos o gastos sin cerrar: todos los hechos de diciembre deben estar contabilizados.
            $cfg=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
            if(!$cfg||!$cfg['inicio_contable'])throw new ErpException('Define primero la fecha de inicio contable.',409);
            $pend=(int)$this->r->uno("SELECT COUNT(*) n FROM erp_integraciones_outbox o JOIN erp_eventos e ON e.id=o.evento_id WHERE o.destino='contabilidad' AND o.estado<>'confirmado' AND e.creado_en>=? AND e.creado_en<?",[$cfg['inicio_contable'].' 00:00:00',($anio+1).'-01-01 00:00:00'])['n'];
            if($pend>0)throw new ErpException('Hay '.$pend.' hecho(s) económicos sin contabilizar hasta el fin del ejercicio. Procésalos antes de cerrarlo.',409);
            $sinPrestar=$this->prestacionesPendientes($cfg['inicio_contable'],($anio+1).'-01-01');
            if($sinPrestar>0)throw new ErpException('Hay '.$sinPrestar.' servicio(s) prestado(s) sin contabilizar hasta el fin del ejercicio. Ejecuta el supervisor contable antes de cerrar.',409);
            $filas=$this->r->q("SELECT c.id,c.tipo,SUM(l.debe_centavos) d,SUM(l.haber_centavos) h FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE a.fecha BETWEEN ? AND ? AND c.tipo IN('ingreso','costo','gasto') GROUP BY c.id,c.tipo HAVING SUM(l.debe_centavos)<>SUM(l.haber_centavos) FOR SHARE",[$anio.'-01-01',$anio.'-12-31']);
            if(!$filas)throw new ErpException('No hay resultado del ejercicio que cerrar.',409);
            $lineas=[];$neto=0;foreach($filas as $f){$saldo=(int)$f['d']-(int)$f['h'];$neto+=$saldo;$lineas[]=['cuenta_id'=>(int)$f['id'],'debe'=>$saldo<0?-$saldo:0,'haber'=>$saldo>0?$saldo:0];}
            $res=$this->rolCuenta('resultado_ejercicio');// Pérdida (gastos y costos > ingresos): el resultado se debita; utilidad: se acredita.
            $lineas[]=['cuenta_id'=>$res,'debe'=>$neto>0?$neto:0,'haber'=>$neto<0?-$neto:0];
            if($neto===0)$lineas=array_filter($lineas,fn($l)=>$l['cuenta_id']!==$res);
            return $this->publicar($actor,['fecha'=>$anio.'-12-31','tipo'=>'cierre','origen'=>$origen,'concepto'=>'Cierre del ejercicio '.$anio,'lineas'=>array_values($lineas)]);
        });
    }

    // ---------------------------------------------------------------- consultas (libros y estados)
    /** Saldos por cuenta en un rango de fechas. $cierres=false excluye los asientos de cierre de ejercicio (estado de resultados). */
    public function saldos(string $desde,string $hasta,bool $cierres=true):array
    {
        $f=$cierres?'':" AND a.tipo<>'cierre'";
        return $this->r->q("SELECT c.id,c.codigo,c.nombre,c.tipo,c.imputable,COALESCE(SUM(l.debe_centavos),0) debe,COALESCE(SUM(l.haber_centavos),0) haber FROM erp_cont_cuentas c LEFT JOIN (erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id AND a.fecha BETWEEN ? AND ?{$f}) ON l.cuenta_id=c.id GROUP BY c.id,c.codigo,c.nombre,c.tipo,c.imputable ORDER BY c.codigo",[$desde,$hasta]);
    }
    public function balanceComprobacion(string $hasta):array
    {
        $filas=[];$d=0;$h=0;foreach($this->saldos('2000-01-01',$hasta) as $s){if((int)$s['debe']===0&&(int)$s['haber']===0)continue;$sal=(int)$s['debe']-(int)$s['haber'];$filas[]=$s+['saldo_deudor'=>max(0,$sal),'saldo_acreedor'=>max(0,-$sal)];$d+=(int)$s['debe'];$h+=(int)$s['haber'];}
        return ['filas'=>$filas,'debe'=>$d,'haber'=>$h,'cuadra'=>$d===$h];
    }
    public function estadoResultados(string $desde,string $hasta):array
    {
        $sec=['ingreso'=>[],'costo'=>[],'gasto'=>[]];$tot=['ingreso'=>0,'costo'=>0,'gasto'=>0];
        foreach($this->saldos($desde,$hasta,false) as $s){if(!isset($sec[$s['tipo']])||((int)$s['debe']===0&&(int)$s['haber']===0))continue;$v=$s['tipo']==='ingreso'?(int)$s['haber']-(int)$s['debe']:(int)$s['debe']-(int)$s['haber'];$sec[$s['tipo']][]=['codigo'=>$s['codigo'],'nombre'=>$s['nombre'],'valor'=>$v];$tot[$s['tipo']]+=$v;}
        return ['secciones'=>$sec,'totales'=>$tot,'utilidad_bruta'=>$tot['ingreso']-$tot['costo'],'resultado'=>$tot['ingreso']-$tot['costo']-$tot['gasto']];
    }
    public function balanceGeneral(string $hasta):array
    {
        $sec=['activo'=>[],'pasivo'=>[],'patrimonio'=>[]];$tot=['activo'=>0,'pasivo'=>0,'patrimonio'=>0];$resultado=0;
        foreach($this->saldos('2000-01-01',$hasta) as $s){
            $sal=(int)$s['debe']-(int)$s['haber'];if($sal===0&&(int)$s['debe']===0)continue;
            if(in_array($s['tipo'],['ingreso','costo','gasto'],true)){$resultado-=$sal;continue;}
            $v=$s['tipo']==='activo'?$sal:-$sal;$sec[$s['tipo']][]=['codigo'=>$s['codigo'],'nombre'=>$s['nombre'],'valor'=>$v];$tot[$s['tipo']]+=$v;
        }
        $totalPatrimonio=$tot['patrimonio']+$resultado;
        return ['secciones'=>$sec,'totales'=>$tot,'resultado_sin_cerrar'=>$resultado,'pasivo_mas_patrimonio'=>$tot['pasivo']+$totalPatrimonio,'cuadra'=>$tot['activo']===$tot['pasivo']+$totalPatrimonio];
    }
    public function mayor(int $cuenta,string $desde,string $hasta,int $limite=2000):array
    {
        $c=$this->r->uno('SELECT * FROM erp_cont_cuentas WHERE id=?',[$cuenta])??throw new ErpException('Cuenta no encontrada.',404);
        $ini=$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<?',[$cuenta,$desde]);
        $deudora=in_array($c['tipo'],['activo','costo','gasto'],true);$saldo=($deudora?1:-1)*((int)$ini['d']-(int)$ini['h']);$inicial=$saldo;$mov=[];
        // El saldo final sale de la suma del rango completo, no del listado: el listado se acota por rendimiento y lo avisa.
        $rango=$this->r->uno('SELECT COUNT(*) n,COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha BETWEEN ? AND ?',[$cuenta,$desde,$hasta]);
        $final=$inicial+($deudora?1:-1)*((int)$rango['d']-(int)$rango['h']);
        foreach($this->r->q('SELECT a.id asiento_id,a.numero,a.fecha,a.tipo,a.concepto,l.debe_centavos,l.haber_centavos,l.contacto_id,l.referencia FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha BETWEEN ? AND ? ORDER BY a.fecha,a.numero,l.nro LIMIT '.max(1,$limite),[$cuenta,$desde,$hasta]) as $m){
            $saldo+=($deudora?1:-1)*((int)$m['debe_centavos']-(int)$m['haber_centavos']);$mov[]=$m+['saldo'=>$saldo];
        }
        return ['cuenta'=>$c,'saldo_inicial'=>$inicial,'movimientos'=>$mov,'saldo_final'=>$final,'total_movimientos'=>(int)$rango['n'],'truncado'=>(int)$rango['n']>count($mov)];
    }
    public function diario(string $desde,string $hasta,int $pagina=1):array
    {
        $por=25;$total=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_cont_asientos WHERE fecha BETWEEN ? AND ?',[$desde,$hasta])['n'];$paginas=max(1,(int)ceil($total/$por));$pagina=max(1,min($pagina,$paginas));
        $asientos=$this->r->q('SELECT a.*,(SELECT r.id FROM erp_cont_asientos r WHERE r.reversa_de_id=a.id) reversado_por FROM erp_cont_asientos a WHERE a.fecha BETWEEN ? AND ? ORDER BY a.fecha,a.numero LIMIT '.$por.' OFFSET '.(($pagina-1)*$por),[$desde,$hasta]);
        foreach($asientos as &$a)$a['lineas']=$this->r->q('SELECT l.*,c.codigo,c.nombre FROM erp_cont_lineas l JOIN erp_cont_cuentas c ON c.id=l.cuenta_id WHERE l.asiento_id=? ORDER BY l.nro',[(int)$a['id']]);unset($a);
        return ['asientos'=>$asientos,'pagina'=>$pagina,'paginas'=>$paginas,'total'=>$total];
    }
    /** Saldo por tercero de una cuenta de rol (cxc, proveedores, anticipos_clientes) a una fecha. */
    public function auxiliar(string $rol,string $hasta):array
    {
        $cuenta=$this->rolCuenta($rol);$deudora=self::ROLES[$rol]==='activo';$filas=[];$total=0;
        foreach($this->r->q('SELECT l.contacto_id,SUM(l.debe_centavos) d,SUM(l.haber_centavos) h FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<=? GROUP BY l.contacto_id HAVING SUM(l.debe_centavos)<>SUM(l.haber_centavos)',[$cuenta,$hasta]) as $f){
            $v=($deudora?1:-1)*((int)$f['d']-(int)$f['h']);$nombre=$f['contacto_id']?($this->r->uno('SELECT nombre FROM erp_contactos WHERE id=?',[(int)$f['contacto_id']])['nombre']??'Tercero #'.$f['contacto_id']):'Sin tercero';$filas[]=['contacto_id'=>$f['contacto_id'],'nombre'=>$nombre,'saldo'=>$v];$total+=$v;
        }
        usort($filas,fn($a,$b)=>$b['saldo']<=>$a['saldo']);return ['filas'=>$filas,'total'=>$total];
    }
    /** Controles del propio libro: asientos completos y balanceados. Devuelve las inconsistencias (vacío = íntegro). */
    public function integridad():array
    {
        $p=[];
        foreach($this->r->q('SELECT a.id,a.numero,a.total_centavos,a.n_lineas,COUNT(l.id) n,COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_asientos a LEFT JOIN erp_cont_lineas l ON l.asiento_id=a.id GROUP BY a.id,a.numero,a.total_centavos,a.n_lineas HAVING COUNT(l.id)<>a.n_lineas OR SUM(l.debe_centavos)<>a.total_centavos OR SUM(l.haber_centavos)<>a.total_centavos OR SUM(l.id) IS NULL') as $a)
            $p[]='El asiento '.$a['numero'].' está incompleto o desbalanceado (líneas '.$a['n'].'/'.$a['n_lineas'].').';
        return $p;
    }
    /**
     * Conciliación entre el libro y los auxiliares del negocio. Cada control declara de dónde sale cada cifra; una diferencia nunca se ajusta sola:
     * se explica (hecho sin contabilizar, movimiento sin regla) y se corrige con un asiento o con la regla que falta.
     */
    public function conciliacion(string $hasta):array
    {
        $saldo=function(string $rol)use($hasta):int{
            $cuenta=$this->r->uno('SELECT r.cuenta_id FROM erp_cont_roles r WHERE r.rol=?',[$rol]);if(!$cuenta)return 0;
            $f=$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos),0) d,COALESCE(SUM(l.haber_centavos),0) h FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<=?',[(int)$cuenta['cuenta_id'],$hasta]);
            return (self::ROLES[$rol]==='activo'?1:-1)*((int)$f['d']-(int)$f['h']);
        };
        $n=fn(string $sql,array $a=[])=>(int)($this->r->uno($sql,$a)['n']??0);
        $controles=[];
        // Cada auxiliar se calcula con sus movimientos fechados hasta el cierre del día de corte, igual que el libro.
        $fin=$hasta.' 23:59:59';
        $aux=$n("SELECT COALESCE(SUM(CASE WHEN o.creado_en<=? THEN o.monto_centavos ELSE 0 END),0)
            -COALESCE((SELECT SUM(k.monto_centavos) FROM erp_proveedor_creditos k JOIN erp_obligaciones ok ON ok.id=k.obligacion_id WHERE ok.origen='compra' AND ok.creado_en<=? AND k.creado_en<=?),0)
            -COALESCE((SELECT SUM(p.monto_centavos) FROM erp_obligacion_pagos p JOIN erp_obligaciones op ON op.id=p.obligacion_id WHERE op.origen='compra' AND op.creado_en<=? AND p.creado_en<=?),0)
            +COALESCE((SELECT SUM(x.monto_centavos) FROM erp_proveedor_reembolsos x JOIN erp_obligaciones oz ON oz.id=x.obligacion_id WHERE oz.origen='compra' AND oz.creado_en<=? AND x.creado_en<=?),0) n
            FROM erp_obligaciones o WHERE o.origen='compra'",[$fin,$fin,$fin,$fin,$fin,$fin,$fin]);
        $controles[]=['nombre'=>'Proveedores','libro'=>$saldo('proveedores'),'auxiliar'=>$aux,'fuente'=>'Facturas de proveedor menos créditos y pagos, más reintegros, hasta la fecha de corte'];
        $aux=$n("SELECT COALESCE(SUM(CASE WHEN o.creado_en<=? THEN o.monto_centavos ELSE 0 END),0)
            -COALESCE((SELECT SUM(k.monto_centavos) FROM erp_proveedor_creditos k JOIN erp_obligaciones ok ON ok.id=k.obligacion_id WHERE ok.origen='comision' AND ok.creado_en<=? AND k.creado_en<=?),0)
            -COALESCE((SELECT SUM(p.monto_centavos) FROM erp_obligacion_pagos p JOIN erp_obligaciones op ON op.id=p.obligacion_id WHERE op.origen='comision' AND op.creado_en<=? AND p.creado_en<=?),0) n
            FROM erp_obligaciones o WHERE o.origen='comision'",[$fin,$fin,$fin,$fin,$fin]);
        $controles[]=['nombre'=>'Comisiones por pagar','libro'=>$saldo('comisiones_por_pagar'),'auxiliar'=>$aux,'fuente'=>'Obligaciones de comisiones liquidadas por pago directo, hasta la fecha de corte'];
        $aux=$n('SELECT COALESCE(SUM(monto_centavos),0) n FROM erp_prepago_movimientos WHERE creado_en<=?',[$fin]);
        $controles[]=['nombre'=>'Anticipos de clientes','libro'=>$saldo('anticipos_clientes'),'auxiliar'=>$aux,'fuente'=>'Libro de saldos prepagados (cargas, aplicaciones, restituciones y reembolsos) hasta la fecha de corte'];
        $aux=$n("SELECT COALESCE(SUM(CASE WHEN tipo='ingreso' THEN monto_centavos ELSE -monto_centavos END),0) n FROM caja_movimientos WHERE metodo='efectivo' AND registrado_en<=?",[$fin]);
        $controles[]=['nombre'=>'Caja en efectivo','libro'=>$saldo('caja'),'auxiliar'=>$aux,'fuente'=>'Movimientos de caja en efectivo hasta la fecha de corte. Los ingresos y egresos manuales de caja todavía no tienen regla contable y aparecen como diferencia.'];
        foreach($controles as &$c)$c['diferencia']=$c['libro']-$c['auxiliar'];unset($c);
        $pendientes=$n("SELECT COUNT(*) n FROM erp_integraciones_outbox WHERE destino='contabilidad' AND estado<>'confirmado'");
        $inicio=$this->config()['inicio_contable']??null;$sinPrestar=$inicio?$this->prestacionesPendientes($inicio):0;
        $bal=$this->balanceComprobacion($hasta);
        return ['controles'=>$controles,'eventos_sin_contabilizar'=>$pendientes,'prestaciones_sin_contabilizar'=>$sinPrestar,'integridad'=>$this->integridad(),'balance_cuadra'=>$bal['cuadra']];
    }
}
