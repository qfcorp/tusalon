<?php
declare(strict_types=1);

/**
 * Conector con el Panel Tukán Central (protocolo v1). No pasa por el enrutador de index.php.
 *   /tukan_central.php?accion=sso&t=<token>     entra como super usuario y abre el panel de TuSalón
 *   /tukan_central.php?accion=estado&t=<token>  cifras de TuSalón en JSON (solo lectura)
 * Sin TUKAN_CENTRAL_ID o TUKAN_CENTRAL_SECRET en config/.env responde 404 y no hace nada más.
 */

use TuSalon\{TukanCentral, TukanCentralRechazo};

$accion = (string) ($_GET['accion'] ?? '');
if ($accion === 'estado') define('TUKAN_SIN_SESION', true);   // el estado no abre sesión
require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store');
if (!TukanCentral::config()) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No encontrado\n";
    exit;
}

/** Corta con un error: JSON para el estado, página sencilla para el SSO. */
$fallar = function (int $codigo, string $mensaje) use ($accion): never {
    http_response_code($codigo);
    if ($accion === 'sso') {
        vista_admin('central_error', ['mensaje' => $mensaje, 'urlCentral' => TukanCentral::urlCentral()], 'No se pudo entrar · Panel Tukán');
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
};

if (!in_array($accion, ['sso', 'estado'], true)) {
    $fallar(400, 'Acción no válida.');
}

try {
    $central = new TukanCentral(db());
    if (!$central->dentroDelLimite(ip_visitante())) {
        header('Retry-After: 60');
        $fallar(429, 'Demasiados pedidos. Espera un minuto y vuelve a intentar.');
    }
    $p = $central->validar((string) ($_GET['t'] ?? ''), $accion);

    if ($accion === 'estado') {
        $estado = $central->estado();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }

    // SSO: igual que el ingreso normal del super usuario (admin_entrar)
    $id = $central->superUsuario($p, ip_visitante());
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $id;
    $_SESSION['desde_central'] = 1;
    unset($_SESSION['intentos_admin']);
    redirigir('admin');
} catch (TukanCentralRechazo $e) {
    $fallar(401, $e->getMessage());
} catch (Throwable $e) {
    error_log('TuSalón central: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    $fallar(500, 'Algo falló en TuSalón. Intenta de nuevo en un momento.');
}
