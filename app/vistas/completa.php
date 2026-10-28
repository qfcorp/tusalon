<?php
/** Mi plan (Anexo B). Variables: $detalle, $catalogo, $planes, $plan, $precios, $pedidos, $resaltar. */
$play = en_app_play();
$esDemo = $detalle['demo'];
$wa = fn(string $texto) => 'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode($texto);
$filas = ['mensual' => planes()->textoPeriodo('mensual'), 'semestral' => planes()->textoPeriodo('semestral'), 'anual' => planes()->textoPeriodo('anual')];
$abiertos = [];
foreach ($pedidos as $p) if (in_array($p['estado'], ['pendiente', 'en_proceso'], true)) foreach (TuSalon\Modulos::limpiar($p['modulos']) as $m) $abiertos[$m] = $p;
$etiqueta = function (string $id) use ($detalle, $catalogo, $play): string {
    if (in_array($id, $detalle['del_plan'], true) && !in_array($id, $detalle['quitados'], true)) return 'Incluido en tu plan';
    if (in_array($id, $detalle['elegidos'] ?? [], true)) return 'Elegido · incluido en tu plan';   // planes de $20 y $45 (migración 011)
    if (in_array($id, $detalle['extra'], true)) return $play ? 'Extra' : 'Extra · ' . dinero($detalle['precio_por_extra'][$id] ?? $catalogo[$id]['precio_mensual']) . ' al mes';
    if (($detalle['por_elegir'] ?? 0) > 0 && !in_array($id, TuSalon\Modulos::FUERA_DEL_CUPO, true)) return 'Puedes elegirlo en tu plan';
    return $play ? 'Para agregar' : 'Precio por cotización';   // regla del dueño: lo que no tiene no muestra precio
};
$activos = array_values(array_filter($catalogo, fn($m) => in_array($m['id'], $detalle['activos'], true)));
$disponibles = array_values(array_filter($catalogo, fn($m) => !in_array($m['id'], $detalle['contratados'], true)));
$vence = $esDemo ? $u['prueba_hasta'] : $u['activo_hasta'];
?>
<div class="cabecera">
    <div>
        <h1>Mi plan</h1>
        <p>Plan <strong><?= e($plan['nombre']) ?></strong><?= $esDemo ? ' · <strong>Demo</strong> con todos los módulos encendidos para que pruebes' : '' ?>
            <?= $vence ? ' · ' . ($esDemo ? 'hasta el ' : 'pagado hasta el ') . (new DateTimeImmutable($vence))->format('d/m/Y') : '' ?></p>
        <?php if (!$esDemo && ($detalle['modulos_elegir'] ?? 0) > 0): ?>
            <p data-por-elegir="<?= (int) $detalle['por_elegir'] ?>">Tu plan trae <strong><?= (int) $detalle['modulos_elegir'] ?> módulos a tu elección</strong><?= $detalle['por_elegir'] > 0
                ? ': te ' . ($detalle['por_elegir'] === 1 ? 'queda 1 por elegir' : 'quedan ' . (int) $detalle['por_elegir'] . ' por elegir') . ($play ? '. Escríbele a Tukán y te lo enciende.' : '. Pídelo abajo y Tukán lo enciende.') : '.' ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if (!$play): ?>
<section class="bloque" id="total">
    <h2><?= $esDemo ? 'Lo que pagarías al contratar' : 'Lo que pagas al mes' ?></h2>
    <ul class="lista">
        <li><span>Plan <?= e($plan['nombre']) ?></span><span class="monto"><?= dinero($detalle['precio_plan']) ?></span></li>
        <?php foreach ($detalle['extra'] as $id): $elegido = in_array($id, $detalle['elegidos'] ?? [], true); ?>
            <li><span><?= e($catalogo[$id]['icono'] . ' ' . $catalogo[$id]['nombre']) ?> <small>(<?= $elegido ? 'elegido, incluido en el plan' : 'extra' ?>)</small></span><span class="monto"><?= $elegido ? 'Incluido' : dinero($detalle['precio_por_extra'][$id] ?? $catalogo[$id]['precio_mensual']) ?></span></li>
        <?php endforeach; ?>
        <?php foreach ($detalle['quitados'] as $id): ?>
            <li><span><?= e($catalogo[$id]['nombre']) ?> <small>(lo quitaste del plan)</small></span><span class="monto monto-neg"><?= dinero(-$catalogo[$id]['descuento']) ?></span></li>
        <?php endforeach; ?>
        <?php if ($detalle['precio_acordado'] !== null): ?>
            <li><span>Precio acordado con Tukán</span><span class="monto"><?= dinero($detalle['precio_acordado']) ?></span></li>
        <?php endif; ?>
    </ul>
    <p class="total-plan"><span>Total al mes</span><span data-total-mensual><?= dinero($detalle['mensual']) ?></span></p>
    <table class="tabla" style="margin-top:12px">
        <?php foreach ($filas as $per => $txt): $c = $precios[$per]; ?>
            <tr><td><?= e($txt) ?></td><td class="der"><strong><?= dinero($c['monto']) ?></strong><br><small><?= dinero($c['precio_por_mes']) ?> al mes</small></td>
                <td class="der"><a class="boton boton-chico <?= $per === 'mensual' ? 'boton-claro' : '' ?>" href="<?= e($wa("Hola, quiero " . ($esDemo ? 'contratar' : 'renovar') . " TuSalón ($txt) para mi salón \"{$u['salon']}\" ({$u['email']}).")) ?>" target="_blank" rel="noopener"><?= $esDemo ? 'Contratar' : 'Renovar' ?></a></td></tr>
        <?php endforeach; ?>
    </table>
    <p class="suave">Pagas por transferencia, DeUna o tarjeta. Tukán te activa el plan el mismo día.</p>
