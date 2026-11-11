<section class="tk-tarjeta bloque" style="max-width:560px">
    <h1>No se pudo completar</h1>
    <p><?= e($mensaje) ?></p>
    <?php if (!empty($salir)): ?>
        <a class="tk-boton boton" href="<?= e(url('salir')) ?>">Cerrar sesión</a>
    <?php else: ?>
        <a class="tk-boton boton" href="<?= e(url('inicio')) ?>">Volver al inicio</a>
    <?php endif; ?>
</section>
