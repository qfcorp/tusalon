<?php
use TuSalon\WhatsApp;
$meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
$estados = ['pendiente' => 'Por aceptar', 'reservada' => 'Reservada', 'confirmada' => 'Confirmada', 'atendida' => 'Atendida',
            'no_asistio' => 'No asistió', 'cancelada' => 'Cancelada', 'rechazada' => 'Rechazada'];
$form = function (?array $c) use ($meses) {
    $s = $c ? 'e' : 'n';
    ob_start(); ?>
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="<?= $c ? 'editar' : 'crear' ?>">
    <?php if ($c): ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><?php endif; ?>
    <div class="campo"><label for="nom<?= $s ?>">Nombre</label>
        <input type="text" id="nom<?= $s ?>" name="nombre" value="<?= e($c['nombre'] ?? '') ?>" maxlength="120" required></div>
    <div class="fila-campos">
        <div class="campo"><label for="tel<?= $s ?>">Celular</label>
            <input type="tel" id="tel<?= $s ?>" name="telefono" value="<?= e($c['telefono'] ?? '') ?>" placeholder="0991234567"></div>
        <fieldset class="campo cumple">
            <legend>Cumpleaños <small>(día y mes)</small></legend>
            <div class="fila-cumple">
                <select name="cumple_dia" aria-label="Día del cumpleaños"><option value="">Día</option>
                    <?php for ($i = 1; $i <= 31; $i++): ?><option value="<?= $i ?>" <?= (int) ($c['cumple_dia'] ?? 0) === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select>
                <select name="cumple_mes" aria-label="Mes del cumpleaños"><option value="">Mes</option>
                    <?php foreach ($meses as $i => $m): ?><option value="<?= $i + 1 ?>" <?= (int) ($c['cumple_mes'] ?? 0) === $i + 1 ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select>
            </div>
        </fieldset>
    </div>
    <div class="campo"><label for="ale<?= $s ?>">Alergias <small>(tintes, productos)</small></label>
        <input type="text" id="ale<?= $s ?>" name="alergias" value="<?= e($c['alergias'] ?? '') ?>"></div>
    <div class="campo"><label for="not<?= $s ?>">Notas</label>
        <textarea id="not<?= $s ?>" name="notas" rows="2"><?= e($c['notas'] ?? '') ?></textarea></div>
    <button class="boton boton-ancho" type="submit"><?= $c ? 'Guardar cambios' : 'Guardar cliente' ?></button>
    <?php return ob_get_clean();
};
?>
<div class="cabecera">
    <div>
        <h1><?= $cliente ? e($cliente['nombre']) : 'Mis clientes' ?></h1>
        <p><?= $cliente ? 'Cliente tuyo: solo tú ves sus datos.' : 'Tus clientes propios. Solo tú los ves: el dueño y recepción no ven su nombre ni su celular.' ?></p>
    </div>
    <?php if ($cliente): ?><a class="boton boton-claro" href="<?= e(url('mis_clientes')) ?>">Volver</a><?php endif; ?>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if ($cliente): $wa = WhatsApp::enlace($cliente['telefono'], 'Hola ' . explode(' ', trim($cliente['nombre']))[0] . ', te escribe ' . $u['nombre'] . '. '); ?>
<div class="rejilla rejilla-2">
    <form method="post" class="bloque"><?= $form($cliente) ?>
        <?php if ($wa): ?><a class="boton boton-wa boton-ancho" style="margin-top:10px" href="<?= e($wa) ?>" target="_blank" rel="noopener">Escribirle por WhatsApp</a><?php endif; ?>
    </form>

    <section class="bloque">
        <h2>Agendar en tu silla</h2>
        <?php if (!$servicios): ?>
            <p class="vacio">No tienes servicios asignados. Pídele al dueño que te los agregue.</p>
        <?php else: ?>
        <form method="post">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="cita"><input type="hidden" name="id" value="<?= (int) $cliente['id'] ?>">
            <div class="fila-campos">
                <div class="campo"><label for="cf">Día</label><input type="date" id="cf" name="fecha" value="<?= e($cita['fecha']) ?>" min="<?= date('Y-m-d') ?>" required></div>
                <div class="campo"><label for="ch">Hora</label><input type="time" id="ch" name="hora" value="<?= e($cita['hora']) ?>" step="900" required></div>
            </div>
            <fieldset class="campo" style="border:0;padding:0">
                <legend class="etiqueta" style="margin-bottom:6px">Servicios</legend>
                <div class="opciones">
                <?php foreach ($servicios as $sv): ?>
                    <label class="opcion"><input type="checkbox" name="servicios[]" value="<?= (int) $sv['id'] ?>" <?= in_array((int) $sv['id'], $cita['servicios'], true) ? 'checked' : '' ?>>
                        <span><strong><?= e($sv['nombre']) ?> · <?= dinero($sv['precio']) ?></strong><small><?= (int) $sv['duracion_minutos'] ?> min</small></span></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <button class="boton boton-ancho" type="submit">Agendar cita</button>
        </form>
        <?php endif; ?>

        <h2 style="margin-top:18px">Sus citas</h2>
        <?php if (!$historial): ?>
            <p class="suave">Todavía no tiene citas.</p>
        <?php else: ?>
            <ul class="lista">
            <?php foreach ($historial as $h): ?>
                <li><div><div class="principal-linea"><?= e($h['servicios']) ?></div>
                    <div class="linea-sub"><?= (new DateTimeImmutable($h['inicio']))->format('d/m/Y H:i') ?> · <?= dinero($h['precio']) ?></div></div>
                    <span class="etq etq-<?= e($h['estado']) ?>"><?= $estados[$h['estado']] ?? e($h['estado']) ?></span></li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!$historial): ?>
            <form method="post" style="margin-top:12px"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $cliente['id'] ?>">
                <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar a <?= e($cliente['nombre']) ?>?">Borrar cliente</button></form>
        <?php endif; ?>
    </section>
</div>
<?php else: ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <form method="get" role="search" style="margin-bottom:10px">
            <input type="hidden" name="r" value="mis_clientes">
            <label class="oculto" for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o celular">
        </form>
        <?php if (!$clientes): ?>
            <p class="vacio"><?= $q !== '' ? 'Nadie coincide con esa búsqueda.' : 'Todavía no tienes clientes propios. Agrégalos aquí.' ?></p>
        <?php else: ?>
            <ul class="lista">
            <?php foreach ($clientes as $c): $wa = WhatsApp::enlace($c['telefono'], 'Hola ' . explode(' ', trim($c['nombre']))[0] . ', te escribe ' . $u['nombre'] . '. '); ?>
                <li>
                    <a href="<?= e(url('mis_clientes', ['id' => $c['id']])) ?>" style="flex:1;text-decoration:none;color:inherit">
                        <div class="principal-linea"><?= e($c['nombre']) ?></div>
                        <div class="linea-sub"><?= $c['telefono'] ? e($c['telefono']) : 'Sin celular' ?>
                            <?= $c['proxima'] ? '· próxima ' . (new DateTimeImmutable($c['proxima']))->format('d/m H:i') : ($c['ultima_visita'] ? '· última ' . (new DateTimeImmutable($c['ultima_visita']))->format('d/m/Y') : '') ?></div>
                    </a>
                    <?php if ($wa): ?><a class="boton boton-wa boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp a <?= e($c['nombre']) ?>">WhatsApp</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section class="bloque">
        <h2>Nuevo cliente</h2>
        <form method="post"><?= $form(null) ?></form>
    </section>
</div>
<?php endif; ?>
