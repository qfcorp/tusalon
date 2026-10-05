<div class="cabecera">
    <div><h1>Nuevo salón</h1>
        <p>Créalo por tu cliente. El sistema le genera una contraseña y te la muestra para que se la envíes.</p></div>
</div>
<form method="post" class="bloque" style="max-width:640px">
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <div class="campo"><label for="salon">Nombre del salón o barbería</label>
        <input type="text" id="salon" name="salon" value="<?= e($d['salon']) ?>" required></div>
    <div class="fila-campos">
        <div class="campo"><label for="nombre">Nombre del dueño</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($d['nombre']) ?>" required></div>
        <div class="campo"><label for="telefono">Celular del dueño</label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567"></div>
    </div>
    <div class="campo"><label for="email">Correo del dueño <small>(con este entra)</small></label>
        <input type="email" id="email" name="email" value="<?= e($d['email']) ?>" required></div>
    <div class="campo"><label for="plan">Plan</label>
        <select id="plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $d['plan'] === $p['codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= dinero($p['precio_mensual']) ?> al mes</option><?php endforeach; ?></select></div>
    <button class="boton boton-ancho" type="submit">Crear salón</button>
</form>
