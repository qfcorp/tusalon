<?php
declare(strict_types=1);

namespace TuSalon;

use Throwable;

require_once __DIR__ . '/tukan_correo.php';

/**
 * «Enviar por correo» en la pantalla «Demo lista» del panel de Tukán (desde info@tukansoftware.com, con
 * compartido/tukan_correo.php del Panel Tukán Central; guía compartido/LEEME_CORREO.md).
 *
 * La contraseña de la demo se ve una sola vez y no se guarda en la base, ni en la sesión, ni en los registros. Para
 * mandarla por correo, la pantalla lleva un «sello»: los datos de la demo CIFRADOS (XChaCha20-Poly1305) con una llave al
 * azar de un solo uso. La llave queda en la sesión (sin la contraseña) atada al super usuario y al salón y vence en 30 min:
 * el sello solo no sirve, y la llave sola tampoco. Al enviar bien, la llave se borra y el sello ya no abre.
 */
final class CorreoDemo
{
    public const DURA_SEG = 1800;
    public const PROGRAMA = 'TuSalón';
    private const MAX_PENDIENTES = 10;

    public static function listo(): bool
    {
        return tukan_correo_listo();
    }

    public static function comandoConfigurar(): string
    {
        return 'cd /var/www/tukan-central && git pull && bash deploy/configurar_correo.sh';
    }

    private static function ad(int $adminId, int $salonId): string
    {
        return "tusalon:correo_demo:$adminId:$salonId";
    }

    /**
     * Sella los datos ($d: usuario, clave, enlace, hasta, contacto, negocio, email) y guarda la llave en $sesion.
     * Devuelve el sello (texto para un campo oculto).
     */
    public static function sellar(array $d, int $adminId, int $salonId, array &$sesion, ?int $ahora = null): string
    {
        $ahora ??= time();
        $pend = array_filter(is_array($sesion['correo_demo'] ?? null) ? $sesion['correo_demo'] : [],
            fn($x) => is_array($x) && ($x['exp'] ?? 0) > $ahora);
        if (count($pend) >= self::MAX_PENDIENTES) $pend = array_slice($pend, -(self::MAX_PENDIENTES - 1), null, true);
        $id = bin2hex(random_bytes(12));
        $llave = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $exp = $ahora + self::DURA_SEG;
        $pend[$id] = ['llave' => base64_encode($llave), 'exp' => $exp, 'admin' => $adminId, 'salon' => $salonId];
        $sesion['correo_demo'] = $pend;
        $json = json_encode(['d' => $d, 'exp' => $exp], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $cifrado = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($json, self::ad($adminId, $salonId), $nonce, $llave);
        sodium_memzero($llave);
        return $id . '.' . rtrim(strtr(base64_encode($nonce . $cifrado), '+/', '-_'), '=');
    }

    /** Abre un sello: ['id', 'd'] o null si es falso, de otra sesión o de otro usuario, ya se usó o venció. */
    public static function abrir(string $sello, int $adminId, int $salonId, array $sesion, ?int $ahora = null): ?array
    {
        $ahora ??= time();
        if ($sello === '' || strlen($sello) > 20000 || !preg_match('/^([0-9a-f]{24})\.([A-Za-z0-9_-]+)$/', $sello, $m)) return null;
        $p = $sesion['correo_demo'][$m[1]] ?? null;
        if (!is_array($p) || ($p['exp'] ?? 0) <= $ahora || (int) ($p['admin'] ?? 0) !== $adminId || (int) ($p['salon'] ?? 0) !== $salonId) return null;
        try {
            $crudo = base64_decode(strtr($m[2], '-_', '+/'), true);
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if ($crudo === false || strlen($crudo) <= $n) return null;
            $json = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($crudo, $n), self::ad($adminId, $salonId), substr($crudo, 0, $n),
                (string) base64_decode((string) $p['llave'], true));
        } catch (Throwable) {
            return null;
        }
        $x = $json === false ? null : json_decode($json, true);
        if (!is_array($x) || !is_array($x['d'] ?? null) || (int) ($x['exp'] ?? 0) <= $ahora) return null;
        return ['id' => $m[1], 'd' => $x['d']];
    }

    /** Borra la llave (después de enviar bien): el sello ya no abre. */
    public static function gastar(string $id, array &$sesion): void
    {
        unset($sesion['correo_demo'][$id]);
    }

    public static function mensaje(array $d): array
    {
        return tukan_correo_demo(['programa' => self::PROGRAMA, 'enlace' => (string) ($d['enlace'] ?? ''), 'usuario' => (string) ($d['usuario'] ?? ''),
                                  'clave' => (string) ($d['clave'] ?? ''), 'hasta' => (string) ($d['hasta'] ?? ''),
                                  'contacto' => (string) ($d['contacto'] ?? ''), 'negocio' => (string) ($d['negocio'] ?? '')]);
    }

    /** Manda el correo. El $error nunca trae la contraseña de la demo ni la del buzón. */
    public static function enviar(array $d, string $para, ?string &$error = null): bool
    {
        $m = self::mensaje($d);
        $ok = tukan_correo_enviar($para, $m['asunto'], $m['html'], $m['texto'], $error, array_filter(['nombre_para' => (string) ($d['contacto'] ?? '')]));
        $clave = (string) ($d['clave'] ?? '');
        if ($error !== null && $clave !== '') $error = str_replace($clave, '***', $error);
        return $ok;
    }
}
