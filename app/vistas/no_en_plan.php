<?php
/** "No está en tu plan" (Anexo B): el módulo de esta página está apagado. Los datos siguen guardados. */
$cat = modulos()->catalogo();
$m = $cat[$modulo] ?? null;
$esDueno = $u && !es_peluquero($u) && !es_recepcion($u);
?>
<section class="bloque bloque-modulo" style="max-width:640px">
    <p class="etiqueta-plan">No está en tu plan</p>
    <h1><?= e(($m['icono'] ?? '') . ' ' . ($m['nombre'] ?? 'Esta función')) ?></h1>
    <p><?= e($m['descripcion'] ?? '') ?></p>
    <?php if ($esDueno): ?>
        <?php /* Regla del dueño: el precio de un módulo que el salón no tiene no se muestra; se pide cotización */ ?>
        <?php if (!en_app_play() && $m): ?><p>Pide la cotización y Tukán te escribe con el valor para agregarlo a tu plan.</p><?php endif; ?>
        <p class="suave">Si lo usaste antes, tus datos están guardados: al encenderlo vuelve todo como estaba.</p>
        <div class="acciones">
            <?php if (!en_app_play() && $m): ?>
                <form method="post" action="<?= e(url('completa')) ?>"><?= campo_csrf() ?><input type="hidden" name="accion" value="pedir"><input type="hidden" name="modulo" value="<?= e($modulo) ?>">
                    <button class="boton" type="submit">Pedir cotización</button></form>
            <?php endif; ?>
            <a class="boton boton-claro" href="<?= e(url('completa', ['modulo' => $modulo])) ?>">Ver Mi plan</a>
        </div>
    <?php else: ?>
        <p class="suave">Pídele al dueño del salón que lo agregue a su plan.</p>
        <div class="acciones"><a class="boton boton-claro" href="<?= e(url(es_peluquero($u) ? 'mi_portal' : 'inicio')) ?>">Volver</a></div>
    <?php endif; ?>
</section>
