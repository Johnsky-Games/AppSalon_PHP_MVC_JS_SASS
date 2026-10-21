<?php
namespace Services;

use Services\Contabilidad\DepreciacionLineal as D;

/**
 * Depreciación de activos fijos (migración 052). Todo es POLÍTICA CONTABLE configurable por el propietario con su fuente; no se cargan vidas útiles,
 * porcentajes residuales ni límites de deducibilidad tributaria (esas reglas requieren fuente oficial y no se implementan aquí).
 *
 *  · Política (`dep_categoria`): vida útil, residual y método (solo lineal). Se copia a la ficha de cada activo: crear otra política no altera lo ya configurado.
 *  · Ficha (`dep_ficha`): una por activo (única en la base), con el mes de inicio y, para un activo anterior al libro, el acumulado inicial.
 *  · Corrida mensual: calcular (borrador) → contabilizar (hecho al adaptador contable: Dr gasto por depreciación · Cr depreciación acumulada, fechado el último
 *    día del mes) → anular (reverso). Una sola corrida vigente por mes (única en la base); los meses se contabilizan en orden y sin saltos.
 *  · Baja: retira el activo (Dr depreciación acumulada · Dr pérdida por baja por el valor en libros · Cr activos fijos por el costo). No hay venta: una venta
 *    exige comprobante electrónico y tratamiento tributario, bloqueados hasta contar con fuente oficial.
 * Permisos: configurar políticas/fichas, bajas y anulaciones = `contabilidad.configurar` (propietario); calcular y contabilizar = `contabilidad.registrar`.
 */
final class ErpDepreciacionService extends ErpService
{
    public const MOTIVOS_BAJA=['obsolescencia'=>'Obsolescencia','dano'=>'Daño irreparable','perdida'=>'Pérdida o robo','donacion'=>'Donación','otro'=>'Otro (explicar en la nota)'];

    private function hoy():string {return substr($this->r::ahora(),0,10);}
    private function entero(mixed $v,int $min,int $max,string $que):int
    {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^\d{1,6}$/D',(string)$v)||(int)$v<$min||(int)$v>$max)throw new ErpException($que.' debe ser un entero entre '.$min.' y '.$max.'.');
        return (int)$v;
    }
    private function inicioContable():string
    {
        $c=$this->r->uno('SELECT inicio_contable FROM erp_cont_config WHERE id=1 FOR SHARE');
        if(!$c||!$c['inicio_contable'])throw new ErpException('Define primero la fecha de inicio contable.',409);return (string)$c['inicio_contable'];
    }
    private function ym(int $anio,int $mes):string {return sprintf('%04d-%02d',$anio,$mes);}

