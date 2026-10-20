<?php
namespace Services\Fiscal;

/** Evidencia de autorización, separada del XML firmado y de la recepción. */
final class SriAutorizacion
{
    public static function validar(array $respuesta, string $firmadoEsperado): void
    {
        foreach (['clave', 'numero_autorizacion', 'fecha_autorizacion', 'ambiente', 'xml'] as $campo) {
            if (!is_string($respuesta[$campo] ?? null)) {
                throw new \RuntimeException('La evidencia de autorización está incompleta.');
            }
        }
        $esperado = SriXml::validar($firmadoEsperado);
        $clave = $esperado->getElementsByTagName('claveAcceso')->item(0)->textContent;
        if (($respuesta['estado'] ?? null) !== 'AUTORIZADO' || ($respuesta['autorizado'] ?? null) !== true
            || $respuesta['clave'] !== $clave || $respuesta['numero_autorizacion'] !== $clave
            || $respuesta['ambiente'] !== ($clave[23] === '1' ? 'PRUEBAS' : 'PRODUCCIÓN')
            || $esperado->getElementsByTagNameNS(SriFirmaXades::DS, 'Signature')->length !== 1) {
            throw new \RuntimeException('La autorización no corresponde al comprobante firmado.');
        }
        $fecha = $respuesta['fecha_autorizacion'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))$/D', $fecha)
            || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            throw new \RuntimeException('Fecha de autorización no válida.');
        }
        $autorizado = SriXml::validar($respuesta['xml']);
        // Incluye las tres referencias y la firma; solo tolera diferencias de serialización XML.
        if (!hash_equals(hash('sha256', $esperado->C14N(false, false)), hash('sha256', $autorizado->C14N(false, false)))) {
            throw new \RuntimeException('El XML autorizado difiere del comprobante enviado.');
        }
    }

    /** Contenedor autónomo según la respuesta de autorización offline del SRI. */
    public static function exportar(array $respuesta, string $firmadoEsperado): string
    {
        self::validar($respuesta, $firmadoEsperado);
        $d = new \DOMDocument('1.0', 'UTF-8');
        $raiz = $d->appendChild($d->createElement('autorizacion'));
        foreach (['estado' => 'estado', 'numeroAutorizacion' => 'numero_autorizacion', 'fechaAutorizacion' => 'fecha_autorizacion', 'ambiente' => 'ambiente'] as $tag => $campo) {
            $raiz->appendChild($d->createElement($tag))->appendChild($d->createTextNode($respuesta[$campo]));
        }
        $comprobante = $raiz->appendChild($d->createElement('comprobante'));
        // Partir el delimitador conserva incluso XML con secciones CDATA internas.
        $partes = explode(']]>', $respuesta['xml']);
        foreach ($partes as $i => $parte) {
            $comprobante->appendChild($d->createCDATASection(($i ? '>' : '').$parte.($i < count($partes) - 1 ? ']]' : '')));
        }
        $mensajes = $raiz->appendChild($d->createElement('mensajes'));
        foreach ($respuesta['mensajes'] ?? [] as $mensaje) {
            $nodo = $mensajes->appendChild($d->createElement('mensaje'));
            foreach (['identificador', 'mensaje', 'informacionAdicional', 'tipo'] as $campo) {
                if (isset($mensaje[$campo]) && is_string($mensaje[$campo]) && $mensaje[$campo] !== '') {
                    $nodo->appendChild($d->createElement($campo))->appendChild($d->createTextNode($mensaje[$campo]));
                }
            }
        }
        return $d->saveXML();
    }
}
