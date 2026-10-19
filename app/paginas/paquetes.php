<?php
use TuSalon\Fidelidad;

$sid = (int) $u['salon_id'];
$fid = new Fidelidad(db());
$error = null;
$editar = (int) ($_GET['editar'] ?? 0);

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'crear' || $accion === 'editar') {
            $fid->guardarPaquete($sid, $accion === 'editar' ? (int) $_POST['paquete_id'] : null, [
                'nombre' => $_POST['nombre'] ?? '', 'servicio_id' => $_POST['servicio_id'] ?? 0, 'sesiones' => $_POST['sesiones'] ?? 0,
                'precio' => numero($_POST['precio'] ?? '') ?? 0, 'vigencia_dias' => $_POST['vigencia_dias'] ?? '',
                'activo' => $accion === 'crear' || !empty($_POST['activo']),
            ]);
            aviso($accion === 'crear' ? 'Paquete creado. Ya lo puedes vender.' : 'Paquete guardado.');
        } elseif ($accion === 'vender') {
            $fid->venderPaquete($sid, (int) ($_POST['paquete_id'] ?? 0), (int) ($_POST['cliente_id'] ?? 0), (string) ($_POST['metodo_pago'] ?? 'efectivo'));
            aviso('Paquete vendido. El dinero quedó en la caja de hoy.');
        } elseif ($accion === 'anular') {
            $fid->anularPaqueteVendido($sid, (int) ($_POST['vendido_id'] ?? 0));
            aviso('Venta del paquete anulada.');
        } else {
            throw new RuntimeException('Acción no válida.');
        }
        redirigir('paquetes');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$st = db()->prepare('SELECT id, nombre, precio FROM servicios WHERE salon_id = ? AND activo AND profesional_id IS NULL ORDER BY nombre');
$st->execute([$sid]);
$servicios = $st->fetchAll();
$paquetes = $fid->paquetes($sid);
$vendidos = $fid->paquetesVendidos($sid);
$clientes = clientes_para_elegir($sid);
$clienteElegido = (int) ($_GET['cliente'] ?? $_POST['cliente_id'] ?? 0);
$enEdicion = null;
foreach ($paquetes as $p) if ((int) $p['id'] === $editar) $enEdicion = $p;
vista('paquetes', compact('servicios', 'paquetes', 'vendidos', 'clientes', 'clienteElegido', 'enEdicion', 'error'), 'Paquetes · TuSalón');
