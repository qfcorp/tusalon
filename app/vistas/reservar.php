<?php
use TuSalon\{Agenda, WhatsApp};
$diasCortos = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
?>
<header class="publico-cabeza">
    <span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($salon['nombre'], 0, 1))) ?></span>
    <div>
        <h1><?= e($salon['nombre']) ?></h1>
        <p>Reserva tu cita en línea</p>
    </div>
</header>

<?php if ($confirmada):
    $ini = new DateTimeImmutable($confirmada['inicio']);
    $waSalon = $salon['telefono'] ? WhatsApp::enlace($salon['telefono'],
        'Hola, reservé una cita para el ' . $ini->format('d/m') . ' a las ' . $ini->format('H:i') . ' con ' . $confirmada['profesional'] . '.') : null;
?>
    <section class="bloque confirmacion" role="status">
        <?php if ($confirmada['estado'] === 'pendiente'): ?>
            <h2>¡Solicitud enviada!</h2>
            <p>El salón la está confirmando y te avisará por WhatsApp. Mientras tanto, esta hora queda apartada para ti.</p>
        <?php else: ?>
            <h2>¡Tu cita está reservada!</h2>
        <?php endif; ?>
        <ul class="lista">
            <li><span>Día</span><strong><?= Agenda::DIAS[(int) $ini->format('w')] ?> <?= (int) $ini->format('j') ?> de <?= $meses[(int) $ini->format('n')] ?></strong></li>
            <li><span>Hora</span><strong><?= $ini->format('H:i') ?></strong></li>
            <li><span>Con</span><strong><?= e($confirmada['profesional']) ?></strong></li>
            <li><span>Servicio</span><strong><?= e(implode(' + ', array_column($confirmada['lista_servicios'], 'nombre'))) ?></strong></li>
            <li><span>Valor</span><strong><?= dinero(array_sum(array_column($confirmada['lista_servicios'], 'precio'))) ?></strong></li>
        </ul>
        <p class="suave">Si no puedes venir, avisa al salón con tiempo.</p>
        <div class="acciones">
            <?php if ($waSalon): ?><a class="boton boton-wa" href="<?= e($waSalon) ?>">Escribir al salón</a><?php endif; ?>
            <a class="boton boton-claro" href="/?r=reservar&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">Reservar otra cita</a>
        </div>
    </section>
<?php else: ?>

<form method="post" class="reserva" id="form-reserva" data-salon="<?= e($salon['slug']) ?>" novalidate>
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">1</span> ¿Con quién?</legend>
        <div class="tarjetas">
            <?php foreach ($profesionales as $p): ?>
                <label class="tarjeta">
                    <input type="radio" name="profesional" value="<?= $p['id'] ?>" <?= $d['profesional'] === (int) $p['id'] ? 'checked' : '' ?> required>
                    <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($p['nombre'], 0, 1))) ?></span>
                    <span><?= e($p['nombre']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">2</span> ¿Qué te vas a hacer?</legend>
        <div class="opciones">
            <?php foreach ($servicios as $s): ?>
                <label class="opcion">
                    <input type="radio" name="servicio" value="<?= $s['id'] ?>" data-min="<?= (int) $s['duracion_minutos'] ?>" <?= $d['servicio'] === (int) $s['id'] ? 'checked' : '' ?> required>
                    <span style="flex:1"><strong><?= e($s['nombre']) ?></strong><small><?= (int) $s['duracion_minutos'] ?> min</small></span>
                    <strong><?= dinero($s['precio']) ?></strong>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">3</span> ¿Qué día?</legend>
        <div class="dias" role="radiogroup">
            <?php for ($i = 0; $i < 21; $i++):
                $f = new DateTimeImmutable("+$i days");
                $abierto = isset($horario[(int) $f->format('w')]);
                $valor = $f->format('Y-m-d'); ?>
                <label class="dia <?= $abierto ? '' : 'cerrado' ?>">
                    <input type="radio" name="fecha" value="<?= $valor ?>" <?= $abierto ? '' : 'disabled' ?> <?= $d['fecha'] === $valor && $abierto ? 'checked' : '' ?>>
                    <span class="dia-sem"><?= $i === 0 ? 'Hoy' : ($i === 1 ? 'Mañana' : $diasCortos[(int) $f->format('w')]) ?></span>
                    <span class="dia-num"><?= (int) $f->format('j') ?></span>
                    <span class="dia-mes"><?= $abierto ? $meses[(int) $f->format('n')] : 'cerrado' ?></span>
                </label>
            <?php endfor; ?>
        </div>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">4</span> ¿A qué hora?</legend>
        <p class="suave" id="horas-estado" aria-live="polite">Elige peluquero, servicio y día para ver las horas libres.</p>
        <div class="horas-libres" id="horas" data-elegida="<?= e($d['hora']) ?>"></div>
        <noscript><p>Activa JavaScript en tu navegador para ver las horas libres.</p></noscript>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">5</span> Tus datos</legend>
        <div class="campo"><label for="nombre">Tu nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" autocomplete="name" required></div>
        <div class="campo"><label for="telefono">Tu celular</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567" autocomplete="tel" required>
            <small>Te escribiremos por WhatsApp para recordarte la cita.</small></div>
        <div class="trampa" aria-hidden="true"><label for="sitio_web">No llenar</label><input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off"></div>
        <button class="boton boton-ancho" type="submit" id="btn-reservar">Reservar cita</button>
    </fieldset>
</form>
<?php endif; ?>
