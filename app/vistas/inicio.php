<?php
use TuSalon\WhatsApp;
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$nombre = explode(' ', trim($u['nombre']))[0];
?>
<div class="cabecera">
    <div>
        <h1>Hola, <?= e($nombre) ?></h1>
        <p><?= ucfirst($dias[(int) date('w')]) ?> <?= (int) date('j') ?> de <?= $meses[(int) date('n')] ?></p>
    </div>
    <div class="acciones">
        <a class="boton" href="<?= e(url('cita_nueva')) ?>">Nueva cita</a>
        <a class="boton boton-claro" href="<?= e(url('cobrar')) ?>">Cobrar</a>
    </div>
</div>

<div class="rejilla rejilla-3">
    <div class="bloque">
        <div class="cifra"><?= dinero($caja['cobrado_local']) ?></div>
        <p class="cifra-nombre">Entró a la caja hoy</p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= count($pendientes) ?></div>
        <p class="cifra-nombre">Citas por atender · <?= $atendidas ?> atendidas</p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= dinero($resumen['ganancia_local']) ?></div>
        <p class="cifra-nombre">Ganancia del local hoy</p>
    </div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Próximas citas</h2>
        <?php if (!$pendientes): ?>
            <p class="vacio">No hay citas pendientes hoy.<br><a href="<?= e(url('cita_nueva')) ?>">Agendar una cita</a></p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach (array_slice($pendientes, 0, 8) as $c):
                    $ini = new DateTimeImmutable($c['inicio']);
                    $wa = WhatsApp::enlace($c['telefono'], WhatsApp::recordatorio($c['cliente'] ?: 'cliente', $u['salon'], $ini, $c['profesional']));
                ?>
                <li>
                    <span class="hora"><?= $ini->format('H:i') ?></span>
                    <a href="<?= e(url('cita', ['id' => $c['id']])) ?>" style="flex:1;text-decoration:none;color:inherit">
                        <div class="principal-linea"><?= e($c['cliente'] ?: 'Sin cliente') ?></div>
                        <div class="linea-sub"><?= e($c['servicios']) ?> · <?= e($c['profesional']) ?></div>
                    </a>
                    <?php if ($wa): ?>
                        <a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Recordar por WhatsApp a <?= e($c['cliente']) ?>">WhatsApp</a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="bloque">
        <h2>Tu día como dueño</h2>
        <ul class="lista">
            <li><span>Lo que produjiste en tu silla</span><span class="monto"><?= dinero($resumen['produccion_dueno']) ?></span></li>
            <li><span>Entró a la caja del local</span><span class="monto"><?= dinero($resumen['entro_a_caja']) ?></span></li>
            <li><span>Ganancia del local</span><span class="monto"><?= dinero($resumen['ganancia_local']) ?></span></li>
            <?php if (es_completa($u)): ?>
                <li><span>Gastos (facturas recibidas)</span><span class="monto monto-neg"><?= dinero(-$resumen['gastos_facturas']) ?></span></li>
                <li><strong>Ganancia después de gastos</strong><strong class="monto"><?= dinero($resumen['ganancia_despues_de_gastos']) ?></strong></li>
            <?php else: ?>
                <li><span class="candado">Gastos y ganancia real con tus facturas</span><a href="<?= e(url('completa')) ?>">Plan Completa</a></li>
            <?php endif; ?>
        </ul>
    </section>
</div>
