<?php
$tipos = ['dueno' => 'Dueño', 'empleado' => 'Empleado', 'porcentaje' => 'Porcentaje', 'alquiler' => 'Alquila puesto'];
$num = fn($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
$resumenRegla = function (array $p) use ($num): string {
    return match ($p['tipo']) {
        'dueno'      => 'Atiende en su silla. No cobra comisión.',
        'empleado'   => ((float) $p['sueldo_mensual'] > 0 ? 'Sueldo ' . dinero($p['sueldo_mensual']) . ' + ' : '')
                        . $num($p['comision_servicio_pct']) . ' % de servicios'
                        . ((float) $p['comision_producto_pct'] > 0 ? ', ' . $num($p['comision_producto_pct']) . ' % de productos' : '')
                        . ($p['afiliado_iess'] ? ' · afiliado al IESS' : ''),
        'porcentaje' => 'Se lleva el ' . $num($p['pct_profesional']) . ' % · cobra '
                        . ($p['quien_cobra'] === 'profesional' ? 'él directamente' : 'la caja del local'),
        'alquiler'   => 'Paga ' . dinero($p['monto_arriendo']) . ' ' . ['semanal' => 'por semana', 'quincenal' => 'por quincena', 'mensual' => 'al mes'][$p['frecuencia_arriendo']]
                        . ' · cobra con su propio QR',
    };
};
?>
<div class="cabecera">
    <h1>Equipo</h1>
    <div class="acciones">
        <a class="boton boton-claro" href="<?= e(url('servicios')) ?>">Servicios</a>
        <a class="boton boton-claro" href="<?= e(url('cuentas')) ?>">Cuentas</a>
    </div>
</div>

<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Quiénes trabajan aquí</h2>
        <ul class="lista">
            <?php foreach ($equipo as $p): ?>
                <li>
                    <div style="flex:1">
                        <div class="principal-linea"><?= e($p['nombre']) ?> <span class="etq etq-<?= e($p['tipo']) ?>"><?= $tipos[$p['tipo']] ?></span></div>
                        <div class="linea-sub"><?= e($resumenRegla($p)) ?></div>
                    </div>
                    <?php if ($p['tipo'] !== 'dueno'): ?>
                        <div style="text-align:right">
                            <div class="monto <?= $p['saldo'] < 0 ? 'monto-neg' : '' ?>"><?= dinero($p['saldo']) ?></div>
                            <small><?= $p['saldo'] < 0 ? 'te debe' : ($p['saldo'] > 0 ? 'le debes' : 'al día') ?></small>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!es_completa($u)): ?>
            <p class="candado" style="margin-top:12px">Acceso de cada peluquero desde su celular: <a href="<?= e(url('completa')) ?>">plan Completa</a></p>
        <?php endif; ?>
    </section>

    <?php if (!$puedeAgregar): ?>
        <section class="bloque">
            <h2>Agregar peluquero</h2>
            <p>El plan Básica permite hasta 3 personas, contándote a ti.</p>
            <a class="boton" href="<?= e(url('completa')) ?>">Pasar al plan Completa</a>
        </section>
    <?php else: ?>
    <form method="post" class="bloque">
        <?= campo_csrf() ?>
        <h2>Agregar peluquero</h2>
        <div class="fila-campos">
            <div class="campo"><label for="nombre">Nombre</label><input type="text" id="nombre" name="nombre" required></div>
            <div class="campo"><label for="telefono">Celular</label><input type="tel" id="telefono" name="telefono" placeholder="0991234567"></div>
        </div>

        <fieldset class="campo" style="border:0;padding:0">
            <legend class="etiqueta" style="margin-bottom:6px">¿Cómo trabaja?</legend>
            <div class="opciones">
                <label class="opcion"><input type="radio" name="tipo" value="empleado" checked>
                    <span><strong>Empleado</strong><small>Le pagas sueldo, comisión o las dos cosas.</small></span></label>
                <label class="opcion"><input type="radio" name="tipo" value="porcentaje">
                    <span><strong>Por porcentaje</strong><small>Se reparten cada corte: 50/50, 60/40…</small></span></label>
                <label class="opcion"><input type="radio" name="tipo" value="alquiler">
                    <span><strong>Alquila el puesto</strong><small>Te paga un arriendo fijo y cobra a sus clientes.</small></span></label>
            </div>
        </fieldset>

        <div data-tipo="empleado">
            <div class="fila-campos">
                <div class="campo"><label for="sueldo_mensual">Sueldo al mes <small>(0 si es solo comisión)</small></label>
                    <input type="number" id="sueldo_mensual" name="sueldo_mensual" value="0" step="0.01" min="0"></div>
                <div class="campo"><label for="comision_servicio_pct">Comisión de servicios (%)</label>
                    <input type="number" id="comision_servicio_pct" name="comision_servicio_pct" value="40" step="0.5" min="0" max="100"></div>
            </div>
            <div class="campo"><label for="comision_producto_pct">Comisión de productos (%)</label>
                <input type="number" id="comision_producto_pct" name="comision_producto_pct" value="10" step="0.5" min="0" max="100"></div>
            <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="afiliado_iess" value="1">
                <span><strong>Está afiliado al IESS</strong><small>Se completa hasta el básico y se calcula el aporte.</small></span></label>
        </div>

        <div data-tipo="porcentaje">
            <div class="campo"><label for="pct_profesional">Porcentaje para el peluquero (%)</label>
                <input type="number" id="pct_profesional" name="pct_profesional" value="50" step="1" min="1" max="99"></div>
            <div class="campo"><span class="etiqueta">¿Quién recibe el dinero del cliente?</span>
                <div class="opciones">
                    <label class="opcion"><input type="radio" name="quien_cobra" value="local" checked><span><strong>La caja del local</strong><small>Al cerrar, el local le paga su parte.</small></span></label>
                    <label class="opcion"><input type="radio" name="quien_cobra" value="profesional"><span><strong>Él directamente</strong><small>Al cerrar, él le paga al local su parte.</small></span></label>
                </div>
            </div>
        </div>

        <div data-tipo="alquiler">
            <div class="fila-campos">
                <div class="campo"><label for="monto_arriendo">Valor del arriendo</label>
                    <input type="number" id="monto_arriendo" name="monto_arriendo" step="0.01" min="0"></div>
                <div class="campo"><label for="frecuencia_arriendo">Cada cuánto</label>
                    <select id="frecuencia_arriendo" name="frecuencia_arriendo">
                        <option value="semanal">Semanal</option><option value="quincenal">Quincenal</option><option value="mensual">Mensual</option>
                    </select></div>
            </div>
        </div>
        <button class="boton boton-ancho" type="submit">Agregar al equipo</button>
    </form>
    <?php endif; ?>
</div>
