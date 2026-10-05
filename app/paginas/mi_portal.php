<?php
use TuSalon\{Agenda, Liquidacion};

$sid = (int) $u['salon_id'];
$pid = (int) $u['profesional_id'];
$pdo = db();

$periodos = ['hoy' => 'Hoy', 'semana' => 'Esta semana', 'mes' => 'Este mes'];
$periodo = array_key_exists($_GET['p'] ?? '', $periodos) ? $_GET['p'] : 'semana';
$hasta = date('Y-m-d');
$desde = match ($periodo) {
    'hoy'    => $hasta,
    'semana' => date('Y-m-d', strtotime('monday this week')),
    'mes'    => date('Y-m-01'),
};

$st = $pdo->prepare('SELECT p.nombre, p.tipo, r.* FROM profesionales p
                       JOIN reglas_pago r ON r.profesional_id = p.id AND r.vigente_hasta IS NULL
                      WHERE p.id = ? AND p.salon_id = ?');
$st->execute([$pid, $sid]);
$yo = $st->fetch();

// Servicios que hizo en el periodo, con lo que le toca de cada uno
$st = $pdo->prepare(
    "SELECT v.fecha, COALESCE(s.nombre, pr.nombre) AS servicio, vi.tipo, cl.nombre AS cliente,
            vi.subtotal, vi.ganancia_profesional, v.cobrado_por
       FROM venta_items vi JOIN ventas v ON v.id = vi.venta_id
  LEFT JOIN servicios s ON s.id = vi.servicio_id
  LEFT JOIN productos pr ON pr.id = vi.producto_id
  LEFT JOIN clientes cl ON cl.id = v.cliente_id
      WHERE vi.profesional_id = ? AND v.salon_id = ? AND NOT v.anulada AND v.fecha::date BETWEEN ? AND ?
      ORDER BY v.fecha DESC"
);
$st->execute([$pid, $sid, $desde, $hasta]);
$hechos = $st->fetchAll();

// Propinas de cobros donde él atendió el primer servicio
$st = $pdo->prepare(
    "SELECT COALESCE(SUM(v.propina),0) FROM ventas v
      WHERE v.salon_id = ? AND NOT v.anulada AND v.fecha::date BETWEEN ? AND ?
        AND (SELECT profesional_id FROM venta_items WHERE venta_id = v.id ORDER BY id LIMIT 1) = ?"
);
$st->execute([$sid, $desde, $hasta, $pid]);
$propinas = (float) $st->fetchColumn();

$ganado = array_sum(array_map(fn($h) => (float) $h['ganancia_profesional'], $hechos)) + $propinas;
$saldo = (new Liquidacion($pdo))->saldo($pid);

// Próximas citas (desde hoy, 7 días)
$st = $pdo->prepare(
    "SELECT c.id, c.inicio, c.fin, c.estado, cl.nombre AS cliente, cl.telefono,
            (SELECT string_agg(s.nombre, ' + ') FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios
       FROM citas c LEFT JOIN clientes cl ON cl.id = c.cliente_id
      WHERE c.profesional_id = ? AND c.salon_id = ? AND c.fin >= now() AND c.inicio < (CURRENT_DATE + 7)
        AND (c.estado IN ('reservada','confirmada') OR (c.estado = 'pendiente' AND (c.expira_en IS NULL OR c.expira_en >= now())))
      ORDER BY c.inicio LIMIT 30"
);
$st->execute([$pid, $sid]);
$proximas = $st->fetchAll();

// Sus servicios: precio y lo que le toca por cada uno
$st = $pdo->prepare('SELECT nombre, precio, pago_profesional, duracion_minutos FROM servicios
                      WHERE salon_id = ? AND activo AND (profesional_id IS NULL OR profesional_id = ?) ORDER BY nombre');
$st->execute([$sid, $pid]);
$catalogo = $st->fetchAll();

vista('mi_portal', compact('yo', 'periodos', 'periodo', 'hechos', 'propinas', 'ganado', 'saldo', 'proximas', 'catalogo'),
      'Mi portal · TuSalón');
