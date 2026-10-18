<?php
namespace Services;
final class ErpException extends \DomainException
{
    public function __construct(string $mensaje,int $http=422){parent::__construct($mensaje,$http);}
}
