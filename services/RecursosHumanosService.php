<?php
namespace Services;

use Repositories\ColaboradorRepository;

final class RecursosHumanosService
{
    public const ROLES = ['propietario' => 'Propietario', 'gerente' => 'Gerencia', 'recepcion' => 'Recepción', 'profesional' => 'Profesional', 'finanzas' => 'Finanzas'];
    public const ESTADOS = ['activo' => 'Activo', 'invitado' => 'Invitación pendiente', 'suspendido' => 'Suspendido', 'baja' => 'Baja'];

    public function __construct(private ColaboradorRepository $repo) {}

    public function consultar(array $entrada): array
    {
        $errores = [];
        $q = $entrada['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 80) { $errores[] = 'La búsqueda admite hasta 80 caracteres.'; $q = ''; }
        $q = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', ' ', $q));
        $opcion = static function (string $campo, array $catalogo) use ($entrada, &$errores): ?string {
            $v = $entrada[$campo] ?? '';
            if ($v === '') { return null; }
            if (!is_string($v) || !isset($catalogo[$v])) { $errores[] = 'El filtro de ' . $campo . ' no es válido.'; return null; }
            return $v;
        };
        $rol = $opcion('rol', self::ROLES); $estado = $opcion('estado', self::ESTADOS);
        $paginaRaw = $entrada['pagina'] ?? '1';
        $pagina = is_string($paginaRaw) && preg_match('/^[1-9]\d{0,5}$/', $paginaRaw) ? (int)$paginaRaw : 1;
        if ($paginaRaw !== (string)$pagina && $paginaRaw !== $pagina) { $errores[] = 'La página no es válida.'; }
        return ['filtros' => ['q' => $q, 'rol' => $rol, 'estado' => $estado], 'errores' => $errores,
            'fichasSinVinculo' => $this->repo->profesionalesSinCuenta(),
            'directorio' => $this->repo->directorio($q, $rol, $estado, $pagina)];
    }
}
