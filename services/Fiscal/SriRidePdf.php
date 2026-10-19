<?php
namespace Services\Fiscal;

use Dompdf\{Dompdf,Options};

/** PDF local de la misma representación fiscal. No interpreta HTML ni recursos enviados por usuarios. */
final class SriRidePdf
{
    public static function generar(array $ride): string
    {
        ob_start();
        try { require __DIR__.'/../../views/erp/_fiscal-ride-documento.php'; $documento=ob_get_contents(); }
        finally { ob_end_clean(); }
        $temporal=sys_get_temp_dir().'/appsalon-sri-pdf-'.bin2hex(random_bytes(16));
        if(!mkdir($temporal,0700)) throw new \RuntimeException('No se pudo preparar la representación fiscal.');
        try {
            $o=new Options();
            $o->setIsRemoteEnabled(false);$o->setIsPhpEnabled(false);$o->setIsJavascriptEnabled(false);
            $o->setAllowedProtocols([]);$o->setChroot([__DIR__.'/../../vendor/dompdf/dompdf/lib/fonts']);
            $o->setTempDir($temporal);$o->setFontCache($temporal);$o->setDefaultFont('DejaVu Sans');
            $o->setIsFontSubsettingEnabled(true);
            $d=new Dompdf($o);$d->setPaper('A4');
            $css=(string)file_get_contents(__DIR__.'/../../resources/sri/ride-pdf.css');
            $d->loadHtml('<!doctype html><html lang="es"><head><meta charset="UTF-8"><style>'.$css.'</style></head><body>'.$documento.'</body></html>','UTF-8');
            $d->render();$pdf=$d->output();
            if(!str_starts_with($pdf,'%PDF-')||strlen($pdf)>5*1024*1024) throw new \RuntimeException('La representación fiscal excede el tamaño permitido.');
            return $pdf;
        } finally {
            foreach(glob($temporal.'/*')?:[] as $archivo) if(is_file($archivo))unlink($archivo);
            rmdir($temporal);
        }
    }
}
