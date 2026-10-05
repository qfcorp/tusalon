<div class="cabecera">
    <h1>Servicios y precios</h1>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tus servicios</h2>
        <?php if (!$servicios): ?><p class="vacio">Agrega tu primer servicio.</p><?php endif; ?>
        <ul class="lista">
            <?php foreach ($servicios as $s): ?>
                <li>
                    <details style="flex:1">
                        <summary style="cursor:pointer">
                            <span class="principal-linea" style="<?= $s['activo'] ? '' : 'text-decoration:line-through;color:var(--tinta-suave)' ?>"><?= e($s['nombre']) ?></span>
                            <span class="linea-sub"> · <?= dinero($s['precio']) ?> · <?= (int) $s['duracion_minutos'] ?> min</span>
                        </summary>
                        <form method="post" style="margin-top:10px">
                            <?= campo_csrf() ?>
                            <input type="hidden" name="accion" value="editar">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <div class="campo"><label for="n<?= $s['id'] ?>">Nombre</label>
                                <input type="text" id="n<?= $s['id'] ?>" name="nombre" value="<?= e($s['nombre']) ?>" required></div>
                            <div class="fila-campos">
                                <div class="campo"><label for="p<?= $s['id'] ?>">Precio</label>
                                    <input type="number" id="p<?= $s['id'] ?>" name="precio" value="<?= e($s['precio']) ?>" step="0.01" min="0" required></div>
                                <div class="campo"><label for="d<?= $s['id'] ?>">Minutos</label>
                                    <input type="number" id="d<?= $s['id'] ?>" name="duracion_minutos" value="<?= (int) $s['duracion_minutos'] ?>" min="5" step="5" required></div>
                            </div>
                            <button class="boton boton-chico" type="submit">Guardar</button>
                        </form>
                    </details>
                    <form method="post">
                        <?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="activar">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <button class="boton boton-claro boton-chico" type="submit"><?= $s['activo'] ? 'Ocultar' : 'Mostrar' ?></button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <input type="hidden" name="accion" value="crear">
        <h2>Agregar servicio</h2>
        <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" placeholder="Corte degradado" required></div>
        <div class="fila-campos">
            <div class="campo"><label for="precio">Precio</label><input type="number" id="precio" name="precio" step="0.01" min="0" required></div>
            <div class="campo"><label for="duracion_minutos">Minutos</label><input type="number" id="duracion_minutos" name="duracion_minutos" value="30" min="5" step="5" required></div>
        </div>
        <button class="boton boton-ancho" type="submit">Agregar servicio</button>
    </form>
</div>
