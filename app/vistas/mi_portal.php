<?php
use TuSalon\Agenda;
$fmtPct = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
$teToca = function (array $s) use ($yo, $fmtPct): string {
    return match ($yo['tipo']) {
        'empleado'   => $s['pago_profesional'] !== null ? dinero($s['pago_profesional'])
                        : dinero((float) $s['precio'] * (float) $yo['comision_servicio_pct'] / 100) . ' <small>(' . $fmtPct($yo['comision_servicio_pct']) . ' %)</small>',
        'porcentaje' => dinero((float) $s['precio'] * (float) $yo['pct_profesional'] / 100) . ' <small>(' . $fmtPct($yo['pct_profesional']) . ' %)</small>',
        'alquiler'   => dinero($s['precio']) . ' <small>(todo)</small>',
        default      => '—',
    };
};
$nombre = explode(' ', trim($yo['nombre']))[0];
$textoSaldo = $saldo > 0 ? 'El local te debe' : ($saldo < 0 ? 'Le debes al local' : 'Estás al día con el local');
$diaAnterior = '';
?>
<div class="cabecera">
    <div>
        <h1>Hola, <?= e($nombre) ?></h1>
        <p><?= e($u['salon']) ?></p>
    </div>
    <nav class="acciones" aria-label="Periodo">
        <?php foreach ($periodos as $k => $txt): ?>
            <a class="boton boton-chico <?= $k === $periodo ? '' : 'boton-claro' ?>" href="<?= e(url('mi_portal', ['p' => $k])) ?>"
               <?= $k === $periodo ? 'aria-current="page"' : '' ?>><?= $txt ?></a>
        <?php endforeach; ?>
    </nav>
</div>

<div class="rejilla rejilla-3">
    <div class="bloque">
        <div class="cifra"><?= dinero($ganado) ?></div>
        <p class="cifra-nombre"><?= $yo['tipo'] === 'alquiler' ? 'Tus ingresos' : 'Lo que ganaste' ?> · <?= strtolower($periodos[$periodo]) ?></p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= count($hechos) ?></div>
        <p class="cifra-nombre">Servicios hechos<?= $propinas > 0 ? ' · propinas ' . dinero($propinas) : '' ?></p>
    </div>
    <div class="bloque">
        <div class="cifra <?= $saldo < 0 ? 'monto-neg' : '' ?>"><?= dinero(abs($saldo)) ?></div>
        <p class="cifra-nombre"><?= $textoSaldo ?></p>
    </div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tus próximas citas</h2>
        <?php if (!$proximas): ?>
            <p class="vacio">No tienes citas en los próximos 7 días.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($proximas as $c):
                    $ini = new DateTimeImmutable($c['inicio']);
                    $dia = $ini->format('Y-m-d') === date('Y-m-d') ? 'Hoy' : Agenda::DIAS[(int) $ini->format('w')] . ' ' . $ini->format('j/n'); ?>
                    <?php if ($dia !== $diaAnterior): $diaAnterior = $dia; ?>
                        <li style="justify-content:flex-start"><strong><?= e($dia) ?></strong></li>
                    <?php endif; ?>
                    <li>
                        <span class="hora"><?= $ini->format('H:i') ?></span>
                        <div style="flex:1">
                            <div class="principal-linea"><?= e($c['cliente'] ?: 'Sin cliente') ?></div>
                            <div class="linea-sub"><?= e($c['servicios']) ?></div>
                        </div>
                        <?php if ($c['estado'] === 'pendiente'): ?>
                            <a class="etq etq-pendiente" href="<?= e(url('solicitudes')) ?>">Por aceptar</a>
                        <?php else: ?>
                            <span class="etq etq-<?= e($c['estado']) ?>"><?= $c['estado'] === 'confirmada' ? 'Confirmada' : 'Reservada' ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloque">
        <h2>Lo que hiciste · <?= strtolower($periodos[$periodo]) ?></h2>
        <?php if (!$hechos): ?>
            <p class="vacio">Todavía no hay servicios cobrados en este periodo.</p>
        <?php else: ?>
            <div class="desliza">
            <table class="tabla">
                <thead><tr><th>Servicio</th><th class="der">Cobrado</th><th class="der">Te toca</th></tr></thead>
                <tbody>
                <?php foreach ($hechos as $h): ?>
                    <tr>
                        <td><strong><?= e($h['servicio']) ?></strong><br>
                            <small><?= (new DateTimeImmutable($h['fecha']))->format('d/m H:i') ?><?= $h['cliente'] ? ' · ' . e($h['cliente']) : '' ?></small></td>
                        <td class="der"><?= dinero($h['subtotal']) ?></td>
                        <td class="der"><strong><?= dinero($h['ganancia_profesional']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<section class="bloque">
    <h2>Tus servicios y lo que te toca</h2>
    <div class="desliza">
    <table class="tabla">
        <thead><tr><th>Servicio</th><th class="der">Precio</th><th class="der">Te toca</th></tr></thead>
        <tbody>
        <?php foreach ($catalogo as $s): ?>
            <tr><td><?= e($s['nombre']) ?> <small>· <?= (int) $s['duracion_minutos'] ?> min</small></td>
                <td class="der"><?= dinero($s['precio']) ?></td>
                <td class="der"><?= $teToca($s) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ($yo['tipo'] === 'alquiler'): ?>
        <p class="suave" style="margin-top:10px">Tu arriendo: <?= dinero($yo['monto_arriendo']) ?> <?= ['semanal' => 'por semana', 'quincenal' => 'por quincena', 'mensual' => 'al mes'][$yo['frecuencia_arriendo']] ?? '' ?>.</p>
    <?php endif; ?>
</section>
