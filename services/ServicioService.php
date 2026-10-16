<?php

namespace Services;

use Model\Servicio;
use Repositories\PersistenceException;
use Repositories\ServicioRepository;

/**
 * Servicio de dominio para la validación y operaciones del catálogo de servicios.
 *
 * Política sobre precio cero:
 * Todo servicio del catálogo comercial debe tener un precio estrictamente positivo
 * (mínimo 0.01 y máximo 9999.99 conforme a la columna DECIMAL(6,2) en MySQL).
 * Los valores cero (0, 0.0, 0.00) y negativos son rechazados en la validación.
 *
 * Política sobre duración en minutos:
 * Todo servicio debe tener una duración expresada como entero positivo (`>= 1` minuto).
 * El valor `DEFAULT_DURACION_MINUTOS = 30` constituye un supuesto técnico configurable
 * para registros preexistentes en migraciones o inicialización de formularios, y no un
 * dato confirmado del negocio.
 */
class ServicioService
{
    public const STATUS_OK = 'ok';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_NOT_FOUND = 'not_found';
    public const STATUS_ERROR = 'error';

    public const MAX_NOMBRE_LENGTH = 60;
    public const MIN_PRECIO_CENTAVOS = 1;       // 0.01
    public const MAX_PRECIO_CENTAVOS = 999999;  // 9999.99

    /**
     * Supuesto configurable para servicios preexistentes o valor inicial de formulario.
     * No representa un dato confirmado del negocio; cada servicio es configurable por el administrador.
     */
    public const DEFAULT_DURACION_MINUTOS = 30;

    private ServicioRepository $repository;

    public function __construct(?ServicioRepository $repository = null)
    {
        $this->repository = $repository ?? new ServicioRepository();
    }

    /**
     * Valida que un identificador de servicio sea un entero positivo escalar válido.
     * Rechaza arreglos, booleanos, flotantes, cero, negativos o cadenas no numéricas.
     *
     * @param mixed $idRaw Valor recibido (por ejemplo desde $_GET['id'] o $_POST['id']).
     * @return int|null Entero positivo (>= 1) o null si es inválido.
     */
    public function validarId($idRaw): ?int
    {
        if (is_int($idRaw)) {
            return ($idRaw >= 1 && $idRaw <= 2147483647) ? $idRaw : null;
        }

        if (!is_string($idRaw)) {
            return null;
        }

        $trimmed = trim($idRaw);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }

