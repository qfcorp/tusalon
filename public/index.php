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
             'inventario', 'paquetes', 'tarjetas', 'puntos', 'ficha', 'ficha_foto', 'comprobante', 'play_compra',
             'gastos', 'recepcion', 'mis_clientes'];
// Lo único que ve un peluquero con acceso propio (mis_clientes: solo quien alquila el puesto)
$dePeluquero = ['mi_portal', 'solicitudes', 'fotos', 'salir', 'prueba_terminada', 'ficha', 'ficha_foto', 'comprobante', 'mis_clientes'];
// Recepción (plan Completa): agenda, citas, cobros, caja y clientes; nada de configuración, cuentas del equipo ni ganancias
$deRecepcion = ['inicio', 'agenda', 'cita', 'cita_nueva', 'clientes', 'cliente', 'caja', 'cobrar', 'solicitudes', 'avisos_clientes',
                'comprobante', 'salir', 'prueba_terminada'];

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

// Anexo A: las páginas públicas de un salón pausado dicen "temporalmente no disponible" (503); las de un salón en la
// papelera no existen (404). Cada página pública está en Papelera::RUTAS_PUBLICAS o PUBLICAS_SIN_SALON.
if (in_array($ruta, $publicas, true) && !in_array($ruta, \TuSalon\Papelera::PUBLICAS_SIN_SALON, true)) {
    $papelera = new \TuSalon\Papelera(db());
    $sidPublico = $papelera->salonDeRuta($ruta, $_GET);
    $estadoPublico = $sidPublico === null ? 'normal' : $papelera->estadoPublico($sidPublico);
    if ($estadoPublico !== 'normal') {
        $borrado = $estadoPublico === 'eliminado';
        http_response_code($borrado ? 404 : 503);
        header('Cache-Control: no-store');
        if (!$borrado) header('Retry-After: 3600');
        $texto = $borrado ? 'Esta página no está disponible.' : 'Temporalmente no disponible. Intenta más tarde.';
        if ($ruta === 'horas') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['horas' => [], 'en_confirmacion' => [], 'error' => $texto, 'disponible' => false], JSON_UNESCAPED_UNICODE);
        } elseif ($ruta === 'foto') {
            header('Content-Type: text/plain; charset=utf-8');
            echo $texto;
        } else {
            vista_publica($borrado ? 'reservar_no' : 'no_disponible', ['salon' => null], $borrado ? 'TuSalón' : 'Temporalmente no disponible · TuSalón');
        }
        exit;
    }
}

if (in_array($ruta, $privadas, true)) {
    $u = requiere_sesion();
    if (es_peluquero($u) && !in_array($ruta, $dePeluquero, true)) {
        redirigir('mi_portal');
    }
    if (es_recepcion($u) && !in_array($ruta, $deRecepcion, true)) {
        redirigir('inicio');
    }
    if (es_recepcion($u) && !es_completa($u) && $ruta !== 'salir') {
        vista('error', ['mensaje' => 'La cuenta de recepción es del plan Completa. Pídele al dueño que active el plan Completa para volver a entrar.',
                        'salir' => true], 'Recepción · TuSalón');
        return;
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
