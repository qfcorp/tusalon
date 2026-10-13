<?php
use TuSalon\Anticipos;

/**
 * Payphone devuelve aquí al cliente después de pagar con tarjeta:
 * /?r=payphone&id=...&clientTransactionId=...
 * Se confirma con Payphone y se regresa a la página de la cita.
 */
$id = (int) ($_GET['id'] ?? 0);
$tx = (string) ($_GET['clientTransactionId'] ?? '');
try {
    [$estado, $token] = (new Anticipos(db()))->confirmarPayphone($id, $tx);
    $_SESSION[$estado === 'aprobado' ? 'aviso_cliente' : 'aviso_cliente_error'] = match ($estado) {
        'aprobado'  => '¡Pago recibido! Tu anticipo quedó registrado.',
        'cancelado' => 'Cancelaste el pago. Puedes intentarlo de nuevo o pagar por transferencia.',
        default     => 'El pago no se aprobó. Puedes intentarlo de nuevo o pagar por transferencia.',
    };
    header('Location: /?r=confirmar&t=' . rawurlencode($token) . '#anticipo');
    exit;
} catch (RuntimeException $e) {
    http_response_code(400);
    vista_publica('confirmar_no', [], 'Pago · TuSalón');
}
