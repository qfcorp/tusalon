<?php use TuSalon\Agenda; ?>
<div class="cabecera">
    <div>
        <h1>Horario y reservas en línea</h1>
        <p>Tus clientes ven las horas libres de cada peluquero según este horario.</p>
    </div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tu página de reservas</h2>
        <?php if (es_completa($u)): ?>
            <p>Comparte este enlace en tu Instagram, Facebook y estado de WhatsApp:</p>
            <p><input type="text" value="<?= e($enlace) ?>" readonly onclick="this.select()" aria-label="Enlace de reservas"></p>
            <div class="acciones">
                <a class="boton" href="<?= e($enlace) ?>" target="_blank" rel="noopener">Ver mi página</a>
                <a class="boton boton-wa" href="https://wa.me/?text=<?= rawurlencode('Reserva tu cita en ' . $u['salon'] . ': ' . $enlace) ?>" target="_blank" rel="noopener">Compartir por WhatsApp</a>
            </div>
        <?php else: ?>
            <p class="candado">Las reservas en línea son del plan Completa.</p>
            <a class="boton" href="<?= e(url('completa')) ?>">Ver plan Completa</a>
        <?php endif; ?>
    </section>

    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <h2>Días y horas de atención</h2>
        <?php for ($d = 1; $d <= 7; $d++): $i = $d % 7; $h = $horario[$i] ?? null; ?>
            <div class="fila-horario">
                <label class="opcion" style="padding:8px 10px">
                    <input type="checkbox" name="abierto[<?= $i ?>]" value="1" <?= $h ? 'checked' : '' ?>>
                    <span><strong><?= Agenda::DIAS[$i] ?></strong></span>
                </label>
                <input type="time" name="abre[<?= $i ?>]" value="<?= e($h['abre'] ?? '09:00') ?>" aria-label="Abre el <?= Agenda::DIAS[$i] ?>">
                <input type="time" name="cierra[<?= $i ?>]" value="<?= e($h['cierra'] ?? '19:00') ?>" aria-label="Cierra el <?= Agenda::DIAS[$i] ?>">
            </div>
        <?php endfor; ?>
        <div class="fila-campos" style="margin-top:12px">
            <div class="campo"><label for="intervalo_reservas">Ofrecer horas cada</label>
                <select id="intervalo_reservas" name="intervalo_reservas">
                    <?php foreach ([10, 15, 20, 30, 60] as $m): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['intervalo_reservas'] === $m ? 'selected' : '' ?>><?= $m ?> minutos</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="anticipacion_minutos">Reservar con al menos</label>
                <select id="anticipacion_minutos" name="anticipacion_minutos">
                    <?php foreach ([0 => 'Sin mínimo', 30 => '30 minutos', 60 => '1 hora', 120 => '2 horas', 1440 => '1 día'] as $m => $txt): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['anticipacion_minutos'] === $m ? 'selected' : '' ?>><?= $txt ?> de anticipación</option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <h2 style="margin-top:18px">Cuando un cliente reserva en línea</h2>
        <div class="campo"><span class="etiqueta">¿Quién acepta la cita?</span>
            <div class="opciones">
                <?php foreach (['dueno' => ['Solo el dueño', 'Llega como solicitud y tú la aceptas o la rechazas.'],
                                'peluquero' => ['El peluquero de la cita', 'Cada peluquero acepta las suyas desde su portal (tú también puedes).'],
                                'cualquiera' => ['El dueño o el peluquero', 'La acepta el primero que la vea.'],
                                'automatico' => ['Nadie: se reserva sola', 'La cita queda reservada al instante.']] as $k => [$tit, $sub]): ?>
                    <label class="opcion"><input type="radio" name="acepta_reservas" value="<?= $k ?>" <?= $conf['acepta_reservas'] === $k ? 'checked' : '' ?>>
                        <span><strong><?= $tit ?></strong><small><?= $sub ?></small></span></label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="avisar_a">Avisar a</label>
                <select id="avisar_a" name="avisar_a">
                    <?php foreach (['ambos' => 'Al dueño y al peluquero', 'dueno' => 'Solo al dueño', 'peluquero' => 'Solo al peluquero'] as $k => $txt): ?>
                        <option value="<?= $k ?>" <?= $conf['avisar_a'] === $k ? 'selected' : '' ?>><?= $txt ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="minutos_para_aceptar">Si nadie responde, liberar la hora en</label>
                <select id="minutos_para_aceptar" name="minutos_para_aceptar">
                    <?php foreach ([30 => '30 minutos', 60 => '1 hora', 120 => '2 horas', 240 => '4 horas', 720 => '12 horas', 1440 => '1 día'] as $m => $txt): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['minutos_para_aceptar'] === $m ? 'selected' : '' ?>><?= $txt ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <p class="suave">Mientras la solicitud espera, esa hora aparece "en confirmación" para los demás clientes.</p>
        <button class="boton boton-ancho" type="submit">Guardar</button>
    </form>
</div>
