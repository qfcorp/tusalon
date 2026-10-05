<?php use TuSalon\Automaticas; ?>
<div class="cabecera">
    <div>
        <h1>Reporte del mes</h1>
        <p>Lo más vendido, quién vendió más y las horas más vacías. Te llega solo por Telegram cada día 1.</p>
    </div>
    <form method="get" class="acciones">
        <input type="hidden" name="r" value="reporte">
        <select name="mes" onchange="this.form.submit()" aria-label="Mes">
            <?php foreach ($meses as $m => $txt): ?><option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="boton boton-chico" type="submit">Ver</button></noscript>
    </form>
</div>

<?php if (!$reporte): ?>
    <section class="bloque">
        <p class="candado">El reporte del mes es del plan Completa.</p>
        <a class="boton" href="<?= e(url('completa')) ?>">Ver plan Completa</a>
    </section>
<?php else: $r = $reporte; ?>
    <div class="rejilla rejilla-3">
        <div class="bloque"><div class="cifra"><?= dinero($r['total']) ?></div><p class="cifra-nombre">Ventas · <?= $r['ventas'] ?> cobros</p></div>
        <div class="bloque"><div class="cifra"><?= $r['clientes_nuevos'] ?></div><p class="cifra-nombre">Clientes nuevos · <?= $r['reservas_online'] ?> reservas en línea</p></div>
        <div class="bloque"><div class="cifra"><?= $r['estrellas'] !== null ? $r['estrellas'] . ' ★' : '—' ?></div><p class="cifra-nombre">Calificación · <?= $r['calificaciones'] ?> <?= $r['calificaciones'] === 1 ? 'opinión' : 'opiniones' ?></p></div>
    </div>

    <div class="rejilla rejilla-2">
        <section class="bloque">
            <h2>Lo más vendido</h2>
            <?php if (!$r['servicios']): ?><p class="vacio">Sin ventas este mes.</p><?php else: ?>
                <table class="tabla"><thead><tr><th>Servicio</th><th class="der">Veces</th><th class="der">Total</th></tr></thead><tbody>
                <?php foreach ($r['servicios'] as $s): ?>
                    <tr><td><?= e($s['nombre']) ?></td><td class="der"><?= (int) $s['cantidad'] ?></td><td class="der"><?= dinero($s['total']) ?></td></tr>
                <?php endforeach; ?></tbody></table>
            <?php endif; ?>
        </section>
        <section class="bloque">
            <h2>Equipo</h2>
            <table class="tabla"><thead><tr><th>Peluquero</th><th class="der">Vendió</th><th class="der">Estrellas</th></tr></thead><tbody>
            <?php foreach ($r['equipo'] as $i => $e): ?>
                <tr><td><?= $i === 0 && $e['vendido'] > 0 ? '🏆 ' : '' ?><?= e($e['nombre']) ?></td><td class="der"><?= dinero($e['vendido']) ?></td>
                    <td class="der"><?= $e['estrellas'] !== null ? e($e['estrellas']) . ' ★' : '—' ?></td></tr>
            <?php endforeach; ?></tbody></table>
        </section>
        <section class="bloque">
            <h2>Horas más vacías</h2>
            <?php if (!$r['horas_muertas']): ?><p class="vacio">Configura tu horario para ver esto.</p><?php else: ?>
                <ul class="lista">
                    <?php foreach ($r['horas_muertas'] as $h): ?><li><span><?= e($h['hora']) ?></span><strong><?= (int) $h['citas'] ?> citas</strong></li><?php endforeach; ?>
                    <?php if ($r['dia_flojo']): ?><li><span>Día más flojo: <?= e($r['dia_flojo']['dia']) ?></span><strong><?= (int) $r['dia_flojo']['citas'] ?> citas</strong></li><?php endif; ?>
                </ul>
                <p class="suave">Idea: una promoción en esas horas para llenarlas.</p>
            <?php endif; ?>
        </section>
        <section class="bloque">
            <h2>Faltas y cancelaciones</h2>
            <ul class="lista">
                <li><span>No llegaron</span><strong><?= $r['faltas'] ?></strong></li>
                <li><span>Cancelaron (el cliente)</span><strong><?= $r['cancelaciones'] ?></strong></li>
                <li><span>Propinas</span><strong><?= dinero($r['propinas']) ?></strong></li>
                <?php if ($r['gastos'] > 0): ?><li><span>Gastos (facturas recibidas)</span><strong><?= dinero($r['gastos']) ?></strong></li><?php endif; ?>
            </ul>
        </section>
    </div>
    <form method="post"><?= campo_csrf() ?>
        <button class="boton" type="submit">Enviar este reporte a mi Telegram</button></form>
<?php endif; ?>
