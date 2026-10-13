<?php
declare(strict_types=1);

namespace TuSalon;

use DateTimeImmutable;
use PDO;

/**
 * Conector con el Panel Tukán Central (protocolo v1, igual en todos los programas Tukán).
 *  - Configuración: TUKAN_CENTRAL_ID, TUKAN_CENTRAL_SECRET, TUKAN_CENTRAL_URL y TUKAN_SOLO_CENTRAL en config/.env
 *  - public/tukan_central.php usa esta clase: ?accion=sso (entrar como super usuario) y ?accion=estado (cifras en JSON)
 *  - Token: b64url(json) . "." . b64url(HMAC-SHA256(b64url(json), secreto)), dura máximo 60 s y sirve una sola vez
 */
final class TukanCentral
{
    public const PRODUCTO = 'tusalon';
    public const NOMBRE = 'TuSalón';
    public const PROTOCOLO = 1;
    public const URL_POR_DEFECTO = 'https://panel.tukanec.com';
    public const LIMITE_POR_MINUTO = 30;
    public const TOLERANCIA = 30;   // segundos de diferencia de reloj permitidos
    public const VIDA_MAXIMA = 60;  // exp - iat
    public const MAX_LISTA = 20;

    public function __construct(private PDO $db) {}

    // ------------------------------------------------------------------
    // Configuración
    // ------------------------------------------------------------------

    /** ['id' => ..., 'secreto' => ...] o null si el conector está apagado (falta el ID o el secreto, o el secreto es corto). */
    public static function config(): ?array
    {
        $id = trim((string) getenv('TUKAN_CENTRAL_ID'));
        $secreto = trim((string) getenv('TUKAN_CENTRAL_SECRET'));
        if (!preg_match('/^[a-z0-9_-]{2,40}$/', $id) || strlen($secreto) < 32) return null;
        return ['id' => $id, 'secreto' => $secreto];
    }

    /** Dirección del panel central (para el botón "← Panel Tukán" y los mensajes). */
    public static function urlCentral(): string
    {
        $url = rtrim(trim((string) getenv('TUKAN_CENTRAL_URL')), '/');
        return preg_match('#^https?://[^\s"\'<>]+$#i', $url) ? $url : self::URL_POR_DEFECTO;
    }

