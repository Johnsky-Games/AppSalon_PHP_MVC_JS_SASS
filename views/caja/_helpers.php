<?php
use Services\CobroCalculo;

if (!function_exists('caja_dinero')) {
    /** Importe en centavos → «$1,234.50». */
    function caja_dinero(int|null $centavos): string { return '$' . CobroCalculo::formato((int)$centavos); }
    function caja_estado_pago(string $e): array
    {
        return match ($e) {
            'pagado' => ['Pagado', 'caja-badge--ok'], 'parcial' => ['Pago parcial', 'caja-badge--aviso'], 'por_verificar' => ['Por verificar', 'caja-badge--accion'],
            'reembolsado' => ['Reembolsado', 'caja-badge--neutro'], default => ['Pendiente de cobro', 'caja-badge--pendiente'],
        };
    }
    function caja_estado_cita(string $e): string
    {
        return match ($e) { 'confirmada' => 'Confirmada', 'en_servicio' => 'En servicio', 'completada' => 'Atendida', 'cancelada' => 'Cancelada', 'no_asistio' => 'No asistió', default => ucfirst(str_replace('_', ' ', $e)) };
    }
    function caja_metodo(string $m): string { return match ($m) { 'efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'tarjeta_mock' => 'Tarjeta (simulada)', '' => '—', default => ucfirst($m) }; }
    function caja_fecha(?string $f): string { return $f ? s(substr($f, 0, 16)) : '—'; }
}
