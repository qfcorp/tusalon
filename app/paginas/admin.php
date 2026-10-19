<?php
$panel = new TuSalon\PanelTukan(db());
$r = $panel->resumen();
$registro = $panel->registro(null, 12);
$pedidosDemo = new TuSalon\PedidosDemo(db());
if (es_post()) {   // marcar un pedido de demo como contactado o descartado
    verificar_csrf();
    $p = $pedidosDemo->pedido((int) ($_POST['pedido'] ?? 0));
    $estado = (string) ($_POST['estado'] ?? '');
    if ($p && in_array($p['estado'], ['nueva', 'contactada'], true) && in_array($estado, ['contactada', 'descartada'], true)) {
        $pedidosDemo->marcar((int) $p['id'], $estado, (superadmin()['nombre'] ?? 'Tukán') . ' <' . (superadmin()['email'] ?? '') . '>');
        registrar_admin("Pedido de demo N.º {$p['id']} ({$p['salon']}): $estado");
        aviso('Pedido de demo marcado como ' . TuSalon\PedidosDemo::ESTADOS[$estado] . '.');
    }
    redirigir('admin');
}
$demos = $pedidosDemo->lista();
$pedidosModulos = modulos()->pedidos();
vista_admin('admin', compact('r', 'registro', 'demos', 'pedidosModulos'), 'Resumen · Panel Tukán');
