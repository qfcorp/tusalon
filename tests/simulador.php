<?php
/**
 * Solo para pruebas: imita a Telegram y a Payphone con "php -S 127.0.0.1:<puerto> tests/simulador.php".
 *  - /bot<token>/<metodo>  (Telegram): guarda {"metodo","datos"} en TG_MOCK_LOG y responde ok
 *  - lo demás (Payphone): guarda "Authorization URI cuerpo" en PP_MOCK_DIR/llamadas.log y responde PP_MOCK_DIR/respuesta.json
 */
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$cuerpo = (string) file_get_contents('php://input');
header('Content-Type: application/json');
if (str_starts_with($ruta, '/bot')) {
    $log = (string) getenv('TG_MOCK_LOG');
    if ($log !== '') {
        $linea = json_encode(['metodo' => basename($ruta), 'datos' => json_decode($cuerpo, true) ?? []], JSON_UNESCAPED_UNICODE);
        file_put_contents($log, $linea . "\n", FILE_APPEND | LOCK_EX);
    }
    echo json_encode(['ok' => true, 'result' => ['message_id' => 1]]);
    return;
}
$dir = (string) getenv('PP_MOCK_DIR');
if ($dir !== '') {
    file_put_contents("$dir/llamadas.log", ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . ' ' . $_SERVER['REQUEST_URI'] . ' ' . $cuerpo . "\n", FILE_APPEND | LOCK_EX);
}
echo ($dir !== '' && is_file("$dir/respuesta.json")) ? file_get_contents("$dir/respuesta.json") : '{}';
