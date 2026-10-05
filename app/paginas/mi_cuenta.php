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
$telegram = new \TuSalon\Telegram(db());
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? 'fotos');
    try {
        if ($accion === 'fotos') {
            $cuentas->cambiarPermisoFotos($sid, (int) $cliente['id'], !empty($_POST['acepta_fotos']));
            $_SESSION['aviso_cliente'] = !empty($_POST['acepta_fotos'])
                ? 'Listo: el salón puede guardar fotos de tus servicios.' : 'Listo: el salón ya no guardará nuevas fotos tuyas.';
        } elseif ($accion === 'cumple') {
            $cuentas->guardarCumple($sid, (int) $cliente['id'], (int) ($_POST['cumple_mes'] ?? 0) ?: null, (int) ($_POST['cumple_dia'] ?? 0) ?: null);
            $_SESSION['aviso_cliente'] = 'Cumpleaños guardado.';
        } elseif ($accion === 'tg_conectar') {
            $_SESSION['tg_cliente'] = $telegram->enlaceCliente((int) $cliente['id']);
        } elseif ($accion === 'tg_desconectar') {
            $telegram->desconectarCliente((int) $cliente['id']);
            $_SESSION['aviso_cliente'] = 'Ya no recibirás avisos por Telegram.';
        }
    } catch (RuntimeException $e) {
        $_SESSION['aviso_cliente'] = $e->getMessage();
    }
    header('Location: /?r=mi_cuenta&s=' . rawurlencode($slug));
    exit;
}
$avisoCliente = $_SESSION['aviso_cliente'] ?? null;
$tgEnlace = $_SESSION['tg_cliente'] ?? null;
unset($_SESSION['aviso_cliente'], $_SESSION['tg_cliente']);
$tgDisponible = \TuSalon\Telegram::configurado();
$historial = $cuentas->historial($sid, (int) $cliente['id']);
$proximas = $cuentas->proximas($sid, (int) $cliente['id']);
vista_publica('mi_cuenta', compact('salon', 'cliente', 'historial', 'proximas', 'avisoCliente', 'tgEnlace', 'tgDisponible'), 'Mi cuenta · ' . $salon['nombre']);
