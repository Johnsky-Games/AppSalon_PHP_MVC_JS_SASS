<?php
namespace Classes;

/** Rangos calendario resueltos en servidor; las consultas siguen validando sus límites de dominio. */
final class PeriodoFiltro
{
    public const OPCIONES = ['personalizado'=>'Personalizado', 'hoy'=>'Hoy', 'ayer'=>'Ayer', 'manana'=>'Mañana',
        'semana'=>'Esta semana', 'proxima_semana'=>'Próxima semana', 'mes'=>'Este mes', 'mes_anterior'=>'Mes anterior'];

    public static function aplicar(array $entrada, string $inicio='desde', string $fin='hasta', bool $historico=false, ?\DateTimeImmutable $ahora=null): array
    {
        $p=$entrada['periodo']??'personalizado';
        if (!is_string($p) || !isset(self::OPCIONES[$p]) || $p==='personalizado') { return $entrada; }
        $hoy=($ahora??new \DateTimeImmutable('now',new \DateTimeZone('America/Guayaquil')))->setTimezone(new \DateTimeZone('America/Guayaquil'))->setTime(0,0);
        [$d,$h]=match($p) {
            'hoy'=>[$hoy,$hoy], 'ayer'=>[$hoy->modify('-1 day'),$hoy->modify('-1 day')],
            'manana'=>[$hoy->modify('+1 day'),$hoy->modify('+1 day')],
            'semana'=>[$hoy->modify('-'.((int)$hoy->format('N')-1).' days'),$hoy->modify('+'.(7-(int)$hoy->format('N')).' days')],
            'proxima_semana'=>[$hoy->modify('+'.(8-(int)$hoy->format('N')).' days'),$hoy->modify('+'.(14-(int)$hoy->format('N')).' days')],
            'mes'=>[$hoy->modify('first day of this month'),$hoy->modify('last day of this month')],
            'mes_anterior'=>[$hoy->modify('first day of last month'),$hoy->modify('last day of last month')],
        };
        if ($historico && $h>$hoy) { $h=$hoy; }
        if ($historico && $d>$hoy) { $d=$hoy; }
        $entrada[$inicio]=$d->format('Y-m-d'); $entrada[$fin]=$h->format('Y-m-d');
        $entrada['pagina']='1';
        return $entrada;
    }
}
