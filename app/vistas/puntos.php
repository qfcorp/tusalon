<?php
$valor100 = $conf['valor'] * 100;
$ejemplo = 20; $ptsEj = (int) floor($ejemplo * $conf['por_dolar']);
?>
<div class="cabecera">
    <div>
        <h1>Puntos</h1>
        <p>Tus clientes ganan puntos cada vez que pagan y los cambian por descuentos.</p>
    </div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <form method="post" class="bloque">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="config">
        <h2>Cómo funcionan</h2>
        <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $conf['activo'] ? 'checked' : '' ?>>
            <span><strong>Dar puntos a mis clientes</strong><small>Se suman solos al cobrar, si eliges el cliente.</small></span></label>
        <div class="fila-campos">
            <div class="campo"><label for="pd">Puntos por cada $1 pagado</label><input type="number" id="pd" name="por_dolar" min="0.1" max="100" step="0.1" value="<?= e(rtrim(rtrim(number_format($conf['por_dolar'], 2, '.', ''), '0'), '.')) ?>"></div>
            <div class="campo"><label for="pv">100 puntos valen ($)</label><input type="number" id="pv" name="valor_100" min="0.01" step="0.01" value="<?= e(number_format($valor100, 2, '.', '')) ?>"></div>
        </div>
        <div class="campo"><label for="pm">Se canjean desde (puntos)</label><input type="number" id="pm" name="minimo" min="1" step="1" value="<?= (int) $conf['minimo_canje'] ?>"></div>
        <p class="aviso" style="background:var(--verde-claro)">Ejemplo: un cliente que paga <?= dinero($ejemplo) ?> gana <strong><?= $ptsEj ?> puntos</strong>.
            Con <?= (int) $conf['minimo_canje'] ?> puntos tiene <strong><?= dinero($conf['minimo_canje'] * $conf['valor']) ?></strong> de descuento.
            Eso es como devolverle el <?= number_format($conf['por_dolar'] * $conf['valor'] * 100, 1, ',', '.') ?> % de lo que gasta.</p>
        <button class="boton boton-ancho" type="submit">Guardar</button>
        <small style="display:block;margin-top:8px">Los puntos se ganan por lo que el cliente paga ese día (sin propina). Lo que paga con paquete, tarjeta de regalo o puntos no suma puntos.</small>
    </form>

    <section class="bloque">
        <h2>Clientes con más puntos</h2>
        <?php if (!$mejores): ?><p class="vacio">Todavía nadie tiene puntos.</p><?php else: ?>
        <ul class="lista"><?php foreach ($mejores as $c): ?>
            <li><a href="<?= e(url('cliente', ['id' => $c['id']])) ?>"><?= e($c['nombre']) ?></a>
                <span class="monto"><?= number_format((int) $c['puntos'], 0, ',', '.') ?> pts<br><small><?= dinero((int) $c['puntos'] * $conf['valor']) ?></small></span></li>
        <?php endforeach; ?></ul>
        <?php endif; ?>

        <form method="post" class="caja-mov" style="margin-top:16px">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="ajuste">
            <h3>Regalar o quitar puntos</h3>
            <div class="campo"><label for="ac">Cliente</label>
                <select id="ac" name="cliente_id" required><option value="">Elegir…</option>
                    <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['nombre']) ?> (<?= (int) $c['puntos'] ?> pts)</option><?php endforeach; ?></select></div>
            <div class="fila-campos">
                <div class="campo"><label for="ap">Puntos <small>(negativo para quitar)</small></label><input type="number" id="ap" name="puntos" step="1" required placeholder="50"></div>
                <div class="campo"><label for="am">Motivo</label><input type="text" id="am" name="motivo" maxlength="120" required placeholder="Regalo por su cumpleaños"></div>
            </div>
            <button class="boton boton-claro boton-chico" type="submit">Guardar</button>
        </form>
    </section>
</div>