</section>
<?php endif; ?>

<?php if ($pedidos): ?>
<section class="bloque" id="pedidos">
    <h2>Tus pedidos de módulos</h2>
    <ul class="lista">
        <?php foreach (array_slice($pedidos, 0, 8) as $p):
            $txt = ['pendiente' => 'Tukán lo está revisando', 'en_proceso' => 'Tukán lo está revisando', 'atendida' => 'Encendido', 'descartada' => 'No se aprobó'][$p['estado']]; ?>
            <li><span><?= e(implode(', ', array_map([modulos(), 'nombre'], TuSalon\Modulos::limpiar($p['modulos'])))) ?>
                <br><small>Pedido el <?= (new DateTimeImmutable($p['creado']))->format('d/m/Y') ?><?= $p['respuesta'] && $p['estado'] === 'descartada' ? ' · ' . e($p['respuesta']) : '' ?></small></span>
                <span class="etq <?= $p['estado'] === 'atendida' ? 'etq-confirmada' : ($p['estado'] === 'descartada' ? 'etq-cancelada' : 'etq-pendiente') ?>"><?= $txt ?></span></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="bloque">
    <h2><?= $esDemo ? 'Todo lo que tiene tu demo' : 'Tus módulos encendidos' ?></h2>
    <?php if (!$activos): ?>
        <p class="vacio"><?= ($detalle['por_elegir'] ?? 0) > 0 ? 'Tu plan trae la base (abajo) y ' . ((int) $detalle['por_elegir'] === 1 ? '1 módulo' : (int) $detalle['por_elegir'] . ' módulos') . ' a tu elección' . ($play ? '.' : ': elígelos abajo.') : 'Tu plan trae solo la base (abajo). Agrega los módulos que necesites.' ?></p>
    <?php else: ?>
    <ul class="modulos">
        <?php foreach ($activos as $m): ?>
        <li class="modulo modulo-activo" id="modulo-<?= e($m['id']) ?>" data-modulo="<?= e($m['id']) ?>">
            <span class="modulo-icono" aria-hidden="true"><?= e($m['icono']) ?></span>
            <div class="modulo-cuerpo"><strong><?= e($m['nombre']) ?></strong><p><?= e($m['descripcion']) ?></p></div>
            <div class="modulo-lado"><span class="etq etq-confirmada"><?= e($esDemo && !in_array($m['id'], $detalle['contratados'], true) ? 'Solo en la demo' : $etiqueta($m['id'])) ?></span>
                <?php if ($esDemo && !in_array($m['id'], $detalle['contratados'], true) && !$play): ?>
                    <?php if (isset($abiertos[$m['id']])): ?><small>Ya lo pediste</small><?php else: ?>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="<?= e($m['id']) ?>">
                        <button class="boton boton-claro boton-chico" type="submit">Lo quiero · Pedir cotización</button></form>
                    <?php endif; ?>
                <?php endif; ?></div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>

