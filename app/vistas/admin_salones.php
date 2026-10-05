<?php use TuSalon\{PanelTukan, WhatsApp}; ?>
<div class="cabecera">
    <div><h1>Salones (<?= count($salones) ?>)</h1>
        <p>Todos los salones y barberías que usan TuSalón.</p></div>
    <a class="boton" href="<?= e(url('admin_nuevo')) ?>">+ Nuevo salón</a>
</div>

<form method="get" class="bloque filtros-admin">
    <input type="hidden" name="r" value="admin_salones">
    <div class="campo"><label for="q">Buscar</label>
        <input type="search" id="q" name="q" value="<?= e($buscar) ?>" placeholder="Nombre, dueño, correo o celular"></div>
    <div class="campo"><label for="f">Estado</label>
        <select id="f" name="f" onchange="this.form.submit()">
            <option value="">Todos</option>
            <?php foreach (PanelTukan::ESTADOS as $k => $txt): ?><option value="<?= $k ?>" <?= $filtro === $k ? 'selected' : '' ?>><?= $txt ?></option><?php endforeach; ?>
        </select></div>
    <button class="boton" type="submit">Buscar</button>
</form>

<section class="bloque">
    <?php if (!$salones): ?>
        <p class="vacio">No hay salones<?= $buscar !== '' || $filtro !== '' ? ' con ese filtro' : ' todavía' ?>.</p>
    <?php else: ?>
        <div class="desliza">
        <table class="tabla tabla-admin">
            <thead><tr><th>Salón</th><th>Dueño</th><th>Plan</th><th>Estado</th><th>Vence</th><th class="der">Equipo</th><th class="der">Citas 30 días</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($salones as $s):
                $vence = $s['estado_cuenta'] === 'prueba' || $s['estado_cuenta'] === 'prueba_vencida' ? $s['prueba_hasta'] : $s['activo_hasta'];
                $wa = WhatsApp::enlace($s['telefono'], 'Hola ' . explode(' ', (string) $s['dueno'])[0] . ', te escribo de TuSalón.'); ?>
                <tr>
                    <td><a href="<?= e(url('admin_salon', ['id' => $s['id']])) ?>"><strong><?= e($s['nombre']) ?></strong></a><br><small>desde <?= date('d/m/Y', strtotime($s['creado_en'])) ?></small></td>
                    <td><?= e($s['dueno']) ?><br><small><?= e($s['dueno_email']) ?></small></td>
                    <td><?= e($s['plan_nombre']) ?></td>
                    <td><span class="etq est-<?= e($s['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$s['estado_cuenta']] ?></span></td>
                    <td><?= $vence ? date('d/m/Y', strtotime($vence)) : '—' ?></td>
                    <td class="der"><?= (int) $s['peluqueros'] ?></td>
                    <td class="der"><?= (int) $s['citas_30'] ?></td>
                    <td class="der"><?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>
