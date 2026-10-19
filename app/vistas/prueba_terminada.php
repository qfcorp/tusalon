<?php
$titulos = [
    'prueba_vencida' => ['Tu demo terminó', 'Contáctanos para contratar tu plan. Tus citas, clientes y cobros están guardados: sigues donde lo dejaste.', 'terminó mi demo'],
    'vencido'        => ['Tu plan venció', 'Tu plan estaba pagado hasta el ' . (!empty($u['activo_hasta']) ? (new DateTimeImmutable($u['activo_hasta']))->format('d/m/Y') : '—') . '. Tus datos están guardados: renueva y sigues donde lo dejaste.', 'venció mi plan'],
    'suspendido'     => ['Tu cuenta está pausada', 'Tus datos están guardados. Escríbenos para reactivarla.', 'mi cuenta está pausada'],
    'cancelado'      => ['Tu cuenta está cerrada', 'Si quieres volver, escríbenos y la reactivamos con tus datos.', 'quiero reactivar mi cuenta'],
];
[$titulo, $texto, $motivo] = $titulos[$estadoCuenta] ?? $titulos['prueba_vencida'];
$pausada = $estadoCuenta === 'suspendido';   // Anexo A: pausada por Tukán, con el motivo (genérico en revisión y uso indebido)
?>
<section class="bloque" style="max-width:560px">
    <h1><?= e($titulo) ?></h1>
    <?php if ($pausada): $wa = 'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode('Hola, mi cuenta de TuSalón está pausada (' . $u['salon'] . '). ¿Me ayudan a reactivarla?'); ?>
        <p class="aviso-pausa"><strong>Motivo:</strong> <?= e(\TuSalon\Papelera::textoPausa($u['pausa_tipo'] ?? null, $u['pausa_motivo'] ?? null)) ?></p>
        <p>Mientras está pausada no puedes trabajar en TuSalón y tu página de reservas dice "temporalmente no disponible". Tus datos están guardados.</p>
        <div class="acciones">
            <?php if (!en_app_play()): ?><a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribir a Tukán por WhatsApp (+<?= e(whatsapp_ventas()) ?>)</a>
            <?php else: ?><p>WhatsApp de Tukán: <strong>+<?= e(whatsapp_ventas()) ?></strong></p><?php endif; ?>
            <a class="boton boton-claro" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
        </div>
</section>
    <?php return; endif; ?>
    <p><?= e($texto) ?></p>
    <div class="acciones">
        <a class="boton" href="<?= e(url('completa')) ?>">Ver Mi plan</a>
        <?php if (!en_app_play()): ?><a class="boton boton-wa" href="https://wa.me/<?= e(whatsapp_ventas()) ?>?text=<?= rawurlencode("Hola, $motivo de TuSalón (" . $u['salon'] . ') y quiero contratar un plan.') ?>" target="_blank" rel="noopener">Escribir por WhatsApp</a><?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    </div>
</section>
