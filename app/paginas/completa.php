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
// «Pagar con tarjeta» (módulo común src/TukanPagoPlan.php, 10 nov 2026): el dueño elige su plan y paga solo; el cobro lo hace el
// Panel Tukán Central (Plux). Si el Panel no contesta o lo tiene apagado, Mi plan queda como siempre. En la app de Google Play, nunca.
require_once dirname(__DIR__, 2) . '/src/TukanPagoPlan.php';
// Una cuenta pausada o cerrada por Tukán no se reactiva pagando: eso lo decide Tukán.
$pagoPuede = es_dueno_o_admin($u) && !en_app_play() && !in_array($u['estado'] ?? '', ['suspendido', 'cancelado'], true);
$pagoOpciones = fn(): array => TukanPagoPlan::opciones($mods->planes(), planes()->periodos(),
    fn(string $pl, string $per) => planes()->cotizar($pl, $per, $sid), fn(string $per) => planes()->textoPeriodo($per),
    (string) $u['plan_codigo'], ($u['precio_acordado'] ?? null) !== null && $u['precio_acordado'] !== '', count(array_diff(\TuSalon\Modulos::ids(), \TuSalon\Modulos::FUERA_DE_PLANES)));
// «Te sugerimos el Completo» (decisión de Dimitry, 10 nov 2026): si con su plan y sus módulos paga igual o más que el Completo (o le
// faltan $5 o menos), se le avisa con el botón «Pasarme al Completo». $sugerir($m) = cómo quedaría si además agrega el módulo $m.
$modTotal = count(array_diff(\TuSalon\Modulos::ids(), \TuSalon\Modulos::FUERA_DE_PLANES));
$planCompleto = TukanPagoPlan::completo($mods->planes(), $modTotal);
$sugerir = function (?string $conModulo = null) use ($mods, $u, $modTotal, $planCompleto): ?array {
    if ($planCompleto === null) return null;
    $f = salon_de_usuario($u);
    if ($conModulo !== null) $f['modulos_extra'] = implode(',', \TuSalon\Modulos::conDependencias(array_merge(\TuSalon\Modulos::limpiar($f['modulos_extra'] ?? ''), [$conModulo])));
    $d = $mods->detalle($f);
    return TukanPagoPlan::sugerirCompleto($mods->planes(), $modTotal, (string) $u['plan_codigo'], (float) $d['mensual'], (float) $mods->detalle($f, $planCompleto)['mensual'], $d['precio_acordado'] !== null);
};
if (es_post() && ($_POST['accion'] ?? '') === 'pasar_completo') {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede cambiar el plan.');
        if (en_app_play()) throw new RuntimeException('En la app el plan se paga con Google Play.');
        if ($planCompleto === null || $planCompleto === $u['plan_codigo']) throw new RuntimeException('Ya tienes el plan más completo.');
        $nomCompleto = (string) $mods->planes()[$planCompleto]['nombre'];
        $pagoEstado = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
        $viva = TukanPagoPlan::suscripcion($pagoEstado);
        if (TukanPagoPlan::activo($pagoEstado) && !$viva) {   // paga el Completo con tarjeta: sale marcado en «Paga tu plan con tarjeta»
            aviso('Elige cada cuánto pagar el plan ' . $nomCompleto . ' y toca «Pagar con tarjeta».');
            redirigir('completa', ['elegir' => $planCompleto]);
        }
        if ($viva && !TukanPagoPlan::pagada($pagoEstado)) throw new RuntimeException('Tienes un pago con tarjeta sin terminar: toca «Elegir otro plan» y elige el ' . $nomCompleto . '.');
        if ($viva) {   // suscripción viva y pagada: pasa ya al Completo; el valor nuevo rige según conteste el Panel
            $a = TukanPagoPlan::ajustar((string) $sid, (float) planes()->cotizar($planCompleto, (string) ($viva['periodo'] ?? 'mensual'), $sid)['monto'], (string) $u['email'], true);
            if (!in_array($a['resultado'], ['subio', 'baja_al_renovar', 'igual'], true)) throw new RuntimeException($a['resultado'] === 'sin_panel' ? 'No se pudo conectar con el sistema de pagos. Intenta en unos minutos.' : $a['texto']);
            (new \TuSalon\PanelTukan(db()))->cambiarPlan($sid, $planCompleto);
            aviso(trim('¡Listo! Ya tienes el plan ' . $nomCompleto . ', con todos los módulos. ' . $a['texto']));
            redirigir('completa');
        }
        // Sin pago con tarjeta: queda el pedido para Tukán (como los pedidos de módulos)
        $faltan = array_values(array_diff($mods->planes()[$planCompleto]['modulos'], $mods->detalle(salon_de_usuario($u))['contratados']));
        $mods->pedir($sid, (int) $u['id'], $faltan, 'QUIERE PASARSE AL PLAN ' . mb_strtoupper($nomCompleto) . ' (cambiarle el plan, no encender módulos sueltos)');
        aviso('Listo. Pediste pasarte al plan ' . $nomCompleto . '. Tukán revisa tu pedido y te escribe.');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('completa');
}
if (es_post() && in_array($_POST['accion'] ?? '', ['pagar_tarjeta', 'descartar_pago_tarjeta'], true)) {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede pagar el plan.');
        if (en_app_play()) throw new RuntimeException('En la app el plan se paga con Google Play.');
        $pagoEstado = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
        if (!TukanPagoPlan::activo($pagoEstado)) throw new RuntimeException('El pago con tarjeta no está disponible por ahora. Escríbele a Tukán y te ayudamos.');
        if ($_POST['accion'] === 'descartar_pago_tarjeta') {
            TukanPagoPlan::descartar($pagoEstado, (string) $sid, (int) ($_POST['id'] ?? 0), (string) $u['email']);
            aviso('Listo: ya puedes elegir otro plan.');
            redirigir('completa');
        }
        if (TukanPagoPlan::suscripcion($pagoEstado)) throw new RuntimeException('Ya tienes una suscripción con tarjeta. No hace falta pagar otra vez.');
        [$plPago, $perPago] = array_pad(explode('|', (string) ($_POST['opcion'] ?? ''), 2), 2, '');
        $opPago = $pagoOpciones()[$plPago] ?? null;
        if (!$opPago || !isset($opPago['precios'][$perPago])) throw new RuntimeException('Elige el plan y cada cuánto quieres pagar.');
        $neg = db()->query('SELECT ruc, telefono FROM salones WHERE id = ' . $sid)->fetch() ?: [];
        $cobro = TukanPagoPlan::crear([
            'cliente_id' => (string) $sid, 'nombre' => (string) ($u['salon'] ?? ''), 'plan' => $plPago, 'plan_nombre' => $opPago['nombre'],
            'periodo' => $perPago, 'meses' => $opPago['precios'][$perPago]['meses'], 'subtotal' => $opPago['precios'][$perPago]['monto'],
            'suscripcion' => ($_POST['forma'] ?? 'suscripcion') !== 'una_vez',
            'comprador' => ['nombre' => (string) $u['nombre'], 'correo' => (string) $u['email'], 'telefono' => (string) ($neg['telefono'] ?? '')],
            'ruc' => (string) ($neg['ruc'] ?? ''), 'volver' => \TuSalon\Agenda::urlBase() . url('completa'),
        ], (string) $u['email']);
        header('Location: ' . $cobro['url_pago']);   // la página segura de pago (Plux)
        exit;
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
        // Antes de pedirlo: si con este módulo pasaría a pagar igual o más que el Completo, primero se le sugiere el Completo
        if (!en_app_play() && empty($_POST['solo_modulo']) && es_dueno_o_admin($u) && ($u['estado'] ?? '') !== 'prueba'
            && ($sugerir()['nivel'] ?? '') !== 'mayor' && ($sugerir($m)['nivel'] ?? '') === 'mayor') {
            redirigir('completa', ['modulo' => $m, 'sugerir' => 1]);
        }
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
// Pago con tarjeta: solo se pregunta al Panel al mostrar la página (y nunca en la app de Google Play)
$pagoEstado = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
$pagoHtml = TukanPagoPlan::activo($pagoEstado)
    ? TukanPagoPlan::html($pagoEstado, $pagoOpciones(), campo_csrf(), url('completa'), $detalle['demo'], (string) ($_GET['plux'] ?? ''),
        TukanPagoPlan::htmlSugerencia($sugerir()), (string) ($_GET['elegir'] ?? '')) : '';
// El aviso de arriba «te sugerimos el Completo» (nunca en la app de Google Play; a la demo solo si puede pagar aquí y ya ve los valores)
$sugHtml = '';
if (es_dueno_o_admin($u) && !en_app_play() && (!$detalle['demo'] || $pagoHtml !== '')) {
    $mSug = !empty($_GET['sugerir']) && \TuSalon\Modulos::existe((string) ($_GET['modulo'] ?? '')) && !$detalle['demo'] ? (string) $_GET['modulo'] : null;
    $sMod = $mSug !== null ? $sugerir($mSug) : null;
    $sugHtml = $sMod !== null
        ? TukanPagoPlan::htmlSugerencia($sMod, campo_csrf(), url('completa'), $mods->nombre($mSug), '<form method="post" action="' . e(url('completa')) . '" style="display:inline">' . campo_csrf()
            . '<input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="' . e($mSug) . '"><input type="hidden" name="solo_modulo" value="1">'
            . '<button class="boton boton-claro boton-chico" type="submit">Pedir solo este módulo</button></form>')
        : TukanPagoPlan::htmlSugerencia($sugerir(), campo_csrf(), url('completa'));
}
$resaltar = \TuSalon\Modulos::existe((string) ($_GET['modulo'] ?? '')) ? (string) $_GET['modulo'] : '';
vista('completa', compact('pagoHtml', 'sugHtml', 'cancelacion', 'detalle', 'catalogo', 'planes', 'plan', 'precios', 'pedidos', 'resaltar'), 'Mi plan · TuSalón');
