<?php
use TuSalon\Telegram;

/**
 * Telegram llama a esta dirección cuando alguien escribe al bot o toca un botón.
 * Solo se acepta si trae el texto secreto configurado (TELEGRAM_WEBHOOK_SECRET).
 */
header('Content-Type: application/json');
$secreto = (string) getenv('TELEGRAM_WEBHOOK_SECRET');
$recibido = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!es_post() || $secreto === '' || !hash_equals($secreto, $recibido)) {
    http_response_code(403);
    echo '{"ok":false}';
    return;
}
$update = json_decode((string) file_get_contents('php://input'), true);
if (is_array($update)) {
    (new Telegram(db()))->procesar($update);
}
echo '{"ok":true}';
