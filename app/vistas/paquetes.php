<?php $activos = array_values(array_filter($paquetes, fn($p) => $p['activo'])); $hoy = date('Y-m-d'); ?>
<div class="cabecera">
    <div>
        <h1>Paquetes</h1>
        <p>El cliente paga varias sesiones por adelantado. Cada vez que viene, se descuenta una al cobrar.</p>
    </div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <form method="post" class="bloque">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="vender">
        <h2>Vender un paquete</h2>
        <?php if (!$activos): ?>
            <p class="vacio">Primero crea un paquete (abajo).</p>
        <?php elseif (!$clientes): ?>
            <p class="vacio">Primero agrega el cliente en <a href="<?= e(url('cliente')) ?>">Clientes</a>.</p>
        <?php else: ?>
            <div class="campo"><label for="vp">Paquete</label>
                <select id="vp" name="paquete_id" required>
                    <?php foreach ($activos as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?> · <?= dinero($p['precio']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="vc">Cliente</label>
                <select id="vc" name="cliente_id" required>
                    <option value="">Elegir…</option>
                    <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) $c['id'] === $clienteElegido ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?></option><?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="vm">Cómo pagó</label>
                <select id="vm" name="metodo_pago"><?php foreach (metodos_pago() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
            <button class="boton boton-ancho" type="submit">Vender paquete</button>
            <small style="display:block;margin-top:8px">El dinero entra hoy a la caja. Cuando el cliente venga, al cobrar marca "Usar sesión del paquete".</small>
        <?php endif; ?>
    </form>

    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="<?= $enEdicion ? 'editar' : 'crear' ?>">
        <?php if ($enEdicion): ?><input type="hidden" name="paquete_id" value="<?= (int) $enEdicion['id'] ?>"><?php endif; ?>
        <h2><?= $enEdicion ? 'Editar paquete' : 'Crear paquete' ?></h2>
        <?php if (!$servicios): ?><p class="vacio">Primero agrega tus <a href="<?= e(url('servicios')) ?>">servicios</a>.</p><?php else: ?>
        <div class="campo"><label for="ps">Servicio que incluye</label>
            <select id="ps" name="servicio_id" required>
                <?php foreach ($servicios as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) ($enEdicion['servicio_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nombre']) ?> (normal <?= dinero($s['precio']) ?>)</option><?php endforeach; ?>
            </select></div>
        <div class="fila-campos">
            <div class="campo"><label for="pn">Sesiones</label><input type="number" id="pn" name="sesiones" min="2" max="100" value="<?= (int) ($enEdicion['sesiones'] ?? 5) ?>" required></div>
            <div class="campo"><label for="pp">Precio del paquete</label><input type="number" id="pp" name="precio" min="0.01" step="0.01" value="<?= e($enEdicion['precio'] ?? '') ?>" placeholder="40,00" required></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="pv">Vence en <small>(días; vacío = no vence)</small></label><input type="number" id="pv" name="vigencia_dias" min="7" max="730" value="<?= e($enEdicion['vigencia_dias'] ?? '90') ?>"></div>
            <div class="campo"><label for="pnom">Nombre <small>(opcional)</small></label><input type="text" id="pnom" name="nombre" maxlength="100" value="<?= e($enEdicion['nombre'] ?? '') ?>" placeholder="5 cortes"></div>
        </div>
        <?php if ($enEdicion): ?>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $enEdicion['activo'] ? 'checked' : '' ?>><span><strong>Se sigue vendiendo</strong></span></label>
        <?php endif; ?>
        <button class="boton boton-ancho" type="submit"><?= $enEdicion ? 'Guardar' : 'Crear paquete' ?></button>
        <?php if ($enEdicion): ?><a class="boton boton-claro boton-ancho" style="margin-top:8px" href="<?= e(url('paquetes')) ?>">Cancelar</a><?php endif; ?>
        <?php endif; ?>
    </form>
</div>

<section class="bloque">
    <h2>Tus paquetes</h2>
    <?php if (!$paquetes): ?><p class="vacio">Todavía no has creado paquetes.</p><?php else: ?>
    <div class="desliza"><table class="tabla">
        <thead><tr><th>Paquete</th><th class="der">Precio</th><th class="der">Cada sesión</th><th class="der">Ahorro</th><th class="der">Vendidos</th><th></th></tr></thead>
        <tbody><?php foreach ($paquetes as $p):
            $cada = (float) $p['precio'] / (int) $p['sesiones'];
            $ahorro = (float) $p['precio_servicio'] * (int) $p['sesiones'] - (float) $p['precio']; ?>
            <tr style="<?= $p['activo'] ? '' : 'opacity:.55' ?>"><td><strong><?= e($p['nombre']) ?></strong><br><small><?= (int) $p['sesiones'] ?> × <?= e($p['servicio']) ?> · <?= $p['vigencia_dias'] ? 'vence en ' . (int) $p['vigencia_dias'] . ' días' : 'no vence' ?></small></td>
                <td class="der"><?= dinero($p['precio']) ?></td><td class="der"><?= dinero($cada) ?></td>
                <td class="der"><?= $ahorro > 0 ? dinero($ahorro) : '—' ?></td><td class="der"><?= (int) $p['vendidos'] ?></td>
                <td class="der"><a class="boton boton-claro boton-chico" href="<?= e(url('paquetes', ['editar' => $p['id']])) ?>">Editar</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Paquetes vendidos</h2>
    <?php if (!$vendidos): ?><p class="vacio">Aún no has vendido paquetes.</p><?php else: ?>
    <ul class="lista">
        <?php foreach ($vendidos as $v):
            $vencido = $v['vence_en'] && $v['vence_en'] < $hoy;
            $estado = $v['anulado'] ? 'Anulado' : ((int) $v['quedan'] === 0 ? 'Terminado' : ($vencido ? 'Vencido' : 'Le quedan ' . (int) $v['quedan'])); ?>
            <li style="<?= $v['anulado'] ? 'opacity:.55' : '' ?>">
                <div><div class="principal-linea"><a href="<?= e(url('cliente', ['id' => $v['cliente_id']])) ?>"><?= e($v['cliente']) ?></a> · <?= e($v['nombre']) ?></div>
                    <div class="linea-sub">Comprado el <?= (new DateTimeImmutable($v['comprado_en']))->format('d/m/Y') ?> · <?= dinero($v['precio_pagado']) ?>
                        · usó <?= (int) $v['sesiones_usadas'] ?> de <?= (int) $v['sesiones_total'] ?><?= $v['vence_en'] ? ' · vence ' . (new DateTimeImmutable($v['vence_en']))->format('d/m/Y') : '' ?></div></div>
                <div style="text-align:right">
                    <span class="etq <?= !$v['anulado'] && (int) $v['quedan'] > 0 && !$vencido ? 'etq-confirmada' : 'etq-reservada' ?>"><?= $estado ?></span>
                    <?php if (!$v['anulado'] && (int) $v['sesiones_usadas'] === 0): ?>
                        <form method="post" style="margin-top:6px"><?= campo_csrf() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="vendido_id" value="<?= (int) $v['id'] ?>">
                            <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Anular esta venta? Se quita de la caja.">Anular</button></form>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
