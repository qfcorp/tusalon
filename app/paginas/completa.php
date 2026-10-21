<?php
/**
 * Mi plan (Anexo B): lo que siempre tiene el salón (base), sus módulos encendidos, los que puede agregar y lo que paga
 * al mes por su plan (su contrato). Regla del dueño de Tukán: el precio de lo que el salón NO tiene nunca se muestra:
 * "Pedir cotización" crea un pedido (cambio_modulos) que llega al Panel Tukán Central (o al panel de Tukán), que cotiza,
 * aprueba o rechaza. En la app de Google Play no se muestran precios ni pedidos de módulos
 * (ahí solo se paga el plan con Google; ver docs/GOOGLE_PLAY.md).
 */
$mods = modulos();
$sid = (int) $u['salon_id'];
if (es_post()) {
    verificar_csrf();
    try {
        if (en_app_play()) throw new RuntimeException('Para agregar módulos, comunícate con Tukán.');
        if (($_POST['accion'] ?? '') !== 'pedir') throw new RuntimeException('Acción no válida.');
        $m = (string) ($_POST['modulo'] ?? '');
        if (!\TuSalon\Modulos::existe($m)) throw new RuntimeException('Ese módulo no existe.');
        $mods->pedir($sid, (int) $u['id'], [$m], (string) ($_POST['nota'] ?? ''));
        aviso('Listo. Pediste la cotización de «' . $mods->nombre($m) . '». Tukán te escribe con el valor y para encenderlo.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('completa');
}
$detalle = $mods->detalle(salon_de_usuario($u));
$catalogo = $mods->catalogo();
$planes = $mods->planes();
$plan = $planes[$u['plan_codigo']] ?? ['nombre' => $u['plan_codigo'], 'precio_mensual' => 0, 'modulos' => []];
$precios = [];
foreach (['mensual', 'semestral', 'anual'] as $per) $precios[$per] = planes()->cotizar((string) $u['plan_codigo'], $per, $sid);
$pedidos = $mods->pedidos($sid, false);
$resaltar = \TuSalon\Modulos::existe((string) ($_GET['modulo'] ?? '')) ? (string) $_GET['modulo'] : '';
vista('completa', compact('detalle', 'catalogo', 'planes', 'plan', 'precios', 'pedidos', 'resaltar'), 'Mi plan · TuSalón');
