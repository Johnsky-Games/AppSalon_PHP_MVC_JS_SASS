<?php
namespace Services;

use Services\Fiscal\SriComprobante;

/**
 * Parámetros fiscales versionados por régimen y vigencia. AppSalon no trae cifras legales cargadas: cada valor lo registra una persona con
 * permiso de configuración tras verificarlo en la fuente oficial y deja constancia (norma, enlace y fecha de consulta). Sin parámetro vigente
 * no se emite el comprobante que depende de él. Las versiones son de solo anexar; las emitidas conservan en su instantánea la que usaron.
 */
final class ErpFiscalParametroService extends ErpService
{
    public const REGIMENES=['*','general','rimpe_emprendedor','rimpe_negocio_popular'];
    private const CLAVES=[
        'consumidor_final.total_maximo_centavos'=>['tipo'=>'importe','descripcion'=>'Importe total máximo (USD) de una factura a consumidor final'],
        'regimen.leyenda'=>['tipo'=>'texto','descripcion'=>'Leyenda de régimen que debe imprimirse en el comprobante (solo regímenes RIMPE)'],
        'servicios.hecho_generador'=>['tipo'=>'opcion','opciones'=>['prestacion'],'descripcion'=>'Momento elegido por el contribuyente para el hecho generador del IVA de servicios'],
        'nota_credito.tolerancia_centavos'=>['tipo'=>'entero','min'=>0,'max'=>1,'descripcion'=>'Desviación máxima (centavos) que se admite en una nota de crédito entre su IVA y base×tarifa, y entre su detalle y cantidad×precio. El reparto acumulado nunca produce más de 1 centavo por línea, por eso 1 es el máximo. Mayor que cero exige evidencia: enlace oficial de sri.gob.ec con la versión de la ficha técnica en la fuente, o la clave de acceso de una nota de crédito con esa desviación AUTORIZADA por el SRI; sin valor, la nota debe cuadrar exactamente'],
        'sustitucion.verificacion_minutos'=>['tipo'=>'entero','min'=>15,'max'=>1440,'descripcion'=>'Minutos de vigencia de la verificación previa a sustituir un comprobante rechazado (política interna)'],
    ];

    /** Catálogo completo (incluye una clave por cada código de tarifa de IVA que el generador sabe producir). */
    public static function catalogo():array
    {
        $c=self::CLAVES;
        foreach(SriComprobante::IVA as $codigo=>$pb)$c['iva.codigo.'.$codigo]=['tipo'=>'iva','pb'=>$pb,'descripcion'=>'El código SRI '.$codigo.' (tarifa '.number_format($pb/100,2,'.','').'%) puede emitirse en esta vigencia'];
        return $c;
    }
    public static function claveIva(string $codigo):string {return 'iva.codigo.'.$codigo;}

    /** Versión aplicable a la fecha: la más reciente del régimen propio y, si no hay, la general. «derogado» retira la regla. */
    public function resolver(string $clave,string $regimen,string $fecha,bool $bloquear=false):?array
    {
        $cierre=$bloquear?' FOR SHARE':'';
        if($regimen!=='*'){
            $p=$this->r->uno('SELECT * FROM erp_fiscal_parametros WHERE clave=? AND regimen=? AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1'.$cierre,[$clave,$regimen,$fecha]);
            if($p)return $p['estado']==='vigente'?$p:null;
        }
        $g=$this->r->uno("SELECT * FROM erp_fiscal_parametros WHERE clave=? AND regimen='*' AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1".$cierre,[$clave,$fecha]);
        return ($g&&$g['estado']==='vigente')?$g:null;
    }
    public function requerir(string $clave,string $regimen,string $fecha):array
    {
        return $this->resolver($clave,$regimen,$fecha,true)??throw new ErpException('Falta el parámetro fiscal «'.$clave.'» vigente el '.$fecha.($regimen==='*'?'':' para el régimen '.$regimen).'. Regístralo en Facturación › Parámetros fiscales después de verificarlo en la fuente oficial del SRI.',409);
    }
    /** Referencia mínima que se congela en la instantánea del comprobante. */
    public static function referencia(array $p):array {return ['id'=>(int)$p['id'],'clave'=>$p['clave'],'regimen'=>$p['regimen'],'vigente_desde'=>$p['vigente_desde'],'valor'=>$p['valor_entero']!==null?(int)$p['valor_entero']:$p['valor_texto']];}