        $val = filter_var($trimmed, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 2147483647
            ]
        ]);

        return $val === false ? null : $val;
    }

    /**
     * Valida las entradas del catálogo conforme al esquema vigente:
     * - `nombre`: escalar string no vacío, longitud máxima 60 caracteres UTF-8 (`VARCHAR(60)`).
     * - `precio`: escalar numérico/decimal con hasta 2 decimales, estrictamente mayor a 0
     *   (política de rechazo de precio cero y negativos) y máximo 9999.99 (`DECIMAL(6,2)`).
     * - `duracion_minutos`: escalar entero estrictamente positivo (`>= 1` y `<= 2147483647`).
     *
     * @param array $datos Datos de entrada (únicamente se consideran `nombre`, `precio` y `duracion_minutos`).
     * @return array Estructura de alertas `['error' => [...]]` o arreglo vacío `[]` si es válido.
     */
    public static function validarDatos(array $datos): array
    {
        $errores = [];

        // 1. Validación de `nombre`
        $rawNombre = $datos['nombre'] ?? '';
        if (!is_string($rawNombre)) {
            $errores[] = 'El nombre del servicio tiene un formato inválido';
        } else {
            $nombre = trim($rawNombre);
            if ($nombre === '') {
                $errores[] = 'El Nombre del Servicio es Obligatorio';
            } elseif (mb_strlen($nombre, 'UTF-8') > self::MAX_NOMBRE_LENGTH) {
                $errores[] = 'El nombre del servicio no puede exceder los 60 caracteres';
            }
        }

        // 2. Validación de `precio`
        $rawPrecio = $datos['precio'] ?? '';
        if (!is_string($rawPrecio) && !is_int($rawPrecio) && !is_float($rawPrecio)) {
            $errores[] = 'El precio del servicio tiene un formato inválido';
        } else {
            $precioStr = trim((string)$rawPrecio);
            if ($precioStr === '') {
                $errores[] = 'El Precio del Servicio es Obligatorio';
            } elseif (preg_match('/^-\d+(\.\d+)?$/', $precioStr) === 1) {
                $errores[] = 'El precio no puede ser negativo';
            } elseif (preg_match('/^\d+\.\d{3,}$/', $precioStr) === 1) {
                $errores[] = 'El precio solo puede tener hasta 2 decimales';
            } elseif (preg_match('/^\d+(\.\d{1,2})?$/', $precioStr) !== 1) {
                $errores[] = 'El precio no es válido';
            } else {
                $partes = explode('.', $precioStr);
                $enterosSinCeros = ltrim($partes[0], '0');

                if (strlen($enterosSinCeros) > 4) {
                    $errores[] = 'El precio no puede exceder 9999.99';
                } else {
                    $enteros = (int)($partes[0]);
                    $decimales = (int)str_pad($partes[1] ?? '0', 2, '0', STR_PAD_RIGHT);
                    $centavos = ($enteros * 100) + $decimales;

                    if ($centavos < self::MIN_PRECIO_CENTAVOS) {
                        // Política explícita sobre precio cero: no se admiten servicios de $0.00
                        $errores[] = 'El precio debe ser mayor a 0';
                    } elseif ($centavos > self::MAX_PRECIO_CENTAVOS) {
                        $errores[] = 'El precio no puede exceder 9999.99';
                    }
                }
            }
        }

        // 3. Validación de `duracion_minutos` (entero positivo obligatorio)
        $rawDuracion = $datos['duracion_minutos'] ?? '';
        if (!is_string($rawDuracion) && !is_int($rawDuracion)) {
            $errores[] = 'La duración del servicio tiene un formato inválido';
        } else {
            $duracionStr = trim((string)$rawDuracion);
            if ($duracionStr === '') {
                $errores[] = 'La Duración del Servicio es Obligatoria';
            } elseif (preg_match('/^-\d+$/', $duracionStr) === 1 || preg_match('/^0+$/', $duracionStr) === 1) {
                $errores[] = 'La duración del servicio debe ser un número entero positivo mayor a 0';
            } elseif (!ctype_digit($duracionStr)) {
                $errores[] = 'La duración del servicio debe ser un número entero positivo en minutos';
            } else {
                $valDuracion = filter_var($duracionStr, FILTER_VALIDATE_INT, [
                    'options' => [
                        'min_range' => 1,
                        'max_range' => 2147483647
                    ]
                ]);
                if ($valDuracion === false) {
                    $errores[] = 'La duración del servicio debe ser un número entero positivo válido';
                }
            }
        }

        if (empty($errores)) {
            return [];
        }

        return ['error' => $errores];
    }

    /**
     * Normaliza un precio ya validado al formato estándar de dos decimales (`0.00`).
     */
    public static function normalizarPrecio(string $precioStr): string
    {
        $partes = explode('.', trim($precioStr));
        $enteros = (int)$partes[0];
        $decimales = str_pad($partes[1] ?? '0', 2, '0', STR_PAD_RIGHT);
        return sprintf('%d.%s', $enteros, $decimales);
    }

    /**
     * Normaliza una duración ya validada a representación entera en cadena.
     */
    public static function normalizarDuracion($duracionRaw): string
    {
        return (string)(int)trim((string)$duracionRaw);
    }

    /**
     * Lista todos los servicios del catálogo.
     * Distingue catálogo vacío (`status => ok`, `servicios => []`) de fallo SQL (`status => error`).
     */
    public function listar(): array
    {
        try {
            $servicios = $this->repository->findAll();
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'servicios' => $servicios,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'servicios' => [],
                'alertas' => [
                    'error' => ['No fue posible cargar el catálogo de servicios. Intente nuevamente.']
                ]
            ];
        }
    }

    /**
     * Obtiene un servicio por su identificador validado.
     * Distingue ID inválido (`status => invalid`), registro inexistente (`status => not_found`)
     * y fallo SQL (`status => error`).
     */
    public function obtenerPorId($idRaw): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'servicio' => null,
                'alertas' => [
                    'error' => ['El identificador del servicio no es válido.']
                ]
            ];
        }

        try {
            $servicio = $this->repository->findById($id);
            if ($servicio === null) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'servicio' => null,
                    'alertas' => [
                        'error' => ['El servicio solicitado no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'servicio' => $servicio,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'servicio' => null,
                'alertas' => [
                    'error' => ['Error al consultar el servicio en la base de datos.']
                ]
            ];
        }
    }

    /**
     * Valida y crea un nuevo servicio aceptando únicamente `nombre`, `precio` y `duracion_minutos`.
     * Ignora cualquier `id` enviado en `$datos`.
     */
    public function crear(array $datos): array
    {
        $servicio = new Servicio();
        $servicio->sincronizarEditable($datos);
        $servicio->id = null;

        $alertas = self::validarDatos($datos);
        if (!empty($alertas)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'id' => null,
                'servicio' => $servicio,
                'alertas' => $alertas
            ];
        }

        $servicio->nombre = trim((string)$datos['nombre']);
        $servicio->precio = self::normalizarPrecio((string)$datos['precio']);
        $servicio->duracion_minutos = self::normalizarDuracion($datos['duracion_minutos']);

        try {
            $insertId = $this->repository->create($servicio);
            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'id' => $insertId,
                'servicio' => $servicio,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'id' => null,
                'servicio' => $servicio,
                'alertas' => [
                    'error' => ['No fue posible guardar el servicio debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Valida y actualiza un servicio existente.
     * El `id` procede exclusivamente de `$idRaw` (identificador validado de la operación),
     * ignorando cualquier `id` presente en `$datos` (ej. `$_POST['id']`).
     * Soporta actualizaciones sin cambios en los valores como operación válida (`status => ok`).
     */
    public function actualizar($idRaw, array $datos): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'servicio' => null,
                'alertas' => [
                    'error' => ['El identificador del servicio no es válido.']
                ]
            ];
        }

        try {
            $existente = $this->repository->findById($id);
        } catch (PersistenceException $e) {
            $fallback = new Servicio(['id' => (string)$id]);
            $fallback->sincronizarEditable($datos);
            $fallback->id = (string)$id;
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'servicio' => $fallback,
                'alertas' => [
                    'error' => ['No fue posible consultar el servicio debido a un error de base de datos.']
                ]
            ];
        }

        if ($existente === null) {
            return [
                'status' => self::STATUS_NOT_FOUND,
                'resultado' => false,
                'servicio' => null,
                'alertas' => [
                    'error' => ['El servicio que intenta actualizar no existe.']
                ]
            ];
        }

        // Aplicar únicamente campos editables (`nombre`, `precio` y `duracion_minutos`), preservando el ID validado
        $existente->sincronizarEditable($datos);
        $existente->id = (string)$id;

        $alertas = self::validarDatos($datos);
        if (!empty($alertas)) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'servicio' => $existente,
                'alertas' => $alertas
            ];
        }

        $existente->nombre = trim((string)$datos['nombre']);
        $existente->precio = self::normalizarPrecio((string)$datos['precio']);
        $existente->duracion_minutos = self::normalizarDuracion($datos['duracion_minutos']);
        $existente->id = (string)$id;

        try {
            $actualizado = $this->repository->update($existente);
            if (!$actualizado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'servicio' => null,
                    'alertas' => [
                        'error' => ['El servicio que intenta actualizar no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'servicio' => $existente,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'servicio' => $existente,
                'alertas' => [
                    'error' => ['No fue posible actualizar el servicio debido a un error de base de datos.']
                ]
            ];
        }
    }

    /**
     * Elimina un servicio existente por su identificador validado.
     * Distingue ID inválido (`status => invalid`), registro inexistente (`status => not_found`),
     * eliminación exitosa (`status => ok`) y fallo SQL (`status => error`).
     */
    public function eliminar($idRaw): array
    {
        $id = $this->validarId($idRaw);
        if ($id === null) {
            return [
                'status' => self::STATUS_INVALID,
                'resultado' => false,
                'alertas' => [
                    'error' => ['El identificador del servicio no es válido.']
                ]
            ];
        }

        try {
            $eliminado = $this->repository->delete($id);
            if (!$eliminado) {
                return [
                    'status' => self::STATUS_NOT_FOUND,
                    'resultado' => false,
                    'alertas' => [
                        'error' => ['El servicio que intenta eliminar no existe.']
                    ]
                ];
            }

            return [
                'status' => self::STATUS_OK,
                'resultado' => true,
                'alertas' => []
            ];
        } catch (PersistenceException $e) {
            return [
                'status' => self::STATUS_ERROR,
                'resultado' => false,
                'alertas' => [
                    'error' => ['No fue posible eliminar el servicio debido a un error de base de datos.']
                ]
            ];
        }
    }
}
