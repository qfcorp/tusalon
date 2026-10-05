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
            <?php if ($clienteSesion): ?><a class="boton boton-claro" href="/?r=mi_cuenta&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">Ver mi cuenta</a><?php endif; ?>
        </div>
    </section>
<?php else: ?>

<form method="post" class="reserva" id="form-reserva" data-salon="<?= e($salon['slug']) ?>" novalidate>
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($citaVieja): $vi = new DateTimeImmutable($citaVieja['inicio']); ?>
        <p class="aviso" role="status">Estás cambiando tu cita del <strong><?= $vi->format('d/m') ?> a las <?= $vi->format('H:i') ?></strong>.
            Elige la nueva hora; la anterior se libera cuando reserves la nueva.</p>
    <?php endif; ?>

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
        <label class="etiqueta" for="servicio">Servicio</label>
        <select id="servicio" name="servicio" required class="select-servicio">
            <option value="">Elige un servicio…</option>
            <?php foreach ($servicios as $s): ?>
                <option value="<?= $s['id'] ?>" data-min="<?= (int) $s['duracion_minutos'] ?>" data-precio="<?= e($s['precio']) ?>"
                        data-desc="<?= e($s['descripcion'] ?? '') ?>" <?= $d['servicio'] === (int) $s['id'] ? 'selected' : '' ?>>
                    <?= e($s['nombre']) ?> · <?= (int) $s['duracion_minutos'] ?> min · <?= dinero($s['precio']) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="suave ayuda-servicio">Deja el mouse sobre un servicio para ver qué incluye y cuánto dura. En el celular toca <b>ⓘ</b>.</p>
        <div class="servicio-elegido" id="servicio-elegido" hidden></div>
        <script type="application/json" id="datos-reserva"><?= json_encode([
            'porProfesional' => (object) array_map(fn($m) => (object) $m, $porProfesional),
            'dias' => (object) $diasProfesional,
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    </fieldset>

    <fieldset class="bloque paso">
        <legend><span class="paso-num">3</span> ¿Qué día?</legend>
        <div class="dias" role="radiogroup">
            <?php for ($i = 0; $i < 21; $i++):
                $f = new DateTimeImmutable("+$i days");
                $abierto = isset($horario[(int) $f->format('w')]);
                $valor = $f->format('Y-m-d'); ?>
                <label class="dia <?= $abierto ? '' : 'cerrado' ?>" data-dow="<?= (int) $f->format('w') ?>">
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
        <?php if ($clienteSesion): ?>
            <p>Reservas con tu cuenta: <strong><?= e($clienteSesion['nombre']) ?></strong> · <?= e($clienteSesion['telefono']) ?></p>
            <p class="suave"><a href="/?r=mi_cuenta&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">Ver mi historial</a> ·
               <a href="/?r=cliente_salir&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">No soy yo</a></p>
        <?php else: ?>
        <div class="modos" role="radiogroup" aria-label="Cómo quieres reservar">
            <label class="modo"><input type="radio" name="modo" value="invitado" <?= $d['modo'] !== 'cuenta' ? 'checked' : '' ?>>
                <span><strong>Como invitado</strong><small>Solo nombre y celular</small></span></label>
            <label class="modo"><input type="radio" name="modo" value="cuenta" <?= $d['modo'] === 'cuenta' ? 'checked' : '' ?>>
                <span><strong>Crear mi cuenta</strong><small>Guarda tu historial</small></span></label>
        </div>
        <p class="suave" style="margin:8px 0 14px">¿Ya tienes cuenta? <a href="/?r=cliente_entrar&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">Entra aquí</a></p>
        <div class="campo"><label for="nombre">Tu nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" autocomplete="name" required></div>
        <div class="campo"><label for="telefono">Tu celular</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567" autocomplete="tel" required>
            <small>Te escribiremos por WhatsApp para recordarte la cita.</small></div>
        <div data-modo="cuenta">
            <div class="campo"><label for="email">Tu correo</label>
                <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email"></div>
            <div class="campo"><label for="clave">Crea una contraseña <small>(mínimo 8 caracteres)</small></label>
                <input type="password" id="clave" name="clave" autocomplete="new-password" minlength="8"></div>
            <fieldset class="campo cumple">
                <legend>Tu cumpleaños <small>(opcional, sin el año)</small></legend>
                <div class="fila-cumple">
                    <select name="cumple_dia" aria-label="Día de tu cumpleaños">
                        <option value="">Día</option>
                        <?php for ($i = 1; $i <= 31; $i++): ?><option value="<?= $i ?>" <?= $d['cumple_dia'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?>
                    </select>
                    <select name="cumple_mes" aria-label="Mes de tu cumpleaños">
                        <option value="">Mes</option>
                        <?php foreach (['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'] as $i => $m): ?>
                            <option value="<?= $i + 1 ?>" <?= $d['cumple_mes'] === $i + 1 ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small>Para saludarte en tu día. 🎂</small>
            </fieldset>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="acepta_fotos" value="1" <?= $d['acepta_fotos'] ? 'checked' : '' ?>>
                <span><strong>El salón puede guardar fotos de mis cortes</strong><small>Las verás solo tú y el salón, en tu historial. Puedes cambiarlo cuando quieras.</small></span></label>
        </div>
        <?php endif; ?>
        <div class="trampa" aria-hidden="true"><label for="sitio_web">No llenar</label><input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off"></div>
        <button class="boton boton-ancho" type="submit" id="btn-reservar">Reservar cita</button>
    </fieldset>
</form>
<?php endif; ?>
