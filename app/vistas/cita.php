<?php
use TuSalon\WhatsApp;
$ini = new DateTimeImmutable($cita['inicio']);
$fin = new DateTimeImmutable($cita['fin']);
$total = array_sum(array_map(fn($s) => (float) $s['precio'], $cita['lista_servicios']));
$wa = WhatsApp::enlace($cita['telefono'], WhatsApp::recordatorio($cita['cliente'] ?: 'cliente', $u['salon'], $ini, $cita['profesional']));
$estados = ['pendiente' => 'Solicitud por aceptar', 'reservada' => 'Reservada', 'confirmada' => 'Confirmada',
            'atendida' => 'Atendida y cobrada', 'no_asistio' => 'No asistió', 'cancelada' => 'Cancelada', 'rechazada' => 'Rechazada'];
$abierta = in_array($cita['estado'], ['reservada', 'confirmada'], true);
$boton = fn(string $estado, string $texto, string $clase = 'boton-claro', string $confirmar = '') =>
    '<form method="post" style="display:inline">' . campo_csrf()
    . '<input type="hidden" name="estado" value="' . $estado . '">'
    . '<button class="boton ' . $clase . '" type="submit"' . ($confirmar ? ' data-confirmar="' . e($confirmar) . '"' : '') . '>' . $texto . '</button></form>';
?>
<div class="cabecera">
    <div>
        <h1><?= e($cita['cliente'] ?: 'Cita sin cliente') ?></h1>
        <p><?= $ini->format('d/m/Y') ?>, <?= $ini->format('H:i') ?> a <?= $fin->format('H:i') ?> · con <?= e($cita['profesional']) ?></p>
    </div>
    <span class="etq etq-<?= e($cita['estado']) ?>"><?= $estados[$cita['estado']] ?></span>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Servicios</h2>
        <ul class="lista">
            <?php foreach ($cita['lista_servicios'] as $s): ?>
                <li><span><?= e($s['nombre']) ?><?php if ((float) $s['precio'] !== (float) $s['precio_lista']): ?><br><small>Precio normal <?= dinero($s['precio_lista']) ?></small><?php endif; ?></span><span class="monto"><?= dinero($s['precio']) ?></span></li>
            <?php endforeach; ?>
            <li><strong>Total</strong><strong class="monto"><?= dinero($total) ?></strong></li>
        </ul>
        <?php if ($cita['notas']): ?><p class="suave">Notas: <?= e($cita['notas']) ?></p><?php endif; ?>
    </section>

    <section class="bloque">
        <h2>Qué hacer</h2>
        <div class="acciones" style="flex-direction:column;align-items:stretch">
            <?php if ($cita['estado'] === 'pendiente'): ?>
                <p>El cliente la pidió en línea<?= $cita['expira_en'] ? '. Si nadie responde, la hora se libera a las ' . (new DateTimeImmutable($cita['expira_en']))->format('H:i') : '' ?>.</p>
                <form method="post" action="<?= e(url('solicitudes')) ?>"><?= campo_csrf() ?><input type="hidden" name="cita" value="<?= (int) $cita['id'] ?>">
                    <button class="boton boton-ancho" name="accion" value="aceptar" type="submit">Aceptar cita</button></form>
                <form method="post" action="<?= e(url('solicitudes')) ?>"><?= campo_csrf() ?><input type="hidden" name="cita" value="<?= (int) $cita['id'] ?>">
                    <button class="boton boton-peligro boton-ancho" name="accion" value="rechazar" type="submit" data-confirmar="¿Rechazar esta solicitud?">Rechazar</button></form>
            <?php elseif ($abierta): ?>
                <a class="boton" href="<?= e(url('cobrar', ['cita' => $cita['id']])) ?>">Cobrar <?= dinero($total) ?></a>
                <?php if ($wa): ?>
                    <a class="boton boton-wa" href="<?= e($wa) ?>" target="_blank" rel="noopener">Enviar recordatorio por WhatsApp</a>
                <?php else: ?>
                    <p class="suave">Este cliente no tiene celular. Agrégalo en <a href="<?= e(url('clientes')) ?>">Clientes</a> para mandarle recordatorios.</p>
                <?php endif; ?>
                <?php if ($cita['estado'] === 'reservada') echo $boton('confirmada', 'Marcar como confirmada'); ?>
                <?= $boton('no_asistio', 'No asistió', 'boton-peligro', '¿Marcar que el cliente no llegó?') ?>
                <?= $boton('cancelada', 'Cancelar cita', 'boton-peligro', '¿Cancelar esta cita?') ?>
            <?php elseif ($cita['estado'] === 'atendida'): ?>
                <p>Esta cita ya se cobró. Puedes verla en <a href="<?= e(url('caja', ['fecha' => $ini->format('Y-m-d')])) ?>">la caja de ese día</a>.</p>
            <?php else: ?>
                <?= $boton('reservada', 'Volver a abrir la cita') ?>
            <?php endif; ?>
            <a class="boton boton-claro" href="<?= e(url('agenda', ['fecha' => $ini->format('Y-m-d')])) ?>">Ver la agenda del día</a>
        </div>
    </section>
</div>
