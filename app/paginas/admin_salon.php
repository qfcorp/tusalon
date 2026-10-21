<?php
use TuSalon\{PanelTukan, Planes};

$id = (int) ($_GET['id'] ?? 0);
$panel = new PanelTukan(db());
$planes = planes();
$salon = $panel->salon($id);
if (!$salon) { aviso('Salón no encontrado.', 'error'); redirigir('admin_salones'); }
$claveNueva = null;
if (!empty($_SESSION['clave_nueva_' . $id])) {   // recién creado desde "Nuevo salón": se muestra una vez
    $claveNueva = $_SESSION['clave_nueva_' . $id];
    unset($_SESSION['clave_nueva_' . $id]);
}
$correoDemo = null;
if ($claveNueva) {   // «Demo lista»: los datos van cifrados en el formulario de «Enviar por correo» (la contraseña no se guarda)
    $correoDemo = ['para' => (string) $claveNueva['email'], 'listo' => TuSalon\CorreoDemo::listo(), 'salon_id' => $id, 'estado' => null, 'error' => null,
                   'sello' => TuSalon\CorreoDemo::sellar(['usuario' => $claveNueva['email'], 'clave' => $claveNueva['clave'], 'enlace' => TuSalon\Agenda::urlBase(),
                                                         'hasta' => (string) ($salon['prueba_hasta'] ?? ''), 'contacto' => $claveNueva['nombre'],
                                                         'negocio' => $salon['nombre']], (int) superadmin()['id'], $id, $_SESSION)];
}

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        switch ($accion) {
            case 'pago':
                $r = $panel->registrarPago($id, $_POST);   // la misma lógica que usa el Panel Tukán Central
                registrar_admin("Pago registrado: {$r['plan']} {$r['periodo']} " . dinero($r['monto']) . " ({$r['metodo']})", $id);
                aviso('Pago registrado. El salón quedó activo.');
                break;
            case 'anular_pago':
                $st = db()->prepare('SELECT 1 FROM suscripciones WHERE id = ? AND salon_id = ?');
                $st->execute([(int) ($_POST['pago'] ?? 0), $id]);
                if (!$st->fetchColumn()) throw new RuntimeException('Ese pago no es de este salón.');
                $planes->anularPago((int) ($_POST['pago'] ?? 0));
                registrar_admin('Pago anulado #' . (int) $_POST['pago'], $id);
                aviso('Pago anulado.');
                break;
            case 'plan':
                $panel->cambiarPlan($id, (string) ($_POST['plan'] ?? ''));
                registrar_admin('Plan cambiado a ' . $_POST['plan'], $id);
                aviso('Plan cambiado.');
                break;
            case 'estado':
                $panel->cambiarEstado($id, (string) ($_POST['estado'] ?? ''));
                registrar_admin('Estado cambiado a ' . $_POST['estado'], $id);
                aviso('Estado cambiado.');
                break;
            case 'prueba':
                $hasta = $panel->extenderPrueba($id, (int) ($_POST['dias'] ?? 0));
                registrar_admin('Prueba extendida hasta ' . $hasta, $id);
                aviso('Demo hasta el ' . date('d/m/Y', strtotime($hasta)) . '.');
                break;
            case 'ia_tope':   // tope del mes de la IA de este salón (vacío = el sugerido)
                $t = trim((string) ($_POST['ia_tope'] ?? ''));
                if ($t !== '' && !is_numeric(str_replace(',', '.', $t))) throw new RuntimeException('Escribe el tope con números, por ejemplo 4,00.');
                TuSalon\Ia::guardarTope(db(), $id, $t === '' ? null : (float) str_replace(',', '.', $t));
                registrar_admin('Tope de IA del mes: ' . ($t === '' ? 'el sugerido' : '$' . number_format((float) str_replace(',', '.', $t), 2, ',', '.')), $id);
                aviso('Tope de IA guardado.');
                break;
            case 'notas':
                $panel->guardarNotas($id, (string) ($_POST['notas'] ?? ''));
                aviso('Notas guardadas.');
                break;
            case 'restaurar':   // Anexo A: sacar de la papelera (dentro de los 30 días)
                if (empty($salon['eliminado_en'])) throw new RuntimeException('Este salón no está en la papelera.');
                if (TuSalon\Papelera::fechaPurga($salon['eliminado_en']) <= date('Y-m-d')) throw new RuntimeException('Ya pasaron los 30 días de la papelera.');
                (new TuSalon\Papelera(db()))->restaurar($id);
                registrar_admin('Restaurado de la papelera', $id);
                aviso('Salón restaurado: vuelve tal como estaba.');
                break;
            case 'modulos':   // Anexo B: interruptores de módulos (apagar no borra datos)
                $mods = modulos();
                $actual = $mods->detalle($mods->salon($id));
                $marcados = TuSalon\Modulos::limpiar(array_map('strval', array_keys(array_filter((array) ($_POST['mod'] ?? [])))));
                $base = $actual['demo'] ? $actual['activos'] : $actual['contratados'];
                $acTxt = trim((string) ($_POST['precio_acordado'] ?? ''));
                if ($acTxt !== '' && !is_numeric(str_replace(',', '.', $acTxt))) throw new RuntimeException('Escribe el precio acordado con números, por ejemplo 30,00.');
                $r = $mods->cambiar($id, array_values(array_diff($marcados, $base)), array_values(array_diff($base, TuSalon\Modulos::conDependencias($marcados))),
                    $acTxt === '' ? null : (float) str_replace(',', '.', $acTxt), (string) ($_POST['motivo'] ?? ''),
                    (superadmin()['nombre'] ?? 'Tukán') . ' <' . (superadmin()['email'] ?? '') . '>', 'panel');
                if (!$r['sin_cambios']) registrar_admin('Módulos: ' . $mods->textoCambio($r), $id);
                aviso($r['sin_cambios'] ? 'Los módulos no cambiaron.' : 'Módulos guardados. ' . $mods->textoCambio($r) . '. Paga ' . dinero($r['detalle']['mensual']) . ' al mes.');
                redirigir('admin_salon', ['id' => $id]);
            case 'pedido_modulos':   // aprobar o rechazar "Pedir este módulo" (lo mismo que el Panel Tukán Central)
                $mods = modulos();
                $p = $mods->pedido((int) ($_POST['pedido'] ?? 0));
                if (!$p || (int) $p['salon_id'] !== $id || !in_array($p['estado'], ['pendiente', 'en_proceso'], true)) throw new RuntimeException('Ese pedido ya no está abierto.');
                $quien = (superadmin()['nombre'] ?? 'Tukán') . ' <' . (superadmin()['email'] ?? '') . '>';
                if (($_POST['resultado'] ?? '') === 'aprobar') {
                    $r = $mods->cambiar($id, TuSalon\Modulos::limpiar($p['modulos']), [], false, 'Pedido desde Mi plan', $quien, 'panel');
                    $mods->cerrarPedido((int) $p['id'], 'atendida', $quien, 'Aprobado');
                    registrar_admin('Pedido de módulos aprobado: ' . $mods->textoCambio($r), $id);
                    aviso('Pedido aprobado. ' . $mods->textoCambio($r) . '.');
                } else {
                    $mods->cerrarPedido((int) $p['id'], 'descartada', $quien, (string) ($_POST['respuesta'] ?? ''));
                    registrar_admin('Pedido de módulos rechazado', $id);
                    aviso('Pedido rechazado.');
                }
                redirigir('admin_salon', ['id' => $id]);
            case 'clave':
                $claveNueva = $panel->nuevaClaveDueno($id);
                registrar_admin('Nueva contraseña para el dueño', $id);
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        if ($accion !== 'clave') redirigir('admin_salon', ['id' => $id]);
    } catch (RuntimeException $e) {
        aviso($e->getMessage(), 'error');
        redirigir('admin_salon', ['id' => $id]);
    }
    $salon = $panel->salon($id);
}
$pagos = $panel->pagos($id);
$registro = $panel->registro($id, 15);
$listaPlanes = $planes->lista();
$cotizaciones = [];
foreach ($listaPlanes as $p) foreach (array_keys(Planes::PERIODOS_NOMBRE) as $per) $cotizaciones[$p["codigo"]][$per] = $planes->cotizar($p["codigo"], $per, $id);
$inicioSugerido = $planes->inicioSugerido($id)->format('Y-m-d');
$modDetalle = modulos()->detalle(modulos()->salon($id));
$modHistorial = modulos()->historial($id, 10);
$modPedidos = modulos()->pedidos($id);
$iaInfo = ['tope' => TuSalon\Ia::tope(db(), $id), 'propio' => TuSalon\Ia::topePropio(db(), $id), 'modulo' => TuSalon\Ia::conModulo(db(), $id),
           'gasto' => TuSalon\Ia::gastoMes(db(), $id)];
vista_admin('admin_salon', compact('correoDemo', 'iaInfo', 'salon', 'pagos', 'registro', 'listaPlanes', 'cotizaciones', 'inicioSugerido', 'claveNueva', 'modDetalle', 'modHistorial', 'modPedidos'),
            $salon['nombre'] . ' · Panel Tukán');
