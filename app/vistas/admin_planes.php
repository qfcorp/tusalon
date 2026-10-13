<?php use TuSalon\Planes; ?>
<div class="cabecera">
    <div><h1>Planes y precios</h1>
        <p>Lo que cambies aquí se ve al instante en el registro, en la página de planes de cada salón y en los próximos pagos.</p></div>
</div>

<div class="rejilla rejilla-2">
    <?php foreach ($lista as $p): ?>
        <form method="post" class="bloque">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="plan"><input type="hidden" name="codigo" value="<?= e($p['codigo']) ?>">
            <h2>Plan <?= e($p['nombre']) ?></h2>
            <div class="fila-campos">
                <div class="campo"><label for="n-<?= e($p['codigo']) ?>">Nombre</label>
                    <input type="text" id="n-<?= e($p['codigo']) ?>" name="nombre" value="<?= e($p['nombre']) ?>" required></div>
                <div class="campo"><label for="p-<?= e($p['codigo']) ?>">Precio al mes ($)</label>
                    <input type="number" id="p-<?= e($p['codigo']) ?>" name="precio_mensual" value="<?= e($p['precio_mensual']) ?>" step="0.01" min="0" required></div>
            </div>
            <div class="campo"><label for="m-<?= e($p['codigo']) ?>">Máximo de personas en el equipo <small>(vacío = sin límite)</small></label>
                <input type="number" id="m-<?= e($p['codigo']) ?>" name="max_profesionales" value="<?= e($p['max_profesionales'] ?? '') ?>" min="1"></div>
            <div class="campo"><label for="d-<?= e($p['codigo']) ?>">Descripción corta <small>(opcional)</small></label>
                <input type="text" id="d-<?= e($p['codigo']) ?>" name="descripcion" value="<?= e($p['descripcion'] ?? '') ?>" maxlength="300"></div>
            <table class="tabla">
                <thead><tr><th>Periodo</th><th class="der">Cobras</th><th class="der">Sale al mes</th></tr></thead>
                <tbody><?php foreach ($tabla[$p['codigo']] as $per => $c): ?>
                    <tr><td><?= e(planes()->textoPeriodo($per)) ?></td><td class="der"><strong><?= dinero($c['monto']) ?></strong></td><td class="der"><?= dinero($c['precio_por_mes']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table>
            <button class="boton boton-ancho" type="submit" style="margin-top:12px">Guardar plan <?= e($p['nombre']) ?></button>
        </form>
    <?php endforeach; ?>
</div>

<form method="post" class="bloque">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="ajustes">
    <h2>Prueba gratis, promociones y cobro</h2>
    <div class="rejilla rejilla-3" style="gap:12px">
        <div class="campo"><label for="dias_prueba">Días de prueba gratis</label>
            <input type="number" id="dias_prueba" name="dias_prueba" value="<?= e($a['dias_prueba']) ?>" min="0" max="90" required></div>
        <div class="campo"><label for="dias_gracia">Días de gracia al vencer</label>
            <input type="number" id="dias_gracia" name="dias_gracia" value="<?= e($a['dias_gracia']) ?>" min="0" max="30" required>
            <small>Después del vencimiento, sigue entrando estos días con un aviso.</small></div>
        <div class="campo"><label for="whatsapp_ventas">WhatsApp de ventas</label>
            <input type="tel" id="whatsapp_ventas" name="whatsapp_ventas" value="<?= e($a['whatsapp_ventas']) ?>" required>
            <small>Con código del país: 593…</small></div>
    </div>
    <div class="rejilla rejilla-2" style="gap:12px">
        <fieldset class="campo"><legend>Semestral</legend>
            <div class="fila-campos">
                <div class="campo"><label for="semestral_paga">Paga (meses)</label><input type="number" id="semestral_paga" name="semestral_paga" value="<?= e($a['semestral_paga']) ?>" min="1" max="6" required></div>
                <div class="campo"><label for="semestral_recibe">Recibe (meses)</label><input type="number" id="semestral_recibe" name="semestral_recibe" value="<?= e($a['semestral_recibe']) ?>" min="6" max="12" required></div>
            </div></fieldset>
        <fieldset class="campo"><legend>Anual (incluye dominio propio)</legend>
            <div class="fila-campos">
                <div class="campo"><label for="anual_paga">Paga (meses)</label><input type="number" id="anual_paga" name="anual_paga" value="<?= e($a['anual_paga']) ?>" min="1" max="12" required></div>
                <div class="campo"><label for="anual_recibe">Recibe (meses)</label><input type="number" id="anual_recibe" name="anual_recibe" value="<?= e($a['anual_recibe']) ?>" min="12" max="24" required></div>
            </div></fieldset>
    </div>
    <button class="boton" type="submit">Guardar ajustes</button>
</form>

<?php
$gpConf = TuSalon\GooglePlay::configurado();
?>
<section class="bloque">
    <h2>Precios para Google Play (app de Android)</h2>
    <p class="suave">En la app de Google Play los planes se pagan con Google, que cobra el 15 % en Ecuador. Estos son los precios sugeridos para que te quede lo mismo que en la web.
        Los precios reales se ponen en <strong>Play Console → Monetizar → Suscripciones</strong>, con estos códigos de producto.</p>
    <div class="desliza"><table class="tabla">
        <thead><tr><th>Código del producto en Google Play</th><th class="der">Precio web</th><th class="der">Precio sugerido en Google Play</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $p): foreach (['mensual', 'semestral', 'anual'] as $pe):
            $web = (float) $tabla[$p['codigo']][$pe]['monto']; ?>
            <tr><td><code><?= e(TuSalon\GooglePlay::producto($p['codigo'], $pe)) ?></code><br><small><?= e($p['nombre']) ?> · <?= $pe === 'mensual' ? 'cada mes' : ($pe === 'semestral' ? 'cada 6 meses' : 'cada año') ?></small></td>
                <td class="der"><?= dinero($web) ?></td><td class="der"><strong><?= dinero(TuSalon\GooglePlay::precioSugerido($web)) ?></strong></td></tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table></div>
    <p>Paquete de la app: <code><?= e(TuSalon\GooglePlay::paquete()) ?></code> ·
        Cuenta de servicio de Google: <?= $gpConf ? '<span class="etq etq-confirmada">configurada</span>' : '<span class="etq etq-reservada">falta (ver docs/GOOGLE_PLAY.md)</span>' ?></p>
</section>
