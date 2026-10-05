<?php
/** El super administrador crea un salón para un cliente (con prueba gratis, o activado si ya pagó). */
$d = ['salon' => '', 'nombre' => '', 'telefono' => '', 'email' => '', 'plan' => 'completa'];
$error = null;
if (es_post()) {
    verificar_csrf();
    foreach ($d as $k => $_) $d[$k] = trim((string) ($_POST[$k] ?? ''));
    $clave = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(12))), 0, 10);
    try {
        $alta = (new TuSalon\Altas(db()))->crearSalonConDueno($d['salon'], $d['nombre'], $d['telefono'], $d['email'], $clave, $d['plan']);
        registrar_admin('Salón creado desde el panel', $alta['salon_id']);
        $_SESSION['clave_nueva_' . $alta['salon_id']] = ['email' => strtolower($d['email']), 'clave' => $clave, 'nombre' => $d['nombre']];
        aviso('Salón creado con prueba gratis. Si ya pagó, registra el pago abajo.');
        redirigir('admin_salon', ['id' => $alta['salon_id'], 'nuevo' => 1]);
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
$listaPlanes = planes()->lista();
vista_admin('admin_nuevo', compact('d', 'error', 'listaPlanes'), 'Nuevo salón · Panel Tukán');
