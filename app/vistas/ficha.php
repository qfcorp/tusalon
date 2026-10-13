<?php $esDueno = !es_peluquero($u); ?>
<div class="cabecera">
    <div>
        <h1>Ficha técnica · <?= e($cliente['nombre']) ?></h1>
        <p>Fórmulas de color y fotos de antes y después, para repetir el mismo resultado.</p>
    </div>
    <a class="boton boton-claro" href="<?= e($esDueno ? url('cliente', ['id' => $cliente['id']]) : url('mi_portal')) ?>">Volver</a>
</div>
<?php if ($cliente['alergias']): ?><p class="aviso aviso-error">⚠️ Alergias: <?= e($cliente['alergias']) ?></p><?php endif; ?>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Historial de color (<?= count($fichas) ?>)</h2>
        <?php if (!$fichas): ?><p class="vacio">Todavía no hay fichas. Guarda la primera al terminar el trabajo.</p><?php endif; ?>
        <?php foreach ($fichas as $f): ?>
            <article class="ficha-item">
                <div style="display:flex;justify-content:space-between;gap:8px;align-items:start">
                    <div><strong><?= e($f['servicio'] ?: 'Color') ?></strong>
                        <div class="linea-sub suave"><?= (new DateTimeImmutable($f['fecha']))->format('d/m/Y') ?><?= $f['profesional'] ? ' · ' . e($f['profesional']) : '' ?></div></div>
                    <?php if ($esDueno || (int) $f['profesional_id'] === (int) $u['profesional_id']): ?>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="ficha_id" value="<?= (int) $f['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Borrar esta ficha y sus fotos?">Borrar</button></form>
                    <?php endif; ?>
                </div>
                <dl class="ficha-datos">
                    <?php foreach (['marca_tinte' => 'Marca', 'tono' => 'Tono', 'oxidante' => 'Oxidante', 'tiempo_minutos' => 'Tiempo', 'resultado' => 'Resultado'] as $k => $t):
                        if (!$f[$k]) continue; ?>
                        <dt><?= $t ?></dt><dd><?= e($f[$k]) ?><?= $k === 'tiempo_minutos' ? ' min' : '' ?></dd>
                    <?php endforeach; ?>
                </dl>
                <?php if ($f['formula']): ?><p style="margin:4px 0"><span class="suave">Fórmula:</span><br><strong style="white-space:pre-line"><?= e($f['formula']) ?></strong></p><?php endif; ?>
                <?php if ($f['notas']): ?><p class="suave" style="margin:4px 0;white-space:pre-line"><?= e($f['notas']) ?></p><?php endif; ?>
                <?php if ($f['foto_antes'] || $f['foto_despues']): ?>
                    <div class="fotos-ficha">
                        <?php foreach (['antes' => 'Antes', 'despues' => 'Después'] as $m => $t): ?>
                            <figure><?php if ($f['foto_' . $m]): ?><a href="<?= e(url('ficha_foto', ['id' => $f['id'], 'm' => $m])) ?>" target="_blank"><img src="<?= e(url('ficha_foto', ['id' => $f['id'], 'm' => $m])) ?>" alt="<?= $t ?>" loading="lazy"></a><?php else: ?><div style="aspect-ratio:3/4;border:1px dashed var(--linea);border-radius:8px"></div><?php endif; ?>
                                <figcaption><?= $t ?></figcaption></figure>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <form method="post" enctype="multipart/form-data" class="bloque">
        <?= campo_csrf() ?>
        <h2>Nueva ficha</h2>
        <div class="fila-campos">
            <div class="campo"><label for="fs">Trabajo</label><input type="text" id="fs" name="servicio" maxlength="100" placeholder="Tinte raíz, mechas, balayage…" list="trabajos">
                <datalist id="trabajos"><option value="Tinte completo"><option value="Retoque de raíz"><option value="Mechas"><option value="Balayage"><option value="Decoloración"><option value="Matiz"><option value="Alisado"></datalist></div>
            <div class="campo"><label for="ff">Fecha</label><input type="date" id="ff" name="fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="fm">Marca</label><input type="text" id="fm" name="marca_tinte" maxlength="60" placeholder="Igora, Majirel, Koleston…"></div>
            <div class="campo"><label for="ft">Tono</label><input type="text" id="ft" name="tono" maxlength="40" placeholder="7.1"></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="fo">Oxidante</label><input type="text" id="fo" name="oxidante" maxlength="20" placeholder="20 vol"></div>
            <div class="campo"><label for="fti">Tiempo (minutos)</label><input type="number" id="fti" name="tiempo_minutos" min="1" max="600" placeholder="35"></div>
        </div>
        <div class="campo"><label for="ffo">Fórmula exacta</label><textarea id="ffo" name="formula" rows="2" placeholder="30 g 7.1 + 10 g 8.0 + 60 ml oxidante 20 vol"></textarea></div>
        <?php if ($esDueno && $profesionales): ?>
        <div class="campo"><label for="fp">Lo hizo</label><select id="fp" name="profesional_id"><option value="">—</option>
            <?php foreach ($profesionales as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="campo"><label for="fr">Resultado <small>(opcional)</small></label><input type="text" id="fr" name="resultado" maxlength="200" placeholder="Rubio ceniza parejo, cubrió las canas"></div>
        <div class="campo"><label for="fn">Notas <small>(opcional)</small></label><textarea id="fn" name="notas" rows="2" placeholder="Cuero cabelludo sensible, usar protector"></textarea></div>
        <fieldset class="campo" style="border:0;padding:0">
            <legend class="etiqueta" style="margin-bottom:6px">Fotos <small>(opcional)</small></legend>
            <label class="opcion" style="margin-bottom:8px"><input type="checkbox" name="autoriza_fotos" value="1">
                <span><strong>El cliente autorizó las fotos</strong><small>Solo las ve el personal del salón.</small></span></label>
            <div class="fila-campos">
                <div class="campo"><label for="fa">Antes</label><input type="file" id="fa" name="foto_antes" accept="image/*" capture="environment"></div>
                <div class="campo"><label for="fd">Después</label><input type="file" id="fd" name="foto_despues" accept="image/*" capture="environment"></div>
            </div>
        </fieldset>
        <button class="boton boton-ancho" type="submit">Guardar ficha</button>
    </form>
</div>