    // ---------------------------------------------------------------- configuración
    public function categoriaCrear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_categoria',$d,function()use($actor,$d){
            $nombre=$this->texto($d['nombre']??'',80);if(mb_strlen($nombre)<2)throw new ErpException('Indica el nombre de la política.');
            $vida=$this->entero($d['vida_util_meses']??null,1,600,'La vida útil (meses)');
            $bp=D::puntosBasicos((string)($d['residual_pct']??''));if($bp===null||$bp>9999)throw new ErpException('El valor residual es un porcentaje del costo entre 0 y 99,99 (hasta 2 decimales).');
            if(($d['metodo']??'lineal')!=='lineal')throw new ErpException('Solo está disponible el método lineal.');
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica la fuente o el criterio contable (p. ej. «Política acordada con el contador, acta del …»).');
            if($this->r->uno('SELECT id FROM erp_dep_categorias WHERE nombre=? FOR UPDATE',[$nombre]))throw new ErpException('Ya existe una política con ese nombre.',409);
            $this->r->q("INSERT INTO erp_dep_categorias(nombre,vida_util_meses,residual_bp,metodo,fuente,creado_por,creado_en) VALUES(?,?,?,'lineal',?,?,?)",[$nombre,$vida,$bp,$fuente,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'dep_categoria_creada','dep_categoria',$id,['nombre'=>$nombre,'vida_util_meses'=>$vida,'residual_bp'=>$bp,'fuente'=>$fuente]);return $id;
        });
    }

    public function fichaCrear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_ficha',$d,function()use($actor,$d){
            $inicioContable=$this->inicioContable();
            $a=$this->r->uno('SELECT * FROM erp_activos WHERE id=? FOR UPDATE',[$this->id($d['activo_id']??null)])??throw new ErpException('Activo no encontrado.',404);
            if($this->r->uno('SELECT id FROM erp_dep_fichas WHERE activo_id=? FOR UPDATE',[(int)$a['id']]))throw new ErpException('Ese activo ya tiene su ficha de depreciación.',409);
            $cat=$this->r->uno('SELECT * FROM erp_dep_categorias WHERE id=? FOR SHARE',[$this->id($d['categoria_id']??null)])??throw new ErpException('Política de depreciación no encontrada.',404);
            $mes=is_string($d['inicio']??null)&&preg_match('/^(\d{4})-(\d{2})$/D',$d['inicio'],$m)&&(int)$m[2]>=1&&(int)$m[2]<=12?$d['inicio']:throw new ErpException('El mes de inicio va como AAAA-MM.');
            $inicio=$mes.'-01';
            if(substr($a['adquirido_en'],0,7)>$mes)throw new ErpException('No se deprecia antes del mes de adquisición ('.substr($a['adquirido_en'],0,7).').');
            if($inicio<substr($inicioContable,0,7).'-01')throw new ErpException('El inicio de depreciación no puede ser anterior al mes del inicio contable ('.substr($inicioContable,0,7).'). Para un activo anterior al libro, empieza en ese mes e indica el acumulado inicial.');
            $costo=(int)$a['costo_centavos'];if($costo<1)throw new ErpException('El activo no tiene costo: corrígelo antes de configurar su depreciación.');
            $acum=trim((string)($d['acumulado_inicial']??''))===''?0:$this->importe($d['acumulado_inicial'],true);
            if($acum>0&&$a['adquirido_en']>=$inicioContable)throw new ErpException('El acumulado inicial solo aplica a un activo adquirido antes del inicio contable ('.$inicioContable.').');
            $sinVida=trim((string)($d['vida_util_meses']??''))==='';
            // Un activo con acumulado inicial ya consumió parte de su vida: se reparte el saldo en los meses que le quedan, no en la vida completa de la política.
            if($acum>0&&$sinVida)throw new ErpException('Indica los meses de vida útil que le quedan al activo: el acumulado inicial ya consumió parte de la vida de la política.');
            $vida=$sinVida?(int)$cat['vida_util_meses']:$this->entero($d['vida_util_meses'],1,600,'La vida útil (meses)');
            if($acum>0&&$vida>(int)$cat['vida_util_meses'])throw new ErpException('Los meses restantes no pueden superar la vida de la política ('.(int)$cat['vida_util_meses'].').');
            // Un mes ya procesado no puede incluir un activo que entra tarde: las cuotas se reparten en orden y sin saltos.
            if($this->r->uno("SELECT id FROM erp_dep_corridas WHERE estado<>'anulada' AND anio*100+mes>=? LIMIT 1 FOR UPDATE",[(int)substr($mes,0,4)*100+(int)substr($mes,5,2)]))throw new ErpException('Ya existe una corrida de ese mes o posterior: el activo no puede empezar a depreciarse en un mes ya procesado. Empieza en el primer mes sin corrida o anula las corridas posteriores.',409);
            $residual=D::residual($costo,(int)$cat['residual_bp']);
            if($acum+$residual>=$costo)throw new ErpException('El acumulado inicial más el valor residual debe ser menor que el costo: no queda nada por depreciar.');
            $this->r->q("INSERT INTO erp_dep_fichas(activo_id,categoria_id,inicio,costo_centavos,acumulado_inicial_centavos,residual_centavos,vida_util_meses,metodo,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,'lineal',?,?)",[(int)$a['id'],(int)$cat['id'],$inicio,$costo,$acum,$residual,$vida,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'dep_ficha_creada','activo',(int)$a['id'],['ficha_id'=>$id,'categoria_id'=>(int)$cat['id'],'inicio'=>$inicio,'costo'=>$costo,'acumulado_inicial'=>$acum,'residual'=>$residual,'vida_util_meses'=>$vida]);return $id;
        });
    }

    // ---------------------------------------------------------------- corridas mensuales
    /** Corridas vigentes (no anuladas) distintas de $excluir: los meses se calculan y contabilizan en orden. */
    private function verificarSecuencia(int $anio,int $mes,?int $excluir):void
    {
        $clave=$anio*100+$mes;
        foreach($this->r->q("SELECT id,anio,mes,estado FROM erp_dep_corridas WHERE estado<>'anulada' AND id<>? FOR UPDATE",[(int)$excluir]) as $c){
            $k=(int)$c['anio']*100+(int)$c['mes'];
            if($k===$clave)throw new ErpException('El mes '.$this->ym($anio,$mes).' ya tiene una corrida vigente ('.$c['estado'].').',409);
            if($k>$clave)throw new ErpException('Existe una corrida de un mes posterior ('.$this->ym((int)$c['anio'],(int)$c['mes']).'): anúlala antes de recalcular este mes.',409);
            if($c['estado']==='borrador')throw new ErpException('Contabiliza o descarta primero el borrador de '.$this->ym((int)$c['anio'],(int)$c['mes']).'.',409);
        }
    }
    private function acumuladoContabilizado(int $activo):int
    {
        return (int)$this->r->uno("SELECT COALESCE(SUM(l.monto_centavos),0) s FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id WHERE l.activo_id=? AND c.estado='contabilizada'",[$activo])['s'];
    }
    /**
     * Tramos de depreciación de una ficha: el original y cada cambio de estimación vigente, en orden de fecha. Cada tramo reparte su `base`
     * (lo que falta por depreciar a su inicio) en `vida` meses a partir de `desde`; `previo` es el acumulado total a esa fecha.
     * @return list<array{desde:string,previo:int,base:int,vida:int,residual:int}>
     */
    private function tramos(array $f):array
    {
        $t=[['desde'=>(string)$f['inicio'],'previo'=>(int)$f['acumulado_inicial_centavos'],'base'=>(int)$f['costo_centavos']-(int)$f['residual_centavos']-(int)$f['acumulado_inicial_centavos'],'vida'=>(int)$f['vida_util_meses'],'residual'=>(int)$f['residual_centavos']]];
        foreach($this->r->q("SELECT * FROM erp_dep_estimaciones WHERE ficha_id=? AND estado='vigente' ORDER BY desde",[(int)$f['id']]) as $e)
            $t[]=['desde'=>(string)$e['desde'],'previo'=>(int)$e['acumulado_previo_centavos'],'base'=>(int)$e['base_centavos'],'vida'=>(int)$e['vida_meses'],'residual'=>(int)$e['residual_centavos']];
        return $t;
    }
    /** Tramo que rige un mes (el último cuyo inicio no es posterior); null si el mes es anterior al inicio de la ficha. */
    private function tramoDelMes(array $tramos,int $anio,int $mes):?array
    {
        $r=null;foreach($tramos as $t)if(D::numeroDeMes($t['desde'],$anio,$mes)>=1)$r=$t;return $r;
    }
    /** Acumulado total que debe llevar contabilizado un activo al empezar el mes, según el tramo que lo rige. */
    private function esperadoAlMes(array $tramos,int $anio,int $mes):int
    {
        $t=$this->tramoDelMes($tramos,$anio,$mes);if(!$t)return $tramos[0]['previo'];
        $n=min($t['vida'],max(0,D::numeroDeMes($t['desde'],$anio,$mes)-1));return $t['previo']+D::objetivo($t['base'],$t['vida'],$n);
    }
    /** @return list<array{activo_id:int,codigo:string,nro:int,monto:int,acumulado:int,libros:int}> */
    private function lineasDelMes(int $anio,int $mes):array
    {
        $out=[];
        foreach($this->r->q('SELECT f.*,a.codigo FROM erp_dep_fichas f JOIN erp_activos a ON a.id=f.activo_id WHERE NOT EXISTS(SELECT 1 FROM erp_dep_bajas b WHERE b.activo_id=f.activo_id AND b.estado=\'vigente\') ORDER BY f.activo_id FOR SHARE') as $f){
            $tramos=$this->tramos($f);$t=$this->tramoDelMes($tramos,$anio,$mes);if(!$t)continue;
            $k=D::numeroDeMes($t['desde'],$anio,$mes);if($k>$t['vida'])continue;
            $posted=(int)$f['acumulado_inicial_centavos']+$this->acumuladoContabilizado((int)$f['activo_id']);$esperado=$this->esperadoAlMes($tramos,$anio,$mes);
            if($posted!==$esperado)throw new ErpException('El activo '.$f['codigo'].' no está al día: lleva '.number_format($posted/100,2,'.','').' depreciados y a este mes le corresponden '.number_format($esperado/100,2,'.','').'. Contabiliza antes los meses anteriores.',409);
            $cuota=D::cuota($t['base'],$t['vida'],$k);if($cuota<1)continue;
            $acum=$posted+$cuota;
            $out[]=['activo_id'=>(int)$f['activo_id'],'codigo'=>$f['codigo'],'nro'=>$k,'monto'=>$cuota,'acumulado'=>$acum,'libros'=>(int)$f['costo_centavos']-$acum];
        }
        return $out;
    }
    private function periodo(mixed $anio,mixed $mes):array
    {
        $a=$this->entero($anio,2000,2100,'El año');$m=$this->entero($mes,1,12,'El mes');
        $inicio=$this->inicioContable();$fin=D::finDeMes($a,$m);
        if($fin>$this->hoy())throw new ErpException('El mes '.$this->ym($a,$m).' aún no termina: se deprecia por meses completos, una vez cerrados.',409);
        if($fin<$inicio)throw new ErpException('El mes '.$this->ym($a,$m).' es anterior al inicio contable ('.$inicio.').',409);
        return [$a,$m,$fin];
    }

    public function calcular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','dep_calcular',$d,function()use($actor,$d){
            [$anio,$mes]=$this->periodo($d['anio']??null,$d['mes']??null);
            $this->verificarSecuencia($anio,$mes,null);
            $lineas=$this->lineasDelMes($anio,$mes);if(!$lineas)throw new ErpException('Ningún activo tiene cuota de depreciación en '.$this->ym($anio,$mes).'.',409);
            $total=array_sum(array_column($lineas,'monto'));
            $version=1+(int)$this->r->uno('SELECT COALESCE(MAX(version),0) v FROM erp_dep_corridas WHERE anio=? AND mes=?',[$anio,$mes])['v'];
            try{$this->r->q("INSERT INTO erp_dep_corridas(anio,mes,version,estado,total_centavos,n_lineas,creado_por,creado_en) VALUES(?,?,?,'borrador',?,?,?,?)",[$anio,$mes,$version,$total,count($lineas),$actor,$this->r::ahora()]);}
            catch(\mysqli_sql_exception $e){if((int)$e->getCode()===1062)throw new ErpException('El mes '.$this->ym($anio,$mes).' ya tiene una corrida vigente.',409);throw $e;}
            $id=$this->r->id();
            foreach($lineas as $l)$this->r->q('INSERT INTO erp_dep_lineas(corrida_id,activo_id,nro_cuota,monto_centavos,acumulado_centavos,valor_libros_centavos) VALUES(?,?,?,?,?,?)',[$id,$l['activo_id'],$l['nro'],$l['monto'],$l['acumulado'],$l['libros']]);
            $this->evento($actor,'dep_corrida_calculada','dep_corrida',$id,['periodo'=>$this->ym($anio,$mes),'version'=>$version,'total'=>$total,'n_lineas'=>count($lineas)]);return $id;
        });
    }

    public function contabilizar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','dep_contabilizar',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_dep_corridas WHERE id=? FOR UPDATE',[$this->id($d['corrida_id']??null)])??throw new ErpException('Corrida no encontrada.',404);
            if($k['estado']==='contabilizada')return (int)$k['id'];
            if($k['estado']!=='borrador')throw new ErpException('La corrida está anulada: calcula el mes de nuevo.',409);
            [$anio,$mes,$fin]=$this->periodo($k['anio'],$k['mes']);
            $this->verificarSecuencia($anio,$mes,(int)$k['id']);
            // El borrador debe seguir siendo exactamente lo que se calcularía hoy: si cambió una ficha, una baja o un mes anterior, se recalcula.
            $ahora=$this->lineasDelMes($anio,$mes);$guardadas=$this->r->q('SELECT activo_id,nro_cuota,monto_centavos,acumulado_centavos FROM erp_dep_lineas WHERE corrida_id=? ORDER BY activo_id',[(int)$k['id']]);
            $a=array_map(fn($l)=>[$l['activo_id'],$l['nro'],$l['monto'],$l['acumulado']],$ahora);$b=array_map(fn($l)=>[(int)$l['activo_id'],(int)$l['nro_cuota'],(int)$l['monto_centavos'],(int)$l['acumulado_centavos']],$guardadas);
            if($a!==$b)throw new ErpException('Los datos cambiaron desde que se calculó el borrador: descártalo y vuelve a calcular el mes.',409);
            $periodo=$this->ym($anio,$mes);
            $evento=$this->evento($actor,'depreciacion_contabilizada','dep_corrida',(int)$k['id'],['periodo'=>$periodo,'anio'=>$anio,'mes'=>$mes,'total'=>(int)$k['total_centavos'],'n_lineas'=>(int)$k['n_lineas'],'fecha'=>$fin],['contabilidad']);
            $this->r->q("UPDATE erp_dep_corridas SET estado='contabilizada',contabilizada_por=?,contabilizada_en=?,evento_contab_id=? WHERE id=?",[$actor,$this->r::ahora(),$evento,(int)$k['id']]);
            return (int)$k['id'];
        });
    }

    /** Descarta un borrador o anula una corrida contabilizada con su reverso (solo la última y solo si ninguna baja posterior depende de ella). */
    public function anular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_anular',$d,function()use($actor,$d){
            $k=$this->r->uno('SELECT * FROM erp_dep_corridas WHERE id=? FOR UPDATE',[$this->id($d['corrida_id']??null)])??throw new ErpException('Corrida no encontrada.',404);
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');
            if($k['estado']==='anulada')return (int)$k['id'];
            $clave=(int)$k['anio']*100+(int)$k['mes'];
            if($this->r->uno("SELECT id FROM erp_dep_corridas WHERE estado<>'anulada' AND anio*100+mes>? LIMIT 1 FOR UPDATE",[$clave]))throw new ErpException('Hay corridas de meses posteriores: anúlalas primero, de la más reciente a la más antigua.',409);
            if($this->r->uno("SELECT e.id FROM erp_dep_estimaciones e JOIN erp_dep_lineas l ON l.activo_id=e.activo_id AND l.corrida_id=? WHERE e.estado='vigente' AND e.desde>? LIMIT 1 FOR UPDATE",[(int)$k['id'],sprintf('%04d-%02d-01',$k['anio'],$k['mes'])]))throw new ErpException('Un cambio de estimación posterior toma como base esta corrida: anúlalo antes.',409);
            if($k['estado']==='contabilizada'&&$this->r->uno("SELECT id FROM erp_dep_bajas WHERE estado='vigente' AND fecha>? LIMIT 1 FOR UPDATE",[D::finDeMes((int)$k['anio'],(int)$k['mes'])]))throw new ErpException('Una baja posterior depende de esta corrida: anula antes la baja.',409);
            $this->r->q("UPDATE erp_dep_corridas SET estado='anulada',anulada_por=?,anulada_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$k['id']]);
            $periodo=$this->ym((int)$k['anio'],(int)$k['mes']);
            if($k['estado']==='contabilizada')$this->evento($actor,'depreciacion_anulada','dep_corrida',(int)$k['id'],['aprobacion_evento_id'=>(int)$k['evento_contab_id'],'motivo'=>$motivo,'periodo'=>$periodo,'fecha'=>$this->hoy()],['contabilidad']);
            else $this->evento($actor,'dep_borrador_descartado','dep_corrida',(int)$k['id'],['motivo'=>$motivo,'periodo'=>$periodo]);
            return (int)$k['id'];
        });
    }

    // ---------------------------------------------------------------- bajas
    public function bajaRegistrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_baja',$d,function()use($actor,$d){
            $inicioContable=$this->inicioContable();
            $a=$this->r->uno('SELECT * FROM erp_activos WHERE id=? FOR UPDATE',[$this->id($d['activo_id']??null)])??throw new ErpException('Activo no encontrado.',404);
            $f=$this->r->uno('SELECT * FROM erp_dep_fichas WHERE activo_id=? FOR SHARE',[(int)$a['id']])??throw new ErpException('Configura primero la depreciación del activo: la baja se calcula sobre su ficha.',409);
            if($this->r->uno("SELECT id FROM erp_dep_bajas WHERE activo_id=? AND estado='vigente' FOR UPDATE",[(int)$a['id']]))throw new ErpException('El activo ya está dado de baja.',409);
            $fecha=$this->fecha($d['fecha']??null);if($fecha>$this->hoy())throw new ErpException('La baja no puede tener fecha futura.');
            if($fecha<$inicioContable||$fecha<$a['adquirido_en'])throw new ErpException('La fecha de baja es anterior al inicio contable o a la adquisición del activo.');
            $motivo=$d['motivo']??'';if(!isset(self::MOTIVOS_BAJA[$motivo]))throw new ErpException('Motivo de baja no válido. La venta de activos no está disponible: requiere comprobante electrónico y tratamiento tributario sin fuente verificada.');
            $nota=trim((string)($d['nota']??''))===''?null:$this->texto($d['nota'],300);if($motivo==='otro'&&$nota===null)throw new ErpException('Explica el motivo en la nota.');
            [$fa,$fm]=[(int)substr($fecha,0,4),(int)substr($fecha,5,2)];
            // En el mes de la baja no se deprecia: ese mes (o uno posterior) no puede tener cuota del activo, y todo mes anterior debe estar contabilizado.
            if($this->r->uno("SELECT l.id FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id WHERE l.activo_id=? AND c.estado<>'anulada' AND c.anio*100+c.mes>=? LIMIT 1 FOR SHARE",[(int)$a['id'],$fa*100+$fm]))throw new ErpException('El activo ya se depreció en el mes de la baja o en uno posterior: la baja va en un mes posterior al último depreciado.',409);
            $posted=(int)$f['acumulado_inicial_centavos']+$this->acumuladoContabilizado((int)$a['id']);$esperado=$this->esperadoAlMes($this->tramos($f),$fa,$fm);
            if($posted!==$esperado)throw new ErpException('Contabiliza primero la depreciación hasta el mes anterior a la baja (acumulado '.number_format($posted/100,2,'.','').', esperado '.number_format($esperado/100,2,'.','').').',409);
            $costo=(int)$f['costo_centavos'];$acum=$posted;$libros=$costo-$acum;
            $evento=$this->evento($actor,'activo_baja','activo',(int)$a['id'],['activo_id'=>(int)$a['id'],'codigo'=>$a['codigo'],'costo'=>$costo,'acumulado'=>$acum,'valor_libros'=>$libros,'fecha'=>$fecha,'motivo'=>$motivo],['contabilidad']);
            $this->r->q("INSERT INTO erp_dep_bajas(activo_id,fecha,motivo,nota,costo_centavos,acumulado_centavos,valor_libros_centavos,evento_contab_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?)",[(int)$a['id'],$fecha,$motivo,$nota,$costo,$acum,$libros,$evento,$actor,$this->r::ahora()]);
            return $this->r->id();
        });
    }

    /** Anula una baja con su reverso, siempre que no se haya depreciado ningún mes desde la baja (el activo reanuda donde estaba). */
    public function bajaAnular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_baja_anular',$d,function()use($actor,$d){
            $b=$this->r->uno('SELECT * FROM erp_dep_bajas WHERE id=? FOR UPDATE',[$this->id($d['baja_id']??null)])??throw new ErpException('Baja no encontrada.',404);
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');
            if($b['estado']==='anulada')return (int)$b['id'];
            $clave=(int)substr($b['fecha'],0,4)*100+(int)substr($b['fecha'],5,2);
            if($this->r->uno("SELECT id FROM erp_dep_corridas WHERE estado<>'anulada' AND anio*100+mes>=? LIMIT 1 FOR UPDATE",[$clave]))throw new ErpException('Ya hay corridas de depreciación del mes de la baja o posteriores (se calcularon sin este activo): anúlalas antes.',409);
            $this->r->q("UPDATE erp_dep_bajas SET estado='anulada',anulada_por=?,anulada_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$b['id']]);
            $this->evento($actor,'activo_baja_anulada','activo',(int)$b['activo_id'],['aprobacion_evento_id'=>(int)$b['evento_contab_id'],'motivo'=>$motivo,'fecha'=>$this->hoy()],['contabilidad']);
            return (int)$b['id'];
        });
    }

    // ---------------------------------------------------------------- cambios de estimación (prospectivos)
    /** ¿Existe alguna corrida vigente (borrador o contabilizada, de cualquier activo) en el mes indicado o en uno posterior? Ese mes ya fue procesado. */
    private function hayCorridaDesde(string $desde):bool
    {
        return (bool)$this->r->uno("SELECT id FROM erp_dep_corridas WHERE estado<>'anulada' AND anio*100+mes>=? LIMIT 1 FOR SHARE",[(int)substr($desde,0,4)*100+(int)substr($desde,5,2)]);
    }
    /**
     * Cambio de estimación PROSPECTIVO (vida restante y, opcionalmente, valor residual). Rige desde un mes en el que el activo todavía no tiene cuota: lo depreciado
     * hasta el mes anterior queda tal cual (no se anula ni se recalcula) y el saldo por depreciar se reparte en la vida restante. Exige que el activo esté al día.
     */
    public function estimacionRegistrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_estimacion',$d,function()use($actor,$d){
            $inicioContable=$this->inicioContable();
            $a=$this->r->uno('SELECT * FROM erp_activos WHERE id=? FOR UPDATE',[$this->id($d['activo_id']??null)])??throw new ErpException('Activo no encontrado.',404);
            $f=$this->r->uno('SELECT * FROM erp_dep_fichas WHERE activo_id=? FOR UPDATE',[(int)$a['id']])??throw new ErpException('Configura primero la depreciación del activo.',409);
            if($this->r->uno("SELECT id FROM erp_dep_bajas WHERE activo_id=? AND estado='vigente' FOR UPDATE",[(int)$a['id']]))throw new ErpException('El activo está dado de baja.',409);
            $mes=is_string($d['desde']??null)&&preg_match('/^(\d{4})-(\d{2})$/D',$d['desde'],$m)&&(int)$m[2]>=1&&(int)$m[2]<=12?$d['desde']:throw new ErpException('El mes de vigencia va como AAAA-MM.');
            $desde=$mes.'-01';[$anio,$mm]=[(int)$m[1],(int)$m[2]];
            if($desde<substr($inicioContable,0,7).'-01')throw new ErpException('La vigencia no puede ser anterior al mes del inicio contable ('.substr($inicioContable,0,7).').');
            $tramos=$this->tramos($f);$ultimo=end($tramos);
            if($desde<=$ultimo['desde'])throw new ErpException('Ya hay una vigencia desde '.substr($ultimo['desde'],0,7).': la nueva debe empezar en un mes posterior.',409);
            if($this->hayCorridaDesde($desde))throw new ErpException('Ya existe una corrida de depreciación de '.$mes.' o de un mes posterior: un cambio de estimación aplica hacia adelante, desde el primer mes sin corrida, sin tocar lo ya calculado.',409);
            $posted=(int)$f['acumulado_inicial_centavos']+$this->acumuladoContabilizado((int)$a['id']);$esperado=$this->esperadoAlMes($tramos,$anio,$mm);
            if($posted!==$esperado)throw new ErpException('El activo no está al día a '.$mes.': lleva '.number_format($posted/100,2,'.','').' depreciados y le corresponden '.number_format($esperado/100,2,'.','').'. Contabiliza antes los meses anteriores.',409);
            $vida=$this->entero($d['vida_meses']??null,1,600,'Los meses de vida restantes');
            $costo=(int)$f['costo_centavos'];
            if(trim((string)($d['residual_pct']??''))===''){$residual=(int)$ultimo['residual'];}
            else{$bp=D::puntosBasicos((string)$d['residual_pct']);if($bp===null||$bp>9999)throw new ErpException('El valor residual es un porcentaje del costo entre 0 y 99,99 (hasta 2 decimales).');$residual=D::residual($costo,$bp);}
            $base=$costo-$residual-$posted;if($base<1)throw new ErpException('Con ese valor residual no queda nada por depreciar: el acumulado ya cubre costo menos residual.');
            $criterio=$this->texto($d['criterio']??'',300);if(mb_strlen($criterio)<5)throw new ErpException('Indica el criterio contable del cambio de estimación (al menos 5 caracteres).');
            $evento=$this->evento($actor,'dep_estimacion_registrada','activo',(int)$a['id'],['activo_id'=>(int)$a['id'],'desde'=>$desde,'acumulado_previo'=>$posted,'residual'=>$residual,'base'=>$base,'vida_meses'=>$vida,'criterio'=>$criterio]);
            $this->r->q("INSERT INTO erp_dep_estimaciones(ficha_id,activo_id,desde,acumulado_previo_centavos,residual_centavos,base_centavos,vida_meses,criterio,evento_id,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?)",[(int)$f['id'],(int)$a['id'],$desde,$posted,$residual,$base,$vida,$criterio,$evento,$actor,$this->r::ahora()]);
            return $this->r->id();
        });
    }
    /** Anula una vigencia que todavía no se usó (no hay corridas desde ese mes). Lo ya calculado nunca se toca. */
    public function estimacionAnular(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','dep_estimacion_anular',$d,function()use($actor,$d){
            $e=$this->r->uno('SELECT * FROM erp_dep_estimaciones WHERE id=? FOR UPDATE',[$this->id($d['estimacion_id']??null)])??throw new ErpException('Cambio de estimación no encontrado.',404);
            $motivo=$this->texto($d['motivo']??'',300);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');
            if($e['estado']==='anulada')return (int)$e['id'];
            if($this->hayCorridaDesde((string)$e['desde']))throw new ErpException('Ya hay corridas de esa vigencia o posteriores: no se puede anular sin tocar depreciaciones válidas. Registra un nuevo cambio de estimación hacia adelante.',409);
            $this->r->q("UPDATE erp_dep_estimaciones SET estado='anulada',anulada_por=?,anulada_en=?,anulacion_motivo=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$e['id']]);
            $this->evento($actor,'dep_estimacion_anulada','activo',(int)$e['activo_id'],['estimacion_id'=>(int)$e['id'],'motivo'=>$motivo]);
            return (int)$e['id'];
        });
    }
    public function estimaciones():array {return $this->r->q('SELECT e.*,a.codigo,a.nombre FROM erp_dep_estimaciones e JOIN erp_activos a ON a.id=e.activo_id ORDER BY e.id DESC LIMIT 100');}

    // ---------------------------------------------------------------- lectura / reportes
    /** Auxiliar de activos: costo, acumulado, valor en libros y estado de cada activo (con o sin ficha). */
    public function activos():array
    {
        $out=[];
        foreach($this->r->q('SELECT a.id,a.codigo,a.nombre,a.costo_centavos,a.adquirido_en,f.id ficha_id,f.inicio,f.vida_util_meses,f.residual_centavos,f.acumulado_inicial_centavos,c.nombre categoria,
                (SELECT b.id FROM erp_dep_bajas b WHERE b.activo_id=a.id AND b.estado=\'vigente\') baja_id,(SELECT b.fecha FROM erp_dep_bajas b WHERE b.activo_id=a.id AND b.estado=\'vigente\') baja_fecha
                FROM erp_activos a LEFT JOIN erp_dep_fichas f ON f.activo_id=a.id LEFT JOIN erp_dep_categorias c ON c.id=f.categoria_id ORDER BY a.id') as $a){
            if(!$a['ficha_id']){$a+=['acumulado'=>0,'libros'=>(int)$a['costo_centavos'],'estado'=>'sin_configurar','meses_depreciados'=>0];$out[]=$a;continue;}
            $posted=$this->acumuladoContabilizado((int)$a['id']);$acum=(int)$a['acumulado_inicial_centavos']+$posted;
            $meses=(int)$this->r->uno("SELECT COUNT(*) n FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id WHERE l.activo_id=? AND c.estado='contabilizada'",[(int)$a['id']])['n'];
            $a['acumulado']=$acum;$a['libros']=(int)$a['costo_centavos']-$acum;$a['meses_depreciados']=$meses;
            $tr=$this->tramos($this->r->uno('SELECT * FROM erp_dep_fichas WHERE id=?',[(int)$a['ficha_id']]));$resid=(int)end($tr)['residual'];$a['residual_vigente']=$resid;$a['estimaciones']=count($tr)-1;
            $a['estado']=$a['baja_id']?'baja':($acum+$resid>=(int)$a['costo_centavos']?'totalmente_depreciado':'en_depreciacion');$out[]=$a;
        }
        return $out;
    }
    public function categorias():array {return $this->r->q('SELECT * FROM erp_dep_categorias ORDER BY nombre');}
    public function corridas(int $limite=60):array {return $this->r->q('SELECT * FROM erp_dep_corridas ORDER BY anio DESC,mes DESC,version DESC LIMIT '.max(1,min(200,$limite)));}
    public function bajas():array {return $this->r->q('SELECT b.*,a.codigo,a.nombre FROM erp_dep_bajas b JOIN erp_activos a ON a.id=b.activo_id ORDER BY b.id DESC LIMIT 100');}
    /** Depreciación contabilizada por mes de un año (reporte del ejercicio). @return list<array{mes:int,total:int,activos:int}> */
    public function porMes(int $anio):array
    {
        return array_map(fn($f)=>['mes'=>(int)$f['mes'],'total'=>(int)$f['total'],'activos'=>(int)$f['activos']],
            $this->r->q("SELECT c.mes,SUM(l.monto_centavos) total,COUNT(*) activos FROM erp_dep_corridas c JOIN erp_dep_lineas l ON l.corrida_id=c.id WHERE c.anio=? AND c.estado='contabilizada' GROUP BY c.mes ORDER BY c.mes",[$anio]));
    }
    /**
     * Cuadro de depreciación de un activo, mes a mes: lo contabilizado y la proyección con los mismos tramos (ficha y cambios de estimación vigentes)
     * que usará el cálculo.
     * @return array{activo:array,filas:list<array<string,mixed>>}|null
     */
    public function cuadro(int $activo):?array
    {
        $f=$this->r->uno('SELECT f.*,a.codigo,a.nombre FROM erp_dep_fichas f JOIN erp_activos a ON a.id=f.activo_id WHERE f.activo_id=?',[$activo]);if(!$f)return null;
        $baja=$this->r->uno("SELECT fecha FROM erp_dep_bajas WHERE activo_id=? AND estado='vigente'",[$activo]);
        $hechas=[];foreach($this->r->q("SELECT l.nro_cuota,l.monto_centavos,l.acumulado_centavos,l.valor_libros_centavos,c.anio,c.mes FROM erp_dep_lineas l JOIN erp_dep_corridas c ON c.id=l.corrida_id WHERE l.activo_id=? AND c.estado='contabilizada'",[$activo]) as $l)$hechas[(int)$l['anio']*100+(int)$l['mes']]=$l;
        $tramos=$this->tramos($f);$fin=0;foreach($tramos as $t)$fin=max($fin,D::numeroDeMes($tramos[0]['desde'],(int)substr($t['desde'],0,4),(int)substr($t['desde'],5,2))-1+$t['vida']);
        $filas=[];$acum=(int)$f['acumulado_inicial_centavos'];
        for($m=0;$m<$fin;$m++){
            $d=(new \DateTimeImmutable($tramos[0]['desde']))->modify('+'.$m.' months');$anio=(int)$d->format('Y');$mes=(int)$d->format('n');$p=$d->format('Y-m');$t=$this->tramoDelMes($tramos,$anio,$mes);if(!$t)continue;
            $k=D::numeroDeMes($t['desde'],$anio,$mes);$clave=$anio*100+$mes;
            if(isset($hechas[$clave])){$acum=(int)$hechas[$clave]['acumulado_centavos'];$filas[]=['nro'=>(int)$hechas[$clave]['nro_cuota'],'periodo'=>$p,'cuota'=>(int)$hechas[$clave]['monto_centavos'],'acumulado'=>$acum,'libros'=>(int)$hechas[$clave]['valor_libros_centavos'],'estado'=>'contabilizada'];continue;}
            if($k>$t['vida'])continue;
            $cuota=D::cuota($t['base'],$t['vida'],$k);$acum+=$cuota;$filas[]=['nro'=>$k,'periodo'=>$p,'cuota'=>$cuota,'acumulado'=>$acum,'libros'=>(int)$f['costo_centavos']-$acum,'estado'=>$baja?'no aplica (baja)':'proyectada'];
        }
        return ['activo'=>$f,'filas'=>array_values(array_filter($filas,fn($x)=>$x['cuota']>0))];
    }
}
