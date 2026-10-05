<?php
$campos = function (array $s, string $sufijo) use ($profesionales, $quienHace) {
    $pago = $s['pago_profesional'] ?? null;
    $quien = isset($s['id']) ? ($quienHace[(int) $s['id']] ?? []) : [];
    ob_start(); ?>
    <div class="campo"><label for="n<?= $sufijo ?>">Nombre del servicio</label>
        <input type="text" id="n<?= $sufijo ?>" name="nombre" value="<?= e($s['nombre'] ?? '') ?>" placeholder="Corte degradado" required></div>
    <div class="fila-campos">
        <div class="campo"><label for="p<?= $sufijo ?>">Precio al cliente</label>
            <input type="number" id="p<?= $sufijo ?>" name="precio" value="<?= e($s['precio'] ?? '') ?>" step="0.01" min="0" required></div>
        <div class="campo"><label for="g<?= $sufijo ?>">Pago al peluquero</label>
            <input type="number" id="g<?= $sufijo ?>" name="pago_profesional" value="<?= e($pago ?? '') ?>" step="0.01" min="0">
            <small>Lo que le pagas a un empleado por hacerlo. Vacío = usa su % de comisión.</small></div>
    </div>
    <div class="fila-campos">
        <div class="campo"><label for="d<?= $sufijo ?>">Duración (minutos)</label>
            <input type="number" id="d<?= $sufijo ?>" name="duracion_minutos" value="<?= (int) ($s['duracion_minutos'] ?? 30) ?>" min="5" step="5" required></div>
        <label class="opcion" style="align-self:end;margin-bottom:14px">
            <input type="checkbox" name="reserva_online" value="1" <?= ($s['reserva_online'] ?? true) ? 'checked' : '' ?>>
            <span><strong>Se puede reservar en línea</strong></span></label>
    </div>
    <div class="campo"><label for="x<?= $sufijo ?>">Explicación para el cliente <small>(opcional)</small></label>
        <textarea id="x<?= $sufijo ?>" name="descripcion" rows="3" maxlength="600"
                  placeholder="Qué incluye, cómo se hace, qué resultado esperar. Ej.: Lavado, corte con máquina y tijera, perfilado de barba y peinado."><?= e($s['descripcion'] ?? '') ?></textarea>
        <small>El cliente la ve al reservar, junto con la duración.</small></div>
    <?php if ($profesionales): ?>
    <input type="hidden" name="quien_enviado" value="1">
    <fieldset class="campo quien-hace">
        <legend>¿Quién lo hace? <small>(y precio si es distinto)</small></legend>
        <?php foreach ($profesionales as $p):
            $pid = (int) $p['id'];
            $marcado = !$quien || array_key_exists($pid, $quien); ?>
            <div class="fila-quien">
                <label class="opcion"><input type="checkbox" name="hace[<?= $pid ?>]" value="1" <?= $marcado ? 'checked' : '' ?>>
                    <span><?= e($p['nombre']) ?></span></label>
                <input type="number" name="precio_prof[<?= $pid ?>]" value="<?= e($quien[$pid] ?? '') ?>" step="0.01" min="0"
                       placeholder="Normal" aria-label="Precio de <?= e($p['nombre']) ?>">
            </div>
        <?php endforeach; ?>
        <small>Desmarca a quien no lo hace. Deja el precio vacío para usar el precio normal.</small>
    </fieldset>
    <?php endif; ?>
    <?php return ob_get_clean();
};
?>
<div class="cabecera">
    <div>
        <h1>Servicios y precios</h1>
        <p>Crea todos los servicios que ofreces, con lo que cobras y lo que pagas al peluquero.</p>
    </div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tus servicios (<?= count($servicios) ?>)</h2>
        <?php if (!$servicios): ?><p class="vacio">Agrega tu primer servicio.</p><?php endif; ?>
        <div class="desliza">
        <table class="tabla">
            <thead><tr><th>Servicio</th><th class="der">Cliente paga</th><th class="der">Al peluquero</th><th class="der">Queda al local</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($servicios as $s):
                $pago = $s['pago_profesional']; ?>
                <tr style="<?= $s['activo'] ? '' : 'opacity:.55' ?>">
                    <td>
                        <details>
                            <summary style="cursor:pointer"><strong><?= e($s['nombre']) ?></strong>
                                <br><small><?= (int) $s['duracion_minutos'] ?> min<?= $s['reserva_online'] ? ' · en línea' : '' ?><?= $s['activo'] ? '' : ' · oculto' ?><?= !empty($quienHace[(int) $s['id']]) ? ' · solo ' . count($quienHace[(int) $s['id']]) . ' peluquero(s)' : '' ?></small></summary>
                            <form method="post" style="margin-top:10px;min-width:260px">
                                <?= campo_csrf() ?>
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                <?= $campos($s, (string) $s['id']) ?>
                                <button class="boton boton-chico" type="submit">Guardar cambios</button>
                            </form>
                        </details>
                    </td>
                    <td class="der"><?= dinero($s['precio']) ?></td>
                    <td class="der"><?= $pago === null ? '<small>según %</small>' : dinero($pago) ?></td>
                    <td class="der"><?= $pago === null ? '—' : dinero((float) $s['precio'] - (float) $pago) ?></td>
                    <td class="der">
                        <form method="post">
                            <?= campo_csrf() ?>
                            <input type="hidden" name="accion" value="activar">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button class="boton boton-claro boton-chico" type="submit"><?= $s['activo'] ? 'Ocultar' : 'Mostrar' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="suave" style="margin-top:12px">El pago fijo se aplica a los <strong>empleados</strong>. Quien trabaja por porcentaje se lleva su % y quien alquila se queda con todo.</p>
    </section>

    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear">
        <h2>Agregar servicio</h2>
        <?= $campos([], 'nuevo') ?>
        <button class="boton boton-ancho" type="submit">Agregar servicio</button>
    </form>
</div>
