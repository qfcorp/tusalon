<?php
use TuSalon\Planes;

$planes = planes();
if (es_post()) {
    verificar_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'plan') {
            $max = trim((string) ($_POST['max_profesionales'] ?? ''));
            $planes->guardarPlan((string) ($_POST['codigo'] ?? ''), (string) ($_POST['nombre'] ?? ''),
                (float) str_replace(',', '.', (string) ($_POST['precio_mensual'] ?? '0')), $max === '' ? null : (int) $max,
                (string) ($_POST['descripcion'] ?? ''));
            registrar_admin('Plan ' . $_POST['codigo'] . ' actualizado: ' . dinero((float) str_replace(',', '.', (string) $_POST['precio_mensual'])) . ' al mes');
            aviso('Plan guardado. Los precios nuevos se aplican a los próximos pagos.');
        } else {
            $planes->guardarAjustes($_POST);
            registrar_admin('Ajustes de prueba y promociones actualizados');
            aviso('Ajustes guardados.');
        }
        redirigir('admin_planes');
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        redirigir('admin_planes');
    }
}
$lista = $planes->lista();
$a = $planes->ajustes();
$tabla = [];
foreach ($lista as $p) foreach (array_keys(Planes::PERIODOS_NOMBRE) as $per) $tabla[$p['codigo']][$per] = $planes->cotizar($p['codigo'], $per);
vista_admin('admin_planes', compact('lista', 'a', 'tabla'), 'Planes y precios · Panel Tukán');
