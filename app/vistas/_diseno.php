<?php
/**
 * Diseño general de TuSalón con la identidad gráfica común de Tukán (10 nov 2026): barra de arriba del color del programa,
 * menú azul noche con secciones y emojis (public/assets/tukan-ui.css y tukan-ui.js, idénticos en todos los programas Tukán).
 * Ver docs/LINEA_GRAFICA.md. @var string $contenido @var string $titulo @var ?array $u
 */
require_once dirname(__DIR__, 2) . '/src/TukanLegal.php';   // enlaces Términos · Privacidad del menú y aviso de términos
$rutaActual = $_GET['r'] ?? 'inicio';
// Pantallas que cuelgan de una opción del menú (para marcarla como activa)
$padre = ['cita' => 'agenda', 'cita_nueva' => 'agenda', 'cliente' => 'clientes', 'ficha' => 'clientes', 'fotos' => 'clientes',
          'horario_peluquero' => 'equipo', 'comprobante' => 'solicitudes'];
$activa = $padre[$rutaActual] ?? $rutaActual;
$pendientesCampana = 0;
$grupos = [];
if ($u) {
    $pendientesCampana = (new \TuSalon\Notificaciones(db()))->sinLeer((int) $u['id']);
    $globo = $pendientesCampana > 0 ? $pendientesCampana : null;
    if (es_peluquero($u)) {
        $grupos['Mi trabajo'] = [
            ['mi_portal', '📊', 'Mi día'],
            ['solicitudes', '🔔', 'Solicitudes', $globo],
        ];
        if (($u['tipo_profesional'] ?? '') === 'alquiler' && modulo('clientes_privados', $u)) {
            $grupos['Mi trabajo'][] = ['mis_clientes', '👥', 'Mis clientes'];
        }
        $grupos['Ayuda'] = [['ayuda', '❓', 'Ayuda']];
    } elseif (es_recepcion($u)) {
        $grupos['Principal'] = [
            ['inicio', '📊', 'Inicio'],
            ['agenda', '📅', 'Agenda'],
            ['solicitudes', '🔔', 'Solicitudes', $globo],
            ['caja', '💵', 'Caja'],
        ];
        $grupos['Clientes'] = [
            ['clientes', '👥', 'Clientes'],
            ['avisos_clientes', '📣', 'Avisos a clientes'],
        ];
        $grupos['Ayuda'] = array_values(array_filter([
            modulo('asistente_ia') ? ['asistente', '✨', 'Asistente IA'] : null,
            ['ayuda', '❓', 'Ayuda'],
        ]));
    } else {   // dueño y administradores
        $grupos['Principal'] = [
            ['inicio', '📊', 'Inicio'],
            ['agenda', '📅', 'Agenda'],
            ['solicitudes', '🔔', 'Solicitudes', $globo],
            ['caja', '💵', 'Caja'],
        ];
        $grupos['Clientes y ventas'] = array_values(array_filter([
            ['clientes', '👥', 'Clientes'],
            ['avisos_clientes', '📣', 'Avisos a clientes'],
            modulo('inventario') ? ['inventario', '📦', 'Inventario'] : null,
            modulo('paquetes_regalo') ? ['paquetes', '🎁', 'Paquetes'] : null,
            modulo('paquetes_regalo') ? ['tarjetas', '🎟️', 'Tarjetas de regalo'] : null,
            modulo('puntos') ? ['puntos', '⭐', 'Puntos'] : null,
        ]));
        $grupos['Equipo'] = array_values(array_filter([
            ['equipo', '💇', 'Equipo'],
            ['cuentas', '🧮', 'Cuentas del equipo'],
            modulo('recepcion') ? ['recepcion', '🛎️', 'Recepción'] : null,
            ['administradores', '🛡️', 'Administradores'],
        ]));
        $grupos['Dinero'] = array_values(array_filter([
            modulo('reportes') ? ['reporte', '📈', 'Reporte del mes'] : null,
            modulo('gastos_sri') ? ['gastos', '🧾', 'Gastos y facturas'] : null,
            ['tufactura', '🧾', 'Factura electrónica'],
        ]));
        $grupos['Configuración'] = array_values(array_filter([
            ['servicios', '✂️', 'Servicios y precios'],
            ['horario', '🕘', 'Horario y reservas en línea'],
            ['completa', '💳', 'Mi plan'],
            modulo('asistente_ia') ? ['asistente', '✨', 'Asistente IA'] : null,
            ['ayuda', '❓', 'Ayuda'],
        ]));
    }
}
$avisos = tomar_avisos();
$roles = ['dueno' => 'Dueño', 'admin' => 'Administrador', 'recepcion' => 'Recepción', 'profesional' => 'Peluquero'];
$iniciales = 'Ts';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1E5A4C">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="TuSalón">
<title><?= e($titulo) ?></title>
<link rel="stylesheet" href="/assets/tukan-ui.css?v=1">
<link rel="stylesheet" href="/assets/app.css?v=15">
<style>:root{ --tk-color:#1E5A4C; --tk-color-claro:#7FD1B9; --tk-color-texto:#ffffff; }</style>
</head>
<body class="tk <?= $u ? 'con-sesion' : 'sin-sesion' ?>">
<?php if ($u): ?>
<header class="tk-arriba">
    <div style="display:flex;align-items:center;gap:10px;min-width:0">
        <button class="tk-boton-menu" type="button" aria-label="Abrir el menú" aria-expanded="false">☰</button>
        <a class="tk-marca" href="<?= e(url(es_peluquero($u) ? 'mi_portal' : 'inicio')) ?>"><span class="tk-logo" aria-hidden="true"><?= $iniciales ?></span>
            <span><b><?= e($u['salon']) ?></b><small>TuSalón · <?= $u['estado'] === 'prueba' ? 'Demo' : 'Plan ' . e(modulos()->planes()[$u['plan_codigo']]['nombre'] ?? $u['plan_codigo']) ?></small></span></a>
    </div>
    <div class="tk-usuario"><span class="tk-nombre">👤 <?= e($u['nombre']) ?></span><span class="tk-rol"><?= e($roles[$u['rol']] ?? $u['rol']) ?></span><a class="tk-salir" href="<?= e(url('salir')) ?>">Salir</a></div>
</header>
<div class="tk-cuerpo">
    <nav class="tk-menu" aria-label="Menú principal">
        <?php foreach ($grupos as $grupo => $opciones): if (!$opciones) continue; ?>
            <div class="tk-menu-titulo"><?= e($grupo) ?></div>
            <?php foreach ($opciones as $op): [$r, $ico, $texto] = $op; $globito = $op[3] ?? null; ?>
                <a href="<?= e(url($r)) ?>"<?= $activa === $r ? ' class="activo"' : '' ?><?= $rutaActual === $r ? ' aria-current="page"' : '' ?>>
                    <span class="tk-ico" aria-hidden="true"><?= $ico ?></span> <?= e($texto) ?><?php if ($globito): ?> <span class="tk-globito contador" aria-label="<?= (int) $globito ?> sin leer"><?= (int) $globito ?></span><?php endif; ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <a href="<?= e(url('salir')) ?>"><span class="tk-ico" aria-hidden="true">🚪</span> Cerrar sesión</a>
        <p class="pie-legal tk-menu-legal"><?= TukanLegal::enlaces(url('privacidad')) ?></p>
    </nav>
    <div class="tk-fondo-menu"></div>
    <main class="tk-contenido" id="contenido">
        <?php ob_start(); foreach ($avisos as $a): ?>
            <p class="tk-aviso <?= $a['tipo'] === 'error' ? 'mal' : 'ok' ?> aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
        <?php endforeach; $avisosHtml = ob_get_clean(); ?>
        <?php if (empty($tirasMostradas)) require __DIR__ . '/_tiras.php'; ?>
        <?= $avisosHtml ?>
        <?= $contenido ?>
    </main>
</div>
<?php else: ?>
<header class="tk-arriba">
    <a class="tk-marca" href="<?= e(url('login')) ?>"><span class="tk-logo" aria-hidden="true"><?= $iniciales ?></span>
        <span><b>TuSalón</b><small>La agenda y la caja de tu peluquería</small></span></a>
</header>
<main class="tk-contenido tk-acceso" id="contenido">
    <?php foreach ($avisos as $a): ?>
        <p class="tk-aviso <?= $a['tipo'] === 'error' ? 'mal' : 'ok' ?> aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<?php endif; ?>
<script src="/assets/tukan-ui.js?v=1" defer></script>
<script src="/assets/app.js?v=11" defer></script>
</body>
</html>
