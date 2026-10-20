<?php
// Simulación exclusiva del receptor SRI para comprobar RIDE y descargas en navegador.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('DB_NAME') !== 'appsalon_e2e_test') {
    fwrite(STDERR, "La autorización sintética exige la base y el entorno E2E aislados.\n"); exit(1);
}
require __DIR__.'/../../includes/app.php';
$r = new \Repositories\ErpRepository($db);
if ($r->uno('SELECT DATABASE() nombre')['nombre'] !== 'appsalon_e2e_test') exit(2);
$id = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$doc = $r->uno("SELECT * FROM erp_fiscal_documentos WHERE id=? AND estado='pendiente'", [$id]);
if (!$doc || (int) $r->uno("SELECT COUNT(*) n FROM erp_fiscal_documentos WHERE estado IN('pendiente','recibida')")['n'] !== 1) exit(3);
$transporte = new class($doc) implements \Interfaces\SriTransporteInterface {
    public function __construct(private array $doc) {}
    public function post(string $url, string $xml): string
    {
        if (!str_ends_with($url, '/AutorizacionComprobantesOffline') || !str_contains($xml, $this->doc['clave_acceso'])) throw new \RuntimeException('Petición ajena al ensayo.');
        $d = new \DOMDocument('1.0', 'UTF-8');
        $e = $d->appendChild($d->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Envelope'));
        $b = $e->appendChild($d->createElementNS('http://schemas.xmlsoap.org/soap/envelope/', 'soap:Body'));
        $op = $b->appendChild($d->createElementNS('http://ec.gob.sri.ws.autorizacion', 'ns2:autorizacionComprobanteResponse'));
        $r = $op->appendChild($d->createElement('RespuestaAutorizacionComprobante'));
        $n = static function(\DOMNode $p, string $tag, string $texto) use ($d): void {$p->appendChild($d->createElement($tag))->appendChild($d->createTextNode($texto));};
        $n($r,'claveAccesoConsultada',$this->doc['clave_acceso']); $n($r,'numeroComprobantes','1');
        $a = $r->appendChild($d->createElement('autorizaciones'))->appendChild($d->createElement('autorizacion'));
        foreach (['estado'=>'AUTORIZADO','numeroAutorizacion'=>$this->doc['clave_acceso'],'fechaAutorizacion'=>'2026-10-19T10:00:00-05:00','ambiente'=>'PRUEBAS','comprobante'=>$this->doc['xml_firmado']] as $tag=>$valor) $n($a,$tag,$valor);
        $a->appendChild($d->createElement('mensajes'));
        return $d->saveXML();
    }
};
$resultado = (new \Services\ErpFiscalService($r))->procesar($transporte);
if ($resultado['autorizados'] !== 1) throw new \RuntimeException('La autorización sintética no se verificó.');
echo "Autorización sintética E2E verificada, sin conexión al SRI.\n";
