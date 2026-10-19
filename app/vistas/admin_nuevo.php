<div class="cabecera">
    <div><h1>Nueva demo</h1>
        <p>Créala por tu cliente. El sistema le genera una contraseña y te la muestra para que se la envíes.</p></div>
</div>
<form method="post" class="bloque" style="max-width:640px">
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($pedido): ?><input type="hidden" name="pedido" value="<?= (int) $pedido['id'] ?>">
        <p class="aviso">Pedido de demo del <?= date('d/m/Y', strtotime($pedido['creado'])) ?><?= $pedido['ciudad'] ? ' · ' . e($pedido['ciudad']) : '' ?><?= $pedido['necesita'] ? ' · «' . e($pedido['necesita']) . '»' : '' ?></p><?php endif; ?>
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
    <fieldset class="campo" style="border:0;padding:0">
        <legend class="etiqueta">Módulos de la demo</legend>
        <label class="interruptor"><input type="checkbox" name="solo" value="1" <?= $todos ? '' : 'checked' ?> data-solo-modulos> Solo los que marque (si no, todos)</label>
        <ul class="modulos" style="margin-top:8px">
        <?php foreach (modulos()->catalogo() as $m): ?>
            <li class="modulo"><label class="interruptor"><input type="checkbox" name="mod[<?= e($m['id']) ?>]" value="1" <?= in_array($m['id'], $elegidos, true) ? 'checked' : '' ?>> <?= e($m['icono'] . ' ' . $m['nombre']) ?></label></li>
        <?php endforeach; ?>
        </ul>
    </fieldset>
    <button class="boton boton-ancho" type="submit">Crear demo</button>
</form>
