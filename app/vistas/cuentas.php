<div class="cabecera">
    <div>
        <h1>Cuentas del equipo</h1>
        <p>Cuánto le debes a cada peluquero, o cuánto te debe.</p>
    </div>
</div>

<?php if (!$equipo): ?>
    <section class="bloque"><p class="vacio">Todavía no tienes peluqueros. <a href="<?= e(url('equipo')) ?>">Agrega a tu equipo</a>.</p></section>
<?php endif; ?>

<div class="rejilla rejilla-2">
<?php foreach ($equipo as $p): $s = $p['saldo']; ?>
    <section class="bloque">
        <div class="cabecera" style="margin-bottom:8px">
            <h2 style="margin:0"><?= e($p['nombre']) ?></h2>
            <div style="text-align:right">
                <div class="cifra <?= $s < 0 ? 'monto-neg' : '' ?>" style="font-size:1.5rem"><?= dinero(abs($s)) ?></div>
                <small><?= $s > 0 ? 'Le pagas tú' : ($s < 0 ? 'Te paga a ti' : 'Al día') ?></small>
            </div>
        </div>
        <?php if ($p['movimientos']): ?>
            <details>
                <summary style="cursor:pointer;margin-bottom:6px">Ver detalle (<?= count($p['movimientos']) ?>)</summary>
                <ul class="lista">
                    <?php foreach ($p['movimientos'] as $m): ?>
                        <li>
                            <span><?= e($m['descripcion']) ?><br><small><?= (new DateTimeImmutable($m['fecha']))->format('d/m') ?></small></span>
                            <span class="monto <?= (float) $m['monto'] < 0 ? 'monto-neg' : '' ?>"><?= dinero($m['monto']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>
        <div class="acciones" style="margin-top:12px">
            <?php if ($p['tipo'] === 'alquiler'): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="accion" value="arriendo">
                    <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Cargar el arriendo de <?= e(dinero($p['monto_arriendo'])) ?> a <?= e($p['nombre']) ?>?">Cargar arriendo <?= dinero($p['monto_arriendo']) ?></button></form>
            <?php endif; ?>
            <?php if ($p['movimientos']): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><input type="hidden" name="accion" value="cerrar">
                    <button class="boton boton-chico" type="submit" data-confirmar="¿Ya se pagó? La cuenta de <?= e($p['nombre']) ?> vuelve a cero.">Pagado, cerrar cuenta</button></form>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>
</div>

<?php if (!es_completa($u)): ?>
    <p class="candado">Cobro automático del arriendo, anticipos, escalas de comisión y rol de pagos con IESS: <a href="<?= e(url('completa')) ?>">plan Completa</a></p>
<?php endif; ?>

<?php if ($historial): ?>
<section class="bloque" style="margin-top:16px">
    <h2>Cuentas cerradas</h2>
    <div class="desliza">
        <table class="tabla">
            <thead><tr><th>Peluquero</th><th>Desde</th><th>Hasta</th><th class="der">Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $h): ?>
                <tr>
                    <td><?= e($h['nombre']) ?></td>
                    <td><?= (new DateTimeImmutable($h['desde']))->format('d/m/Y') ?></td>
                    <td><?= (new DateTimeImmutable($h['hasta']))->format('d/m/Y') ?></td>
                    <td class="der"><?= (float) $h['total'] >= 0 ? 'Le pagaste ' . dinero($h['total']) : 'Te pagó ' . dinero(-$h['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
