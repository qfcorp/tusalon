<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Planes pagados dentro de la app de Android (Google Play).
 *
 * En la app de Google Play los planes se pagan con la cuenta de Google del dueño
 * (Google cobra su comisión, por eso allí los precios son otros y se ponen en Play Console).
 * Cada plan y periodo es una suscripción de Google con este código:
 *     tusalon_<plan>_<periodo>     ej. tusalon_completa_mensual
 *
 * El sistema nunca confía en lo que dice el celular: con el código de compra (purchaseToken)
 * le pregunta a Google, con la cuenta de servicio del dueño de la app, si está pagado y hasta cuándo.
 *
 * Configuración (config/.env):
 *   GOOGLE_PLAY_PAQUETE=com.tukansoftware.tusalon
 *   GOOGLE_PLAY_CUENTA=/var/www/tusalon/config/google-play.json   (cuenta de servicio de Google Cloud)
 */
final class GooglePlay
{
    public const PERIODOS = ['mensual' => 1, 'semestral' => 6, 'anual' => 12];
    public const ACTIVOS = ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD'];

    /** @var callable|null para pruebas: fn(string $metodo, string $url, ?array $cuerpo, array $cabeceras): array [codigo, json] */
    private $http;
    private ?string $tokenAcceso = null;

    public function __construct(private PDO $db, ?callable $http = null)
    {
        $this->http = $http;
    }

    public static function paquete(): string
    {
        return (string) (getenv('GOOGLE_PLAY_PAQUETE') ?: 'com.tukansoftware.tusalon');
    }

    /**
     * Interruptor general. La app de Google Play queda APAGADA hasta que el instalador
     * la active (pregunta "Activar la app de Google Play?" y guarda GOOGLE_PLAY_ACTIVO=1 en config/.env).
     */
    public static function activo(): bool
    {
        return getenv('GOOGLE_PLAY_ACTIVO') === '1';
    }

    public static function configurado(): bool
    {
        $f = (string) getenv('GOOGLE_PLAY_CUENTA');
        return $f !== '' && is_file($f);
    }

    public static function producto(string $plan, string $periodo): string
    {
        return "tusalon_{$plan}_{$periodo}";
    }

    /** tusalon_completa_mensual -> ['completa', 'mensual'] (o null si no es nuestro). */
    public static function leerProducto(string $producto): ?array
    {
        if (!preg_match('/^tusalon_([a-z]+)_(mensual|semestral|anual)$/', $producto, $m)) return null;
        return [$m[1], $m[2]];
    }

    /**
     * Precio sugerido en Google Play para que, después de la comisión, quede lo mismo que en la web.
     * Ej. $35 / (1 - 0,15) = $41,18 -> $41,99
     */
    public static function precioSugerido(float $precioWeb, float $comision = 0.15): float
    {
        $bruto = $precioWeb / (1 - $comision);
        return floor($bruto) + 0.99;
    }

    // ------------------------------------------------------------------
    // Conversación con Google
    // ------------------------------------------------------------------

