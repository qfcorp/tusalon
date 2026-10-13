<?php
use TuSalon\{Agenda, WhatsApp};
$ini = new DateTimeImmutable($cita['inicio']);
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$valor = array_sum(array_map(fn($s) => (float) $s['precio'], $cita['lista_servicios']));
$activa = in_array($cita['estado'], ['pendiente', 'reservada', 'confirmada'], true) && $ini > new DateTimeImmutable();
$estados = ['pendiente' => 'El salón la está confirmando', 'reservada' => 'Reservada', 'confirmada' => 'Confirmada',
            'atendida' => 'Atendida', 'no_asistio' => 'No asististe', 'cancelada' => 'Cancelada', 'rechazada' => 'No se pudo atender'];
$slugUrl = rawurlencode($salon['slug']);
$waSalon = $salon['telefono'] ? WhatsApp::enlace($salon['telefono'], 'Hola, te escribo por mi cita del ' . $ini->format('d/m') . ' a las ' . $ini->format('H:i') . '.') : null;
?>
<header class="publico-cabeza">
    <span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($salon['nombre'], 0, 1))) ?></span>
    <div><h1><?= e($salon['nombre']) ?></h1><p>Tu cita</p></div>
</header>
<?php if ($aviso): ?><p class="aviso aviso-ok" role="status"><?= e($aviso) ?></p><?php endif; ?>
<?php if ($avisoError): ?><p class="aviso aviso-error" role="alert"><?= e($avisoError) ?></p><?php endif; ?>

<section class="bloque confirmacion">
    <h2><?= e(explode(' ', trim((string) $cita['cliente']))[0]) ?>, esta es tu cita</h2>
    <ul class="lista">
        <li><span>Día</span><strong><?= Agenda::DIAS[(int) $ini->format('w')] ?> <?= (int) $ini->format('j') ?> de <?= $meses[(int) $ini->format('n')] ?></strong></li>
        <li><span>Hora</span><strong><?= $ini->format('H:i') ?></strong></li>
        <li><span>Con</span><strong><?= e($cita['profesional']) ?></strong></li>
        <li><span>Servicio</span><strong><?= e(implode(' + ', array_column($cita['lista_servicios'], 'nombre'))) ?></strong></li>
        <li><span>Valor a cancelar</span><strong><?= dinero($valor) ?></strong></li>
        <?php if (in_array($cita['anticipo_estado'], ['pagado', 'usado'], true)): ?>
            <li><span>Anticipo pagado</span><strong>✅ <?= dinero($cita['anticipo']) ?> (pagas <?= dinero(max(0, $valor - (float) $cita['anticipo'])) ?> en el salón)</strong></li>
        <?php endif; ?>
        <li><span>Estado</span><strong><span class="etq etq-<?= e($cita['estado']) ?>"><?= $estados[$cita['estado']] ?></span>
            <?= $cita['confirmada_cliente_en'] && $activa ? ' · ya confirmaste' : '' ?></strong></li>
    </ul>

    <?php if ($activa): ?>
        <div class="acciones" style="flex-direction:column;align-items:stretch;margin-top:14px">
            <?php if (in_array($cita['estado'], ['reservada', 'confirmada'], true) && !$cita['confirmada_cliente_en']): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="confirmar">
                    <button class="boton boton-ancho" type="submit">✅ Confirmo que voy</button></form>
            <?php endif; ?>
            <?php if ($noCancelar === null): ?>
                <a class="boton boton-claro boton-ancho" href="/?r=reservar&amp;s=<?= e($slugUrl) ?>&amp;cambia=<?= e($token) ?>">Cambiar día u hora</a>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="cancelar">
                    <button class="boton boton-peligro boton-ancho" type="submit" data-confirmar="¿Seguro que quieres cancelar tu cita?">Cancelar mi cita</button></form>
                <?php if ((int) $salon['horas_cancelacion'] > 0): ?>
                    <p class="suave">Puedes cancelar o cambiar hasta <?= (int) $salon['horas_cancelacion'] ?> <?= (int) $salon['horas_cancelacion'] === 1 ? 'hora' : 'horas' ?> antes.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="suave"><?= e($noCancelar) ?></p>
            <?php endif; ?>
            <?php if ($waSalon): ?><a class="boton boton-wa boton-ancho" href="<?= e($waSalon) ?>">Escribir al salón</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($anticipoConf): $ae = $cita['anticipo_estado']; ?>
