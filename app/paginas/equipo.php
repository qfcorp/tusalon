<?php
use TuSalon\{Liquidacion, Planes, Profesionales};

$sid = (int) $u['salon_id'];
$pdo = db();
$planes = new Planes($pdo);
$error = null;
$num = fn($k) => (float) str_replace(',', '.', (string) ($_POST[$k] ?? '0'));

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? 'crear');
    if ($accion === 'desactivar') {
        $pdo->prepare("UPDATE profesionales SET activo = false WHERE id = ? AND salon_id = ? AND tipo <> 'dueno'")
            ->execute([(int) $_POST['id'], $sid]);
        aviso('Peluquero desactivado. Su historial se conserva.');
        redirigir('equipo');
    }
    $tipo = (string) ($_POST['tipo'] ?? '');
    $regla = match ($tipo) {
        'empleado'   => ['sueldo_mensual' => $num('sueldo_mensual'), 'comision_servicio_pct' => $num('comision_servicio_pct'),
                         'comision_producto_pct' => $num('comision_producto_pct'),
                         'afiliado_iess' => !empty($_POST['afiliado_iess']), 'garantizar_basico' => !empty($_POST['afiliado_iess'])],
        'porcentaje' => ['pct_profesional' => $num('pct_profesional'),
                         'quien_cobra' => ($_POST['quien_cobra'] ?? 'local') === 'profesional' ? 'profesional' : 'local'],
        'alquiler'   => ['monto_arriendo' => $num('monto_arriendo'), 'frecuencia_arriendo' => (string) ($_POST['frecuencia_arriendo'] ?? '')],
        default      => [],
    };
    $nombre = trim((string) ($_POST['nombre'] ?? ''));
    try {
        if (mb_strlen($nombre) < 2) {
            throw new RuntimeException('Escribe el nombre del peluquero.');
        }
        if (!in_array($tipo, ['empleado', 'porcentaje', 'alquiler'], true)) {
            throw new RuntimeException('Elige cómo trabaja.');
        }
        foreach (['comision_servicio_pct', 'comision_producto_pct'] as $k) {
            if (($regla[$k] ?? 0) < 0 || ($regla[$k] ?? 0) > 100) {
                throw new RuntimeException('La comisión debe estar entre 0 y 100 %.');
            }
        }
        $id = (new Profesionales($pdo, $planes))->agregar($sid, $nombre, $tipo, $regla);
        $tel = trim((string) ($_POST['telefono'] ?? ''));
        if ($tel !== '') {
            $pdo->prepare('UPDATE profesionales SET telefono = ? WHERE id = ?')->execute([$tel, $id]);
        }
        aviso("$nombre ya está en tu equipo.");
        redirigir('equipo');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$st = $pdo->prepare(
    'SELECT p.*, r.sueldo_mensual, r.comision_servicio_pct, r.comision_producto_pct, r.afiliado_iess,
            r.pct_profesional, r.quien_cobra, r.monto_arriendo, r.frecuencia_arriendo
       FROM profesionales p
       JOIN reglas_pago r ON r.profesional_id = p.id AND r.vigente_hasta IS NULL
      WHERE p.salon_id = ? AND p.activo
      ORDER BY p.tipo = \'dueno\' DESC, p.nombre'
);
$st->execute([$sid]);
$equipo = $st->fetchAll();
$liq = new Liquidacion($pdo);
foreach ($equipo as &$p) {
    $p['saldo'] = $p['tipo'] === 'dueno' ? 0.0 : $liq->saldo((int) $p['id']);
}
unset($p);
$puedeAgregar = $planes->puedeAgregarProfesional($sid);
vista('equipo', compact('equipo', 'error', 'puedeAgregar'), 'Equipo · TuSalón');
