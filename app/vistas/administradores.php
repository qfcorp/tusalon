<?php
/** @var array $cuentas @var int $activos @var ?string $error @var array $d @var array $u */
$max = \TuSalon\Administradores::MAXIMO;
$conRecepcion = modulo('recepcion');
?>
<div class="cabecera">
    <div>
        <h1>Administradores</h1>
        <p>Personas de confianza que manejan el salón con el mismo nivel que el dueño. No atienden citas ni cuentan en el límite de personas.</p>
    </div>
    <div class="acciones"><a class="boton boton-claro boton-chico" href="<?= e(url('equipo')) ?>">Volver a Equipo</a></div>
</div>
<?php if ($error): ?><p class="aviso aviso-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<div class="rejilla rejilla-2">
    <section class="bloque" data-administradores>
        <h2>Quiénes administran</h2>
        <p class="suave" data-cupo><?= (int) $activos ?> de <?= $max ?> administradores activos, además del dueño.</p>
        <ul class="lista">
        <?php foreach ($cuentas as $c): $yo = (int) $c['id'] === (int) $u['id']; ?>
            <li style="flex-wrap:wrap;align-items:flex-start" data-cuenta="<?= (int) $c['id'] ?>">
                <div style="flex:1;min-width:180px">
                    <div class="principal-linea"><?= e($c['nombre']) ?>
                        <?php if ($c['rol'] === 'dueno'): ?><span class="etq etq-dueno">Dueño</span>
                        <?php else: ?><span class="etq etq-empleado">Administrador</span><?php endif; ?>
                        <?php if ($yo): ?><span class="etq etq-reservada">Tú</span><?php endif; ?>
                        <?php if (!$c['activo']): ?><span class="etq etq-reservada">Sin acceso</span><?php endif; ?>
                    </div>
                    <div class="linea-sub"><?= e($c['email']) ?></div>
                    <?php if ($c['rol'] === 'dueno'): ?>
                        <div class="linea-sub">Es la cuenta principal ante Tukán. Nadie más la puede cambiar ni desactivar.</div>
                    <?php elseif ($c['activo']): ?>
                        <details style="margin-top:6px">
                            <summary style="cursor:pointer;font-size:.9rem;color:var(--verde)">Editar</summary>
                            <form method="post" style="margin-top:8px">
                                <?= campo_csrf() ?><input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <div class="campo"><label for="an<?= (int) $c['id'] ?>">Nombre</label>
                                    <input type="text" id="an<?= (int) $c['id'] ?>" name="nombre" value="<?= e($c['nombre']) ?>" maxlength="100" required></div>
                                <div class="campo"><label for="ae<?= (int) $c['id'] ?>">Correo con el que entra</label>
                                    <input type="email" id="ae<?= (int) $c['id'] ?>" name="email" value="<?= e($c['email']) ?>" required></div>
                                <?php if ($conRecepcion && !$yo): ?>
                                <div class="campo"><label for="ar<?= (int) $c['id'] ?>">Tipo de cuenta</label>
                                    <select id="ar<?= (int) $c['id'] ?>" name="rol">
                                        <option value="admin" selected>Administrador</option>
                                        <option value="recepcion">Recepción (pasa a la pantalla Recepción)</option>
                                    </select></div>
                                <?php else: ?><input type="hidden" name="rol" value="admin"><?php endif; ?>
                                <button class="boton boton-chico" type="submit">Guardar</button>
                            </form>
                        </details>
                        <details style="margin-top:6px">
                            <summary style="cursor:pointer;font-size:.9rem;color:var(--verde)">Cambiar contraseña</summary>
                            <form method="post" style="margin-top:8px">
                                <?= campo_csrf() ?><input type="hidden" name="accion" value="clave"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <div class="campo"><label for="ak<?= (int) $c['id'] ?>">Nueva contraseña</label>
                                    <input type="text" id="ak<?= (int) $c['id'] ?>" name="clave" minlength="8" required autocomplete="off"></div>
                                <button class="boton boton-chico" type="submit">Guardar</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
                <?php if ($c['rol'] === 'admin' && !$yo): ?>
                <form method="post"><?= campo_csrf() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <?php if ($c['activo']): ?>
                        <input type="hidden" name="accion" value="desactivar">
                        <button class="boton boton-peligro boton-chico" type="submit" data-confirmar="¿Quitarle el acceso a <?= e($c['nombre']) ?>?">Desactivar</button>
                    <?php else: ?>
                        <input type="hidden" name="accion" value="activar">
                        <button class="boton boton-claro boton-chico" type="submit">Activar</button>
                    <?php endif; ?>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
        </ul>

        <h3 style="margin-top:18px">Qué puede hacer un administrador</h3>
        <ul style="margin:0;padding-left:20px">
            <li>Todo lo que hace el dueño: agenda, caja, clientes, servicios, horario, equipo, cuentas, reportes y Mi plan</li>
            <li>Crear, editar y desactivar a otros administradores, a recepción y a los peluqueros</li>
        </ul>
        <h3 style="margin-top:12px">Qué no puede hacer</h3>
        <ul style="margin:0;padding-left:20px">
            <li>Cambiar ni desactivar la cuenta del dueño</li>
            <li>Desactivarse ni cambiarse el tipo de cuenta a sí mismo</li>
        </ul>
    </section>

    <section class="bloque">
        <h2>Nuevo administrador</h2>
        <?php if ($activos >= $max): ?>
            <p class="candado" data-limite-admin><?= e(\TuSalon\Administradores::MENSAJE_LIMITE) ?> Desactiva uno para agregar otro.</p>
        <?php endif; ?>
        <form method="post">
            <?= campo_csrf() ?><input type="hidden" name="accion" value="crear"><input type="hidden" name="rol" value="admin">
            <div class="campo"><label for="nn">Nombre</label>
                <input type="text" id="nn" name="nombre" value="<?= e($d['nombre']) ?>" maxlength="100" required></div>
            <div class="campo"><label for="ne">Correo con el que entra</label>
                <input type="email" id="ne" name="email" value="<?= e($d['email']) ?>" required autocomplete="off"></div>
            <div class="campo"><label for="nk">Contraseña <small>(mínimo 8 caracteres; dásela tú)</small></label>
                <input type="text" id="nk" name="clave" minlength="8" required autocomplete="off"></div>
            <button class="boton boton-ancho" type="submit">Crear administrador</button>
        </form>
        <p class="suave" style="margin-top:10px;font-size:.9rem">Entra por la misma página que tú, con su correo y contraseña. Si además atiende clientes, agrégalo también en Equipo como peluquero.</p>
    </section>
</div>
