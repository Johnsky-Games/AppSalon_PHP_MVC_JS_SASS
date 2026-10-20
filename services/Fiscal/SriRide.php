<?php
namespace Services\Fiscal;

/** Proyección de una autorización previamente verificada; nunca consulta datos actuales del catálogo. */
final class SriRide
{
    public static function datos(string $contenedor): array
    {
        $d = SriXml::cargar($contenedor);
        $a = new \DOMXPath($d);
        if ($d->documentElement->tagName !== 'autorizacion' || $a->evaluate('string(/autorizacion/estado)') !== 'AUTORIZADO') {
            throw new \InvalidArgumentException('El RIDE exige una autorización verificada.');
        }
        $xml = $a->evaluate('string(/autorizacion/comprobante)');
        $factura = SriXml::validar($xml);
        if ($factura->documentElement->tagName !== 'factura') {
            throw new \InvalidArgumentException('Este RIDE admite únicamente facturas.');
        }
        $xp = new \DOMXPath($factura);
        $leer = static fn(string $ruta, ?\DOMNode $n = null): string => $xp->evaluate('string('.$ruta.')', $n);
        $campos = static function(string $ruta, array $nombres) use ($leer): array {
            $r = []; foreach ($nombres as $nombre) $r[$nombre] = $leer($ruta.'/'.$nombre); return $r;
        };
        $datos = [
            'emisor' => $campos('/factura/infoTributaria', ['razonSocial','nombreComercial','ruc','dirMatriz','estab','ptoEmi','secuencial','claveAcceso','ambiente','tipoEmision','agenteRetencion','contribuyenteRimpe']),
            'factura' => $campos('/factura/infoFactura', ['fechaEmision','dirEstablecimiento','contribuyenteEspecial','obligadoContabilidad','razonSocialComprador','identificacionComprador','direccionComprador','totalSinImpuestos','totalDescuento','importeTotal','moneda','propina']),
            'autorizacion' => ['numero' => $a->evaluate('string(/autorizacion/numeroAutorizacion)'), 'fecha' => $a->evaluate('string(/autorizacion/fechaAutorizacion)')],
            'lineas' => [], 'impuestos' => [], 'pagos' => [], 'adicionales' => [],
        ];
        if ($datos['autorizacion']['numero'] !== $datos['emisor']['claveAcceso']) {
            throw new \InvalidArgumentException('La representación no corresponde a su autorización.');
        }
        foreach ($xp->query('/factura/detalles/detalle') as $n) {
            $fila = []; foreach (['codigoPrincipal','codigoAuxiliar','descripcion','cantidad','precioUnitario','descuento','precioTotalSinImpuesto'] as $campo) $fila[$campo] = $leer($campo, $n);
            $fila['adicionales'] = [];
            foreach ($xp->query('detallesAdicionales/detAdicional', $n) as $extra) $fila['adicionales'][] = ['nombre'=>$extra->getAttribute('nombre'),'valor'=>$extra->getAttribute('valor')];
            $datos['lineas'][] = $fila;
        }
        $tarifas = [];
        foreach ($xp->query('/factura/detalles/detalle/impuestos/impuesto') as $n) $tarifas[$leer('codigoPorcentaje', $n)] = $leer('tarifa', $n);
        foreach ($xp->query('/factura/infoFactura/totalConImpuestos/totalImpuesto') as $n) {
            if ($leer('codigo', $n) !== '2') throw new \InvalidArgumentException('El RIDE del piloto admite IVA nacional.');
            $codigo = $leer('codigoPorcentaje', $n);
            $etiqueta = match ($codigo) {'0'=>'IVA 0%', '6'=>'No objeto de IVA', '7'=>'Exento de IVA', default=>isset($tarifas[$codigo]) ? 'IVA '.$tarifas[$codigo].'%' : 'IVA (código SRI '.$codigo.')'};
            $datos['impuestos'][] = ['etiqueta'=>$etiqueta, 'base'=>$leer('baseImponible', $n), 'valor'=>$leer('valor', $n)];
        }
        foreach ($xp->query('/factura/infoFactura/pagos/pago') as $n) {
            $codigo = $leer('formaPago', $n);
            $etiqueta = match ($codigo) {'01'=>'Sin utilización del sistema financiero', '15'=>'Compensación de deudas', '16'=>'Tarjeta de débito', '17'=>'Dinero electrónico', '18'=>'Tarjeta prepago', '19'=>'Tarjeta de crédito', '20'=>'Otros con utilización del sistema financiero', '21'=>'Endoso de títulos', default=>'Código SRI '.$codigo};
            $datos['pagos'][] = ['codigo'=>$codigo, 'etiqueta'=>$etiqueta, 'total'=>$leer('total', $n), 'plazo'=>$leer('plazo', $n), 'unidad'=>$leer('unidadTiempo', $n)];
        }
        foreach ($xp->query('/factura/infoAdicional/campoAdicional') as $n) $datos['adicionales'][] = ['nombre'=>$n->getAttribute('nombre'),'valor'=>$n->textContent];
        return $datos;
    }
}
