<?php
use TuSalon\{FacturasRecibidas, Sri};
$cats = FacturasRecibidas::CATEGORIAS;
$origenes = ['xml' => 'XML', 'txt' => 'Reporte TXT', 'sri' => 'SRI', 'clave' => 'Clave de acceso'];
$ganancia = $resumen['ganancia_despues_de_gastos'];
?>
<div class="cabecera">
    <div>
        <h1>Gastos y facturas</h1>
        <p>Las facturas que te dan tus proveedores, leídas del SRI. TuSalón no hace facturas: solo lee las que recibes.</p>
    </div>
    <form method="get" class="acciones">
        <input type="hidden" name="r" value="gastos">
        <?php if ($categoria !== ''): ?><input type="hidden" name="cat" value="<?= e($categoria) ?>"><?php endif; ?>
        <select name="mes" onchange="this.form.submit()" aria-label="Mes">
            <?php foreach ($meses as $m => $txt): ?><option value="<?= $m ?>" <?= $m === $mes ? 'selected' : '' ?>><?= e($txt) ?></option><?php endforeach; ?>
        </select>
        <noscript><button class="boton boton-chico" type="submit">Ver</button></noscript>
    </form>
</div>

<div class="rejilla rejilla-3 cifras-largas">
    <div class="bloque"><div class="cifra"><?= dinero($resumen['ganancia_local']) ?></div><p class="cifra-nombre">Ganancia del local</p></div>
    <div class="bloque"><div class="cifra monto-neg"><?= dinero(-$resumen['gastos_facturas']) ?></div><p class="cifra-nombre">Gastos con facturas</p></div>
    <div class="bloque"><div class="cifra <?= $ganancia < 0 ? 'monto-neg' : '' ?>"><?= dinero($ganancia) ?></div><p class="cifra-nombre">Ganancia real</p></div>
</div>

<?php if ($porCompletar > 0): ?>
    <form method="post" class="aviso franja-avisos" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between">
        <?= campo_csrf() ?><input type="hidden" name="accion" value="completar">
        <span><?= $porCompletar ?> <?= $porCompletar === 1 ? 'factura llegó' : 'facturas llegaron' ?> del reporte TXT sin el detalle de lo que compraste.</span>
        <button class="boton boton-chico" type="submit">Completar con el SRI</button>
    </form>
<?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Subir facturas</h2>
        <details open>
            <summary style="cursor:pointer;font-weight:600;margin-bottom:8px">Archivos XML</summary>
            <p class="suave" style="font-size:.92rem">El XML que te manda el proveedor por correo, o los que bajas de <strong>SRI en línea → Comprobantes electrónicos recibidos</strong>. Puedes elegir varios o un .zip.</p>
            <form method="post" enctype="multipart/form-data">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="xml">
                <div class="campo"><label for="xml">Archivos</label>
                    <input type="file" id="xml" name="xml[]" accept=".xml,.zip,text/xml,application/xml,application/zip" multiple required></div>
                <button class="boton boton-ancho" type="submit">Subir XML</button>
            </form>
        </details>
        <details style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:8px">Reporte del SRI (.txt)</summary>
            <p class="suave" style="font-size:.92rem">En SRI en línea: <strong>Facturación electrónica → Comprobantes electrónicos recibidos</strong>, elige el mes y toca <strong>Descargar reporte</strong>. Trae todas las facturas del mes de una vez.</p>
            <form method="post" enctype="multipart/form-data">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="txt">
                <div class="campo"><label for="txt">Archivo .txt</label>
                    <input type="file" id="txt" name="txt" accept=".txt,.csv,text/plain" required></div>
                <button class="boton boton-claro boton-ancho" type="submit">Subir reporte</button>
            </form>
        </details>
        <details style="margin-top:14px">
            <summary style="cursor:pointer;font-weight:600;margin-bottom:8px">Claves de acceso</summary>
            <p class="suave" style="font-size:.92rem">El número de 49 dígitos que viene en la factura (debajo del código de barras). Pega una o varias; las buscamos en el SRI (hasta <?= FacturasRecibidas::MAX_SRI ?> por vez).</p>
            <form method="post">
                <?= campo_csrf() ?><input type="hidden" name="accion" value="claves">
                <div class="campo"><label for="claves">Claves</label>
                    <textarea id="claves" name="claves" rows="3" inputmode="numeric" placeholder="1410202601179000000000120010010000012341234567811" required></textarea></div>
                <button class="boton boton-claro boton-ancho" type="submit">Buscar en el SRI</button>
            </form>
        </details>
    </section>

    <section class="bloque">
        <h2>Gastos de <?= e(mb_strtolower($meses[$mes] ?? $mes)) ?></h2>
        <?php if (!$resumen['gastos_por_categoria']): ?>
            <p class="vacio">Todavía no hay facturas en este mes.</p>
        <?php else: ?>
            <ul class="lista">
                <?php foreach ($resumen['gastos_por_categoria'] as $k => $v): ?>
                    <li><a href="<?= e(url('gastos', ['mes' => $mes, 'cat' => $k])) ?>" style="flex:1;color:inherit"><?= e($cats[$k] ?? $k) ?></a><span class="monto"><?= dinero($v) ?></span></li>
                <?php endforeach; ?>
                <li><strong>Total</strong><strong class="monto"><?= dinero($resumen['gastos_facturas']) ?></strong></li>
            </ul>
        <?php endif; ?>
        <form method="post" style="margin-top:16px">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="ruc">
            <div class="campo"><label for="ruc">RUC del salón <small>(para avisarte si una factura viene a nombre de otra persona)</small></label>
                <input type="text" id="ruc" name="ruc" value="<?= e($rucSalon) ?>" inputmode="numeric" maxlength="13" placeholder="1790000000001"></div>
            <button class="boton boton-claro boton-chico" type="submit">Guardar RUC</button>
        </form>
    </section>
