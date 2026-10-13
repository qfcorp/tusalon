<?php
$wa = fn(string $plan, string $periodo) => 'https://wa.me/' . whatsapp_ventas() . '?text=' . rawurlencode(
    "Hola, quiero contratar TuSalón plan $plan ($periodo) para mi salón \"{$u['salon']}\" ({$u['email']}).");
$filas = ['mensual' => planes()->textoPeriodo('mensual'), 'semestral' => planes()->textoPeriodo('semestral'), 'anual' => planes()->textoPeriodo('anual')];
$completa = [
    'Personas ilimitadas y varias sucursales',
    'Reservas en línea con enlace y código QR',
    'Recordatorios por WhatsApp con un toque y avisos automáticos por Telegram',
    'Anticipo en línea para que el cliente no falte',
    'Comisiones por servicio y producto, escalas por meta, propinas, anticipos',
    'Rol de pagos con IESS y décimos',
    'Cobro automático del arriendo y reparto del porcentaje',
    'Clientes privados para quien alquila el puesto',
    'Ficha técnica de color con fotos antes y después',
    'Inventario, paquetes, tarjetas de regalo y puntos',
    'Gastos con las facturas que recibes del SRI y ganancia real',
    'App para que cada peluquero vea su agenda y sus comisiones',
];
?>
<?php if (en_app_play()):
    $gp = new TuSalon\GooglePlay(db());
    $compras = $gp->deSalon((int) $u['salon_id']);
    $nombres = ['basica' => 'Básica', 'completa' => 'Completa'];
    $resumenes = ['basica' => 'Agenda, clientes, caja diaria y cuentas del equipo, para hasta 3 personas.',
                  'completa' => 'Todo, sin límite de personas: reservas en línea, anticipo, paquetes, tarjetas de regalo, puntos, ficha de color, inventario y reporte del mes.'];
?>
<div class="cabecera">
    <div>
        <h1>Planes de TuSalón</h1>
        <p>Tu plan actual: <strong><?= $u['plan_codigo'] === 'completa' ? 'Completa' : 'Básica' ?></strong><?= $u['estado'] === 'prueba' ? ' (en prueba gratis)' : '' ?><?= !empty($u['activo_hasta']) ? ' · pagado hasta el ' . (new DateTimeImmutable($u['activo_hasta']))->format('d/m/Y') : '' ?></p>
    </div>
</div>
<div id="pago-play" data-csrf="<?= e(token_csrf()) ?>" data-volver="<?= e(url('inicio')) ?>">
    <p class="aviso" id="play-estado" role="status">Cargando los precios de Google Play…</p>
    <div class="rejilla rejilla-2">
        <?php foreach (['basica', 'completa'] as $plan): ?>
        <section class="bloque" style="<?= $plan === 'completa' ? 'border-color:var(--verde);box-shadow:0 0 0 2px var(--verde-claro)' : '' ?>">
            <h2><?= $nombres[$plan] ?></h2>
            <p><?= e($resumenes[$plan]) ?></p>
            <div class="opciones">
                <?php foreach (['mensual' => 'Cada mes', 'semestral' => 'Cada 6 meses', 'anual' => 'Cada año'] as $per => $txt):
                    $prod = TuSalon\GooglePlay::producto($plan, $per); ?>
                    <button type="button" class="opcion boton-play" data-producto="<?= e($prod) ?>" disabled style="justify-content:space-between;width:100%;text-align:left;cursor:pointer">
                        <span><strong><?= $txt ?></strong></span><span class="monto" data-precio="<?= e($prod) ?>">—</span>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    <p class="suave">El pago lo hace Google Play con tu cuenta de Google. Se renueva solo; lo cancelas cuando quieras en Play Store → Pagos y suscripciones.</p>
</div>
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
<?php return; endif; ?>
<div class="cabecera">
    <div>
        <h1>Planes de TuSalón</h1>
        <p>Tu plan actual: <strong><?= $u['plan_codigo'] === 'completa' ? 'Completa' : 'Básica' ?></strong><?= $u['estado'] === 'prueba' ? ' (en prueba gratis)' : '' ?></p>
    </div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Básica · <?= dinero($precios['basica']['mensual']['monto']) ?> al mes</h2>
        <p>Agenda, clientes, caja diaria y cuentas del equipo para hasta 3 personas. Recordatorios por WhatsApp con un toque.</p>
        <table class="tabla">
            <?php foreach ($filas as $per => $txt): $c = $precios['basica'][$per]; ?>
                <tr><td><?= $txt ?></td><td class="der"><strong><?= dinero($c['monto']) ?></strong><br><small><?= dinero($c['precio_por_mes']) ?> al mes</small></td>
                    <td class="der"><a class="boton boton-claro boton-chico" href="<?= e($wa('Básica', $txt)) ?>" target="_blank" rel="noopener">Contratar</a></td></tr>
            <?php endforeach; ?>
        </table>
    </section>

    <section class="bloque" style="border-color:var(--verde);box-shadow:0 0 0 2px var(--verde-claro)">
        <h2>Completa · <?= dinero($precios['completa']['mensual']['monto']) ?> al mes</h2>
        <p>Todo lo de Básica, más:</p>
        <ul style="margin:0 0 12px;padding-left:20px">
            <?php foreach ($completa as $item): ?><li><?= e($item) ?></li><?php endforeach; ?>
        </ul>
        <table class="tabla">
            <?php foreach ($filas as $per => $txt): $c = $precios['completa'][$per]; ?>
                <tr><td><?= $txt ?></td><td class="der"><strong><?= dinero($c['monto']) ?></strong><br><small><?= dinero($c['precio_por_mes']) ?> al mes</small></td>
                    <td class="der"><a class="boton boton-chico" href="<?= e($wa('Completa', $txt)) ?>" target="_blank" rel="noopener">Contratar</a></td></tr>
            <?php endforeach; ?>
        </table>
    </section>
</div>
<p class="suave">Pagas por transferencia, DeUna o tarjeta. Te activamos el plan el mismo día.</p>
