<?php
use TuSalon\{Agenda, Caja, Liquidacion};

$hoy = date('Y-m-d');
$sid = (int) $u['salon_id'];
$citas = array_map(fn($c) => ocultar_cliente_privado($c, $u), (new Agenda(db()))->delDia($sid, $hoy));
$caja = (new Caja(db()))->resumenDia($sid, $hoy);
$resumen = es_recepcion($u) ? null : (new Liquidacion(db()))->resumenDueno($sid, $hoy, $hoy);   // recepción no ve ganancias

$pendientes = array_values(array_filter($citas, fn($c) => in_array($c['estado'], ['reservada', 'confirmada'], true)));
$atendidas = count(array_filter($citas, fn($c) => $c['estado'] === 'atendida'));

$solicitudes = count($agendaObj = (new Agenda(db()))->solicitudes($sid, $u));
$auto = new TuSalon\Automaticas(db());
$ahora = new DateTimeImmutable();
$porRecordar = count(array_filter($auto->recordatoriosManana($sid, $ahora, false), fn($c) => !$c['recordatorio_en'] && !$c['confirmada_cliente_en']));
$cumpleHoy = count(array_filter($auto->cumpleanosHoy($sid, $ahora), fn($c) => !$c['saludado']));
$stockBajo = modulo('inventario') && !es_recepcion($u) ? (new TuSalon\Inventario(db()))->bajos((int) $u['salon_id']) : [];
$st = db()->prepare("SELECT count(*) FROM citas WHERE salon_id = ? AND anticipo_estado = 'por_revisar' AND estado IN ('pendiente','reservada','confirmada')");
$st->execute([(int) $u['salon_id']]);
$comprobantes = (int) $st->fetchColumn();

// Primeros pasos (identidad gráfica Tukán, 10 nov 2026): solo el dueño y sus administradores; se calculan con los datos del salón.
// «Ocultar» se guarda por salón en ajustes (pasos_ocultos_<id>).
$claveOcultos = 'pasos_ocultos_' . $sid;
if (es_post() && ($_POST['accion'] ?? '') === 'ocultar_pasos' && es_dueno_o_admin($u)) {
    verificar_csrf();
    db()->prepare("INSERT INTO ajustes (clave, valor) VALUES (?, '1') ON CONFLICT (clave) DO UPDATE SET valor = '1'")->execute([$claveOcultos]);
    redirigir('inicio');
}
$pasos = [];
if (es_dueno_o_admin($u)) {
    $st = db()->prepare('SELECT valor FROM ajustes WHERE clave = ?');
    $st->execute([$claveOcultos]);
    if (!$st->fetchColumn()) {
        $cuenta = function (string $sql) use ($sid): int { $q = db()->prepare($sql); $q->execute([$sid]); return (int) $q->fetchColumn(); };
        require_once RAIZ . '/src/TukanLegal.php';
        $pasos = [
            ['Agrega tus servicios y sus precios', url('servicios'), $cuenta('SELECT count(*) FROM servicios WHERE salon_id = ? AND activo') > 0],
            ['Agrega a tu equipo (quienes atienden en el salón)', url('equipo'), $cuenta('SELECT count(*) FROM profesionales WHERE salon_id = ? AND activo') > 1],
            ['Registra tu primer cliente', url('clientes'), $cuenta('SELECT count(*) FROM clientes WHERE salon_id = ?') > 0],
            ['Agenda tu primera cita', url('cita_nueva'), $cuenta('SELECT count(*) FROM citas WHERE salon_id = ?') > 0],
            ['Cobra tu primer servicio', url('cobrar'), $cuenta('SELECT count(*) FROM ventas WHERE salon_id = ?') > 0],
            ['Revisa tu plan y acepta los términos', url('completa'), TukanLegal::aceptado(db(), (string) $sid)],
        ];
        if (!in_array(false, array_column($pasos, 2), true)) $pasos = [];   // todos hechos: ya no sale
    }
}
vista('inicio', compact('citas', 'caja', 'resumen', 'pendientes', 'atendidas', 'solicitudes', 'porRecordar', 'cumpleHoy', 'stockBajo', 'comprobantes', 'pasos'), 'Inicio · TuSalón');
