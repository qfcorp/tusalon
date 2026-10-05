<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Ts</span>
    <div>
        <h1>Prueba TuSalón gratis</h1>
        <p>7 días, sin tarjeta</p>
    </div>
</div>

<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <?php if ($errores): ?>
        <div class="aviso aviso-error" role="alert">
            <?php foreach ($errores as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="campo">
        <label for="salon">Nombre de tu salón o barbería</label>
        <input type="text" id="salon" name="salon" value="<?= e($d['salon']) ?>" required autofocus>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="nombre">Tu nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" autocomplete="name" required>
        </div>
        <div class="campo">
            <label for="telefono">Tu celular</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567" autocomplete="tel">
        </div>
    </div>
    <div class="campo">
        <label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" autocomplete="email" required>
    </div>
    <div class="campo">
        <label for="clave">Contraseña <small>(mínimo 8 caracteres)</small></label>
        <input type="password" id="clave" name="clave" autocomplete="new-password" minlength="8" required>
    </div>
    <fieldset class="campo" style="border:0;padding:0;margin:0 0 16px">
        <legend class="etiqueta" style="margin-bottom:6px">¿Qué plan quieres probar?</legend>
        <div class="opciones">
            <label class="opcion">
                <input type="radio" name="plan" value="completa" <?= $d['plan'] === 'completa' ? 'checked' : '' ?>>
                <span><strong>Completa · $35 al mes</strong>
                    <small>Todo: reservas en línea, WhatsApp automático, comisiones, arriendo de sillas, gastos.</small></span>
            </label>
            <label class="opcion">
                <input type="radio" name="plan" value="basica" <?= $d['plan'] === 'basica' ? 'checked' : '' ?>>
                <span><strong>Básica · $25 al mes</strong>
                    <small>Agenda, clientes y caja, hasta 3 personas.</small></span>
            </label>
        </div>
    </fieldset>
    <button class="boton boton-ancho" type="submit">Crear mi salón</button>
</form>
<p>¿Ya tienes cuenta? <a href="<?= e(url('login')) ?>">Entrar</a></p>
