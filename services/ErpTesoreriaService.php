<?php
namespace Services;

/**
 * Tesorería bancaria (migración 051): cuentas bancarias ligadas a una cuenta contable de activo, extractos importados (solo de anexar)
 * y conciliación línea de extracto <-> línea del libro. Nunca crea ni modifica asientos: una partida del extracto sin registro en el libro
 * se contabiliza con un asiento manual y después se concilia. Una diferencia no se ajusta sola.
 *
 * Extracto en texto (CSV con «;»): fecha (AAAA-MM-DD);descripción;referencia (opcional);monto con signo (+ entra al banco, − sale; punto decimal).
 */
final class ErpTesoreriaService extends ErpService
{
    /** Diferencia máxima de días entre el extracto y el asiento para sugerir o conciliar sin nota. Criterio operativo, no una regla legal. */
    public const VENTANA_DIAS=5;
    public const MAX_LINEAS=2000;
    public const MAX_BYTES=524288;

    /** Centavos de «-1234.56» (hasta 2 decimales) o null si no es un importe válido. */
    public static function centavos(string $v):?int
    {
        if(!preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,2}))?$/D',trim($v),$m))return null;
        $c=(int)$m[2]*100+(int)str_pad($m[3]??'',2,'0');return $m[1]==='-'?-$c:$c;
    }

    /**
     * @return list<array{fecha:string,descripcion:string,referencia:?string,monto:int}>
     * @throws ErpException con el número de línea que falla
     */
    public static function leerExtracto(string $texto):array
    {
        if(strlen($texto)>self::MAX_BYTES)throw new ErpException('El extracto excede '.(self::MAX_BYTES/1024).' KB.');
        if(!mb_check_encoding($texto,'UTF-8'))throw new ErpException('El extracto debe estar en UTF-8.');
        $texto=preg_replace('/^\xEF\xBB\xBF/','',$texto);$out=[];$vista=false;
        foreach(preg_split('/\r\n|\n|\r/',$texto) as $i=>$linea){
            if(trim($linea)==='')continue;$n=$i+1;
            $c=array_map('trim',explode(';',$linea));
            // Encabezado opcional: solo la primera línea no vacía y solo si su primera columna empieza por «fecha» o «date». Cualquier otra cosa se valida como movimiento y falla con su número de línea.
            $primera=!$vista;$vista=true;
            if($primera&&preg_match('/^(fecha|date)\b/iu',$c[0]))continue;
            if(count($c)!==4&&count($c)!==3)throw new ErpException('Línea '.$n.': se esperan fecha;descripción;referencia;monto.');
            [$f,$desc,$ref,$monto]=count($c)===4?$c:[$c[0],$c[1],'',$c[2]];
            $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$f);if(!$d||$d->format('Y-m-d')!==$f)throw new ErpException('Línea '.$n.': fecha no válida (AAAA-MM-DD).');
            $m=self::centavos($monto);if($m===null||$m===0)throw new ErpException('Línea '.$n.': monto no válido (decimal con punto, distinto de cero).');
            if(mb_strlen($desc)<1||mb_strlen($desc)>160||preg_match('/[\x00-\x1f\x7f]/',$desc))throw new ErpException('Línea '.$n.': descripción de 1 a 160 caracteres sin control.');
            if(mb_strlen($ref)>60||preg_match('/[\x00-\x1f\x7f]/',$ref))throw new ErpException('Línea '.$n.': referencia de hasta 60 caracteres.');
            if(count($out)>=self::MAX_LINEAS)throw new ErpException('Un extracto admite hasta '.self::MAX_LINEAS.' líneas: divídelo por períodos.');
            $out[]=['fecha'=>$f,'descripcion'=>$desc,'referencia'=>$ref===''?null:$ref,'monto'=>$m];
        }
        if(!$out)throw new ErpException('El extracto no tiene líneas.');
        return $out;
    }

    public function cuentaCrear(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.configurar','tes_cuenta',$d,function()use($actor,$d){
            $codigo=is_string($d['cuenta']??null)?trim($d['cuenta']):'';
            $c=$this->r->uno('SELECT id,tipo,imputable,activa FROM erp_cont_cuentas WHERE codigo=? FOR UPDATE',[$codigo])??throw new ErpException('La cuenta contable no existe.',404);
            if($c['tipo']!=='activo'||(int)$c['imputable']!==1||(int)$c['activa']!==1)throw new ErpException('La cuenta bancaria se liga a una cuenta contable de activo, imputable y activa.');
            if($this->r->uno('SELECT id FROM erp_tes_cuentas WHERE cuenta_cont_id=? FOR UPDATE',[(int)$c['id']]))throw new ErpException('Esa cuenta contable ya está ligada a una cuenta bancaria.',409);
            $nombre=$this->texto($d['nombre']??'',80);$entidad=$this->texto($d['entidad']??'',80);
            $dig=isset($d['ultimos_digitos'])&&$d['ultimos_digitos']!==''?(string)$d['ultimos_digitos']:null;
            if($dig!==null&&!preg_match('/^\d{2,6}$/D',$dig))throw new ErpException('Indica solo los últimos 2 a 6 dígitos del número de cuenta (el número completo no se guarda).');
            $this->r->q('INSERT INTO erp_tes_cuentas(cuenta_cont_id,nombre,entidad,ultimos_digitos,creado_por,creado_en) VALUES(?,?,?,?,?,?)',[(int)$c['id'],$nombre,$entidad,$dig,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'tes_cuenta_creada','tes_cuenta',$id,['cuenta_cont_id'=>(int)$c['id'],'nombre'=>$nombre,'entidad'=>$entidad]);return $id;
        });
    }

    public function importar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','tes_extracto',$d,function()use($actor,$d){
            $cuenta=$this->r->uno('SELECT * FROM erp_tes_cuentas WHERE id=? AND activa=1 FOR UPDATE',[$this->id($d['tes_cuenta_id']??null)])??throw new ErpException('Cuenta bancaria no encontrada.',404);
            $desde=$this->fecha($d['desde']??null);$hasta=$this->fecha($d['hasta']??null);if($hasta<$desde)throw new ErpException('El período del extracto es inválido.');
            $ini=self::centavos((string)($d['saldo_inicial']??''));$fin=self::centavos((string)($d['saldo_final']??''));
            if($ini===null||$fin===null||abs($ini)>99999999999||abs($fin)>99999999999)throw new ErpException('Indica el saldo inicial y final del extracto (decimal con punto).');
            $lineas=self::leerExtracto(is_string($d['extracto']??null)?$d['extracto']:'');
            $suma=0;foreach($lineas as $i=>$l){
                if($l['fecha']<$desde||$l['fecha']>$hasta)throw new ErpException('Línea '.($i+1).' del extracto: la fecha queda fuera del período '.$desde.' a '.$hasta.'.');
                $suma+=$l['monto'];
            }
            if($ini+$suma!==$fin)throw new ErpException('El extracto no cuadra: saldo inicial '.number_format($ini/100,2,'.','').' + movimientos '.number_format($suma/100,2,'.','').' no es el saldo final '.number_format($fin/100,2,'.','').'. Corrige el archivo o los saldos.',422);
            $huella=hash('sha256',json_encode([(int)$cuenta['id'],$desde,$hasta,$ini,$fin,$lineas],JSON_THROW_ON_ERROR));
            $previo=$this->r->uno('SELECT id FROM erp_tes_extractos WHERE tes_cuenta_id=? AND huella=?',[(int)$cuenta['id'],$huella]);if($previo)return (int)$previo['id'];
            $ult=$this->r->uno('SELECT hasta,saldo_final_centavos FROM erp_tes_extractos WHERE tes_cuenta_id=? ORDER BY hasta DESC,id DESC LIMIT 1 FOR UPDATE',[(int)$cuenta['id']]);
            if($ult){
                if($desde<=$ult['hasta'])throw new ErpException('El extracto debe empezar después del último importado (termina el '.$ult['hasta'].').',409);
                if($ini!==(int)$ult['saldo_final_centavos'])throw new ErpException('El saldo inicial ('.number_format($ini/100,2,'.','').') no coincide con el saldo final del extracto anterior ('.number_format($ult['saldo_final_centavos']/100,2,'.','').'): falta un extracto o un dato está mal.',409);
            }
            $this->r->q('INSERT INTO erp_tes_extractos(tes_cuenta_id,desde,hasta,saldo_inicial_centavos,saldo_final_centavos,n_lineas,huella,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?)',[(int)$cuenta['id'],$desde,$hasta,$ini,$fin,count($lineas),$huella,$actor,$this->r::ahora()]);$id=$this->r->id();
            foreach($lineas as $i=>$l)$this->r->q('INSERT INTO erp_tes_extracto_lineas(extracto_id,nro,fecha,descripcion,referencia,monto_centavos) VALUES(?,?,?,?,?,?)',[$id,$i+1,$l['fecha'],$l['descripcion'],$l['referencia'],$l['monto']]);
            $this->evento($actor,'tes_extracto_importado','tes_extracto',$id,['tes_cuenta_id'=>(int)$cuenta['id'],'desde'=>$desde,'hasta'=>$hasta,'n_lineas'=>count($lineas),'saldo_final'=>$fin]);return $id;
        });
    }

    /** Líneas del libro de la cuenta contable del banco, aún sin vínculo vigente, con el signo del banco (debe = entra). $montos limita a esos importes. */
    private function libroSinConciliar(int $cuentaContId,?string $desde,string $hasta,bool $bloquear=false,?array $montos=null):array
    {
        $sql='SELECT l.id,a.numero,a.fecha,a.concepto,(l.debe_centavos-l.haber_centavos) monto FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id
            WHERE l.cuenta_id=? AND a.fecha<=?'.($desde!==null?' AND a.fecha>=?':'').' AND NOT EXISTS(SELECT 1 FROM erp_tes_conciliaciones c WHERE c.cont_linea_id=l.id AND c.estado=\'vigente\')';
        $p=[$cuentaContId,$hasta];if($desde!==null)$p[]=$desde;
        if($montos!==null){if(!$montos)return [];$sql.=' AND (l.debe_centavos-l.haber_centavos) IN('.implode(',',array_fill(0,count($montos),'?')).')';array_push($p,...$montos);}
        return $this->r->q($sql.' ORDER BY a.fecha,l.id'.($bloquear?' FOR UPDATE':''),$p);
    }
    private function extractoSinConciliar(int $extractoId):array
    {
        return $this->r->q('SELECT x.id,x.nro,x.fecha,x.descripcion,x.referencia,x.monto_centavos monto FROM erp_tes_extracto_lineas x WHERE x.extracto_id=? AND NOT EXISTS(SELECT 1 FROM erp_tes_conciliaciones c WHERE c.extracto_linea_id=x.id AND c.estado=\'vigente\') ORDER BY x.nro',[$extractoId]);
    }
    private function enVentana(string $a,string $b):bool {return abs((new \DateTimeImmutable($a))->diff(new \DateTimeImmutable($b))->days)<=self::VENTANA_DIAS;}

    /**
     * Por cada movimiento sin conciliar: `candidatos` (mismo importe y sentido, dentro de la ventana de fechas; los únicos que usa la conciliación automática) y
     * `lejanos` (mismo importe y sentido pero fuera de la ventana, hasta 10 los más cercanos; solo se concilian a mano y con nota, p. ej. una partida en tránsito).
     * @return array{pares:list<array{linea:array,candidatos:list<array>,lejanos:list<array>}>}
     */
    public function sugerencias(int $extractoId,bool $bloquear=false):array
    {
        $e=$this->r->uno('SELECT e.*,c.cuenta_cont_id FROM erp_tes_extractos e JOIN erp_tes_cuentas c ON c.id=e.tes_cuenta_id WHERE e.id=?',[$extractoId])??throw new ErpException('Extracto no encontrado.',404);
        $fin=(new \DateTimeImmutable($e['hasta']))->modify('+'.self::VENTANA_DIAS.' days')->format('Y-m-d');
        $sin=$this->extractoSinConciliar($extractoId);
        $libro=$this->libroSinConciliar((int)$e['cuenta_cont_id'],null,$fin,$bloquear,array_values(array_unique(array_map(fn($l)=>(int)$l['monto'],$sin))));$pares=[];
        foreach($sin as $l){
            $mismo=array_values(array_filter($libro,fn($x)=>(int)$x['monto']===(int)$l['monto']));
            $dentro=array_values(array_filter($mismo,fn($x)=>$this->enVentana($x['fecha'],$l['fecha'])));
            $lejos=array_values(array_filter($mismo,fn($x)=>!$this->enVentana($x['fecha'],$l['fecha'])));
            usort($lejos,fn($a,$b)=>abs(strtotime($a['fecha'])-strtotime($l['fecha']))<=>abs(strtotime($b['fecha'])-strtotime($l['fecha'])));
            $pares[]=['linea'=>$l,'candidatos'=>$dentro,'lejanos'=>array_slice($lejos,0,10)];
        }
        return ['pares'=>$pares];
    }

    /** Vincula solo lo inequívoco: una línea de extracto con un único candidato que, a su vez, no es candidato de ninguna otra línea. Lo ambiguo queda para la persona. */
    public function conciliarAutomatico(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','tes_conciliar_auto',$d,function()use($actor,$d){
            $id=$this->id($d['extracto_id']??null);$this->r->uno('SELECT id FROM erp_tes_extractos WHERE id=? FOR UPDATE',[$id])??throw new ErpException('Extracto no encontrado.',404);
            $pares=$this->sugerencias($id,true)['pares'];$uso=[];
            foreach($pares as $p)foreach($p['candidatos'] as $c)$uso[$c['id']]=($uso[$c['id']]??0)+1;
            $n=0;
            foreach($pares as $p){
                if(count($p['candidatos'])!==1)continue;$c=$p['candidatos'][0];if(($uso[$c['id']]??0)!==1)continue;
                $this->r->q("INSERT INTO erp_tes_conciliaciones(extracto_linea_id,cont_linea_id,metodo,creado_por,creado_en) VALUES(?,?,'automatica',?,?)",[(int)$p['linea']['id'],(int)$c['id'],$actor,$this->r::ahora()]);$n++;
            }
            $this->evento($actor,'tes_conciliacion_automatica','tes_extracto',$id,['vinculadas'=>$n,'pendientes'=>count($pares)-$n]);return $id;
        });
    }

    public function conciliar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','tes_conciliar',$d,function()use($actor,$d){
            $x=$this->r->uno('SELECT x.id,x.fecha,x.monto_centavos,e.tes_cuenta_id FROM erp_tes_extracto_lineas x JOIN erp_tes_extractos e ON e.id=x.extracto_id WHERE x.id=? FOR UPDATE',[$this->id($d['extracto_linea_id']??null)])??throw new ErpException('Línea de extracto no encontrada.',404);
            $cuenta=$this->r->uno('SELECT cuenta_cont_id FROM erp_tes_cuentas WHERE id=?',[(int)$x['tes_cuenta_id']]);
            $l=$this->r->uno('SELECT l.id,l.cuenta_id,(l.debe_centavos-l.haber_centavos) monto,a.fecha FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.id=? FOR UPDATE',[$this->id($d['cont_linea_id']??null)])??throw new ErpException('Línea del libro no encontrada.',404);
            if((int)$l['cuenta_id']!==(int)$cuenta['cuenta_cont_id'])throw new ErpException('Esa línea del libro no pertenece a la cuenta contable de este banco.');
            if((int)$l['monto']!==(int)$x['monto_centavos'])throw new ErpException('El importe y el sentido deben coincidir exactamente (extracto '.number_format($x['monto_centavos']/100,2,'.','').' frente al libro '.number_format($l['monto']/100,2,'.','').'). Una diferencia se explica con un asiento, no se fuerza.',409);
            foreach([['extracto_linea_id',(int)$x['id'],'La línea del extracto ya está conciliada.'],['cont_linea_id',(int)$l['id'],'La línea del libro ya está conciliada con otra partida.']] as [$col,$v,$msg])
                if($this->r->uno("SELECT id FROM erp_tes_conciliaciones WHERE $col=? AND estado='vigente' FOR UPDATE",[$v]))throw new ErpException($msg,409);
            $nota=isset($d['nota'])&&$d['nota']!==''?$this->texto($d['nota'],160):null;
            if(!$this->enVentana($l['fecha'],$x['fecha'])&&$nota===null)throw new ErpException('Las fechas difieren más de '.self::VENTANA_DIAS.' días (extracto '.$x['fecha'].', libro '.$l['fecha'].'): indica en la nota por qué se concilian.');
            $this->r->q("INSERT INTO erp_tes_conciliaciones(extracto_linea_id,cont_linea_id,metodo,nota,creado_por,creado_en) VALUES(?,?,'manual',?,?,?)",[(int)$x['id'],(int)$l['id'],$nota,$actor,$this->r::ahora()]);$id=$this->r->id();
            $this->evento($actor,'tes_conciliacion_manual','tes_conciliacion',$id,['extracto_linea_id'=>(int)$x['id'],'cont_linea_id'=>(int)$l['id']]);return $id;
        });
    }

    public function deshacer(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'contabilidad.registrar','tes_desconciliar',$d,function()use($actor,$d){
            $c=$this->r->uno("SELECT id FROM erp_tes_conciliaciones WHERE id=? AND estado='vigente' FOR UPDATE",[$this->id($d['conciliacion_id']??null)])??throw new ErpException('Conciliación vigente no encontrada.',404);
            $motivo=$this->texto($d['motivo']??'',160);if(mb_strlen($motivo)<5)throw new ErpException('Explica el motivo (al menos 5 caracteres).');
            $this->r->q("UPDATE erp_tes_conciliaciones SET estado='deshecha',deshecho_por=?,deshecho_en=?,motivo_deshecho=? WHERE id=?",[$actor,$this->r::ahora(),$motivo,(int)$c['id']]);
            $this->evento($actor,'tes_conciliacion_deshecha','tes_conciliacion',(int)$c['id'],['motivo'=>$motivo]);return (int)$c['id'];
        });
    }

    /** @return list<array<string,mixed>> cuentas bancarias con su último extracto y lo pendiente */
    public function cuentas():array
    {
        $out=[];
        foreach($this->r->q('SELECT t.*,c.codigo,c.nombre cuenta_nombre FROM erp_tes_cuentas t JOIN erp_cont_cuentas c ON c.id=t.cuenta_cont_id ORDER BY t.id') as $t){
            $t['extractos']=$this->r->q('SELECT e.id,e.desde,e.hasta,e.n_lineas,e.saldo_inicial_centavos,e.saldo_final_centavos,
                (SELECT COUNT(*) FROM erp_tes_extracto_lineas x WHERE x.extracto_id=e.id AND NOT EXISTS(SELECT 1 FROM erp_tes_conciliaciones k WHERE k.extracto_linea_id=x.id AND k.estado=\'vigente\')) pendientes
                FROM erp_tes_extractos e WHERE e.tes_cuenta_id=? ORDER BY e.hasta DESC,e.id DESC LIMIT 500',[(int)$t['id']]);
            // Se listan los 24 más recientes y, además, todo extracto con movimientos por conciliar: ninguno queda inalcanzable.
            $t['extractos']=array_values(array_filter($t['extractos'],fn($e,$i)=>$i<24||(int)$e['pendientes']>0,ARRAY_FILTER_USE_BOTH));
            $out[]=$t;
        }
        return $out;
    }

    /**
     * Conciliación de la cuenta al cierre de su último extracto. La identidad  saldo banco − saldo libro = (apertura del banco − libro previo) + extracto sin registrar − libro sin extracto
     * se calcula y se comprueba; si no se cumple (p. ej. un vínculo con fecha fuera del rango) se informa como incoherencia en lugar de ocultarla.
     * @return array<string,mixed>
     */
    public function informe(int $tesCuentaId):array
    {
        $t=$this->r->uno('SELECT t.*,c.codigo,c.nombre cuenta_nombre FROM erp_tes_cuentas t JOIN erp_cont_cuentas c ON c.id=t.cuenta_cont_id WHERE t.id=?',[$tesCuentaId])??throw new ErpException('Cuenta bancaria no encontrada.',404);
        $primero=$this->r->uno('SELECT desde,saldo_inicial_centavos FROM erp_tes_extractos WHERE tes_cuenta_id=? ORDER BY desde,id LIMIT 1',[$tesCuentaId]);
        $ultimo=$this->r->uno('SELECT hasta,saldo_final_centavos FROM erp_tes_extractos WHERE tes_cuenta_id=? ORDER BY hasta DESC,id DESC LIMIT 1',[$tesCuentaId]);
        if(!$primero||!$ultimo)return ['cuenta'=>$t,'sin_extractos'=>true];
        $cc=(int)$t['cuenta_cont_id'];
        $saldoLibro=(int)$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos-l.haber_centavos),0) s FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<=?',[$cc,$ultimo['hasta']])['s'];
        $libroPrevio=(int)$this->r->uno('SELECT COALESCE(SUM(l.debe_centavos-l.haber_centavos),0) s FROM erp_cont_lineas l JOIN erp_cont_asientos a ON a.id=l.asiento_id WHERE l.cuenta_id=? AND a.fecha<?',[$cc,$primero['desde']])['s'];
        $extractoPend=$this->r->q('SELECT x.id,x.nro,x.fecha,x.descripcion,x.referencia,x.monto_centavos monto,x.extracto_id FROM erp_tes_extracto_lineas x JOIN erp_tes_extractos e ON e.id=x.extracto_id WHERE e.tes_cuenta_id=? AND NOT EXISTS(SELECT 1 FROM erp_tes_conciliaciones k WHERE k.extracto_linea_id=x.id AND k.estado=\'vigente\') ORDER BY x.fecha,x.id',[$tesCuentaId]);
        $libroPend=$this->libroSinConciliar($cc,$primero['desde'],$ultimo['hasta']);
        $sumE=array_sum(array_map(fn($x)=>(int)$x['monto'],$extractoPend));$sumL=array_sum(array_map(fn($x)=>(int)$x['monto'],$libroPend));
        $saldoBanco=(int)$ultimo['saldo_final_centavos'];$apertura=(int)$primero['saldo_inicial_centavos']-$libroPrevio;
        $identidad=($saldoBanco-$saldoLibro)===($apertura+$sumE-$sumL);
        return ['cuenta'=>$t,'sin_extractos'=>false,'corte'=>$ultimo['hasta'],'desde'=>$primero['desde'],'saldo_banco'=>$saldoBanco,'saldo_libro'=>$saldoLibro,'diferencia'=>$saldoBanco-$saldoLibro,
            'diferencia_apertura'=>$apertura,'extracto_sin_registrar'=>$extractoPend,'extracto_sin_registrar_total'=>$sumE,'libro_sin_extracto'=>$libroPend,'libro_sin_extracto_total'=>$sumL,
            'identidad_cumple'=>$identidad,'conciliada'=>$identidad&&$apertura===0&&!$extractoPend&&!$libroPend];
    }
}