    public function listar(string $fecha,string $regimen='*'):array
    {
        $vigentes=[];foreach(array_keys(self::catalogo()) as $clave)foreach(self::REGIMENES as $reg){
            $p=$this->r->uno('SELECT * FROM erp_fiscal_parametros WHERE clave=? AND regimen=? AND vigente_desde<=? ORDER BY vigente_desde DESC LIMIT 1',[$clave,$reg,$fecha]);if($p)$vigentes[]=$p;
        }
        return $vigentes;
    }
    private function entero(mixed $v,int $min,int $max):int
    {
        if((!is_int($v)&&!is_string($v))||!preg_match('/^\d{1,10}$/D',(string)$v)||(int)$v<$min||(int)$v>$max)throw new ErpException('Valor entero fuera de rango ('.$min.' a '.$max.').');return (int)$v;
    }
    public function historial(int $limite=100):array {return $this->r->q('SELECT * FROM erp_fiscal_parametros ORDER BY id DESC LIMIT '.max(1,min(500,$limite)));}

    /**
     * Una tolerancia mayor que cero ensancha lo que se firma y se envía al SRI: solo se registra con evidencia comprobable.
     *  A) Enlace oficial de sri.gob.ec y, en la fuente, la ficha técnica con su versión; o
     *  B) en la nota, las claves de acceso (49 dígitos) de notas de crédito de esta base, AUTORIZADAS por el SRI, cuyos XML firmados muestren, entre todas,
     *     una desviación de al menos esa magnitud en CADA dimensión que la tolerancia habilita (IVA de línea, detalle y suma por tarifa) y se puedan
     *     medir por completo (el SRI procesó y aceptó esos documentos tal como se enviaron).
     */
    public function verificarEvidenciaTolerancia(int $tolerancia,string $fuente,?string $url,?string $nota):void
    {
        if(is_string($url)&&preg_match('#^https://([a-z0-9-]+\.)*sri\.gob\.ec/#i',$url)&&preg_match('/ficha\s+t[eé]cnica/iu',$fuente)&&preg_match('/\b\d+\.\d+\b/',$fuente))return;
        $sinEvidencia='Una tolerancia mayor que cero requiere evidencia: (A) un enlace oficial de sri.gob.ec y, en la fuente, la ficha técnica con su versión (por ejemplo «Ficha técnica comprobantes electrónicos esquema offline, versión 2.xx, sección …»), o (B) en la nota, la clave de acceso de 49 dígitos de una nota de crédito de esta base con esa desviación AUTORIZADA por el SRI. Sin evidencia, la nota debe cuadrar exactamente y solo se bloquea la operación que no cuadre.';
        if($nota===null||!preg_match_all('/(?<!\d)(\d{49})(?!\d)/',$nota,$m))throw new ErpException($sinEvidencia);
        // Cada dimensión se demuestra por separado (IVA de línea, detalle y suma por tarifa): una nota puede probar una y no las otras.
        $maximo=['desviacion_iva'=>0,'desviacion_detalle'=>0,'desviacion_tarifa'=>0];
        foreach(array_unique($m[1]) as $clave){
            $ev=$this->evidenciaNota($clave);
            if($ev===null)throw new ErpException('La clave de acceso '.$clave.' no corresponde a una nota de crédito de esta base. '.$sinEvidencia);
            if($ev['estado']!=='autorizada')throw new ErpException('La nota '.$clave.' no está autorizada por el SRI (estado «'.$ev['estado'].'»): una nota rechazada o pendiente no demuestra que la desviación se acepte.',409);
            if($ev['no_evaluables']>0)throw new ErpException('No se pudo medir por completo la nota '.$clave.'; no sirve como evidencia.',409);
            foreach($maximo as $dim=>$v)$maximo[$dim]=max($v,$ev[$dim]);
        }
        $faltan=[];foreach(['desviacion_iva'=>'IVA de línea','desviacion_detalle'=>'detalle (cantidad × precio)','desviacion_tarifa'=>'suma del IVA por tarifa'] as $dim=>$etiqueta)if($maximo[$dim]<$tolerancia)$faltan[]=$etiqueta.' ('.$maximo[$dim].' c)';
        if($faltan)throw new ErpException('Las notas autorizadas citadas no demuestran la tolerancia de '.$tolerancia.' centavo(s) en: '.implode('; ',$faltan).'. Cita notas autorizadas que cubran cada dimensión, o la ficha técnica oficial (A).',409);
    }

