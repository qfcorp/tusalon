<?php
use TuSalon\{PanelTukan, WhatsApp};
$wa = fn(array $s, string $msg) => WhatsApp::enlace($s['telefono'], $msg);
?>
<div class="cabecera">
    <div><h1>Hola, <?= e(explode(' ', $admin['nombre'])[0]) ?></h1>
        <p>Así va TuSalón hoy, <?= date('d/m/Y') ?>.</p></div>
    <a class="boton" href="<?= e(url('admin_nuevo')) ?>">+ Nuevo salón</a>
</div>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= $r['total'] ?></div><p class="cifra-nombre">Salones registrados</p></div>
    <div class="bloque"><div class="cifra"><?= $r['cuenta']['activo'] + $r['cuenta']['por_vencer'] ?></div><p class="cifra-nombre">Pagando · <?= $r['cuenta']['prueba'] ?> en prueba</p></div>
    <div class="bloque"><div class="cifra"><?= dinero($r['mensual']) ?></div><p class="cifra-nombre">Ingreso mensual (lo que vale al mes lo pagado)</p></div>
    <div class="bloque"><div class="cifra"><?= dinero($r['cobrado_mes']) ?></div><p class="cifra-nombre">Cobrado este mes · <?= dinero($r['cobrado_anio']) ?> en el año</p></div>
</div>

<section class="bloque">
    <h2>Estado de los salones</h2>
    <div class="chips-estado">
        <?php foreach (PanelTukan::ESTADOS as $k => $txt): ?>
            <a class="chip-estado est-<?= $k ?>" href="<?= e(url('admin_salones', ['f' => $k])) ?>"><strong><?= $r['cuenta'][$k] ?></strong> <?= $txt ?></a>
        <?php endforeach; ?>
    </div>
</section>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Pruebas que terminan en 3 días (<?= count($r['pruebas_por_terminar']) ?>)</h2>
        <p class="suave">El mejor momento para escribirles y ofrecer el plan.</p>
        <?php if (!$r['pruebas_por_terminar']): ?><p class="vacio">Ninguna por ahora.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['pruebas_por_terminar'] as $s): $link = $wa($s, 'Hola ' . explode(' ', (string) $s['dueno'])[0] . ', soy de TuSalón. Tu prueba de ' . $s['nombre'] . ' termina el ' . date('d/m', strtotime($s['prueba_hasta'])) . '. ¿Te ayudo a elegir tu plan para que sigas usándolo?'); ?>
                    <li><a href="<?= e(url('admin_salon', ['id' => $s['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($s['nombre']) ?></div>
                        <div class="linea-sub"><?= e($s['dueno']) ?> · termina <?= date('d/m', strtotime($s['prueba_hasta'])) ?> · <?= (int) $s['citas_30'] ?> citas</div></a>
                        <?php if ($link): ?><a class="boton boton-wa boton-chico" href="<?= e($link) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Planes que vencen en 15 días (<?= count($r['por_vencer']) ?>)</h2>
        <p class="suave">Recuérdales renovar.</p>
        <?php if (!$r['por_vencer']): ?><p class="vacio">Ninguno por ahora.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['por_vencer'] as $s): $link = $wa($s, 'Hola ' . explode(' ', (string) $s['dueno'])[0] . ', soy de TuSalón. El plan de ' . $s['nombre'] . ' vence el ' . date('d/m', strtotime($s['activo_hasta'])) . '. ¿Te paso los datos para renovarlo?'); ?>
                    <li><a href="<?= e(url('admin_salon', ['id' => $s['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($s['nombre']) ?></div>
                        <div class="linea-sub"><?= e($s['plan_nombre']) ?> · vence <?= date('d/m/Y', strtotime($s['activo_hasta'])) ?></div></a>
                        <?php if ($link): ?><a class="boton boton-wa boton-chico" href="<?= e($link) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Últimos registrados</h2>
        <?php if (!$r['nuevos']): ?><p class="vacio">Todavía no hay salones. Crea el primero o comparte el enlace de registro.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($r['nuevos'] as $s): ?>
                    <li><a href="<?= e(url('admin_salon', ['id' => $s['id']])) ?>" style="flex:1;color:inherit;text-decoration:none">
                        <div class="principal-linea"><?= e($s['nombre']) ?></div>
                        <div class="linea-sub"><?= e($s['dueno']) ?> · <?= date('d/m/Y', strtotime($s['creado_en'])) ?></div></a>
                        <span class="etq est-<?= e($s['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$s['estado_cuenta']] ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Últimos movimientos del panel</h2>
        <?php if (!$registro): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($registro as $m): ?>
                    <li><span><?= e($m['accion']) ?><?= $m['salon'] ? ' · <strong>' . e($m['salon']) . '</strong>' : '' ?></span>
                        <small style="white-space:nowrap"><?= date('d/m H:i', strtotime($m['creado_en'])) ?></small></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
