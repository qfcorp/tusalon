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
// «Cancelar mi plan» (módulo común src/TukanCancelarPlan.php, 7 nov 2026): solo el dueño o sus administradores
require_once dirname(__DIR__, 2) . '/src/TukanCancelarPlan.php';
require_once dirname(__DIR__, 2) . '/src/TukanLegal.php';
if (es_post() && ($_POST['accion'] ?? '') === 'aceptar_terminos_tukan') {   // aviso de arriba (vistas/_diseno.php)
    verificar_csrf();
    if (es_dueno_o_admin($u)) {
        $okTl = TukanLegal::procesar(db(), (string) $sid, $u['nombre'] . ' <' . $u['email'] . '>');
        aviso($okTl ? 'Gracias: aceptaste los términos y la política de privacidad.' : 'Para seguir, marca «Leí y acepto los términos y la política de privacidad».', $okTl ? 'ok' : 'error');
    }
    redirigir('inicio');
}
if (es_post() && in_array($_POST['accion'] ?? '', ['cancelar_plan', 'seguir_plan'], true)) {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede cancelar el plan.');
        if (en_app_play()) throw new RuntimeException('En la app cancelas en Google Play → Pagos y suscripciones.');
        if ($_POST['accion'] === 'seguir_plan') {
            TukanCancelarPlan::seguir(db(), (string) $sid, (string) ($u['salon'] ?? ''), (string) $u['email']);
            aviso('¡Qué bueno! Tu plan sigue como siempre.');
        } else {
            if (empty($_POST['confirmo'])) throw new RuntimeException('Para cancelar, marca «Sí, quiero cancelar mi plan».');
            $rTc = TukanCancelarPlan::pedir(db(), (string) $sid, (string) ($u['salon'] ?? ''), $u['activo_hasta'] ?? null, (string) ($_POST['motivo'] ?? ''),
                $u['nombre'] . ' <' . $u['email'] . '>', (string) $u['email']);
            aviso('Listo: tu plan no se renovará.' . ($rTc['hasta'] ? ' Sigues usando todo hasta el ' . date('d/m/Y', strtotime($rTc['hasta'])) . '.' : '')
                . ' Tus datos no se borran. Si cambias de idea, toca «Seguir con mi plan».');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('completa');
}
if (!es_post()) TukanCancelarPlan::reintentar(db(), (string) $sid, (string) $u['email']);
$cancelacion = TukanCancelarPlan::estado(db(), (string) $sid);
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
vista('completa', compact('cancelacion', 'detalle', 'catalogo', 'planes', 'plan', 'precios', 'pedidos', 'resaltar'), 'Mi plan · TuSalón');
