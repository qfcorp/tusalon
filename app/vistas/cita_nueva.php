<div class="cabecera">
    <h1>Nueva cita</h1>
    <a class="boton boton-claro" href="<?= e(url('agenda', ['fecha' => $d['fecha']])) ?>">Volver a la agenda</a>
</div>

<form method="post" class="bloque" style="max-width:640px">
    <?= campo_csrf() ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="fila-campos">
        <div class="campo">
            <label for="fecha">Día</label>
            <input type="date" id="fecha" name="fecha" value="<?= e($d['fecha']) ?>" required>
        </div>
        <div class="campo">
            <label for="hora">Hora</label>
            <input type="time" id="hora" name="hora" value="<?= e($d['hora']) ?>" step="900" required>
        </div>
    </div>

    <div class="campo">
        <label for="profesional">Con quién</label>
        <select id="profesional" name="profesional" required>
            <option value="">Elegir…</option>
            <?php foreach ($profesionales as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $d['profesional'] === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="campo">
        <label for="cliente_id">Cliente</label>
        <select id="cliente_id" name="cliente_id">
            <option value="0">Cliente nuevo o sin nombre</option>
            <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $d['cliente_id'] === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['nombre']) ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fila-campos">
        <div class="campo">
            <label for="cliente_nuevo">Nombre del cliente nuevo <small>(opcional)</small></label>
            <input type="text" id="cliente_nuevo" name="cliente_nuevo" value="<?= e($d['cliente_nuevo']) ?>">
        </div>
        <div class="campo">
            <label for="telefono">Celular <small>(para el WhatsApp)</small></label>
            <input type="tel" id="telefono" name="telefono" value="<?= e($d['telefono']) ?>" placeholder="0991234567">
        </div>
    </div>

    <fieldset class="campo" style="border:0;padding:0">
        <legend class="etiqueta" style="margin-bottom:6px">Servicios <small>(puedes cambiar el precio para este cliente)</small></legend>
        <?php if (!$servicios): ?>
            <p>Primero agrega tus servicios en <a href="<?= e(url('servicios')) ?>">Servicios y precios</a>.</p>
        <?php endif; ?>
        <div class="opciones">
            <?php foreach ($servicios as $s):
                $precio = $d['precios'][$s['id']] ?? number_format((float) $s['precio'], 2, '.', ''); ?>
                <div class="opcion">
                    <input type="checkbox" id="sv<?= $s['id'] ?>" name="servicios[]" value="<?= $s['id'] ?>" <?= in_array((int) $s['id'], $d['servicios'], true) ? 'checked' : '' ?>>
                    <label for="sv<?= $s['id'] ?>" style="flex:1"><strong><?= e($s['nombre']) ?></strong>
                        <small style="display:block"><?= (int) $s['duracion_minutos'] ?> min · precio normal <?= dinero($s['precio']) ?></small></label>
                    <input type="number" name="precio[<?= $s['id'] ?>]" value="<?= e($precio) ?>" step="0.01" min="0"
                           style="width:110px" aria-label="Precio de <?= e($s['nombre']) ?> para este cliente">
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="campo">
        <label for="notas">Notas <small>(opcional)</small></label>
        <textarea id="notas" name="notas" rows="2"><?= e($d['notas']) ?></textarea>
    </div>
    <button class="boton boton-ancho" type="submit">Agendar cita</button>
</form>
