<?php
use TuSalon\Fidelidad;

if (!requiere_completa($u, 'Puntos', 'Tus clientes ganan puntos cada vez que pagan y los cambian por descuentos. Así vuelven más seguido.')) return;
$sid = (int) $u['salon_id'];
$fid = new Fidelidad(db());
$error = null;

if (es_post()) {
    verificar_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'config') {
            $fid->guardarConfigPuntos($sid, !empty($_POST['activo']), numero($_POST['por_dolar'] ?? '') ?? 0,
                                      (numero($_POST['valor_100'] ?? '') ?? 0) / 100, (int) ($_POST['minimo'] ?? 0));
            aviso('Puntos guardados.');
        } else {
            $fid->ajustarPuntos($sid, (int) ($_POST['cliente_id'] ?? 0), (int) ($_POST['puntos'] ?? 0), (string) ($_POST['motivo'] ?? ''));
            aviso('Puntos actualizados.');
        }
        redirigir('puntos');
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$conf = $fid->configPuntos($sid);
$mejores = $fid->mejoresClientes($sid);
$clientes = clientes_para_elegir($sid);
vista('puntos', compact('conf', 'mejores', 'clientes', 'error'), 'Puntos · TuSalón');
