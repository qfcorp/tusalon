<?php
use TuSalon\{Agenda, Liquidacion};

$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;
$citaId = (int) ($_REQUEST['cita'] ?? 0);
$cita = $citaId ? (new Agenda($pdo))->cita($sid, $citaId) : null;
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
// Precio acordado al agendar (el dueño pudo cambiarlo); si no hay cita, el precio normal
$preciosCita = $cita ? array_column($cita['lista_servicios'], 'precio', 'id') : [];
$profElegido = $cita ? (int) $cita['profesional_id'] : 0;

if (es_post()) {
    verificar_csrf();
    $profId = (int) ($_POST['profesional_id'] ?? 0);
    $marcados = array_map('intval', (array) ($_POST['servicio'] ?? []));
    $precios = (array) ($_POST['precio'] ?? []);
    $metodo = (string) ($_POST['metodo_pago'] ?? 'efectivo');
    $cobradoPor = ($_POST['cobrado_por'] ?? 'local') === 'profesional' ? 'profesional' : 'local';
    $propina = max(0, (float) str_replace(',', '.', (string) ($_POST['propina'] ?? '0')));

    $ids = array_column($profesionales, 'id');
    $validos = array_column($servicios, null, 'id');
    try {
        if (!in_array($profId, array_map('intval', $ids), true)) {
            throw new RuntimeException('Elige quién atendió.');
        }
        $items = [];
        foreach ($marcados as $sId) {
            if (!isset($validos[$sId])) continue;
            $precio = round((float) str_replace(',', '.', (string) ($precios[$sId] ?? $validos[$sId]['precio'])), 2);
            if ($precio < 0) throw new RuntimeException('Un precio no puede ser negativo.');
            $items[] = ['tipo' => 'servicio', 'servicio_id' => $sId, 'profesional_id' => $profId, 'precio_unitario' => $precio];
        }
        if (!$items) {
            throw new RuntimeException('Marca al menos un servicio.');
        }
        if (!in_array($metodo, ['efectivo', 'tarjeta', 'transferencia', 'payphone', 'plux', 'deuna'], true)) {
            $metodo = 'efectivo';
        }
        $pdo->beginTransaction();
        $ventaId = (new Liquidacion($pdo))->registrarVenta([
            'salon_id'    => $sid,
            'fecha'       => date('Y-m-d H:i:s'),
            'cobrado_por' => $cobradoPor,
            'cobrado_por_profesional_id' => $cobradoPor === 'profesional' ? $profId : null,
            'metodo_pago' => $metodo,
            'propina'     => $propina,
            'cliente_id'  => $cita['cliente_id'] ?? null,
        ], $items);
        if ($cita) {
            $pdo->prepare('UPDATE ventas SET cita_id = ? WHERE id = ?')->execute([$cita['id'], $ventaId]);
            (new Agenda($pdo))->cambiarEstado($sid, (int) $cita['id'], 'atendida');
        }
        $pdo->commit();
        aviso('Cobro registrado.');
        redirigir('caja');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        $seleccion = $marcados;
        $profElegido = $profId;
    }
}
vista('cobrar', compact('cita', 'profesionales', 'servicios', 'seleccion', 'profElegido', 'error', 'preciosCita'), 'Cobrar · TuSalón');
