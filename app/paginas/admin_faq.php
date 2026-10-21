<?php
use TuSalon\Ia;

/**
 * Panel de Tukán → Preguntas frecuentes (IA): las respuestas de la Ayuda que se guardaron (las mismas para todos los
 * salones). Aprobar, editar (queda aprobada y «escrita a mano»), borrar y agregar a mano. Todo con CSRF y en registro_admin.
 */
$faq = Ia::faq(db());
$filtro = (string) ($_GET['filtro'] ?? 'sin_aprobar');
if (!in_array($filtro, ['', 'sin_aprobar', 'automatica', 'manual'], true)) $filtro = 'sin_aprobar';
$editar = null;
if (es_post()) {
    verificar_csrf();
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $pregunta = trim(mb_substr((string) ($_POST['pregunta'] ?? ''), 0, 500));
    $respuesta = trim(mb_substr((string) ($_POST['respuesta'] ?? ''), 0, 6000));
    $f = $id ? $faq->obtener($id) : null;
    if (in_array($accion, ['aprobar', 'editar', 'borrar'], true) && !$f) {
        aviso('Esa pregunta ya no existe.', 'error');
    } elseif ($accion === 'aprobar') {
        $faq->aprobar($id);
        registrar_admin('Preguntas frecuentes (IA): aprobó «' . mb_substr($f['pregunta'], 0, 120) . '»');
        aviso('Pregunta aprobada.');
    } elseif ($accion === 'borrar') {
        $faq->borrar($id);
        registrar_admin('Preguntas frecuentes (IA): borró «' . mb_substr($f['pregunta'], 0, 120) . '»');
        aviso('Pregunta borrada.');
    } elseif (in_array($accion, ['editar', 'agregar'], true)) {
        if (mb_strlen($pregunta) < 5 || mb_strlen($respuesta) < 5) {
            aviso('Escribe la pregunta y la respuesta (al menos 5 letras cada una).', 'error');
            redirigir('admin_faq', ['filtro' => $filtro] + ($accion === 'editar' ? ['editar' => $id] : []));
        }
        if ($accion === 'editar') {
            $faq->editar($id, $pregunta, $respuesta);
            registrar_admin('Preguntas frecuentes (IA): editó «' . mb_substr($pregunta, 0, 120) . '»');
            aviso('Pregunta guardada: queda aprobada y escrita a mano.');
        } else {
            $faq->agregar($pregunta, $respuesta);
            registrar_admin('Preguntas frecuentes (IA): agregó «' . mb_substr($pregunta, 0, 120) . '»');
            aviso('Pregunta agregada (queda aprobada).');
        }
    }
    redirigir('admin_faq', ['filtro' => $filtro]);
}
if (isset($_GET['editar'])) $editar = $faq->obtener((int) $_GET['editar']);
$lista = $faq->listar($filtro);
$resumen = $faq->resumen();
$estadoIa = tukan_ia_estado();
vista_admin('admin_faq', compact('lista', 'resumen', 'filtro', 'editar', 'estadoIa'), 'Preguntas frecuentes (IA) · Panel Tukán');
