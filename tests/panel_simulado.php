<?php
declare(strict_types=1);
/**
 * Panel Tukán Central SIMULADO para las pruebas de «Pagar mi plan con tarjeta» (php -S 127.0.0.1:PUERTO tests/panel_simulado.php).
 * Imita plux_api.php del Panel (tukan-central/public/plux_api.php y PagosPlux::atenderPrograma): revisa el token v2 «plux»
 * (firma con TUKAN_CENTRAL_SECRET, programa TUKAN_CENTRAL_ID, hash del cuerpo, vida y que no se repita) y contesta
 * «estado», «crear», «cancelar», «cambiar» y «cancelar_plan» con las mismas respuestas y códigos. No cobra nada: todo es de mentira.
 * También imita demo_aviso.php (aviso al instante de un pedido de demo, token v2 «demo»): contesta 202 y lo anota en avisos_demo.jsonl.
 * Guarda en PANEL_SIM_DIR: config.json ({"activo":bool,"pruebas":bool}), cobros.json y pedidos.jsonl (cada pedido recibido).
 * Para las pruebas: POST /_control {"activo":…, "pruebas":…} · {"aprobar":id,"pagado_hasta":"AAAA-MM-DD"} · {"estado":id,"a":"rechazado"} · {"reiniciar":true}
 */