    /**
     * TUKAN_SOLO_CENTRAL=1: el ingreso local del super usuario queda apagado (solo se entra desde el panel central).
     * Solo vale si el conector está configurado; si no, nadie podría entrar al panel.
     */
    public static function soloCentral(): bool
    {
        return trim((string) getenv('TUKAN_SOLO_CENTRAL')) === '1' && self::config() !== null;
    }

    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** Firma un contenido (lo usa el panel central; aquí sirve para las pruebas y la prueba de conectar_central.sh). */
    public static function firmar(array $p, string $secreto): string
    {
        $a = self::b64((string) json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $a . '.' . self::b64(hash_hmac('sha256', $a, $secreto, true));
    }

    // ------------------------------------------------------------------
    // Validación del token (en el orden del protocolo)
    // ------------------------------------------------------------------

    /**
     * Revisa el token para la acción pedida ('sso' o 'estado'). Devuelve su contenido.
     * Si algo falla lanza TukanCentralRechazo con el motivo (en español).
     */
    public function validar(string $token, string $accion, ?int $ahora = null): array
    {
        $conf = self::config();
        if (!$conf) throw new TukanCentralRechazo('El conector no está configurado.');
        $ahora ??= time();

        // 1. Dos partes separadas por "." y firma correcta
        $partes = explode('.', $token);
        if (count($partes) !== 2 || $partes[0] === '' || $partes[1] === '') throw new TukanCentralRechazo('El enlace no es válido.');
        $esperada = self::b64(hash_hmac('sha256', $partes[0], $conf['secreto'], true));
        if (!hash_equals($esperada, $partes[1])) throw new TukanCentralRechazo('La firma del enlace no es correcta.');
        $json = self::unb64($partes[0]);
        $p = $json === false ? null : json_decode($json, true);
        if (!is_array($p)) throw new TukanCentralRechazo('El enlace no es válido.');

        // 2. Versión, tipo e instancia
        if (($p['v'] ?? null) !== 1) throw new TukanCentralRechazo('Versión del protocolo no soportada.');
        if (($p['tipo'] ?? null) !== $accion) throw new TukanCentralRechazo('El enlace no es para esta acción.');
        if (($p['inst'] ?? null) !== $conf['id']) throw new TukanCentralRechazo('El enlace es de otra instalación.');

        // 3. Tiempo (30 s de tolerancia de reloj)
        $iat = $p['iat'] ?? null;
        $exp = $p['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp)) throw new TukanCentralRechazo('El enlace no tiene fecha válida.');
        if ($ahora > $exp + self::TOLERANCIA) throw new TukanCentralRechazo('El enlace ya venció. Vuelve a intentar desde el Panel Tukán.');
        if ($iat > $ahora + self::TOLERANCIA) throw new TukanCentralRechazo('El enlace tiene una fecha futura. Revisa la hora del servidor.');
        if ($exp < $iat || $exp - $iat > self::VIDA_MAXIMA) throw new TukanCentralRechazo('El enlace dura más de lo permitido.');

        // 4. Nonce de un solo uso (se guarda hasta que el token ya no podría servir)
        $nonce = $p['nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/^[0-9a-f]{32}$/', $nonce)) throw new TukanCentralRechazo('El enlace no es válido (nonce).');
        $this->db->prepare('DELETE FROM tukan_central_nonces WHERE vence < to_timestamp(?)')->execute([$ahora]);
        $st = $this->db->prepare('INSERT INTO tukan_central_nonces (nonce, vence) VALUES (?, to_timestamp(?)) ON CONFLICT (nonce) DO NOTHING');
        $st->execute([$nonce, $exp + self::TOLERANCIA]);
        if ($st->rowCount() !== 1) throw new TukanCentralRechazo('Este enlace ya se usó. Vuelve a intentar desde el Panel Tukán.');

        // 5. Correo válido
        $email = $p['email'] ?? null;
        if (!is_string($email) || mb_strlen($email) > 120 || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            throw new TukanCentralRechazo('El correo del enlace no es válido.');
        }
        $p['email'] = strtolower(trim($email));
        return $p;
    }

    /** 6. Límite: máximo 30 pedidos por minuto por IP. Devuelve false si ya se pasó. */
    public function dentroDelLimite(string $ip, ?int $ahora = null): bool
    {
        $ahora ??= time();
        $minuto = $ahora - $ahora % 60;
        $this->db->prepare("DELETE FROM tukan_central_pedidos WHERE minuto < to_timestamp(?) - interval '5 minutes'")->execute([$minuto]);
        $st = $this->db->prepare('INSERT INTO tukan_central_pedidos (ip, minuto, n) VALUES (?, to_timestamp(?), 1)
                                  ON CONFLICT (ip, minuto) DO UPDATE SET n = tukan_central_pedidos.n + 1 RETURNING n');
        $st->execute([mb_substr($ip, 0, 45), $minuto]);
        return (int) $st->fetchColumn() <= self::LIMITE_POR_MINUTO;
    }

    // ------------------------------------------------------------------
    // SSO: busca o crea el super usuario
    // ------------------------------------------------------------------

    /**
     * Super usuario con el correo del token (lo crea la primera vez, con una contraseña aleatoria que nadie conoce).
     * Deja constancia en el registro del panel. Devuelve su id. La sesión la inicia tukan_central.php.
     */
    public function superUsuario(array $p, string $ip = ''): int
    {
        $email = strtolower(trim((string) $p['email']));
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($p['nombre'] ?? '')) ?? '');
        if (mb_strlen($nombre) < 2) $nombre = explode('@', $email)[0];
        $nombre = mb_substr($nombre, 0, 100);

        $buscar = $this->db->prepare('SELECT id, activo FROM superadmins WHERE lower(email) = ? ORDER BY id LIMIT 1');
        $buscar->execute([$email]);
        $a = $buscar->fetch();
        $nuevo = false;
        if (!$a) {
            $st = $this->db->prepare('INSERT INTO superadmins (nombre, email, password_hash, creado_por_central) VALUES (?, ?, ?, true)
                                      ON CONFLICT (email) DO NOTHING RETURNING id');
            $st->execute([$nombre, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)]);
            $id = $st->fetchColumn();
            $nuevo = $id !== false;
            if (!$nuevo) {   // otro pedido lo creó al mismo tiempo
                $buscar->execute([$email]);
                $a = $buscar->fetch();
            } else {
                $a = ['id' => (int) $id, 'activo' => true];
            }
        }
        if (!$a['activo']) throw new TukanCentralRechazo('Tu super usuario está desactivado en ' . self::NOMBRE . '.');
        $id = (int) $a['id'];
        $this->db->prepare('UPDATE superadmins SET ultimo_ingreso = now() WHERE id = ?')->execute([$id]);
        $registro = $this->db->prepare('INSERT INTO registro_admin (admin_id, accion) VALUES (?, ?)');
        if ($nuevo) $registro->execute([$id, 'Super usuario creado por el Panel Tukán Central']);
        $registro->execute([$id, mb_substr('Entró desde el Panel Tukán Central' . ($ip !== '' ? ' · IP ' . $ip : ''), 0, 300)]);
        return $id;
    }

