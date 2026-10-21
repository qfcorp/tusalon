<?php
/**
 * «Enviar por correo» de la pantalla «Demo lista» (compartido/LEEME_CORREO.md §3). La contraseña viaja dentro del sello
 * cifrado (src/CorreoDemo.php), nunca en claro.
 * @var array $correo ['sello', 'para', 'listo', 'salon_id', 'estado' (null|'enviado'), 'error' (null|texto)]
 */
?>
<section class="bloque correo-demo" id="correo-demo" aria-labelledby="correo-demo-t" style="margin-top:12px">
    <h2 id="correo-demo-t">Enviar por correo</h2>
    <?php if (($correo['estado'] ?? null) === 'enviado'): ?>
        <p class="aviso aviso-ok" role="status" data-correo-enviado>Correo enviado a <strong><?= e($correo['para']) ?></strong> desde info@tukansoftware.com. Que revise también la carpeta de spam.</p>
    <?php elseif (empty($correo['listo'])): ?>
        <button class="boton" type="button" disabled data-correo-sin-configurar>Configure el correo de Tukán</button>
        <p class="suave" style="margin-top:8px">En el servidor, una sola vez:</p>
        <pre style="white-space:pre-wrap;word-break:break-all"><code><?= e(\TuSalon\CorreoDemo::comandoConfigurar()) ?></code></pre>
    <?php else: ?>
        <?php if (!empty($correo['error'])): ?><p class="aviso aviso-error" role="alert"><?= e($correo['error']) ?></p><?php endif; ?>
        <form method="post" action="<?= e(url('admin_demo_correo', ['id' => (int) $correo['salon_id']])) ?>" data-form-correo-demo>
            <?= campo_csrf() ?><input type="hidden" name="sello" value="<?= e($correo['sello']) ?>">
            <div class="campo"><label for="correo-para">Correo del cliente</label>
                <input id="correo-para" name="para" type="email" required maxlength="254" autocomplete="off" value="<?= e($correo['para']) ?>">
                <small class="suave">Le llega «Su demo de TuSalón está lista» con sus datos de entrada, desde info@tukansoftware.com.</small></div>
            <button class="boton" type="submit">Enviar por correo</button>
        </form>
    <?php endif; ?>
</section>
