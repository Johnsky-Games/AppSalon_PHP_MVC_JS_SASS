<?php
namespace Classes\Exportacion;

interface EscritorTabla
{
    /** @param array<int,array{clave:string,titulo:string,tipo:string}> $columnas */
    public function iniciar(array $columnas): void;
    /** @param array<string,mixed> $valores */
    public function fila(array $valores): void;
    /** Fila final de totales (números ya calculados, nunca fórmulas). @param array<string,mixed> $valores por clave de columna */
    public function totales(string $etiqueta, array $valores): void;
    /** Fila final de aviso en texto (p. ej. «exportación truncada»), nunca una fórmula. */
    public function nota(string $texto): void;
    public function cerrar(): void;
    public function filasEscritas(): int;
}
