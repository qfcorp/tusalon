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
                aviso('Prueba gratis hasta el ' . date('d/m/Y', strtotime($hasta)) . '.');
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
foreach ($listaPlanes as $p) foreach (array_keys(Planes::PERIODOS_NOMBRE) as $per) $cotizaciones[$p['codigo']][$per] = $planes->cotizar($p['codigo'], $per);
$inicioSugerido = $planes->inicioSugerido($id)->format('Y-m-d');
vista_admin('admin_salon', compact('salon', 'pagos', 'registro', 'listaPlanes', 'cotizaciones', 'inicioSugerido', 'claveNueva'),
            $salon['nombre'] . ' · Panel Tukán');
