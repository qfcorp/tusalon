<header class="publico-cabeza">
    <span class="marca-sello" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($salon['nombre'], 0, 1))) ?></span>
    <div><h1><?= e($salon['nombre']) ?></h1><p>Entra a tu cuenta</p></div>
</header>
<form method="post" class="bloque" novalidate>
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="email">Correo</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required autofocus></div>
    <div class="campo"><label for="clave">Contraseña</label>
        <input type="password" id="clave" name="clave" autocomplete="current-password" required></div>
    <button class="boton boton-ancho" type="submit">Entrar</button>
</form>
<p>¿No tienes cuenta? Créala al <a href="/?r=reservar&amp;s=<?= e(rawurlencode($salon['slug'])) ?>">reservar tu próxima cita</a>.</p>
