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
     * Valida autenticación, CSRF, reglas de negocio en servidor y asigna la cita
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
            detener_ejecucion();
            return;
        }

        // 2. Verificación de CSRF
        if (!validar_csrf()) {
            http_response_code(403);
            echo json_encode(['resultado' => false, 'error' => 'Token CSRF inválido o ausente']);
            detener_ejecucion();
            return;
        }

        // 3. Sanitización y Validación de Entradas en el Servidor
        $fecha = trim($_POST['fecha'] ?? '');
        $hora = trim($_POST['hora'] ?? '');
        $serviciosRaw = trim($_POST['servicios'] ?? '');

        // Validar formato de fecha (YYYY-MM-DD)
        $partesFecha = explode('-', $fecha);
        if (count($partesFecha) !== 3 || !checkdate((int)$partesFecha[1], (int)$partesFecha[2], (int)$partesFecha[0])) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Fecha no válida']);
            detener_ejecucion();
            return;
        }

        // Validar que la fecha no sea anterior a hoy y no sea fin de semana (regla existente en app.js)
        $timestampFecha = strtotime($fecha);
        $diaSemana = (int)date('w', $timestampFecha); // 0 = Domingo, 6 = Sábado
        if ($timestampFecha < strtotime(date('Y-m-d')) || in_array($diaSemana, [0, 6], true)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'No se puede agendar citas en fines de semana o fechas pasadas']);
            detener_ejecucion();
            return;
        }

        // Validar formato y rango de hora (10:00 a 18:00)
        $partesHora = explode(':', $hora);
        if (count($partesHora) < 2 || !is_numeric($partesHora[0])) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Hora no válida']);
            detener_ejecucion();
            return;
        }
        $horaNumero = (int)$partesHora[0];
        if ($horaNumero < 10 || $horaNumero > 18) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'La hora debe estar comprendida entre las 10:00 y las 18:00']);
            detener_ejecucion();
            return;
        }

        // Validar servicios seleccionados
        $idServicios = array_filter(array_map('trim', explode(',', $serviciosRaw)));
        if (empty($idServicios)) {
            http_response_code(422);
            echo json_encode(['resultado' => false, 'error' => 'Debes seleccionar al menos un servicio']);
            detener_ejecucion();
            return;
        }

        // Comprobar existencia real de los servicios en la base de datos
        foreach ($idServicios as $idServicio) {
            if (!is_numeric($idServicio) || !Servicio::find($idServicio)) {
                http_response_code(422);
                echo json_encode(['resultado' => false, 'error' => 'Uno o más servicios seleccionados no son válidos']);
                detener_ejecucion();
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
        if ($db) {
            $db->begin_transaction();
        }

        try {
            $resultado = $cita->guardar();
            if (empty($resultado['resultado']) || empty($resultado['id'])) {
                throw new \Exception("Error al registrar la cita");
            }

            $idCita = $resultado['id'];

            foreach ($idServicios as $idServicio) {
                $citaServicio = new CitaServicio([
                    'citaId' => $idCita,
                    'servicioId' => (int)$idServicio
                ]);
                $resultadoServicio = $citaServicio->guardar();
                if (empty($resultadoServicio['resultado'])) {
                    throw new \Exception("Error al registrar el servicio asociado ID: " . $idServicio);
                }
            }

            if ($db) {
                $db->commit();
            }

            http_response_code(200);
            // Conservar el contrato JSON esperado por src/js/app.js (resultado.resultado truthy)
            echo json_encode(['resultado' => $resultado]);
        } catch (\Throwable $e) {
            if ($db) {
                $db->rollback();
            }
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

            $id = $_POST['id'] ?? null;
            if (!is_numeric($id)) {
                header('Location: /admin');
                detener_ejecucion();
                return;
            }

            $cita = Cita::find($id);
            if (!$cita) {
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion();
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
                detener_ejecucion();
                return;
            }

            $db = ActiveRecord::getDB();
            if ($db) {
                $db->begin_transaction();
            }

            try {
                // Eliminar servicios asociados en citasservicios
                if ($db) {
                    $stmt = $db->prepare("DELETE FROM citasservicios WHERE citaId = ?");
                    if ($stmt) {
                        $citaIdInt = (int)$id;
                        $stmt->bind_param('i', $citaIdInt);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                $cita->eliminar();

                if ($db) {
                    $db->commit();
                }

                $destino = $_SERVER['HTTP_REFERER'] ?? ($esAdmin ? '/admin' : '/cita');
                header('Location: ' . $destino);
                detener_ejecucion();
                return;
            } catch (\Throwable $e) {
                if ($db) {
                    $db->rollback();
                }
                header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/admin'));
                detener_ejecucion();
                return;
            }
        }
    }
}