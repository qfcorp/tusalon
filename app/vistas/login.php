<div class="tk-saludo">
    <h1>✂️ TuSalón</h1>
    <p>La agenda y la caja de tu peluquería</p>
</div>

<form method="post" class="tk-tarjeta bloque" novalidate>
    <?= campo_csrf() ?>
    <h2>Entrar</h2>
    <?php if ($error): ?><p class="tk-aviso mal aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="tk-campo campo">
        <label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus>
    </div>
    <div class="tk-campo campo">
        <label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required>
    </div>
    <button class="tk-boton boton boton-ancho" type="submit">Entrar</button>
</form>
<p>¿Todavía no tienes cuenta? <a href="<?= e(url('registro')) ?>">Solicitar demo</a></p>
<p><small><a href="https://tukansoftware.com/terminos.html" target="_blank" rel="noopener">Términos y condiciones</a> · <a href="<?= e(url('privacidad')) ?>">Política de privacidad</a></small></p>
