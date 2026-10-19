<?php
use TuSalon\{Agenda, WhatsApp};

/**
 * Clientes privados de quien alquila el puesto (plan Completa). Son solo suyos: el dueño y recepción no ven su nombre
 * ni su celular (en la agenda aparece "Cliente de <peluquero>"), no salen en las listas del salón ni en sus avisos.
 * Desde aquí el arrendatario los crea, los edita, les escribe por WhatsApp y les agenda citas en su silla.
 */
if (!es_peluquero($u) || ($u['tipo_profesional'] ?? '') !== 'alquiler') redirigir(es_peluquero($u) ? 'mi_portal' : 'clientes');
$sid = (int) $u['salon_id'];
$pid = (int) $u['profesional_id'];
$pdo = db();
$agenda = new Agenda($pdo);
$error = null;
$verId = (int) ($_GET['id'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));

$mio = function (int $id) use ($pdo, $sid, $pid): ?array {
    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? AND salon_id = ? AND profesional_privado_id = ?');
    $st->execute([$id, $sid, $pid]);
    return $st->fetch() ?: null;
};
$datos = function () {
    $d = [];
    foreach (['nombre', 'telefono', 'alergias', 'notas'] as $k) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $d['cumple_mes'] = (int) ($_POST['cumple_mes'] ?? 0) ?: null;
    $d['cumple_dia'] = (int) ($_POST['cumple_dia'] ?? 0) ?: null;
    if (mb_strlen($d['nombre']) < 2) throw new RuntimeException('Escribe el nombre del cliente.');
    if ($d['telefono'] !== '' && WhatsApp::normalizarTelefono($d['telefono']) === null) throw new RuntimeException('El celular no es válido (ej. 0991234567).');
    if (($d['cumple_mes'] === null) !== ($d['cumple_dia'] === null)) throw new RuntimeException('Para el cumpleaños elige el día y el mes.');
    if ($d['cumple_mes'] !== null && !checkdate($d['cumple_mes'], $d['cumple_dia'], 2024)) throw new RuntimeException('Esa fecha de cumpleaños no existe.');
    return $d;
};

$cita = ['fecha' => date('Y-m-d'), 'hora' => '', 'servicios' => []];
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear') {
            $d = $datos();
            $st = $pdo->prepare('INSERT INTO clientes (salon_id, profesional_privado_id, creado_por_usuario, nombre, telefono, alergias, notas, cumple_mes, cumple_dia)
                                 VALUES (?,?,?,?,?,?,?,?,?) RETURNING id');
            $st->execute([$sid, $pid, (int) $u['id'], mb_substr($d['nombre'], 0, 120), $d['telefono'] ?: null, $d['alergias'] ?: null,
                          $d['notas'] ?: null, $d['cumple_mes'], $d['cumple_dia']]);
            aviso("{$d['nombre']} quedó guardado en tus clientes.");
            redirigir('mis_clientes', ['id' => (int) $st->fetchColumn()]);
        }
        $c = $mio((int) ($_POST['id'] ?? 0));
        if (!$c) throw new RuntimeException('No se encontró ese cliente.');
        $verId = (int) $c['id'];
        if ($accion === 'editar') {
            $d = $datos();
            $pdo->prepare('UPDATE clientes SET nombre = ?, telefono = ?, alergias = ?, notas = ?, cumple_mes = ?, cumple_dia = ? WHERE id = ?')
                ->execute([mb_substr($d['nombre'], 0, 120), $d['telefono'] ?: null, $d['alergias'] ?: null, $d['notas'] ?: null,
                           $d['cumple_mes'], $d['cumple_dia'], $c['id']]);
            aviso('Cliente guardado.');
        } elseif ($accion === 'cita') {
            $cita = ['fecha' => (string) ($_POST['fecha'] ?? ''), 'hora' => (string) ($_POST['hora'] ?? ''),
                     'servicios' => array_map('intval', (array) ($_POST['servicios'] ?? []))];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cita['fecha']) || !preg_match('/^\d{2}:\d{2}$/', $cita['hora'])) {
                throw new RuntimeException('Elige el día y la hora.');
            }
            if ($cita['fecha'] . ' ' . $cita['hora'] < date('Y-m-d H:i')) throw new RuntimeException('Esa hora ya pasó.');
            $mios = array_map('intval', array_column($agenda->serviciosDe($sid, $pid), 'id'));
            $propios = $pdo->prepare('SELECT id FROM servicios WHERE salon_id = ? AND profesional_id = ? AND activo');
            $propios->execute([$sid, $pid]);
            $mios = array_merge($mios, array_map('intval', $propios->fetchAll(PDO::FETCH_COLUMN)));
            if (array_diff($cita['servicios'], $mios)) throw new RuntimeException('Hay un servicio que no haces tú.');
            $agenda->crearCita($sid, $pid, (int) $c['id'], $cita['fecha'] . ' ' . $cita['hora'], $cita['servicios'], 'Cliente propio');
            aviso('Cita agendada en tu silla.');
        } elseif ($accion === 'borrar') {
            $st = $pdo->prepare('SELECT (SELECT count(*) FROM citas WHERE cliente_id = ?) + (SELECT count(*) FROM ventas WHERE cliente_id = ?)');
            $st->execute([$c['id'], $c['id']]);
            if ((int) $st->fetchColumn() > 0) throw new RuntimeException('Este cliente ya tiene citas o cobros: no se puede borrar.');
            $pdo->prepare('DELETE FROM clientes WHERE id = ?')->execute([$c['id']]);
            aviso('Cliente borrado.');
            redirigir('mis_clientes');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        redirigir('mis_clientes', ['id' => $c['id']]);
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) throw $e;
        $error = $e->getMessage();
    }
}

