<?php
$f = new DateTimeImmutable($fecha);
$dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
$esHoy = $fecha === date('Y-m-d');
$altoHora = 72;
$tipos = ['dueno' => 'Dueño', 'empleado' => 'Empleado', 'porcentaje' => 'Porcentaje', 'alquiler' => 'Alquila'];
$colores = ['dueno' => '#E9B23C', 'empleado' => '#1E5A4C', 'porcentaje' => '#3A6BB0', 'alquiler' => '#8A4F94'];
?>
<div class="cabecera">
    <h1>Agenda</h1>
    <form class="agenda-nav" method="get">
        <input type="hidden" name="r" value="agenda">
        <a class="boton boton-claro boton-chico" href="<?= e(url('agenda', ['fecha' => $f->modify('-1 day')->format('Y-m-d')])) ?>" aria-label="Día anterior">‹</a>
        <label class="oculto" for="fecha">Fecha</label>
        <input type="date" id="fecha" name="fecha" value="<?= e($fecha) ?>" onchange="this.form.submit()">
        <a class="boton boton-claro boton-chico" href="<?= e(url('agenda', ['fecha' => $f->modify('+1 day')->format('Y-m-d')])) ?>" aria-label="Día siguiente">›</a>
        <?php if (!$esHoy): ?><a class="boton boton-claro boton-chico" href="<?= e(url('agenda')) ?>">Hoy</a><?php endif; ?>
        <a class="boton boton-chico" href="<?= e(url('cita_nueva', ['fecha' => $fecha])) ?>">Nueva cita</a>
    </form>
</div>
<p class="suave"><?= $dias[(int) $f->format('w')] ?> <?= $f->format('j/n/Y') ?> · Toca un espacio libre para agendar.</p>

<div class="agenda" style="--sillas: <?= count($sillas) ?>; --alto-hora: <?= $altoHora ?>px"
     data-hoy="<?= $esHoy ? '1' : '0' ?>" data-hora-inicio="<?= $horaInicio ?>" data-hora-fin="<?= $horaFin ?>">
    <div class="esquina"></div>
    <?php foreach ($sillas as $s): ?>
        <div class="silla-cabeza">
            <span class="franja-color" style="background: <?= $colores[$s['tipo']] ?>"></span>
            <strong><?= e($s['nombre']) ?></strong>
            <span class="etq etq-<?= e($s['tipo']) ?>"><?= $tipos[$s['tipo']] ?></span>
        </div>
    <?php endforeach; ?>

    <div class="horas" aria-hidden="true">
        <?php for ($h = $horaInicio; $h < $horaFin; $h++): ?><div><?= sprintf('%02d:00', $h) ?></div><?php endfor; ?>
    </div>
    <?php foreach ($sillas as $s): ?>
        <div class="columna" aria-label="Agenda de <?= e($s['nombre']) ?>">
            <?php for ($m = $horaInicio * 60; $m < $horaFin * 60; $m += 30):
                $hora = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
                <a class="hueco" href="<?= e(url('cita_nueva', ['fecha' => $fecha, 'hora' => $hora, 'profesional' => $s['id']])) ?>"
                   aria-label="Agendar con <?= e($s['nombre']) ?> a las <?= $hora ?>"></a>
            <?php endfor; ?>
            <?php foreach ($porSilla[$s['id']] ?? [] as $c):
                $ini = new DateTimeImmutable($c['inicio']);
                $fin = new DateTimeImmutable($c['fin']);
                $top = (((int) $ini->format('G') * 60 + (int) $ini->format('i')) - $horaInicio * 60) / 60 * $altoHora;
                $alto = max(28, ($fin->getTimestamp() - $ini->getTimestamp()) / 3600 * $altoHora - 2);
            ?>
                <a class="turno <?= e($c['estado']) ?>" href="<?= e(url('cita', ['id' => $c['id']])) ?>"
                   style="top: <?= round($top, 1) ?>px; height: <?= round($alto, 1) ?>px"
                   title="<?= e($ini->format('H:i') . ' ' . ($c['cliente'] ?: 'Sin cliente') . ' · ' . $c['servicios']) ?>">
                    <?php if ($alto < 44): ?>
                        <strong><?= $ini->format('H:i') ?> <?= e($c['cliente'] ?: 'Sin cliente') ?> · <?= e($c['servicios']) ?></strong>
                    <?php else: ?>
                        <strong><?= $ini->format('H:i') ?> <?= e($c['cliente'] ?: 'Sin cliente') ?></strong>
                        <?= e($c['servicios']) ?>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
