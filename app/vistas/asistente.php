<?php
use TuSalon\Asistente;
/** @var string $mensaje @var int $clienteId @var ?array $borrador @var ?array $recuperar @var ?array $neutro @var array $inactivos @var array $clientes */
$dias = \TuSalon\Agenda::DIAS;
?>
<div class="cabecera">
    <div>
        <h1>✨ Asistente IA</h1>
        <p>Arma la cita con el mensaje que te mandó el cliente y escribe el mensaje para los clientes que no vuelven. Tú revisas antes de guardar o enviar.</p>
    </div>
</div>

<div class="rejilla rejilla-2">
<section class="bloque" id="agendar" data-asistente-agendar>
    <h2>Agendar a partir de un mensaje</h2>
    <form method="post" action="<?= e(url('asistente')) ?>#agendar">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="agendar">
        <div class="campo"><label for="mensaje">Mensaje del cliente</label>
            <textarea id="mensaje" name="mensaje" rows="4" maxlength="<?= Asistente::MAX_MENSAJE ?>" required placeholder="Quiero corte y tinte el viernes en la tarde"><?= e($mensaje) ?></textarea>
            <small class="suave">Copia y pega el mensaje de WhatsApp tal cual.</small></div>
        <div class="campo"><label for="cliente_id">Cliente <small>(opcional)</small></label>
            <select id="cliente_id" name="cliente_id"><option value="0">— Lo elijo después —</option>
                <?php foreach ($clientes as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $clienteId === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nombre']) ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?></option><?php endforeach; ?>
            </select></div>
        <button class="boton" type="submit">Proponer la cita</button>
    </form>
    <?php if ($neutro && $neutro['seccion'] === 'agendar'): ?><p class="aviso" role="status" style="margin-top:12px" data-ia-neutro><?= $neutro['html'] ?></p><?php endif; ?>
    <?php if ($borrador): $f = new DateTimeImmutable($borrador['fecha']); ?>
        <div class="aviso aviso-ok" style="margin-top:12px" data-borrador-cita data-fecha="<?= e($borrador['fecha']) ?>" data-hora="<?= e($borrador['hora']) ?>" data-profesional="<?= (int) $borrador['profesional_id'] ?>">
            <p><strong>Borrador de la cita</strong> (todavía no está guardada)</p>
            <p><?= e(implode(' + ', $borrador['servicios_nombres'])) ?> · <?= (int) $borrador['minutos'] ?> min<br>
               con <strong><?= e($borrador['profesional']) ?></strong><br>
               el <strong><?= e(mb_strtolower($dias[(int) $f->format('w')])) ?> <?= $f->format('d/m/Y') ?> a las <?= e($borrador['hora']) ?></strong>
               <?= $borrador['cliente'] !== '' ? '<br>Cliente: ' . e($borrador['cliente']) : '' ?>
               <?= $borrador['nota'] !== '' ? '<br>Nota: ' . e($borrador['nota']) : '' ?></p>
            <?php if (!$borrador['exacta']): ?><p class="suave" data-hora-ajustada>La hora que pidió no estaba libre en la agenda: esta es la más cercana.</p><?php endif; ?>
            <a class="boton" href="<?= e(Asistente::enlaceCita($borrador, $clienteId ?: null)) ?>">Revisar y confirmar</a>
        </div>
    <?php endif; ?>
</section>

<section class="bloque" id="recuperar" data-asistente-recuperar>
    <h2>Mensaje para clientes que no vuelven</h2>
    <?php if ($neutro && $neutro['seccion'] === 'recuperar'): ?><p class="aviso" role="status" data-ia-neutro><?= $neutro['html'] ?></p><?php endif; ?>
    <?php if ($recuperar): ?>
        <div class="aviso aviso-ok" data-mensaje-recuperar>
            <p><strong>Para <?= e($recuperar['cliente']['nombre']) ?></strong></p>
            <p style="white-space:pre-line"><?= e($recuperar['texto']) ?></p>
            <?php if ($recuperar['wa']): ?><a class="boton boton-wa" href="<?= e($recuperar['wa']) ?>" target="_blank" rel="noopener">Enviar por WhatsApp</a>
            <?php else: ?><small>Este cliente no tiene celular: copia el mensaje.</small><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!$inactivos): ?><p class="vacio">No hay clientes que hayan dejado de venir.</p><?php else: ?>
        <ul class="lista">
        <?php foreach (array_slice($inactivos, 0, 30) as $c): ?>
            <li style="flex-wrap:wrap">
                <div style="flex:1;min-width:160px"><div class="principal-linea"><?= e($c['nombre']) ?></div>
                    <div class="linea-sub">Hace <?= (int) $c['semanas'] ?> semanas · <?= (int) $c['visitas'] ?> visitas</div></div>
                <form method="post" action="<?= e(url('asistente')) ?>#recuperar"><?= campo_csrf() ?><input type="hidden" name="accion" value="recuperar">
                    <input type="hidden" name="cliente" value="<?= (int) $c['id'] ?>">
                    <button class="boton boton-claro boton-chico" type="submit">Escribir mensaje</button></form>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
</div>
