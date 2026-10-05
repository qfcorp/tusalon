<?php
$metodos = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'tarjeta' => 'Tarjeta',
            'payphone' => 'Payphone', 'plux' => 'Plux'];
$esHoy = $fecha === date('Y-m-d');
$f = new DateTimeImmutable($fecha);
?>
<div class="cabecera">
    <div>
        <h1>Caja</h1>
        <p><?= $esHoy ? 'Hoy' : $f->format('d/m/Y') ?></p>
    </div>
    <div class="acciones">
        <a class="boton boton-claro boton-chico" href="<?= e(url('caja', ['fecha' => $f->modify('-1 day')->format('Y-m-d')])) ?>">Día anterior</a>
        <?php if (!$esHoy): ?><a class="boton boton-claro boton-chico" href="<?= e(url('caja')) ?>">Hoy</a><?php endif; ?>
        <a class="boton" href="<?= e(url('cobrar')) ?>">Cobrar</a>
    </div>
</div>

<div class="rejilla rejilla-3">
    <div class="bloque">
        <div class="cifra"><?= dinero($resumen['cobrado_local']) ?></div>
        <p class="cifra-nombre">Entró a la caja del local</p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= dinero($resumen['efectivo_esperado']) ?></div>
        <p class="cifra-nombre">Debe haber en efectivo</p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= dinero($resumen['cobrado_profesionales']) ?></div>
        <p class="cifra-nombre">Cobraron los peluqueros directo</p>
    </div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Cobros del día</h2>
        <?php if (!$resumen['ventas']): ?>
            <p class="vacio">Todavía no hay cobros este día.<br><a href="<?= e(url('cobrar')) ?>">Registrar un cobro</a></p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($resumen['ventas'] as $v): ?>
                    <li>
                        <span class="hora"><?= (new DateTimeImmutable($v['fecha']))->format('H:i') ?></span>
                        <div style="flex:1">
                            <div class="principal-linea"><?= e($v['detalle']) ?></div>
                            <div class="linea-sub">
                                <?= e($v['profesionales']) ?> · <?= $metodos[$v['metodo_pago']] ?? e($v['metodo_pago']) ?>
                                <?= $v['cobrado_por'] === 'profesional' ? ' · lo cobró el peluquero' : '' ?>
                                <?= (float) $v['propina'] > 0 ? ' · propina ' . dinero($v['propina']) : '' ?>
                            </div>
                        </div>
                        <span class="monto"><?= dinero((float) $v['total'] + (float) $v['propina']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloque">
        <h2>Por forma de pago</h2>
        <?php if (!$resumen['por_metodo']): ?>
            <p class="suave">Sin cobros en la caja del local.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($resumen['por_metodo'] as $m => $monto): ?>
                    <li><span><?= $metodos[$m] ?? e($m) ?></span><span class="monto"><?= dinero($monto) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2 style="margin-top:20px">Cierre de caja</h2>
        <?php if ($c = $resumen['cierre']): ?>
            <ul class="lista">
                <li><span>Debía haber</span><span class="monto"><?= dinero($c['efectivo_esperado']) ?></span></li>
                <li><span>Se contó</span><span class="monto"><?= dinero($c['efectivo_contado']) ?></span></li>
                <li><strong>Diferencia</strong><strong class="monto <?= (float) $c['diferencia'] < 0 ? 'monto-neg' : '' ?>"><?= dinero($c['diferencia']) ?></strong></li>
            </ul>
        <?php else: ?>
            <form method="post">
                <?= campo_csrf() ?>
                <div class="campo">
                    <label for="efectivo_contado">¿Cuánto efectivo hay en la caja?</label>
                    <input type="number" id="efectivo_contado" name="efectivo_contado" step="0.01" min="0" required>
                    <small>Debería haber <?= dinero($resumen['efectivo_esperado']) ?>.</small>
                </div>
                <button class="boton" type="submit" data-confirmar="¿Cerrar la caja de este día? Después no se puede cambiar.">Cerrar caja</button>
            </form>
        <?php endif; ?>
    </section>
</div>
