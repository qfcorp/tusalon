<?php
$tipos = ['dueno' => 'dueño', 'empleado' => 'empleado', 'porcentaje' => 'porcentaje', 'alquiler' => 'alquila'];
$cobraSugerido = 'local';
foreach ($profesionales as $p) {
    if ((int) $p['id'] === $profElegido) $cobraSugerido = $p['cobra'];
}
$paqPorServicio = [];
foreach ($paquetes as $pq) $paqPorServicio[(int) $pq['servicio_id']][] = $pq;
$valorPunto = (float) ($puntos['valor'] ?? 0);
?>
<div class="cabecera">
    <div>
        <h1>Cobrar</h1>
        <?php if ($cita): ?><p>Cita de <?= e($cita['cliente'] ?: 'cliente sin nombre') ?> a las <?= (new DateTimeImmutable($cita['inicio']))->format('H:i') ?></p><?php endif; ?>
    </div>
    <a class="boton boton-claro" href="<?= e($cita ? url('cita', ['id' => $cita['id']]) : url('caja')) ?>">Volver</a>
</div>

<?php if (!$cita && $completa): ?>
<form method="get" class="bloque" style="max-width:640px">
    <input type="hidden" name="r" value="cobrar">
    <div class="campo" style="margin-bottom:0"><label for="cliente_id">Cliente <small>(para usar paquetes, puntos y sumarle puntos)</small></label>
        <select id="cliente_id" name="cliente_id" onchange="this.form.submit()">
            <option value="">Sin cliente</option>
            <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $cliente && (int) $cliente['id'] === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="boton boton-claro boton-chico" type="submit">Elegir</button></noscript></div>
</form>
<?php endif; ?>

