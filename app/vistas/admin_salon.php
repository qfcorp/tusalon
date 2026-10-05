<?php
use TuSalon\{PanelTukan, Planes, WhatsApp};
$s = $salon;
$enlaceReservas = \TuSalon\Agenda::urlBase() . '/?r=reservar&s=' . rawurlencode($s['slug']);
$waDueno = WhatsApp::enlace($s['telefono'], 'Hola ' . explode(' ', (string) $s['dueno'])[0] . ', te escribo de TuSalón.');
$metodos = ['transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'payphone' => 'Payphone', 'plux' => 'Plux', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'cortesia' => 'Cortesía (gratis)'];
?>
<p><a href="<?= e(url('admin_salones')) ?>">← Todos los salones</a></p>
<div class="cabecera">
    <div><h1><?= e($s['nombre']) ?></h1>
        <p><span class="etq est-<?= e($s['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$s['estado_cuenta']] ?></span>
           Plan <strong><?= e($s['plan_nombre']) ?></strong>
           <?php if (in_array($s['estado_cuenta'], ['prueba', 'prueba_vencida'], true)): ?> · prueba hasta <?= date('d/m/Y', strtotime($s['prueba_hasta'])) ?>
           <?php elseif ($s['activo_hasta']): ?> · pagado hasta <?= date('d/m/Y', strtotime($s['activo_hasta'])) ?><?php endif; ?></p></div>
    <form method="post" action="<?= e(url('admin_ver', ['id' => $s['id']])) ?>"><?= campo_csrf() ?>
        <button class="boton boton-claro" type="submit">Entrar como el dueño (soporte)</button></form>
</div>

<?php if ($claveNueva): ?>
    <section class="bloque" style="border-color:var(--navaja)">
        <h2>Nueva contraseña del dueño</h2>
        <p>Correo: <strong><?= e($claveNueva['email']) ?></strong> · Contraseña: <strong class="clave-temporal"><?= e($claveNueva['clave']) ?></strong></p>
        <p class="suave">Se muestra solo esta vez. Envíasela y pídele que la cambie.</p>
        <?php $waClave = WhatsApp::enlace($s['telefono'], "Hola {$claveNueva['nombre']}, tu acceso a TuSalón: " . \TuSalon\Agenda::urlBase() . " · Correo: {$claveNueva['email']} · Contraseña: {$claveNueva['clave']}"); ?>
        <?php if ($waClave): ?><a class="boton boton-wa" href="<?= e($waClave) ?>" target="_blank" rel="noopener">Enviársela por WhatsApp</a><?php endif; ?>
    </section>
<?php endif; ?>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= (int) $s['peluqueros'] ?></div><p class="cifra-nombre">Personas en el equipo</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $s['citas_30'] ?></div><p class="cifra-nombre">Citas en 30 días</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $s['clientes'] ?></div><p class="cifra-nombre">Clientes</p></div>
    <div class="bloque"><div class="cifra cifra-chica"><?= $s['ultima_actividad'] ? date('d/m/Y', strtotime($s['ultima_actividad'])) : '—' ?></div><p class="cifra-nombre">Última actividad</p></div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Registrar un pago</h2>
        <form method="post" id="form-pago" data-cotizaciones='<?= e(json_encode($cotizaciones)) ?>'>
            <?= campo_csrf() ?><input type="hidden" name="accion" value="pago">
            <div class="fila-campos">
                <div class="campo"><label for="plan">Plan</label>
                    <select id="plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $p['codigo'] === $s['plan_codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="campo"><label for="periodo">Periodo</label>
                    <select id="periodo" name="periodo"><?php foreach (Planes::PERIODOS_NOMBRE as $k => $txt): ?><option value="<?= $k ?>"><?= e(planes()->textoPeriodo($k)) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="inicio">Desde</label>
                    <input type="date" id="inicio" name="inicio" value="<?= e($inicioSugerido) ?>" required>
                    <small>Si aún tiene días pagados, empieza al día siguiente.</small></div>
                <div class="campo"><label for="metodo">Cómo pagó</label>
                    <select id="metodo" name="metodo"><?php foreach ($metodos as $k => $txt): ?><option value="<?= $k ?>"><?= $txt ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="fila-campos">
                <div class="campo"><label for="monto">Monto cobrado</label>
                    <input type="number" id="monto" name="monto" step="0.01" min="0" placeholder="">
                    <small id="monto-ayuda">Vacío = precio normal.</small></div>
                <div class="campo" id="campo-dominio"><label for="dominio">Dominio del salón <small>(anual)</small></label>
                    <input type="text" id="dominio" name="dominio" placeholder="barberiaelcorte.com"></div>
            </div>
            <button class="boton boton-ancho" type="submit">Registrar pago y activar</button>
        </form>
    </section>

    <section class="bloque">
        <h2>Datos del dueño</h2>
        <ul class="lista">
            <li><span>Dueño</span><strong><?= e($s['dueno'] ?? '—') ?></strong></li>
            <li><span>Correo</span><strong><?= e($s['dueno_email'] ?? '—') ?></strong></li>
            <li><span>Celular</span><strong><?= e($s['telefono'] ?? '—') ?></strong></li>
            <li><span>Página de reservas</span><a href="<?= e($enlaceReservas) ?>" target="_blank" rel="noopener"><?= e($s['slug']) ?></a></li>
        </ul>
        <div class="acciones" style="margin-top:12px">
            <?php if ($waDueno): ?><a class="boton boton-wa boton-chico" href="<?= e($waDueno) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
            <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="clave">
                <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Crear una contraseña nueva para el dueño? La anterior deja de funcionar.">Nueva contraseña</button></form>
        </div>
    </section>

    <section class="bloque">
        <h2>Plan, estado y prueba</h2>
        <form method="post" class="fila-accion"><?= campo_csrf() ?><input type="hidden" name="accion" value="plan">
            <label for="cambiar-plan">Cambiar plan</label>
            <select id="cambiar-plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $p['codigo'] === $s['plan_codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= dinero($p['precio_mensual']) ?></option><?php endforeach; ?></select>
            <button class="boton boton-chico" type="submit">Cambiar</button></form>
        <form method="post" class="fila-accion"><?= campo_csrf() ?><input type="hidden" name="accion" value="prueba">
            <label for="dias">Dar más días de prueba</label>
            <select id="dias" name="dias"><?php foreach ([3, 7, 15, 30] as $d): ?><option value="<?= $d ?>"><?= $d ?> días</option><?php endforeach; ?></select>
            <button class="boton boton-chico" type="submit">Extender</button></form>
        <div class="acciones" style="margin-top:12px">
            <?php if ($s['estado'] === 'suspendido' || $s['estado'] === 'cancelado'): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="activo">
                    <button class="boton boton-chico" type="submit">Reactivar</button></form>
            <?php else: ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="suspendido">
                    <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Pausar este salón? No podrá entrar hasta que lo reactives. Sus datos se guardan.">Pausar (suspender)</button></form>
            <?php endif; ?>
            <?php if ($s['estado'] !== 'cancelado'): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="estado" value="cancelado">
                    <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Marcar como cancelado (ya no usa TuSalón)? Sus datos se guardan.">Cancelado</button></form>
            <?php endif; ?>
        </div>
    </section>

    <section class="bloque">
        <h2>Notas internas</h2>
        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="notas">
            <textarea name="notas" rows="4" aria-label="Notas internas" placeholder="Ej.: paga por transferencia el 5 de cada mes; contacto: su hija Ana."><?= e($s['notas_admin'] ?? '') ?></textarea>
            <button class="boton boton-claro boton-chico" type="submit" style="margin-top:8px">Guardar notas</button></form>
        <p class="suave">Solo las ves tú.</p>
    </section>
</div>

<section class="bloque">
    <h2>Pagos (<?= count($pagos) ?>)</h2>
    <?php if (!$pagos): ?><p class="vacio">Todavía no ha pagado.</p><?php else: ?>
        <div class="desliza">
        <table class="tabla">
            <thead><tr><th>Pagado</th><th>Plan</th><th>Periodo</th><th>Cubre</th><th>Cómo</th><th class="der">Monto</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pagos as $p): ?>
                <tr style="<?= $p['anulada'] ? 'opacity:.5;text-decoration:line-through' : '' ?>">
                    <td><?= date('d/m/Y', strtotime($p['pagado_en'])) ?></td><td><?= e($p['plan_nombre']) ?></td>
                    <td><?= e(Planes::PERIODOS_NOMBRE[$p['periodo']]) ?> (<?= (int) $p['meses_recibidos'] ?> meses)<?= $p['dominio'] ? '<br><small>' . e($p['dominio']) . '</small>' : '' ?></td>
                    <td><?= date('d/m/Y', strtotime($p['inicio'])) ?> → <?= date('d/m/Y', strtotime($p['fin'])) ?></td>
                    <td><?= e($metodos[$p['metodo_pago']] ?? $p['metodo_pago']) ?></td>
                    <td class="der"><?= dinero($p['monto']) ?></td>
                    <td class="der"><?php if (!$p['anulada']): ?>
                        <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="anular_pago"><input type="hidden" name="pago" value="<?= (int) $p['id'] ?>">
                            <button class="boton boton-claro boton-chico" type="submit" data-confirmar="¿Anular este pago? Úsalo solo si se registró por error.">Anular</button></form>
                    <?php else: ?><small>anulado</small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="bloque">
    <h2>Historial del panel</h2>
    <?php if (!$registro): ?><p class="vacio">Sin movimientos.</p><?php else: ?>
        <ul class="lista">
            <?php foreach ($registro as $m): ?>
                <li><span><?= e($m['accion']) ?> <small>· <?= e($m['admin'] ?? '') ?></small></span><small><?= date('d/m/Y H:i', strtotime($m['creado_en'])) ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
