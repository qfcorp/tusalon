<?php
declare(strict_types=1);

/**
 * Pruebas de lógica de los ADMINISTRADORES (21 oct 2026): el dueño crea hasta 4 administradores con su mismo nivel,
 * que no cuentan en el límite de personas ni dependen de ningún módulo.
 *  1. Crear administradores (correo único, contraseña mínima, rol inválido rechazado sin guardar nada).
 *  2. Límite de 4 activos sin contar al dueño (crear, activar y pasar una cuenta a administrador).
 *  3. No cuentan en Modulos::maxPersonas.
 *  4. No pueden tocar al dueño ni desactivarse o cambiarse el rol a sí mismos; sí manejan a otros administradores.
 *  5. Mismo nivel que el dueño en la lógica (avisos, solicitudes, ficha técnica) y en las rutas de public/index.php.
 * Borra y recrea la base de prueba (igual que run_tests.php).
 *
 * Uso (lo corre tests/run_logica.sh):
 *   TUSALON_DB_NAME=tusalon_prueba TUSALON_DB_USER=tusalon TUSALON_DB_PASS=prueba php tests/prueba_administradores.php
 */

define('RAIZ', dirname(__DIR__));
date_default_timezone_set('America/Guayaquil');
spl_autoload_register(function (string $clase): void {
    if (str_starts_with($clase, 'TuSalon\\')) {
        $archivo = RAIZ . '/src/' . substr($clase, 8) . '.php';
        if (is_file($archivo)) require $archivo;
    }
});

use TuSalon\{Administradores, Agenda, Altas, Db, Modulos, Notificaciones, Planes, Profesionales};

$ok = 0;
$fallos = [];
function check(string $nombre, mixed $esperado, mixed $real): void
{
    global $ok, $fallos;
    if ($esperado === $real) { $ok++; echo "  OK    $nombre\n"; }
    else { $fallos[] = $nombre; echo "  FALLA $nombre — esperado " . var_export($esperado, true) . ', salió ' . var_export($real, true) . "\n"; }
}
function check_error(string $nombre, string $texto, callable $f): void
{
    global $ok, $fallos;
    try { $f(); $fallos[] = $nombre; echo "  FALLA $nombre — esperaba un error con [$texto]\n"; }
    catch (Throwable $e) {
        if (str_contains($e->getMessage(), $texto)) { $ok++; echo "  OK    $nombre\n"; }
        else { $fallos[] = $nombre; echo "  FALLA $nombre — el error fue [" . $e->getMessage() . "]\n"; }
    }
}

