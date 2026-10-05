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
                $plan = (string) ($_POST['plan'] ?? '');
                $periodo = (string) ($_POST['periodo'] ?? '');
                $inicio = (string) ($_POST['inicio'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $inicio)) throw new RuntimeException('Elige la fecha de inicio.');
                $metodo = (string) ($_POST['metodo'] ?? '');
                if (!in_array($metodo, ['transferencia', 'deuna', 'payphone', 'plux', 'efectivo', 'tarjeta', 'cortesia'], true)) throw new RuntimeException('Elige cómo pagó.');
                $montoTxt = trim((string) ($_POST['monto'] ?? ''));
                $monto = $montoTxt === '' ? null : (float) str_replace(',', '.', $montoTxt);
                $dominio = trim((string) ($_POST['dominio'] ?? '')) ?: null;
                if ($dominio !== null && !preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/i', $dominio)) throw new RuntimeException('El dominio no es válido (ej. barberiaelcorte.com).');
                $planes->suscribir($id, $plan, $periodo, new DateTimeImmutable($inicio), $metodo, $dominio, $monto);
                $c = $planes->cotizar($plan, $periodo);
                registrar_admin("Pago registrado: $plan $periodo " . dinero($monto ?? $c['monto']) . " ($metodo)", $id);
                aviso('Pago registrado. El salón quedó activo.');
                break;
            case 'anular_pago':
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
