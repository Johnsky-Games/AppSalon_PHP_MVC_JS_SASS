<?php
namespace Services;

/**
 * Presupuestos v1 (migración 064). Un presupuesto = año + escenario; sus versiones pasan por borrador (o revisión) -> aprobada -> reemplazada.
 * Una versión aprobada se conserva intacta. Un presupuesto NUNCA genera ventas, obligaciones, cobros ni asientos: solo guarda cifras escritas o cargadas.
 *
 * Lo REAL sale de la vista bi_presupuesto_real_mes (una sola definición para la pantalla y para BI):
 *  · ventas = venta devengada (líneas de ventas confirmadas por mes de confirmación), netas de descuentos, menos devoluciones en el mes en que se registran;
 *  · gastos = gasto devengado del libro contable (asientos por su fecha) en cuentas de gasto o costo; las comisiones, en su partida propia;
 *  · cobros = flujo de caja (bi_presupuesto_cobros_mes): se muestran aparte, sin presupuesto ni desviación en esta versión.
 *
 * Plantilla CSV (separador «;», punto decimal): mes;tipo;referencia;nombre;base;impuesto
 *   tipo servicio|producto|gasto|comisiones. referencia = id del servicio, id del producto o código de la cuenta; vacía en comisiones. «nombre» es solo informativo.
 *   Fila con base e impuesto vacíos = sin cambio (no es cero). Para fijar un presupuesto en cero escribe 0. En gasto y comisiones el impuesto va vacío o 0.
 */
