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
    if ($accion === 'acceso' || $accion === 'quitar_acceso') {
        $pid = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT nombre FROM profesionales WHERE id = ? AND salon_id = ? AND tipo <> 'dueno' AND activo");
        $st->execute([$pid, $sid]);
        $nom = $st->fetchColumn();
        try {
            if (!$nom) throw new RuntimeException('Peluquero no encontrado.');
            if ($accion === 'quitar_acceso') {
                $pdo->prepare("UPDATE usuarios SET activo = false WHERE profesional_id = ? AND salon_id = ? AND rol = 'profesional'")
                    ->execute([$pid, $sid]);
                aviso("$nom ya no puede entrar a su portal.");
                redirigir('equipo');
            }
            if (!es_completa($u)) throw new RuntimeException('El portal del peluquero es del plan Completa.');
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $clave = (string) ($_POST['clave'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo no es válido.');
            if (strlen($clave) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
            $st = $pdo->prepare('SELECT id, salon_id, profesional_id FROM usuarios WHERE email = ?');
            $st->execute([$email]);
            $existe = $st->fetch();
            if ($existe && ((int) $existe['salon_id'] !== $sid || (int) $existe['profesional_id'] !== $pid)) {
                throw new RuntimeException('Ese correo ya se usa en otra cuenta.');
            }
            if ($existe) {
                $pdo->prepare('UPDATE usuarios SET password_hash = ?, activo = true WHERE id = ?')
                    ->execute([password_hash($clave, PASSWORD_DEFAULT), $existe['id']]);
            } else {
                $pdo->prepare("UPDATE usuarios SET activo = false WHERE profesional_id = ? AND salon_id = ? AND rol = 'profesional'")
                    ->execute([$pid, $sid]);
                $pdo->prepare("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?,'profesional')")
                    ->execute([$sid, $pid, $nom, $email, password_hash($clave, PASSWORD_DEFAULT)]);
            }
            aviso("Listo. $nom ya puede entrar con $email y ver sus citas y lo que gana.");
            redirigir('equipo');
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    } elseif ($accion === 'desactivar') {
        $pdo->prepare("UPDATE profesionales SET activo = false WHERE id = ? AND salon_id = ? AND tipo <> 'dueno'")
            ->execute([(int) $_POST['id'], $sid]);
        aviso('Peluquero desactivado. Su historial se conserva.');
        redirigir('equipo');
    }
    if (!in_array($accion, ['crear'], true)) goto fin_post;
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
    fin_post:
}

$st = $pdo->prepare(
    'SELECT p.*, (SELECT email FROM usuarios WHERE profesional_id = p.id AND rol = \'profesional\' AND activo LIMIT 1) AS acceso_email,
            r.sueldo_mensual, r.comision_servicio_pct, r.comision_producto_pct, r.afiliado_iess,
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
