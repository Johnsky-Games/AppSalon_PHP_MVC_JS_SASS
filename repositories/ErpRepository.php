<?php
namespace Repositories;

/** Acceso interno: SQL definido en servicios, nunca nombres de tabla/columna enviados por el usuario. */
final class ErpRepository extends OperacionRepository
{
    public function q(string $sql,array $args=[]):array
    { return $this->consulta($sql,implode('',array_map(static fn($v)=>is_int($v)?'i':'s',$args)),$args); }
    public function uno(string $sql,array $args=[]):?array {return $this->q($sql,$args)[0]??null;}
    public function id():int {return (int)$this->db->insert_id;}
}
