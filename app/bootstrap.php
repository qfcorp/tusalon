<?php
declare(strict_types=1);

/**
 * Arranque de TuSalón: configuración, sesión, conexión y funciones de ayuda.
 * La configuración vive en config/.env (fuera de la carpeta pública y fuera de GitHub).
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');

// 1) Configuración: config/.env -> variables de entorno
$envFile = RAIZ . '/config/.env';
if (is_file($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) ?: [] as $k => $v) {
        if (getenv($k) === false) {
            putenv("$k=$v");
        }
    }
}

// 2) Cargar clases de src/
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) {
            require $archivo;
        }
    }
});

// 3) Sesión segura
session_name('tusalon');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

// ---------------------------------------------------------------------
// Funciones de ayuda
// ---------------------------------------------------------------------

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= \TuSalon\Db::conectar();
}

/** Escapa texto para mostrarlo en HTML. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $ruta, array $params = []): string
{
    $q = http_build_query(array_merge(['r' => $ruta], $params));
    return '/?' . $q;
}

function redirigir(string $ruta, array $params = []): never
{
    header('Location: ' . url($ruta, $params));
    exit;
}

/** Dinero al estilo ecuatoriano: $1.234,50 */
function dinero(float|string|null $v): string
{
    $v = (float) $v;
    return ($v < 0 ? '−$' : '$') . number_format(abs($v), 2, ',', '.');
}

function aviso(string $texto, string $tipo = 'ok'): void
{
    $_SESSION['avisos'][] = ['texto' => $texto, 'tipo' => $tipo];
}

function tomar_avisos(): array
{
    $a = $_SESSION['avisos'] ?? [];
    unset($_SESSION['avisos']);
    return $a;
}

function token_csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function campo_csrf(): string
{
    return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

function verificar_csrf(): void
{
    if (!hash_equals(token_csrf(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('La página caducó. Vuelve atrás y recarga antes de enviar.');
    }
}

function es_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Usuario con sesión iniciada (o null). */
function usuario(): ?array
{
    static $u = false;
    if ($u !== false) {
        return $u;
    }
    if (empty($_SESSION['usuario_id'])) {
        return $u = null;
    }
    $st = db()->prepare(
        'SELECT u.id, u.nombre, u.email, u.rol, u.profesional_id, u.salon_id,
                s.nombre AS salon, s.slug, s.plan_codigo, s.estado, s.prueba_hasta, s.activo_hasta,
                p.tipo AS tipo_profesional
           FROM usuarios u JOIN salones s ON s.id = u.salon_id
      LEFT JOIN profesionales p ON p.id = u.profesional_id
          WHERE u.id = ? AND u.activo'
    );
    $st->execute([$_SESSION['usuario_id']]);
    return $u = ($st->fetch() ?: null);
}

function requiere_sesion(): array
{
    $u = usuario();
    if (!$u) {
        redirigir('login');
    }
    return $u;
}

function es_completa(array $u): bool
{
    return $u['plan_codigo'] === 'completa';
}

/** Días que le quedan a la prueba gratis (null si ya pagó). */
function dias_prueba(array $u): ?int
{
    if ($u['estado'] !== 'prueba' || !$u['prueba_hasta']) {
        return null;
    }
    $hoy = new DateTimeImmutable('today');
    $fin = new DateTimeImmutable($u['prueba_hasta']);
    return $hoy > $fin ? -1 : (int) $hoy->diff($fin)->days;
}

/** Muestra una vista dentro del diseño general. */
function vista(string $nombre, array $datos = [], string $titulo = 'TuSalón'): void
{
    extract($datos, EXTR_SKIP);
    $u = usuario();
    ob_start();
    require RAIZ . "/app/vistas/$nombre.php";
    $contenido = ob_get_clean();
    require RAIZ . '/app/vistas/_diseno.php';
}

/** Vista del panel del super administrador (Tukán). */
function vista_admin(string $nombre, array $datos = [], string $titulo = 'Panel Tukán'): void
{
    extract($datos, EXTR_SKIP);
    $admin = superadmin();
    ob_start();
    require RAIZ . "/app/vistas/$nombre.php";
    $contenido = ob_get_clean();
    require RAIZ . '/app/vistas/_admin.php';
}

/** Muestra una vista pública (portal de reservas), sin menú del sistema. */
function vista_publica(string $nombre, array $datos = [], string $titulo = 'TuSalón'): void
{
    extract($datos, EXTR_SKIP);
    ob_start();
    require RAIZ . "/app/vistas/$nombre.php";
    $contenido = ob_get_clean();
    require RAIZ . '/app/vistas/_publico.php';
}

function es_peluquero(?array $u): bool
{
    return $u !== null && $u['rol'] === 'profesional';
}

function planes(): \TuSalon\Planes
{
    static $p = null;
    return $p ??= new \TuSalon\Planes(db());
}

/** WhatsApp de ventas de Tukán (se cambia en el panel del super administrador). */
function whatsapp_ventas(): string
{
    return planes()->ajuste('whatsapp_ventas') ?: '593996408397';
}

/** Precio mensual de un plan, con formato ($25,00). */
function precio_plan(string $codigo): string
{
    foreach (planes()->lista() as $p) {
        if ($p['codigo'] === $codigo) return dinero($p['precio_mensual']);
    }
    return '';
}

/** Super administrador con sesión (o null). */
function superadmin(): ?array
{
    static $a = false;
    if ($a !== false) return $a;
    if (empty($_SESSION['admin_id'])) return $a = null;
    $st = db()->prepare('SELECT id, nombre, email FROM superadmins WHERE id = ? AND activo');
    $st->execute([$_SESSION['admin_id']]);
    return $a = ($st->fetch() ?: null);
}

function registrar_admin(string $accion, ?int $salonId = null): void
{
    $a = superadmin();
    db()->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (?,?,?)')
        ->execute([$a['id'] ?? null, $salonId, mb_substr($accion, 0, 300)]);
}
