<?php
$tipos = ['inicial' => 'Stock inicial', 'entrada' => 'Llegó', 'venta' => 'Vendido', 'uso' => 'Usado en el salón', 'ajuste' => 'Corrección de conteo'];
$formProducto = function (?array $p) {
    $s = $p ? 'e' : 'n';
    ob_start(); ?>
    <?= campo_csrf() ?>
    <input type="hidden" name="accion" value="<?= $p ? 'editar' : 'crear' ?>">
    <?php if ($p): ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>"><?php endif; ?>
    <div class="fila-campos">
        <div class="campo"><label for="nom<?= $s ?>">Nombre</label>
            <input type="text" id="nom<?= $s ?>" name="nombre" value="<?= e($p['nombre'] ?? '') ?>" placeholder="Shampoo keratina 500 ml" required></div>
        <div class="campo"><label for="mar<?= $s ?>">Marca <small>(opcional)</small></label>
            <input type="text" id="mar<?= $s ?>" name="marca" value="<?= e($p['marca'] ?? '') ?>"></div>
    </div>
    <fieldset class="campo" style="border:0;padding:0">
        <legend class="etiqueta" style="margin-bottom:6px">¿Para qué es?</legend>
        <div class="opciones" style="flex-direction:row;flex-wrap:wrap">
            <label class="opcion" style="flex:1"><input type="radio" name="para_venta" value="1" <?= ($p['para_venta'] ?? true) ? 'checked' : '' ?>>
                <span><strong>Lo vendo al cliente</strong><small>Aparece al cobrar</small></span></label>
            <label class="opcion" style="flex:1"><input type="radio" name="para_venta" value="0" <?= isset($p['para_venta']) && !$p['para_venta'] ? 'checked' : '' ?>>
                <span><strong>Lo uso en el salón</strong><small>Tintes, oxidantes, ampollas</small></span></label>
        </div>
    </fieldset>
    <div class="fila-campos">
        <div class="campo"><label for="pre<?= $s ?>">Precio al cliente</label>
            <input type="number" id="pre<?= $s ?>" name="precio" value="<?= e($p['precio'] ?? '') ?>" step="0.01" min="0" placeholder="0,00"></div>
        <div class="campo"><label for="cos<?= $s ?>">Lo que te cuesta</label>
            <input type="number" id="cos<?= $s ?>" name="costo" value="<?= e($p['costo'] ?? '') ?>" step="0.01" min="0" placeholder="0,00"></div>
    </div>
    <div class="fila-campos">
        <?php if (!$p): ?>
        <div class="campo"><label for="sto<?= $s ?>">¿Cuántos tienes hoy?</label>
            <input type="number" id="sto<?= $s ?>" name="stock" value="0" min="0" step="1"></div>
        <?php endif; ?>
        <div class="campo"><label for="min<?= $s ?>">Avisarme cuando queden</label>
            <input type="number" id="min<?= $s ?>" name="stock_minimo" value="<?= (int) ($p['stock_minimo'] ?? 2) ?>" min="0" step="1"></div>
    </div>
    <?php if ($p): ?>
        <label class="opcion" style="margin-bottom:14px"><input type="checkbox" name="activo" value="1" <?= $p['activo'] ? 'checked' : '' ?>>
            <span><strong>Producto activo</strong><small>Desmárcalo si ya no lo manejas (se guarda su historial).</small></span></label>
    <?php endif; ?>
    <button class="boton boton-ancho" type="submit"><?= $p ? 'Guardar cambios' : 'Agregar producto' ?></button>
    <?php return ob_get_clean();
};
?>
<div class="cabecera">
    <div>
        <h1>Inventario</h1>
        <p>Lo que vendes y lo que usas en el salón, con aviso cuando algo se acaba.</p>
    </div>
    <?php if ($producto): ?><a class="boton boton-claro" href="<?= e(url('inventario')) ?>">Ver todos</a><?php endif; ?>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<?php if (!$producto): ?>
<div class="rejilla rejilla-3">
    <div class="bloque"><p class="cifra"><?= count(array_filter($productos, fn($p) => $p['activo'])) ?></p><p class="cifra-nombre">productos activos</p></div>
    <div class="bloque"><p class="cifra"><?= dinero($valor['costo']) ?></p><p class="cifra-nombre">en el estante (lo que te costó)</p></div>
    <div class="bloque"><p class="cifra" style="<?= $bajos ? 'color:var(--rojo)' : '' ?>"><?= count($bajos) ?></p><p class="cifra-nombre">por acabarse</p></div>
</div>
<?php if ($bajos): ?>
    <p class="aviso aviso-error" role="status">Se están acabando: <?= e(implode(', ', array_map(fn($b) => "{$b['nombre']} ({$b['stock']})", $bajos))) ?>.</p>
