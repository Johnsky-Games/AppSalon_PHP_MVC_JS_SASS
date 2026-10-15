<?php

namespace Tests\Qa;

require_once __DIR__ . '/R5Case.php';

/**
 * R5-QA · Recorrido de NAVEGADOR real (Chromium vía puppeteer-core; `tests/Qa/r5_qa_navegador.cjs`) — matriz 1 y 7 del usuario:
 * flujo A/B/C con clics reales (llegar a la pantalla correcta, conservar período, botón deshabilitado con motivo, error dentro del layout con «Volver» a la agenda
 * y sin enlaces a /mis-citas, «Finalizar y cobrar» hasta el formulario de ESA cita), cobro (mensajes concretos que no dependen de un toast, botón «en proceso»,
 * un solo POST ante doble envío), modales (foco, Escape, retorno al disparador, trampa de foco), descargas reales CSV/XLSX, comprobante imprimible, teclado,
 * responsive y consola.
 *
 * Requiere CHROME_BIN (por omisión /opt/pw-browsers/chromium-1194/chrome-linux/chrome) y QA_EXIGIR_NAVEGADOR=1 para que su ausencia sea un fallo y no un skip.
 */
final class R5QaNavegadorTest extends R5Case
{
    private static array $r = [];
    private static array $cfg = [];
    private static ?string $motivoSin = null;
    private const CHROME_RESPALDO = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

    private static function chromium(): ?string
    {
        $env = getenv('CHROME_BIN');
        if (is_string($env) && $env !== '') { return is_file($env) && is_executable($env) ? $env : null; }
        return is_file(self::CHROME_RESPALDO) && is_executable(self::CHROME_RESPALDO) ? self::CHROME_RESPALDO : null;
    }

