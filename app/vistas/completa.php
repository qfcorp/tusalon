<?php
$wa = fn(string $plan, string $periodo) => 'https://wa.me/' . WHATSAPP_VENTAS . '?text=' . rawurlencode(
    "Hola, quiero contratar TuSalón plan $plan ($periodo) para mi salón \"{$u['salon']}\" ({$u['email']}).");
$filas = ['mensual' => 'Mensual', 'semestral' => 'Paga 5, recibe 6', 'anual' => 'Paga 10, recibe 12 + dominio propio'];
$completa = [
    'Personas ilimitadas y varias sucursales',
    'Reservas en línea con enlace y código QR',
    'Recordatorios automáticos por WhatsApp (confirmación, cumpleaños, gracias)',
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
