<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Ts</span>
    <div>
        <h1>Solicita tu demo de TuSalón</h1>
        <p>Te preparamos tu salón con lo que necesitas y te lo mostramos</p>
    </div>
</div>

<?php if ($enviado): ?>
<section class="bloque" role="status">
    <h2>¡Gracias! Recibimos tu pedido</h2>
    <p>Te escribimos por WhatsApp para preparar tu demo con los módulos que necesitas.</p>
    <?php if (!en_app_play()): ?>
    <p><a class="boton boton-wa" href="https://wa.me/<?= e(whatsapp_ventas()) ?>?text=<?= rawurlencode('Hola, acabo de pedir una demo de TuSalón.') ?>" target="_blank" rel="noopener">Escribir ahora por WhatsApp</a></p>
    <?php endif; ?>
</section>
<p>¿Ya tienes cuenta? <a href="<?= e(url('login')) ?>">Entrar</a></p>
<?php return; endif; ?>

<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert">
            <?php foreach ($errores as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="fila-campos">
        <div class="campo">
            <label for="nombre">Tu nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" autocomplete="name" required autofocus>
        </div>
        <div class="campo">
            <label for="salon">Nombre de tu salón o barbería</label>
            <input type="text" id="salon" name="salon" value="<?= e($d['salon']) ?>" required>
        </div>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="ciudad">Ciudad</label>
            <input type="text" id="ciudad" name="ciudad" value="<?= e($d['ciudad']) ?>" autocomplete="address-level2" required>
        </div>
        <div class="campo">
            <label for="whatsapp">WhatsApp</label>
            <input type="tel" id="whatsapp" name="whatsapp" value="<?= e($d['whatsapp']) ?>" placeholder="0991234567" autocomplete="tel" required>
        </div>
    </div>
    <div class="campo">
        <label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email" required>
    </div>
    <div class="campo">
        <label for="necesita">¿Qué necesitas? <small>(opcional)</small></label>
        <textarea id="necesita" name="necesita" rows="3" maxlength="600" placeholder="Ej.: somos 4 peluqueros, uno alquila el puesto, queremos reservas en línea"><?= e($d['necesita']) ?></textarea>
    </div>
    <fieldset class="campo" style="border:0;padding:0;margin:0 0 16px">
        <legend class="etiqueta" style="margin-bottom:6px">¿Qué módulos te interesan? <small>(opcional)</small></legend>
        <p class="suave" style="margin:0 0 8px;font-size:.9rem">Todos traen agenda, clientes, servicios, cobros, caja y las cuentas de tu equipo.</p>
        <div class="opciones">
            <?php foreach (modulos()->catalogo() as $m): ?>
            <label class="opcion">
                <input type="checkbox" name="modulos[]" value="<?= e($m['id']) ?>" <?= in_array($m['id'], $d['modulos'], true) ? 'checked' : '' ?>>
                <span><strong><?= e($m['icono'] . ' ' . $m['nombre']) ?></strong><small><?= e($m['descripcion']) ?></small></span>
            </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="campo" style="position:absolute;left:-9999px" aria-hidden="true">
        <label for="sitio_web">No llenar</label><input type="text" id="sitio_web" name="sitio_web" tabindex="-1" autocomplete="off">
    </div>
    <button class="boton boton-ancho" type="submit">Solicitar demo</button>
</form>
<p>¿Ya tienes cuenta? <a href="<?= e(url('login')) ?>">Entrar</a></p>
