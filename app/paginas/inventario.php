<?php
use TuSalon\Inventario;

if (!requiere_completa($u, 'Inventario', 'Lleva el control de tus productos: lo que vendes (shampoo, ceras) y lo que usas en el salón (tintes, oxidantes). Te avisa cuando algo se está acabando.')) return;
$sid = (int) $u['salon_id'];
$inv = new Inventario(db());
$error = null;
$verId = (int) ($_GET['id'] ?? 0);

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $pid = (int) ($_POST['producto_id'] ?? 0);
    try {
        switch ($accion) {
            case 'crear':
            case 'editar':
                $id = $inv->guardar($sid, $accion === 'editar' ? $pid : null, [
                    'nombre' => $_POST['nombre'] ?? '', 'marca' => $_POST['marca'] ?? '',
                    'precio' => numero($_POST['precio'] ?? '') ?? 0, 'costo' => numero($_POST['costo'] ?? '') ?? 0,
                    'stock' => (int) ($_POST['stock'] ?? 0), 'stock_minimo' => (int) ($_POST['stock_minimo'] ?? 0),
                    'para_venta' => ($_POST['para_venta'] ?? '1') === '1',
                    'activo' => $accion === 'crear' || !empty($_POST['activo']),
                ], (int) $u['id']);
                aviso($accion === 'crear' ? 'Producto agregado.' : 'Producto guardado.');
                redirigir('inventario', $accion === 'editar' ? ['id' => $id] : []);
            case 'entrada':
                $inv->entrada($sid, $pid, (int) ($_POST['cantidad'] ?? 0), numero($_POST['costo'] ?? ''), (string) ($_POST['nota'] ?? ''), (int) $u['id']);
                aviso('Listo, se sumó al stock.');
                break;
            case 'uso':
                $inv->uso($sid, $pid, (int) ($_POST['cantidad'] ?? 0), (string) ($_POST['nota'] ?? ''), (int) $u['id']);
                aviso('Listo, se descontó del stock.');
                break;
            case 'ajuste':
                $dif = $inv->ajustar($sid, $pid, (int) ($_POST['real'] ?? -1), (string) ($_POST['nota'] ?? ''), (int) $u['id']);
                aviso($dif === 0 ? 'El conteo coincide. No hubo cambios.' : 'Conteo corregido (' . ($dif > 0 ? '+' : '') . $dif . ').');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        redirigir('inventario', ['id' => $pid]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
        if ($pid) $verId = $pid;
    }
}

$productos = $inv->lista($sid, true);
$producto = $verId ? $inv->producto($sid, $verId) : null;
$movimientos = $producto ? $inv->movimientos($sid, $verId) : [];
$valor = $inv->valor($sid);
$bajos = $inv->bajos($sid);
vista('inventario', compact('productos', 'producto', 'movimientos', 'valor', 'bajos', 'error'), 'Inventario · TuSalón');