<?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Tus productos</h2>
        <?php if (!$productos): ?>
            <p class="vacio">Todavía no tienes productos. Agrega el primero a la derecha.</p>
        <?php else: ?>
        <div class="desliza">
        <table class="tabla">
            <thead><tr><th>Producto</th><th class="der">Hay</th><th class="der">Precio</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($productos as $p): ?>
                <tr style="<?= $p['activo'] ? '' : 'opacity:.55' ?>">
                    <td><strong><?= e($p['nombre']) ?></strong><?= $p['marca'] ? '<br><small>' . e($p['marca']) . '</small>' : '' ?>
                        <br><span class="etq <?= $p['para_venta'] ? 'etq-empleado' : 'etq-porcentaje' ?>"><?= $p['para_venta'] ? 'Para vender' : 'Uso del salón' ?></span>
                        <?php if (!$p['activo']): ?><span class="etq etq-cancelada">Inactivo</span><?php endif; ?></td>
                    <td class="der"><strong style="<?= $p['bajo'] ? 'color:var(--rojo)' : '' ?>"><?= (int) $p['stock'] ?></strong><?= $p['bajo'] ? '<br><small style="color:var(--rojo)">¡Se acaba!</small>' : '' ?></td>
                    <td class="der"><?= $p['para_venta'] ? dinero($p['precio']) : '—' ?></td>
                    <td class="der"><a class="boton boton-claro boton-chico" href="<?= e(url('inventario', ['id' => $p['id']])) ?>">Abrir</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <form method="post" class="bloque">
        <h2>Agregar producto</h2>
        <?= $formProducto(null) ?>
    </form>
</div>

<?php else: $p = $producto; ?>
<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2><?= e($p['nombre']) ?></h2>
        <p style="font-size:1.1rem">Hay <strong style="<?= (int) $p['stock'] <= (int) $p['stock_minimo'] ? 'color:var(--rojo)' : '' ?>"><?= (int) $p['stock'] ?></strong>
            · aviso cuando queden <?= (int) $p['stock_minimo'] ?></p>

        <form method="post" class="caja-mov"><?= campo_csrf() ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="accion" value="entrada">
            <h3>Llegó mercadería</h3>
            <div class="fila-campos">
                <div class="campo"><label for="ce">Cantidad</label><input type="number" id="ce" name="cantidad" min="1" step="1" required></div>
                <div class="campo"><label for="ke">Costo de cada uno <small>(opcional)</small></label><input type="number" id="ke" name="costo" step="0.01" min="0" value="<?= e($p['costo']) ?>"></div>
            </div>
            <div class="campo"><label for="ne">Nota <small>(ej. número de factura)</small></label><input type="text" id="ne" name="nota" maxlength="200"></div>
            <button class="boton boton-chico" type="submit">Sumar al stock</button>
        </form>

        <form method="post" class="caja-mov"><?= campo_csrf() ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="accion" value="uso">
            <h3>Se usó en el salón</h3>
            <div class="fila-campos">
                <div class="campo"><label for="cu">Cantidad</label><input type="number" id="cu" name="cantidad" min="1" step="1" required></div>
                <div class="campo"><label for="nu">Para qué <small>(opcional)</small></label><input type="text" id="nu" name="nota" maxlength="200" placeholder="Tinte de Carla"></div>
            </div>
            <button class="boton boton-claro boton-chico" type="submit">Descontar</button>
        </form>

        <form method="post" class="caja-mov"><?= campo_csrf() ?><input type="hidden" name="producto_id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="accion" value="ajuste">
            <h3>Corregir conteo</h3>
            <p class="suave" style="font-size:.9rem">Cuenta lo que hay en el estante y escribe el número real.</p>
            <div class="fila-campos">
                <div class="campo"><label for="rr">Hay en realidad</label><input type="number" id="rr" name="real" min="0" step="1" required></div>
                <div class="campo"><label for="nr">Motivo <small>(opcional)</small></label><input type="text" id="nr" name="nota" maxlength="200" placeholder="Se rompió uno"></div>
            </div>
            <button class="boton boton-claro boton-chico" type="submit">Corregir</button>
        </form>

        <h3 style="margin-top:18px">Historial</h3>
        <?php if (!$movimientos): ?><p class="suave">Sin movimientos todavía.</p><?php else: ?>
        <ul class="lista">
            <?php foreach ($movimientos as $m): ?>
                <li><div><div class="principal-linea"><?= e($tipos[$m['tipo']] ?? $m['tipo']) ?></div>
                    <div class="linea-sub"><?= (new DateTimeImmutable($m['fecha']))->format('d/m/Y H:i') ?><?= $m['nota'] ? ' · ' . e($m['nota']) : '' ?><?= $m['usuario'] ? ' · ' . e($m['usuario']) : '' ?></div></div>
                    <span class="monto <?= (int) $m['cantidad'] < 0 ? 'monto-neg' : '' ?>"><?= (int) $m['cantidad'] > 0 ? '+' : '' ?><?= (int) $m['cantidad'] ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
    <form method="post" class="bloque">
        <h2>Datos del producto</h2>
        <?= $formProducto($p) ?>
    </form>
</div>
<?php endif; ?>
