<?php
use TuSalon\CuentaCliente;

$slug = (string) ($_GET['s'] ?? '');
$st = db()->prepare('SELECT id, nombre, slug, telefono FROM salones WHERE slug = ?');
$st->execute([$slug]);
$salon = $st->fetch();
$cuentas = new CuentaCliente(db());
$cliente = $salon && isset($_SESSION['cliente'][(int) $salon['id']])
    ? $cuentas->datos((int) $salon['id'], (int) $_SESSION['cliente'][(int) $salon['id']]) : null;
if (!$cliente) {
    header('Location: /?r=cliente_entrar&s=' . rawurlencode($slug));
    exit;
}
$sid = (int) $salon['id'];
if (es_post()) {
    verificar_csrf();
    $cuentas->cambiarPermisoFotos($sid, (int) $cliente['id'], !empty($_POST['acepta_fotos']));
    $_SESSION['aviso_cliente'] = !empty($_POST['acepta_fotos'])
        ? 'Listo: el salón puede guardar fotos de tus servicios.' : 'Listo: el salón ya no guardará nuevas fotos tuyas.';
    header('Location: /?r=mi_cuenta&s=' . rawurlencode($slug));
    exit;
}
$avisoCliente = $_SESSION['aviso_cliente'] ?? null;
unset($_SESSION['aviso_cliente']);
$historial = $cuentas->historial($sid, (int) $cliente['id']);
$proximas = $cuentas->proximas($sid, (int) $cliente['id']);
vista_publica('mi_cuenta', compact('salon', 'cliente', 'historial', 'proximas', 'avisoCliente'), 'Mi cuenta · ' . $salon['nombre']);
