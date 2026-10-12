<?php
namespace Services;
use Repositories\OperacionRepository;
final class InventarioService
{
    private OperacionRepository $repo;
    public function __construct(?OperacionRepository $repo = null) { $this->repo = $repo ?? new OperacionRepository(); }
    public function crear(array $datos): int
    {
        $sku = ValoresExactos::texto($datos['sku'] ?? null, 40);
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $sku)) { throw new \InvalidArgumentException('SKU: use letras, números, guion o guion bajo.'); }
        $nombre = ValoresExactos::texto($datos['nombre'] ?? null,120);
        $unidad = ValoresExactos::texto($datos['unidad'] ?? null,20);
        $minimo = ValoresExactos::decimal($datos['minimo'] ?? '0',3,true);
        $costo = ValoresExactos::decimal($datos['costo'] ?? '0',2,true);
        $this->repo->consulta('INSERT INTO inventario_productos (sku,nombre,unidad,minimo_milesimas,costo_centavos) VALUES (?,?,?,?,?)','sssii',[$sku,$nombre,$unidad,$minimo,$costo]);
        return (int)$this->repo->db->insert_id;
    }
    public function mover(int $actor, array $datos): array
    {
        $id = filter_var($datos['producto_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !in_array($datos['tipo'] ?? '',['entrada','salida'],true)) { throw new \InvalidArgumentException('Producto o tipo de movimiento inválido.'); }
        $tipo = $datos['tipo'];
        $cantidad = ValoresExactos::decimal($datos['cantidad'] ?? null,3);
        $motivo = ValoresExactos::texto($datos['motivo'] ?? null,180);
        $clave = ValoresExactos::clave($datos['idempotencia'] ?? null,$actor);
        $huella = hash('sha256',json_encode([$id,$tipo,$cantidad,$motivo]));
        return $this->repo->transaccion(function() use($id,$tipo,$cantidad,$motivo,$clave,$huella,$actor) {
            $producto = $this->repo->consulta('SELECT * FROM inventario_productos WHERE id=? FOR UPDATE','i',[$id])[0] ?? null;
            if (!$producto || !(int)$producto['activo']) { throw new \InvalidArgumentException('Producto no disponible.'); }
            $anterior = $this->repo->consulta('SELECT * FROM inventario_movimientos WHERE idempotencia=?','s',[$clave])[0] ?? null;
            if ($anterior) {
                if (!hash_equals($anterior['huella'],$huella)) { throw new \InvalidArgumentException('El identificador ya pertenece a otra operación.'); }
                return ['id'=>(int)$anterior['id'],'idempotente'=>true];
            }
            $saldo = (int)$producto['stock_milesimas'] + ($tipo==='entrada' ? $cantidad : -$cantidad);
            if ($saldo<0 || $saldo>9999999999) { throw new \InvalidArgumentException('Stock insuficiente o cantidad fuera de rango.'); }
            $this->repo->consulta('UPDATE inventario_productos SET stock_milesimas=? WHERE id=?','ii',[$saldo,$id]);
            $this->repo->consulta('INSERT INTO inventario_movimientos (producto_id,actor_id,tipo,cantidad_milesimas,saldo_milesimas,costo_centavos,motivo,idempotencia,huella,registrado_en) VALUES (?,?,?,?,?,?,?,?,?,?)','iisiiissss',[$id,$actor,$tipo,$cantidad,$saldo,(int)$producto['costo_centavos'],$motivo,$clave,$huella,OperacionRepository::ahora()]);
            return ['id'=>(int)$this->repo->db->insert_id,'idempotente'=>false];
        });
    }
}
