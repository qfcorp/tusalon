<div class="acceso-marca">
    <span class="marca-sello" aria-hidden="true">Tk</span>
    <div><h1>Panel Tukán</h1><p>Administración de TuSalón</p></div>
</div>
<form method="post" class="bloque acceso-caja">
    <?= campo_csrf() ?>
    <h2>Entrar</h2>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus></div>
    <div class="campo"><label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required></div>
    <button class="boton boton-ancho" type="submit">Entrar al panel</button>
</form>
