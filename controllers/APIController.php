<?php

namespace Controllers;

use Model\Cita;
use Model\Servicio;
use Model\CitaServicio;
use Model\ActiveRecord;

class APIController
{
    public static function index()
    {
        $servicios = Servicio::all();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($servicios);
    }

    /**
     * Guarda una cita y sus servicios dentro de una transacción atómica.
     * Valida autenticación, CSRF, tipos, reglas de negocio en servidor y asigna la cita
     * estrictamente a la sesión autenticada (mitigando IDOR).
     */
    public static function guardar()
    {
        iniciar_sesion_segura();
        header('Content-Type: application/json; charset=utf-8');

        // 1. Verificación de Autenticación
        if (!isset($_SESSION['login']) || $_SESSION['login'] !== true || empty($_SESSION['id'])) {
            http_response_code(401);
            echo json_encode(['resultado' => false, 'error' => 'No autenticado']);
            detener_ejecucion(401);
            return;
        }

        // 2. Verificación de CSRF
        if (!validar_csrf()) {
            http_response_code(403);
            echo json_encode(['resultado' => false, 'error' => 'Token CSRF inválido o ausente']);
            detener_ejecucion(403);
            return;
        }

        // 3. Sanitización y Validación de Tipos en el Servidor
        $fecha = is_string($_POST['fecha'] ?? null) ? trim($_POST['fecha']) : '';
        $hora = is_string($_POST['hora'] ?? null) ? trim($_POST['hora']) : '';
        $serviciosRaw = is_string($_POST['servicios'] ?? null) ? trim($_POST['servicios']) : '';

        // Validar formato estricto de fecha (AAAA-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Formato de fecha inválido. Se requiere AAAA-MM-DD.']);
            detener_ejecucion(422);
            return;
        }

