<?php
/**
 * Inicio (dashboard) con la identidad gráfica común de Tukán (10 nov 2026), en este orden: saludo, avisos (.tk-tira),
 * primeros pasos, cifras, tarjetas del día y accesos rápidos. Ver docs/LINEA_GRAFICA.md.
 */
use TuSalon\WhatsApp;
$dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$nombre = explode(' ', trim($u['nombre']))[0];
$h = (int) date('G');   // hora de Ecuador (la app corre en America/Guayaquil)
[$emoji, $saludo] = $h >= 5 && $h < 12 ? ['☀️', 'Buenos días'] : ($h >= 12 && $h < 19 ? ['🌤️', 'Buenas tardes'] : ['🌙', 'Buenas noches']);
$hechos = count(array_filter(array_column($pasos, 2)));
?>
<div class="tk-saludo">
    <h1><?= $emoji ?> <?= $saludo ?>, <?= e($nombre) ?> 👋</h1>
    <p><?= ucfirst($dias[(int) date('w')]) ?> <?= (int) date('j') ?> de <?= $meses[(int) date('n')] ?> · Tus citas, la caja y lo que tienes que hacer hoy</p>
</div>

<?php require __DIR__ . '/_tiras.php'; ?>
<?php if ($solicitudes > 0): ?>
    <div class="tk-tira"><span>🔔 Tienes <?= $solicitudes ?> <?= $solicitudes === 1 ? 'solicitud' : 'solicitudes' ?> de cita por aceptar.</span>
        <a class="tk-boton chico" href="<?= e(url('solicitudes')) ?>">Ver ahora</a></div>
<?php endif; ?>
<?php if (($u['estado'] ?? '') === 'prueba') echo TukanDemos::tarjetaDemo($u['prueba_hasta'] ?? null);   // mientras la cuenta es demo: hasta cuándo dura y los dos PDF ?>

<?php if ($pasos): ?>
<section class="tk-pasos" aria-labelledby="titulo-pasos">
    <div class="tk-tarjeta-cabeza">
        <div><h2 id="titulo-pasos">🚀 Primeros pasos</h2><p>Completa estos pasos y tu salón queda listo (<?= $hechos ?> de <?= count($pasos) ?>)</p></div>
        <form method="post" data-sin-ocupado><?= campo_csrf() ?><input type="hidden" name="accion" value="ocultar_pasos">
            <button class="tk-boton secundario chico" type="submit">Ocultar</button></form>
    </div>
    <div class="tk-barra" role="progressbar" aria-valuemin="0" aria-valuemax="<?= count($pasos) ?>" aria-valuenow="<?= $hechos ?>"><i style="width:<?= round($hechos * 100 / count($pasos)) ?>%"></i></div>
    <ol>
        <?php foreach ($pasos as $i => [$texto, $enlace, $hecho]): ?>
            <li<?= $hecho ? ' class="hecho"' : '' ?>><span class="tk-check"><?= $hecho ? '✓' : $i + 1 ?></span><span class="tk-paso-texto"><?= e($texto) ?></span>
                <?php if (!$hecho): ?><a class="tk-boton chico" href="<?= e($enlace) ?>">Hacerlo</a><?php endif; ?></li>
        <?php endforeach; ?>
    </ol>
</section>
<?php endif; ?>

<div class="tk-cifras">
    <div class="tk-cifra ok"><div class="tk-cifra-titulo">Entró a la caja hoy</div><div class="tk-cifra-valor"><?= dinero($caja['cobrado_local']) ?></div><div class="tk-cifra-detalle">Cobros del local de hoy</div></div>
    <div class="tk-cifra programa"><div class="tk-cifra-titulo">Citas por atender</div><div class="tk-cifra-valor"><?= count($pendientes) ?></div><div class="tk-cifra-detalle"><?= $atendidas ?> atendidas hoy</div></div>
    <div class="tk-cifra <?= $solicitudes > 0 ? 'aviso' : '' ?>"><div class="tk-cifra-titulo">Solicitudes por aceptar</div><div class="tk-cifra-valor"><?= $solicitudes ?></div><div class="tk-cifra-detalle">Citas pedidas en línea</div></div>
    <?php if ($resumen !== null): /* recepción: no ve ganancias */ ?>
    <div class="tk-cifra"><div class="tk-cifra-titulo">Ganancia del local hoy</div><div class="tk-cifra-valor"><?= dinero($resumen['ganancia_local']) ?></div><div class="tk-cifra-detalle">Después de lo del equipo</div></div>
    <?php endif; ?>
