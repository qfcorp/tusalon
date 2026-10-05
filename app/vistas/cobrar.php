<?php
$metodos = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'tarjeta' => 'Tarjeta',
            'payphone' => 'Payphone', 'plux' => 'Plux'];
$tipos = ['dueno' => 'dueño', 'empleado' => 'empleado', 'porcentaje' => 'porcentaje', 'alquiler' => 'alquila'];
$cobraSugerido = 'local';
foreach ($profesionales as $p) {
    if ((int) $p['id'] === $profElegido) $cobraSugerido = $p['cobra'];
}
?>
<div class="cabecera">
    <div>
        <h1>Cobrar</h1>
        <?php if ($cita): ?><p>Cita de <?= e($cita['cliente'] ?: 'cliente sin nombre') ?> a las <?= (new DateTimeImmutable($cita['inicio']))->format('H:i') ?></p><?php endif; ?>
    </div>
    <a class="boton boton-claro" href="<?= e($cita ? url('cita', ['id' => $cita['id']]) : url('caja')) ?>">Volver</a>
</div>

<form method="post" id="form-cobro" class="bloque" style="max-width:640px">
    <?= campo_csrf() ?>
    <?php if ($cita): ?><input type="hidden" name="cita" value="<?= (int) $cita['id'] ?>"><?php endif; ?>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="campo">
        <label for="profesional_id">Quién atendió</label>
        <select id="profesional_id" name="profesional_id" required>
            <option value="">Elegir…</option>
            <?php foreach ($profesionales as $p): ?>
                <option value="<?= $p['id'] ?>" data-cobra="<?= $p['cobra'] ?>" <?= (int) $p['id'] === $profElegido ? 'selected' : '' ?>>
                    <?= e($p['nombre']) ?> (<?= $tipos[$p['tipo']] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <fieldset class="campo" style="border:0;padding:0">
        <legend class="etiqueta" style="margin-bottom:6px">Servicios <small>(puedes cambiar el precio)</small></legend>
        <div class="opciones">
            <?php foreach ($servicios as $s): $marcado = in_array((int) $s['id'], array_map('intval', $seleccion), true); ?>
                <div class="opcion" data-servicio>
                    <input type="checkbox" id="s<?= $s['id'] ?>" name="servicio[]" value="<?= $s['id'] ?>" <?= $marcado ? 'checked' : '' ?>>
                    <label for="s<?= $s['id'] ?>" style="flex:1"><strong><?= e($s['nombre']) ?></strong></label>
                    <input type="number" name="precio[<?= $s['id'] ?>]" value="<?= e(number_format((float) ($preciosCita[$s['id']] ?? $s['precio']), 2, '.', '')) ?>"
                           step="0.01" min="0" style="width:110px" aria-label="Precio de <?= e($s['nombre']) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="fila-campos">
        <div class="campo">
            <label for="metodo_pago">Cómo pagó</label>
            <select id="metodo_pago" name="metodo_pago">
                <?php foreach ($metodos as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="propina">Propina <small>(opcional)</small></label>
            <input type="number" id="propina" name="propina" value="0" step="0.01" min="0">
        </div>
    </div>

    <fieldset class="campo" style="border:0;padding:0">
        <legend class="etiqueta" style="margin-bottom:6px">¿Quién recibió el dinero?</legend>
        <div class="opciones">
            <label class="opcion"><input type="radio" name="cobrado_por" value="local" <?= $cobraSugerido === 'local' ? 'checked' : '' ?>>
                <span><strong>La caja del local</strong></span></label>
            <label class="opcion"><input type="radio" name="cobrado_por" value="profesional" <?= $cobraSugerido === 'profesional' ? 'checked' : '' ?>>
                <span><strong>El peluquero directamente</strong><small>Con su propio QR, transferencia o efectivo</small></span></label>
        </div>
    </fieldset>

    <p style="font-size:1.4rem;font-weight:700;margin:8px 0 14px">Total: <span id="total-cobro">$0,00</span></p>
    <button class="boton boton-ancho" type="submit">Registrar cobro</button>
</form>