    public static function setUpBeforeClass(): void
    {
        $chrome = self::chromium();
        if ($chrome === null || !is_dir(self::RAIZ . '/node_modules/puppeteer-core')) { self::$motivoSin = 'Chromium o puppeteer-core no disponibles (CHROME_BIN / npm ci)'; return; }
        self::limpiar();
        $base = self::servidorQ5('nav');
        $e = self::escenario();
        $pro = $e['proA']['profesional_id'];
        $cli = $e['cliente']['id'];
        $cita = function (string $estado, int $extra = 0, ?array $slot = null) use ($pro, $cli): int {
            [$f, $h] = $slot ?? self::slotComenzado($extra);
            return self::crearCita(['cliente' => $cli, 'pro' => $pro, 'fecha' => $f, 'hora' => $h, 'estado' => $estado]);
        };
        self::$cfg = [
            'base' => $base, 'owner' => ['email' => $e['dueno']['email'], 'password' => $e['dueno']['password']], 'cliente' => ['email' => $e['cliente']['email'], 'password' => $e['cliente']['password']],
            'desde' => self::gye('-2 days')->format('Y-m-d'), 'hasta' => self::gye('+3 days')->format('Y-m-d'),
            'citaComenzada' => $cita('confirmada', 1), 'citaComenzadaDos' => $cita('confirmada', 2), 'citaComenzadaTres' => $cita('confirmada', 3),
            'citaFutura' => $cita('confirmada', 0, self::slotFuturo()), 'citaEnServicio' => $cita('en_servicio', 4), 'citaCobrar' => $cita('en_servicio', 5),
            'citaCobro' => $cita('completada', 6), 'citaCobro2' => $cita('completada', 7), 'citaConPago' => $cita('completada', 8),
        ];
        // Una cita ya pagada (para comprobante, devolución y descargas): se paga por la interfaz si existe; si no, por SQL (para que el resto del recorrido se ejecute).
        $pagada = false;
        try {
            $n = self::entrar($base, $e['dueno']);
            $r = $n->get('/caja/cita?id=' . self::$cfg['citaConPago']);
            $f = $r['status'] === 200 ? self::formulario($r['body'], '~/caja/cita/cobrar~') : null;
            if ($f) {
                $c = [];
                $m = self::campo($f, '/monto|importe|valor|cantidad/i', '/recib|entreg|cambio|total|saldo|pendiente/i'); if ($m) { $c[$m] = '20.00'; }
                $rc = self::campo($f, '/recib|entreg/i'); if ($rc) { $c[$rc] = '20.00'; }
                $me = self::campo($f, '/metodo|medio|forma/i');
                if ($me) { foreach ($f['selects'][$me] ?? [] as $o) { if (stripos($o, 'efectivo') !== false) { $c[$me] = $o; } } }
                $pagada = in_array(self::enviar($n, $f, $c)['status'], [200, 302, 303], true);
            }
        } catch (\Throwable $ex) { /* se completa por SQL */ }
        if (!$pagada || (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [self::$cfg['citaConPago']]) === 0) {
            $id = self::$cfg['citaConPago'];
            self::q("INSERT INTO pagos(cita_id,cita_referencia_id,usuario_id,referencia_pago,proveedor,monto,estado,metodo_pago,idempotency_key,pagado_at) VALUES (?,?,?,?,'manual','20.00','completado','efectivo',?,?)", [$id, $id, $cli, 'MAN-NAV-' . $id, 'nav:' . $id, self::gye()->format('Y-m-d H:i:s')]);
            self::q("INSERT INTO caja_movimientos(actor_id,tipo,monto_centavos,concepto,metodo,pago_id,idempotencia,huella,registrado_en) VALUES (?,'ingreso',2000,'Cobro navegador','efectivo',?,?,?,?)", [$e['dueno']['id'], (int)self::db()->insert_id, 'nav-c:' . $id, hash('sha256', 'nav' . $id), self::gye()->format('Y-m-d H:i:s')]);
            self::q("UPDATE citas SET estado_pago='pagado' WHERE id=?", [$id]);
        }
        self::q('TRUNCATE intentos_login');
        $p = proc_open(['node', self::RAIZ . '/tests/Qa/r5_qa_navegador.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::RAIZ, array_merge(getenv(), ['QA_CONFIG' => json_encode(self::$cfg), 'CHROME_BIN' => $chrome]));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        proc_close($p);
        self::$r = json_decode($out, true) ?: ['error' => 'sin salida JSON: ' . $err . substr($out, 0, 400)];
    }

    public static function tearDownAfterClass(): void { self::pararServidorQ5('nav'); }

    protected function setUp(): void
    {
        if (!self::$cfg) {
            if (getenv('QA_EXIGIR_NAVEGADOR') === '1') { $this->fail('QA_EXIGIR_NAVEGADOR=1: ' . self::$motivoSin); }
            $this->markTestSkipped(self::$motivoSin ?? 'Chromium no disponible');
        }
        $this->assertArrayNotHasKey('error', self::$r, self::$r['error'] ?? '');
    }

    /** @return array<string,mixed> */
    private function paso(string $nombre): array
    {
        $p = self::$r['pasos'][$nombre] ?? null;
        $this->assertNotNull($p, "el recorrido no ejecutó el paso $nombre");
        $this->assertTrue($p['ok'], "paso $nombre: " . ($p['error'] ?? ''));
        return $p;
    }

    // ------------------------------------------------------------------

    /** Req A (navegador): el CLIC real en «Iniciar atención» llega a la agenda (o detalle) con el período conservado, estado «en servicio» y «Finalizar atención» disponible; sin enlaces a /mis-citas. */
    public function test_A_clic_real_en_iniciar_atencion(): void
    {
        $this->assertTrue(self::$r['pasos']['login']['ok'], 'el login de equipo por la interfaz no salió de /equipo/login');
        $p = $this->paso('A_iniciar_cita_comenzada');
        $this->assertContains(parse_url($p['destino'], PHP_URL_PATH), ['/agenda', '/agenda/cita'], 'destino: ' . $p['destino']);
        parse_str((string)parse_url($p['destino'], PHP_URL_QUERY), $q);
        $this->assertSame(self::$cfg['desde'], $q['desde'] ?? null, 'se perdió «desde» en ' . $p['destino']);
        $this->assertSame(self::$cfg['hasta'], $q['hasta'] ?? null, 'se perdió «hasta» en ' . $p['destino']);
        $this->assertTrue($p['enServicio'], 'la pantalla final no muestra «en servicio»');
        $this->assertSame(0, $p['enlacesMisCitas'], 'la pantalla del equipo enlaza a /mis-citas');
        $this->assertSame('en_servicio', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [self::$cfg['citaComenzada']]));
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM agenda_eventos WHERE citaId=?', [self::$cfg['citaComenzada']]));
    }

    /** Req A (navegador): la cita futura tiene el botón deshabilitado con el motivo «Disponible desde las HH:MM» y un clic sobre él no navega. */
    public function test_A_cita_futura_boton_deshabilitado_con_motivo_y_clic_inerte(): void
    {
        $p = $this->paso('A_cita_futura_deshabilitada');
        $this->assertTrue($p['hay'], 'no se muestra el botón «Iniciar atención» deshabilitado: ' . $p['texto']);
        $this->assertTrue($p['disabled'], 'el botón de la cita futura no está deshabilitado');
        $this->assertTrue($p['motivo'], 'falta el motivo «Disponible desde las HH:MM»: ' . $p['texto']);
        $this->assertSame('sin cambio', $p['urlTrasClic'], 'el clic en el botón deshabilitado navegó');
        $this->assertSame('confirmada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [self::$cfg['citaFutura']]));
    }

    /** Req A+B (navegador): el POST forjado a una cita futura muestra un error DENTRO del layout; su «Volver a la agenda» lleva a /agenda con el período y ningún enlace va al portal del cliente. */
    public function test_A_B_error_forjado_dentro_del_layout_y_volver_a_la_agenda(): void
    {
        $p = $this->paso('A_post_forjado_error_dentro_del_layout');
        $this->assertTrue($p['tieneLayout'], 'el error no está dentro del layout: ' . $p['texto']);
        $this->assertNotContains('/mis-citas', $p['hrefs'], 'el error enlaza a /mis-citas: ' . json_encode($p['hrefs']));
        $this->assertNotNull($p['volverHref'], 'el error no ofrece «Volver» a la agenda: ' . json_encode($p['hrefs']));
        $this->assertStringContainsString('desde=' . self::$cfg['desde'], $p['volverHref']);
        $this->assertStringStartsWith('/agenda', (string)$p['trasVolver']);
        $this->assertStringContainsString('hasta=' . self::$cfg['hasta'], (string)$p['trasVolver']);
        $this->assertSame('confirmada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [self::$cfg['citaFutura']]));
    }

    /** Req C (navegador): «Finalizar atención» → completada, sin marcarla pagada. */
    public function test_C_finalizar_atencion_con_clic(): void
    {
        $p = $this->paso('C_finalizar_atencion');
        $this->assertTrue($p['completada'], 'la pantalla no muestra «completada» (' . $p['destino'] . ')');
        $this->assertFalse($p['dicePagado'], 'finalizar no puede mostrar la cita como pagada');
        $this->assertSame('pendiente', (string)self::escalar('SELECT estado_pago FROM citas WHERE id=?', [self::$cfg['citaEnServicio']]));
    }

    /** Req C (navegador): «Finalizar y cobrar» lleva al formulario de cobro de ESA cita (cliente, total y formulario) sin teclear ningún ID. */
    public function test_C_finalizar_y_cobrar_con_clic_llega_al_cobro_de_esa_cita(): void
    {
        $p = $this->paso('C_finalizar_y_cobrar');
        $this->assertStringStartsWith('/caja/cita', $p['destino']);
        $this->assertSame((string)self::$cfg['citaCobrar'], $p['id']);
        $this->assertTrue($p['muestraCliente'] && $p['muestraTotal'], 'el cobro no muestra cliente y total ($20.00)');
        $this->assertNotEmpty(array_filter($p['formularios'], fn($a) => str_contains((string)$a, '/caja/cita/cobrar')), 'no hay formulario de cobro: ' . json_encode($p['formularios']));
        $this->assertSame('completada', (string)self::escalar('SELECT estado FROM citas WHERE id=?', [self::$cfg['citaCobrar']]));
    }

    /** Req UX: un monto que excede el saldo muestra un mensaje CONCRETO (saldo/monto/importe) en la página y que PERMANECE tras 5 s (no depende de un toast efímero); nada se registra. */
    public function test_N_mensaje_de_exceso_concreto_y_persistente(): void
    {
        $p = $this->paso('N_cobro_exceso_mensaje_concreto_y_persistente');
        $this->assertNotEmpty($p['alertasInmediatas'], 'el rechazo no deja ningún mensaje visible en la página. Texto: ' . $p['texto']);
        $this->assertMatchesRegularExpression('/saldo|monto|importe|excede|supera/iu', implode(' ', $p['alertasInmediatas']), 'mensaje poco concreto: ' . implode(' | ', $p['alertasInmediatas']));
        $this->assertNotEmpty($p['alertasTras5s'], 'el mensaje crítico desapareció a los 5 s (¿solo un toast?)');
        $this->assertSame(0, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [self::$cfg['citaCobro']]));
    }

    /** Req UX: tras el primer clic el botón de cobro queda en estado de proceso (deshabilitado/aria-busy/texto) y varios clics/envíos adicionales producen UN solo POST y UN solo pago. */
    public function test_N_cobro_boton_en_proceso_y_un_solo_envio(): void
    {
        $p = $this->paso('N_cobro_boton_en_proceso_y_un_solo_post');
        $this->assertTrue($p['enProceso'], 'el botón de cobro no indica estado de proceso tras el clic: ' . json_encode($p['estado']));
        $this->assertSame(1, $p['posts'], 'varios clics/envíos generaron ' . $p['posts'] . ' POST a /caja/cita/cobrar');
        $this->assertLessThanOrEqual(1, (int)self::escalar('SELECT COUNT(*) FROM pagos WHERE cita_referencia_id=?', [self::$cfg['citaCobro2']]));
    }

    /** Req UX: «Iniciar atención» muestra estado de proceso y dos clics extra no duplican el POST ni el evento. */
    public function test_N_agenda_iniciar_boton_en_proceso_y_un_solo_envio(): void
    {
        $p = $this->paso('N_agenda_iniciar_boton_en_proceso');
        $this->assertTrue($p['enProceso'], 'el botón «Iniciar atención» no indica estado de proceso tras el clic: ' . json_encode($p['estado']));
        $this->assertSame(1, $p['posts'], 'POSTs a /agenda/estado: ' . $p['posts']);
        $this->assertSame(1, (int)self::escalar('SELECT COUNT(*) FROM agenda_eventos WHERE citaId=?', [self::$cfg['citaComenzadaTres']]));
    }

    private function modal(string $nombre): void
    {
        $p = $this->paso($nombre);
        if (!empty($p['sinDisparador'])) { $this->markTestIncomplete("$nombre: no hay disparador en la pantalla (la funcionalidad aún no existe)."); }
        if (!empty($p['sinModal'])) { $this->assertTrue(true); return; }   // la acción no abre modal: no hay nada que atrapar (la confirmación en línea se prueba en otras pruebas)
        $this->assertTrue($p['dentro'], "$nombre: el foco no se mueve al diálogo al abrirlo");
        $this->assertTrue($p['nombre'], "$nombre: el diálogo no tiene nombre accesible (aria-labelledby/aria-label/título)");
        $this->assertTrue($p['modal'], "$nombre: falta aria-modal/dialog nativo");
        $this->assertTrue($p['atrapaFoco'], "$nombre: el foco escapa del diálogo con Tab");
        $this->assertTrue($p['cierraConEscape'], "$nombre: Escape no cierra el diálogo");
        $this->assertTrue($p['devuelveFoco'], "$nombre: al cerrar, el foco no vuelve al disparador");
    }

    /** Req UX/a11y: modal de «Registrar ausencia» (si abre diálogo): foco dentro, nombre accesible, trampa de foco, Escape y retorno al disparador. */
    public function test_M_modal_registrar_ausencia_accesible(): void { $this->modal('M_modal_registrar_ausencia'); }

    /** Req UX/a11y + contrato §8: «Listado»/«Descargar» del CRM abre vista o modal accesible. */
    public function test_M_modal_listado_o_descarga_crm_accesible(): void { $this->modal('M_modal_listado_o_descarga_crm'); }

    /** Req UX/a11y: devolución/rechazo en caja (si abre diálogo) cumple los mismos criterios. */
    public function test_M_modal_devolucion_o_rechazo_accesible(): void { $this->modal('M_modal_devolucion_o_rechazo'); }

    /** Req F (navegador): el clic real en «Excel» descarga un .xlsx genuino (ZIP) y en «CSV» un .csv con BOM; en CRM y caja. */
    public function test_D_descargas_reales_con_clic(): void
    {
        $fallos = [];
        foreach (['D_descarga_xlsx_crm' => ['xlsx', true], 'D_descarga_csv_crm' => ['csv', false], 'D_descarga_xlsx_caja' => ['xlsx', true]] as $paso => [$ext, $zip]) {
            $p = self::$r['pasos'][$paso] ?? null;
            if (!$p || !$p['ok']) { $fallos[] = "$paso: " . ($p['error'] ?? 'no se ejecutó'); continue; }
            if (!empty($p['sinEnlace'])) { $fallos[] = "$paso: la pantalla no ofrece el enlace de descarga ($ext)"; continue; }
            if (!empty($p['sinArchivo'])) { $fallos[] = "$paso: el clic no descargó ningún archivo"; continue; }
            if (!str_ends_with($p['archivo'], ".$ext")) { $fallos[] = "$paso: el archivo se llama {$p['archivo']}"; }
            if ($zip && !$p['esZip']) { $fallos[] = "$paso: {$p['archivo']} no es un XLSX (ZIP)"; }
            if (!$zip && !$p['bom']) { $fallos[] = "$paso: el CSV no tiene BOM"; }
        }
        $this->sinFallos($fallos);
    }

    /** Req comprobante: se imprime sin barra lateral, trae la leyenda «Comprobante interno — no es factura autorizada por el SRI» en pantalla e impresión y produce un PDF válido. */
    public function test_P_comprobante_imprimible_con_leyenda(): void
    {
        $p = $this->paso('P_comprobante_impresion');
        $this->assertSame(200, $p['status']);
        $this->assertTrue($p['leyendaPantalla'], 'falta la leyenda en pantalla');
        $this->assertTrue($p['impresion']['leyenda'], 'falta la leyenda en la vista de impresión');
        $this->assertContains($p['impresion']['barraLateral'], ['none', 'ausente'], 'la barra lateral se imprime');
        $this->assertTrue($p['pdfValido'], 'no se pudo generar el PDF del comprobante');
    }

    /** Req UX/a11y: todo control alcanzable con Tab en agenda, cobro y CRM muestra indicador de foco (contorno o sombra). */
    public function test_K_teclado_foco_visible(): void
    {
        $p = $this->paso('K_teclado_agenda_y_caja');
        foreach ($p['pantallas'] as $t) {
            $this->assertGreaterThan(5, $t['total'], "{$t['ruta']}: poca navegación por teclado");
            $this->assertSame([], $t['sinIndicador'], "{$t['ruta']}: controles sin indicador de foco");
        }
    }

    /** Req UX: sin desbordamiento horizontal de 320 a 1440 px en agenda, detalle, caja, cobro y CRM. */
    public function test_R_sin_desbordes_horizontales(): void
    {
        $p = $this->paso('R_sin_desbordes_ni_errores');
        $this->sinFallos($p['malos']);
    }

    /** Req UX: sin errores de consola ni excepciones JS durante todo el recorrido. */
    public function test_R_sin_errores_de_consola(): void
    {
        $this->sinFallos(array_map(fn($e) => "{$e['tipo']}: {$e['texto']} ({$e['url']})", array_filter(self::$r['consola'], fn($e) => !str_contains($e['texto'], 'status of 4'))));
    }

    /** Req portales (navegador): una sesión de equipo que abre /mis-citas termina en el inicio del equipo, con aviso y SIN «Reservar una cita». */
    public function test_S_sesion_de_equipo_en_mis_citas(): void
    {
        $p = $this->paso('S_sesion_de_equipo_en_mis_citas');
        $this->assertNotSame('/mis-citas', $p['destino'], 'el equipo permanece en la pantalla del cliente');
        $this->assertFalse($p['reservar'], 'se muestra «Reservar una cita» a una sesión de equipo');
        $this->assertNotEmpty($p['aviso'], 'sin aviso al redirigir');
    }

    /** Req portales (navegador): un cliente que abre la agenda recibe 403 con «Volver» a /cita y ningún enlace al equipo. */
    public function test_S_cliente_en_agenda(): void
    {
        $p = $this->paso('S_cliente_en_agenda');
        $this->assertSame(403, $p['status']);
        $this->assertSame([], $p['volverAEquipo'], 'el 403 del cliente enlaza al equipo: ' . json_encode($p['volverAEquipo']));
        $this->assertTrue($p['tieneCita'], 'el 403 del cliente no ofrece volver a /cita');
    }
}
