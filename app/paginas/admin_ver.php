<?php
/** El super administrador entra al salón como su dueño, para dar soporte. Queda registrado. */
$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT u.id, s.nombre FROM usuarios u JOIN salones s ON s.id = u.salon_id
                      WHERE u.salon_id = ? AND u.rol = 'dueno' ORDER BY u.id LIMIT 1");
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { aviso('Este salón no tiene dueño con acceso.', 'error'); redirigir('admin_salon', ['id' => $id]); }
if (!es_post()) {   // se entra solo con el botón (POST), no con un enlace
    redirigir('admin_salon', ['id' => $id]);
}
verificar_csrf();
registrar_admin('Entró al salón como el dueño (soporte)', $id);
session_regenerate_id(true);
$_SESSION['usuario_id'] = (int) $d['id'];
$_SESSION['admin_viendo'] = $id;
$_SESSION['admin_viendo_usuario'] = 1;
redirigir('inicio');
