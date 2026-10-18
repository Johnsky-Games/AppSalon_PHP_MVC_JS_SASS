<?php
namespace Services;

final class ErpMaestrosService extends ErpService
{
    public function empresa(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.configurar','empresa',$d,function()use($actor,$d){
            $nombre=$this->texto($d['nombre']??'',120);$direccion=$this->texto($d['direccion']??'',300);
            $ident=trim((string)($d['identificacion']??''));
            if(!preg_match('/^[A-Za-z0-9-]{5,30}$/D',$ident))throw new ErpException('Completa la identificación del emisor.');
            $this->r->q('UPDATE erp_empresa SET nombre=?,identificacion=?,direccion=?,actualizado_por=?,actualizado_en=? WHERE id=1',[$nombre,$ident,$direccion,$actor,$this->r::ahora()]);
            $this->evento($actor,'empresa_actualizada','empresa',1,['nombre'=>$nombre]);return 1;
        });
    }
    public function contacto(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.ventas.crear','contacto',$d,function()use($actor,$d){
            $tipo=$d['tipo']??'cliente';if(!in_array($tipo,['cliente','proveedor','ambos'],true))throw new ErpException('Tipo de contacto no válido.');
            if($tipo!=='cliente')$this->permitir($actor,'erp.compras');
            $nombre=$this->texto($d['nombre']??'',120);$email=trim(is_string($d['email']??null)?$d['email']:'');
            if($email!==''&&(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>254))throw new ErpException('Correo no válido.');
            $telefono=trim(is_string($d['telefono']??null)?$d['telefono']:'');
            if(strlen($telefono)>40||preg_match('/[\x00-\x1f]/',$telefono))throw new ErpException('Teléfono no válido.');
            $ident=trim(is_string($d['identificacion']??null)?$d['identificacion']:'');
            if($ident!==''&&!preg_match('/^[A-Za-z0-9-]{3,30}$/D',$ident))throw new ErpException('Identificación no válida.');
            $dir=trim(is_string($d['direccion']??null)?$d['direccion']:'');if(strlen($dir)>300)throw new ErpException('Dirección demasiado larga.');
            if($ident!==''&&$this->r->uno('SELECT id FROM erp_contactos WHERE identificacion=? AND activo=1',[$ident]))throw new ErpException('Ya existe un contacto con esa identificación. Utiliza su ficha.');
            $this->r->q('INSERT INTO erp_contactos(nombre,identificacion,email,telefono,direccion,tipo,creado_por,creado_en) VALUES(?,?,?,?,?,?,?,?)',[$nombre,$ident?:null,$email?:null,$telefono?:null,$dir,$tipo,$actor,$this->r::ahora()]);
            $id=$this->r->id();$this->evento($actor,'contacto_creado','contacto',$id,['tipo'=>$tipo]);return $id;
        });
    }
    public function catalogo(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'erp.catalogo','catalogo',$d,function()use($actor,$d){
            $tipo=$d['origen']??'';if(!in_array($tipo,['producto','servicio'],true))throw new ErpException('Selecciona producto o servicio.');
            $origen=$this->id($d['origen_id']??null);$precio=$this->importe($d['precio']??null,true);
            $tasa=ValoresExactos::decimal($d['impuesto']??null,2,true);if($tasa>10000)throw new ErpException('Impuesto fuera de rango.');
            $uso=$d['tipo']??'venta';if(!in_array($uso,['venta','consumo','ambos'],true))throw new ErpException('Uso no válido.');
            if($tipo==='servicio'&&$uso==='consumo')throw new ErpException('Un servicio no es un insumo.');
            $tabla=$tipo==='producto'?'inventario_productos':'servicios';
            if(!$this->r->uno("SELECT id FROM $tabla WHERE id=? FOR UPDATE",[$origen]))throw new ErpException('Artículo no encontrado.');
            $col=$tipo==='producto'?'producto_id':'servicio_id';
            $antes=$this->r->uno("SELECT * FROM erp_catalogo WHERE $col=? FOR UPDATE",[$origen]);
            if($antes){$id=(int)$antes['id'];$this->r->q('UPDATE erp_catalogo SET precio_centavos=?,impuesto_pb=?,tipo=?,activo=?,actualizado_por=?,actualizado_en=? WHERE id=?',[$precio,$tasa,$uso,($d['activo']??'1')==='0'?0:1,$actor,$this->r::ahora(),$id]);}
            else{$this->r->q("INSERT INTO erp_catalogo($col,precio_centavos,impuesto_pb,tipo,actualizado_por,actualizado_en) VALUES(?,?,?,?,?,?)",[$origen,$precio,$tasa,$uso,$actor,$this->r::ahora()]);$id=$this->r->id();}
            if($tipo==='servicio'){
                $calc=ErpCalculo::linea(1000,$precio,0,$tasa);
                $this->r->q('UPDATE servicios SET precio=? WHERE id=?',[CobroCalculo::plano($calc['total']),$origen]);
            }
            $this->evento($actor,'tarifa_catalogo','catalogo',$id,['antes'=>$antes,'precio_centavos'=>$precio,'impuesto_pb'=>$tasa,'uso'=>$uso]);return $id;
        });
    }
    public function vincularCliente(int $actor,array $d):array
    {
        return $this->ejecutar($actor,'clientes.ver','contacto_vincular',$d,function()use($actor,$d){
            $id=$this->id($d['contacto_id']??null);$uid=$this->id($d['usuario_id']??null);
            $u=$this->r->uno("SELECT u.id,u.email,c.estado FROM usuarios u JOIN clientes c ON c.usuario_id=u.id WHERE u.id=? FOR UPDATE",[$uid])??throw new ErpException('Selecciona una membresía de cliente existente.');
            $contacto=$this->r->uno("SELECT * FROM erp_contactos WHERE id=? AND tipo IN ('cliente','ambos') FOR UPDATE",[$id])??throw new ErpException('Contacto cliente no encontrado.');
            if($contacto['usuario_id']&&(int)$contacto['usuario_id']!==$uid)throw new ErpException('El contacto ya tiene una identidad vinculada. Resuelve la conciliación desde su ficha.',409);
            if($this->r->uno('SELECT id FROM erp_contactos WHERE usuario_id=? AND id<>?',[$uid,$id]))throw new ErpException('Esta membresía ya está vinculada a otro contacto.',409);
            // No atribuir ventas históricas por coincidencia de nombre/correo. El enlace solo afecta operaciones futuras.
            $this->r->q('UPDATE erp_contactos SET usuario_id=? WHERE id=?',[$uid,$id]);
            $this->evento($actor,'contacto_vinculado','contacto',$id,['usuario_id'=>$uid]);return $id;
        });
    }
}
