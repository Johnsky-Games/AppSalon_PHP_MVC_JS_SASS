<?php

namespace Classes;

use Model\ActiveRecord;
use Repositories\CrecimientoRepository;

/**
 * Plantilla común de correos: HTML con tablas e inline CSS y una versión de texto equivalente.
 * No depende de imágenes, JavaScript ni de las hojas de estilo de la aplicación.
 */
class EmailTemplate
{
    private array $negocio;

    /** Una configuración explícita facilita pruebas y vistas previas sin modificar el negocio. */
    public function __construct(?array $ajustes = null)
    {
        if ($ajustes === null) {
            $ajustes = [];
            try {
                $db = ActiveRecord::getDB();
                if ($db instanceof \mysqli) {
                    $ajustes = (new CrecimientoRepository($db))->ajustes();
                }
            } catch (\Throwable $e) {
                // Confirmación/recuperación siguen disponibles si faltan los ajustes del negocio.
                $ajustes = [];
            }
        }

        $this->negocio = [
            'nombre' => trim((string)($ajustes['nombre_comercial'] ?? '')) ?: 'App Salón',
            'contacto' => trim((string)($ajustes['contacto'] ?? '')),
            'telefono' => trim((string)($ajustes['telefono'] ?? '')),
            'direccion' => trim((string)($ajustes['direccion'] ?? '')),
            'email' => trim((string)($ajustes['email_contacto'] ?? '')),
        ];
        if (filter_var($this->negocio['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->negocio['email'] = '';
        }
    }

    private static function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Conserva query/tokens pero nunca permite esquemas ejecutables ni atributos inyectados. */
    public static function urlSegura(string $url): string
    {
        $url = trim($url);
        if (
            $url === ''
            || preg_match('/[\x00-\x20\x7F<>"\'`]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return Email::resolverAppUrl();
        }
        $partes = parse_url($url);
        if (
            !is_array($partes)
            || !in_array(strtolower((string)($partes['scheme'] ?? '')), ['http', 'https'], true)
            || empty($partes['host'])
            || isset($partes['user'])
            || isset($partes['pass'])
        ) {
            return Email::resolverAppUrl();
        }
        return $url;
    }

    /**
     * Todo el contenido dinámico llega como texto, nunca como HTML confiado al llamador.
     * @param array<string, mixed> $mensaje
     * @return array{html: string, texto: string}
     */
    public function render(array $mensaje): array
    {
        $nombreNegocio = $this->negocio['nombre'];
        $titulo = (string)($mensaje['titulo'] ?? 'Información de tu cuenta');
        $categoria = (string)($mensaje['categoria'] ?? 'TU SALÓN');
        $preheader = (string)($mensaje['preheader'] ?? $titulo);
        $nombreCliente = trim((string)($mensaje['nombre'] ?? '')) ?: 'Cliente';
        $parrafos = is_array($mensaje['parrafos'] ?? null) ? $mensaje['parrafos'] : [];
        $detalles = is_array($mensaje['detalles'] ?? null) ? $mensaje['detalles'] : [];
        $servicios = is_array($mensaje['servicios'] ?? null) ? $mensaje['servicios'] : [];
        $nota = trim((string)($mensaje['nota'] ?? ''));
        $enlace = self::urlSegura((string)($mensaje['enlace'] ?? Email::resolverAppUrl()));
        $boton = (string)($mensaje['boton'] ?? 'Abrir mi cuenta');
        // Enlace de marca/«Visitar el sitio»: los correos del equipo llevan al acceso del EQUIPO, nunca al del cliente (ronda 5).
        $sitio = Email::resolverAppUrl() . (($mensaje['sitio_ruta'] ?? '') === '/equipo/login' ? '/equipo/login' : '');

        $htmlParrafos = '';
        $texto = [$nombreNegocio, $categoria, $titulo, '', 'Hola ' . $nombreCliente . ',', ''];
        foreach ($parrafos as $parrafo) {
            $parrafo = (string)$parrafo;
            $htmlParrafos .= '<p style="margin:0 0 18px;font-size:16px;line-height:26px;color:#435650;">' . self::escapar($parrafo) . '</p>';
            $texto[] = $parrafo;
            $texto[] = '';
        }

        $htmlDetalles = '';
        if ($detalles !== []) {
            $htmlDetalles = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;border:1px solid #dce5df;border-radius:12px;background-color:#f7faf6;margin:6px 0 24px;"><tr><td style="padding:18px 20px;">';
            foreach ($detalles as $etiqueta => $valor) {
                $valor = (string)$valor;
                $htmlDetalles .= '<p style="margin:0 0 12px;font-size:15px;line-height:24px;overflow-wrap:anywhere;word-break:break-word;"><span style="display:block;font-size:12px;font-weight:bold;color:#54685f;">' . self::escapar((string)$etiqueta) . '</span><strong style="font-size:16px;color:#18343e;">' . self::escapar($valor) . '</strong></p>';
                $texto[] = $etiqueta . ': ' . $valor;
            }
            $htmlDetalles .= '</td></tr></table>';
            $texto[] = '';
        }

        $htmlServicios = '';
        if ($servicios !== []) {
            $etiquetaServicios = (string)($mensaje['etiqueta_servicios'] ?? 'Servicios de tu reserva');
            $htmlServicios = '<h2 style="margin:0 0 12px;font-size:18px;line-height:26px;color:#18343e;">' . self::escapar($etiquetaServicios) . '</h2><ul style="margin:0 0 24px;padding-left:20px;color:#435650;font-size:15px;line-height:25px;">';
            $texto[] = $etiquetaServicios . ':';
            foreach ($servicios as $servicio) {
                if (!is_array($servicio)) {
                    continue;
                }
                $nombreServicio = (string)($servicio['nombre'] ?? 'Servicio');
                $duracion = (int)($servicio['duracion_minutos'] ?? 0);
                $detalleServicio = $nombreServicio . ($duracion > 0 ? ' (' . $duracion . ' min)' : '');
                $precioServicio = '$' . (string)($servicio['precio'] ?? '0.00');
                $htmlServicios .= '<li style="padding:6px 0;overflow-wrap:anywhere;word-break:break-word;">' . self::escapar($detalleServicio) . '<br><strong style="color:#18343e;">' . self::escapar($precioServicio) . '</strong></li>';
                $texto[] = '- ' . $detalleServicio . ' — ' . $precioServicio;
            }
            $htmlServicios .= '</ul>';
            $texto[] = '';
        }

        $htmlTotal = '';
        if (isset($mensaje['total'])) {
            $etiquetaTotal = (string)($mensaje['etiqueta_total'] ?? 'Total de tu reserva');
            $total = (string)$mensaje['total'];
            $htmlTotal = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;table-layout:fixed;margin:0 0 26px;background-color:#f4efe3;border-radius:10px;"><tr><td style="padding:16px 20px;"><span style="display:block;font-size:13px;line-height:22px;color:#435650;">' . self::escapar($etiquetaTotal) . '</span><strong style="font-size:26px;line-height:34px;color:#18343e;overflow-wrap:anywhere;">' . self::escapar($total) . '</strong></td></tr></table>';
            $texto[] = $etiquetaTotal . ': ' . $total;
            $texto[] = '';
        }

        $htmlNota = $nota !== '' ? '<p style="margin:24px 0 0;padding:14px 16px;border-left:3px solid #b5cabc;background-color:#f7faf6;font-size:13px;line-height:22px;color:#52665d;">' . self::escapar($nota) . '</p>' : '';
        $texto[] = $boton . ': ' . $enlace;
        if ($nota !== '') {
            $texto[] = '';
            $texto[] = $nota;
        }

        $htmlContacto = '';
        $texto[] = '';
        $texto[] = $nombreNegocio;
        foreach (['contacto' => 'Contacto', 'telefono' => 'Teléfono', 'direccion' => 'Dirección', 'email' => 'Correo'] as $clave => $etiqueta) {
            $valor = $this->negocio[$clave];
            if ($valor === '') {
                continue;
            }
            $texto[] = $etiqueta . ': ' . $valor;
            $valorHtml = self::escapar($valor);
            if ($clave === 'email') {
                $valorHtml = '<a href="mailto:' . self::escapar($valor) . '" style="color:#16766c;text-decoration:underline;">' . $valorHtml . '</a>';
            }
            $htmlContacto .= '<p style="margin:5px 0;font-size:13px;line-height:21px;color:#52665d;overflow-wrap:anywhere;word-break:break-word;"><strong>' . $etiqueta . ':</strong> ' . $valorHtml . '</p>';
        }
        $aviso = (string)($mensaje['aviso'] ?? 'Este mensaje contiene información sobre tu cuenta o tu reserva.');
        $texto[] = 'Sitio: ' . $sitio;
        $texto[] = '';
        $texto[] = $aviso;

        $eTitulo = self::escapar($titulo);
        $eNombreNegocio = self::escapar($nombreNegocio);
        $eCategoria = self::escapar($categoria);
        $ePreheader = self::escapar($preheader);
        $eCliente = self::escapar($nombreCliente);
        $eEnlace = self::escapar($enlace);
        $eBoton = self::escapar($boton);
        $eSitio = self::escapar($sitio);
        $eAviso = self::escapar($aviso);

        $html = <<<HTML
<!doctype html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{$eTitulo}</title>
<style>
body,table,td,a {-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
table,td {mso-table-lspace:0pt;mso-table-rspace:0pt;}
@media only screen and (max-width:620px) {.email-outer{padding:12px 8px!important;}.email-pad{padding:24px 20px!important;}.email-title{font-size:26px!important;line-height:34px!important;}.email-footer{padding:20px!important;}.email-button{width:100%!important;}.email-button a{display:block!important;text-align:center!important;}}
</style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#f3f1e9;font-family:Arial,Helvetica,sans-serif;color:#18343e;">
<div aria-hidden="true" style="display:none;font-size:1px;line-height:1px;color:#f3f1e9;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{$ePreheader}</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f3f1e9" style="width:100%;table-layout:fixed;background-color:#f3f1e9;">
<tr><td align="center" class="email-outer" style="padding:36px 16px;">
<!--[if mso]><table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;table-layout:fixed;background-color:#ffffff;border:1px solid #dce5df;border-radius:20px;">
<tr><td class="email-pad" bgcolor="#123b42" style="padding:26px 32px;background-color:#123b42;border-radius:20px 20px 0 0;overflow-wrap:anywhere;word-break:break-word;">
<a href="{$eSitio}" style="font-size:22px;line-height:30px;font-weight:bold;text-decoration:none;color:#ffffff;">{$eNombreNegocio}</a>
<p style="margin:4px 0 0;font-size:13px;line-height:20px;color:#d4e8df;">Tu cuenta. Tus reservas. Tu espacio.</p>
</td></tr>
<tr><td class="email-pad" bgcolor="#f4efe3" style="padding:30px 32px;background-color:#f4efe3;overflow-wrap:anywhere;word-break:break-word;">
<p style="margin:0 0 10px;font-size:12px;line-height:20px;letter-spacing:1px;font-weight:bold;color:#16766c;">{$eCategoria}</p>
<h1 class="email-title" style="margin:0;font-size:30px;line-height:39px;letter-spacing:-0.5px;font-weight:bold;color:#18343e;">{$eTitulo}</h1>
</td></tr>
<tr><td class="email-pad" style="padding:30px 32px;overflow-wrap:anywhere;word-break:break-word;">
<p style="margin:0 0 18px;font-size:17px;line-height:27px;color:#18343e;">Hola <strong>{$eCliente}</strong>,</p>
{$htmlParrafos}{$htmlDetalles}{$htmlServicios}{$htmlTotal}
<table class="email-button" role="presentation" cellspacing="0" cellpadding="0" border="0" style="max-width:100%;margin:4px 0 0;"><tr><td align="center" bgcolor="#16766c" style="border-radius:8px;background-color:#16766c;mso-padding-alt:16px 24px;">
<a href='{$eEnlace}' style="display:inline-block;padding:16px 24px;border:1px solid #16766c;border-radius:8px;background-color:#16766c;color:#ffffff;font-size:16px;line-height:22px;font-weight:bold;text-decoration:none;mso-padding-alt:0;">{$eBoton}</a>
</td></tr></table>
<p style="margin:18px 0 6px;font-size:12px;line-height:20px;color:#52665d;">Si el botón no abre, copia este enlace en tu navegador:</p>
<p style="margin:0;font-size:12px;line-height:20px;overflow-wrap:anywhere;word-break:break-all;"><a href='{$eEnlace}' style="color:#16766c;text-decoration:underline;word-break:break-all;">{$eEnlace}</a></p>
{$htmlNota}
</td></tr>
<tr><td class="email-footer" bgcolor="#f7faf6" style="padding:24px 32px;border-top:1px solid #dce5df;background-color:#f7faf6;border-radius:0 0 20px 20px;overflow-wrap:anywhere;word-break:break-word;">
<p style="margin:0 0 10px;font-size:15px;line-height:24px;font-weight:bold;color:#18343e;">{$eNombreNegocio}</p>
{$htmlContacto}
<p style="margin:12px 0 0;font-size:13px;line-height:22px;"><a href="{$eSitio}" style="color:#16766c;text-decoration:underline;">Visitar el sitio</a></p>
<p style="margin:18px 0 0;font-size:12px;line-height:20px;color:#52665d;">{$eAviso}</p>
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr></table>
</body>
</html>
HTML;

        return ['html' => $html, 'texto' => implode("\n", $texto)];
    }

    /** Alternativa para consumidores que entregan HTML sin una versión de texto explícita. */
    public static function textoDesdeHtml(string $html): string
    {
        $html = preg_replace('/<(head|style|script)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<div\b[^>]*aria-hidden=["\']true["\'][^>]*>.*?<\/div>/is', '', $html) ?? $html;
        $html = preg_replace('/<br\s*\/?\s*>|<\/(?:p|h[1-6]|li|tr)>/i', "\n", $html) ?? $html;
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace('/[\t ]+/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\n[\t ]+/', "\n", $texto) ?? $texto;
        return trim(preg_replace('/\n{3,}/', "\n\n", $texto) ?? $texto);
    }
}