    /**
     * Qué dicen los documentos que el SRI ya procesó sobre las desviaciones de 1 centavo (solo lectura; no modifica nada).
     * Veredicto: «acepta» si hay al menos una nota AUTORIZADA con desviación; «rechaza» si no hay ninguna aceptada y alguna rechazada con desviación
     * trae el error de diferencias (52); «sin_evidencia» en cualquier otro caso (no se asume nada).
     * @return array{veredicto:string,autorizadas_con_desviacion:list<array<string,mixed>>,rechazadas_con_desviacion:list<array<string,mixed>>,autorizadas_exactas:int,rechazadas_exactas:int,no_evaluables:int}
     */
    public function evidenciaTolerancia():array
    {
        $dims=['desviacion_iva','desviacion_detalle','desviacion_tarifa'];$aceptadas=[];
        $out=['veredicto'=>'sin_evidencia','autorizadas_con_desviacion'=>[],'rechazadas_con_desviacion'=>[],'autorizadas_exactas'=>0,'rechazadas_exactas'=>0,'no_evaluables'=>0,'dimensiones_aceptadas'=>[]];
        foreach($this->r->q("SELECT clave_acceso,estado,xml_firmado,respuesta_json FROM erp_fiscal_documentos WHERE tipo='04' AND estado IN('autorizada','rechazada') ORDER BY id") as $d){
            try{$m=\Services\Fiscal\SriNotaCreditoEvidencia::medir((string)$d['xml_firmado']);}catch(\InvalidArgumentException){$out['no_evaluables']++;continue;}
            if($m['no_evaluables']>0){$out['no_evaluables']++;continue;}
            $max=max($m['desviacion_iva'],$m['desviacion_detalle'],$m['desviacion_tarifa']);
            $fila=['clave_acceso'=>$d['clave_acceso'],'ambiente'=>$d['clave_acceso'][23],'desviacion_iva'=>$m['desviacion_iva'],'desviacion_detalle'=>$m['desviacion_detalle'],'desviacion_tarifa'=>$m['desviacion_tarifa']];
            if($d['estado']==='autorizada'){
                if($max>0){$out['autorizadas_con_desviacion'][]=$fila;foreach($dims as $dim)if($m[$dim]>0)$aceptadas[$dim]=true;}else $out['autorizadas_exactas']++;
            }else{
                if($max>0)$out['rechazadas_con_desviacion'][]=$fila+['error_diferencias'=>self::tieneErrorDiferencias($d['respuesta_json'])];
                else $out['rechazadas_exactas']++;
            }
        }
        $out['dimensiones_aceptadas']=array_values(array_filter($dims,fn($x)=>isset($aceptadas[$x])));
        if(count($out['dimensiones_aceptadas'])===count($dims))$out['veredicto']='acepta';
        elseif($out['dimensiones_aceptadas'])$out['veredicto']='acepta_parcial';
        elseif(array_filter($out['rechazadas_con_desviacion'],fn($f)=>$f['error_diferencias']))$out['veredicto']='rechaza';
        return $out;
    }

    /** ¿Alguno de los mensajes estructurados del SRI (hasta 100) es el error 52 «ERROR EN DIFERENCIAS»? Se decodifica la respuesta: no se busca en texto truncado. */
    private static function tieneErrorDiferencias(mixed $json):bool
    {
        $r=json_decode((string)$json,true);if(!is_array($r))return false;
        $mensajes=$r['mensajes']??[];if(!is_array($mensajes))return false;
        foreach(array_slice($mensajes,0,100) as $m){
            if(!is_array($m))continue;
            if(trim((string)($m['identificador']??''))==='52'||preg_match('/ERROR\s+EN\s+DIFERENCIAS/i',(string)($m['mensaje']??'')))return true;
        }
        return false;
    }

    /** @return ?array{estado:string,ambiente:string,desviacion_iva:int,desviacion_detalle:int,desviacion_tarifa:int,no_evaluables:int,lineas:int} */
    public function evidenciaNota(string $clave):?array
    {
        $d=$this->r->uno("SELECT clave_acceso,estado,xml_firmado FROM erp_fiscal_documentos WHERE tipo='04' AND clave_acceso=?",[$clave]);
        if(!$d)return null;
        try{$m=\Services\Fiscal\SriNotaCreditoEvidencia::medir((string)$d['xml_firmado']);}catch(\InvalidArgumentException){return null;}
        return ['estado'=>(string)$d['estado'],'ambiente'=>$clave[23]]+$m;
    }

