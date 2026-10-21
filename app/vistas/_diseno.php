<?php
/** @var string $contenido @var string $titulo @var ?array $u */
$rutaActual = $_GET['r'] ?? 'inicio';
$menu = [
    'inicio'   => ['Hoy',      'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
    'agenda'   => ['Agenda',   'M7 3v3M17 3v3M4 8h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z'],
    'caja'     => ['Caja',     'M3 7h18v12H3zM3 11h18M7 15h3'],
    'clientes' => ['Clientes', 'M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 19v-1a4 4 0 0 0-3-3.9M15 4.1a3 3 0 0 1 0 5.8'],
    'equipo'   => ['Equipo',   'M6 20v-8M6 8V4M12 20v-4M12 12V4M18 20v-10M18 6V4M4 12h4M10 16h4M16 10h4'],
];
$masActivo = in_array($rutaActual, ['equipo', 'servicios', 'cuentas', 'completa', 'horario', 'inventario', 'paquetes', 'tarjetas', 'puntos', 'clientes', 'cliente', 'ficha', 'reporte', 'avisos_clientes', 'gastos', 'recepcion', 'administradores'], true);
$pendientesCampana = 0;
if ($u) {
    $pendientesCampana = (new \TuSalon\Notificaciones(db()))->sinLeer((int) $u['id']);
    if (es_peluquero($u)) {
        $menu = [
            'mi_portal'   => ['Mi día', 'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
            'solicitudes' => ['Solicitudes', 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4'],
        ];
        if (($u['tipo_profesional'] ?? '') === 'alquiler' && modulo('clientes_privados', $u)) {
            $menu['mis_clientes'] = ['Mis clientes', 'M16 19v-1a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v1M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 19v-1a4 4 0 0 0-3-3.9M15 4.1a3 3 0 0 1 0 5.8'];
        }
    } elseif (es_recepcion($u)) {
        $masActivo = false;
        $menu = ['inicio' => $menu['inicio'], 'agenda' => $menu['agenda'],
                 'solicitudes' => ['Solicitudes', 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4'],
                 'caja' => $menu['caja'], 'clientes' => $menu['clientes']];
    } else {
        $menu = ['inicio' => $menu['inicio'], 'agenda' => $menu['agenda'],
                 'solicitudes' => ['Solicitudes', 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4'],
                 'caja' => $menu['caja'], 'equipo' => $menu['equipo']];
    }
}
$avisos = tomar_avisos();
$esDuenoAqui = es_dueno_o_admin($u);
$diasPrueba = $esDuenoAqui ? dias_prueba($u) : null;
$estadoCuenta = $esDuenoAqui ? planes()->estadoCuenta($u) : null;
$diasPlan = ($estadoCuenta === 'activo' && !empty($u['activo_hasta']))
    ? (int) (new DateTimeImmutable('today'))->diff(new DateTimeImmutable($u['activo_hasta']))->days : null;
$adminViendo = !empty($_SESSION['admin_viendo']) && superadmin();
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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=14">
</head>
<body class="<?= $u ? 'con-sesion' : 'sin-sesion' ?>">
<?php if ($u): ?>
<aside class="lateral" aria-label="Menú principal">
    <a class="marca" href="<?= e(url('inicio')) ?>">
        <span class="marca-sello" aria-hidden="true">Ts</span>
        <span class="marca-texto">
            <strong><?= e($u['salon']) ?></strong>
            <small><?= $u['estado'] === 'prueba' ? 'Demo' : 'Plan ' . e(modulos()->planes()[$u['plan_codigo']]['nombre'] ?? $u['plan_codigo']) ?></small>
        </span>
    </a>
    <nav class="menu">
        <?php foreach ($menu as $r => [$texto, $icono]): ?>
            <a href="<?= e(url($r)) ?>" class="<?= ($rutaActual === $r || ($r === 'equipo' && $masActivo)) ? 'activo' : '' ?>"
               <?= $rutaActual === $r ? 'aria-current="page"' : '' ?>>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="<?= $icono ?>"/></svg>
                <span><?= $r === 'equipo' ? '<span class="solo-ancho">Equipo</span><span class="solo-celular">Más</span>' : e($texto) ?></span>
                <?php if ($r === 'solicitudes' && $pendientesCampana > 0): ?><span class="contador" aria-label="<?= $pendientesCampana ?> sin leer"><?= $pendientesCampana ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="lateral-pie">
        <?php if (es_recepcion($u)): ?>
        <a href="<?= e(url('avisos_clientes')) ?>">Avisos a clientes</a>
        <?php elseif (es_dueno_o_admin($u)): ?>
        <a href="<?= e(url('clientes')) ?>">Clientes</a>
        <a href="<?= e(url('avisos_clientes')) ?>">Avisos a clientes</a>
        <?php if (modulo('reportes')): ?><a href="<?= e(url('reporte')) ?>">Reporte del mes</a><?php endif; ?>
        <?php if (modulo('gastos_sri')): ?><a href="<?= e(url('gastos')) ?>">Gastos y facturas</a><?php endif; ?>
        <a href="<?= e(url('servicios')) ?>">Servicios y precios</a>
        <?php if (modulo('inventario')): ?><a href="<?= e(url('inventario')) ?>">Inventario</a><?php endif; ?>
        <?php if (modulo('paquetes_regalo')): ?><a href="<?= e(url('paquetes')) ?>">Paquetes</a><?php endif; ?>
        <?php if (modulo('paquetes_regalo')): ?><a href="<?= e(url('tarjetas')) ?>">Tarjetas de regalo</a><?php endif; ?>
        <?php if (modulo('puntos')): ?><a href="<?= e(url('puntos')) ?>">Puntos</a><?php endif; ?>
        <a href="<?= e(url('horario')) ?>">Horario y reservas en línea</a>
        <a href="<?= e(url('cuentas')) ?>">Cuentas del equipo</a>
        <?php if (modulo('recepcion')): ?><a href="<?= e(url('recepcion')) ?>">Recepción</a><?php endif; ?>
        <a href="<?= e(url('administradores')) ?>">Administradores</a>
        <a href="<?= e(url('completa')) ?>">Mi plan</a>
        <?php endif; ?>
        <a href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </div>
</aside>
<?php endif; ?>

<main class="principal" id="contenido">
    <?php if ($diasPrueba !== null && $diasPrueba >= 0): ?>
        <p class="franja-prueba">
            Demo: <?= $diasPrueba === 0 ? 'termina hoy' : "te quedan $diasPrueba " . ($diasPrueba === 1 ? 'día' : 'días') ?>.
            <a href="<?= e(url('completa')) ?>">Contratar mi plan</a>
        </p>
    <?php endif; ?>
    <?php if ($adminViendo): ?>
        <p class="franja-admin">Estás viendo <strong><?= e($u['salon']) ?></strong> como administrador de Tukán.
            <a href="<?= e(url('admin_volver')) ?>">Volver al panel</a></p>
    <?php endif; ?>
    <?php if ($estadoCuenta === 'por_vencer'): ?>
        <p class="franja-prueba">Tu plan venció el <?= (new DateTimeImmutable($u['activo_hasta']))->format('d/m') ?>. Renueva en los próximos días para no perder el acceso.
            <a href="<?= e(url('completa')) ?>">Renovar</a></p>
    <?php elseif ($diasPlan !== null && $diasPlan <= 5): ?>
        <p class="franja-prueba">Tu plan vence <?= $diasPlan === 0 ? 'hoy' : "en $diasPlan " . ($diasPlan === 1 ? 'día' : 'días') ?>.
            <a href="<?= e(url('completa')) ?>">Renovar</a></p>
    <?php endif; ?>
    <?php foreach ($avisos as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<script src="/assets/app.js?v=11" defer></script>
</body>
</html>