$cliente = $verId ? $mio($verId) : null;
$historial = [];
$servicios = [];
if ($cliente) {
    $st = $pdo->prepare("SELECT c.id, c.inicio, c.estado,
                                (SELECT string_agg(s.nombre, ' + ') FROM cita_servicios cs JOIN servicios s ON s.id = cs.servicio_id WHERE cs.cita_id = c.id) AS servicios,
                                (SELECT COALESCE(SUM(cs.precio),0) FROM cita_servicios cs WHERE cs.cita_id = c.id) AS precio
                           FROM citas c WHERE c.cliente_id = ? AND c.salon_id = ? ORDER BY c.inicio DESC LIMIT 30");
    $st->execute([$cliente['id'], $sid]);
    $historial = $st->fetchAll();
    $servicios = $agenda->serviciosDe($sid, $pid);
    $st = $pdo->prepare('SELECT id, nombre, descripcion, duracion_minutos, precio FROM servicios WHERE salon_id = ? AND profesional_id = ? AND activo ORDER BY nombre');
    $st->execute([$sid, $pid]);
    $servicios = array_merge($servicios, $st->fetchAll());
}
$sql = "SELECT c.id, c.nombre, c.telefono,
               (SELECT MAX(inicio) FROM citas WHERE cliente_id = c.id AND estado = 'atendida') AS ultima_visita,
               (SELECT MIN(inicio) FROM citas WHERE cliente_id = c.id AND estado IN ('reservada','confirmada') AND inicio >= now()) AS proxima
          FROM clientes c WHERE c.salon_id = ? AND c.profesional_privado_id = ?";
$params = [$sid, $pid];
if ($q !== '') {
    $sql .= ' AND (c.nombre ILIKE ? OR c.telefono LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . (preg_replace('/\D/', '', $q) ?: $q) . '%';
}
$st = $pdo->prepare($sql . ' ORDER BY c.nombre LIMIT 500');
$st->execute($params);
$clientes = $st->fetchAll();
vista('mis_clientes', compact('clientes', 'cliente', 'historial', 'servicios', 'error', 'q', 'cita'), 'Mis clientes · TuSalón');
