<section class="bloque">
    <?php if ($salon): ?>
        <h1><?= e($salon['nombre']) ?></h1>
        <p>Este salón todavía no recibe reservas en línea.</p>
        <?php if ($salon['telefono']): $wa = \TuSalon\WhatsApp::enlace($salon['telefono'], 'Hola, quiero agendar una cita.'); ?>
            <?php if ($wa): ?><a class="boton boton-wa" href="<?= e($wa) ?>">Escribir por WhatsApp</a><?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <h1>No encontramos este salón</h1>
        <p>Revisa el enlace que te compartieron.</p>
    <?php endif; ?>
</section>
