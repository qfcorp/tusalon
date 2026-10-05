<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Ts</span>
    <div>
        <h1>TuSalón</h1>
        <p>La agenda y la caja de tu peluquería</p>
    </div>
</div>

<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <h2>Entrar</h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo">
        <label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
    </div>
    <div class="campo">
        <label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required>
    </div>
    <button class="boton boton-ancho" type="submit">Entrar</button>
</form>
<p>¿Todavía no tienes cuenta? <a href="<?= e(url('registro')) ?>">Prueba TuSalón <?= (int) planes()->ajuste('dias_prueba') ?> días gratis</a></p>
