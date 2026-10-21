<?php /** @var array $lista @var array $resumen @var string $filtro @var ?array $editar @var array $estadoIa */
$filtros = ['sin_aprobar' => 'Por revisar', '' => 'Todas', 'automatica' => 'Automáticas', 'manual' => 'Escritas a mano'];
?>
<div class="cabecera">
    <div><h1>Preguntas frecuentes (IA)</h1>
        <p>Lo que responde la Ayuda de TuSalón. Cada respuesta sirve para todos los salones: si una está mal, edítala y sale bien para todos.</p>
        <p class="suave">IA: <?= !$estadoIa['configurada'] ? 'sin configurar (la Ayuda responde con estas preguntas y el manual)' : ($estadoIa['pausada'] ? 'en pausa' : 'encendida') ?>
            · <?= (int) $resumen['total'] ?> preguntas · <?= (int) $resumen['sin_aprobar'] ?> por revisar · <?= (int) $resumen['usos'] ?> usos</p></div>
</div>

<section class="bloque" id="agregar">
    <h2><?= $editar ? 'Editar pregunta' : 'Agregar pregunta' ?></h2>
    <form method="post" action="<?= e(url('admin_faq', ['filtro' => $filtro])) ?>" data-form-faq>
        <?= campo_csrf() ?><input type="hidden" name="accion" value="<?= $editar ? 'editar' : 'agregar' ?>">
        <?php if ($editar): ?><input type="hidden" name="id" value="<?= (int) $editar['id'] ?>"><?php endif; ?>
        <div class="campo"><label for="faq-p">Pregunta</label>
            <input id="faq-p" name="pregunta" maxlength="500" required value="<?= e($editar['pregunta'] ?? '') ?>" placeholder="¿Cómo registro un pago?"></div>
        <div class="campo"><label for="faq-r">Respuesta</label>
            <textarea id="faq-r" name="respuesta" rows="5" maxlength="6000" required><?= e($editar['respuesta'] ?? '') ?></textarea>
            <small class="suave">Puedes usar **negritas** y pasos 1. 2. 3. Al guardar queda aprobada.</small></div>
        <button class="boton" type="submit"><?= $editar ? 'Guardar' : 'Agregar pregunta' ?></button>
        <?php if ($editar): ?><a class="boton boton-claro" href="<?= e(url('admin_faq', ['filtro' => $filtro])) ?>">Cancelar</a><?php endif; ?>
    </form>
</section>

<section class="bloque">
    <p class="filtros"><?php foreach ($filtros as $k => $t): ?>
        <a class="boton boton-chico <?= $k === $filtro ? '' : 'boton-claro' ?>" href="<?= e(url('admin_faq', ['filtro' => $k])) ?>"><?= e($t) ?></a>
    <?php endforeach; ?></p>
    <?php if (!$lista): ?><p class="vacio">No hay preguntas en esta lista.</p><?php else: ?>
    <div class="desliza"><table class="tabla tabla-admin" data-tabla-faq>
        <thead><tr><th>Pregunta</th><th>Respuesta</th><th>Usos</th><th>Origen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($lista as $f): ?>
            <tr data-faq="<?= (int) $f['id'] ?>">
                <td><?= e($f['pregunta']) ?><?= $f['aprobada'] ? '' : ' <span class="etq etq-pendiente">Por revisar</span>' ?></td>
                <td><?= e(mb_strimwidth($f['respuesta'], 0, 160, '…')) ?></td>
                <td><?= (int) $f['usos'] ?></td>
                <td><?= $f['origen'] === 'manual' ? 'Escrita a mano' : 'Automática' ?></td>
                <td style="white-space:nowrap">
                    <?php if (!$f['aprobada']): ?>
                    <form method="post" action="<?= e(url('admin_faq', ['filtro' => $filtro])) ?>" style="display:inline"><?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button class="boton boton-chico" type="submit">Aprobar</button></form>
                    <?php endif; ?>
                    <a class="boton boton-claro boton-chico" href="<?= e(url('admin_faq', ['filtro' => $filtro, 'editar' => $f['id']])) ?>#agregar">Editar</a>
                    <form method="post" action="<?= e(url('admin_faq', ['filtro' => $filtro])) ?>" style="display:inline" onsubmit="return confirm('¿Borrar esta pregunta? Ya no se usará en la Ayuda.')"><?= campo_csrf() ?>
                        <input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit">Borrar</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
