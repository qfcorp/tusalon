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
                    <span class="etq etq-<?= e($c['estado']) ?>"><?= $c['estado'] === 'pendiente' ? 'Por confirmar' : 'Confirmada' ?></span></li>
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
                        <span class="monto"><?= dinero($h['valor']) ?></span>
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

<form method="post" class="bloque">
    <?= campo_csrf() ?>
    <h2>Fotos de tus servicios</h2>
    <label class="opcion"><input type="checkbox" name="acepta_fotos" value="1" <?= $cliente['acepta_fotos'] ? 'checked' : '' ?> onchange="this.form.submit()">
        <span><strong>El salón puede guardar fotos de mis cortes</strong><small>Solo las ven tú y el salón.</small></span></label>
    <noscript><button class="boton boton-chico" type="submit" style="margin-top:8px">Guardar</button></noscript>
</form>
