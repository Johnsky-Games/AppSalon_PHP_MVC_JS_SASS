<?php
namespace Interfaces;

interface SriTransporteInterface
{
    /** Devuelve el cuerpo SOAP íntegro; un timeout no equivale a rechazo ni autorización. */
    public function post(string $url,string $xml):string;
}
