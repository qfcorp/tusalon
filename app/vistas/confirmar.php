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