        $partesFecha = explode('-', $fecha);
        if (!checkdate((int)$partesFecha[1], (int)$partesFecha[2], (int)$partesFecha[0])) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'La fecha ingresada no corresponde a un día de calendario válido.']);
            detener_ejecucion(422);
            return;
        }

        // Validar que la fecha sea estrictamente futura (mínimo mañana, alineado con min del input y política comercial)
        $fechaHoy = date('Y-m-d');
        if ($fecha <= $fechaHoy) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'No se pueden agendar citas para el mismo día ni para fechas pasadas. La reserva debe ser con al menos un día de anticipación.']);
            detener_ejecucion(422);
            return;
        }

        // Validar exclusión de fines de semana (0 = Domingo, 6 = Sábado)
        $diaSemana = (int)date('w', strtotime($fecha));
        if (in_array($diaSemana, [0, 6], true)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'No se puede agendar citas en fines de semana (sábados o domingos).']);
            detener_ejecucion(422);
            return;
        }

        // Validar formato estricto de hora (HH:MM o HH:MM:SS)
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $hora)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Formato de hora inválido. Se requiere HH:MM.']);
            detener_ejecucion(422);
            return;
        }

        $partesHora = explode(':', $hora);
        $horaInt = (int)$partesHora[0];
        $minutosInt = (int)$partesHora[1];

        // Horario de atención: 10:00 a 18:00 horas inclusive (límite superior 18:00:00)
        if ($horaInt < 10 || $horaInt > 18 || ($horaInt === 18 && $minutosInt > 0)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'El horario de atención es de 10:00 a 18:00 horas.']);
            detener_ejecucion(422);
            return;
        }

        // Validar servicios seleccionados: enteros positivos y política explícita de desduplicación
        $partesServicios = array_filter(array_map('trim', explode(',', $serviciosRaw)), fn($s) => $s !== '');
        if (empty($partesServicios)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Debes seleccionar al menos un servicio']);
            detener_ejecucion(422);
            return;
        }

        $idServicios = [];
        foreach ($partesServicios as $p) {
            $idVal = filter_var($p, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($idVal === false) {
                http_response_code(422);
                echo json_encode(['resultado' => false, 'error' => 'Uno o más identificadores de servicio son inválidos. Deben ser enteros positivos.']);
                detener_ejecucion(422);
                return;
            }
            $idServicios[] = $idVal;
        }

        // Desduplicar servicios repetidos
        $idServicios = array_values(array_unique($idServicios));

        // Comprobar existencia real de los servicios en la base de datos
        foreach ($idServicios as $idServicio) {
            if (!Servicio::find($idServicio)) {
                http_response_code(422);
                echo json_encode(['resultado' => false, 'error' => 'Uno o más servicios seleccionados no existen o no son válidos']);
                detener_ejecucion(422);
                return;
            }
        }

        // 4. Construcción de Cita con Usuario forzado desde la SESIÓN (Nunca del POST)
        $cita = new Cita([
            'fecha' => $fecha,
            'hora' => $hora,
            'usuarioId' => (int)$_SESSION['id']
        ]);

        // 5. Persistencia Atómica con Transacción
        $db = ActiveRecord::getDB();
        if (!$db) {
            http_response_code(500);
            echo json_encode(['resultado' => false, 'error' => 'Servicio de base de datos no disponible']);
            detener_ejecucion(500);
            return;
        }

        $db->begin_transaction();

        try {
            $resultado = $cita->guardar();
            if (empty($resultado['resultado']) || empty($resultado['id'])) {
                throw new \RuntimeException("Error al registrar la cita principal");
            }

            $idCita = (int)$resultado['id'];

            foreach ($idServicios as $idServicio) {
                $citaServicio = new CitaServicio([
                    'citaId' => $idCita,
                    'servicioId' => $idServicio
                ]);
                $resultadoServicio = $citaServicio->guardar();
                if (empty($resultadoServicio['resultado'])) {
                    throw new \RuntimeException("Error al registrar el servicio asociado ID: " . $idServicio);
                }
            }

            if (!$db->commit()) {
                throw new \RuntimeException("Fallo al confirmar la transacción de la reserva");
            }

            http_response_code(200);
            // Conservar el contrato JSON esperado por src/js/app.js (resultado.resultado truthy)
            echo json_encode(['resultado' => $resultado]);
        } catch (\Throwable $e) {
            $db->rollback();
            http_response_code(500);
            echo json_encode(['resultado' => false, 'error' => 'No se pudo procesar la reserva. Operación cancelada.']);
        }
    }

    /**
     * Eliminación de cita con verificación de pertenencia (IDOR), autenticación y CSRF.
     */
    public static function eliminar()
    {
        iniciar_sesion_segura();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            exigir_csrf();
            isAuth();

            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                header('Location: /admin');
                detener_ejecucion(302);
                return;
            }

            $cita = Cita::find($id);
            if (!$cita) {
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion(302);
                return;
            }

            // Comprobación de pertenencia: Solo el dueño de la cita o un Administrador pueden eliminarla
            $esAdmin = isset($_SESSION['admin']) && (string)$_SESSION['admin'] === '1';
            $esPropietario = (string)$cita->usuarioId === (string)$_SESSION['id'];

            if (!$esAdmin && !$esPropietario) {
                http_response_code(403);
                if (es_peticion_json()) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['resultado' => false, 'error' => 'No tienes autorización para eliminar esta cita']);
                } else {
                    echo "Error 403: No tienes autorización para eliminar esta cita.";
                }
                detener_ejecucion(403);
                return;
            }

            $db = ActiveRecord::getDB();
            if (!$db) {
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion(302);
                return;
            }

            $db->begin_transaction();

            try {
                // Eliminar servicios asociados en citasservicios
                $stmt = $db->prepare("DELETE FROM citasservicios WHERE citaId = ?");
                if (!$stmt) {
                    throw new \RuntimeException("Fallo al preparar eliminación de servicios");
                }

                $stmt->bind_param('i', $id);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new \RuntimeException("Fallo al ejecutar eliminación de servicios");
                }
                $stmt->close();

                if (!$cita->eliminar()) {
                    throw new \RuntimeException("Fallo al eliminar registro de cita");
                }

                if (!$db->commit()) {
                    throw new \RuntimeException("Fallo al confirmar la transacción");
                }

                $destino = $_SERVER['HTTP_REFERER'] ?? ($esAdmin ? '/admin' : '/cita');
                header('Location: ' . $destino);
                detener_ejecucion(302);
                return;
            } catch (\Throwable $e) {
                $db->rollback();
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion(302);
                return;
            }
        }
    }
}