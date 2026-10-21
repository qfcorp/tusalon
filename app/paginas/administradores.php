<?php
/**
 * Administradores: hasta 4 cuentas con el mismo nivel que el dueño. No son personas que atienden (no cuentan en el
 * límite de personas) y no dependen de ningún módulo. Las reglas viven en src/Administradores.php.
 */
use TuSalon\Administradores;

$sid = (int) $u['salon_id'];
$pdo = db();
$adm = new Administradores($pdo);
$error = null;
$d = ['nombre' => '', 'email' => ''];

if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ($accion) {
            case 'crear':
                $d['nombre'] = trim((string) ($_POST['nombre'] ?? ''));
                $d['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
                $adm->crear($sid, $u, $d['nombre'], $d['email'], (string) ($_POST['clave'] ?? ''), (string) ($_POST['rol'] ?? 'admin'));
                aviso("Listo. {$d['nombre']} ya puede entrar con {$d['email']} como administrador.");
                break;
            case 'editar':
                $rol = (string) ($_POST['rol'] ?? 'admin');
                if ($rol === 'recepcion' && !modulo('recepcion')) throw new RuntimeException('La cuenta de recepción no está en tu plan.');
                $adm->editar($sid, $u, $id, (string) ($_POST['nombre'] ?? ''), (string) ($_POST['email'] ?? ''), $rol);
                aviso('Cambios guardados.');
                break;
            case 'clave':
                $adm->cambiarClave($sid, $u, $id, (string) ($_POST['clave'] ?? ''));
                aviso('Contraseña cambiada.');
                break;
            case 'activar':
                $adm->activar($sid, $u, $id);
                aviso('La cuenta puede volver a entrar.');
                break;
            case 'desactivar':
                $adm->desactivar($sid, $u, $id);
                aviso('La cuenta ya no puede entrar.');
                break;
            default:
                throw new RuntimeException('Acción no válida.');
        }
        redirigir('administradores');
    } catch (RuntimeException $e) {
        if ($e instanceof PDOException) throw $e;
        $error = $e->getMessage();
    }
}

$cuentas = $adm->lista($sid);
$activos = $adm->activos($sid);
vista('administradores', compact('cuentas', 'activos', 'error', 'd'), 'Administradores · TuSalón');
