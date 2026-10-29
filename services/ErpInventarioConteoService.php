<?php
namespace Services;

use Repositories\ErpRepository;

/**
 * Conteo físico de inventario (migración 070). Un conteo REGISTRA al iniciar su alcance, su responsable y las existencias de referencia de cada producto; se cuenta después
 * (sin contar ≠ contado en cero); se cierra para aprobación; y al aprobarse cada diferencia genera UN ajuste a través del flujo existente de solicitudes de ajuste
 * (erp_solicitudes tipo ajuste_stock → ErpAprobacionService::resolver → movimiento de inventario + concepto + hecho contable), vinculado al conteo con su valoración.
 *
 * Reglas de integridad:
 *  · La diferencia es contado − existencia FÍSICA que tenía el sistema al contar, y se aplica como DIFERENCIA sobre el stock actual (nunca se sobrescribe el stock con una lectura antigua).
 *  · Las reservas activas no son faltantes: solo impiden que el stock quede por debajo de lo reservado (conflicto que bloquea la aprobación).
 *  · Los movimientos entre el inicio y el conteo, y después del conteo, se muestran como conflictos; los posteriores exigen confirmación explícita.
 *  · Aprobar exige concepto (por sentido) y motivo; otra persona autorizada debe aprobar (salvo propietario), como en las demás aprobaciones.
 *  · Nada se borra: un conteo aprobado solo se revierte con ajustes inversos, también únicos por línea.
 */
final class ErpInventarioConteoService extends ErpService
{
    public const MAX_PRODUCTOS=500;

    // ------------------------------------------------------------------------------------------------------------- escritura

