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
        <?php if (es_recepcion($u)): ?>
            <?php if (modulo('asistente_ia')): ?><a class="boton boton-claro solo-celular" href="<?= e(url('asistente')) ?>">✨ Asistente IA</a><?php endif; ?>
            <a class="boton boton-claro solo-celular" href="<?= e(url('ayuda')) ?>">Ayuda</a>
            <a class="boton boton-claro solo-celular" href="<?= e(url('salir')) ?>">Salir</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($solicitudes > 0): ?>
    <a class="aviso franja-solicitudes" href="<?= e(url('solicitudes')) ?>">
        Tienes <?= $solicitudes ?> <?= $solicitudes === 1 ? 'solicitud' : 'solicitudes' ?> de cita por aceptar. Ver ahora
    </a>
<?php endif; ?>

<?php if ($comprobantes > 0): ?>
    <a class="aviso franja-solicitudes" href="<?= e(url('solicitudes')) ?>">
        💳 <?= $comprobantes ?> <?= $comprobantes === 1 ? 'comprobante de anticipo' : 'comprobantes de anticipo' ?> por revisar. Ver ahora
    </a>
<?php endif; ?>
<?php if ($stockBajo): ?>
    <a class="aviso franja-avisos" href="<?= e(url('inventario')) ?>">
        📦 Se está acabando: <?= e(implode(', ', array_slice(array_map(fn($b) => "{$b['nombre']} ({$b['stock']})", $stockBajo), 0, 3))) ?><?= count($stockBajo) > 3 ? ' y ' . (count($stockBajo) - 3) . ' más' : '' ?> · Ver
    </a>
<?php endif; ?>

<div class="instalar-app" data-instalar-app hidden>
    <img src="/assets/icono-192.png" alt="" width="48" height="48">
    <div style="flex:1"><p><strong>Instala TuSalón en tu celular</strong></p>
        <p class="suave" style="font-size:.9rem">Ábrelo como una app, con su ícono en la pantalla.</p></div>
    <button class="boton boton-chico" type="button" data-instalar hidden>Instalar</button>
</div>

<?php if ($porRecordar > 0 || $cumpleHoy > 0): ?>
    <a class="aviso franja-avisos" href="<?= e(url('avisos_clientes')) ?>">
        <?= $porRecordar > 0 ? "⏰ $porRecordar " . ($porRecordar === 1 ? 'cita de mañana por recordar' : 'citas de mañana por recordar') : '' ?>
        <?= $porRecordar > 0 && $cumpleHoy > 0 ? ' · ' : '' ?>
        <?= $cumpleHoy > 0 ? "🎂 $cumpleHoy " . ($cumpleHoy === 1 ? 'cliente cumple años hoy' : 'clientes cumplen años hoy') : '' ?>
        · Ver
    </a>
<?php endif; ?>

<div class="rejilla rejilla-3">
    <div class="bloque">
        <div class="cifra"><?= dinero($caja['cobrado_local']) ?></div>
        <p class="cifra-nombre">Entró a la caja hoy</p>
    </div>
    <div class="bloque">
        <div class="cifra"><?= count($pendientes) ?></div>
        <p class="cifra-nombre">Citas por atender · <?= $atendidas ?> atendidas</p>
    </div>
    <?php if ($resumen === null): /* recepción: no ve ganancias */ ?>
    <div class="bloque">
        <div class="cifra"><?= $solicitudes ?></div>
        <p class="cifra-nombre">Solicitudes por aceptar</p>
    </div>
    <?php else: ?>
    <div class="bloque">
        <div class="cifra"><?= dinero($resumen['ganancia_local']) ?></div>
        <p class="cifra-nombre">Ganancia del local hoy</p>
    </div>
    <?php endif; ?>
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

    <?php if ($resumen !== null): ?>
    <section class="bloque">
        <h2>Tu día como dueño</h2>
        <ul class="lista">
            <li><span>Lo que produjiste en tu silla</span><span class="monto"><?= dinero($resumen['produccion_dueno']) ?></span></li>
            <li><span>Entró a la caja del local</span><span class="monto"><?= dinero($resumen['entro_a_caja']) ?></span></li>
            <li><span>Ganancia del local</span><span class="monto"><?= dinero($resumen['ganancia_local']) ?></span></li>
            <?php if (modulo('gastos_sri')): ?>
                <li><a href="<?= e(url('gastos')) ?>">Gastos (facturas recibidas)</a><span class="monto monto-neg"><?= dinero(-$resumen['gastos_facturas']) ?></span></li>
                <li><strong>Ganancia después de gastos</strong><strong class="monto"><?= dinero($resumen['ganancia_despues_de_gastos']) ?></strong></li>
            <?php else: ?>
                <li><span class="candado">Gastos y ganancia real con tus facturas</span><a href="<?= e(url('completa', ['modulo' => 'gastos_sri'])) ?>">Ver Mi plan</a></li>
            <?php endif; ?>
        </ul>
        <?php if (modulo('gastos_sri') && (float) $resumen['gastos_facturas'] == 0.0): ?>
            <p class="suave" style="margin-top:8px;font-size:.9rem">¿Te llegaron facturas de tus proveedores? <a href="<?= e(url('gastos')) ?>">Súbelas del SRI</a> para ver tu ganancia real.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>
