<?php use TuSalon\Agenda;
$slugUrl = e(rawurlencode($salon['slug'])); ?>
<header class="publico-cabeza">
    <span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($salon['nombre'], 0, 1))) ?></span>
    <div><h1>Hola, <?= e(explode(' ', trim($cliente['nombre']))[0]) ?></h1><p><?= e($salon['nombre']) ?></p></div>
</header>
<?php if ($avisoCliente): ?><p class="aviso aviso-ok" role="status"><?= e($avisoCliente) ?></p><?php endif; ?>

<div class="acciones" style="margin-bottom:16px">
    <a class="boton" href="/?r=reservar&amp;s=<?= $slugUrl ?>">Reservar una cita</a>
    <a class="boton boton-claro" href="/?r=cliente_salir&amp;s=<?= $slugUrl ?>">Salir</a>
</div>

<section class="bloque">
    <h2>Tus próximas citas</h2>
    <?php if (!$proximas): ?>
        <p class="suave">No tienes citas agendadas.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($proximas as $c): $ini = new DateTimeImmutable($c['inicio']); ?>
                <li><div><div class="principal-linea"><?= Agenda::DIAS[(int) $ini->format('w')] ?> <?= $ini->format('j/n') ?> · <?= $ini->format('H:i') ?></div>
                    <div class="linea-sub"><?= e($c['servicios']) ?> con <?= e($c['profesional']) ?></div></div>
                    <div style="text-align:right">
                        <span class="etq etq-<?= e($c['estado']) ?>"><?= $c['estado'] === 'pendiente' ? 'Por confirmar' : 'Confirmada' ?></span>
                        <div><small><?= dinero($c['valor']) ?></small></div>
                        <a class="enlace-chico" href="/?r=confirmar&amp;t=<?= e($c['token']) ?>">Confirmar, cambiar o cancelar</a></div></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Tu historial</h2>
    <?php if (!$historial): ?>
        <p class="suave">Aquí verás cada servicio que te hagas, con el peluquero que te atendió<?= $cliente['acepta_fotos'] ? ' y las fotos' : '' ?>.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($historial as $h): $ini = new DateTimeImmutable($h['inicio']); ?>
                <li style="display:block">
                    <div style="display:flex;justify-content:space-between;gap:12px">
                        <div><div class="principal-linea"><?= e($h['servicios']) ?></div>
                            <div class="linea-sub"><?= $ini->format('d/m/Y') ?> · con <?= e($h['profesional']) ?></div></div>
                        <div style="text-align:right"><span class="monto"><?= dinero($h['valor']) ?></span>
                            <?php if ($h['estrellas']): ?><div class="estrellas" aria-label="<?= (int) $h['estrellas'] ?> de 5"><?= str_repeat('★', (int) $h['estrellas']) ?></div>
                            <?php elseif ($h['token']): ?><div><a class="enlace-chico" href="/?r=confirmar&amp;t=<?= e($h['token']) ?>">⭐ Calificar</a></div><?php endif; ?></div>
                    </div>
                    <?php if ($h['fotos']): ?>
                        <div class="galeria">
                            <?php foreach ($h['fotos'] as $f): ?>
                                <figure><a href="/?r=foto&amp;id=<?= (int) $f['id'] ?>" target="_blank" rel="noopener">
                                    <img src="/?r=foto&amp;id=<?= (int) $f['id'] ?>" alt="Foto <?= $f['momento'] === 'antes' ? 'antes' : 'después' ?> del servicio" loading="lazy"></a>
                                    <figcaption><?= $f['momento'] === 'antes' ? 'Antes' : 'Después' ?></figcaption></figure>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?php if ($tgDisponible): ?>
<section class="bloque">
    <h2>Avisos por Telegram</h2>
    <?php if ($cliente['telegram_chat_id']): ?>
        <p>Tu Telegram está conectado: te llega la confirmación con el valor y un recordatorio el día anterior para confirmar con un toque.</p>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="tg_desconectar">
            <button class="boton boton-claro boton-chico" type="submit">Dejar de recibir avisos</button></form>
    <?php elseif ($tgEnlace): ?>
        <p>Toca el botón, se abrirá Telegram y presiona <strong>Iniciar</strong>.</p>
        <a class="boton" href="<?= e($tgEnlace) ?>" target="_blank" rel="noopener">Abrir Telegram</a>
    <?php else: ?>
        <p>Recibe la confirmación de tus citas y un recordatorio el día anterior.</p>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="tg_conectar">
            <button class="boton" type="submit">Conectar mi Telegram</button></form>
    <?php endif; ?>
</section>
<?php endif; ?>

<form method="post" class="bloque">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="cumple">
    <h2>Tu cumpleaños <small class="suave">(opcional)</small></h2>
    <div class="fila-cumple">
        <select name="cumple_dia" aria-label="Día"><option value="">Día</option>
            <?php for ($i = 1; $i <= 31; $i++): ?><option value="<?= $i ?>" <?= (int) $cliente['cumple_dia'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
        <select name="cumple_mes" aria-label="Mes"><option value="">Mes</option>
            <?php foreach (['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'] as $i => $m): ?>
                <option value="<?= $i + 1 ?>" <?= (int) $cliente['cumple_mes'] === $i + 1 ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
        <button class="boton boton-chico" type="submit">Guardar</button>
    </div>
    <small class="suave">Solo el día y el mes, para saludarte. 🎂</small>
</form>

<form method="post" class="bloque">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="fotos">
    <h2>Fotos de tus servicios</h2>
    <label class="opcion"><input type="checkbox" name="acepta_fotos" value="1" <?= $cliente['acepta_fotos'] ? 'checked' : '' ?> onchange="this.form.submit()">
        <span><strong>El salón puede guardar fotos de mis cortes</strong><small>Solo las ven tú y el salón.</small></span></label>
    <noscript><button class="boton boton-chico" type="submit" style="margin-top:8px">Guardar</button></noscript>
</form>