</div>

<div class="tk-columnas">
    <section class="tk-tarjeta">
        <div class="tk-tarjeta-cabeza"><div><h2>📅 Próximas citas</h2><p>Las citas de hoy que faltan por atender</p></div>
            <a class="tk-boton chico" href="<?= e(url('agenda')) ?>">Ver la agenda</a></div>
        <?php if (!$pendientes): ?>
            <div class="tk-vacio"><b>No hay citas pendientes hoy.</b>Cuando agendes una cita, aparece aquí.<br><br>
                <a class="tk-boton" href="<?= e(url('cita_nueva')) ?>">＋ Agendar una cita</a></div>
        <?php else: ?>
            <div class="tk-tabla-caja"><table class="tk-tabla tarjetas">
                <thead><tr><th>Hora</th><th>Cliente</th><th>Peluquero</th><th>Acción</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($pendientes, 0, 8) as $c):
                    $ini = new DateTimeImmutable($c['inicio']);
                    $wa = WhatsApp::enlace($c['telefono'], WhatsApp::recordatorio($c['cliente'] ?: 'cliente', $u['salon'], $ini, $c['profesional']));
                ?>
                <tr>
                    <td data-titulo="Hora" class="num"><?= $ini->format('H:i') ?></td>
                    <td data-titulo="Cliente"><a href="<?= e(url('cita', ['id' => $c['id']])) ?>"><?= e($c['cliente'] ?: 'Sin cliente') ?></a><small><?= e($c['servicios']) ?></small></td>
                    <td data-titulo="Peluquero"><?= e($c['profesional']) ?></td>
                    <td data-titulo="Acción"><?php if ($wa): ?><a class="tk-boton ok chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="Recordar por WhatsApp a <?= e($c['cliente']) ?>">💬 WhatsApp</a><?php else: ?><a class="tk-boton secundario chico" href="<?= e(url('cita', ['id' => $c['id']])) ?>">Abrir</a><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php $hayPendientes = $comprobantes > 0 || $stockBajo || $porRecordar > 0 || $cumpleHoy > 0; ?>
    <section class="tk-tarjeta <?= $hayPendientes ? 'aviso' : 'ok' ?>">
        <div class="tk-tarjeta-cabeza"><div><h2>🔔 Pendientes de hoy</h2><p>Lo que conviene revisar antes de que se te pase</p></div>
            <a class="tk-boton chico" href="<?= e(url('avisos_clientes')) ?>">Ver avisos a clientes</a></div>
        <?php if (!$hayPendientes): ?>
            <div class="tk-vacio"><b>Todo al día. 👍</b>Aquí te avisamos de comprobantes por revisar, productos que se acaban, citas por recordar y cumpleaños.</div>
        <?php else: ?>
            <div class="tk-tabla-caja"><table class="tk-tabla tarjetas">
                <thead><tr><th>Pendiente</th><th>Acción</th></tr></thead>
                <tbody>
                <?php if ($comprobantes > 0): ?>
                    <tr><td data-titulo="Pendiente">💳 <?= $comprobantes ?> <?= $comprobantes === 1 ? 'comprobante de anticipo' : 'comprobantes de anticipo' ?> por revisar.</td>
                        <td data-titulo="Acción"><a class="tk-boton chico" href="<?= e(url('solicitudes')) ?>">Ver ahora</a></td></tr>
                <?php endif; ?>
                <?php if ($stockBajo): ?>
                    <tr><td data-titulo="Pendiente">📦 Se está acabando: <?= e(implode(', ', array_slice(array_map(fn($b) => "{$b['nombre']} ({$b['stock']})", $stockBajo), 0, 3))) ?><?= count($stockBajo) > 3 ? ' y ' . (count($stockBajo) - 3) . ' más' : '' ?></td>
                        <td data-titulo="Acción"><a class="tk-boton secundario chico" href="<?= e(url('inventario')) ?>">Ver</a></td></tr>
                <?php endif; ?>
                <?php if ($porRecordar > 0): ?>
                    <tr><td data-titulo="Pendiente">⏰ <?= $porRecordar ?> <?= $porRecordar === 1 ? 'cita de mañana por recordar' : 'citas de mañana por recordar' ?></td>
                        <td data-titulo="Acción"><a class="tk-boton secundario chico" href="<?= e(url('avisos_clientes')) ?>">Ver</a></td></tr>
                <?php endif; ?>
                <?php if ($cumpleHoy > 0): ?>
                    <tr><td data-titulo="Pendiente">🎂 <?= $cumpleHoy ?> <?= $cumpleHoy === 1 ? 'cliente cumple años hoy' : 'clientes cumplen años hoy' ?></td>
                        <td data-titulo="Acción"><a class="tk-boton secundario chico" href="<?= e(url('avisos_clientes')) ?>">Ver</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <?php if ($resumen !== null): ?>
    <section class="tk-tarjeta info">
        <div class="tk-tarjeta-cabeza"><div><h2>💼 Tu día como dueño</h2><p>Lo que produjiste, lo que entró y lo que gana el local hoy</p></div>
            <?php if (modulo('reportes')): ?><a class="tk-boton chico" href="<?= e(url('reporte')) ?>">Ver el reporte del mes</a><?php else: ?><a class="tk-boton chico" href="<?= e(url('caja')) ?>">Ver la caja</a><?php endif; ?></div>
        <div class="tk-tabla-caja"><table class="tk-tabla tarjetas">
            <thead><tr><th>Concepto</th><th class="num">Valor</th></tr></thead>
            <tbody>
                <tr><td data-titulo="Concepto">Lo que produjiste en tu silla</td><td data-titulo="Valor" class="num tk-dinero"><?= dinero($resumen['produccion_dueno']) ?></td></tr>
                <tr><td data-titulo="Concepto">Entró a la caja del local</td><td data-titulo="Valor" class="num tk-dinero"><?= dinero($resumen['entro_a_caja']) ?></td></tr>
                <tr><td data-titulo="Concepto">Ganancia del local</td><td data-titulo="Valor" class="num tk-dinero ok"><?= dinero($resumen['ganancia_local']) ?></td></tr>
                <?php if (modulo('gastos_sri')): ?>
                    <tr><td data-titulo="Concepto"><a href="<?= e(url('gastos')) ?>">Gastos (facturas recibidas)</a></td><td data-titulo="Valor" class="num tk-dinero mal"><?= dinero(-$resumen['gastos_facturas']) ?></td></tr>
                    <tr><td data-titulo="Concepto"><strong>Ganancia después de gastos</strong></td><td data-titulo="Valor" class="num tk-dinero"><?= dinero($resumen['ganancia_despues_de_gastos']) ?></td></tr>
                <?php else: ?>
                    <tr><td data-titulo="Concepto"><span class="candado">Gastos y ganancia real con tus facturas</span></td><td data-titulo="Valor" class="num"><a class="tk-boton secundario chico" href="<?= e(url('completa', ['modulo' => 'gastos_sri'])) ?>">Ver Mi plan</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table></div>
        <?php if (modulo('gastos_sri') && (float) $resumen['gastos_facturas'] == 0.0): ?>
            <p class="suave" style="margin:10px 0 0;font-size:.9rem">¿Te llegaron facturas de tus proveedores? <a href="<?= e(url('gastos')) ?>">Súbelas del SRI</a> para ver tu ganancia real.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<div class="instalar-app" data-instalar-app hidden>
    <img src="/assets/icono-192.png" alt="" width="48" height="48">
    <div style="flex:1"><p><strong>Instala TuSalón en tu celular</strong></p>
        <p class="suave" style="font-size:.9rem">Ábrelo como una app, con su ícono en la pantalla.</p></div>
    <button class="tk-boton chico" type="button" data-instalar hidden>Instalar</button>
</div>

<section class="tk-tarjeta" aria-labelledby="titulo-accesos">
    <div class="tk-tarjeta-cabeza"><div><h2 id="titulo-accesos">⚡ Accesos rápidos</h2><p>Lo que más se usa en el salón</p></div></div>
    <div class="tk-acciones">
        <a class="tk-boton grande" href="<?= e(url('cita_nueva')) ?>">＋ Nueva cita</a>
        <a class="tk-boton grande ok" href="<?= e(url('cobrar')) ?>">💵 Cobrar</a>
        <a class="tk-boton grande secundario" href="<?= e(url('agenda')) ?>">📅 Agenda</a>
        <a class="tk-boton grande secundario" href="<?= e(url('clientes')) ?>">👥 Clientes</a>
        <a class="tk-boton grande secundario" href="<?= e(url('caja')) ?>">💵 Caja del día</a>
        <?php if (modulo('asistente_ia')): ?><a class="tk-boton grande secundario" href="<?= e(url('asistente')) ?>">✨ Asistente IA</a><?php endif; ?>
    </div>
</section>
