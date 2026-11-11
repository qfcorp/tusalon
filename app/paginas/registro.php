<?php
/**
 * "Solicitar demo" (antes: registro abierto con periodo de prueba). Todo se pide demo:
 * (10 nov 2026) Demos automáticas: 6 datos obligatorios (un solo teléfono: el celular) y aviso al instante al Panel, que crea la demo de 3 días y manda la clave al correo.
 * este formulario NO crea un salón. Guarda el pedido (PedidosDemo) y Tukán lo atiende desde el Panel Tukán Central
 * (solicitud demo_pedida → crear_demo) o desde su propio panel (Inicio → Pedidos de demo).
 */
if (usuario()) {
    redirigir('inicio');
}
$errores = [];
$enviado = !empty($_SESSION['demo_pedida']);
unset($_SESSION['demo_pedida']);
$d = ['nombre' => '', 'nombre_comercial' => '', 'celular' => '', 'direccion' => '', 'ciudad' => '', 'email' => '', 'necesita' => '', 'modulos' => []];
$porCampo = [];   // campo => mensaje (se muestra junto a cada campo)

if (es_post()) {
    verificar_csrf();
    foreach (['nombre', 'nombre_comercial', 'celular', 'direccion', 'ciudad', 'email', 'necesita'] as $k) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    if ($d['nombre_comercial'] === '') $d['nombre_comercial'] = trim((string) ($_POST['salon'] ?? ''));   // nombres de antes del formulario
    if ($d['celular'] === '') $d['celular'] = trim((string) ($_POST['whatsapp'] ?? ''));
    $d['modulos'] = \TuSalon\Modulos::limpiar(array_map('strval', (array) ($_POST['modulos'] ?? [])));
    if (trim((string) ($_POST['sitio_web'] ?? '')) !== '') {   // trampa para robots: una persona no ve este campo
        $_SESSION['demo_pedida'] = true;
        redirigir('registro');
    }
    try {
        $pedidos = new \TuSalon\PedidosDemo(db());
        $idPedido = $pedidos->crear($d, ip_visitante());
        // Aviso al instante al Panel Tukán Central para que la demo salga en segundos (5 s como máximo). Si no contesta no pasa
        // nada: el pedido ya quedó guardado y el Panel lo ve en su revisión de cada 15 minutos.
        TukanDemos::avisar('demo_pedida:' . $idPedido, [\TuSalon\TukanCentral::class, 'firmar']);
        $_SESSION['demo_pedida'] = true;
        redirigir('registro');
    } catch (RuntimeException $e) {
        $porCampo = isset($pedidos) ? $pedidos->errores : [];
        if (!$porCampo) $errores[] = $e->getMessage();
        else $errores[] = 'Revisa los datos marcados: todos son obligatorios.';
    }
}
vista('registro', ['errores' => $errores, 'porCampo' => $porCampo, 'd' => $d, 'enviado' => $enviado], 'Solicitar demo · TuSalón');
