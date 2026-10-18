<?php
namespace Interfaces;
/** Cada adaptador usa la referencia estable como clave idempotente y consulta antes de repetir tras un timeout. */
interface IntegracionErpInterface
{
    /** @return array{estado:string,referencia_externa:string,datos?:array} */
    public function enviar(string $referencia,array $documento):array;
    /** null si no existe; una respuesta confirmada permite recuperar una entrega cuya respuesta se perdió. */
    public function consultar(string $referencia):?array;
}
