<?php
use TuSalon\Ia;

/**
 * Ayuda («¿En qué le ayudamos?») para TODOS los planes: responde con las preguntas frecuentes guardadas, la IA con el
 * manual (src/Ia.php, compartido/tukan_ia.php) o el manual tal cual. Nunca dice por qué no usó la IA.
 */
$sid = (int) $u['salon_id'];
$pregunta = '';
$respuesta = null;
$error = null;
if (es_post()) {
    verificar_csrf();
    $pregunta = trim(mb_substr((string) ($_POST['pregunta'] ?? ''), 0, 500));
    if ($pregunta === '') {
        $error = 'Escriba su pregunta.';
    } elseif (!Ia::puedePreguntar($_SESSION)) {
        $error = 'Espere un minuto antes de preguntar otra vez, por favor.';
    } else {
        $respuesta = Ia::ayuda(db(), $sid, $pregunta);
    }
}
vista('ayuda', compact('pregunta', 'respuesta', 'error'), 'Ayuda · TuSalón');
