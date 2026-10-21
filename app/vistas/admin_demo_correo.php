<?php /** @var ?array $salon @var array $correo @var bool $caducado */ ?>
<p><a href="<?= e(url($salon ? 'admin_salon' : 'admin_salones', $salon ? ['id' => $salon['id']] : [])) ?>">← <?= $salon ? e($salon['nombre']) : 'Todos los salones' ?></a></p>
<h1>Demo lista · Enviar por correo</h1>
<?php if ($caducado): ?>
    <section class="bloque" role="alert" data-correo-caducado>
        <p>Este envío ya caducó (pasaron más de 30 minutos, ya se envió o se cerró la sesión). La contraseña de la demo solo se muestra una vez:
           si hace falta mandarla otra vez, crea una contraseña nueva para el dueño en la ficha del salón.</p>
    </section>
<?php else: ?>
    <?php require __DIR__ . '/_correo_demo.php'; ?>
<?php endif; ?>
