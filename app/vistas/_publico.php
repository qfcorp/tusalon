<?php
/** Diseño de las páginas públicas (portal de reservas). @var string $contenido @var string $titulo */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1E5A4C">
<meta name="robots" content="noindex">
<title><?= e($titulo) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css?v=9">
</head>
<body class="publico">
<main class="principal publico-principal" id="contenido">
    <?= $contenido ?>
    <p class="publico-pie">Reservas con <strong>TuSalón</strong></p>
</main>
<script src="/assets/app.js?v=9" defer></script>
<script src="/assets/reservar.js?v=5" defer></script>
</body>
</html>
