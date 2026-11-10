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
    <p data-gracias><?= e(TukanDemos::GRACIAS) ?></p>
    <?php if (!en_app_play()): ?>
    <p><a class="boton boton-wa" href="https://wa.me/<?= e(whatsapp_ventas()) ?>?text=<?= rawurlencode('Hola, acabo de pedir una demo de TuSalón.') ?>" target="_blank" rel="noopener">Escribir ahora por WhatsApp</a></p>
    <?php endif; ?>
</section>
<p>¿Ya tienes cuenta? <a href="<?= e(url('login')) ?>">Entrar</a></p>
<?php return; endif; ?>

<form method="post" class="bloque">
    <?= campo_csrf() ?>
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert">
            <?php foreach ($errores as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <p class="suave" data-demo-dias><strong><?= e(TukanDemos::AVISO_DIAS) ?></strong> Todos los datos son obligatorios.</p>
    <div class="fila-campos">
        <div class="campo">
            <label for="nombre">Tu nombre y apellido</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" autocomplete="name" maxlength="80" autofocus required<?= isset($porCampo['nombre']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['nombre'])): ?><small class="error-campo" data-error="nombre" style="color:#b42318;display:block"><?= e($porCampo['nombre']) ?></small><?php endif; ?>
        </div>
        <div class="campo">
            <label for="nombre_comercial">Nombre de tu salón o barbería</label>
            <input type="text" id="nombre_comercial" name="nombre_comercial" value="<?= e($d['nombre_comercial']) ?>" autocomplete="organization" maxlength="120" required<?= isset($porCampo['nombre_comercial']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['nombre_comercial'])): ?><small class="error-campo" data-error="nombre_comercial" style="color:#b42318;display:block"><?= e($porCampo['nombre_comercial']) ?></small><?php endif; ?>
        </div>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="telefono">Teléfono de contacto</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="022345678" autocomplete="tel" maxlength="20" pattern="[0-9+ ()\-]{7,20}" required<?= isset($porCampo['telefono']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['telefono'])): ?><small class="error-campo" data-error="telefono" style="color:#b42318;display:block"><?= e($porCampo['telefono']) ?></small><?php endif; ?>
        </div>
        <div class="campo">
            <label for="celular">Celular</label>
            <input type="tel" id="celular" name="celular" value="<?= e($d['celular']) ?>" placeholder="0991234567" autocomplete="mobile tel" maxlength="20" pattern="[0-9+ ()\-]{9,20}" required<?= isset($porCampo['celular']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['celular'])): ?><small class="error-campo" data-error="celular" style="color:#b42318;display:block"><?= e($porCampo['celular']) ?></small><?php endif; ?>
        </div>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="direccion">Dirección</label>
            <input type="text" id="direccion" name="direccion" value="<?= e($d['direccion']) ?>" autocomplete="street-address" maxlength="200" required<?= isset($porCampo['direccion']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['direccion'])): ?><small class="error-campo" data-error="direccion" style="color:#b42318;display:block"><?= e($porCampo['direccion']) ?></small><?php endif; ?>
        </div>
        <div class="campo">
            <label for="ciudad">Ciudad</label>
            <input type="text" id="ciudad" name="ciudad" value="<?= e($d['ciudad']) ?>" autocomplete="address-level2" maxlength="80" required<?= isset($porCampo['ciudad']) ? ' aria-invalid="true"' : '' ?>>
            <?php if (isset($porCampo['ciudad'])): ?><small class="error-campo" data-error="ciudad" style="color:#b42318;display:block"><?= e($porCampo['ciudad']) ?></small><?php endif; ?>
        </div>
    </div>
    <div class="campo">
            <label for="email">Correo</label>
            <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email" maxlength="120" required<?= isset($porCampo['email']) ? ' aria-invalid="true"' : '' ?>>
            <small class="suave" data-aviso-correo><?= e(TukanDemos::AVISO_CORREO) ?></small>
            <?php if (isset($porCampo['email'])): ?><small class="error-campo" data-error="email" style="color:#b42318;display:block"><?= e($porCampo['email']) ?></small><?php endif; ?>
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