    public function iniciar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'inventario.gestionar','inv_conteo_iniciar',$d,function()use($actor,$d){
            $alcance=match($d['alcance']??''){'todos_activos'=>'todos_activos','seleccion'=>'seleccion',default=>throw new ErpException('Elige el alcance del conteo: todos los productos activos o una selección.')};
            $descripcion=$this->texto($d['descripcion']??'',200);
            if(mb_strlen($descripcion)<3)throw new ErpException('Describe el alcance del conteo (al menos 3 caracteres).');
            $responsable=$this->id($d['responsable_id']??null);
            if(!(new AuthorizationService($this->r->db))->permite($responsable,'inventario.gestionar'))throw new ErpException('El responsable del conteo debe poder gestionar inventario.',422);
            if($alcance==='seleccion'){
                $crudos=is_array($d['productos']??null)?$d['productos']:[];$ids=[];
                foreach($crudos as $x)$ids[$this->id($x)]=true;
                $ids=array_keys($ids);sort($ids);
                if(!$ids)throw new ErpException('Elige al menos un producto para el conteo.');
            }else{
                $ids=array_map(static fn($f)=>(int)$f['id'],$this->r->q('SELECT id FROM inventario_productos WHERE activo=1 ORDER BY id'));
                if(!$ids)throw new ErpException('No hay productos activos que contar.');
            }
            if(count($ids)>self::MAX_PRODUCTOS)throw new ErpException('Un conteo admite hasta '.self::MAX_PRODUCTOS.' productos: divide el alcance.');
            $ph=implode(',',array_fill(0,count($ids),'?'));
            // Lectura actual (LOCK IN SHARE MODE): bajo REPEATABLE READ una lectura normal no vería un conteo confirmado mientras se esperaba el bloqueo.
            // Se bloquean los productos en orden creciente (igual que la aprobación) y se toma la foto de referencia con el bloqueo puesto.
            $prods=$this->r->q("SELECT * FROM inventario_productos WHERE id IN($ph) ORDER BY id FOR UPDATE",$ids);
            if(count($prods)!==count($ids))throw new ErpException('Algún producto del alcance no existe.',404);
            $ocupados=$this->r->q("SELECT DISTINCT p.nombre FROM erp_inv_conteo_lineas l JOIN erp_inv_conteos c ON c.id=l.conteo_id JOIN inventario_productos p ON p.id=l.producto_id WHERE l.producto_id IN($ph) AND c.estado IN('en_conteo','pendiente') ORDER BY p.nombre LIMIT 5 LOCK IN SHARE MODE",$ids);
            if($ocupados)throw new ErpException('Hay productos que ya están en otro conteo abierto: '.implode(', ',array_column($ocupados,'nombre')).'.',409);
            $ahora=ErpRepository::ahora();
            $maxMov=(int)$this->r->uno('SELECT COALESCE(MAX(id),0) m FROM inventario_movimientos')['m'];
            $this->r->q('INSERT INTO erp_inv_conteos(estado,alcance,descripcion,responsable_id,iniciado_por,iniciado_en,ultimo_movimiento_inicio) VALUES(\'en_conteo\',?,?,?,?,?,?)',[$alcance,$descripcion,$responsable,$actor,$ahora,$maxMov]);
            $id=$this->r->id();
            foreach($prods as $p){
                $ult=(int)$this->r->uno('SELECT COALESCE(MAX(id),0) m FROM inventario_movimientos WHERE producto_id=? LOCK IN SHARE MODE',[(int)$p['id']])['m'];
                $this->r->q('INSERT INTO erp_inv_conteo_lineas(conteo_id,producto_id,sku,nombre,unidad,referencia_milesimas,costo_ref_centavos,ultimo_mov_ref) VALUES(?,?,?,?,?,?,?,?)',
                    [$id,(int)$p['id'],$p['sku'],$p['nombre'],$p['unidad'],(int)$p['stock_milesimas'],(int)$p['costo_centavos'],$ult]);
            }
            $this->evento($actor,'inventario_conteo_iniciado','inventario_conteo',$id,['alcance'=>$alcance,'descripcion'=>$descripcion,'responsable_id'=>$responsable,'n_productos'=>count($ids),'ultimo_movimiento_inicio'=>$maxMov]);
            return $id;
        });
    }

    /** Registra (o corrige) lo contado de un producto. Vacío no es cero: sin cantidad el producto queda «sin contar». */
    public function contar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'inventario.gestionar','inv_conteo_contar',$d,function()use($actor,$d){
            $linea=$this->r->uno('SELECT * FROM erp_inv_conteo_lineas WHERE id=?',[$this->id($d['linea_id']??null)])??throw new ErpException('Línea de conteo no encontrada.',404);
            $c=$this->bloquearConteo((int)$linea['conteo_id']);
            if($c['estado']!=='en_conteo')throw new ErpException('El conteo ya no admite lecturas ('.$c['estado'].').',409);
            $texto=$d['cantidad']??null;
            if(!is_scalar($texto)||trim((string)$texto)==='')throw new ErpException('Indica la cantidad contada (0 si no hay existencias). Sin cantidad, el producto queda «sin contar».');
            $cant=ValoresExactos::decimal($texto,3,true);
            // Lectura del sistema en el mismo instante: producto bloqueado y lecturas actuales (no la instantánea de REPEATABLE READ), así que stock, reservas y último movimiento son coherentes entre sí.
            $p=$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$linea['producto_id']])??throw new ErpException('Producto no encontrado.',404);
            $reservado=$this->reservado((int)$linea['producto_id'],true);
            $ult=(int)$this->r->uno('SELECT COALESCE(MAX(id),0) m FROM inventario_movimientos WHERE producto_id=? LOCK IN SHARE MODE',[(int)$linea['producto_id']])['m'];
            $ahora=ErpRepository::ahora();
            $this->r->q('UPDATE erp_inv_conteo_lineas SET contado_milesimas=?,contado_por=?,contado_en=?,sistema_al_contar_milesimas=?,reservado_al_contar_milesimas=?,ultimo_mov_al_contar=? WHERE id=?',
                [$cant,$actor,$ahora,(int)$p['stock_milesimas'],$reservado,$ult,(int)$linea['id']]);
            $this->r->q('INSERT INTO erp_inv_conteo_registros(conteo_id,linea_id,contado_milesimas,sistema_milesimas,reservado_milesimas,ultimo_mov_id,actor_id,creado_en) VALUES(?,?,?,?,?,?,?,?)',
                [(int)$c['id'],(int)$linea['id'],$cant,(int)$p['stock_milesimas'],$reservado,$ult,$actor,$ahora]);
            return (int)$linea['id'];
        });
    }

    /** Cierra las lecturas y envía el conteo a aprobación. Los productos sin contar quedan fuera de los ajustes. */
    public function cerrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'inventario.gestionar','inv_conteo_cerrar',$d,function()use($actor,$d){
            $c=$this->bloquearConteo($this->id($d['conteo_id']??null));
            if($c['estado']!=='en_conteo')throw new ErpException('El conteo ya no está en conteo ('.$c['estado'].').',409);
            $n=$this->r->uno('SELECT COUNT(*) total,SUM(contado_milesimas IS NOT NULL) contadas FROM erp_inv_conteo_lineas WHERE conteo_id=?',[(int)$c['id']]);
            if((int)$n['contadas']===0)throw new ErpException('Cuenta al menos un producto antes de cerrar el conteo.');
            $this->r->q("UPDATE erp_inv_conteos SET estado='pendiente',cerrado_por=?,cerrado_en=? WHERE id=?",[$actor,ErpRepository::ahora(),(int)$c['id']]);
            $this->evento($actor,'inventario_conteo_cerrado','inventario_conteo',(int)$c['id'],['contadas'=>(int)$n['contadas'],'sin_contar'=>(int)$n['total']-(int)$n['contadas']]);
            return (int)$c['id'];
        });
    }

    public function cancelar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'inventario.gestionar','inv_conteo_cancelar',$d,function()use($actor,$d){
            $c=$this->bloquearConteo($this->id($d['conteo_id']??null));$motivo=$this->motivo($d['motivo']??'');
            if(!in_array($c['estado'],['en_conteo','pendiente'],true))throw new ErpException('Solo se cancela un conteo abierto ('.$c['estado'].').',409);
            $this->r->q("UPDATE erp_inv_conteos SET estado='cancelado',resuelto_por=?,resuelto_en=?,motivo=? WHERE id=?",[$actor,ErpRepository::ahora(),$motivo,(int)$c['id']]);
            $this->evento($actor,'inventario_conteo_cancelado','inventario_conteo',(int)$c['id'],['motivo'=>$motivo]);
            return (int)$c['id'];
        });
    }

    public function rechazar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.aprobaciones','inv_conteo_rechazar',$d,function()use($actor,$d){
            $this->permitir($actor,'inventario.gestionar');
            $c=$this->bloquearConteo($this->id($d['conteo_id']??null));$motivo=$this->motivo($d['motivo']??'');
            if($c['estado']!=='pendiente')throw new ErpException('Solo se rechaza un conteo pendiente de aprobación ('.$c['estado'].').',409);
            $this->r->q("UPDATE erp_inv_conteos SET estado='rechazado',resuelto_por=?,resuelto_en=?,motivo=? WHERE id=?",[$actor,ErpRepository::ahora(),$motivo,(int)$c['id']]);
            $this->evento($actor,'inventario_conteo_rechazado','inventario_conteo',(int)$c['id'],['motivo'=>$motivo]);
            return (int)$c['id'];
        });
    }

    /**
     * Aprueba el conteo: cada diferencia genera un ajuste (una sola vez) por el flujo de solicitudes de ajuste, con el concepto elegido según el sentido y el motivo obligatorio.
     * Todo o nada: si una línea no se puede aplicar (reservas, rango, valor), no se aplica ninguna.
     */
    public function aprobar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.aprobaciones','inv_conteo_aprobar',$d,function()use($actor,$d){
            $this->permitir($actor,'inventario.gestionar');
            $c=$this->bloquearConteo($this->id($d['conteo_id']??null));
            if($c['estado']!=='pendiente')throw new ErpException($c['estado']==='aprobado'?'Este conteo ya fue aprobado: sus ajustes ya se aplicaron una sola vez.':'Solo se aprueba un conteo pendiente de aprobación ('.$c['estado'].').',409);
            $this->exigirOtraPersona($actor,$c);
            $motivo=$this->motivo($d['motivo']??'');
            $an=$this->analizar($c,true);
            $dif=array_values(array_filter($an,static fn($l)=>$l['tipo']!==null));
            $bloqueos=[];foreach($dif as $l)foreach($l['conflictos'] as $k)if($k['bloquea'])$bloqueos[]=$l['nombre'].': '.$k['mensaje'];
            if($bloqueos)throw new ErpException('El conteo tiene conflictos que bloquean la aprobación — '.implode(' · ',$bloqueos),409);
            $avisos=0;foreach($dif as $l)foreach($l['conflictos'] as $k)if($k['codigo']==='movimientos_posteriores_al_conteo')$avisos++;
            if($avisos>0&&empty($d['confirmar_movimientos']))throw new ErpException('Hubo movimientos de inventario después de contar '.$avisos.' producto(s). El ajuste se aplica como diferencia sobre el stock actual, sin sobrescribirlo; confirma que lo revisaste.',409);
            $kc=new ErpInventarioConceptoService($this->r);
            $cf=null;$cs=null;
            if(array_filter($dif,static fn($l)=>$l['tipo']==='salida'))$cf=$kc->validarParaSolicitud($d['concepto_faltante_id']??null,'salida');
            if(array_filter($dif,static fn($l)=>$l['tipo']==='entrada'))$cs=$kc->validarParaSolicitud($d['concepto_sobrante_id']??null,'entrada');
            $aplicados=0;
            foreach($dif as $l){
                $this->aplicarAjuste($actor,$c,$l,'conteo',$l['tipo'],(int)$l['cantidad'],$l['tipo']==='salida'?$cf:$cs,'Conteo #'.$c['id'].' · '.$motivo);
                $aplicados++;
            }
            $this->r->q("UPDATE erp_inv_conteos SET estado='aprobado',resuelto_por=?,resuelto_en=?,motivo=?,concepto_faltante_id=?,concepto_sobrante_id=? WHERE id=?",[$actor,ErpRepository::ahora(),$motivo,$cf,$cs,(int)$c['id']]);
            $this->evento($actor,'inventario_conteo_aprobado','inventario_conteo',(int)$c['id'],['motivo'=>$motivo,'ajustes'=>$aplicados,'concepto_faltante_id'=>$cf,'concepto_sobrante_id'=>$cs]);
            return (int)$c['id'];
        });
    }

    /** Revierte un conteo aprobado con ajustes INVERSOS (uno por ajuste aplicado, una sola vez); los ajustes originales se conservan. */
    public function revertir(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.aprobaciones','inv_conteo_revertir',$d,function()use($actor,$d){
            $this->permitir($actor,'inventario.gestionar');
            $c=$this->bloquearConteo($this->id($d['conteo_id']??null));$motivo=$this->motivo($d['motivo']??'');
            if($c['estado']!=='aprobado')throw new ErpException($c['estado']==='revertido'?'Este conteo ya fue revertido.':'Solo se revierte un conteo aprobado ('.$c['estado'].').',409);
            $this->exigirOtraPersona($actor,$c);
            $ajustes=$this->r->q("SELECT a.*,l.producto_id,l.nombre FROM erp_inv_conteo_ajustes a JOIN erp_inv_conteo_lineas l ON l.id=a.linea_id WHERE a.conteo_id=? AND a.rol='conteo' ORDER BY l.producto_id FOR UPDATE",[(int)$c['id']]);
            $kc=new ErpInventarioConceptoService($this->r);$cEntrada=null;$cSalida=null;
            if(array_filter($ajustes,static fn($a)=>$a['tipo']==='salida'))$cEntrada=$kc->validarParaSolicitud($d['concepto_entrada_id']??null,'entrada');
            if(array_filter($ajustes,static fn($a)=>$a['tipo']==='entrada'))$cSalida=$kc->validarParaSolicitud($d['concepto_salida_id']??null,'salida');
            // Bloqueo de productos en orden creciente y análisis de reservas antes de aplicar nada.
            foreach($ajustes as $a)$this->r->uno('SELECT id FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$a['producto_id']]);
            foreach($ajustes as $a){
                if($a['tipo']==='entrada'){
                    $p=$this->r->uno('SELECT stock_milesimas FROM inventario_productos WHERE id=? FOR UPDATE',[(int)$a['producto_id']]);// lectura actual: el producto ya está bloqueado y una reposición confirmada durante la espera no se ve en la instantánea
                    if((int)$p['stock_milesimas']-(int)$a['cantidad_milesimas']<$this->reservado((int)$a['producto_id'],true))
                        throw new ErpException('No se puede revertir «'.$a['nombre'].'»: el stock quedaría por debajo de lo reservado o de cero.',409);
                }
            }
            $n=0;
            foreach($ajustes as $a){
                $inverso=$a['tipo']==='entrada'?'salida':'entrada';
                $linea=$this->r->uno('SELECT * FROM erp_inv_conteo_lineas WHERE id=?',[(int)$a['linea_id']]);
                $this->aplicarAjuste($actor,$c,$linea+['nombre'=>$a['nombre']],'reverso',$inverso,(int)$a['cantidad_milesimas'],$inverso==='entrada'?$cEntrada:$cSalida,'Reverso del conteo #'.$c['id'].' · '.$motivo);
                $n++;
            }
            $this->r->q("UPDATE erp_inv_conteos SET estado='revertido',revertido_por=?,revertido_en=?,motivo_reverso=? WHERE id=?",[$actor,ErpRepository::ahora(),$motivo,(int)$c['id']]);
            $this->evento($actor,'inventario_conteo_revertido','inventario_conteo',(int)$c['id'],['motivo'=>$motivo,'ajustes_inversos'=>$n]);
            return (int)$c['id'];
        });
    }

    // ------------------------------------------------------------------------------------------------------------- reglas internas

    private function bloquearConteo(int $id):array
    {
        return $this->r->uno('SELECT * FROM erp_inv_conteos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Conteo no encontrado.',404);
    }
    private function motivo(mixed $m):string
    {
        $m=is_string($m)?trim($m):'';
        if(mb_strlen($m)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');
        return $this->texto($m,300);
    }
    /** Con `$actual` es una lectura actual (LOCK IN SHARE MODE): bajo REPEATABLE READ una lectura normal no vería reservas confirmadas mientras se esperaba el bloqueo del producto. */
    private function reservado(int $producto,bool $actual=false):int
    {
        return (int)$this->r->uno("SELECT COALESCE(SUM(cantidad_milesimas),0) s FROM erp_stock_reservas WHERE producto_id=? AND estado='activa'".($actual?' LOCK IN SHARE MODE':''),[$producto])['s'];
    }
    /** Como en las demás aprobaciones: quien envió el conteo a aprobación no lo aprueba solo (el propietario puede). */
    private function exigirOtraPersona(int $actor,array $c):void
    {
        $ctx=(new AuthorizationService($this->r->db))->contexto($actor);
        if((int)$c['cerrado_por']===$actor&&($ctx['rol']??'')!=='propietario')throw new ErpException('Otra persona autorizada debe resolver este conteo.',403);
    }

    /** Un ajuste por la vía existente: solicitud de ajuste de stock (solicitante = quien envió el conteo) resuelta por quien aprueba. Devuelve el id del vínculo. */
    private function aplicarAjuste(int $actor,array $c,array $linea,string $rol,string $tipo,int $cantidad,int $conceptoId,string $motivo):int
    {
        $ahora=ErpRepository::ahora();
        $datos=['producto_id'=>(int)$linea['producto_id'],'tipo'=>$tipo,'cantidad'=>number_format($cantidad/1000,3,'.',''),'motivo'=>mb_substr($motivo,0,300),'concepto_id'=>$conceptoId,'conteo_id'=>(int)$c['id'],'linea_id'=>(int)$linea['id'],'rol'=>$rol];
        $this->r->q('INSERT INTO erp_solicitudes(tipo,datos_json,motivo,solicitante_id,creado_en) VALUES(?,?,?,?,?)',['ajuste_stock',json_encode($datos,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),mb_substr($motivo,0,300),(int)$c['cerrado_por'],$ahora]);
        $sid=$this->r->id();
        $this->evento((int)$c['cerrado_por'],'aprobacion_solicitada','solicitud',$sid,['tipo'=>'ajuste_stock','conteo_id'=>(int)$c['id'],'linea_id'=>(int)$linea['id'],'rol'=>$rol]);
        $clave=substr(hash('sha256','conteo:'.$c['id'].':'.$rol.':'.$linea['id']),0,40);
        (new ErpAprobacionService($this->r))->resolver($actor,['solicitud_id'=>$sid,'decision'=>'aprobar','nota'=>mb_substr($motivo,0,300),'idem_key'=>$clave]);
        $mov=$this->r->uno("SELECT m.* FROM erp_stock_origenes o JOIN inventario_movimientos m ON m.id=o.movimiento_id WHERE o.documento_tipo='ajuste' AND o.documento_id=?",[$sid])??throw new ErpException('El ajuste no generó su movimiento de inventario.',409);
        $valor=intdiv((int)$mov['costo_centavos']*(int)$mov['cantidad_milesimas']+500,1000);
        $this->r->q('INSERT INTO erp_inv_conteo_ajustes(conteo_id,linea_id,rol,solicitud_id,movimiento_id,tipo,cantidad_milesimas,costo_centavos,valor_centavos,concepto_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [(int)$c['id'],(int)$linea['id'],$rol,$sid,(int)$mov['id'],$tipo,(int)$mov['cantidad_milesimas'],(int)$mov['costo_centavos'],$valor,$conceptoId,$actor,$ahora]);
        return $this->r->id();
    }

    // ------------------------------------------------------------------------------------------------------------- lectura

    /**
     * Líneas del conteo con lo que ocurre AHORA (stock, reservas, costo), la diferencia, su valoración y los conflictos. Con `$bloquear` toma los productos FOR UPDATE en orden.
     * Conflictos: sin_contar (informativo), movimientos_entre_inicio_y_conteo (informativo), movimientos_posteriores_al_conteo (exige confirmación), bajo_reservas (bloquea).
     * @return list<array<string,mixed>>
     */
    public function analizar(array $conteo,bool $bloquear=false):array
    {
        $lineas=$this->r->q('SELECT * FROM erp_inv_conteo_lineas WHERE conteo_id=? ORDER BY producto_id',[(int)$conteo['id']]);
        foreach($lineas as &$l){
            $p=$this->r->uno('SELECT stock_milesimas,costo_centavos FROM inventario_productos WHERE id=?'.($bloquear?' FOR UPDATE':''),[(int)$l['producto_id']]);
            $stock=(int)$p['stock_milesimas'];$costo=(int)$p['costo_centavos'];$res=$this->reservado((int)$l['producto_id'],$bloquear);
            $l['stock_actual']=$stock;$l['reservado_actual']=$res;$l['disponible_actual']=$stock-$res;$l['costo_actual']=$costo;
            $l['contada']=$l['contado_milesimas']!==null;$l['tipo']=null;$l['cantidad']=0;$l['valor_centavos']=0;$l['stock_resultante']=null;$l['conflictos']=[];
            if(!$l['contada']){
                $l['conflictos'][]=['codigo'=>'sin_contar','bloquea'=>false,'mensaje'=>'sin contar: no genera ajuste (no es lo mismo que contado en cero)'];
                continue;
            }
            $dif=(int)$l['diferencia_milesimas'];
            $l['tipo']=$dif>0?'entrada':($dif<0?'salida':null);$l['cantidad']=abs($dif);
            $valor=self::valorAjuste($costo,abs($dif));$l['valor_centavos']=$valor??0;$l['stock_resultante']=$stock+$dif;
            if($valor===null)$l['conflictos'][]=['codigo'=>'valor_excede_asiento','bloquea'=>true,'mensaje'=>'el valor del ajuste (cantidad × costo) excede el máximo de un asiento contable: recuenta o corrige el costo del producto'];
            $entre=$this->movimientos((int)$l['producto_id'],(int)$l['ultimo_mov_ref'],(int)$l['ultimo_mov_al_contar'],$bloquear);
            if($entre['n']>0)$l['conflictos'][]=['codigo'=>'movimientos_entre_inicio_y_conteo','bloquea'=>false,'mensaje'=>$entre['n'].' movimiento(s) entre el inicio y el conteo (neto '.self::mil($entre['neto']).'): la diferencia se calculó contra el stock del sistema al contar, no contra la referencia inicial'];
            $post=$this->movimientos((int)$l['producto_id'],(int)$l['ultimo_mov_al_contar'],null,$bloquear);
            if($post['n']>0)$l['conflictos'][]=['codigo'=>'movimientos_posteriores_al_conteo','bloquea'=>false,'mensaje'=>$post['n'].' movimiento(s) después de contar (neto '.self::mil($post['neto']).'): el ajuste se aplica como diferencia sobre el stock actual'];
            if($dif<0&&$stock+$dif<$res)$l['conflictos'][]=['codigo'=>'bajo_reservas','bloquea'=>true,'mensaje'=>'el stock quedaría en '.self::mil($stock+$dif).' por debajo de lo reservado ('.self::mil($res).'): libera o consume las reservas, o recuenta'];
        }
        unset($l);
        return $lineas;
    }
    /** @return array{n:int,neto:int} movimientos del producto con id > $desde (y ≤ $hasta si se indica). */
    private function movimientos(int $producto,int $desde,?int $hasta,bool $actual=false):array
    {
        $f=$this->r->uno('SELECT COUNT(*) n,COALESCE(SUM(IF(tipo=\'entrada\',cantidad_milesimas,-cantidad_milesimas)),0) neto FROM inventario_movimientos WHERE producto_id=? AND id>?'.($hasta!==null?' AND id<=?':'').($actual?' LOCK IN SHARE MODE':''),$hasta!==null?[$producto,$desde,$hasta]:[$producto,$desde]);
        return ['n'=>(int)$f['n'],'neto'=>(int)$f['neto']];
    }
    /** Valor del ajuste en centavos (cantidad en milésimas × costo), o null si desborda un entero o supera el máximo de un asiento (misma cota que ErpAprobacionService::resolver). */
    private static function valorAjuste(int $costo,int $cantidad):?int
    {
        if($cantidad>0&&$costo>intdiv(PHP_INT_MAX-500,$cantidad))return null;
        $v=intdiv($costo*$cantidad+500,1000);
        return $v>99999999999?null:$v;
    }
    private static function mil(int $m):string {return rtrim(rtrim(number_format($m/1000,3,'.',''),'0'),'.')?:'0';}

    public function lista():array
    {
        return $this->r->q('SELECT c.id,c.estado,c.alcance,c.descripcion,c.iniciado_en,c.cerrado_en,c.resuelto_en,c.motivo,u.nombre responsable,
            (SELECT COUNT(*) FROM erp_inv_conteo_lineas l WHERE l.conteo_id=c.id) productos,(SELECT COUNT(*) FROM erp_inv_conteo_lineas l WHERE l.conteo_id=c.id AND l.contado_milesimas IS NOT NULL) contadas,
            (SELECT COUNT(*) FROM erp_inv_conteo_ajustes a WHERE a.conteo_id=c.id) ajustes
            FROM erp_inv_conteos c LEFT JOIN usuarios u ON u.id=c.responsable_id ORDER BY c.id DESC LIMIT 100');
    }
    /** Un conteo con sus líneas analizadas (en vivo mientras está abierto), ajustes aplicados y personas. */
    public function conteo(int $id):?array
    {
        $c=$this->r->uno('SELECT c.*,ur.nombre responsable_nombre,ui.nombre iniciado_nombre,uc.nombre cerrado_nombre,uz.nombre resuelto_nombre,uv.nombre revertido_nombre
            FROM erp_inv_conteos c LEFT JOIN usuarios ur ON ur.id=c.responsable_id LEFT JOIN usuarios ui ON ui.id=c.iniciado_por LEFT JOIN usuarios uc ON uc.id=c.cerrado_por
            LEFT JOIN usuarios uz ON uz.id=c.resuelto_por LEFT JOIN usuarios uv ON uv.id=c.revertido_por WHERE c.id=?',[$id]);
        if(!$c)return null;
        $c['lineas']=$this->analizar($c);
        $c['ajustes']=$this->r->q('SELECT a.*,l.nombre,l.sku,k.nombre concepto FROM erp_inv_conteo_ajustes a JOIN erp_inv_conteo_lineas l ON l.id=a.linea_id JOIN erp_inv_conceptos k ON k.id=a.concepto_id WHERE a.conteo_id=? ORDER BY a.id',[$id]);
        $c['registros']=(int)$this->r->uno('SELECT COUNT(*) n FROM erp_inv_conteo_registros WHERE conteo_id=?',[$id])['n'];
        $totales=['faltante_centavos'=>0,'sobrante_centavos'=>0,'con_diferencia'=>0,'sin_contar'=>0,'bloqueos'=>0,'avisos'=>0];
        foreach($c['lineas'] as $l){
            if(!$l['contada'])$totales['sin_contar']++;
            if($l['tipo']==='salida')$totales['faltante_centavos']+=$l['valor_centavos'];elseif($l['tipo']==='entrada')$totales['sobrante_centavos']+=$l['valor_centavos'];
            if($l['tipo']!==null)$totales['con_diferencia']++;
            foreach($l['conflictos'] as $k){if($k['bloquea'])$totales['bloqueos']++;elseif($k['codigo']==='movimientos_posteriores_al_conteo')$totales['avisos']++;}
        }
        $c['totales']=$totales;
        return $c;
    }
    /** Historial de lecturas de una línea (solo de anexar). */
    public function registros(int $lineaId):array
    {
        return $this->r->q('SELECT r.*,u.nombre actor FROM erp_inv_conteo_registros r LEFT JOIN usuarios u ON u.id=r.actor_id WHERE r.linea_id=? ORDER BY r.id',[$lineaId]);
    }
    public function productos():array
    {
        return $this->r->q('SELECT id,sku,nombre,unidad,stock_milesimas FROM inventario_productos WHERE activo=1 ORDER BY nombre');
    }
    /** Personas que pueden ser responsables de un conteo (gestionan inventario). */
    public function responsables():array
    {
        $a=new AuthorizationService($this->r->db);$o=[];
        foreach($this->r->q('SELECT id,nombre,apellido FROM usuarios WHERE confirmado=1 ORDER BY nombre LIMIT 300') as $u)if($a->permite((int)$u['id'],'inventario.gestionar'))$o[]=$u;
        return $o;
    }
}
