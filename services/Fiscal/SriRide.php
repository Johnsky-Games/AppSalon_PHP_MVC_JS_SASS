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
        if ($factura->documentElement->tagName === 'notaCredito') {
            return self::notaCredito($a, $factura);
        }
        if ($factura->documentElement->tagName !== 'factura') {
            throw new \InvalidArgumentException('Este RIDE admite facturas y notas de crédito.');
        }
        $xp = new \DOMXPath($factura);
        $leer = static fn(string $ruta, ?\DOMNode $n = null): string => $xp->evaluate('string('.$ruta.')', $n);
        $campos = static function(string $ruta, array $nombres) use ($leer): array {
            $r = []; foreach ($nombres as $nombre) $r[$nombre] = $leer($ruta.'/'.$nombre); return $r;
        };
        $datos = [
            'tipo' => '01',
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

    /** Nota de crédito 1.1.0: misma proyección que la factura, con el documento modificado y el motivo en lugar de las formas de pago. */
    private static function notaCredito(\DOMXPath $a, \DOMDocument $nota): array
    {
        $xp = new \DOMXPath($nota);
        $leer = static fn(string $ruta, ?\DOMNode $n = null): string => $xp->evaluate('string('.$ruta.')', $n);
        $emisor = []; foreach (['razonSocial','nombreComercial','ruc','dirMatriz','estab','ptoEmi','secuencial','claveAcceso','ambiente','tipoEmision','agenteRetencion','contribuyenteRimpe'] as $c) $emisor[$c] = $leer('/notaCredito/infoTributaria/'.$c);
        $info = []; foreach (['fechaEmision','dirEstablecimiento','contribuyenteEspecial','obligadoContabilidad','razonSocialComprador','identificacionComprador','totalSinImpuestos','moneda','motivo','codDocModificado','numDocModificado','fechaEmisionDocSustento','valorModificacion'] as $c) $info[$c] = $leer('/notaCredito/infoNotaCredito/'.$c);
        $datos = [
            'tipo' => '04',
            'emisor' => $emisor,
            'factura' => [
                'fechaEmision'=>$info['fechaEmision'],'dirEstablecimiento'=>$info['dirEstablecimiento'],'contribuyenteEspecial'=>$info['contribuyenteEspecial'],'obligadoContabilidad'=>$info['obligadoContabilidad'],
                'razonSocialComprador'=>$info['razonSocialComprador'],'identificacionComprador'=>$info['identificacionComprador'],'direccionComprador'=>'','totalSinImpuestos'=>$info['totalSinImpuestos'],
                'totalDescuento'=>'0.00','importeTotal'=>$info['valorModificacion'],'moneda'=>$info['moneda'],'propina'=>'',
            ],
            'sustento' => ['documento'=>$info['codDocModificado'],'numero'=>$info['numDocModificado'],'fecha'=>$info['fechaEmisionDocSustento'],'motivo'=>$info['motivo']],
            'autorizacion' => ['numero' => $a->evaluate('string(/autorizacion/numeroAutorizacion)'), 'fecha' => $a->evaluate('string(/autorizacion/fechaAutorizacion)')],
            'lineas' => [], 'impuestos' => [], 'pagos' => [], 'adicionales' => [],
        ];
        if ($datos['autorizacion']['numero'] !== $emisor['claveAcceso']) {
            throw new \InvalidArgumentException('La representación no corresponde a su autorización.');
        }
        $descuento = 0;
        foreach ($xp->query('/notaCredito/detalles/detalle') as $n) {
            $fila = ['codigoPrincipal'=>$leer('codigoInterno', $n),'codigoAuxiliar'=>$leer('codigoAdicional', $n)];
            foreach (['descripcion','cantidad','precioUnitario','descuento','precioTotalSinImpuesto'] as $campo) $fila[$campo] = $leer($campo, $n);
            $descuento += (int) round(((float) $fila['descuento']) * 100);
            $fila['adicionales'] = [];
            $datos['lineas'][] = $fila;
        }
        $datos['factura']['totalDescuento'] = intdiv($descuento, 100).'.'.str_pad((string)($descuento % 100), 2, '0', STR_PAD_LEFT);
        $tarifas = [];
        foreach ($xp->query('/notaCredito/detalles/detalle/impuestos/impuesto') as $n) $tarifas[$leer('codigoPorcentaje', $n)] = $leer('tarifa', $n);
        foreach ($xp->query('/notaCredito/infoNotaCredito/totalConImpuestos/totalImpuesto') as $n) {
            if ($leer('codigo', $n) !== '2') throw new \InvalidArgumentException('El RIDE del piloto admite IVA nacional.');
            $codigo = $leer('codigoPorcentaje', $n);
            $etiqueta = match ($codigo) {'0'=>'IVA 0%', '6'=>'No objeto de IVA', '7'=>'Exento de IVA', default=>isset($tarifas[$codigo]) ? 'IVA '.$tarifas[$codigo].'%' : 'IVA (código SRI '.$codigo.')'};
            $datos['impuestos'][] = ['etiqueta'=>$etiqueta, 'base'=>$leer('baseImponible', $n), 'valor'=>$leer('valor', $n)];
        }
        return $datos;
    }
}
