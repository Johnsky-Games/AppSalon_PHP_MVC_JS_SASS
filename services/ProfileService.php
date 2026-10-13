<?php
namespace Services;

use Repositories\ProfileRepository;

final class ProfileService
{
    public const MAX_BYTES = 5242880;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 12000000;
    private string $storage;
    public function __construct(private ProfileRepository $repo, ?string $storage = null)
    {
        $this->storage = $storage ?? dirname(__DIR__) . '/storage/private/perfiles';
    }
    public function obtener(int $id): ?array { return $this->repo->obtener($id); }

    /**
     * @param string|null $contexto 'cliente' (portal de clientes: edita sus comunicaciones; no publica retrato),
     *        'equipo' (portal del equipo: edita datos laborales y publicación del retrato; JAMÁS altera el
     *        consentimiento comercial ni las comunicaciones del cliente) o null (comportamiento heredado).
     */
    public function guardar(int $id, array $input, bool $esTitular = true, ?string $contexto = null): void
    {
        $actual = $this->obtener($id);
        if (!$actual) { throw new \InvalidArgumentException('No se encontró el perfil.'); }
        $data = [];
        foreach (['nombre','apellido'] as $campo) {
            if (!is_string($input[$campo] ?? null)) { throw new \InvalidArgumentException('Nombre y apellido son obligatorios.'); }
            $data[$campo] = trim($input[$campo]);
            if ($data[$campo] === '' || mb_strlen($data[$campo]) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $data[$campo])) { throw new \InvalidArgumentException('Nombre y apellido deben tener entre 1 y 60 caracteres.'); }
        }
        $telefono = $input['telefono'] ?? null;
        if (!is_string($telefono) || !preg_match('/^[0-9]{7,10}$/D', trim($telefono))) { throw new \InvalidArgumentException('El teléfono debe tener entre 7 y 10 dígitos.'); }
        $data['telefono'] = trim($telefono);
        foreach (['contacto_email','marketing_email','recordatorios','foto_publica'] as $campo) {
            // Cada portal solo gobierna sus propios campos; el resto conserva su valor guardado.
            $ajeno = ($contexto === 'equipo' && $campo !== 'foto_publica') || ($contexto === 'cliente' && $campo === 'foto_publica');
            $valor = $ajeno ? (int)$actual[$campo] : ($input[$campo] ?? '0');
            if (!in_array($valor, ['0','1',0,1], true)) { throw new \InvalidArgumentException('Preferencia inválida.'); }
            $data[$campo] = (int)$valor;
        }
        if (!$esTitular && $data['marketing_email'] === 1 && !(bool)$actual['marketing_email']) { throw new \InvalidArgumentException('Sólo el titular puede otorgar consentimiento promocional.'); }
        // Only a linked active professional can explicitly publish a portrait.
        if ($data['foto_publica'] === 1 && !(bool)$actual['es_profesional']) { throw new \InvalidArgumentException('La foto del cliente es privada.'); }
        $this->repo->guardar($id, $data);
    }

    public static function validarImagen(string $path): array
    {
        if (!is_file($path) || filesize($path) === 0 || filesize($path) > self::MAX_BYTES) { throw new \InvalidArgumentException('La imagen debe pesar como máximo 5 MB.'); }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { throw new \InvalidArgumentException('Usa una imagen JPEG, PNG o WebP.'); }
        $size = @getimagesize($path);
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > self::MAX_DIMENSION || $size[1] > self::MAX_DIMENSION || $size[0] * $size[1] > self::MAX_PIXELS || ($size['mime'] ?? '') !== $mime) { throw new \InvalidArgumentException('La imagen es inválida o supera 4096 píxeles por lado o 12 megapíxeles.'); }
        return [$size[0],$size[1]];
    }

    public function subirFoto(int $id, array $file): void
    {
        if (!$this->obtener($id)) { throw new \InvalidArgumentException('Perfil inexistente.'); }
        if (($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) { throw new \InvalidArgumentException('No se recibió una imagen válida.'); }
        [$width,$height] = self::validarImagen($file['tmp_name']);
        if (!function_exists('imagecreatefromstring')) { throw new \RuntimeException('El procesador de imágenes no está disponible.'); }
        $source = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$source) { throw new \InvalidArgumentException('No se pudo decodificar la imagen.'); }
        $factor = min(1, 1024 / max($width,$height));
        $target = imagecreatetruecolor(max(1,(int)round($width*$factor)),max(1,(int)round($height*$factor)));
        imagefill($target,0,0,imagecolorallocate($target,255,255,255));
        imagecopyresampled($target,$source,0,0,0,0,imagesx($target),imagesy($target),$width,$height);
        if (!is_dir($this->storage) && !mkdir($this->storage,0700,true) && !is_dir($this->storage)) { imagedestroy($source); imagedestroy($target); throw new \RuntimeException('No se pudo guardar la imagen.'); }
        $name = bin2hex(random_bytes(32)) . '.jpg';
        $path = $this->storage . '/' . $name;
        try {
            // JPEG reencoding discards original filename, metadata and trailing payload.
            if (!imagejpeg($target,$path,88)) { throw new \RuntimeException('No se pudo procesar la imagen.'); }
            chmod($path,0600);
            $old = $this->repo->guardarFoto($id,$name);
            if ($old && preg_match('/^[a-f0-9]{64}\.jpg$/D',$old)) { @unlink($this->storage . '/' . $old); }
        } catch (\Throwable $e) { @unlink($path); throw $e; }
        finally { imagedestroy($source); imagedestroy($target); }
    }
    public function eliminarFoto(int $id): void
    {
        $old = $this->repo->eliminarFoto($id);
        if ($old && preg_match('/^[a-f0-9]{64}\.jpg$/D',$old)) { @unlink($this->storage . '/' . $old); }
    }
    public function rutaFoto(array $perfil): ?string
    {
        $name = $perfil['archivo'] ?? '';
        if (!is_string($name) || !preg_match('/^[a-f0-9]{64}\.jpg$/D',$name)) { return null; }
        $path = $this->storage . '/' . $name;
        return is_file($path) ? $path : null;
    }
    public function fotoVisible(array $perfil, ?int $viewer, bool $puedeGestionar = false): bool
    {
        return $viewer === (int)$perfil['id'] || $puedeGestionar || ((bool)$perfil['foto_publica'] && (bool)$perfil['es_profesional']);
    }
}
