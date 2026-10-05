<?php
use TuSalon\Agenda;

$sid = (int) $u['salon_id'];
$pdo = db();
$error = null;

$d = [
    'fecha'       => (string) ($_REQUEST['fecha'] ?? date('Y-m-d')),
    'hora'        => (string) ($_REQUEST['hora'] ?? ''),
    'profesional' => (int) ($_REQUEST['profesional'] ?? 0),
    'cliente_id'  => (int) ($_REQUEST['cliente_id'] ?? 0),
    'cliente_nuevo' => trim((string) ($_POST['cliente_nuevo'] ?? '')),
    'telefono'    => trim((string) ($_POST['telefono'] ?? '')),
    'servicios'   => array_map('intval', (array) ($_POST['servicios'] ?? [])),
    'notas'       => trim((string) ($_POST['notas'] ?? '')),
];

if (es_post()) {
    verificar_csrf();
    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['fecha']) || !preg_match('/^\d{2}:\d{2}$/', $d['hora'])) {
            throw new RuntimeException('Elige el día y la hora.');
        }
        if (!$d['profesional']) {
            throw new RuntimeException('Elige con quién es la cita.');
        }
        $clienteId = $d['cliente_id'] ?: null;
        if (!$clienteId && $d['cliente_nuevo'] !== '') {
            $st = $pdo->prepare('INSERT INTO clientes (salon_id, nombre, telefono) VALUES (?,?,?) RETURNING id');
            $st->execute([$sid, $d['cliente_nuevo'], $d['telefono'] ?: null]);
            $clienteId = (int) $st->fetchColumn();
        }
        $id = (new Agenda($pdo))->crearCita($sid, $d['profesional'], $clienteId, $d['fecha'] . ' ' . $d['hora'],
                                            $d['servicios'], $d['notas'] ?: null);
        aviso('Cita agendada.');
        redirigir('cita', ['id' => $id]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}

$st = $pdo->prepare('SELECT id, nombre, tipo FROM profesionales WHERE salon_id = ? AND activo ORDER BY nombre');
$st->execute([$sid]);
$profesionales = $st->fetchAll();

$st = $pdo->prepare('SELECT id, nombre, precio, duracion_minutos FROM servicios WHERE salon_id = ? AND activo ORDER BY nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();

$st = $pdo->prepare('SELECT id, nombre, telefono FROM clientes WHERE salon_id = ? AND profesional_privado_id IS NULL ORDER BY nombre LIMIT 500');
$st->execute([$sid]);
$clientes = $st->fetchAll();

vista('cita_nueva', compact('d', 'error', 'profesionales', 'servicios', 'clientes'), 'Nueva cita · TuSalón');