final class ErpPresupuestoService extends ErpService
{
    public const TIPOS=['servicio','producto','gasto','comisiones'];
    public const MAX_FILAS=5000;
    public const MAX_BYTES=1048576;
    public const MESES=[1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    private const EDITABLES=['borrador','revision'];
    /** Versión del cálculo de lo real que se congela en cada foto (criterio por eventos, vistas 066/067). Súbela si cambia la regla de cálculo. */
    public const VERSION_CALCULO=1;

    // ------------------------------------------------------------------------------------------------------------- escritura

    public function crear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_crear',$d,function()use($actor,$d){
            $anio=filter_var($d['anio']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2100]]);
            if($anio===false)throw new ErpException('Indica un año entre 2000 y 2100.');
            $escenario=$this->texto($d['escenario']??'',40);
            if($this->r->uno('SELECT id FROM erp_presupuestos WHERE anio=? AND escenario=? FOR UPDATE',[$anio,$escenario]))throw new ErpException('Ya existe un presupuesto '.$anio.' con el escenario «'.$escenario.'».',409);
            $ahora=$this->r::ahora();
            $this->r->q('INSERT INTO erp_presupuestos(anio,escenario,creado_por,creado_en) VALUES(?,?,?,?)',[$anio,$escenario,$actor,$ahora]);$p=$this->r->id();
            $this->r->q("INSERT INTO erp_presupuesto_versiones(presupuesto_id,numero,estado,creado_por,creado_en) VALUES(?,1,'borrador',?,?)",[$p,$actor,$ahora]);$v=$this->r->id();
            $this->evento($actor,'presupuesto_creado','presupuesto',$p,['anio'=>$anio,'escenario'=>$escenario,'version_id'=>$v]);
            return $v;
        });
    }

    /** Guarda (crea o modifica) la cifra de un mes y un concepto; con base e impuesto vacíos la quita. Mismo mes + concepto = misma línea. */
    public function linea(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_linea',$d,function()use($actor,$d){
            $v=$this->versionEditable($this->id($d['version_id']??null));
            $mes=$this->mes($d['mes']??null);
            [$tipo,$ref]=$this->concepto($d['concepto']??'');
            $vacia=trim((string)($d['base']??''))===''&&trim((string)($d['impuesto']??''))==='';
            // Quitar una cifra siempre se puede, también si su cuenta se archivó después; escribir o cambiar exige cuenta imputable y activa.
            $destino=$this->resolver($tipo,$ref,false,$vacia);
            if($vacia)$this->quitarLinea($actor,(int)$v['id'],$mes,$tipo,$destino['objetivo_id'],'manual',null);
            else{[$base,$imp]=$this->importes($tipo,$d['base']??'',$d['impuesto']??'');$this->fijarLinea($actor,(int)$v['id'],$mes,$tipo,$destino,$base,$imp,'manual',null);}
            return (int)$v['id'];
        });
    }

    /** Lee la plantilla, la valida entera y guarda la VISTA PREVIA. No cambia ninguna cifra: eso ocurre solo al confirmarla. */
    public function cargar(int $actor,array $d):array
    {
        // Antes de la idempotencia (que serializa la solicitud): un archivo que no es UTF-8 se rechaza con un aviso, no con un error interno.
        if(isset($d['csv'])&&(!is_string($d['csv'])||!mb_check_encoding($d['csv'],'UTF-8')))throw new ErpException('La plantilla debe estar en UTF-8.');
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_cargar',$d,function()use($actor,$d){
            $v=$this->versionEditable($this->id($d['version_id']??null));
            $filas=$this->leerPlantilla(is_string($d['csv']??null)?$d['csv']:'',(int)$v['id']);
            $json=json_encode($filas,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->r->q("INSERT INTO erp_presupuesto_cargas(version_id,estado,huella,n_filas,filas_json,creado_por,creado_en) VALUES(?,'previa',?,?,?,?,?)",[(int)$v['id'],hash('sha256',$json),count($filas),$json,$actor,$this->r::ahora()]);
            $id=$this->r->id();
            $this->evento($actor,'presupuesto_carga_previa','presupuesto_carga',$id,['version_id'=>(int)$v['id'],'n_filas'=>count($filas),'huella'=>hash('sha256',$json)]);
            return $id;
        });
    }

    public function confirmarCarga(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_carga_confirmar',$d,function()use($actor,$d){
            $c=$this->r->uno('SELECT * FROM erp_presupuesto_cargas WHERE id=? FOR UPDATE',[$this->id($d['carga_id']??null)])??throw new ErpException('Carga no encontrada.',404);
            if($c['estado']!=='previa')throw new ErpException('Esta carga ya se resolvió.',409);
            $v=$this->versionEditable((int)$c['version_id']);
            $filas=json_decode((string)$c['filas_json'],true,512,JSON_THROW_ON_ERROR);
            // La vista previa vale mientras las cifras sigan como estaban: si otra persona cambió o quitó alguna después, se exige una vista nueva (no se pisa su edición).
            foreach($filas as $f){
                $ex=$this->r->uno('SELECT base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=? AND mes=? AND tipo=? AND objetivo_id=? FOR UPDATE',[(int)$v['id'],(int)$f['mes'],$f['tipo'],(int)$f['objetivo_id']]);
                $igual=$f['base_antes']===null?$ex===null:($ex!==null&&(int)$ex['base_centavos']===(int)$f['base_antes']&&(int)$ex['impuesto_centavos']===(int)$f['impuesto_antes']);
                if(!$igual)throw new ErpException('Las cifras cambiaron después de la vista previa (mes '.(int)$f['mes'].', '.$f['nombre'].'). Descártala y vuelve a cargar el archivo para ver la comparación actual.',409);
            }
            $n=0;
            foreach($filas as $f){
                $destino=$this->resolver($f['tipo'],(string)$f['objetivo_id']);
                $n+=$this->fijarLinea($actor,(int)$v['id'],(int)$f['mes'],$f['tipo'],$destino,(int)$f['base'],(int)$f['impuesto'],'carga',(int)$c['id'])?1:0;
            }
            $this->r->q("UPDATE erp_presupuesto_cargas SET estado='confirmada',resuelto_por=?,resuelto_en=? WHERE id=?",[$actor,$this->r::ahora(),(int)$c['id']]);
            $this->evento($actor,'presupuesto_carga_confirmada','presupuesto_carga',(int)$c['id'],['version_id'=>(int)$v['id'],'n_filas'=>count($filas),'n_cambios'=>$n,'huella'=>$c['huella']]);
            return (int)$c['id'];
        });
    }

    public function descartarCarga(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_carga_descartar',$d,function()use($actor,$d){
            $c=$this->r->uno('SELECT * FROM erp_presupuesto_cargas WHERE id=? FOR UPDATE',[$this->id($d['carga_id']??null)])??throw new ErpException('Carga no encontrada.',404);
            if($c['estado']!=='previa')throw new ErpException('Esta carga ya se resolvió.',409);
            $this->r->q("UPDATE erp_presupuesto_cargas SET estado='descartada',resuelto_por=?,resuelto_en=? WHERE id=?",[$actor,$this->r::ahora(),(int)$c['id']]);
            $this->evento($actor,'presupuesto_carga_descartada','presupuesto_carga',(int)$c['id'],['version_id'=>(int)$c['version_id']]);
            return (int)$c['id'];
        });
    }

    /** Aprueba la versión abierta. Una versión vacía no se aprueba. La aprobada anterior (si la hay) pasa a «reemplazada» y se conserva. */
    public function aprobar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.aprobar','presupuesto_aprobar',$d,function()use($actor,$d){
            $v=$this->versionEditable($this->id($d['version_id']??null));
            if(!(int)$this->r->uno('SELECT COUNT(*) n FROM erp_presupuesto_lineas WHERE version_id=?',[(int)$v['id']])['n'])throw new ErpException('Una versión sin cifras no se aprueba: el presupuesto empieza vacío.');
            $ahora=$this->r::ahora();
            $previa=$this->r->uno("SELECT id FROM erp_presupuesto_versiones WHERE presupuesto_id=? AND estado='aprobada' FOR UPDATE",[(int)$v['presupuesto_id']]);
            if($previa)$this->r->q("UPDATE erp_presupuesto_versiones SET estado='reemplazada',reemplazado_en=? WHERE id=?",[$ahora,(int)$previa['id']]);
            $this->r->q("UPDATE erp_presupuesto_versiones SET estado='aprobada',aprobado_por=?,aprobado_en=? WHERE id=?",[$actor,$ahora,(int)$v['id']]);
            $this->evento($actor,'presupuesto_aprobado','presupuesto_version',(int)$v['id'],['presupuesto_id'=>(int)$v['presupuesto_id'],'numero'=>(int)$v['numero'],'reemplaza_version_id'=>$previa?(int)$previa['id']:null]);
            return (int)$v['id'];
        });
    }

    /** Abre una versión de revisión copiando la aprobada vigente; la aprobada no se toca. Solo una versión abierta por presupuesto. */
    public function revisar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.editar','presupuesto_revisar',$d,function()use($actor,$d){
            $p=$this->r->uno('SELECT * FROM erp_presupuestos WHERE id=? FOR UPDATE',[$this->id($d['presupuesto_id']??null)])??throw new ErpException('Presupuesto no encontrado.',404);
            $motivo=$this->texto($d['motivo']??'',200);
            $base=$this->r->uno("SELECT * FROM erp_presupuesto_versiones WHERE presupuesto_id=? AND estado='aprobada' FOR UPDATE",[(int)$p['id']])??throw new ErpException('Solo se revisa un presupuesto con una versión aprobada.');
            if($this->r->uno("SELECT id FROM erp_presupuesto_versiones WHERE presupuesto_id=? AND estado IN('borrador','revision') FOR UPDATE",[(int)$p['id']]))throw new ErpException('Ya hay una versión abierta de este presupuesto: apruébala o termínala antes de abrir otra revisión.',409);
            $numero=(int)$this->r->uno('SELECT MAX(numero) n FROM erp_presupuesto_versiones WHERE presupuesto_id=?',[(int)$p['id']])['n']+1;
            $ahora=$this->r::ahora();
            $this->r->q("INSERT INTO erp_presupuesto_versiones(presupuesto_id,numero,estado,origen_version_id,motivo,creado_por,creado_en) VALUES(?,?,'revision',?,?,?,?)",[(int)$p['id'],$numero,(int)$base['id'],$motivo,$actor,$ahora]);$nueva=$this->r->id();
            $this->r->q('INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,producto_id,cuenta_id,base_centavos,impuesto_centavos) SELECT ?,mes,tipo,servicio_id,producto_id,cuenta_id,base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=?',[$nueva,(int)$base['id']]);
            $this->r->q("INSERT INTO erp_presupuesto_cambios(version_id,mes,tipo,objetivo_id,accion,base_despues_centavos,impuesto_despues_centavos,origen,actor_id,creado_en) SELECT version_id,mes,tipo,objetivo_id,'crear',base_centavos,impuesto_centavos,'revision',?,? FROM erp_presupuesto_lineas WHERE version_id=?",[$actor,$ahora,$nueva]);
            $this->evento($actor,'presupuesto_revision_abierta','presupuesto_version',$nueva,['presupuesto_id'=>(int)$p['id'],'numero'=>$numero,'origen_version_id'=>(int)$base['id'],'motivo'=>$motivo]);
            return $nueva;
        });
    }

    // ------------------------------------------------------------------------------------------------------------- foto inmutable del comparativo (068)

    /**
     * Congela el comparativo OFICIAL (por eventos) de una versión APROBADA a una fecha de corte: componentes reales por mes y concepto, versión del cálculo, quién y cuándo.
     * Un corte ya fotografiado no se repite: se corrige con corregirFoto(). El corte no puede ser futuro (lo que aún no ocurrió no se congela) y debe caer en el año del presupuesto.
     */
    public function generarFoto(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.aprobar','presupuesto_foto',$d,function()use($actor,$d){
            $v=$this->r->uno('SELECT * FROM erp_presupuesto_versiones WHERE id=? FOR UPDATE',[$this->id($d['version_id']??null)])??throw new ErpException('Versión de presupuesto no encontrada.',404);
            if($v['estado']!=='aprobada')throw new ErpException('Solo se fotografía la versión aprobada vigente: la '.($v['estado']==='reemplazada'?'versión '.$v['numero'].' fue reemplazada.':'versión '.$v['numero'].' aún no está aprobada.'),409);
            $corte=$this->corteFoto($d['corte']??null,(int)$this->r->uno('SELECT anio FROM erp_presupuestos WHERE id=?',[(int)$v['presupuesto_id']])['anio']);
            $this->r->uno('SELECT id FROM erp_presupuestos WHERE id=? FOR UPDATE',[(int)$v['presupuesto_id']]);
            if($this->r->uno('SELECT id FROM erp_presupuesto_fotos WHERE presupuesto_id=? AND corte=? FOR UPDATE',[(int)$v['presupuesto_id'],$corte]))
                throw new ErpException('Ya existe una foto de este presupuesto al '.$corte.'. Para cambiarla genera una corrección: la original se conserva.',409);
            return $this->guardarFoto($actor,$v,$corte,1,null,null);
        });
    }

    /** Corrige una foto: genera una foto NUEVA (número siguiente, mismo corte, vinculada a la anterior y con motivo) recalculada con la versión aprobada vigente. Ambas se conservan. */
    public function corregirFoto(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'presupuestos.aprobar','presupuesto_foto_corregir',$d,function()use($actor,$d){
            $prev=$this->r->uno('SELECT * FROM erp_presupuesto_fotos WHERE id=? FOR UPDATE',[$this->id($d['foto_id']??null)])??throw new ErpException('Foto no encontrada.',404);
            $motivo=$this->texto($d['motivo']??'',200);
            $this->r->uno('SELECT id FROM erp_presupuestos WHERE id=? FOR UPDATE',[(int)$prev['presupuesto_id']]);
            if($this->r->uno('SELECT id FROM erp_presupuesto_fotos WHERE corrige_foto_id=? FOR UPDATE',[(int)$prev['id']]))
                throw new ErpException('Esta foto ya fue corregida: corrige la más reciente de ese corte.',409);
            $v=$this->r->uno("SELECT * FROM erp_presupuesto_versiones WHERE presupuesto_id=? AND estado='aprobada' FOR UPDATE",[(int)$prev['presupuesto_id']])??throw new ErpException('El presupuesto no tiene una versión aprobada vigente.',409);
            return $this->guardarFoto($actor,$v,(string)$prev['corte'],(int)$prev['numero']+1,(int)$prev['id'],$motivo);
        });
    }

    private function corteFoto(mixed $corte,int $anio):string
    {
        $c=is_string($corte)?trim($corte):'';
        $f=\DateTimeImmutable::createFromFormat('!Y-m-d',$c);
        if(!$f||$f->format('Y-m-d')!==$c)throw new ErpException('Indica la fecha de corte (AAAA-MM-DD).');
        if((int)$f->format('Y')!==$anio)throw new ErpException('El corte debe caer en el año del presupuesto ('.$anio.').');
        if($c>substr($this->r::ahora(),0,10))throw new ErpException('El corte no puede ser futuro: lo que aún no ocurrió no se congela.');
        return $c;
    }

    private function guardarFoto(int $actor,array $v,string $corte,int $numero,?int $corrige,?string $motivo):int
    {
        $cmp=$this->comparativo((int)$v['id'],'eventos',$corte);
        $sin=[];foreach($this->r->q('SELECT mes,tipo,objetivo_id,SUM(anulaciones_sin_evento) n FROM bi_presupuesto_real_eventos WHERE anio=? AND fecha_evento<=? GROUP BY mes,tipo,objetivo_id HAVING SUM(anulaciones_sin_evento)>0',[$cmp['anio'],$corte]) as $x)$sin[(int)$x['mes'].'|'.$x['tipo'].'|'.(int)$x['objetivo_id']]=(int)$x['n'];
        $lineas=[];
        foreach($cmp['filas'] as $f){
            $c=$f['componentes'];$tp=$f['tiene'];
            $lineas[]=['mes'=>$f['mes'],'tipo'=>$f['tipo'],'objetivo_id'=>(int)$f['objetivo_id'],'nombre'=>$f['nombre']===null?null:mb_substr((string)$f['nombre'],0,200),
                'pb'=>$tp?$f['presupuesto_base']:null,'pi'=>$tp?$f['presupuesto_impuesto']:null,
                'bruta'=>$c['bruta']??0,'desc'=>$c['descuentos']??0,'venta'=>$c['venta']??0,'dev'=>$c['devoluciones']??0,'anul'=>$c['anulaciones']??0,
                'rb'=>$f['real_base'],'ri'=>$f['real_impuesto'],'sin'=>$sin[$f['mes'].'|'.$f['tipo'].'|'.(int)$f['objetivo_id']]??0];
        }
        $total=array_sum(array_column($lineas,'sin'));
        $lim=['anulaciones_sin_evento'=>$total,'notas'=>array_values(array_filter([
            $total>0?$total.' anulación(es) de venta sin registro de la fecha de anulación (dato antiguo): se trataron como anuladas al confirmarse; no se inventó una fecha.':null,
            'El gasto se fecha por la fecha contable del asiento, no por la fecha en que se registró.',
            'Los cobros de caja no forman parte de la foto (no tienen presupuesto).']))];
        $huella=hash('sha256',json_encode(['presupuesto_id'=>(int)$v['presupuesto_id'],'version_id'=>(int)$v['id'],'anio'=>$cmp['anio'],'corte'=>$corte,'numero'=>$numero,'corrige'=>$corrige,'motivo'=>$motivo,
            'criterio'=>'eventos','version_calculo'=>self::VERSION_CALCULO,'lineas'=>$lineas],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        $ahora=$this->r::ahora();
        $this->r->q('INSERT INTO erp_presupuesto_fotos(presupuesto_id,version_id,anio,corte,numero,corrige_foto_id,motivo,criterio,version_calculo,n_lineas,limitaciones_json,huella,generado_por,generado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [(int)$v['presupuesto_id'],(int)$v['id'],$cmp['anio'],$corte,$numero,$corrige,$motivo,'eventos',self::VERSION_CALCULO,count($lineas),json_encode($lim,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$huella,$actor,$ahora]);
        $id=$this->r->id();
        foreach($lineas as $l)$this->r->q('INSERT INTO erp_presupuesto_foto_lineas(foto_id,mes,tipo,objetivo_id,nombre,presupuesto_base_centavos,presupuesto_impuesto_centavos,ventas_bruta_base_centavos,descuentos_base_centavos,ventas_base_centavos,devoluciones_base_centavos,anulaciones_base_centavos,real_base_centavos,real_impuesto_centavos,anulaciones_sin_evento) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$id,$l['mes'],$l['tipo'],$l['objetivo_id'],$l['nombre'],$l['pb'],$l['pi'],$l['bruta'],$l['desc'],$l['venta'],$l['dev'],$l['anul'],$l['rb'],$l['ri'],$l['sin']]);
        $this->evento($actor,$corrige===null?'presupuesto_foto_generada':'presupuesto_foto_corregida','presupuesto_foto',$id,['presupuesto_id'=>(int)$v['presupuesto_id'],'version_id'=>(int)$v['id'],'corte'=>$corte,'numero'=>$numero,'corrige_foto_id'=>$corrige,'motivo'=>$motivo,'huella'=>$huella,'n_lineas'=>count($lineas)]);
        return $id;
    }

    /** Fotos de un presupuesto, de la más reciente a la más antigua (con responsable, versión del presupuesto y si fue corregida). */
    public function fotos(int $presupuesto):array
    {
        return $this->r->q('SELECT f.id,f.version_id,v.numero version_numero,f.corte,f.numero,f.corrige_foto_id,f.motivo,f.criterio,f.version_calculo,f.n_lineas,f.huella,f.generado_en,u.nombre generado_por_nombre,
            (SELECT o.id FROM erp_presupuesto_fotos o WHERE o.corrige_foto_id=f.id) corregida_por_id
            FROM erp_presupuesto_fotos f JOIN erp_presupuesto_versiones v ON v.id=f.version_id LEFT JOIN usuarios u ON u.id=f.generado_por WHERE f.presupuesto_id=? ORDER BY f.corte DESC,f.numero DESC LIMIT 200',[$presupuesto]);
    }

    /** Una foto con sus líneas y limitaciones; `integra` dice si el contenido guardado sigue coincidiendo con su huella. */
    public function foto(int $id):?array
    {
        $f=$this->r->uno('SELECT f.*,p.escenario,v.numero version_numero,u.nombre generado_por_nombre FROM erp_presupuesto_fotos f JOIN erp_presupuestos p ON p.id=f.presupuesto_id JOIN erp_presupuesto_versiones v ON v.id=f.version_id LEFT JOIN usuarios u ON u.id=f.generado_por WHERE f.id=?',[$id]);
        if(!$f)return null;
        $f['limitaciones']=json_decode((string)$f['limitaciones_json'],true,512,JSON_THROW_ON_ERROR);
        $f['lineas']=$this->r->q("SELECT * FROM erp_presupuesto_foto_lineas WHERE foto_id=? ORDER BY FIELD(tipo,'servicio','producto','sin_clasificar','gasto','comisiones'),nombre,mes",[$id]);
        $f['corregida_por_id']=($this->r->uno('SELECT id FROM erp_presupuesto_fotos WHERE corrige_foto_id=?',[$id])['id']??null);
        $f['integra']=$this->huellaFoto($f)===$f['huella'];
        return $f;
    }
    private function huellaFoto(array $f):string
    {
        $lineas=array_map(static fn($l)=>['mes'=>(int)$l['mes'],'tipo'=>$l['tipo'],'objetivo_id'=>(int)$l['objetivo_id'],'nombre'=>$l['nombre'],
            'pb'=>$l['presupuesto_base_centavos']===null?null:(int)$l['presupuesto_base_centavos'],'pi'=>$l['presupuesto_impuesto_centavos']===null?null:(int)$l['presupuesto_impuesto_centavos'],
            'bruta'=>(int)$l['ventas_bruta_base_centavos'],'desc'=>(int)$l['descuentos_base_centavos'],'venta'=>(int)$l['ventas_base_centavos'],'dev'=>(int)$l['devoluciones_base_centavos'],'anul'=>(int)$l['anulaciones_base_centavos'],
            'rb'=>(int)$l['real_base_centavos'],'ri'=>(int)$l['real_impuesto_centavos'],'sin'=>(int)$l['anulaciones_sin_evento']],$f['lineas']);
        return hash('sha256',json_encode(['presupuesto_id'=>(int)$f['presupuesto_id'],'version_id'=>(int)$f['version_id'],'anio'=>(int)$f['anio'],'corte'=>$f['corte'],'numero'=>(int)$f['numero'],
            'corrige'=>$f['corrige_foto_id']===null?null:(int)$f['corrige_foto_id'],'motivo'=>$f['motivo'],'criterio'=>$f['criterio'],'version_calculo'=>(int)$f['version_calculo'],'lineas'=>$lineas],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }

    // ------------------------------------------------------------------------------------------------------------- reglas internas

    private function versionEditable(int $id):array
    {
        $v=$this->r->uno('SELECT * FROM erp_presupuesto_versiones WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Versión de presupuesto no encontrada.',404);
        if(!in_array($v['estado'],self::EDITABLES,true))throw new ErpException('La versión '.$v['numero'].' está '.($v['estado']==='aprobada'?'aprobada':'reemplazada').' y no se edita: abre una revisión.',409);
        return $v;
    }
    /** Centavos de un importe de presupuesto: hasta 9.999.999,99 por línea y mes (el mismo tope que el esquema), que es más que el de una venta. */
    private function monto(mixed $v,bool $cero=false):int
    {
        $n=ValoresExactos::decimal($v,2,$cero);if($n>999999999)throw new ErpException('Importe fuera de rango.');return $n;
    }
    private function mes(mixed $v):int
    {
        $m=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]);
        if($m===false)throw new ErpException('El mes debe ser un número del 1 al 12.');return $m;
    }
    /** «servicio:12», «producto:5», «gasto:33», «comisiones:0» -> [tipo, referencia]. */
    private function concepto(mixed $v):array
    {
        if(!is_string($v)||!preg_match('/^(servicio|producto|gasto|comisiones):(\d{1,10})$/D',$v,$m))throw new ErpException('Elige el concepto a presupuestar.');
        return [$m[1],$m[2]];
    }
    /** @return array{0:int,1:int} base e impuesto en centavos. Ventas exigen ambos; gasto y comisiones, solo la base. */
    private function importes(string $tipo,mixed $base,mixed $imp):array
    {
        $b=trim((string)$base);$i=trim((string)$imp);
        if($b==='')throw new ErpException('Indica la base sin impuestos (0 si el presupuesto es cero).');
        $b=$this->monto($b,true);
        if(in_array($tipo,['gasto','comisiones'],true)){
            if($i!==''&&$this->monto($i,true)!==0)throw new ErpException('Los gastos y las comisiones se presupuestan sin impuesto: deja el impuesto vacío o en 0.');
            return [$b,0];
        }
        if($i==='')throw new ErpException('Indica el impuesto (0 si no aplica).');
        return [$b,$this->monto($i,true)];
    }
    /**
     * Valida el destino y devuelve su identificador. $porCodigo: la referencia de una cuenta de gasto es su código (CSV) en vez de su id.
     * @return array{objetivo_id:int,servicio_id:?int,producto_id:?int,cuenta_id:?int,nombre:string}
     */
    private function resolver(string $tipo,string $ref,bool $porCodigo=false,bool $relajado=false):array
    {
        $vacio=['servicio_id'=>null,'producto_id'=>null,'cuenta_id'=>null];
        switch($tipo){
            case 'servicio':
                $s=$this->r->uno('SELECT id,nombre FROM servicios WHERE id=?',[$this->id($ref)])??throw new ErpException('El servicio '.$ref.' no existe.');
                return ['objetivo_id'=>(int)$s['id'],'nombre'=>$s['nombre']]+array_merge($vacio,['servicio_id'=>(int)$s['id']]);
            case 'producto':
                $p=$this->r->uno('SELECT id,nombre FROM inventario_productos WHERE id=?',[$this->id($ref)])??throw new ErpException('El producto '.$ref.' no existe.');
                if(!$relajado&&!(int)$this->r->uno('SELECT activo n FROM inventario_productos WHERE id=?',[(int)$p['id']])['n'])throw new ErpException('El producto «'.$p['nombre'].'» está archivado: no admite cifras nuevas ni cambios (solo se puede quitar la que ya tenga).');
                return ['objetivo_id'=>(int)$p['id'],'nombre'=>$p['nombre']]+array_merge($vacio,['producto_id'=>(int)$p['id']]);
            case 'gasto':
                $c=$porCodigo?$this->r->uno('SELECT id,codigo,nombre,tipo,imputable,activa FROM erp_cont_cuentas WHERE codigo=?',[$ref])
                    :$this->r->uno('SELECT id,codigo,nombre,tipo,imputable,activa FROM erp_cont_cuentas WHERE id=?',[$this->id($ref)]);
                if(!$c)throw new ErpException('La cuenta contable '.$ref.' no existe.');
                if(!in_array($c['tipo'],['gasto','costo'],true)||(!$relajado&&((int)$c['imputable']!==1||(int)$c['activa']!==1)))throw new ErpException('La cuenta '.$c['codigo'].' no es una cuenta de gasto o costo imputable y activa.');
                $rol=$this->r->uno("SELECT cuenta_id FROM erp_cont_roles WHERE rol='gasto_comisiones'");
                if($rol&&(int)$rol['cuenta_id']===(int)$c['id'])throw new ErpException('La cuenta '.$c['codigo'].' es la de comisiones: presupuéstala en la partida «Comisiones» para no contarla dos veces.');
                return ['objetivo_id'=>(int)$c['id'],'nombre'=>$c['codigo'].' '.$c['nombre']]+array_merge($vacio,['cuenta_id'=>(int)$c['id']]);
            default:
                return ['objetivo_id'=>0,'nombre'=>'Comisiones']+$vacio;
        }
    }

    /** @return bool true si cambió algo */
    private function fijarLinea(int $actor,int $version,int $mes,string $tipo,array $destino,int $base,int $imp,string $origen,?int $carga):bool
    {
        $ex=$this->r->uno('SELECT id,base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=? AND mes=? AND tipo=? AND objetivo_id=? FOR UPDATE',[$version,$mes,$tipo,$destino['objetivo_id']]);
        if($ex&&(int)$ex['base_centavos']===$base&&(int)$ex['impuesto_centavos']===$imp)return false;
        if($ex)$this->r->q('UPDATE erp_presupuesto_lineas SET base_centavos=?,impuesto_centavos=? WHERE id=?',[$base,$imp,(int)$ex['id']]);
        else $this->r->q('INSERT INTO erp_presupuesto_lineas(version_id,mes,tipo,servicio_id,producto_id,cuenta_id,base_centavos,impuesto_centavos) VALUES(?,?,?,?,?,?,?,?)',[$version,$mes,$tipo,$destino['servicio_id'],$destino['producto_id'],$destino['cuenta_id'],$base,$imp]);
        $this->r->q('INSERT INTO erp_presupuesto_cambios(version_id,mes,tipo,objetivo_id,accion,base_antes_centavos,impuesto_antes_centavos,base_despues_centavos,impuesto_despues_centavos,origen,carga_id,actor_id,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$version,$mes,$tipo,$destino['objetivo_id'],$ex?'modificar':'crear',$ex?(int)$ex['base_centavos']:null,$ex?(int)$ex['impuesto_centavos']:null,$base,$imp,$origen,$carga,$actor,$this->r::ahora()]);
        return true;
    }
    private function quitarLinea(int $actor,int $version,int $mes,string $tipo,int $objetivo,string $origen,?int $carga):void
    {
        $ex=$this->r->uno('SELECT id,base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=? AND mes=? AND tipo=? AND objetivo_id=? FOR UPDATE',[$version,$mes,$tipo,$objetivo]);
        if(!$ex)return;
        $this->r->q('DELETE FROM erp_presupuesto_lineas WHERE id=?',[(int)$ex['id']]);
        $this->r->q("INSERT INTO erp_presupuesto_cambios(version_id,mes,tipo,objetivo_id,accion,base_antes_centavos,impuesto_antes_centavos,origen,carga_id,actor_id,creado_en) VALUES(?,?,?,?,'quitar',?,?,?,?,?,?)",
            [$version,$mes,$tipo,$objetivo,(int)$ex['base_centavos'],(int)$ex['impuesto_centavos'],$origen,$carga,$actor,$this->r::ahora()]);
    }

    // ------------------------------------------------------------------------------------------------------------- plantilla CSV

    /**
     * Valida TODO el archivo y devuelve las filas que cambian cifras, con lo anterior y lo nuevo. Cualquier error rechaza el archivo completo (hasta 20 avisos con su línea).
     * @return list<array{mes:int,tipo:string,objetivo_id:int,nombre:string,base:int,impuesto:int,accion:string,base_antes:?int,impuesto_antes:?int}>
     */
    private function leerPlantilla(string $texto,int $version):array
    {
        if(strlen($texto)>self::MAX_BYTES)throw new ErpException('La plantilla excede '.(self::MAX_BYTES/1024).' KB.');
        if(!mb_check_encoding($texto,'UTF-8'))throw new ErpException('La plantilla debe estar en UTF-8.');
        $texto=preg_replace('/^\xEF\xBB\xBF/','',$texto);$errores=[];$filas=[];$vistas=[];$primera=true;$total=0;
        foreach(preg_split('/\r\n|\n|\r/',$texto) as $i=>$linea){
            if(trim($linea)==='')continue;$n=$i+1;
            $c=array_map('trim',explode(';',$linea));
            if($primera){$primera=false;if(preg_match('/^mes$/iu',$c[0]))continue;}
            if(++$total>self::MAX_FILAS)throw new ErpException('Una carga admite hasta '.self::MAX_FILAS.' filas: divídela.');
            try{
                if(count($c)!==6)throw new ErpException('se esperan 6 columnas: mes;tipo;referencia;nombre;base;impuesto.');
                [$mes,$tipo,$ref,,$base,$imp]=$c;$tipo=mb_strtolower($tipo);
                if($base===''&&$imp==='')continue;
                if(!in_array($tipo,self::TIPOS,true))throw new ErpException('tipo «'.$c[1].'» no válido (servicio, producto, gasto o comisiones).');
                $mes=$this->mes($mes);
                if($tipo==='comisiones'&&$ref!=='')throw new ErpException('la partida de comisiones no lleva referencia.');
                if($tipo!=='comisiones'&&$ref==='')throw new ErpException('indica la referencia (id o código).');
                // Primero se resuelve sin exigir cuenta activa: una fila IGUAL a la cifra vigente de una cuenta archivada es válida (ida y vuelta de la plantilla).
                $destino=$this->resolver($tipo,$tipo==='comisiones'?'0':$ref,$tipo==='gasto',true);
                [$b,$im]=$this->importes($tipo,$base,$imp);
                $clave=$mes.'|'.$tipo.'|'.$destino['objetivo_id'];
                if(isset($vistas[$clave]))throw new ErpException('repite el mes y concepto de la línea '.$vistas[$clave].'.');
                $vistas[$clave]=$n;
                $ex=$this->r->uno('SELECT base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=? AND mes=? AND tipo=? AND objetivo_id=?',[$version,$mes,$tipo,$destino['objetivo_id']]);
                if($ex&&(int)$ex['base_centavos']===$b&&(int)$ex['impuesto_centavos']===$im)continue;
                // Una cifra nueva o modificada sí exige que la cuenta siga imputable y activa.
                if($tipo==='gasto'||$tipo==='producto')$this->resolver($tipo,$ref,$tipo==='gasto');
                $filas[]=['mes'=>$mes,'tipo'=>$tipo,'objetivo_id'=>$destino['objetivo_id'],'nombre'=>$destino['nombre'],'base'=>$b,'impuesto'=>$im,'accion'=>$ex?'modificar':'crear',
                    'base_antes'=>$ex?(int)$ex['base_centavos']:null,'impuesto_antes'=>$ex?(int)$ex['impuesto_centavos']:null];
            }catch(ErpException|\InvalidArgumentException $e){
                if(count($errores)<20)$errores[]='Línea '.$n.': '.$e->getMessage();
            }
        }
        if($errores)throw new ErpException(implode(' ',$errores).' No se guardó nada: corrige el archivo.');
        if(!$filas)throw new ErpException('La plantilla no trae cifras nuevas ni distintas a las actuales (las filas sin base ni impuesto se ignoran).');
        return $filas;
    }

    /** CSV de la plantilla con todos los conceptos y meses; trae las cifras actuales de la versión y deja vacías las demás. */
    public function plantilla(int $version):string
    {
        $this->r->uno('SELECT id FROM erp_presupuesto_versiones WHERE id=?',[$version])??throw new ErpException('Versión de presupuesto no encontrada.',404);
        $rol=$this->r->uno("SELECT cuenta_id FROM erp_cont_roles WHERE rol='gasto_comisiones'");
        $destinos=[];
        foreach($this->r->q('SELECT id,nombre FROM servicios ORDER BY nombre,id') as $s)$destinos[]=['servicio',(int)$s['id'],(string)$s['id'],$s['nombre']];
        // Los conceptos archivados que la versión ya presupuesta también van: la plantilla debe traer todas las cifras actuales.
        foreach($this->r->q('SELECT id,nombre FROM inventario_productos WHERE activo=1 OR id IN(SELECT producto_id FROM erp_presupuesto_lineas WHERE version_id=? AND producto_id IS NOT NULL) ORDER BY nombre,id',[$version]) as $p)$destinos[]=['producto',(int)$p['id'],(string)$p['id'],$p['nombre']];
        foreach($this->r->q("SELECT id,codigo,nombre FROM erp_cont_cuentas WHERE tipo IN('gasto','costo') AND ((imputable=1 AND activa=1) OR id IN(SELECT cuenta_id FROM erp_presupuesto_lineas WHERE version_id=? AND cuenta_id IS NOT NULL)) ORDER BY codigo",[$version]) as $c)
            if(!$rol||(int)$rol['cuenta_id']!==(int)$c['id'])$destinos[]=['gasto',(int)$c['id'],$c['codigo'],$c['nombre']];
        $destinos[]=['comisiones',0,'','Comisiones'];
        if(count($destinos)*12>self::MAX_FILAS)throw new ErpException('Hay demasiados conceptos para una sola plantilla ('.count($destinos).' × 12 meses).');
        $actual=[];foreach($this->r->q('SELECT mes,tipo,objetivo_id,base_centavos,impuesto_centavos FROM erp_presupuesto_lineas WHERE version_id=?',[$version]) as $l)$actual[$l['mes'].'|'.$l['tipo'].'|'.$l['objetivo_id']]=$l;
        // Los nombres vienen del catálogo y la plantilla se abre en una hoja de cálculo: se neutralizan fórmulas (=, +, -, @) y se quitan separadores.
        $limpio=static fn(string $t)=>str_replace([';',"\r","\n"],' ',\Classes\Exportacion\Neutralizacion::textoCsv($t));
        $fmt=static fn(int $c)=>intdiv($c,100).'.'.str_pad((string)($c%100),2,'0',STR_PAD_LEFT);
        $out="mes;tipo;referencia;nombre;base;impuesto\n";
        for($m=1;$m<=12;$m++)foreach($destinos as [$tipo,$id,$ref,$nombre]){
            $l=$actual[$m.'|'.$tipo.'|'.$id]??null;
            $out.=$m.';'.$tipo.';'.$ref.';'.$limpio($nombre).';'.($l?$fmt((int)$l['base_centavos']):'').';'.($l&&in_array($tipo,['servicio','producto'],true)?$fmt((int)$l['impuesto_centavos']):'')."\n";
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------------------------------- lectura

    /** @return list<array<string,mixed>> presupuestos con su versión vigente y la abierta */
    public function lista():array
    {
        return $this->r->q("SELECT p.id,p.anio,p.escenario,
            (SELECT v.id FROM erp_presupuesto_versiones v WHERE v.presupuesto_id=p.id AND v.estado='aprobada') vigente_id,
            (SELECT v.numero FROM erp_presupuesto_versiones v WHERE v.presupuesto_id=p.id AND v.estado='aprobada') vigente_numero,
            (SELECT v.id FROM erp_presupuesto_versiones v WHERE v.presupuesto_id=p.id AND v.estado IN('borrador','revision')) abierta_id,
            (SELECT v.numero FROM erp_presupuesto_versiones v WHERE v.presupuesto_id=p.id AND v.estado IN('borrador','revision')) abierta_numero
            FROM erp_presupuestos p ORDER BY p.anio DESC,p.escenario LIMIT 200");
    }
    public function version(int $id):?array
    {
        $v=$this->r->uno("SELECT v.*,p.anio,p.escenario,cu.nombre creado_por_nombre,au.nombre aprobado_por_nombre FROM erp_presupuesto_versiones v JOIN erp_presupuestos p ON p.id=v.presupuesto_id
            LEFT JOIN usuarios cu ON cu.id=v.creado_por LEFT JOIN usuarios au ON au.id=v.aprobado_por WHERE v.id=?",[$id]);
        if(!$v)return null;
        $v['versiones']=$this->r->q("SELECT v.id,v.numero,v.estado,v.motivo,v.creado_en,v.aprobado_en,u.nombre aprobado_por_nombre FROM erp_presupuesto_versiones v LEFT JOIN usuarios u ON u.id=v.aprobado_por WHERE v.presupuesto_id=? ORDER BY v.numero DESC",[(int)$v['presupuesto_id']]);
        return $v;
    }
    /** Líneas de una versión con el nombre de su concepto. */
    public function lineas(int $version):array
    {
        return $this->r->q("SELECT l.id,l.mes,l.tipo,l.objetivo_id,l.base_centavos,l.impuesto_centavos,(l.base_centavos+l.impuesto_centavos) total_centavos,
            COALESCE(s.nombre,p.nombre,CONCAT(c.codigo,' ',c.nombre),'Comisiones') nombre
            FROM erp_presupuesto_lineas l LEFT JOIN servicios s ON s.id=l.servicio_id LEFT JOIN inventario_productos p ON p.id=l.producto_id LEFT JOIN erp_cont_cuentas c ON c.id=l.cuenta_id
            WHERE l.version_id=? ORDER BY FIELD(l.tipo,'servicio','producto','gasto','comisiones'),nombre,l.mes",[$version]);
    }
    public function cambios(int $version,int $limite=100):array
    {
        return $this->r->q("SELECT c.id,c.mes,c.tipo,c.objetivo_id,c.accion,c.base_antes_centavos,c.impuesto_antes_centavos,c.base_despues_centavos,c.impuesto_despues_centavos,c.origen,c.creado_en,u.nombre actor
            FROM erp_presupuesto_cambios c LEFT JOIN usuarios u ON u.id=c.actor_id WHERE c.version_id=? ORDER BY c.id DESC LIMIT ".(int)$limite,[$version]);
    }
    public function carga(int $id):?array
    {
        $c=$this->r->uno('SELECT * FROM erp_presupuesto_cargas WHERE id=?',[$id]);
        if($c)$c['filas']=json_decode((string)$c['filas_json'],true,512,JSON_THROW_ON_ERROR);
        return $c;
    }
    /** Conceptos que se pueden presupuestar, para el selector manual. */
    public function conceptos():array
    {
        $rol=$this->r->uno("SELECT cuenta_id FROM erp_cont_roles WHERE rol='gasto_comisiones'");
        return [
            'servicio'=>$this->r->q('SELECT id,nombre FROM servicios ORDER BY nombre LIMIT 500'),
            'producto'=>$this->r->q('SELECT id,nombre FROM inventario_productos WHERE activo=1 ORDER BY nombre LIMIT 1000'),
            'gasto'=>array_values(array_filter($this->r->q("SELECT id,CONCAT(codigo,' ',nombre) nombre FROM erp_cont_cuentas WHERE tipo IN('gasto','costo') AND imputable=1 AND activa=1 ORDER BY codigo LIMIT 1000"),fn($c)=>!$rol||(int)$rol['cuenta_id']!==(int)$c['id'])),
        ];
    }

    /**
     * Desviación de lo real frente al presupuesto. Presupuesto ausente (null) = «sin_presupuesto»: sin desviación. Presupuesto 0 = «presupuesto_cero»: desviación en
     * dinero (= real) y SIN porcentaje. El porcentaje va en centésimas de punto (1234 = 12,34 %), redondeado a mitad hacia arriba, igual que ROUND de las vistas.
     * @return array{estado:string,desviacion:?int,pct_centesimas:?int}
     */
    public static function desviacion(int $real,?int $presupuesto):array
    {
        if($presupuesto===null)return ['estado'=>'sin_presupuesto','desviacion'=>null,'pct_centesimas'=>null];
        $dif=$real-$presupuesto;
        if($presupuesto===0)return ['estado'=>'presupuesto_cero','desviacion'=>$dif,'pct_centesimas'=>null];
        $pct=intdiv(abs($dif)*20000+$presupuesto,2*$presupuesto);
        return ['estado'=>'con_presupuesto','desviacion'=>$dif,'pct_centesimas'=>$dif<0?-$pct:$pct];
    }

    /**
     * Comparativo de UNA versión (aprobada, reemplazada o abierta) contra lo real de su año: una fila por mes y concepto con presupuesto o con real.
     * Los conceptos se agrupan en ventas (servicio, producto, sin_clasificar), gastos (cuentas) y comisiones (partida propia). El total de gastos suma la
     * partida de comisiones UNA sola vez: gastos_totales = gastos de cuentas + comisiones.
     *
     * DOS CRITERIOS para lo real (se informan en el resultado y no son intercambiables):
     *  · `estado_actual` (por defecto): recalculado hoy con el estado vigente de cada venta (bi_presupuesto_real_mes). Anular una venta reescribe los meses pasados.
     *  · `eventos`: cada hecho cuenta el día en que ocurrió (bi_presupuesto_real_eventos): la venta el día que se confirmó, cada devolución el día en que se registró y la anulación
     *    el día en que se anuló, restando el neto que quedaba entonces. Con `$corte` (Y-m-d, inclusive) solo cuentan los eventos hasta esa fecha, es decir, lo que se sabía a ella.
     *    Sin corte cuentan todos los eventos del año. El corte solo aplica a este criterio.
     * @return array{filas:list<array>,anual:list<array>,resumen:array,cobros:list<array>,anio:int,criterio:string,corte:?string}
     */
    public function comparativo(int $version,string $criterio='estado_actual',?string $corte=null):array
    {
        if(!in_array($criterio,['estado_actual','eventos'],true))throw new ErpException('Criterio de comparación no válido.',422);
        if($corte!==null){
            if($criterio!=='eventos')throw new ErpException('La fecha de corte solo aplica al criterio por eventos.',422);
            $f=\DateTimeImmutable::createFromFormat('!Y-m-d',$corte);
            if(!$f||$f->format('Y-m-d')!==$corte)throw new ErpException('La fecha de corte no es válida (AAAA-MM-DD).',422);
        }
        $v=$this->r->uno('SELECT v.id,p.anio FROM erp_presupuesto_versiones v JOIN erp_presupuestos p ON p.id=v.presupuesto_id WHERE v.id=?',[$version])??throw new ErpException('Versión de presupuesto no encontrada.',404);
        $anio=(int)$v['anio'];
        // Fuente de lo real (misma forma en los dos criterios): tabla derivada por mes y destino, ya filtrada por año (y por corte en el criterio por eventos).
        if($criterio==='eventos'){
            $real='(SELECT anio,mes,tipo,objetivo_id,SUM(ventas_base_centavos) ventas_base_centavos,SUM(descuentos_informativo_centavos) descuentos_informativo_centavos,SUM(ventas_bruta_base_centavos) ventas_bruta_base_centavos,'
                .'SUM(devoluciones_base_centavos) devoluciones_base_centavos,SUM(anulaciones_base_centavos) anulaciones_base_centavos,SUM(real_base_centavos) real_base_centavos,SUM(real_impuesto_centavos) real_impuesto_centavos,SUM(real_total_centavos) real_total_centavos '
                .'FROM bi_presupuesto_real_eventos WHERE anio=?'.($corte!==null?' AND fecha_evento<=?':'').' GROUP BY anio,mes,tipo,objetivo_id)';
            $pr=$corte!==null?[$anio,$corte]:[$anio];
        }else{
            $real='(SELECT mes,tipo,objetivo_id,ventas_base_centavos,descuentos_informativo_centavos,ventas_bruta_base_centavos,devoluciones_base_centavos,0 AS anulaciones_base_centavos,real_base_centavos,real_impuesto_centavos,real_total_centavos FROM bi_presupuesto_real_mes WHERE anio=?)';
            $pr=[$anio];
        }
        $filas=$this->r->q("SELECT k.mes,k.tipo,k.objetivo_id,
            COALESCE(s.nombre,p.nombre,CONCAT(c.codigo,' ',c.nombre),IF(k.tipo='comisiones','Comisiones',IF(k.tipo='sin_clasificar','Ventas sin servicio ni producto',NULL))) nombre,
            l.id IS NOT NULL tiene,l.base_centavos pb,l.impuesto_centavos pi,
            COALESCE(r.real_base_centavos,0) rb,COALESCE(r.real_impuesto_centavos,0) ri,COALESCE(r.real_total_centavos,0) rt,
            COALESCE(r.ventas_base_centavos,0) vb,COALESCE(r.devoluciones_base_centavos,0) db,COALESCE(r.descuentos_informativo_centavos,0) ds,COALESCE(r.ventas_bruta_base_centavos,0) vbr,COALESCE(r.anulaciones_base_centavos,0) an
            FROM (SELECT mes,tipo,objetivo_id FROM erp_presupuesto_lineas WHERE version_id=? UNION SELECT mes,tipo,objetivo_id FROM $real rr) k
            LEFT JOIN erp_presupuesto_lineas l ON l.version_id=? AND l.mes=k.mes AND l.tipo=k.tipo AND l.objetivo_id=k.objetivo_id
            LEFT JOIN $real r ON r.mes=k.mes AND r.tipo=k.tipo AND r.objetivo_id=k.objetivo_id
            LEFT JOIN servicios s ON k.tipo='servicio' AND s.id=k.objetivo_id LEFT JOIN inventario_productos p ON k.tipo='producto' AND p.id=k.objetivo_id
            LEFT JOIN erp_cont_cuentas c ON k.tipo='gasto' AND c.id=k.objetivo_id
            ORDER BY FIELD(k.tipo,'servicio','producto','sin_clasificar','gasto','comisiones'),nombre,k.mes",array_merge([$version],$pr,[$version],$pr));
        // Por grupo: presupuesto (solo líneas presupuestadas), real de TODO el año (informativo) y real de los meses/conceptos que sí tienen presupuesto (comparable).
        $g=static fn()=>['pb'=>0,'pi'=>0,'rb'=>0,'rt'=>0,'rb_comparable'=>0,'rt_comparable'=>0,'hay'=>false];
        $res=['ventas'=>$g(),'gastos'=>$g(),'comisiones'=>$g()];
        $res['ventas']['componentes']=['bruta'=>0,'descuentos'=>0,'venta'=>0,'devoluciones'=>0,'anulaciones'=>0,'neta'=>0];
        foreach($filas as &$f){
            $f['mes']=(int)$f['mes'];$f['tiene']=(bool)$f['tiene'];
            $pb=$f['tiene']?(int)$f['pb']:null;$pi=$f['tiene']?(int)$f['pi']:null;
            $f['presupuesto_base']=$pb;$f['presupuesto_impuesto']=$pi;$f['presupuesto_total']=$f['tiene']?$pb+$pi:null;
            $f['real_base']=(int)$f['rb'];$f['real_impuesto']=(int)$f['ri'];$f['real_total']=(int)$f['rt'];
            $f['dev_base']=self::desviacion($f['real_base'],$pb);$f['dev_total']=self::desviacion($f['real_total'],$f['presupuesto_total']);
            $f['sentido']=in_array($f['tipo'],['gasto','comisiones'],true)?'gasto':'ingreso';
            // Componentes explicativos de la base de venta: bruta − descuentos = venta; venta − devoluciones = neta (lo que se compara). No aplican a gastos ni comisiones.
            $f['componentes']=$f['sentido']==='ingreso'?['bruta'=>(int)$f['vbr'],'descuentos'=>(int)$f['ds'],'venta'=>(int)$f['vb'],'devoluciones'=>(int)$f['db'],'anulaciones'=>(int)$f['an'],'neta'=>$f['real_base']]:null;
            $k=$f['tipo']==='comisiones'?'comisiones':($f['tipo']==='gasto'?'gastos':'ventas');
            $res[$k]['rb']+=$f['real_base'];$res[$k]['rt']+=$f['real_total'];
            if($f['componentes']!==null)foreach($f['componentes'] as $q=>$x)$res['ventas']['componentes'][$q]+=$x;
            if($f['tiene']){
                $res[$k]['hay']=true;$res[$k]['pb']+=$pb;$res[$k]['pi']+=$pi;$res[$k]['rb_comparable']+=$f['real_base'];$res[$k]['rt_comparable']+=$f['real_total'];
            }
        }
        unset($f);
        // Consolidado anual por concepto: presupuesto de los meses presupuestados; la desviación compara solo esos meses.
        $anual=[];
        foreach($filas as $f){
            $k=$f['tipo'].'|'.$f['objetivo_id'];
            $anual[$k]??=['tipo'=>$f['tipo'],'objetivo_id'=>$f['objetivo_id'],'nombre'=>$f['nombre'],'sentido'=>$f['sentido'],'meses'=>0,'pb'=>0,'pi'=>0,'pt'=>0,'rb'=>0,'rt'=>0,'rb_comparable'=>0,'rt_comparable'=>0];
            $anual[$k]['rb']+=$f['real_base'];$anual[$k]['rt']+=$f['real_total'];
            if($f['tiene']){$anual[$k]['meses']++;$anual[$k]['pb']+=$f['presupuesto_base'];$anual[$k]['pi']+=$f['presupuesto_impuesto'];$anual[$k]['pt']+=$f['presupuesto_total'];$anual[$k]['rb_comparable']+=$f['real_base'];$anual[$k]['rt_comparable']+=$f['real_total'];}
        }
        foreach($anual as &$x){$x['dev_base']=self::desviacion($x['rb_comparable'],$x['meses']?$x['pb']:null);$x['dev_total']=self::desviacion($x['rt_comparable'],$x['meses']?$x['pt']:null);}
        unset($x);
        foreach($res as &$r){
            $r['pt']=$r['pb']+$r['pi'];
            $r['dev_base']=self::desviacion($r['rb_comparable'],$r['hay']?$r['pb']:null);
            $r['dev_total']=self::desviacion($r['rt_comparable'],$r['hay']?$r['pt']:null);
        }
        unset($r);
        // El total de gastos suma la partida de comisiones UNA sola vez: cuentas de gasto + comisiones.
        $res['gastos_totales']=['pb'=>$res['gastos']['pb']+$res['comisiones']['pb'],'rb'=>$res['gastos']['rb']+$res['comisiones']['rb'],
            'hay'=>$res['gastos']['hay']||$res['comisiones']['hay'],'rb_comparable'=>$res['gastos']['rb_comparable']+$res['comisiones']['rb_comparable']];
        $res['gastos_totales']['dev']=self::desviacion($res['gastos_totales']['rb_comparable'],$res['gastos_totales']['hay']?$res['gastos_totales']['pb']:null);
        $cobros=$this->r->q('SELECT mes,cobros_centavos,devoluciones_de_cobro_centavos,cobros_netos_centavos FROM bi_presupuesto_cobros_mes WHERE anio=? ORDER BY mes',[$anio]);
        return ['filas'=>$filas,'anual'=>array_values($anual),'resumen'=>$res,'cobros'=>$cobros,'anio'=>$anio,'criterio'=>$criterio,'corte'=>$corte];
    }
}