<section class="bloque" id="anticipo">
    <h2>Anticipo para asegurar tu cita: <?= dinero($cita['anticipo']) ?></h2>
    <?php if ($ae === 'por_revisar'): ?>
        <p class="aviso aviso-ok">✅ Recibimos tu comprobante. El salón lo está revisando.</p>
        <p class="suave">¿Te equivocaste de foto? Puedes subir otra abajo.</p>
    <?php elseif ($ae === 'rechazado'): ?>
        <p class="aviso aviso-error">El salón no pudo validar tu comprobante. Revisa los datos y sube otro, o escríbeles.</p>
    <?php else: ?>
        <p>Paga el anticipo para que el salón confirme tu cita. Se descuenta del valor total el día de tu cita.</p>
    <?php endif; ?>

    <?php if ($payphone): ?>
        <link rel="stylesheet" href="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.css">
        <script type="module" src="https://cdn.payphonetodoesposible.com/box/v1.1/payphone-payment-box.js"></script>
        <div id="pp-button" style="margin:12px 0"></div>
        <script>
            window.addEventListener('load', function () {
                if (typeof PPaymentButtonBox === 'undefined') { document.getElementById('pp-button').textContent = 'No se pudo cargar Payphone. Recarga la página.'; return; }
                new PPaymentButtonBox(<?= json_encode($payphone, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>).render('pp-button');
            });
        </script>
        <a class="boton boton-claro boton-ancho" href="/?r=confirmar&amp;t=<?= e($token) ?>#anticipo">Prefiero transferencia o DeUna</a>
    <?php else: ?>
        <?php if ($anticipoConf['payphone_listo'] && $ae !== 'por_revisar'): ?>
            <a class="boton boton-ancho" href="/?r=confirmar&amp;t=<?= e($token) ?>&amp;pagar=tarjeta#anticipo">💳 Pagar con tarjeta</a>
        <?php endif; ?>
        <?php if ($anticipoConf['transferencia_lista']): ?>
            <h3 style="margin-top:14px">Transferencia o DeUna</h3>
            <?php if ($anticipoConf['anticipo_banco']): ?><p style="white-space:pre-line;background:#F6F8F7;border-radius:8px;padding:10px"><?= e($anticipoConf['anticipo_banco']) ?></p><?php endif; ?>
            <?php if ($anticipoConf['anticipo_deuna']): ?><p>DeUna: <strong><?= e($anticipoConf['anticipo_deuna']) ?></strong></p><?php endif; ?>
            <form method="post" enctype="multipart/form-data"><?= campo_csrf() ?><input type="hidden" name="accion" value="comprobante">
                <div class="campo"><label for="metodo">¿Cómo pagaste?</label>
                    <select id="metodo" name="metodo"><option value="transferencia">Transferencia</option><?php if ($anticipoConf['anticipo_deuna']): ?><option value="deuna">DeUna</option><?php endif; ?></select></div>
                <div class="campo"><label for="comprobante">Foto o captura del comprobante</label>
                    <input type="file" id="comprobante" name="comprobante" accept="image/*" required></div>
                <div class="campo"><label for="referencia">Número de comprobante <small>(opcional)</small></label>
                    <input type="text" id="referencia" name="referencia" maxlength="100"></div>
                <button class="boton boton-ancho" type="submit">Enviar comprobante</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($cita['estado'] === 'atendida'): ?>
    <section class="bloque">
        <?php if ($calificacion): ?>
            <h2>Tu calificación</h2>
            <p class="estrellas grande" aria-label="<?= (int) $calificacion['estrellas'] ?> de 5"><?= str_repeat('★', (int) $calificacion['estrellas']) . str_repeat('☆', 5 - (int) $calificacion['estrellas']) ?></p>
            <p>¡Gracias por contarnos cómo te fue!</p>
            <?php if ($google): ?>
                <p>¿Nos ayudas con una reseña en Google? Le sirve mucho a <?= e($salon['nombre']) ?>.</p>
                <a class="boton boton-ancho" href="<?= e($google) ?>" target="_blank" rel="noopener">⭐ Dejar mi reseña en Google</a>
            <?php endif; ?>
        <?php else: ?>
            <h2>¿Cómo te fue con <?= e($cita['profesional']) ?>?</h2>
            <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="calificar">
                <fieldset class="calificar">
                    <legend class="sr-only">Elige de 1 a 5 estrellas</legend>
                    <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" id="estrella<?= $i ?>" name="estrellas" value="<?= $i ?>" required>
                        <label for="estrella<?= $i ?>" title="<?= $i ?> de 5" aria-label="<?= $i ?> <?= $i === 1 ? 'estrella' : 'estrellas' ?>">★</label>
                    <?php endfor; ?>
                </fieldset>
                <div class="campo"><label for="comentario">Cuéntanos más <small>(opcional)</small></label>
                    <textarea id="comentario" name="comentario" rows="3" maxlength="500"></textarea></div>
                <button class="boton boton-ancho" type="submit">Enviar calificación</button>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="acciones">
    <a class="boton boton-claro" href="/?r=reservar&amp;s=<?= e($slugUrl) ?>">Reservar otra cita</a>
</div>