$dir = (string) getenv('PANEL_SIM_DIR');
if ($dir === '') { http_response_code(500); exit('Falta PANEL_SIM_DIR'); }
@mkdir($dir, 0700, true);
$leer = fn(string $f, array $def) => is_file("$dir/$f") ? (json_decode((string) file_get_contents("$dir/$f"), true) ?: $def) : $def;
$guardar = fn(string $f, array $d) => file_put_contents("$dir/$f", json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
$responder = function (int $codigo, array $datos): never {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
const TEXTOS = ['pendiente' => 'Esperando el pago', 'pagado' => 'Pagado', 'rechazado' => 'Tarjeta rechazada (puede intentar otra vez)', 'activa' => 'Suscripción activa',
                'en_gracia' => 'Venció: en días de gracia', 'vencida' => 'Suscripción vencida', 'cancelada' => 'Cancelada', 'anulado' => 'Anulado'];
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$cuerpo = (string) file_get_contents('php://input');
$config = $leer('config.json', []) + ['activo' => true, 'pruebas' => true];
$cobros = $leer('cobros.json', []);
$publico = function (array $c): array {
    $vivo = in_array($c['estado'], ['pendiente', 'rechazado'], true);
    return ['id' => $c['id'], 'tipo' => $c['tipo'], 'estado' => $c['estado'], 'estado_texto' => TEXTOS[$c['estado']] ?? $c['estado'], 'descripcion' => $c['descripcion'],
            'plan' => $c['plan'], 'periodo' => $c['periodo'], 'meses' => $c['meses'], 'subtotal' => $c['subtotal'], 'total' => $c['total'],
            'url_pago' => $vivo ? $c['url_pago'] : null, 'cobra_sola' => $c['tipo'] === 'suscripcion' && $c['estado'] === 'activa', 'pagado_hasta' => $c['pagado_hasta'] ?? null,
            'cuotas' => $c['cuotas'] ?? 0, 'pruebas' => true, 'creado' => date('c', $c['creado']), 'cancelar_hasta' => null, 'cancelacion_programada' => null];
};

if ($ruta === '/_control') {
    $b = json_decode($cuerpo, true) ?: [];
    if (!empty($b['reiniciar'])) { $cobros = []; $config = ['activo' => true, 'pruebas' => true]; @unlink("$dir/pedidos.jsonl"); @unlink("$dir/avisos_demo.jsonl"); }
    foreach (['activo', 'pruebas'] as $k) if (array_key_exists($k, $b)) $config[$k] = (bool) $b[$k];
    foreach ($cobros as &$c) {
        if (isset($b['aprobar']) && $c['id'] === (int) $b['aprobar']) {
            $c['estado'] = $c['tipo'] === 'suscripcion' ? 'activa' : 'pagado';
            $c['pagado_hasta'] = $b['pagado_hasta'] ?? date('Y-m-d', strtotime('+1 month'));
            $c['cuotas'] = 1;
        }
        if (isset($b['estado']) && $c['id'] === (int) $b['estado']) $c['estado'] = (string) $b['a'];
    }
    unset($c);
    $guardar('config.json', $config);
    $guardar('cobros.json', $cobros);
    $responder(200, ['ok' => true, 'config' => $config, 'cobros' => $cobros]);
}
if ($ruta === '/pagar') { header('Content-Type: text/html; charset=utf-8'); exit('<h1>Página de pago SIMULADA</h1><p>Aquí no se cobra nada.</p>'); }
if (!in_array($ruta, ['/plux_api.php', '/demo_aviso.php'], true)) $responder(404, ['ok' => false, 'error' => 'No existe.']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') $responder(405, ['ok' => false, 'error' => 'Solo POST.']);

// Token v2 de tipo «plux»
$unb64 = fn(string $s) => (string) base64_decode(strtr($s, '-_', '+/'));
$b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$partes = explode('.', (string) ($_SERVER['HTTP_X_TUKAN_TOKEN'] ?? ''));
$p = count($partes) === 2 ? json_decode($unb64($partes[0]), true) : null;
if (!is_array($p) || ($p['inst'] ?? '') !== (string) getenv('TUKAN_CENTRAL_ID')) $responder(404, ['ok' => false, 'codigo' => 'no_encontrado', 'error' => 'Programa no conectado al panel.']);
$malo = fn(string $c) => $responder(401, ['ok' => false, 'codigo' => $c, 'error' => "Token no válido ($c)."]);
if (!hash_equals($b64(hash_hmac('sha256', $partes[0], (string) getenv('TUKAN_CENTRAL_SECRET'), true)), $partes[1])) $malo('firma');
if (($p['v'] ?? 0) !== 2 || ($p['tipo'] ?? '') !== ($ruta === '/demo_aviso.php' ? 'demo' : 'plux')) $malo('tipo');
if ((int) ($p['exp'] ?? 0) < time() || (int) ($p['iat'] ?? 0) > time() + 60) $malo('vencido');
if (!hash_equals($b64(hash('sha256', $cuerpo, true)), (string) ($p['bh'] ?? ''))) $malo('cuerpo');
$nonces = $leer('nonces.json', []);
if (isset($nonces[(string) ($p['nonce'] ?? '')])) $malo('nonce');
$nonces[(string) $p['nonce']] = 1;
$guardar('nonces.json', $nonces);

$b = json_decode($cuerpo, true);
if (!is_array($b)) $responder(400, ['ok' => false, 'codigo' => 'invalido', 'error' => 'El cuerpo debe ser JSON.']);
if ($ruta === '/demo_aviso.php') {   // aviso al instante de un pedido de demo: el Panel contesta 202 y revisa ese programa en ese momento
    if (($b['accion'] ?? '') !== 'demo_pedida' || !is_string($b['id'] ?? null) || $b['id'] === '') $responder(422, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Falta el pedido.']);
    file_put_contents("$dir/avisos_demo.jsonl", json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    $responder(202, ['ok' => true]);
}
file_put_contents("$dir/pedidos.jsonl", json_encode($b + ['_email' => $p['email'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
$accion = (string) ($b['accion'] ?? '');
$cid = (string) ($b['cliente_id'] ?? '');
if (!preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $cid)) $responder(422, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Falta el cliente_id.']);
$mios = array_values(array_filter($cobros, fn($c) => $c['cliente_id'] === $cid));
$viva = current(array_filter($mios, fn($c) => $c['tipo'] === 'suscripcion' && in_array($c['estado'], ['pendiente', 'rechazado', 'activa', 'en_gracia'], true))) ?: null;

if ($accion === 'cancelar_plan') {
    foreach ($cobros as &$c) if ($c['cliente_id'] === $cid && empty($b['deshacer']) && $c['tipo'] === 'suscripcion' && in_array($c['estado'], ['pendiente', 'rechazado', 'activa', 'en_gracia'], true)) $c['estado'] = 'cancelada';
    unset($c);
    $guardar('cobros.json', $cobros);
    $responder(200, ['ok' => true, 'canceladas' => $viva && empty($b['deshacer']) ? 1 : 0, 'programadas' => 0]);
}
if ($accion === 'estado') {
    $responder(200, ['ok' => true, 'activo' => $config['activo'], 'iva' => 15, 'pruebas' => $config['pruebas'], 'tope_consumidor' => 50,
                     'cobros' => $config['activo'] ? array_map($publico, array_reverse($mios)) : []]);
}
if (!$config['activo']) $responder(409, ['ok' => false, 'codigo' => 'apagado', 'error' => 'El pago con tarjeta no está disponible por ahora. Pague por transferencia y envíe el comprobante.']);
if ($accion === 'cancelar') {
    foreach ($cobros as &$c) {
        if ($c['id'] === (int) ($b['id'] ?? 0) && $c['cliente_id'] === $cid) {
            $c['estado'] = 'cancelada';
            $guardar('cobros.json', $cobros);
            $responder(200, ['ok' => true, 'programada' => false, 'cobro' => $publico($c)]);
        }
    }
    $responder(404, ['ok' => false, 'codigo' => 'no_encontrado', 'error' => 'Esa suscripción no existe.']);
}
if ($accion === 'cambiar') {   // cambió de módulos en el programa: valor nuevo (sin IVA) de la suscripción viva (como PagosPlux::subirValor)
    $k = null;
    foreach ($cobros as $i => $c) {
        if ($c['cliente_id'] === $cid && $c['tipo'] === 'suscripcion' && ((int) ($b['id'] ?? 0) > 0 ? $c['id'] === (int) $b['id'] : in_array($c['estado'], ['pendiente', 'rechazado', 'activa', 'en_gracia'], true))) $k = $i;
    }
    if ($k === null) $responder(404, ['ok' => false, 'codigo' => 'no_encontrado', 'error' => 'Esa suscripción no existe.']);
    $f = $cobros[$k];
    $nuevo = round((float) ($b['subtotal'] ?? 0), 2);
    $antes = round((float) $f['subtotal'], 2);
    if (in_array($f['estado'], ['pendiente', 'rechazado'], true)) $responder(409, ['ok' => false, 'codigo' => 'sin_pagar', 'error' => 'Todavía no pagó la primera cuota: cancele ese pago y vuelva a pagar con el valor nuevo.', 'cobro' => $publico($f)]);
    if (abs($nuevo - $antes) < 0.01) $responder(200, ['ok' => true, 'cambio' => false, 'cobro' => $publico($f)]);
    $hasta = substr((string) ($f['pagado_hasta'] ?? ''), 0, 10);
    $dinero = fn(float $v) => '$' . number_format($v, 2, ',', '.');
    if ($nuevo < $antes && !empty($b['pasa_a_completo'])) {   // pasarse al Completo SÍ se acepta aunque baje: rige desde el próximo cobro
        $cobros[$k]['subtotal'] = $nuevo;
        $cobros[$k]['total'] = round($nuevo * 1.15, 2);
        $guardar('cobros.json', $cobros);
        $responder(200, ['ok' => true, 'cambio' => true, 'baja_al_renovar' => true, 'cambiado_en_plux' => true, 'proporcional' => null, 'cobro' => $publico($cobros[$k]),
                         'mensaje' => 'Pasó al plan Completo. Desde el próximo cobro' . ($hasta !== '' ? ' (' . date('d/m/Y', strtotime($hasta)) . ')' : '') . ' paga ' . $dinero($cobros[$k]['total']) . ' con IVA. Lo ya pagado no se devuelve.']);
    }
    if ($nuevo < $antes) {
        $responder(409, ['ok' => false, 'codigo' => 'no_baja', 'cobro' => $publico($f), 'error' => 'Los módulos ya contratados no se pueden quitar mientras dure el período pagado'
            . ($hasta !== '' ? ' (hasta el ' . date('d/m/Y', strtotime($hasta)) . ')' : '') . '. La suscripción con tarjeta sigue en ' . $dinero((float) $f['total']) . '.']);
    }
    $cobros[$k]['subtotal'] = $nuevo;
    $cobros[$k]['total'] = round($nuevo * 1.15, 2);
    $prop = null;
    $hoy = date('Y-m-d');
    if ($hasta !== '' && $hoy < $hasta) {   // proporcional de lo que falta del período pagado, contado desde su inicio
        $fin = strtotime($hasta);
        $ini = strtotime($hasta . ' -' . max(1, (int) $f['meses']) . ' months');
        $frac = max(0.0, min(1.0, ($fin - strtotime($hoy)) / max(1, $fin - $ini)));
        $sub = round(($nuevo - $antes) * $frac, 2);
        if ($sub >= 1.0) {
            $id = count($cobros) + 1;
            $prop = ['id' => $id, 'cliente_id' => $cid, 'tipo' => 'cobro', 'estado' => 'pendiente', 'descripcion' => 'Proporcional módulos del ' . date('d/m') . ' al ' . date('d/m/Y', $fin) . ' · ' . ($b['nombre'] ?? $cid),
                     'plan' => $f['plan'], 'periodo' => null, 'meses' => 1, 'subtotal' => $sub, 'total' => round($sub * 1.15, 2),
                     'url_pago' => 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/pagar?c=' . $id, 'creado' => time(), 'volver' => $f['volver'] ?? null];
            $cobros[] = $prop;
        }
    }
    $guardar('cobros.json', $cobros);
    $responder(200, ['ok' => true, 'cambio' => true, 'cambiado_en_plux' => true, 'mensaje' => 'Suscripción con tarjeta: ' . $dinero((float) $f['total']) . ' → ' . $dinero($cobros[$k]['total']) . ' desde el próximo cobro.',
                     'proporcional' => $prop ? $publico($prop) : null, 'cobro' => $publico($cobros[$k])]);
}
if ($accion !== 'crear') $responder(400, ['ok' => false, 'codigo' => 'invalido', 'error' => 'Acción no válida (crear, estado, cancelar o cambiar).']);
$tipo = (string) ($b['tipo'] ?? 'cobro');
if ($tipo === 'suscripcion' && $viva) $responder(409, ['ok' => false, 'codigo' => 'ya_tiene', 'error' => 'Ya tiene una suscripción con tarjeta (' . TEXTOS[$viva['estado']] . ').', 'cobro' => $publico($viva)]);
$subtotal = round((float) ($b['subtotal'] ?? 0), 2);
$error = match (true) {
    !in_array($tipo, ['cobro', 'suscripcion'], true) => 'Elige si es un pago de una sola vez o una suscripción.',
    $subtotal < 0.5 || $subtotal > 50000 => 'El valor sin IVA debe estar entre $0,50 y $50.000.',
    (int) ($b['meses'] ?? 1) < 1 || (int) ($b['meses'] ?? 1) > 36 => 'Elige de 1 a 36 meses (o pases).',
    $tipo === 'suscripcion' && !in_array($b['periodo'] ?? null, ['mensual', 'trimestral', 'semestral', 'anual'], true) => 'Elige cada cuánto se cobra (mensual, cada 3 meses, cada 6 meses o anual).',
    trim((string) ($b['descripcion'] ?? '')) === '' => 'Falta la descripción (la ve el cliente al pagar).',
    default => null,
};
if ($error !== null) $responder(422, ['ok' => false, 'codigo' => 'invalido', 'error' => $error]);
$id = count($cobros) + 1;
$c = ['id' => $id, 'cliente_id' => $cid, 'tipo' => $tipo, 'estado' => 'pendiente', 'descripcion' => (string) $b['descripcion'], 'plan' => $b['plan'] ?? null,
      'periodo' => $b['periodo'] ?? null, 'meses' => (int) ($b['meses'] ?? 1), 'subtotal' => $subtotal, 'total' => round($subtotal * 1.15, 2),
      'url_pago' => 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1') . '/pagar?c=' . $id, 'creado' => time(), 'volver' => $b['volver'] ?? null];
$cobros[] = $c;
$guardar('cobros.json', $cobros);
$responder(201, ['ok' => true, 'cobro' => $publico($c)]);
