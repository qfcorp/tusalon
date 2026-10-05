<?php use TuSalon\Agenda; $usaSalon = !$propio; ?>
<div class="cabecera">
    <div>
        <h1>Horario de <?= e($prof['nombre']) ?></h1>
        <p>Sus días, su almuerzo y sus vacaciones. En esas horas no se le pueden reservar citas.</p>
    </div>
    <a class="boton boton-claro boton-chico" href="<?= e(url('equipo')) ?>">← Equipo</a>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <form method="post" class="bloque" id="form-horario-prof">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="horario">
        <h2>Días y horas</h2>
        <div class="opciones" style="margin-bottom:12px">
            <label class="opcion"><input type="radio" name="modo" value="salon" <?= $usaSalon ? 'checked' : '' ?>>
                <span><strong>Usa el horario del salón</strong><small>Trabaja los mismos días y horas que abre el local.</small></span></label>
            <label class="opcion"><input type="radio" name="modo" value="propio" <?= $usaSalon ? '' : 'checked' ?>>
                <span><strong>Tiene su propio horario</strong><small>Días libres distintos, otra hora de entrada o salida, almuerzo.</small></span></label>
        </div>
        <div class="dias-propios">
            <?php for ($d = 1; $d <= 7; $d++): $i = $d % 7;
                $h = $usaSalon ? ($salon[$i] ?? null) : ($propio[$i] ?? null); ?>
                <div class="fila-horario-prof">
                    <label class="opcion" style="padding:8px 10px">
                        <input type="checkbox" name="trabaja[<?= $i ?>]" value="1" <?= $h ? 'checked' : '' ?>>
                        <span><strong><?= Agenda::DIAS[$i] ?></strong></span></label>
                    <div class="horas-prof">
                        <label>Entra <input type="time" name="abre[<?= $i ?>]" value="<?= e($h['abre'] ?? '09:00') ?>"></label>
                        <label>Sale <input type="time" name="cierra[<?= $i ?>]" value="<?= e($h['cierra'] ?? '19:00') ?>"></label>
                        <label>Almuerzo <input type="time" name="alm_desde[<?= $i ?>]" value="<?= e($h['almuerzo_desde'] ?? '') ?>" aria-label="Almuerzo desde el <?= Agenda::DIAS[$i] ?>"></label>
                        <label>hasta <input type="time" name="alm_hasta[<?= $i ?>]" value="<?= e($h['almuerzo_hasta'] ?? '') ?>" aria-label="Almuerzo hasta el <?= Agenda::DIAS[$i] ?>"></label>
                    </div>
                </div>
            <?php endfor; ?>
            <p class="suave">Deja el almuerzo vacío si no tiene hora fija.</p>
        </div>
        <button class="boton boton-ancho" type="submit">Guardar horario</button>
    </form>

    <div>
        <form method="post" class="bloque">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="bloqueo">
            <h2>Vacaciones, permisos o días libres</h2>
            <div class="fila-campos">
                <div class="campo"><label for="desde">Desde</label><input type="date" id="desde" name="desde" required min="<?= date('Y-m-d') ?>"></div>
                <div class="campo"><label for="hasta">Hasta (incluido)</label><input type="date" id="hasta" name="hasta" required min="<?= date('Y-m-d') ?>"></div>
            </div>
            <details style="margin-bottom:12px"><summary style="cursor:pointer;color:var(--verde)">Solo unas horas (ej. cita médica)</summary>
                <div class="fila-campos" style="margin-top:8px">
                    <div class="campo"><label for="hora_desde">Desde la hora</label><input type="time" id="hora_desde" name="hora_desde"></div>
                    <div class="campo"><label for="hora_hasta">Hasta la hora</label><input type="time" id="hora_hasta" name="hora_hasta"></div>
                </div></details>
            <div class="campo"><label for="motivo">Motivo <small>(solo lo ves tú)</small></label>
                <input type="text" id="motivo" name="motivo" maxlength="80" placeholder="Vacaciones"></div>
            <button class="boton boton-ancho" type="submit">Bloquear esas fechas</button>
        </form>

        <section class="bloque">
            <h2>Próximos bloqueos</h2>
            <?php if (!$bloqueos): ?><p class="vacio">No tiene vacaciones ni permisos programados.</p><?php else: ?>
                <ul class="lista">
                    <?php foreach ($bloqueos as $b): $d = new DateTimeImmutable($b['desde']); $h = new DateTimeImmutable($b['hasta']); ?>
                        <li><div><div class="principal-linea"><?= e($b['motivo'] ?: 'Bloqueado') ?></div>
                            <div class="linea-sub"><?php if ($d->format('H:i') === '00:00' && $h->format('H:i') === '00:00'):
                                $ult = $h->modify('-1 day'); ?>
                                <?= $ult->format('Y-m-d') === $d->format('Y-m-d') ? 'Todo el día ' . $d->format('d/m/Y') : 'Del ' . $d->format('d/m/Y') . ' al ' . $ult->format('d/m/Y') . ' (días completos)' ?>
                            <?php else: ?><?= $d->format('d/m/Y H:i') ?> → <?= $h->format('d/m/Y H:i') ?><?php endif; ?></div></div>
                            <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar_bloqueo">
                                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                <button class="boton boton-claro boton-chico" type="submit">Quitar</button></form></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