<?php if (!$esDemo && $disponibles): ?>
<section class="bloque" id="agregar">
    <h2>Módulos que puedes agregar</h2>
    <p class="suave">Si lo usaste antes, tus datos están guardados: al encenderlo vuelve todo como estaba.</p>
    <ul class="modulos">
        <?php foreach ($disponibles as $m): $pedido = isset($abiertos[$m['id']]); ?>
        <li class="modulo <?= $resaltar === $m['id'] ? 'modulo-activo' : '' ?>" id="modulo-<?= e($m['id']) ?>" data-modulo="<?= e($m['id']) ?>">
            <span class="modulo-icono" aria-hidden="true"><?= e($m['icono']) ?></span>
            <div class="modulo-cuerpo"><strong><?= e($m['nombre']) ?></strong><p><?= e($m['descripcion']) ?></p>
                <?php if ($m['depende_de']): ?><p class="suave">Necesita: <?= e(implode(', ', array_map([modulos(), 'nombre'], $m['depende_de']))) ?> (se agrega solo).</p><?php endif; ?></div>
            <div class="modulo-lado">
                <?php if (!$play): ?><span class="modulo-precio"><?= e($etiqueta($m['id'])) ?></span><?php endif; ?>
                <?php if ($pedido): ?><span class="etq etq-pendiente">Pedido</span>
                <?php elseif (!$play): ?>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="<?= e($m['id']) ?>">
                        <button class="boton boton-chico" type="submit">Pedir cotización</button></form>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<section class="bloque">
    <h2>Lo que siempre tienes</h2>
    <ul style="margin:0;padding-left:20px">
        <?php foreach (TuSalon\Modulos::BASE as $b): ?><li><?= e($b) ?></li><?php endforeach; ?>
    </ul>
</section>

<?php if ($play):
    $gp = new TuSalon\GooglePlay(db());
    $compras = $gp->deSalon((int) $u['salon_id']);
    $nombres = array_map(fn($p) => $p['nombre'], $planes);
?>
<section class="bloque" id="pago-play" data-csrf="<?= e(token_csrf()) ?>" data-volver="<?= e(url('inicio')) ?>">
    <h2>Paga tu plan con Google Play</h2>
    <p class="aviso" id="play-estado" role="status">Cargando los precios de Google Play…</p>
    <div class="rejilla rejilla-2">
        <?php foreach (['basica', 'completa'] as $pc): if (!isset($planes[$pc])) continue; ?>
        <div class="bloque" style="<?= $pc === 'completa' ? 'border-color:var(--verde);box-shadow:0 0 0 2px var(--verde-claro)' : '' ?>">
            <h3><?= e($planes[$pc]['nombre']) ?></h3>
            <p class="suave"><?= $planes[$pc]['modulos'] ? count($planes[$pc]['modulos']) . ' módulos incluidos' : ((int) ($planes[$pc]['modulos_elegir'] ?? 0) > 0 ? 'La base + ' . (int) $planes[$pc]['modulos_elegir'] . ' módulos a tu elección' : 'Solo la base') ?></p>
            <div class="opciones">
                <?php foreach (['mensual' => 'Cada mes', 'semestral' => 'Cada 6 meses', 'anual' => 'Cada año'] as $per => $txt):
                    $prod = TuSalon\GooglePlay::producto($pc, $per); ?>
                    <button type="button" class="opcion boton-play" data-producto="<?= e($prod) ?>" disabled style="justify-content:space-between;width:100%;text-align:left;cursor:pointer">
                        <span><strong><?= $txt ?></strong></span><span class="monto" data-precio="<?= e($prod) ?>">—</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="suave">El pago lo hace Google Play con tu cuenta de Google. Se renueva solo; lo cancelas cuando quieras en Play Store → Pagos y suscripciones.</p>
</section>
<?php if ($compras): ?>
<section class="bloque">
    <h2>Tus pagos con Google Play</h2>
    <ul class="lista"><?php foreach ($compras as $c): ?>
        <li><span><?= e($nombres[$c['plan_codigo']] ?? $c['plan_codigo']) ?> · <?= e($c['periodo']) ?><br><small><?= in_array($c['estado'], TuSalon\GooglePlay::ACTIVOS, true) ? ($c['renueva_sola'] ? 'Se renueva el ' : 'Termina el ') : 'Terminó el ' ?><?= $c['expira'] ? (new DateTimeImmutable($c['expira']))->format('d/m/Y') : '' ?></small></span>
            <span class="etq <?= in_array($c['estado'], TuSalon\GooglePlay::ACTIVOS, true) ? 'etq-confirmada' : 'etq-reservada' ?>"><?= in_array($c['estado'], TuSalon\GooglePlay::ACTIVOS, true) ? 'Activo' : 'Terminado' ?></span></li>
    <?php endforeach; ?></ul>
</section>
<?php endif; ?>
<script src="/assets/play.js?v=1" defer></script>
<?php endif; ?>
