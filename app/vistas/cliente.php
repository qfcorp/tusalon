<div class="cabecera">
    <h1><?= $id ? e($cliente['nombre']) : 'Nuevo cliente' ?></h1>
    <a class="boton boton-claro" href="<?= e(url('clientes')) ?>">Volver</a>
</div>
<div class="rejilla rejilla-2">
    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>
        <div class="campo">
            <label for="nombre">Nombre</label>
            <input type="text" id="nombre" name="nombre" value="<?= e($cliente['nombre']) ?>" required>
        </div>
        <div class="fila-campos">
            <div class="campo">
                <label for="telefono">Celular</label>
                <input type="tel" id="telefono" name="telefono" value="<?= e($cliente['telefono']) ?>" placeholder="0991234567">
            </div>
            <div class="campo">
                <label for="fecha_nacimiento">Cumpleaños</label>
                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= e($cliente['fecha_nacimiento']) ?>">
            </div>
        </div>
        <div class="campo">
            <label for="email">Correo <small>(opcional)</small></label>
            <input type="email" id="email" name="email" value="<?= e($cliente['email']) ?>">
        </div>
        <div class="campo">
            <label for="alergias">Alergias <small>(tintes, productos)</small></label>
            <input type="text" id="alergias" name="alergias" value="<?= e($cliente['alergias']) ?>">
        </div>
        <div class="campo">
            <label for="notas">Notas</label>
            <textarea id="notas" name="notas" rows="3"><?= e($cliente['notas']) ?></textarea>
        </div>
        <button class="boton boton-ancho" type="submit">Guardar cliente</button>
        <?php if (!es_completa($u)): ?>
            <p class="candado" style="margin-top:12px">Ficha técnica de color con fotos antes y después: <a href="<?= e(url('completa')) ?>">plan Completa</a></p>
        <?php endif; ?>
    </form>

    <?php if ($id): ?>
    <section class="bloque">
        <h2>Visitas</h2>
        <?php if (!$historial): ?>
            <p class="suave">Todavía no tiene citas.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($historial as $h): ?>
                    <li>
                        <div>
                            <div class="principal-linea"><?= e($h['servicios']) ?></div>
                            <div class="linea-sub"><?= (new DateTimeImmutable($h['inicio']))->format('d/m/Y H:i') ?> · <?= e($h['profesional']) ?></div>
                        </div>
                        <span class="etq etq-<?= e($h['estado']) ?>"><?= e(str_replace('_', ' ', $h['estado'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a class="boton boton-claro" href="<?= e(url('cita_nueva', ['cliente_id' => $id])) ?>">Agendar cita</a>
    </section>
    <?php endif; ?>
</div>
