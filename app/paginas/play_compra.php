<?php
use TuSalon\GooglePlay;

/**
 * La app de Android avisa que el dueño pagó un plan con Google Play.
 * Recibe JSON {producto, token, csrf} y responde JSON. Se verifica con Google antes de activar nada.
 */
header('Content-Type: application/json; charset=utf-8');
if (!es_post()) { http_response_code(405); echo json_encode(['ok' => false]); return; }
$d = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (!hash_equals(token_csrf(), (string) ($d['csrf'] ?? ''))) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'La página caducó. Recárgala.']); return; }
if (es_peluquero($u)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Solo el dueño puede pagar el plan.']); return; }
try {
    $r = (new GooglePlay(db()))->registrarCompra((int) $u['salon_id'], (string) ($d['producto'] ?? ''), (string) ($d['token'] ?? ''));
    echo json_encode(['ok' => true, 'hasta' => (new DateTimeImmutable($r['hasta']))->format('d/m/Y'), 'plan' => $r['plan']]);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
