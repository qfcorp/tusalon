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
        if (en_app_ios()) throw new RuntimeException(TEXTO_NO_EN_APP);   // iPhone (regla de Apple): ni cancelar ni pagar desde la app
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
$pagoPuede = es_dueno_o_admin($u) && !en_app_tienda() && !in_array($u['estado'] ?? '', ['suspendido', 'cancelado'], true);
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
// Subir de plan SIN suscripción con tarjeta (decisión de Dimitry, 10 nov 2026): el cliente con su período pagado (por transferencia, etc.)
// paga el PRORRATEO de los días que faltan, con tarjeta (pedido «mejora» al Panel) o por transferencia («Enviar por WhatsApp»).
// $prorrateoCompleto() = el cálculo, o null si no aplica (demo, vencido, precio acordado o ya tiene el Completo: esos pagan el plan entero).
$prorrateoCompleto = function () use ($mods, $u, $sid, $planCompleto, $pagoPuede): ?array {
    if ($planCompleto === null || $planCompleto === $u['plan_codigo'] || ($u['estado'] ?? '') !== 'activo') return null;
    if (($u['precio_acordado'] ?? null) !== null && $u['precio_acordado'] !== '') return null;
    $f = salon_de_usuario($u);
    $e = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
    return TukanPagoPlan::prorrateo((float) $mods->detalle($f, $planCompleto)['mensual'], (float) $mods->detalle($f)['mensual'], $u['activo_hasta'] ?? null, null, (float) ($e['iva'] ?? 15));
};
if (es_post() && in_array($_POST['accion'] ?? '', ['pagar_mejora', 'pasar_sin_cobro'], true)) {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede cambiar el plan.');
        if (en_app_ios()) throw new RuntimeException(TEXTO_NO_EN_APP);
        if (en_app_play()) throw new RuntimeException('En la app el plan se paga con Google Play.');
        $pr = $prorrateoCompleto();
        if ($pr === null) throw new RuntimeException('Ya no hay un cambio de plan por pagar.');
        $nomCompleto = (string) $mods->planes()[$planCompleto]['nombre'];
        if ($pr['subtotal'] < 1) {   // menos de $1 (o $0: ya paga igual o más que el plan nuevo): no se cobra y pasa directo, aunque no haya pago con tarjeta
            (new \TuSalon\PanelTukan(db()))->cambiarPlan($sid, $planCompleto);
            db()->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)')->execute([$sid, 'Pasó al plan ' . $nomCompleto . ' desde Mi plan: la diferencia era menor a $1 y no se cobró']);
            aviso('La diferencia es menor a $1: no se cobra. Ya tienes el plan ' . $nomCompleto . '.');
            redirigir('completa');
        }
        if ($_POST['accion'] === 'pasar_sin_cobro') throw new RuntimeException('Ese cambio de plan sí tiene un valor: revisa el cálculo.');
        $pagoEstado = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
        if (!TukanPagoPlan::activo($pagoEstado)) throw new RuntimeException('El pago con tarjeta no está disponible por ahora. Usa «Enviar por WhatsApp».');
        $neg = db()->query('SELECT ruc, telefono FROM salones WHERE id = ' . $sid)->fetch() ?: [];
        $mj = TukanPagoPlan::mejora(['cliente_id' => (string) $sid, 'nombre' => (string) ($u['salon'] ?? ''), 'plan' => $planCompleto, 'plan_nombre' => $nomCompleto,
            'subtotal' => $pr['subtotal'], 'hasta' => $pr['hasta'], 'comprador' => ['nombre' => (string) $u['nombre'], 'correo' => (string) $u['email'], 'telefono' => (string) ($neg['telefono'] ?? '')],
            'ruc' => (string) ($neg['ruc'] ?? ''), 'volver' => \TuSalon\Agenda::urlBase() . url('completa')], (string) $u['email']);
        if ($mj['resultado'] === 'pagar') {
            TukanPagoPlan::mejoraPendiente(db(), (string) $sid, (int) ($mj['cobro']['id'] ?? 0), $planCompleto);   // si paga y no vuelve a Mi plan, la tarea de cada hora la aplica
            header('Location: ' . $mj['url']);   // la página segura de pago (Plux). El plan cambia cuando el pago queda aprobado.
            exit;
        }
        if ($mj['resultado'] === 'minimo') {   // menos de $1: el Panel no lo cobra; el plan cambia sin cobro
            (new \TuSalon\PanelTukan(db()))->cambiarPlan($sid, $planCompleto);
            db()->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)')->execute([$sid, 'Pasó al plan ' . $nomCompleto . ' desde Mi plan: la diferencia era menor a $1 y no se cobró']);
            aviso('La diferencia es menor a $1: no se cobra. Ya tienes el plan ' . $nomCompleto . '.');
            redirigir('completa');
        }
        throw new RuntimeException($mj['texto']);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('completa', ['completo' => 'prorrateo']);
}
if (es_post() && ($_POST['accion'] ?? '') === 'pasar_completo') {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede cambiar el plan.');
        if (en_app_ios()) throw new RuntimeException(TEXTO_NO_EN_APP);
        if (en_app_play()) throw new RuntimeException('En la app el plan se paga con Google Play.');
        if ($planCompleto === null || $planCompleto === $u['plan_codigo']) throw new RuntimeException('Ya tienes el plan más completo.');
        $nomCompleto = (string) $mods->planes()[$planCompleto]['nombre'];
        $pagoEstado = $pagoPuede ? TukanPagoPlan::estado((string) $sid, (string) $u['email']) : null;
        $viva = TukanPagoPlan::suscripcion($pagoEstado);
        if (($u['precio_acordado'] ?? null) !== null && $u['precio_acordado'] !== '') throw new RuntimeException('Tienes un precio acordado con Tukán: escríbenos para cambiar de plan.');
        if (!$viva && $prorrateoCompleto() !== null) redirigir('completa', ['completo' => 'prorrateo']);   // período pagado y sin suscripción: paga el prorrateo (tarjeta o WhatsApp)
        if (TukanPagoPlan::activo($pagoEstado) && !$viva) {   // demo o plan vencido: paga el Completo entero; sale marcado en «Paga tu plan con tarjeta»
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
        // Sin pago con tarjeta disponible (Panel apagado, caído o programa sin conectar): el plan NO cambia y no se crea ningún pedido.
        // Nadie pasa al plan mayor sin pagar (corrección de Dimitry, 10 nov 2026): se le explica y se le ofrece el WhatsApp de Tukán.
        redirigir('completa', ['completo' => 'sin_tarjeta']);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('completa');
}
if (es_post() && in_array($_POST['accion'] ?? '', ['pagar_tarjeta', 'descartar_pago_tarjeta'], true)) {
    verificar_csrf();
    try {
        if (!es_dueno_o_admin($u)) throw new RuntimeException('Solo el dueño puede pagar el plan.');
        if (en_app_ios()) throw new RuntimeException(TEXTO_NO_EN_APP);
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
        if ($plPago === $planCompleto && $prorrateoCompleto() !== null) {   // todavía tiene días pagados de su plan: para pasar YA se paga la diferencia de esos días
            aviso('Para pasar ahora al plan ' . $opPago['nombre'] . ' se paga primero la diferencia de los días que te faltan.');
            redirigir('completa', ['completo' => 'prorrateo']);
        }
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
        if (en_app_ios()) throw new RuntimeException('Este módulo no está incluido en tu plan.');   // iPhone (regla de Apple): no se piden módulos desde la app
        if (en_app_play()) throw new RuntimeException('Para agregar módulos, comunícate con Tukán.');
        if (($_POST['accion'] ?? '') !== 'pedir') throw new RuntimeException('Acción no válida.');
        $m = (string) ($_POST['modulo'] ?? '');
        if (!\TuSalon\Modulos::existe($m)) throw new RuntimeException('Ese módulo no existe.');
        if ($m === 'facturacion') throw new RuntimeException('TuFactura (facturación electrónica del SRI): Próximamente.');   // 12 nov 2026
        // Antes de pedirlo: si con este módulo pasaría a pagar igual o más que el Completo, primero se le sugiere el Completo
        if (!en_app_tienda() && empty($_POST['solo_modulo']) && es_dueno_o_admin($u) && ($u['estado'] ?? '') !== 'prueba'
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
if (($mjPagada = TukanPagoPlan::mejoraPagada(db(), (string) $sid, $pagoEstado)) !== null) {
    $planMj = (string) $mjPagada['plan'];
    $aplicar = isset($mods->planes()[$planMj]) && $planMj !== $u['plan_codigo'];
    if ($aplicar) {
        (new \TuSalon\PanelTukan(db()))->cambiarPlan($sid, $planMj);
        db()->prepare('INSERT INTO registro_admin (admin_id, salon_id, accion) VALUES (NULL, ?, ?)')
            ->execute([$sid, 'Pasó al plan ' . $mods->planes()[$planMj]['nombre'] . ' desde Mi plan: prorrateo pagado con tarjeta (cobro ' . (int) $mjPagada['id'] . ')']);
        aviso('¡Pago recibido! Ya tienes el plan ' . $mods->planes()[$planMj]['nombre'] . ' con todos los módulos. Tu fecha de vencimiento no cambia.');
    }
    TukanPagoPlan::marcarMejora(db(), (string) $sid, (int) $mjPagada['id'], $planMj);
    if ($aplicar) redirigir('completa');
}
$pagoHtml = TukanPagoPlan::activo($pagoEstado)
    ? TukanPagoPlan::html($pagoEstado, $pagoOpciones(), campo_csrf(), url('completa'), $detalle['demo'], (string) ($_GET['plux'] ?? ''),
        TukanPagoPlan::htmlSugerencia($sugerir()), (string) ($_GET['elegir'] ?? '')) : '';
// El aviso de arriba «te sugerimos el Completo» (nunca en la app de Google Play; a la demo solo si puede pagar aquí y ya ve los valores)
$sugHtml = '';
if (es_dueno_o_admin($u) && !en_app_tienda() && (!$detalle['demo'] || $pagoHtml !== '')) {
    if (($_GET['completo'] ?? '') === 'sin_tarjeta' && $planCompleto !== null && $planCompleto !== $u['plan_codigo']) {   // tocó «Pasarme al Completo» y no hay cómo pagar aquí
        $nomC = (string) $mods->planes()[$planCompleto]['nombre'];
        $sinTarjeta = '<div class="aviso tukan-sugerencia" role="status" data-completo="sin-tarjeta" style="background:#fff8e1;border:1px solid #f2c94c;color:#5c4400"><p style="margin:0 0 8px">Para pasarte al '
            . e($nomC) . ' necesitas pagar con tarjeta. En este momento el pago con tarjeta no está disponible: escríbenos y te ayudamos.</p><div class="acciones"><a class="boton boton-wa boton-chico" href="https://wa.me/'
            . e(whatsapp_ventas()) . '?text=' . rawurlencode('Hola, quiero pasarme al plan ' . $nomC . ' de TuSalon (' . ($u['email'] ?? '') . ').') . '" target="_blank" rel="noopener">Escribir a Tukán por WhatsApp</a></div></div>';
    }
    $mSug = !empty($_GET['sugerir']) && \TuSalon\Modulos::existe((string) ($_GET['modulo'] ?? '')) && !$detalle['demo'] ? (string) $_GET['modulo'] : null;
    $sMod = $mSug !== null ? $sugerir($mSug) : null;
    $sugHtml = $sMod !== null
        ? TukanPagoPlan::htmlSugerencia($sMod, campo_csrf(), url('completa'), $mods->nombre($mSug), '<form method="post" action="' . e(url('completa')) . '" style="display:inline">' . campo_csrf()
            . '<input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="' . e($mSug) . '"><input type="hidden" name="solo_modulo" value="1">'
            . '<button class="boton boton-claro boton-chico" type="submit">Pedir solo este módulo</button></form>')
        : TukanPagoPlan::htmlSugerencia($sugerir(), campo_csrf(), url('completa'));
    if (isset($sinTarjeta)) $sugHtml = $sinTarjeta;
    if (($_GET['completo'] ?? '') === 'prorrateo' && ($prVer = $prorrateoCompleto()) !== null) {   // el cálculo del cambio con «Pagar con tarjeta» y «Enviar por WhatsApp»
        $nomC = (string) $mods->planes()[$planCompleto]['nombre'];
        $sugHtml = TukanPagoPlan::htmlProrrateo($prVer, $nomC, campo_csrf(), url('completa'), TukanPagoPlan::activo($pagoEstado) && !TukanPagoPlan::suscripcion($pagoEstado),
            'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode(TukanPagoPlan::textoWhatsappMejora($prVer, (string) ($u['salon'] ?? ''), 'TuSalón', $nomC)));
    }
}
$resaltar = \TuSalon\Modulos::existe((string) ($_GET['modulo'] ?? '')) ? (string) $_GET['modulo'] : '';
vista('completa', compact('pagoHtml', 'sugHtml', 'cancelacion', 'detalle', 'catalogo', 'planes', 'plan', 'precios', 'pedidos', 'resaltar'), 'Mi plan · TuSalón');
