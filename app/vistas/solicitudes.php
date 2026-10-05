<?php
use TuSalon\Agenda;
?>
<div class="cabecera">
    <div>
        <h1>Solicitudes de cita</h1>
        <p>Reservas hechas por clientes en línea que esperan respuesta.</p>
    </div>
</div>

<?php if ($waCliente): ?>
    <section class="bloque" style="border-color:#1F8A4C">
        <p style="margin-bottom:10px">Avísale al cliente con un toque:</p>
        <a class="boton boton-wa" href="<?= e($waCliente) ?>" target="_blank" rel="noopener">Enviar WhatsApp al cliente</a>
    </section>
<?php endif; ?>

<section class="bloque">
    <h2>Por responder (<?= count($pendientes) ?>)</h2>
    <?php if (!$pendientes): ?>
        <p class="vacio">No hay solicitudes pendientes.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($pendientes as $c):
                $ini = new DateTimeImmutable($c['inicio']);
                $vence = $c['expira_en'] ? new DateTimeImmutable($c['expira_en']) : null;
                $puede = Agenda::puedeResponder($c['acepta_reservas'], $u, (int) $c['profesional_id']); ?>
                <li style="flex-wrap:wrap">
                    <div style="flex:1;min-width:220px">
                        <div class="principal-linea"><?= e($c['cliente']) ?> · <?= e($c['servicios']) ?></div>
                        <div class="linea-sub">
                            <?= Agenda::DIAS[(int) $ini->format('w')] ?> <?= $ini->format('j/n') ?> a las <?= $ini->format('H:i') ?>
                            · con <?= e($c['profesional']) ?> · <?= dinero($c['precio']) ?>
                        </div>
                        <?php if ($vence): ?>
                            <div class="linea-sub"><?= $vence < new DateTimeImmutable()
                                ? 'Venció: la hora ya se muestra libre a otros clientes.'
                                : 'Si no respondes, la hora se libera a las ' . $vence->format('H:i') . '.' ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($puede): ?>
                        <div class="acciones acciones-solicitud">
                            <form method="post" class="form-aceptar"><?= campo_csrf() ?><input type="hidden" name="cita" value="<?= $c['id'] ?>">
                                <?php foreach ($c['lista_servicios'] as $sv): ?>
                                    <label class="valor-aceptar"><span>Valor a cobrar<?= count($c['lista_servicios']) > 1 ? ' · ' . e($sv['nombre']) : '' ?></span>
                                        <span class="con-signo">$<input type="number" name="precio[<?= (int) $sv['id'] ?>]" value="<?= e(number_format((float) $sv['precio'], 2, '.', '')) ?>"
                                               step="0.01" min="0" inputmode="decimal" aria-label="Valor de <?= e($sv['nombre']) ?>"></span></label>
                                <?php endforeach; ?>
                                <button class="boton boton-chico" name="accion" value="aceptar" type="submit">Aceptar</button>
                                <small>Al cliente le llega este valor en la confirmación.</small></form>
                            <form method="post"><?= campo_csrf() ?><input type="hidden" name="cita" value="<?= $c['id'] ?>">
                                <button class="boton boton-peligro boton-chico" name="accion" value="rechazar" type="submit"
                                        data-confirmar="¿Rechazar la solicitud de <?= e($c['cliente']) ?>?">Rechazar</button></form>
                        </div>
                    <?php else: ?>
                        <small>La acepta <?= $c['acepta_reservas'] === 'dueno' ? 'el dueño' : 'el peluquero' ?></small>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Avisos por Telegram</h2>
    <?php if (!$tgDisponible): ?>
        <p class="suave">Los avisos por Telegram se activan cuando el sistema tenga su bot configurado.</p>
    <?php elseif ($tgConectado): ?>
        <p>Tu Telegram está conectado. Las solicitudes te llegan con botones para aceptar o rechazar.</p>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="tg_desconectar">
            <button class="boton boton-claro boton-chico" type="submit">Dejar de recibir avisos</button></form>
    <?php elseif ($tgEnlace): ?>
        <p>Toca el botón, se abrirá Telegram y presiona <strong>Iniciar</strong>. Listo.</p>
        <a class="boton" href="<?= e($tgEnlace) ?>" target="_blank" rel="noopener">Abrir Telegram</a>
    <?php else: ?>
        <p>Recibe cada solicitud de cita en tu Telegram y acéptala desde ahí.</p>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="tg_conectar">
            <button class="boton" type="submit">Conectar mi Telegram</button></form>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Avisos recientes</h2>
    <?php if (!$avisos): ?>
        <p class="suave">Aún no tienes avisos.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($avisos as $a): ?>
                <li><span style="<?= $a['leida'] ? 'color:var(--tinta-suave)' : 'font-weight:600' ?>"><?= e($a['texto']) ?></span>
                    <small style="white-space:nowrap"><?= (new DateTimeImmutable($a['creada_en']))->format('d/m H:i') ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
