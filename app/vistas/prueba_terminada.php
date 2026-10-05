<?php
$titulos = [
    'prueba_vencida' => ['Tu prueba gratis terminó', 'Tus citas, clientes y cobros están guardados. Elige un plan para seguir usando TuSalón donde lo dejaste.', 'terminó mi prueba'],
    'vencido'        => ['Tu plan venció', 'Tu plan estaba pagado hasta el ' . (!empty($u['activo_hasta']) ? (new DateTimeImmutable($u['activo_hasta']))->format('d/m/Y') : '—') . '. Tus datos están guardados: renueva y sigues donde lo dejaste.', 'venció mi plan'],
    'suspendido'     => ['Tu cuenta está pausada', 'Tus datos están guardados. Escríbenos para reactivarla.', 'mi cuenta está pausada'],
    'cancelado'      => ['Tu cuenta está cerrada', 'Si quieres volver, escríbenos y la reactivamos con tus datos.', 'quiero reactivar mi cuenta'],
];
[$titulo, $texto, $motivo] = $titulos[$estadoCuenta] ?? $titulos['prueba_vencida'];
?>
<section class="bloque" style="max-width:560px">
    <h1><?= e($titulo) ?></h1>
    <p><?= e($texto) ?></p>
    <div class="acciones">
        <a class="boton" href="<?= e(url('completa')) ?>">Ver planes</a>
        <a class="boton boton-wa" href="https://wa.me/<?= e(whatsapp_ventas()) ?>?text=<?= rawurlencode("Hola, $motivo de TuSalón (" . $u['salon'] . ') y quiero contratar un plan.') ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a>
        <a class="boton boton-claro" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </div>
</section>
