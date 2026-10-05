<?php use TuSalon\Agenda; ?>
<div class="cabecera">
    <div>
        <h1>Avisos a clientes</h1>
        <p>Recordatorios, cumpleaños y clientes que no vuelven. Toca el botón verde y se abre WhatsApp con el mensaje listo.</p>
    </div>
</div>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Recordar las citas de mañana (<?= count($manana) ?>)</h2>
        <?php if (!$manana): ?><p class="vacio">No hay citas para mañana.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($manana as $c): $ini = new DateTimeImmutable($c['inicio']); ?>
                    <li style="flex-wrap:wrap">
                        <div style="flex:1;min-width:180px">
                            <div class="principal-linea"><?= $ini->format('H:i') ?> · <?= e($c['cliente']) ?></div>
                            <div class="linea-sub">con <?= e($c['profesional']) ?> ·
                                <?php if ($c['confirmada_cliente_en']): ?><strong style="color:var(--verde)">✅ confirmó</strong>
                                <?php elseif ($c['recordatorio_en']): ?>recordado, sin confirmar
                                <?php else: ?>sin recordar<?php endif; ?>
                                <?= $c['cliente_telegram'] ? ' · Telegram' : '' ?></div>
                        </div>
                        <div class="acciones">
                            <?php if ($c['wa'] && !$c['confirmada_cliente_en']): ?>
                                <a class="boton boton-wa boton-chico" href="<?= e($c['wa']) ?>" target="_blank" rel="noopener">WhatsApp</a>
                            <?php elseif (!$c['wa']): ?><small>Sin celular</small><?php endif; ?>
                            <?php if (!$c['recordatorio_en'] && !$c['confirmada_cliente_en']): ?>
                                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="recordado">
                                    <input type="hidden" name="cita" value="<?= (int) $c['id'] ?>">
                                    <button class="boton boton-claro boton-chico" type="submit">Ya lo envié</button></form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="suave">El mensaje lleva el valor y un enlace para que el cliente confirme o cancele con un toque.
                <?= es_completa($u) ? 'A los clientes con Telegram conectado se les envía solo, desde las 9:00.' : '' ?></p>
        <?php endif; ?>
    </section>

    <section class="bloque">
        <h2>🎂 Cumpleaños de hoy (<?= count($cumples) ?>)</h2>
        <?php if (!$cumples): ?><p class="vacio">Hoy no cumple años ningún cliente.</p><?php else: ?>
            <ul class="lista">
                <?php foreach ($cumples as $c): ?>
                    <li>
                        <div style="flex:1"><div class="principal-linea"><?= e($c['nombre']) ?></div>
                            <div class="linea-sub"><?= $c['saludado'] ? 'Ya saludado este año' : 'Por saludar' ?></div></div>
                        <div class="acciones">
                            <?php if ($c['wa']): ?><a class="boton boton-wa boton-chico" href="<?= e($c['wa']) ?>" target="_blank" rel="noopener">Saludar</a><?php endif; ?>
                            <?php if (!$c['saludado']): ?>
                                <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="saludado">
                                    <input type="hidden" name="cliente" value="<?= (int) $c['id'] ?>">
                                    <button class="boton boton-claro boton-chico" type="submit">Listo</button></form>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <p class="suave">El cumpleaños (día y mes) lo pone el cliente al crear su cuenta, o tú en su ficha.</p>
    </section>
</div>

<section class="bloque">
    <h2>Clientes por recuperar <small class="suave">(no vienen hace <?= $semanas ?> semanas o más)</small></h2>
    <?php if (!es_completa($u)): ?>
        <p class="candado">La lista de clientes por recuperar es del plan Completa.</p>
        <a class="boton" href="<?= e(url('completa')) ?>">Ver plan Completa</a>
    <?php elseif (!$recuperar): ?>
        <p class="vacio">¡Bien! Todos tus clientes frecuentes han vuelto.</p>
    <?php else: ?>
        <ul class="lista">
            <?php foreach ($recuperar as $c): ?>
                <li>
                    <div style="flex:1"><div class="principal-linea"><?= e($c['nombre']) ?></div>
                        <div class="linea-sub">Última vez hace <?= (int) $c['semanas'] ?> semanas · <?= (int) $c['visitas'] ?> <?= (int) $c['visitas'] === 1 ? 'visita' : 'visitas' ?></div></div>
                    <?php if ($c['wa']): ?><a class="boton boton-wa boton-chico" href="<?= e($c['wa']) ?>" target="_blank" rel="noopener">Invitar a volver</a>
                    <?php else: ?><small>Sin celular</small><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="suave">Las semanas se cambian en <a href="<?= e(url('horario')) ?>">Horario y reservas</a>.</p>
    <?php endif; ?>
</section>
