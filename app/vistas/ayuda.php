<?php /** @var string $pregunta @var ?array $respuesta @var ?string $error */ ?>
<div class="cabecera">
    <div>
        <h1>¿En qué le ayudamos?</h1>
        <p>Pregunte cómo hacer algo en TuSalón y le respondemos con los pasos del manual.</p>
    </div>
</div>
<section class="bloque" data-ayuda>
    <form method="post" action="<?= e(url('ayuda')) ?>">
        <?= campo_csrf() ?>
        <div class="campo"><label for="pregunta">Escriba su pregunta</label>
            <textarea id="pregunta" name="pregunta" rows="3" maxlength="500" required placeholder="¿Cómo registro un pago?"><?= e($pregunta) ?></textarea></div>
        <button class="boton" type="submit">Preguntar</button>
    </form>
    <?php if ($error): ?><p class="aviso aviso-error" role="alert" style="margin-top:12px"><?= e($error) ?></p><?php endif; ?>
</section>
<?php if ($respuesta): ?>
    <section class="bloque" data-respuesta-ayuda aria-live="polite">
        <h2>Respuesta</h2>
        <div class="respuesta-ayuda"><?= \TuSalon\Ia::html((string) $respuesta['respuesta']) ?></div>
        <p class="suave" style="margin-top:12px">¿Le sirvió? Si no, escríbanos por WhatsApp <a href="https://wa.me/593996408397" target="_blank" rel="noopener">099 640 8397</a>.</p>
    </section>
<?php endif; ?>
