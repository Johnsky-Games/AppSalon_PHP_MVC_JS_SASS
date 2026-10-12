<?php
namespace Services;
use Repositories\CrecimientoRepository;

class CrecimientoService
{
    public function __construct(private CrecimientoRepository $repo) {}
    private function texto(mixed $valor, int $max, bool $requerido=true): string
    {
        if (!is_string($valor)) { throw new \InvalidArgumentException('El texto no es válido.'); }
        $v=trim($valor);
        if (($requerido && $v==='') || mb_strlen($v)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v)) { throw new \InvalidArgumentException('Texto vacío o demasiado largo.'); }
        return $v;
    }
    public function validarPreguntas(mixed $datos): array
    {
        if (!is_array($datos) || !$datos || count($datos)>12) { throw new \InvalidArgumentException('Configura entre 1 y 12 preguntas.'); }
        $resultado=[];
        foreach ($datos as $i=>$dato) {
            if (!is_array($dato)) { throw new \InvalidArgumentException('Pregunta inválida.'); }
            $tipo=$dato['tipo']??'texto';
            if (!in_array($tipo,['texto','select','checkbox'],true)) { throw new \InvalidArgumentException('Tipo de pregunta inválido.'); }
            $pregunta=['id'=>'p'.count($resultado),'etiqueta'=>$this->texto($dato['etiqueta']??'',150),'tipo'=>$tipo,'requerida'=>!empty($dato['requerida'])];
            if ($tipo==='select') {
                $opciones=$dato['opciones']??[];
                if (!is_array($opciones) || count($opciones)<2 || count($opciones)>10) { throw new \InvalidArgumentException('Las listas requieren entre 2 y 10 opciones.'); }
                $pregunta['opciones']=array_values(array_unique(array_map(fn($v)=>$this->texto($v,100),$opciones)));
                if (count($pregunta['opciones'])<2) { throw new \InvalidArgumentException('Incluye opciones diferentes.'); }
            }
            $resultado[]=$pregunta;
        }
        return $resultado;
    }
    public function validarRespuestas(array $preguntas, mixed $valores): array
    {
        if (!is_array($valores)) { throw new \InvalidArgumentException('Respuestas inválidas.'); }
        $r=[];
        foreach($preguntas as $p) {
            $v=$valores[$p['id']]??'';
            if($p['tipo']==='checkbox') {
                if (!in_array($v,['','1','0'],true)) { throw new \InvalidArgumentException('Casilla inválida.'); }
                $r[$p['id']]=$v==='1';
                if($p['requerida'] && !$r[$p['id']]) { throw new \InvalidArgumentException('Debes aceptar: '.$p['etiqueta']); }
            } else {
                $r[$p['id']]=$this->texto($v,1000,$p['requerida']);
                if($p['tipo']==='select' && $r[$p['id']]!=='' && !in_array($r[$p['id']],$p['opciones'],true)) { throw new \InvalidArgumentException('Selecciona una opción válida.'); }
            }
        }
        return $r;
    }
    public function cliente(int $id): array { return $this->repo->cliente($id)+$this->repo->catalogos(); }
    public function dashboard(): array { return $this->repo->dashboard(); }
    public function respuestas(int $id): array { return $this->repo->respuestasPrivadas($id); }
    public function accionCliente(int $id, array $datos): string
    {
        switch($datos['accion']??'') {
            case 'consentimiento': $this->repo->consentimiento($id,($datos['aceptado']??'')==='1'); return 'Preferencia comercial actualizada.';
            case 'espera':
                $servicio=filter_var($datos['servicio_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $prof=($datos['profesional_id']??'')==='' ? null : filter_var($datos['profesional_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $fecha=$datos['fecha']??''; $f=is_string($fecha)?\DateTimeImmutable::createFromFormat('!Y-m-d',$fecha,new \DateTimeZone('America/Guayaquil')):false;
                $hoy=new \DateTimeImmutable('today',new \DateTimeZone('America/Guayaquil'));
                if(!$servicio || $prof===false || !$f || $f->format('Y-m-d')!==$fecha || $f<$hoy || $f>$hoy->modify('+180 days')) { throw new \InvalidArgumentException('Servicio o fecha inválidos; selecciona los próximos 180 días.'); }
                $this->repo->agregarEspera($id,$servicio,$prof,$fecha); return 'Solicitud registrada. La lista de espera no garantiza una reserva.';
            case 'cancelar_espera': $this->repo->estadoEspera($this->id($datos['id']??null),'cancelada',$id); return 'Solicitud cancelada.';
            case 'puntos': $this->repo->sincronizarPuntos($id); return 'Puntos de citas completadas actualizados.';
            case 'canje':
                $clave=$datos['clave']??''; if(!is_string($clave)||!preg_match('/^[a-f0-9]{32}$/D',$clave)) { throw new \InvalidArgumentException('Clave de canje inválida.'); }
                $this->repo->canjear($id,$clave); return 'Canje registrado. Recepción debe entregar y registrar el beneficio.';
            case 'respuesta':
                $fid=$this->id($datos['formulario_id']??null); $f=$this->repo->formulario($fid);
                if(!$f) { throw new \InvalidArgumentException('El formulario ya no está disponible.'); }
                $this->repo->responder($fid,$id,$this->validarRespuestas(json_decode($f['preguntas'],true,512,JSON_THROW_ON_ERROR),$datos['respuestas']??[])); return 'Respuestas guardadas de forma privada.';
            default: throw new \InvalidArgumentException('Acción desconocida.');
        }
    }
    public function accionAdmin(int $autor, array $datos): string
    {
        switch($datos['accion']??'') {
            case 'formulario':
                $preguntas=$datos['preguntas']??[];
                if(!is_array($preguntas)){throw new \InvalidArgumentException('Preguntas inválidas.');}
                $preguntas=array_values(array_filter($preguntas,static fn($p)=>is_array($p)&&trim((string)($p['etiqueta']??''))!==''));
                foreach($preguntas as &$p){if(($p['tipo']??'')==='select'&&is_string($p['opciones']??null)){$p['opciones']=array_map('trim',explode(',', $p['opciones']));}}unset($p);
                $this->repo->crearFormulario($autor,$this->texto($datos['titulo']??'',120),$this->validarPreguntas($preguntas)); return 'Formulario publicado.';
            case 'archivar': $this->repo->archivarFormulario($this->id($datos['id']??null)); return 'Formulario archivado; se conservan sus respuestas.';
            case 'espera_estado':
                $estado=$datos['estado']??''; if(!in_array($estado,['contactada','cerrada','cancelada'],true)) { throw new \InvalidArgumentException('Estado inválido.'); }
                $this->repo->estadoEspera($this->id($datos['id']??null),$estado); return 'Solicitud actualizada; no se creó una reserva automáticamente.';
            case 'canje_entregado': $this->repo->completarCanje($this->id($datos['id']??null),$autor); return 'Entrega del beneficio registrada.';
            default: throw new \InvalidArgumentException('Acción desconocida.');
        }
    }
    private function id(mixed $v): int
    { $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if(!$id){throw new \InvalidArgumentException('Identificador inválido.');} return $id; }
    public function segmento(string $segmento): array
    { if(!in_array($segmento,['todos','inactivos'],true)){throw new \InvalidArgumentException('Segmento inválido.');} return $this->repo->segmento($segmento); }
    public function ajustes(): array { return $this->repo->ajustes(); }
    public function guardarAjustes(int $autor, array $datos): void
    {
        $nombre=$this->texto($datos['nombre_comercial']??'',120); $contacto=$this->texto($datos['contacto']??'',200,false); $beneficio=$this->texto($datos['beneficio_fidelidad']??'',300);
        $this->repo->guardarAjustes($autor,['nombre_comercial'=>$nombre,'contacto'=>$contacto,'beneficio_fidelidad'=>$beneficio]);
    }
}