$db = Db::conectar();
$db->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
$db->exec(file_get_contents(RAIZ . '/db/schema.sql'));
$adm = new Administradores($db);
$usuario = function (int $id) use ($db): array {
    $st = $db->prepare('SELECT id, salon_id, rol, profesional_id, nombre, email, activo FROM usuarios WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
};
$cuantos = fn(string $sql) => (int) $db->query($sql)->fetchColumn();

echo "1. Crear administradores\n";
$a = (new Altas($db))->crearSalonConDueno('Barbería Admins', 'Dueño Original', '0990000000', 'dueno@admins.ec', 'claveSegura1', 'basica');
$sid = $a['salon_id'];
$db->prepare("UPDATE salones SET estado = 'activo', activo_hasta = current_date + 30 WHERE id = ?")->execute([$sid]);
$dueno = $usuario($a['usuario_id']);
$id1 = $adm->crear($sid, $dueno, 'Ana Admin', 'ANA@admins.ec', 'claveSegura1');
$ana = $usuario($id1);
check('El dueño crea un administrador (rol admin)', 'admin', $ana['rol']);
check('El correo se guarda en minúsculas', 'ana@admins.ec', $ana['email']);
check('El administrador no es una persona que atiende (sin profesional_id)', null, $ana['profesional_id']);
check('La contraseña se guarda cifrada', true, password_verify('claveSegura1', (string) $db->query("SELECT password_hash FROM usuarios WHERE id = $id1")->fetchColumn()));
check('Tiene el nivel del dueño', true, Administradores::nivelDueno($ana));
check('La recepción no tiene el nivel del dueño', false, Administradores::nivelDueno(['rol' => 'recepcion']));
check('El peluquero no tiene el nivel del dueño', false, Administradores::nivelDueno(['rol' => 'profesional']));
check('Un rol desconocido no tiene el nivel del dueño', false, Administradores::nivelDueno(['rol' => 'chofer']));
$antes = $cuantos("SELECT count(*) FROM usuarios WHERE salon_id = $sid");
check_error('Correo repetido: error', 'ya se usa', fn() => $adm->crear($sid, $dueno, 'Otra Ana', 'ana@admins.ec', 'claveSegura1'));
check_error('Correo repetido con mayúsculas: error', 'ya se usa', fn() => $adm->crear($sid, $dueno, 'Otra Ana', 'Dueno@Admins.ec', 'claveSegura1'));
check_error('Contraseña corta: error', 'al menos 8', fn() => $adm->crear($sid, $dueno, 'Beto', 'beto@admins.ec', 'corta'));
check_error('Correo no válido: error', 'no es válido', fn() => $adm->crear($sid, $dueno, 'Beto', 'beto-sin-arroba', 'claveSegura1'));
check_error('Sin nombre: error', 'nombre', fn() => $adm->crear($sid, $dueno, ' ', 'beto@admins.ec', 'claveSegura1'));
foreach (['dueno', 'profesional', 'superadmin', 'ADMIN', '', 'chofer'] as $rol) {
    check_error("Rol inválido «{$rol}»: error", 'Rol no válido', fn() => $adm->crear($sid, $dueno, 'Beto', 'beto@admins.ec', 'claveSegura1', $rol));
}
check('Con rol inválido no se guardó nada', $antes, $cuantos("SELECT count(*) FROM usuarios WHERE salon_id = $sid"));
check('Nadie quedó como segundo dueño', 1, $cuantos("SELECT count(*) FROM usuarios WHERE salon_id = $sid AND rol = 'dueno'"));
$rec = $usuario($adm->crear($sid, $dueno, 'Rosa Recepción', 'rosa@admins.ec', 'claveSegura1', 'recepcion'));
$pel = (new Profesionales($db, new Planes($db)))->agregar($sid, 'Pedro Peluquero', 'empleado', ['sueldo_mensual' => 0, 'comision_servicio_pct' => 40]);
$db->prepare("INSERT INTO usuarios (salon_id, profesional_id, nombre, email, password_hash, rol) VALUES (?,?,?,?,?,'profesional')")
   ->execute([$sid, $pel, 'Pedro Peluquero', 'pedro@admins.ec', password_hash('claveSegura1', PASSWORD_DEFAULT)]);
$pedro = $usuario((int) $db->lastInsertId());
check_error('Recepción no puede crear administradores', 'Solo el dueño o un administrador', fn() => $adm->crear($sid, $rec, 'X', 'x@admins.ec', 'claveSegura1'));
check_error('Un peluquero no puede crear administradores', 'Solo el dueño o un administrador', fn() => $adm->crear($sid, $pedro, 'X', 'x@admins.ec', 'claveSegura1'));
$otro = (new Altas($db))->crearSalonConDueno('Otro Salón', 'Otro Dueño', '0990000001', 'otro@admins.ec', 'claveSegura1', 'basica');
$otroDueno = $usuario($otro['usuario_id']);
check_error('El dueño de otro salón no puede crear administradores aquí', 'Solo el dueño o un administrador', fn() => $adm->crear($sid, $otroDueno, 'X', 'x@admins.ec', 'claveSegura1'));
check_error('… ni desactivar a uno de aquí', 'Solo el dueño o un administrador', fn() => $adm->desactivar($sid, $otroDueno, $id1));
check_error('… ni tocar una cuenta de otro salón desde el suyo', 'No se encontró', fn() => $adm->desactivar($otro['salon_id'], $otroDueno, $id1));

echo "2. Límite de 4 administradores activos (sin contar al dueño)\n";
$id2 = $adm->crear($sid, $ana, 'Beto Admin', 'beto@admins.ec', 'claveSegura1');   // un administrador crea a otro
check('Un administrador crea a otro administrador', 'admin', $usuario($id2)['rol']);
$id3 = $adm->crear($sid, $dueno, 'Carla Admin', 'carla@admins.ec', 'claveSegura1');
$id4 = $adm->crear($sid, $dueno, 'Dora Admin', 'dora@admins.ec', 'claveSegura1');
check('Hay 4 administradores activos', 4, $adm->activos($sid));
$antes = $cuantos("SELECT count(*) FROM usuarios WHERE salon_id = $sid");
check_error('El quinto no se crea: «Puede tener hasta 4 administradores además del dueño»', 'Puede tener hasta 4 administradores además del dueño',
    fn() => $adm->crear($sid, $dueno, 'Quinto', 'quinto@admins.ec', 'claveSegura1'));
check('… y no se guardó nada', $antes, $cuantos("SELECT count(*) FROM usuarios WHERE salon_id = $sid"));
check('El mensaje es el pedido', 'Puede tener hasta 4 administradores además del dueño.', Administradores::MENSAJE_LIMITE);
$adm->crear($sid, $dueno, 'Rita Recepción', 'rita@admins.ec', 'claveSegura1', 'recepcion');
check('Con 4 administradores aún se crean cuentas de recepción (no cuentan)', 4, $adm->activos($sid));
check_error('Pasar una recepción a administrador con 4 activos: error', 'hasta 4 administradores',
    fn() => $adm->editar($sid, $dueno, (int) $rec['id'], $rec['nombre'], $rec['email'], 'admin'));
check('… la recepción sigue siendo recepción', 'recepcion', $usuario((int) $rec['id'])['rol']);
$adm->desactivar($sid, $dueno, $id4);
check('Desactivar libera un cupo', 3, $adm->activos($sid));
check('El desactivado no se borra (sigue en la lista)', true, in_array($id4, array_map('intval', array_column($adm->lista($sid), 'id')), true));
$id5 = $adm->crear($sid, $dueno, 'Eva Admin', 'eva@admins.ec', 'claveSegura1');
check_error('Activar un quinto: error', 'Puede tener hasta 4 administradores además del dueño', fn() => $adm->activar($sid, $dueno, $id4));
check('… sigue sin acceso', false, $usuario($id4)['activo']);
$adm->desactivar($sid, $ana, $id5);   // un administrador desactiva a otro
check('Un administrador desactiva a otro administrador', false, $usuario($id5)['activo']);
$adm->activar($sid, $ana, $id4);
check('… y activa a otro con cupo libre', true, $usuario($id4)['activo']);
check('Lista: el dueño va primero', 'dueno', $adm->lista($sid)[0]['rol']);
check('La lista no trae peluqueros ni recepción', ['dueno', 'admin'], array_values(array_unique(array_column($adm->lista($sid), 'rol'))));

echo "3. No cuentan en el límite de personas (Modulos::maxPersonas)\n";
$mod = new Modulos($db);
$planes = new Planes($db);
check('Salón con la base: hasta 3 personas', 3, $mod->maxPersonas($sid));
check('Personas que atienden: el dueño y Pedro (los administradores no)', 2, $cuantos("SELECT count(*) FROM profesionales WHERE salon_id = $sid AND activo"));
check('Con 4 administradores aún puede agregar una persona', true, $planes->puedeAgregarProfesional($sid));
(new Profesionales($db, $planes))->agregar($sid, 'Tercera Persona', 'porcentaje', ['pct_profesional' => 50, 'quien_cobra' => 'local']);
check('Con 3 personas ya no puede (los 4 administradores no suman)', false, $planes->puedeAgregarProfesional($sid));
check('Los administradores no dependen de ningún módulo: se crean con solo la base', 4, $adm->activos($sid));
check('Ninguna ruta de administradores es de un módulo', '', Modulos::RUTAS['administradores'] ?? 'falta');

echo "4. Seguridad: el dueño y uno mismo\n";
check_error('Un administrador no desactiva al dueño', 'dueño', fn() => $adm->desactivar($sid, $ana, (int) $dueno['id']));
check_error('… ni le cambia la contraseña', 'dueño', fn() => $adm->cambiarClave($sid, $ana, (int) $dueno['id'], 'otraClave99'));
check_error('… ni le cambia nombre, correo o rol', 'dueño', fn() => $adm->editar($sid, $ana, (int) $dueno['id'], 'Hackeado', 'h@admins.ec', 'admin'));
$d0 = $usuario((int) $dueno['id']);
check('El dueño sigue igual', ['Dueño Original', 'dueno@admins.ec', 'dueno', true], [$d0['nombre'], $d0['email'], $d0['rol'], $d0['activo']]);
check_error('El dueño no se desactiva a sí mismo', 'dueño', fn() => $adm->desactivar($sid, $dueno, (int) $dueno['id']));
check_error('Un administrador no se desactiva a sí mismo', 'a ti mismo', fn() => $adm->desactivar($sid, $ana, (int) $ana['id']));
check_error('Un administrador no se cambia el rol a sí mismo', 'a ti mismo', fn() => $adm->editar($sid, $ana, (int) $ana['id'], 'Ana Admin', 'ana@admins.ec', 'recepcion'));
check('… sigue activa y como administradora', ['admin', true], [$usuario($id1)['rol'], $usuario($id1)['activo']]);
$adm->editar($sid, $ana, (int) $ana['id'], 'Ana María', 'ana.maria@admins.ec', 'admin');
check('Sí puede cambiar su propio nombre y correo', ['Ana María', 'ana.maria@admins.ec'], [$usuario($id1)['nombre'], $usuario($id1)['email']]);
check_error('Editar con un rol inválido: error', 'Rol no válido', fn() => $adm->editar($sid, $dueno, $id2, 'Beto', 'beto@admins.ec', 'dueno'));
check('… y no se guardó nada', 'admin', $usuario($id2)['rol']);
check_error('Editar con un correo de otra cuenta: error', 'ya se usa', fn() => $adm->editar($sid, $dueno, $id2, 'Beto', 'rosa@admins.ec', 'admin'));
check_error('Las cuentas de peluquero se manejan en Equipo', 'Equipo', fn() => $adm->desactivar($sid, $ana, (int) $pedro['id']));
$adm->editar($sid, $ana, $id2, 'Beto', 'beto@admins.ec', 'recepcion');
check('Un administrador pasa a otro a recepción', 'recepcion', $usuario($id2)['rol']);
$adm->desactivar($sid, $ana, (int) $rec['id']);
check('Un administrador desactiva una cuenta de recepción', false, $usuario((int) $rec['id'])['activo']);
$adm->cambiarClave($sid, $ana, $id3, 'nuevaClave123');
check('Un administrador cambia la contraseña de otro', true, password_verify('nuevaClave123', (string) $db->query("SELECT password_hash FROM usuarios WHERE id = $id3")->fetchColumn()));
check_error('Contraseña nueva corta: error', 'al menos 8', fn() => $adm->cambiarClave($sid, $ana, $id3, '1234'));

echo "5. Mismo nivel que el dueño\n";
$ana = $usuario($id1);
$notif = new Notificaciones($db);
check('Los avisos que van al dueño también le llegan', true, in_array($id1, $notif->destinos($sid, $pel, 'dueno'), true));
check('Responde solicitudes como el dueño (regla «solo el dueño»)', true, Agenda::puedeResponder('dueno', $ana, $pel));
$index = file_get_contents(RAIZ . '/public/index.php');
preg_match('/\$privadas = \[(.*?)\];/s', $index, $m);
check('La pantalla Administradores es una ruta privada', true, str_contains($m[1] ?? '', "'administradores'"));
check('El enrutador deja entrar al dueño y a los administradores (es_dueno_o_admin)', true, str_contains($index, '!es_dueno_o_admin($u) && !es_peluquero($u) && !es_recepcion($u)'));
preg_match('/\$deRecepcion = \[(.*?)\];/s', $index, $m);
check('Recepción no entra a Administradores', false, str_contains($m[1] ?? '', "'administradores'"));
preg_match('/\$dePeluquero = \[(.*?)\];/s', $index, $m);
check('El peluquero no entra a Administradores', false, str_contains($m[1] ?? '', "'administradores'"));

echo "\n" . str_repeat('=', 50) . "\n";
echo "Resultado: $ok correctas, " . count($fallos) . " fallidas\n";
exit($fallos ? 1 : 0);
