<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$publicas = ['login', 'registro', 'reservar', 'horas', 'cliente_entrar', 'cliente_salir', 'mi_cuenta', 'foto', 'telegram', 'confirmar'];
$privadas = ['inicio', 'agenda', 'cita', 'cita_nueva', 'clientes', 'cliente', 'servicios', 'equipo',
             'caja', 'cobrar', 'cuentas', 'completa', 'salir', 'prueba_terminada', 'horario', 'solicitudes', 'mi_portal', 'fotos',
             'horario_peluquero', 'avisos_clientes', 'reporte'];
// Lo único que ve un peluquero con acceso propio
$dePeluquero = ['mi_portal', 'solicitudes', 'fotos', 'salir', 'prueba_terminada'];

$ruta = (string) ($_GET['r'] ?? 'inicio');
if (!in_array($ruta, array_merge($publicas, $privadas), true)) {
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
    // Prueba vencida y sin pagar: solo puede ver cómo pagar
    if (!in_array($ruta, ['prueba_terminada', 'salir', 'completa'], true)
        && ($u['estado'] === 'suspendido' || dias_prueba($u) === -1)) {
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