    // ------------------------------------------------------------------
    // Estado: cifras del programa (solo lectura, sin datos personales salvo nombres de negocios)
    // ------------------------------------------------------------------

    public function estado(?DateTimeImmutable $hoy = null): array
    {
        $hoy ??= new DateTimeImmutable('today');
        $panel = new PanelTukan($this->db);
        $todos = $panel->salones('', '', $hoy);   // mismo estado de cuenta que el panel (Planes::estadoCuenta)
        $cuenta = array_fill_keys(array_keys(PanelTukan::ESTADOS), 0);
        foreach ($todos as $s) $cuenta[$s['estado_cuenta']]++;

        $hace30 = $hoy->modify('-30 days')->format('Y-m-d');
        $en7 = $hoy->modify('+7 days')->format('Y-m-d');
        $pruebas = array_filter($todos, fn($s) => $s['estado_cuenta'] === 'prueba' && $s['prueba_hasta'] && $s['prueba_hasta'] <= $en7);
        usort($pruebas, fn($a, $b) => [$a['prueba_hasta'], $a['nombre']] <=> [$b['prueba_hasta'], $b['nombre']]);
        // "Sin pagar" del panel: prueba terminada o plan vencido (no pueden entrar hasta pagar). "desde" = primer día sin pagar.
        $vencidos = [];
        foreach ($todos as $s) {
            if (!in_array($s['estado_cuenta'], ['prueba_vencida', 'vencido'], true)) continue;
            $fin = $s['estado_cuenta'] === 'vencido' ? $s['activo_hasta'] : $s['prueba_hasta'];
            $vencidos[] = ['nombre' => (string) $s['nombre'], 'desde' => $fin ? (new DateTimeImmutable($fin))->modify('+1 day')->format('Y-m-d') : null];
        }
        usort($vencidos, fn($a, $b) => [$a['desde'], $a['nombre']] <=> [$b['desde'], $b['nombre']]);

        $inicioMes = $hoy->modify('first day of this month')->format('Y-m-d');
        $inicioSiguiente = $hoy->modify('first day of next month')->format('Y-m-d');
        $contar = function (string $sql, array $params = []): int {
            $st = $this->db->prepare($sql);
            $st->execute($params);
            return (int) $st->fetchColumn();
        };

        return [
            'ok' => true,
            'protocolo' => self::PROTOCOLO,
            'producto' => self::PRODUCTO,
            'nombre' => self::NOMBRE,
            'version' => self::version(dirname(__DIR__)),
            'generado' => (new DateTimeImmutable('now'))->format('c'),
            'negocios' => [
                'total' => count($todos),
                'prueba' => $cuenta['prueba'],
                'activos' => $cuenta['activo'],
                'por_vencer' => $cuenta['por_vencer'],
                'vencidos' => $cuenta['vencido'] + $cuenta['prueba_vencida'],
                'suspendidos' => $cuenta['suspendido'] + $cuenta['cancelado'],
            ],
            'nuevos_30d' => count(array_filter($todos, fn($s) => substr((string) $s['creado_en'], 0, 10) >= $hace30)),
            'ingresos_mes' => round($panel->cobradoMes($hoy->format('Y-m')), 2),
            'ingresos_mes_anterior' => round($panel->cobradoMes($hoy->modify('first day of last month')->format('Y-m')), 2),
            'pruebas_vencen_7d' => array_slice(array_map(fn($s) => ['nombre' => (string) $s['nombre'], 'vence' => (string) $s['prueba_hasta']], $pruebas), 0, self::MAX_LISTA),
            'pagos_vencidos' => array_slice($vencidos, 0, self::MAX_LISTA),
            'extra' => [
                'Citas este mes' => $contar("SELECT count(*) FROM citas WHERE inicio >= ? AND inicio < ? AND estado NOT IN ('cancelada','rechazada')",
                                            [$inicioMes, $inicioSiguiente]),
                'Citas atendidas este mes' => $contar("SELECT count(*) FROM citas WHERE inicio >= ? AND inicio < ? AND estado = 'atendida'",
                                                      [$inicioMes, $inicioSiguiente]),
                'Clientes' => $contar('SELECT count(*) FROM clientes'),
                'Peluqueros activos' => $contar('SELECT count(*) FROM profesionales WHERE activo'),
                'Usuarios activos' => $contar('SELECT count(*) FROM usuarios WHERE activo'),
                'Ingreso mensual recurrente' => round($panel->ingresoMensual($hoy), 2),
                'Pruebas terminadas sin pagar' => $cuenta['prueba_vencida'],
                'Cancelados' => $cuenta['cancelado'],
            ],
        ];
    }

