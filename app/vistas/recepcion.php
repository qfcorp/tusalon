<div class="cabecera">
    <div>
        <h1>Recepción</h1>
        <p>Cuentas para quien atiende el mostrador: agenda, cobra y cierra la caja sin ver tus ganancias.</p>
    </div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque">
        <h2>Quiénes entran como recepción</h2>
        <?php if (!$cuentas): ?>
            <p class="vacio">Todavía no hay cuentas de recepción.</p>
        <?php else: ?>
            <ul class="lista">
            <?php foreach ($cuentas as $c): ?>
                <li style="flex-wrap:wrap;align-items:flex-start">
                    <div style="flex:1;min-width:180px">
                        <div class="principal-linea"><?= e($c['nombre']) ?> <?php if (!$c['activo']): ?><span class="etq etq-reservada">Sin acceso</span><?php endif; ?></div>
                        <div class="linea-sub"><?= e($c['email']) ?></div>
                        <?php if ($c['activo']): ?>
                        <details style="margin-top:6px">
                            <summary style="cursor:pointer;font-size:.9rem;color:var(--verde)">Cambiar contraseña</summary>
                            <form method="post" style="margin-top:8px">
                                <?= campo_csrf() ?><input type="hidden" name="accion" value="clave"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <div class="campo"><label for="rc<?= (int) $c['id'] ?>">Nueva contraseña</label>
                                    <input type="text" id="rc<?= (int) $c['id'] ?>" name="clave" minlength="8" required autocomplete="off"></div>
                                <button class="boton boton-chico" type="submit">Guardar</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </div>
                    <form method="post"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <?php if ($c['activo']): ?>
                            <input type="hidden" name="accion" value="desactivar">
                            <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitarle el acceso a <?= e($c['nombre']) ?>?">Quitar acceso</button>
                        <?php else: ?>
                            <input type="hidden" name="accion" value="activar">
                            <button class="boton boton-claro boton-chico" type="submit">Devolver acceso</button>
                        <?php endif; ?>
                    </form>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h3 style="margin-top:18px">Qué puede hacer</h3>
        <ul style="margin:0;padding-left:20px">
            <li>Ver la agenda, crear citas y responder las solicitudes en línea</li>
            <li>Cobrar, confirmar anticipos y cerrar la caja del día</li>
            <li>Ver y crear clientes, y mandar recordatorios por WhatsApp</li>
        </ul>
        <h3 style="margin-top:12px">Qué no ve</h3>
        <ul style="margin:0;padding-left:20px">
            <li>La ganancia del local, los gastos ni el reporte del mes</li>
            <li>Las cuentas del equipo ni lo que gana cada peluquero</li>
            <li>Servicios, precios, horario, equipo, inventario ni tu plan</li>
            <li>Los clientes privados de quien alquila el puesto</li>
        </ul>
    </section>

    <section class="bloque">
        <h2>Nueva cuenta de recepción</h2>
        <form method="post">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="crear">
            <div class="campo"><label for="rn">Nombre</label>
                <input type="text" id="rn" name="nombre" value="<?= e($d['nombre']) ?>" maxlength="100" required></div>
            <div class="campo"><label for="re">Correo con el que entra</label>
                <input type="email" id="re" name="email" value="<?= e($d['email']) ?>" required autocomplete="off"></div>
            <div class="campo"><label for="rk">Contraseña <small>(mínimo 8 caracteres; dásela tú)</small></label>
                <input type="text" id="rk" name="clave" minlength="8" required autocomplete="off"></div>
            <button class="boton boton-ancho" type="submit">Crear cuenta de recepción</button>
        </form>
        <p class="suave" style="margin-top:10px;font-size:.9rem">Entra por la misma página que tú, con su correo y contraseña. Los avisos de reservas que te llegan a ti también le llegan a recepción.</p>
    </section>
</div>
