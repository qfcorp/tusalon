<?php
/**
 * "Solicitar demo" (antes: registro abierto con periodo de prueba). Todo se pide demo:
 * este formulario NO crea un salón. Guarda el pedido (PedidosDemo) y Tukán lo atiende desde el Panel Tukán Central
 * (solicitud demo_pedida → crear_demo) o desde su propio panel (Inicio → Pedidos de demo).
 */
if (usuario()) {
    redirigir('inicio');
}
$errores = [];
$enviado = !empty($_SESSION['demo_pedida']);
unset($_SESSION['demo_pedida']);
$d = ['nombre' => '', 'salon' => '', 'ciudad' => '', 'whatsapp' => '', 'email' => '', 'necesita' => '', 'modulos' => []];

if (es_post()) {
    verificar_csrf();
    foreach (['nombre', 'salon', 'ciudad', 'whatsapp', 'email', 'necesita'] as $k) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $d['modulos'] = \TuSalon\Modulos::limpiar(array_map('strval', (array) ($_POST['modulos'] ?? [])));
    if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {   // trampa para robots: una persona no ve este campo
        $_SESSION['demo_pedida'] = true;
        redirigir('registro');
    }
    try {
        (new \TuSalon\PedidosDemo(db()))->crear($d, ip_visitante());
        $_SESSION['demo_pedida'] = true;
        redirigir('registro');
    } catch (RuntimeException $e) {
        $errores[] = $e->getMessage();
    }
}
vista('registro', ['errores' => $errores, 'd' => $d, 'enviado' => $enviado], 'Solicitar demo · TuSalón');
