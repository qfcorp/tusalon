<?php
use TuSalon\FichaTecnica;

$sid = (int) $u['salon_id'];
$ft = new FichaTecnica(db());
$clienteId = (int) ($_GET['cliente'] ?? 0);
$st = db()->prepare('SELECT id, nombre, telefono, alergias FROM clientes WHERE id = ? AND salon_id = ?');
$st->execute([$clienteId, $sid]);
$cliente = $st->fetch();
if (!$cliente || !$ft->puedeVer($sid, $clienteId, $u)) {
    aviso('No puedes ver la ficha de ese cliente.', 'error');
    redirigir(es_peluquero($u) ? 'mi_portal' : 'clientes');
}
$error = null;
if (es_post()) {
    verificar_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'borrar') {
            $ft->borrar($sid, (int) ($_POST['ficha_id'] ?? 0), $u);
            aviso('Ficha borrada.');
        } else {
            $ft->guardar($sid, $clienteId, $u, $_POST, ['antes' => $_FILES['foto_antes'] ?? [], 'despues' => $_FILES['foto_despues'] ?? []]);
            aviso('Ficha guardada.');
        }
        redirigir('ficha', ['cliente' => $clienteId]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$fichas = $ft->deCliente($sid, $clienteId);
$st = db()->prepare('SELECT id, nombre FROM profesionales WHERE salon_id = ? AND activo ORDER BY nombre');
$st->execute([$sid]);
$profesionales = $st->fetchAll();
vista('ficha', compact('cliente', 'fichas', 'profesionales', 'error'), 'Ficha técnica · TuSalón');