</div>

<section class="bloque">
    <div class="cabecera" style="margin-bottom:8px">
        <h2 style="margin:0">Facturas<?= $categoria !== '' ? ' · ' . e($cats[$categoria]) : '' ?></h2>
        <?php if ($categoria !== ''): ?><a class="boton boton-claro boton-chico" href="<?= e(url('gastos', ['mes' => $mes])) ?>">Ver todas</a><?php endif; ?>
    </div>
    <?php if (!$facturas): ?>
        <p class="vacio">No hay facturas<?= $categoria !== '' ? ' de esta categoría' : '' ?> en este mes.</p>
    <?php else: ?>
        <ul class="lista">
        <?php foreach ($facturas as $f):
            $nc = $f['tipo'] === 'nota_credito';
            $ajena = $rucSalon !== '' && $f['id_comprador'] && substr(preg_replace('/\D/', '', $f['id_comprador']), 0, 10) !== substr($rucSalon, 0, 10); ?>
            <li style="flex-wrap:wrap;align-items:flex-start">
                <div style="flex:1;min-width:200px">
                    <div class="principal-linea"><?= e($f['proveedor'] ?: $f['ruc_proveedor']) ?>
                        <?php if ($nc): ?><span class="etq etq-confirmada">Nota de crédito</span><?php elseif ($f['tipo'] !== 'factura'): ?><span class="etq etq-reservada"><?= e(Sri::TIPOS[$f['tipo']] ?? $f['tipo']) ?></span><?php endif; ?>
                        <?php if (!$f['completa']): ?><span class="etq etq-pendiente">Sin detalle</span><?php endif; ?>
                        <?php if ($ajena): ?><span class="etq etq-rechazada">A nombre de otra persona</span><?php endif; ?></div>
                    <div class="linea-sub"><?= (new DateTimeImmutable($f['fecha_emision']))->format('d/m/Y') ?><?= $f['numero'] ? ' · N.º ' . e($f['numero']) : '' ?> · <?= e($origenes[$f['origen']] ?? $f['origen']) ?></div>
                    <?php if ($f['detalle']): ?><div class="linea-sub"><?= e($f['detalle']) ?></div><?php endif; ?>
                    <form method="post" class="acciones" style="margin-top:6px;align-items:center">
                        <?= campo_csrf() ?><input type="hidden" name="accion" value="categoria"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <select name="categoria" aria-label="Categoría de la factura de <?= e($f['proveedor'] ?: $f['ruc_proveedor']) ?>" style="width:auto;min-width:0;flex:1" onchange="this.form.submit()">
                            <?php foreach ($cats as $k => $t): ?><option value="<?= $k ?>" <?= $f['categoria'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
                        </select>
                        <noscript><button class="boton boton-claro boton-chico" type="submit">Guardar</button></noscript>
                    </form>
                </div>
                <div style="text-align:right">
                    <div class="monto <?= $nc ? '' : 'monto-neg' ?>"><?= dinero($nc ? $f['total'] : -$f['total']) ?></div>
                    <?php if ((float) $f['iva'] > 0): ?><small>IVA <?= dinero($f['iva']) ?></small><br><?php endif; ?>
                    <form method="post" style="margin-top:6px"><?= campo_csrf() ?><input type="hidden" name="accion" value="borrar"><input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitar esta factura de tus gastos? (por ejemplo, si era personal)">Quitar</button></form>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <p class="suave" style="margin-top:12px;font-size:.9rem">Cada proveedor recuerda su categoría: cuando cambias una, las demás facturas de ese proveedor y las próximas se clasifican igual. Las notas de crédito restan.</p>
</section>
