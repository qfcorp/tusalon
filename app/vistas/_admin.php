<?php
/** Diseño del panel del super administrador. @var string $contenido @var string $titulo @var ?array $admin */
$rutaActual = (string) ($_GET['r'] ?? '');
$menuAdmin = ['admin' => 'Resumen', 'admin_salones' => 'Salones', 'admin_nuevo' => 'Nuevo salón', 'admin_planes' => 'Planes y precios'];
$avisos = tomar_avisos();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#143F35">
<meta name="robots" content="noindex">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=11">
</head>
<body class="admin">
<?php if ($admin): ?>
<header class="admin-barra">
    <a class="admin-marca" href="<?= e(url('admin')) ?>"><span class="marca-sello" aria-hidden="true">Tk</span>
        <span><strong>Panel Tukán</strong><small>TuSalón · super administrador</small></span></a>
    <nav class="admin-menu" aria-label="Menú del panel">
        <?php foreach ($menuAdmin as $r => $txt): ?>
            <a href="<?= e(url($r)) ?>" class="<?= $rutaActual === $r || ($r === 'admin_salones' && $rutaActual === 'admin_salon') ? 'activo' : '' ?>"
               <?= $rutaActual === $r ? 'aria-current="page"' : '' ?>><?= $txt ?></a>
        <?php endforeach; ?>
        <a href="<?= e(url('admin_salir')) ?>">Salir</a>
    </nav>
</header>
<?php endif; ?>
<main class="principal admin-principal" id="contenido">
    <?php foreach ($avisos as $a): ?>
        <p class="aviso aviso-<?= e($a['tipo']) ?>" role="status"><?= e($a['texto']) ?></p>
    <?php endforeach; ?>
    <?= $contenido ?>
</main>
<script src="/assets/app.js?v=10" defer></script>
</body>
</html>
