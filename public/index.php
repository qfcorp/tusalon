<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

$publicas = ['login', 'registro'];
$privadas = ['inicio', 'agenda', 'cita', 'cita_nueva', 'clientes', 'cliente', 'servicios', 'equipo',
             'caja', 'cobrar', 'cuentas', 'completa', 'salir', 'prueba_terminada'];

$ruta = (string) ($_GET['r'] ?? 'inicio');
if (!in_array($ruta, array_merge($publicas, $privadas), true)) {
    http_response_code(404);
    $ruta = usuario() ? 'inicio' : 'login';
}

if (in_array($ruta, $privadas, true)) {
    $u = requiere_sesion();
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
    vista('error', ['mensaje' => 'Algo falló al procesar esta página. Intenta de nuevo; si sigue pasando, escríbenos por WhatsApp.']);
}
