<?php
use TuSalon\{Agenda, CorreoDemo, PanelTukan};

/**
 * «Enviar por correo» desde la pantalla «Demo lista» (solo POST con CSRF y solo el super usuario).
 * Llega el sello (los datos de la demo cifrados, src/CorreoDemo.php) y el correo del cliente. Se manda desde
 * info@tukansoftware.com con src/tukan_correo.php y queda en registro_admin SIN la contraseña.
 */
$id = (int) ($_GET['id'] ?? 0);
if (!es_post()) redirigir('admin_salon', ['id' => $id]);
verificar_csrf();
$salon = (new PanelTukan(db()))->salon($id);
$admin = superadmin();
$s = $salon ? CorreoDemo::abrir((string) ($_POST['sello'] ?? ''), (int) $admin['id'], $id, $_SESSION) : null;
$para = trim(mb_substr((string) ($_POST['para'] ?? ''), 0, 254));
$correo = ['sello' => (string) ($_POST['sello'] ?? ''), 'para' => $para, 'listo' => CorreoDemo::listo(), 'salon_id' => $id, 'estado' => null, 'error' => null];
$mostrar = function () use (&$correo, $salon, $s): void {
    vista_admin('admin_demo_correo', ['salon' => $salon, 'correo' => $correo, 'caducado' => $s === null], 'Enviar la demo por correo · Panel Tukán');
};
if ($s === null) {
    http_response_code(410);
    $mostrar();
    return;
}
if (!tukan_correo_valido($para)) {
    http_response_code(422);
    $correo['error'] = 'Escribe un correo válido (ej. nombre@salon.com).';
} elseif (!$correo['listo']) {
    http_response_code(503);
} elseif (CorreoDemo::enviar($s['d'], $para, $error)) {
    CorreoDemo::gastar($s['id'], $_SESSION);
    registrar_admin("Envió la demo por correo a $para", $id);
    $correo['estado'] = 'enviado';
} else {
    registrar_admin("No se pudo enviar la demo por correo a $para: " . mb_substr((string) $error, 0, 200), $id);
    http_response_code(502);
    $correo['error'] = 'No se pudo enviar: ' . $error;
}
$mostrar();