<form method="post" id="form-cobro" class="bloque" style="max-width:640px" data-valor-punto="<?= e($valorPunto) ?>" data-anticipo="<?= e($anticipo) ?>">
    <?= campo_csrf() ?>
    <?php if ($cita): ?><input type="hidden" name="cita" value="<?= (int) $cita['id'] ?>"><?php endif; ?>
    <?php if (!$cita && $cliente): ?><input type="hidden" name="cliente_id" value="<?= (int) $cliente['id'] ?>"><?php endif; ?>
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
            <?php foreach ($servicios as $s): $sId = (int) $s['id']; $marcado = in_array($sId, array_map('intval', $seleccion), true); ?>
                <div class="opcion" data-servicio style="flex-wrap:wrap">
                    <input type="checkbox" id="s<?= $sId ?>" name="servicio[]" value="<?= $sId ?>" <?= $marcado ? 'checked' : '' ?>>
                    <label for="s<?= $sId ?>" style="flex:1"><strong><?= e($s['nombre']) ?></strong></label>
                    <input type="number" name="precio[<?= $sId ?>]" value="<?= e(number_format((float) ($preciosCita[$sId] ?? $s['precio']), 2, '.', '')) ?>"
                           step="0.01" min="0" style="width:110px" aria-label="Precio de <?= e($s['nombre']) ?>">
                    <?php foreach ($paqPorServicio[$sId] ?? [] as $pq): ?>
                        <label class="usar-paquete" style="flex-basis:100%;margin:6px 0 0 28px;font-size:.92rem">
                            <input type="checkbox" name="paquetes[<?= $sId ?>]" value="<?= (int) $pq['id'] ?>" data-valor-sesion="<?= e($pq['valor_sesion']) ?>"
                                   <?= (int) ($d['paquetes'][$sId] ?? 0) === (int) $pq['id'] ? 'checked' : '' ?>>
                            Usar sesión del paquete <strong><?= e($pq['nombre']) ?></strong> (le quedan <?= (int) $pq['quedan'] ?>)</label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <?php if ($productos): ?>
    <details class="campo" <?= array_filter((array) $d['productos']) ? 'open' : '' ?>>
        <summary class="etiqueta" style="cursor:pointer;margin-bottom:6px">Productos vendidos <small>(opcional)</small></summary>
        <div class="opciones">
            <?php foreach ($productos as $pr): ?>
                <div class="opcion" data-producto data-precio="<?= e($pr['precio']) ?>">
                    <label for="pr<?= (int) $pr['id'] ?>" style="flex:1"><strong><?= e($pr['nombre']) ?></strong>
                        <small><?= dinero($pr['precio']) ?> · quedan <?= (int) $pr['stock'] ?></small></label>
                    <input type="number" id="pr<?= (int) $pr['id'] ?>" name="productos[<?= (int) $pr['id'] ?>]" value="<?= (int) ($d['productos'][$pr['id']] ?? 0) ?: '' ?>"
                           min="0" max="<?= (int) $pr['stock'] ?>" step="1" placeholder="0" style="width:80px" aria-label="Cantidad de <?= e($pr['nombre']) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>

    <?php if ($completa): ?>
    <div class="ya-pagado">
        <h3>¿Ya pagó algo antes?</h3>
        <?php if ($anticipo > 0): ?>
            <p class="aviso aviso-ok" style="margin-bottom:10px">✅ Pagó un anticipo de <strong><?= dinero($anticipo) ?></strong>. Se descuenta solo.</p>
        <?php endif; ?>
        <div class="fila-campos">
            <div class="campo"><label for="tarjeta_codigo">Tarjeta de regalo <small>(código)</small></label>
                <input type="text" id="tarjeta_codigo" name="tarjeta_codigo" value="<?= e($d['tarjeta_codigo']) ?>" placeholder="AB12-CD34" autocomplete="off" style="text-transform:uppercase"></div>
            <div class="campo"><label for="tarjeta_monto">Usar de la tarjeta <small>(vacío = lo que alcance)</small></label>
                <input type="number" id="tarjeta_monto" name="tarjeta_monto" value="<?= e($d['tarjeta_monto']) ?>" step="0.01" min="0"></div>
        </div>
        <?php if ($cliente && $puntos['activo']): ?>
            <div class="campo" style="margin-bottom:0"><label for="puntos">Canjear puntos <small>(tiene <?= (int) $cliente['puntos'] ?>, desde <?= (int) $puntos['minimo_canje'] ?>)</small></label>
                <input type="number" id="puntos" name="puntos" value="<?= e($d['puntos']) ?>" min="0" max="<?= (int) $cliente['puntos'] ?>" step="1" placeholder="0"
                       <?= (int) $cliente['puntos'] < (int) $puntos['minimo_canje'] ? 'disabled' : '' ?>>
                <small><?= (int) $cliente['puntos'] ?> puntos = <?= dinero((int) $cliente['puntos'] * $valorPunto) ?></small></div>
        <?php elseif (!$cliente && !$cita): ?>
            <p class="suave" style="margin:0;font-size:.9rem">Elige el cliente arriba para usar sus paquetes o puntos.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="fila-campos">
        <div class="campo">
            <label for="metodo_pago">Cómo pagó lo que falta</label>
            <select id="metodo_pago" name="metodo_pago">
                <?php foreach (metodos_pago() as $k => $v): ?><option value="<?= $k ?>" <?= $d['metodo_pago'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="campo">
            <label for="propina">Propina <small>(opcional)</small></label>
            <input type="number" id="propina" name="propina" value="<?= e($d['propina']) ?>" step="0.01" min="0">
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

    <div class="resumen-cobro" aria-live="polite">
        <span>Total</span><span id="total-servicios" class="monto">$0,00</span>
        <span id="fila-prepago" hidden>Ya pagado</span><span id="total-prepago" class="monto" hidden>$0,00</span>
        <span class="grande">A cobrar hoy</span><span id="total-cobro" class="grande monto">$0,00</span>
    </div>
    <p id="nota-tarjeta" class="suave" style="font-size:.9rem" hidden>La tarjeta de regalo se descuenta al registrar (según su saldo).</p>
    <button class="boton boton-ancho" type="submit">Registrar cobro</button>
</form>
