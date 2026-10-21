<?php
use TuSalon\{PanelTukan, Planes, WhatsApp};
$s = $salon;
$enlaceReservas = \TuSalon\Agenda::urlBase() . '/?r=reservar&s=' . rawurlencode($s['slug']);
$waDueno = WhatsApp::enlace($s['telefono'], 'Hola ' . explode(' ', (string) $s['dueno'])[0] . ', te escribo de TuSalón.');
$metodos = ['transferencia' => 'Transferencia', 'deuna' => 'DeUna', 'payphone' => 'Payphone', 'plux' => 'Plux', 'efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'cortesia' => 'Cortesía (sin cobro)'];
?>
<p><a href="<?= e(url('admin_salones')) ?>">← Todos los salones</a></p>
<div class="cabecera">
    <div><h1><?= e($s['nombre']) ?></h1>
        <p><span class="etq est-<?= e($s['estado_cuenta']) ?>"><?= PanelTukan::ESTADOS[$s['estado_cuenta']] ?></span>
           Plan <strong><?= e($s['plan_nombre']) ?></strong>
           <?php if (in_array($s['estado_cuenta'], ['prueba', 'prueba_vencida'], true)): ?> · demo hasta <?= date('d/m/Y', strtotime($s['prueba_hasta'])) ?>
           <?php elseif ($s['activo_hasta']): ?> · pagado hasta <?= date('d/m/Y', strtotime($s['activo_hasta'])) ?><?php endif; ?></p></div>
    <form method="post" action="<?= e(url('admin_ver', ['id' => $s['id']])) ?>"><?= campo_csrf() ?>
        <button class="boton boton-claro" type="submit">Entrar como el dueño (soporte)</button></form>
</div>

<?php if (!empty($s['eliminado_en'])): $purga = TuSalon\Papelera::fechaPurga($s['eliminado_en']); ?>
    <section class="bloque" style="border-color:var(--rojo);border-width:2px" role="alert">
        <h2>En la papelera</h2>
        <p>Borrado el <?= date('d/m/Y H:i', strtotime($s['eliminado_en'])) ?> por <?= e($s['eliminado_por']) ?>. Motivo: <?= e($s['eliminado_motivo']) ?></p>
        <p>No puede entrar y su página de reservas no existe. <strong>Se borra para siempre el <?= date('d/m/Y', strtotime($purga)) ?></strong> (la copia se guarda 1 año).</p>
        <?php if ($purga > date('Y-m-d')): ?>
            <form method="post" action="<?= e(url('admin_salon', ['id' => $s['id']])) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="restaurar">
                <button class="boton" type="submit">Restaurar</button></form>
        <?php endif; ?>
    </section>
<?php elseif ($s['estado'] === 'suspendido' && !empty($s['pausa_motivo'])): ?>
    <section class="bloque" role="status">
        <h2>Cuenta pausada<?= !empty($s['pausa_tipo']) ? ' · ' . e(TuSalon\Papelera::TIPOS_PAUSA[$s['pausa_tipo']] ?? $s['pausa_tipo']) : '' ?></h2>
        <p>Motivo: <?= e($s['pausa_motivo']) ?><?= $s['pausa_en'] ? ' · desde el ' . date('d/m/Y', strtotime($s['pausa_en'])) : '' ?></p>
        <p class="suave">El salón ve: «<?= e(TuSalon\Papelera::textoPausa($s['pausa_tipo'], $s['pausa_motivo'])) ?>»</p>
    </section>
<?php endif; ?>

<?php if ($claveNueva): ?>
    <section class="bloque" style="border-color:var(--navaja)">
        <h2>Nueva contraseña del dueño</h2>
        <p>Correo: <strong><?= e($claveNueva['email']) ?></strong> · Contraseña: <strong class="clave-temporal"><?= e($claveNueva['clave']) ?></strong></p>
        <p class="suave">Se muestra solo esta vez. Envíasela y pídele que la cambie.</p>
        <?php $waClave = WhatsApp::enlace($s['telefono'], "Hola {$claveNueva['nombre']}, tu acceso a TuSalón: " . \TuSalon\Agenda::urlBase() . " · Correo: {$claveNueva['email']} · Contraseña: {$claveNueva['clave']}"); ?>
        <?php if ($waClave): ?><a class="boton boton-wa" href="<?= e($waClave) ?>" target="_blank" rel="noopener">Enviársela por WhatsApp</a><?php endif; ?>
        <?php if ($correoDemo): $correo = $correoDemo; require __DIR__ . '/_correo_demo.php'; endif; ?>
    </section>
<?php endif; ?>

<div class="rejilla rejilla-4">
    <div class="bloque"><div class="cifra"><?= (int) $s['peluqueros'] ?></div><p class="cifra-nombre">Personas en el equipo</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $s['citas_30'] ?></div><p class="cifra-nombre">Citas en 30 días</p></div>
    <div class="bloque"><div class="cifra"><?= (int) $s['clientes'] ?></div><p class="cifra-nombre">Clientes</p></div>
    <div class="bloque"><div class="cifra cifra-chica"><?= $s['ultima_actividad'] ? date('d/m/Y', strtotime($s['ultima_actividad'])) : '—' ?></div><p class="cifra-nombre">Última actividad</p></div>
</div>

<?php $cat = modulos()->catalogo(); $md = $modDetalle; ?>
<section class="bloque" id="modulos">
    <h2>Módulos <small class="suave">(apagar no borra datos)</small></h2>
    <?php if ($md['demo']): ?><p class="aviso">Está en demo: ve <?= count($md['activos']) === count($cat) ? 'todos los módulos' : count($md['activos']) . ' módulos' ?>. Lo que marques aquí es lo que ve la demo y lo que tendrá al pagar.</p><?php endif; ?>
    <?php foreach ($modPedidos as $p): ?>
        <div class="aviso franja-solicitudes" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
            <span style="flex:1">Pidió desde Mi plan: <strong><?= e(implode(', ', array_map([modulos(), 'nombre'], TuSalon\Modulos::limpiar($p['modulos'])))) ?></strong> (<?= date('d/m/Y', strtotime($p['creado'])) ?>)</span>
            <form method="post" style="display:inline"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedido_modulos"><input type="hidden" name="pedido" value="<?= (int) $p['id'] ?>">
                <button class="boton boton-chico" name="resultado" value="aprobar" type="submit">Aprobar y encender</button>
                <button class="boton boton-claro boton-chico" name="resultado" value="rechazar" type="submit">Rechazar</button></form>
        </div>
    <?php endforeach; ?>
    <form method="post">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="modulos">
        <ul class="modulos">
            <?php foreach ($cat as $m): $on = in_array($m['id'], $md['demo'] ? $md['activos'] : $md['contratados'], true); $delPlan = in_array($m['id'], $md['del_plan'], true); ?>
            <li class="modulo <?= $on ? 'modulo-activo' : '' ?>">
                <span class="modulo-icono" aria-hidden="true"><?= e($m['icono']) ?></span>
                <div class="modulo-cuerpo"><label class="interruptor"><input type="checkbox" name="mod[<?= e($m['id']) ?>]" value="1" <?= $on ? 'checked' : '' ?>> <?= e($m['nombre']) ?></label>
                    <p class="suave"><?= $delPlan ? 'Del plan' . ($on ? '' : ' · quitado (−' . dinero($m['descuento']) . ')') : ($on ? 'Extra' : 'No lo tiene') ?><?= $m['depende_de'] ? ' · necesita ' . e(implode(', ', array_map([modulos(), 'nombre'], $m['depende_de']))) : '' ?></p></div>
                <div class="modulo-lado"><span class="modulo-precio"><?= $delPlan ? 'incluido' : '+' . dinero($m['precio_mensual']) ?></span></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <p class="total-plan"><span>Plan <?= dinero($md['precio_plan']) ?> + extra <?= dinero($md['precio_extra']) ?> − descuento <?= dinero($md['descuento']) ?></span><span><?= dinero($md['mensual']) ?> al mes</span></p>
        <div class="fila-campos">
            <div class="campo"><label for="precio_acordado">Precio acordado al mes <small>(vacío = <?= dinero($md['precio_calculado']) ?>)</small></label>
                <input type="text" inputmode="decimal" id="precio_acordado" name="precio_acordado" value="<?= $md['precio_acordado'] === null ? '' : e(number_format($md['precio_acordado'], 2, ',', '')) ?>"></div>
            <div class="campo"><label for="motivo_mod">Motivo <small>(opcional)</small></label><input type="text" id="motivo_mod" name="motivo" maxlength="300"></div>
        </div>
        <button class="boton" type="submit" data-confirmar="¿Guardar los módulos de <?= e($s['nombre']) ?>? Apagar un módulo no borra sus datos.">Guardar módulos</button>
    </form>
    <?php if ($modHistorial): ?>
        <details style="margin-top:12px"><summary>Cambios de módulos</summary>
            <ul class="lista"><?php foreach ($modHistorial as $h): ?>
                <li><span><?= date('d/m/Y H:i', strtotime($h['momento'])) ?> · <?= e($h['quien']) ?> (<?= e($h['origen']) ?>)<br>
                    <small><?= $h['encendidos'] ? 'Encendió: ' . e($h['encendidos']) . ' ' : '' ?><?= $h['apagados'] ? 'Apagó: ' . e($h['apagados']) . ' ' : '' ?><?= $h['motivo'] ? '· ' . e($h['motivo']) : '' ?></small></span>
                    <span class="monto"><?= dinero($h['precio_despues']) ?></span></li>
            <?php endforeach; ?></ul></details>
    <?php endif; ?>
</section>

<section class="bloque" id="ia" data-ia-salon>
    <h2>IA de Tukán <small class="suave">(solo lo ve Tukán)</small></h2>
    <p>Este mes: <strong>$<?= number_format($iaInfo['gasto']['costo'], 4, ',', '.') ?></strong> de un tope de $<?= number_format($iaInfo['tope'], 2, ',', '.') ?>
       · <?= (int) $iaInfo['gasto']['llamadas'] ?> llamadas · <?= (int) $iaInfo['gasto']['ahorradas'] ?> respuestas sin IA (preguntas frecuentes o manual)
       · <?= (int) $iaInfo['gasto']['ayudas_nuevas'] ?> respuestas nuevas de la Ayuda.</p>
    <p class="suave">Módulo «Asistente IA»: <?= $iaInfo['modulo'] ? 'encendido (tope sugerido $' . number_format(TuSalon\Ia::TOPE_CON_MODULO, 2, ',', '.') . ')' : 'apagado (tope sugerido $' . number_format(TuSalon\Ia::TOPE_SIN_MODULO, 2, ',', '.') . ' y ' . TuSalon\Ia::NUEVAS_SIN_MODULO . ' respuestas nuevas de la Ayuda al mes)' ?>.
       Al llegar al tope el salón no ve ningún aviso: la Ayuda sigue con las preguntas frecuentes y el manual.</p>
    <form method="post" action="<?= e(url('admin_salon', ['id' => $s['id']])) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="ia_tope">
        <div class="campo" style="margin:0"><label for="ia_tope">Tope del mes (USD)</label>
            <input id="ia_tope" name="ia_tope" inputmode="decimal" value="<?= $iaInfo['propio'] === null ? '' : e(number_format($iaInfo['propio'], 2, ',', '')) ?>" placeholder="Vacío = el sugerido"></div>
        <button class="boton boton-claro" type="submit">Guardar tope</button>
    </form>
</section>

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
        <h2>Plan, estado y demo</h2>
        <form method="post" class="fila-accion"><?= campo_csrf() ?><input type="hidden" name="accion" value="plan">
            <label for="cambiar-plan">Cambiar plan</label>
            <select id="cambiar-plan" name="plan"><?php foreach ($listaPlanes as $p): ?><option value="<?= e($p['codigo']) ?>" <?= $p['codigo'] === $s['plan_codigo'] ? 'selected' : '' ?>><?= e($p['nombre']) ?> · <?= dinero($p['precio_mensual']) ?></option><?php endforeach; ?></select>
            <button class="boton boton-chico" type="submit">Cambiar</button></form>
        <form method="post" class="fila-accion"><?= campo_csrf() ?><input type="hidden" name="accion" value="prueba">
            <label for="dias">Dar más días de demo</label>
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
