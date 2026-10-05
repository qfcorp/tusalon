<?php
use TuSalon\Liquidacion;

$sid = (int) $u['salon_id'];
$pdo = db();
$liq = new Liquidacion($pdo);

$profDelSalon = function (int $id) use ($pdo, $sid): ?array {
    $st = $pdo->prepare('SELECT * FROM profesionales WHERE id = ? AND salon_id = ? AND tipo <> \'dueno\'');
    $st->execute([$id, $sid]);
    return $st->fetch() ?: null;
};

if (es_post()) {
    verificar_csrf();
    $p = $profDelSalon((int) ($_POST['id'] ?? 0));
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if (!$p) throw new RuntimeException('Peluquero no encontrado.');
        if ($accion === 'arriendo') {
            $monto = $liq->cargarArriendo((int) $p['id'], date('Y-m-d'));
            aviso('Arriendo de ' . dinero($monto) . ' cargado a ' . $p['nombre'] . '.');
        } elseif ($accion === 'cerrar') {
            $st = $pdo->prepare('SELECT MIN(fecha) FROM movimientos_profesional WHERE profesional_id = ? AND liquidacion_id IS NULL');
            $st->execute([$p['id']]);
            $desde = $st->fetchColumn();
            if (!$desde) throw new RuntimeException($p['nombre'] . ' no tiene nada pendiente.');
            $r = $liq->liquidar((int) $p['id'], $desde, date('Y-m-d'));
            aviso($p['nombre'] . ': ' . str_replace('.', ',', $r['texto']) . '. Cuenta cerrada.');
        }
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
    }
    redirigir('cuentas');
}

$st = $pdo->prepare("SELECT p.id, p.nombre, p.tipo, r.monto_arriendo, r.frecuencia_arriendo
                       FROM profesionales p JOIN reglas_pago r ON r.profesional_id = p.id AND r.vigente_hasta IS NULL
                      WHERE p.salon_id = ? AND p.activo AND p.tipo <> 'dueno' ORDER BY p.nombre");
$st->execute([$sid]);
$equipo = $st->fetchAll();
$detalle = $pdo->prepare('SELECT fecha, tipo, monto, descripcion FROM movimientos_profesional
                           WHERE profesional_id = ? AND liquidacion_id IS NULL ORDER BY fecha DESC, id DESC LIMIT 30');
foreach ($equipo as &$p) {
    $p['saldo'] = $liq->saldo((int) $p['id']);
    $detalle->execute([$p['id']]);
    $p['movimientos'] = $detalle->fetchAll();
}
unset($p);

$st = $pdo->prepare('SELECT l.*, p.nombre FROM liquidaciones l JOIN profesionales p ON p.id = l.profesional_id
                      WHERE l.salon_id = ? ORDER BY l.creada_en DESC LIMIT 15');
$st->execute([$sid]);
$historial = $st->fetchAll();
vista('cuentas', compact('equipo', 'historial'), 'Cuentas · TuSalón');
