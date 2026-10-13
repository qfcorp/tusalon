<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// Enlace de la app de Android con este sitio (Digital Asset Links)
if (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) === '/.well-known/assetlinks.json') {
    if (!\TuSalon\GooglePlay::activo()) { http_response_code(404); exit; }
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(\TuSalon\GooglePlay::assetlinks(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
// La app de Google Play abre el sistema con ?app=play: se recuerda para mostrar el cobro de Google
if (($_GET['app'] ?? '') === 'play' && \TuSalon\GooglePlay::activo()) {
    marcar_app_play();
}

$publicas = ['login', 'registro', 'reservar', 'horas', 'cliente_entrar', 'cliente_salir', 'mi_cuenta', 'foto', 'telegram', 'confirmar', 'payphone', 'privacidad'];
$privadas = ['inicio', 'agenda', 'cita', 'cita_nueva', 'clientes', 'cliente', 'servicios', 'equipo',
             'caja', 'cobrar', 'cuentas', 'completa', 'salir', 'prueba_terminada', 'horario', 'solicitudes', 'mi_portal', 'fotos',
             'horario_peluquero', 'avisos_clientes', 'reporte',
             'inventario', 'paquetes', 'tarjetas', 'puntos', 'ficha', 'ficha_foto', 'comprobante', 'play_compra'];
// Lo único que ve un peluquero con acceso propio
$dePeluquero = ['mi_portal', 'solicitudes', 'fotos', 'salir', 'prueba_terminada', 'ficha', 'ficha_foto', 'comprobante'];

// Panel del super administrador (Tukán): sesión aparte
$deAdmin = ['admin', 'admin_salones', 'admin_salon', 'admin_nuevo', 'admin_planes', 'admin_ver', 'admin_volver', 'admin_salir'];
$publicas[] = 'admin_entrar';

$ruta = (string) ($_GET['r'] ?? 'inicio');
if (in_array($ruta, $deAdmin, true) && !superadmin()) {
    redirigir('admin_entrar');
}
if (!in_array($ruta, array_merge($publicas, $privadas, $deAdmin), true)) {
    http_response_code(404);
    $ruta = usuario() ? 'inicio' : 'login';
}

if (in_array($ruta, $privadas, true)) {
    $u = requiere_sesion();
    if (es_peluquero($u) && !in_array($ruta, $dePeluquero, true)) {
        redirigir('mi_portal');
    }
    if (!es_peluquero($u) && $ruta === 'mi_portal') {
        redirigir('inicio');
    }
    // Prueba vencida, plan vencido o cuenta suspendida: solo puede ver cómo pagar
    if (!in_array($ruta, ['prueba_terminada', 'salir', 'completa', 'play_compra'], true)
        && !planes()->alDia($u) && !(!empty($_SESSION['admin_viendo']) && superadmin())) {
        redirigir('prueba_terminada');
    }
}

try {
    require RAIZ . "/app/paginas/$ruta.php";
} catch (Throwable $e) {
    error_log('TuSalón: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if ($ruta === 'horas') {
        echo json_encode(['horas' => [], 'en_confirmacion' => [], 'error' => true]);
    } else {
        vista('error', ['mensaje' => 'Algo falló al procesar esta página. Intenta de nuevo; si sigue pasando, escríbenos por WhatsApp.']);
    }
}
