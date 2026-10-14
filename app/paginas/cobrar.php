<?php
use TuSalon\{Agenda, Anticipos, Cobro, Fidelidad, Inventario};

$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;
$completa = es_completa($u);
$citaId = (int) ($_REQUEST['cita'] ?? 0);
$cita = $citaId ? (new Agenda($pdo))->cita($sid, $citaId) : null;
if ($cita) $cita = ocultar_cliente_privado($cita, $u);
if ($citaId && (!$cita || !in_array($cita['estado'], ['reservada', 'confirmada'], true))) {
    aviso('Esa cita ya no se puede cobrar.', 'error');
    redirigir('caja');
}

// Peluqueros con su regla vigente (para sugerir quién cobra)
$st = $pdo->prepare(
    "SELECT p.id, p.nombre, p.tipo, r.quien_cobra
       FROM profesionales p
       JOIN reglas_pago r ON r.profesional_id = p.id AND r.vigente_hasta IS NULL
      WHERE p.salon_id = ? AND p.activo ORDER BY p.nombre"
);
$st->execute([$sid]);
$profesionales = $st->fetchAll();
foreach ($profesionales as &$p) {
    $p['cobra'] = ($p['tipo'] === 'alquiler' || ($p['tipo'] === 'porcentaje' && $p['quien_cobra'] === 'profesional'))
        ? 'profesional' : 'local';
}
unset($p);

$st = $pdo->prepare('SELECT id, nombre, precio FROM servicios WHERE salon_id = ? AND activo ORDER BY nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();

$seleccion = $cita ? array_column($cita['lista_servicios'], 'id') : [];
$preciosCita = $cita ? array_column($cita['lista_servicios'], 'precio', 'id') : [];
$profElegido = $cita ? (int) $cita['profesional_id'] : 0;

// Cliente: el de la cita o el que se elige (para paquetes y puntos)
$clienteId = $cita ? (int) $cita['cliente_id'] : (int) ($_REQUEST['cliente_id'] ?? 0);
$fid = new Fidelidad($pdo);
$cliente = null;
$paquetes = [];
if ($clienteId) {
    $st = $pdo->prepare('SELECT id, nombre, puntos, profesional_privado_id FROM clientes WHERE id = ? AND salon_id = ?');
    $st->execute([$clienteId, $sid]);
    $cliente = $st->fetch() ?: null;
    if ($cliente && $cliente['profesional_privado_id'] !== null) {
        // Cliente privado de quien alquila: solo se cobra desde su cita y sin mostrar su nombre
        if (!$cita) $cliente = null;
        else $cliente['nombre'] = $cita['cliente'];
    }
    if (!$cliente) $clienteId = 0;
    elseif ($completa) $paquetes = $fid->paquetesDeCliente($sid, $clienteId);
}
$clientes = (!$cita && $completa) ? clientes_para_elegir($sid) : [];
$productos = $completa ? (new Inventario($pdo))->paraVender($sid) : [];
$puntos = $completa ? $fid->configPuntos($sid) : ['activo' => false];
$anticipo = $cita ? (new Anticipos($pdo))->disponible($cita) : 0.0;

$d = ['metodo_pago' => 'efectivo', 'propina' => '0', 'tarjeta_codigo' => '', 'tarjeta_monto' => '', 'puntos' => '', 'productos' => [], 'paquetes' => []];
if (es_post()) {
    verificar_csrf();
    $profId = (int) ($_POST['profesional_id'] ?? 0);
    $marcados = array_map('intval', (array) ($_POST['servicio'] ?? []));
    $precios = (array) ($_POST['precio'] ?? []);
    $d = array_merge($d, array_intersect_key($_POST, $d));
    try {
        if (!in_array($profId, array_map('intval', array_column($profesionales, 'id')), true)) {
            throw new RuntimeException('Elige quién atendió.');
        }
        $validos = array_column($servicios, null, 'id');
        $serv = [];
        foreach ($marcados as $sId) {
            if (isset($validos[$sId])) $serv[$sId] = $precios[$sId] ?? $validos[$sId]['precio'];
        }
        $r = (new Cobro($pdo))->registrar([
            'salon_id' => $sid, 'profesional_id' => $profId, 'cita' => $cita, 'cliente_id' => $clienteId ?: null,
            'servicios' => $serv, 'productos' => (array) ($_POST['productos'] ?? []),
            'metodo_pago' => (string) ($_POST['metodo_pago'] ?? 'efectivo'),
            'cobrado_por' => (string) ($_POST['cobrado_por'] ?? 'local'),
            'propina' => numero($_POST['propina'] ?? '') ?? 0,
            'paquetes' => array_filter((array) ($_POST['paquetes'] ?? [])),
            'tarjeta_codigo' => (string) ($_POST['tarjeta_codigo'] ?? ''), 'tarjeta_monto' => (string) ($_POST['tarjeta_monto'] ?? ''),
            'puntos' => (int) ($_POST['puntos'] ?? 0),
            'completa' => $completa,
        ]);
        $msg = 'Cobro registrado.';
        if ($r['ya_pagado'] > 0) $msg .= ' Ya estaba pagado ' . dinero($r['ya_pagado']) . '; se cobró hoy ' . dinero($r['a_cobrar'] + $r['propina']) . '.';
        if ($r['detalle']['tarjeta_saldo'] !== null) $msg .= ' A la tarjeta de regalo le quedan ' . dinero($r['detalle']['tarjeta_saldo']) . '.';
        if ($r['puntos_ganados'] > 0) $msg .= " {$cliente['nombre']} ganó {$r['puntos_ganados']} puntos.";
        aviso($msg);
        redirigir('caja');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        $seleccion = $marcados;
        $profElegido = $profId;
        $preciosCita = array_map('floatval', $precios);
    }
}
vista('cobrar', compact('cita', 'profesionales', 'servicios', 'seleccion', 'profElegido', 'error', 'preciosCita', 'completa',
                        'cliente', 'clientes', 'paquetes', 'productos', 'puntos', 'anticipo', 'd'), 'Cobrar · TuSalón');
