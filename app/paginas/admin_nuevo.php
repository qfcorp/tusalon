<?php
/**
 * El super administrador crea una demo para un cliente (todos los módulos, o los que marque). Si viene de un pedido
 * del formulario "Solicitar demo" (?pedido=N), los datos se llenan solos y el pedido queda como "demo creada".
 */
$pedidos = new TuSalon\PedidosDemo(db());
$pedido = isset($_REQUEST['pedido']) ? $pedidos->pedido((int) $_REQUEST['pedido']) : null;
if ($pedido && !in_array($pedido['estado'], ['nueva', 'contactada'], true)) $pedido = null;
$d = ['salon' => $pedido['salon'] ?? '', 'nombre' => $pedido['nombre'] ?? '', 'telefono' => $pedido['whatsapp'] ?? '', 'email' => $pedido['email'] ?? '', 'plan' => 'completa'];
$todos = !isset($_POST['solo']);
$elegidos = TuSalon\Modulos::ids();
$error = null;
if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $elegidos = TuSalon\Modulos::conDependencias(array_map('strval', array_keys(array_filter((array) ($_POST['mod'] ?? [])))));
    $clave = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
    try {
        db()->beginTransaction();
        $alta = (new TuSalon\Altas(db()))->crearSalonConDueno($d['salon'], $d['nombre'], $d['telefono'], $d['email'], $clave, $d['plan'], $pedido ? 'demo_pedida' : 'super');
        if (!$todos && $elegidos !== TuSalon\Modulos::ids()) {
            db()->prepare('UPDATE salones SET modulos_demo = ? WHERE id = ?')->execute([$elegidos ? implode(',', $elegidos) : TuSalon\Modulos::NINGUNO, $alta['salon_id']]);
        }
        $quien = (superadmin()['nombre'] ?? 'Tukán') . ' <' . (superadmin()['email'] ?? '') . '>';
        if ($pedido) {
            $pedidos->marcar((int) $pedido['id'], 'demo_creada', $quien, 'Demo creada desde el panel de Tukán', $alta['salon_id']);
            if ($pedido['necesita'] || $pedido['modulos']) {
                (new TuSalon\PanelTukan(db()))->guardarNotas($alta['salon_id'], 'Pedido de demo: ' . trim((string) $pedido['necesita'])
                    . ($pedido['modulos'] ? ' · Módulos de interés: ' . implode(', ', array_map([modulos(), 'nombre'], TuSalon\Modulos::limpiar($pedido['modulos']))) : ''));
            }
        }
        db()->commit();
        registrar_admin('Demo creada desde el panel' . ($pedido ? ' (pedido de demo N.º ' . $pedido['id'] . ')' : ''), $alta['salon_id']);
        $_SESSION['clave_nueva_' . $alta['salon_id']] = ['email' => strtolower($d['email']), 'clave' => $clave, 'nombre' => $d['nombre']];
        aviso('Demo creada' . ($todos ? ' con todos los módulos' : ' con ' . count($elegidos) . ' módulos') . '. Si ya pagó, registra el pago abajo.');
        redirigir('admin_salon', ['id' => $alta['salon_id'], 'nuevo' => 1]);
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $e->getMessage();
    }
}
$listaPlanes = planes()->lista();
vista_admin('admin_nuevo', compact('d', 'error', 'listaPlanes', 'pedido', 'todos', 'elegidos'), 'Nueva demo · Panel Tukán');