    /** Commit corto de git leído de .git/HEAD (sin ejecutar comandos), o un texto si no se puede. */
    public static function version(string $raiz): string
    {
        $git = $raiz . '/.git';
        if (is_file($git) && is_readable($git)) {   // .git como archivo ("gitdir: ...", worktrees)
            if (preg_match('/^gitdir:\s*(.+)$/m', (string) file_get_contents($git), $m)) {
                $git = str_starts_with(trim($m[1]), '/') ? trim($m[1]) : $raiz . '/' . trim($m[1]);
            }
        }
        if (!is_file("$git/HEAD") || !is_readable("$git/HEAD")) return 'sin-version';
        $head = trim((string) file_get_contents("$git/HEAD"));
        if (preg_match('/^[0-9a-f]{40,64}$/', $head)) return substr($head, 0, 7);
        if (!preg_match('#^ref:\s*(refs/[A-Za-z0-9._/-]+)$#', $head, $m) || str_contains($m[1], '..')) return 'sin-version';
        $ref = $m[1];
        $dirs = [$git];
        if (is_file("$git/commondir") && is_readable("$git/commondir")) {
            $c = trim((string) file_get_contents("$git/commondir"));
            $dirs[] = str_starts_with($c, '/') ? $c : "$git/$c";
        }
        foreach ($dirs as $d) {
            if (is_file("$d/$ref") && is_readable("$d/$ref")) {
                $h = trim((string) file_get_contents("$d/$ref"));
                if (preg_match('/^[0-9a-f]{40,64}$/', $h)) return substr($h, 0, 7);
            }
            if (is_file("$d/packed-refs") && is_readable("$d/packed-refs")) {
                foreach (file("$d/packed-refs", FILE_IGNORE_NEW_LINES) ?: [] as $linea) {
                    if (preg_match('/^([0-9a-f]{40,64}) (.+)$/', $linea, $p) && $p[2] === $ref) return substr($p[1], 0, 7);
                }
            }
        }
        return 'sin-version';
    }
}