    private function llamar(string $metodo, string $url, ?array $cuerpo = null, array $cabeceras = []): array
    {
        if ($this->http) return ($this->http)($metodo, $url, $cuerpo, $cabeceras);
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $metodo];
        $h = $cabeceras;
        if ($cuerpo !== null) {
            if (isset($cuerpo['__form'])) {
                unset($cuerpo['__form']);
                $opts[CURLOPT_POSTFIELDS] = http_build_query($cuerpo);
                $h[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $opts[CURLOPT_POSTFIELDS] = json_encode($cuerpo);
                $h[] = 'Content-Type: application/json';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $r = curl_exec($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$codigo, $r === false ? null : json_decode((string) $r, true)];
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Token de acceso con la cuenta de servicio (JWT firmado con su llave privada). */
    private function token(): string
    {
        if ($this->tokenAcceso) return $this->tokenAcceso;
        $archivo = (string) getenv('GOOGLE_PLAY_CUENTA');
        $cuenta = is_file($archivo) ? json_decode((string) file_get_contents($archivo), true) : null;
        if (!$cuenta || empty($cuenta['private_key']) || empty($cuenta['client_email'])) {
            throw new RuntimeException('Falta configurar la cuenta de Google Play en el servidor.');
        }
        $ahora = time();
        $uri = (string) (getenv('GOOGLE_OAUTH_URL') ?: ($cuenta['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
        $cab = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $datos = self::b64(json_encode([
            'iss' => $cuenta['client_email'], 'scope' => 'https://www.googleapis.com/auth/androidpublisher',
            'aud' => $uri, 'iat' => $ahora, 'exp' => $ahora + 3600,
        ]));
        if (!openssl_sign("$cab.$datos", $firma, $cuenta['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('La llave de la cuenta de Google Play no es válida.');
        }
        [$codigo, $r] = $this->llamar('POST', $uri, ['__form' => 1, 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                                                     'assertion' => "$cab.$datos." . self::b64($firma)]);
        if ($codigo !== 200 || empty($r['access_token'])) throw new RuntimeException('Google no aceptó la cuenta de servicio.');
        return $this->tokenAcceso = $r['access_token'];
    }

    private function api(): string
    {
        return rtrim((string) (getenv('GOOGLE_PLAY_API') ?: 'https://androidpublisher.googleapis.com/androidpublisher/v3'), '/')
            . '/applications/' . rawurlencode(self::paquete());
    }

    /** Lo que Google sabe de una suscripción (API subscriptionsv2). */
    public function consultar(string $purchaseToken): array
    {
        [$codigo, $r] = $this->llamar('GET', $this->api() . '/purchases/subscriptionsv2/tokens/' . rawurlencode($purchaseToken), null,
                                      ['Authorization: Bearer ' . $this->token()]);
        if ($codigo === 404 || $codigo === 410) throw new RuntimeException('Google no reconoce esa compra.');
        if ($codigo !== 200 || !is_array($r)) throw new RuntimeException('No se pudo confirmar la compra con Google. Intenta de nuevo en unos minutos.');
        return $r;
    }

    private function confirmarRecibo(string $producto, string $purchaseToken): void
    {
        $this->llamar('POST', $this->api() . '/purchases/subscriptions/' . rawurlencode($producto) . '/tokens/'
                      . rawurlencode($purchaseToken) . ':acknowledge', [], ['Authorization: Bearer ' . $this->token()]);
    }

    // ------------------------------------------------------------------
    // Registrar y renovar
    // ------------------------------------------------------------------

    /**
     * El dueño compró (o renovó) desde la app. Se verifica con Google y se activa el plan
     * hasta la fecha que Google dice. Devuelve ['plan', 'periodo', 'hasta'].
     */
    public function registrarCompra(int $salonId, string $producto, string $purchaseToken): array
    {
        $leido = self::leerProducto($producto);
        if (!$leido) throw new RuntimeException('Ese plan no existe.');
        [$plan, $periodo] = $leido;
        $purchaseToken = trim($purchaseToken);
        if ($purchaseToken === '' || strlen($purchaseToken) > 4000) throw new RuntimeException('Falta el código de la compra.');

        $st = $this->db->prepare('SELECT salon_id FROM google_play_compras WHERE token = ?');
        $st->execute([$purchaseToken]);
        $dueno = $st->fetchColumn();
        if ($dueno !== false && (int) $dueno !== $salonId) throw new RuntimeException('Esa compra pertenece a otro salón.');

        $g = $this->consultar($purchaseToken);
        $item = null;
        foreach ((array) ($g['lineItems'] ?? []) as $li) {
            if (($li['productId'] ?? '') === $producto) $item = $li;
        }
        if (!$item) throw new RuntimeException('La compra no corresponde a ese plan.');
        $estado = (string) ($g['subscriptionState'] ?? '');
        if (!in_array($estado, self::ACTIVOS, true)) throw new RuntimeException('Google dice que esa suscripción no está activa.');
        $expira = new DateTimeImmutable((string) $item['expiryTime']);
        $expira = $expira->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $renueva = (bool) ($item['autoRenewingPlan']['autoRenewEnabled'] ?? true);

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            if ($dueno === false) {
                $st = $this->db->prepare('INSERT INTO google_play_compras (salon_id, producto, plan_codigo, periodo, token, pedido, estado, expira, renueva_sola)
                                          VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
                $st->execute([$salonId, $producto, $plan, $periodo, $purchaseToken, $g['latestOrderId'] ?? null, $estado,
                              $expira->format('Y-m-d H:i:s'), $renueva ? 't' : 'f']);
                $compraId = (int) $st->fetchColumn();
            } else {
                $st = $this->db->prepare('SELECT id FROM google_play_compras WHERE token = ?');
                $st->execute([$purchaseToken]);
                $compraId = (int) $st->fetchColumn();
            }
            $this->aplicar($salonId, $compraId, $plan, $periodo, $expira, $estado, $renueva, $g['latestOrderId'] ?? null, $item);
            if ($propia) $this->db->commit();
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
        if (($g['acknowledgementState'] ?? '') === 'ACKNOWLEDGEMENT_STATE_PENDING') {
            $this->confirmarRecibo($producto, $purchaseToken);   // si no se confirma en 3 días, Google devuelve el dinero
        }
        return ['plan' => $plan, 'periodo' => $periodo, 'hasta' => $expira->format('Y-m-d')];
    }

    /** Guarda el estado de Google y, si el plan llega más lejos, registra el pago y extiende el salón. */
    private function aplicar(int $salonId, int $compraId, string $plan, string $periodo, DateTimeImmutable $expira,
                             string $estado, bool $renueva, ?string $pedido, array $item): void
    {
        $st = $this->db->prepare('SELECT expira, pedido FROM google_play_compras WHERE id = ?');
        $st->execute([$compraId]);
        $antes = $st->fetch();
        $this->db->prepare('UPDATE google_play_compras SET estado = ?, expira = ?, renueva_sola = ?, pedido = COALESCE(?, pedido), revisado_en = now() WHERE id = ?')
                 ->execute([$estado, $expira->format('Y-m-d H:i:s'), $renueva ? 't' : 'f', $pedido, $compraId]);
        if (!in_array($estado, self::ACTIVOS, true)) return;

        // ¿Ya registramos este periodo? (mismo pedido o misma fecha de fin)
        $st = $this->db->prepare('SELECT 1 FROM suscripciones WHERE google_play_id = ? AND fin = ? AND NOT anulada');
        $st->execute([$compraId, $expira->format('Y-m-d')]);
        if ($st->fetchColumn()) return;

        $meses = self::PERIODOS[$periodo];
        $inicio = $expira->modify("-$meses months");
        $precio = $item['autoRenewingPlan']['recurringPrice'] ?? null;
        $monto = $precio ? round((float) ($precio['units'] ?? 0) + (float) ($precio['nanos'] ?? 0) / 1e9, 2) : 0.0;
        $this->db->prepare('INSERT INTO suscripciones (salon_id, plan_codigo, periodo, meses_pagados, meses_recibidos, monto, inicio, fin,
                                                       metodo_pago, google_play_id)
                            VALUES (?,?,?,?,?,?,?,?,?,?)')
                 ->execute([$salonId, $plan, $periodo, $meses, $meses, $monto, $inicio->format('Y-m-d'), $expira->format('Y-m-d'),
                            'google_play', $compraId]);
        $this->db->prepare("UPDATE salones SET estado = 'activo', plan_codigo = ?,
                                   activo_hasta = GREATEST(COALESCE(activo_hasta, ?::date), ?::date) WHERE id = ?")
                 ->execute([$plan, $expira->format('Y-m-d'), $expira->format('Y-m-d'), $salonId]);
    }

    /**
     * Tarea automática: revisa con Google las suscripciones que vencen pronto o ya vencieron.
     * Si Google las renovó, extiende el plan; si se cancelaron, solo se anota (el plan llega
     * hasta la fecha pagada y luego se bloquea como cualquier plan vencido).
     */
    public function revisarRenovaciones(?DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new DateTimeImmutable();
        $res = ['revisadas' => 0, 'renovadas' => 0, 'errores' => 0];
        if (!self::configurado() && !$this->http) return $res;
        $st = $this->db->prepare("SELECT * FROM google_play_compras
                                   WHERE estado IN ('SUBSCRIPTION_STATE_ACTIVE','SUBSCRIPTION_STATE_IN_GRACE_PERIOD','SUBSCRIPTION_STATE_ON_HOLD')
                                     AND expira < ? AND revisado_en < ?
                                     AND salon_id NOT IN (SELECT id FROM salones WHERE eliminado_en IS NOT NULL)");   // la papelera no se renueva
        $st->execute([$ahora->modify('+1 day')->format('Y-m-d H:i:s'), $ahora->modify('-50 minutes')->format('Y-m-d H:i:s')]);
        foreach ($st->fetchAll() as $c) {
            $res['revisadas']++;
            try {
                $g = $this->consultar($c['token']);
                $item = null;
                foreach ((array) ($g['lineItems'] ?? []) as $li) if (($li['productId'] ?? '') === $c['producto']) $item = $li;
                if (!$item) continue;
                $expira = (new DateTimeImmutable((string) $item['expiryTime']))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                $antes = $c['expira'];
                $this->aplicar((int) $c['salon_id'], (int) $c['id'], $c['plan_codigo'], $c['periodo'], $expira,
                               (string) ($g['subscriptionState'] ?? ''), (bool) ($item['autoRenewingPlan']['autoRenewEnabled'] ?? false),
                               $g['latestOrderId'] ?? null, $item);
                if ($expira->format('Y-m-d H:i:s') > (string) $antes) $res['renovadas']++;
            } catch (RuntimeException $e) {
                $res['errores']++;
                error_log('TuSalón Google Play: ' . $e->getMessage());
                $this->db->prepare('UPDATE google_play_compras SET revisado_en = now() WHERE id = ?')->execute([$c['id']]);
            }
        }
        return $res;
    }

    public function deSalon(int $salonId): array
    {
        $st = $this->db->prepare('SELECT * FROM google_play_compras WHERE salon_id = ? ORDER BY creado_en DESC');
        $st->execute([$salonId]);
        return $st->fetchAll();
    }

    // ------------------------------------------------------------------
    // Enlace de la app con el sitio (Digital Asset Links)
    // ------------------------------------------------------------------

    /** Contenido de /.well-known/assetlinks.json (huellas en config/.env: TWA_SHA256, separadas por coma). */
    public static function assetlinks(): array
    {
        $huellas = array_values(array_filter(array_map('trim', explode(',', (string) getenv('TWA_SHA256')))));
        if (!$huellas) return [];
        return [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target'   => ['namespace' => 'android_app', 'package_name' => self::paquete(), 'sha256_cert_fingerprints' => $huellas],
        ]];
    }
}
