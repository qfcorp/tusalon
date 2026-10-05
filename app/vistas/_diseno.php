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
$masActivo = in_array($rutaActual, ['equipo', 'servicios', 'cuentas', 'completa', 'horario'], true);
$pendientesCampana = 0;
if ($u) {
    $pendientesCampana = (new \TuSalon\Notificaciones(db()))->sinLeer((int) $u['id']);
    if (es_peluquero($u)) {
        $menu = [
            'mi_portal'   => ['Mi día', 'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
            'solicitudes' => ['Solicitudes', 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4'],
        ];
    } else {
        $menu = ['inicio' => $menu['inicio'], 'agenda' => $menu['agenda'],
                 'solicitudes' => ['Solicitudes', 'M6 8a6 6 0 0 1 12 0c0 7 3 8 3 8H3s3-1 3-8M10 21h4'],
                 'caja' => $menu['caja'], 'equipo' => $menu['equipo']];
    }
}
$avisos = tomar_avisos();
$diasPrueba = ($u && !es_peluquero($u)) ? dias_prueba($u) : null;
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1E5A4C">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=8">
</head>
<body class="<?= $u ? 'con-sesion' : 'sin-sesion' ?>">
<?php if ($u): ?>
<aside class="lateral" aria-label="Menú principal">
    <a class="marca" href="<?= e(url('inicio')) ?>">
        <span class="marca-sello" aria-hidden="true">Ts</span>
        <span class="marca-texto">
            <strong><?= e($u['salon']) ?></strong>
            <small>Plan <?= $u['plan_codigo'] === 'completa' ? 'Completa' : 'Básica' ?></small>
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
        <?php if (!es_peluquero($u)): ?>
        <a href="<?= e(url('clientes')) ?>">Clientes</a>
        <a href="<?= e(url('servicios')) ?>">Servicios y precios</a>
        <a href="<?= e(url('horario')) ?>">Horario y reservas en línea</a>
        <a href="<?= e(url('cuentas')) ?>">Cuentas del equipo</a>
        <a href="<?= e(url('completa')) ?>">Mi plan</a>
        <?php endif; ?>
        <a href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </div>
</aside>
<?php endif; ?>

<main class="principal" id="contenido">
    <?php if ($diasPrueba !== null && $diasPrueba >= 0): ?>
        <p class="franja-prueba">
            Prueba gratis: <?= $diasPrueba === 0 ? 'termina hoy' : "te quedan $diasPrueba " . ($diasPrueba === 1 ? 'día' : 'días') ?>.
            <a href="<?= e(url('completa')) ?>">Ver planes</a>
        </p>
    <?php endif; ?>
    <?php foreach ($avisos as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<script src="/assets/app.js?v=1" defer></script>
</body>
</html>
