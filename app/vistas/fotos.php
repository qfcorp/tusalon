<?php $ini = new DateTimeImmutable($cita['inicio']); ?>
<div class="tk-titulo-pagina cabecera">
    <div>
        <h1>Fotos del servicio</h1>
        <p><?= e($cita['cliente'] ?: 'Sin cliente') ?> · <?= $ini->format('d/m/Y H:i') ?> · <?= e(implode(' + ', array_column($cita['lista_servicios'], 'nombre'))) ?></p>
    </div>
    <a class="tk-boton secundario boton boton-claro" href="<?= e($volver) ?>">Volver</a>
</div>
<div class="rejilla rejilla-2">
    <section class="tk-tarjeta bloque">
        <h2>Fotos guardadas (<?= count($lista) ?>)</h2>
        <?php if (!$lista): ?><p class="suave">Todavía no hay fotos.</p><?php endif; ?>
        <div class="galeria">
            <?php foreach ($lista as $f): ?>
                <figure>
                    <a href="<?= e(url('foto', ['id' => $f['id']])) ?>" target="_blank" rel="noopener">
                        <img src="<?= e(url('foto', ['id' => $f['id']])) ?>" alt="Foto <?= $f['momento'] ?>" loading="lazy"></a>
                    <figcaption><?= $f['momento'] === 'antes' ? 'Antes' : 'Después' ?></figcaption>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="foto" value="<?= $f['id'] ?>">
                        <button class="tk-boton chico boton boton-peligro boton-chico boton-ancho" type="submit" data-confirmar="¿Borrar esta foto?">Borrar</button></form>
                </figure>
            <?php endforeach; ?>
        </div>
    </section>
    <section class="tk-tarjeta bloque">
        <h2>Subir foto</h2>
        <?php if ($permiso): ?>
            <p class="candado"><?= e($permiso) ?></p>
            <p class="suave">El cliente puede crear su cuenta y dar permiso cuando reserve en línea.</p>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data">
                <?= campo_csrf() ?>
                <div class="tk-campo campo"><label for="foto">Foto</label>
                    <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp" capture="environment" required></div>
                <div class="tk-campo campo"><span class="etiqueta">¿Es de antes o de después?</span>
                    <div class="modos">
                        <label class="modo"><input type="radio" name="momento" value="antes"><span><strong>Antes</strong></span></label>
                        <label class="modo"><input type="radio" name="momento" value="despues" checked><span><strong>Después</strong></span></label>
                    </div></div>
                <button class="tk-boton boton boton-ancho" type="submit">Guardar foto</button>
            </form>
        <?php endif; ?>
    </section>
</div>
