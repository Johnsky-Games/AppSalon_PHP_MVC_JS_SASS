<?php

namespace Tests\Qa;

/** Cliente HTTP mínimo con jar de cookies propio (una persona/navegador por instancia). */
final class Navegador
{
    private string $jar;
    public array $ultimo = [];
    public int $timeout = 60;

    public function __construct(private string $base)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'qa_jar_');
    }

    public function __destruct() { @unlink($this->jar); }

    /** @return array{status:int,headers:array,body:string,location:?string} */
    public function peticion(string $metodo, string $ruta, array|string|null $campos = null, array $cabeceras = []): array
    {
        $ch = curl_init($this->base . $ruta);
        $h = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PATH_AS_IS => true, CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$h) {
                if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))][] = trim($v); }
                return strlen($line);
            },
        ]);
        if ($metodo === 'HEAD') { curl_setopt($ch, CURLOPT_NOBODY, true); }
        if ($campos !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($campos) ? http_build_query($campos) : $campos);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $this->ultimo = ['status' => $status, 'headers' => $h, 'body' => (string)$body, 'location' => $h['location'][0] ?? null];
    }

    public function get(string $ruta, array $cabeceras = []): array { return $this->peticion('GET', $ruta, null, $cabeceras); }
    public function post(string $ruta, array|string $campos, array $cabeceras = []): array { return $this->peticion('POST', $ruta, $campos, $cabeceras); }

    /** Extrae el token CSRF de una página (campo oculto) o null. */
    public static function csrfDe(string $html): ?string
    {
        return preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $html, $m) ? $m[1] : null;
    }

    public static function campoDe(string $html, string $nombre): ?string
    {
        return preg_match('/name="' . preg_quote($nombre, '/') . '" value="([^"]*)"/', $html, $m) ? html_entity_decode($m[1]) : null;
    }

    /** CSRF vigente de esta sesión (visitando una página pública). */
    public function csrf(string $pagina = '/cliente/login'): string
    {
        $r = $this->get($pagina);
        return self::csrfDe($r['body']) ?? throw new \RuntimeException("Sin CSRF en $pagina (status {$r['status']})");
    }

    /** Inicia sesión por un portal. Devuelve la respuesta del POST. */
    public function login(string $portal, string $email, string $password): array
    {
        $ruta = $portal === 'equipo' ? '/equipo/login' : '/cliente/login';
        $csrf = $this->csrf($ruta);
        return $this->post($ruta, ['csrf_token' => $csrf, 'email' => $email, 'password' => $password]);
    }

    public function cookieSesion(): ?string
    {
        foreach (file($this->jar) ?: [] as $l) {
            $p = explode("\t", trim($l));
            if (count($p) >= 7 && $p[5] === 'PHPSESSID') { return $p[6]; }
        }
        return null;
    }
}
