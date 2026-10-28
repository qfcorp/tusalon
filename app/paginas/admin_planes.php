<?php
use TuSalon\Planes;

$planes = planes();
if (es_post()) {
    verificar_csrf();
    try {
        if (($_POST['accion'] ?? '') === 'catalogo') {   // Anexo B: precios y descripciones de los módulos y qué trae cada plan
            $mods = [];
            foreach ((array) ($_POST['m'] ?? []) as $id => $m) if (is_array($m)) $mods[(string) $id] = array_intersect_key($m, array_flip(['precio_mensual', 'descuento', 'orden', 'descripcion']));
            $pl = [];
            foreach (modulos()->planes() as $codigo => $_) $pl[$codigo] = array_map('strval', array_keys(array_filter((array) ($_POST['plan_mod'][$codigo] ?? []))));
            $cambios = modulos()->guardarCatalogo($mods, $pl);
            if ($cambios) registrar_admin('Catálogo de módulos: ' . implode('; ', $cambios));
            aviso($cambios ? 'Catálogo guardado. Los precios nuevos se aplican a los próximos pagos.' : 'El catálogo no cambió.');
        } elseif (($_POST['accion'] ?? '') === 'plan') {
            $max = trim((string) ($_POST['max_profesionales'] ?? ''));
            $elegir = trim((string) ($_POST['modulos_elegir'] ?? ''));
            $extraPrecio = trim(str_replace(',', '.', (string) ($_POST['precio_modulo_extra'] ?? '')));
            $planes->guardarPlan((string) ($_POST['codigo'] ?? ''), (string) ($_POST['nombre'] ?? ''),
                (float) str_replace(',', '.', (string) ($_POST['precio_mensual'] ?? '0')), $max === '' ? null : (int) $max,
                (string) ($_POST['descripcion'] ?? ''), $elegir === '' ? 0 : (int) $elegir, $extraPrecio === '' ? null : (float) $extraPrecio);
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