    public function registrar(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.configurar','fiscal_parametro',$d,function()use($actor,$d){
            $catalogo=self::catalogo();$clave=is_string($d['clave']??null)?$d['clave']:'';if(!isset($catalogo[$clave]))throw new ErpException('Parámetro fiscal no reconocido.');$def=$catalogo[$clave];
            $regimen=is_string($d['regimen']??null)?$d['regimen']:'*';if(!in_array($regimen,self::REGIMENES,true))throw new ErpException('Régimen no válido.');
            if($clave==='regimen.leyenda'&&!in_array($regimen,['rimpe_emprendedor','rimpe_negocio_popular'],true))throw new ErpException('La leyenda de régimen se registra para un régimen RIMPE concreto.');
            if(in_array($clave,['servicios.hecho_generador','sustitucion.verificacion_minutos'],true)&&$regimen!=='*')throw new ErpException('Este parámetro aplica a todo el emisor.');
            $hoy=substr($this->r::ahora(),0,10);$desde=$this->fecha($d['vigente_desde']??null);$consultado=$this->fecha($d['consultado_en']??null);
            if($consultado>$hoy)throw new ErpException('La fecha de consulta de la fuente no puede ser futura.');
            if($desde>(new \DateTimeImmutable($hoy))->modify('+366 days')->format('Y-m-d')||$desde<'2000-01-01')throw new ErpException('Vigencia fuera de rango.');
            $estado=($d['estado']??'vigente');if(!in_array($estado,['vigente','derogado'],true))throw new ErpException('Estado no válido.');
            $entero=null;$texto=null;
            if($estado==='vigente'){
                $valor=$d['valor']??null;
                try{switch($def['tipo']){
                    case 'importe':$entero=$this->importe($valor);break;
                    case 'entero':$entero=$this->entero($valor,$def['min'],$def['max']);break;
                    case 'texto':$texto=$this->texto($valor,100);break;
                    case 'opcion':if(!is_string($valor)||!in_array($valor,$def['opciones'],true))throw new ErpException('Valor no disponible: el momento del hecho generador en el cobro (anticipos facturables) todavía no está implementado.');$texto=$valor;break;
                    case 'iva':$entero=$this->entero($valor,0,10000);if($entero!==$def['pb'])throw new ErpException('La tarifa debe coincidir con la del código SRI ('.number_format($def['pb']/100,2,'.','').'%).');break;
                }}catch(\InvalidArgumentException $e){throw new ErpException($e->getMessage());}
            }
            $fuente=$this->texto($d['fuente']??'',200);if(mb_strlen($fuente)<5)throw new ErpException('Indica la norma o documento oficial consultado.');
            $url=$d['fuente_url']??null;if($url!==null&&$url!==''){if(!is_string($url)||!preg_match('#^https://[^\s<>"\']{4,280}$#D',$url))throw new ErpException('El enlace de la fuente debe ser una URL https.');}else $url=null;
            $nota=isset($d['nota'])&&$d['nota']!==''?$this->texto($d['nota'],300):null;
            if($clave==='nota_credito.tolerancia_centavos'&&$estado==='vigente'&&(int)$entero>0)$this->verificarEvidenciaTolerancia((int)$entero,$fuente,is_string($url)?$url:null,$nota);
            // Una versión solo sustituye a la anterior hacia adelante: la historia emitida no se reinterpreta.
            $ultima=$this->r->uno('SELECT vigente_desde FROM erp_fiscal_parametros WHERE clave=? AND regimen=? ORDER BY vigente_desde DESC LIMIT 1 FOR UPDATE',[$clave,$regimen]);
            if($ultima&&$desde<=$ultima['vigente_desde'])throw new ErpException('Ya existe una versión con vigencia desde '.$ultima['vigente_desde'].'. Registra la corrección con una vigencia posterior.',409);
            if($ultima&&$desde<$hoy)throw new ErpException('Una versión nueva rige desde hoy o una fecha futura; las anteriores se conservan sin reinterpretarse.',409);
            try{
                $this->r->q('INSERT INTO erp_fiscal_parametros(clave,regimen,vigente_desde,estado,valor_entero,valor_texto,fuente,fuente_url,consultado_en,nota,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$clave,$regimen,$desde,$estado,$entero,$texto,$fuente,$url,$consultado,$nota,$actor,$this->r::ahora()]);
            }catch(\mysqli_sql_exception $e){if((int)$e->getCode()===1062)throw new ErpException('Esa versión del parámetro ya existe.',409);throw $e;}
            $id=$this->r->id();$this->evento($actor,'fiscal_parametro_registrado','fiscal_parametro',$id,['clave'=>$clave,'regimen'=>$regimen,'vigente_desde'=>$desde,'estado'=>$estado,'valor'=>$entero??$texto,'fuente'=>$fuente,'fuente_url'=>$url,'consultado_en'=>$consultado]);return $id;
        });
    }
}
