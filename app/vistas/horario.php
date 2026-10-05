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

        <h2 style="margin-top:18px">Reglas para tus clientes</h2>
        <div class="fila-campos">
            <div class="campo"><label for="horas_cancelacion">El cliente puede cancelar o cambiar hasta</label>
                <select id="horas_cancelacion" name="horas_cancelacion">
                    <?php foreach ([0 => 'Cualquier momento', 1 => '1 hora antes', 2 => '2 horas antes', 3 => '3 horas antes', 6 => '6 horas antes',
                                    12 => '12 horas antes', 24 => '1 día antes', 48 => '2 días antes'] as $m => $txt): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['horas_cancelacion'] === $m ? 'selected' : '' ?>><?= $txt ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="max_faltas">Bloquear reservas en línea tras</label>
                <select id="max_faltas" name="max_faltas">
                    <?php foreach ([0 => 'Nunca bloquear', 1 => '1 falta', 2 => '2 faltas', 3 => '3 faltas', 5 => '5 faltas'] as $m => $txt): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['max_faltas'] === $m ? 'selected' : '' ?>><?= $txt ?></option>
                    <?php endforeach; ?>
                </select>
                <small>Falta = no llegó y no avisó. Puedes perdonarlas en la ficha del cliente.</small></div>
        </div>
        <div class="fila-campos">
            <div class="campo"><label for="semanas_sin_volver">Avisarme de clientes que no vuelven en</label>
                <select id="semanas_sin_volver" name="semanas_sin_volver">
                    <?php foreach ([3, 4, 5, 6, 8, 10, 12] as $m): ?>
                        <option value="<?= $m ?>" <?= (int) $conf['semanas_sin_volver'] === $m ? 'selected' : '' ?>><?= $m ?> semanas</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="campo"><label for="google_resenas_url">Enlace para reseñas en Google <small>(opcional)</small></label>
                <input type="url" id="google_resenas_url" name="google_resenas_url" value="<?= e($conf['google_resenas_url'] ?? '') ?>"
                       placeholder="https://g.page/r/...">
                <small>A quien te califique con 4 o 5 estrellas se le invita a dejar su reseña en Google.</small></div>
        </div>
        <button class="boton boton-ancho" type="submit">Guardar</button>
    </form>
</div>
