<?php $hoy = date('Y-m-d'); ?>
<div class="tk-titulo-pagina cabecera">
    <div>
        <h1>Tarjetas de regalo</h1>
        <p>Se pagan hoy y se usan después, con un código. Sirven para una o varias visitas hasta acabar el saldo.</p>
    </div>
    <form method="get" class="tk-acciones acciones" role="search">
        <input type="hidden" name="r" value="tarjetas">
        <input type="search" name="codigo" value="<?= e($buscar) ?>" placeholder="Código, ej. AB12-CD34" aria-label="Buscar tarjeta por código" style="max-width:200px">
        <button class="tk-boton secundario boton boton-claro" type="submit">Ver saldo</button>
    </form>
</div>
<?php if ($error): ?><p class="tk-aviso mal aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <?php if ($ver):
        $vencida = $ver['vence_en'] && $ver['vence_en'] < $hoy; ?>
    <section class="tk-tarjeta bloque">
        <div class="tarjeta-regalo">
            <p style="margin:0" class="suave">Tarjeta de regalo · <?= e($u['salon']) ?></p>
            <p class="codigo-regalo" style="margin:6px 0"><?= e($ver['codigo']) ?></p>
            <p style="margin:0;font-size:1.2rem">Saldo: <strong><?= dinero($ver['saldo']) ?></strong> de <?= dinero($ver['monto']) ?></p>
            <?php if ($ver['para_nombre'] || $ver['de_nombre']): ?><p style="margin:6px 0 0">Para <?= e($ver['para_nombre'] ?: '—') ?><?= $ver['de_nombre'] ? ', de ' . e($ver['de_nombre']) : '' ?></p><?php endif; ?>
            <?php if ($ver['mensaje']): ?><p style="margin:6px 0 0"><em>"<?= e($ver['mensaje']) ?>"</em></p><?php endif; ?>
            <?php if ($ver['vence_en']): ?><p style="margin:6px 0 0"><small>Válida hasta el <?= (new DateTimeImmutable($ver['vence_en']))->format('d/m/Y') ?></small></p><?php endif; ?>
        </div>
        <?php if ($ver['anulada']): ?><p class="tk-aviso mal aviso aviso-error">Esta tarjeta fue anulada.</p>
        <?php elseif ($vencida): ?><p class="tk-aviso mal aviso aviso-error">Esta tarjeta ya venció.</p><?php endif; ?>
        <div class="tk-acciones acciones">
            <?php if ($waTarjeta): ?><a class="tk-boton ok boton boton-wa" href="<?= e($waTarjeta) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a><?php endif; ?>
            <button class="tk-boton secundario boton boton-claro" type="button" onclick="window.print()">Imprimir</button>
            <?php if (!$ver['anulada'] && (float) $ver['saldo'] === (float) $ver['monto']): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="anular"><input type="hidden" name="tarjeta_id" value="<?= (int) $ver['id'] ?>">
                    <button class="tk-boton boton boton-peligro" type="submit" data-confirmar="¿Anular esta tarjeta? Se quita de la caja del día en que se vendió.">Anular</button></form>
            <?php endif; ?>
        </div>
        <h3 style="margin-top:16px">Usos</h3>
        <?php if (!$usos): ?><p class="suave">Todavía no se ha usado.</p><?php else: ?>
            <ul class="lista"><?php foreach ($usos as $us): ?>
                <li><span><?= (new DateTimeImmutable($us['fecha']))->format('d/m/Y H:i') ?></span><span class="monto monto-neg">−<?= dinero($us['monto']) ?></span></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <form method="post" class="tk-tarjeta bloque">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="vender">
        <h2>Vender una tarjeta de regalo</h2>
        <div class="fila-campos">
            <div class="tk-campo campo"><label for="tm">Valor</label><input type="number" id="tm" name="monto" min="1" max="1000" step="0.01" placeholder="30,00" required></div>
            <div class="tk-campo campo"><label for="tp">Cómo pagó</label>
                <select id="tp" name="metodo_pago"><?php foreach (metodos_pago() as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="fila-campos">
            <div class="tk-campo campo"><label for="tde">De parte de <small>(opcional)</small></label><input type="text" id="tde" name="de_nombre" maxlength="100"></div>
            <div class="tk-campo campo"><label for="tpara">Para <small>(opcional)</small></label><input type="text" id="tpara" name="para_nombre" maxlength="100"></div>
        </div>
        <div class="fila-campos">
            <div class="tk-campo campo"><label for="ttel">Celular de quien la recibe <small>(para enviársela)</small></label><input type="tel" id="ttel" name="para_telefono" maxlength="20" placeholder="0991234567"></div>
            <div class="tk-campo campo"><label for="tv">Válida hasta <small>(opcional)</small></label><input type="date" id="tv" name="vence_en" min="<?= $hoy ?>"></div>
        </div>
        <div class="tk-campo campo"><label for="tmsg">Mensaje <small>(opcional)</small></label><input type="text" id="tmsg" name="mensaje" maxlength="200" placeholder="¡Feliz cumpleaños!"></div>
        <button class="tk-boton boton boton-ancho" type="submit">Crear tarjeta</button>
        <small style="display:block;margin-top:8px">El sistema crea un código único. Al cobrar, escribe el código y se descuenta del saldo.</small>
    </form>
</div>

<section class="tk-tarjeta bloque">
    <h2>Tarjetas vendidas</h2>
    <?php if (!$tarjetas): ?><p class="tk-vacio vacio">Aún no has vendido tarjetas de regalo.</p><?php else: ?>
    <div class="desliza"><table class="tk-tabla tabla">
        <thead><tr><th>Código</th><th>Para</th><th class="der">Valor</th><th class="der">Saldo</th><th></th></tr></thead>
        <tbody><?php foreach ($tarjetas as $t): ?>
            <tr style="<?= $t['anulada'] || (float) $t['saldo'] <= 0 ? 'opacity:.55' : '' ?>">
                <td><strong style="font-family:ui-monospace,monospace"><?= e($t['codigo']) ?></strong><br><small><?= (new DateTimeImmutable($t['creada_en']))->format('d/m/Y') ?><?= $t['anulada'] ? ' · anulada' : '' ?><?= $t['vence_en'] && $t['vence_en'] < $hoy ? ' · vencida' : '' ?></small></td>
                <td><?= e($t['para_nombre'] ?: '—') ?></td>
                <td class="der"><?= dinero($t['monto']) ?></td><td class="der"><strong><?= dinero($t['saldo']) ?></strong></td>
                <td class="der"><a class="tk-boton secundario chico boton boton-claro boton-chico" href="<?= e(url('tarjetas', ['ver' => $t['id']])) ?>">Ver</a></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</section>
