<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

/**
 * Anticipo en línea: el cliente paga una parte antes de su cita para asegurar que llegue.
 * Dos formas de pago:
 *  - Transferencia o DeUna: el cliente sube la foto del comprobante y el salón la revisa.
 *  - Tarjeta con Payphone: se confirma sola cuando el salón puso sus claves de Payphone.
 * Mientras no paga, la reserva queda como solicitud (no ocupa la hora para siempre).
 * El anticipo entra a la caja el día que se confirma y se descuenta al cobrar la cita.
 */
final class Anticipos
{
    public const MODOS = ['no' => 'No pedir anticipo', 'todos' => 'A todos los clientes',
                          'nuevos' => 'Solo a clientes nuevos', 'faltas' => 'Solo a quienes ya faltaron'];
    public const ESTADOS = ['no' => 'Sin anticipo', 'pendiente' => 'Anticipo por pagar', 'por_revisar' => 'Comprobante por revisar',
                            'pagado' => 'Anticipo pagado', 'rechazado' => 'Comprobante rechazado', 'usado' => 'Anticipo descontado'];

    public function __construct(private PDO $db, private ?string $carpeta = null) {}

    public static function apiPayphone(): string
    {
        return rtrim((string) (getenv('PAYPHONE_API_BASE') ?: 'https://pay.payphonetodoesposible.com'), '/');
    }

    public function config(int $salonId): array
    {
        $st = $this->db->prepare('SELECT anticipo_modo, anticipo_tipo, anticipo_valor, anticipo_banco, anticipo_deuna,
                                         payphone_token, payphone_store_id, acepta_reservas, minutos_para_aceptar, nombre
                                    FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $c = $st->fetch();
        $c['payphone_listo'] = trim((string) $c['payphone_token']) !== '' && trim((string) $c['payphone_store_id']) !== '';
        $c['transferencia_lista'] = trim((string) $c['anticipo_banco']) !== '' || trim((string) $c['anticipo_deuna']) !== '';
        return $c;
    }

    public function guardarConfig(int $salonId, array $d): void
    {
        $modo = (string) ($d['anticipo_modo'] ?? 'no');
        $tipo = (string) ($d['anticipo_tipo'] ?? 'porcentaje');
        $valor = round((float) str_replace(',', '.', (string) ($d['anticipo_valor'] ?? 0)), 2);
        $banco = trim((string) ($d['anticipo_banco'] ?? ''));
        $deuna = trim((string) ($d['anticipo_deuna'] ?? ''));
        $token = trim((string) ($d['payphone_token'] ?? ''));
        $store = trim((string) ($d['payphone_store_id'] ?? ''));
        if (!isset(self::MODOS[$modo])) throw new RuntimeException('Elige a quién pedir anticipo.');
        if (!in_array($tipo, ['porcentaje', 'fijo'], true)) throw new RuntimeException('Elige si el anticipo es porcentaje o valor fijo.');
        if ($modo !== 'no') {
            if ($tipo === 'porcentaje' && ($valor < 5 || $valor > 100)) throw new RuntimeException('El porcentaje de anticipo debe estar entre 5 % y 100 %.');
            if ($tipo === 'fijo' && ($valor < 1 || $valor > 500)) throw new RuntimeException('El anticipo fijo debe estar entre $1 y $500.');
            if ($banco === '' && $deuna === '' && ($token === '' || $store === '')) {
                throw new RuntimeException('Para pedir anticipo, escribe tus datos de transferencia, tu DeUna o tus claves de Payphone.');
            }
        }
        if ($deuna !== '' && !preg_match('/^0?9\d{8}$/', preg_replace('/\D/', '', $deuna))) throw new RuntimeException('El celular de DeUna no es válido (ej. 0991234567).');
        if (($token === '') !== ($store === '')) throw new RuntimeException('Para Payphone necesitas las dos claves: el token y el Store ID.');
        $this->db->prepare('UPDATE salones SET anticipo_modo = ?, anticipo_tipo = ?, anticipo_valor = ?, anticipo_banco = ?, anticipo_deuna = ?,
                                   payphone_token = ?, payphone_store_id = ? WHERE id = ?')
                 ->execute([$modo, $tipo, max(0, $valor), mb_substr($banco, 0, 500) ?: null, $deuna ?: null, $token ?: null, $store ?: null, $salonId]);
    }

    /** Cuánto anticipo le toca a este cliente para una cita de este valor (0 = no se pide). */
    public function montoRequerido(int $salonId, ?int $clienteId, float $totalCita): float
    {
        $c = $this->config($salonId);
        if ($c['anticipo_modo'] === 'no' || $totalCita <= 0) return 0.0;
        if (!$c['transferencia_lista'] && !$c['payphone_listo']) return 0.0;
        if ($c['anticipo_modo'] === 'nuevos' && $clienteId) {
            $st = $this->db->prepare("SELECT 1 FROM citas WHERE cliente_id = ? AND salon_id = ? AND estado = 'atendida'
                                      UNION ALL SELECT 1 FROM ventas WHERE cliente_id = ? AND salon_id = ? AND NOT anulada LIMIT 1");
            $st->execute([$clienteId, $salonId, $clienteId, $salonId]);
            if ($st->fetchColumn()) return 0.0;
        }
        if ($c['anticipo_modo'] === 'faltas' && (!$clienteId || (new Agenda($this->db))->faltas($salonId, $clienteId) === 0)) return 0.0;
        $monto = $c['anticipo_tipo'] === 'porcentaje'
            ? $totalCita * (float) $c['anticipo_valor'] / 100
            : min((float) $c['anticipo_valor'], $totalCita);
        return round($monto, 2);
    }

    /**
     * Al reservar en línea: si le toca anticipo, la cita queda como solicitud hasta que pague
     * (aunque el salón acepte las reservas solo). Devuelve el monto (0 si no aplica).
     */
    public function aplicarAlReservar(int $salonId, int $citaId, ?int $clienteId): float
    {
        $st = $this->db->prepare('SELECT COALESCE(sum(precio),0) FROM cita_servicios WHERE cita_id = ?');
        $st->execute([$citaId]);
        $monto = $this->montoRequerido($salonId, $clienteId, (float) $st->fetchColumn());
        if ($monto <= 0) return 0.0;
        $c = $this->config($salonId);
        $expira = (new \DateTimeImmutable())->modify('+' . max(30, (int) $c['minutos_para_aceptar']) . ' minutes')->format('Y-m-d H:i:s');
        $this->db->prepare("UPDATE citas SET anticipo = ?, anticipo_estado = 'pendiente', estado = 'pendiente',
                                   expira_en = COALESCE(expira_en, ?) WHERE id = ? AND salon_id = ?")
                 ->execute([$monto, $expira, $citaId, $salonId]);
        return $monto;
    }

    private function cita(int $citaId): array
    {
        $st = $this->db->prepare('SELECT c.*, cl.nombre AS cliente, p.nombre AS profesional FROM citas c
                                    JOIN profesionales p ON p.id = c.profesional_id LEFT JOIN clientes cl ON cl.id = c.cliente_id WHERE c.id = ?');
        $st->execute([$citaId]);
        $c = $st->fetch();
        if (!$c) throw new RuntimeException('No se encontró la cita.');
        return $c;
    }

    private function carpeta(): string
    {
        return $this->carpeta ?? Fotos::carpeta('comprobantes');
    }

    /** El cliente sube la foto del comprobante de transferencia o DeUna. */
    public function subirComprobante(int $citaId, array $archivo, string $metodo, string $referencia = ''): void
    {
        $c = $this->cita($citaId);
        if (!in_array($c['anticipo_estado'], ['pendiente', 'rechazado', 'por_revisar'], true)) {
            throw new RuntimeException($c['anticipo_estado'] === 'pagado' ? 'Tu anticipo ya está pagado.' : 'Esta cita no tiene anticipo por pagar.');
        }
        if (!in_array($c['estado'], ['pendiente', 'reservada', 'confirmada'], true)) throw new RuntimeException('Esta cita ya no está activa.');
        if (!in_array($metodo, ['transferencia', 'deuna'], true)) $metodo = 'transferencia';
        $nombre = Fotos::guardarImagen($archivo, $this->carpeta(), 1800);
        $anterior = $c['anticipo_comprobante'];
        $min = (int) $this->config((int) $c['salon_id'])['minutos_para_aceptar'];
        // Mientras el salón revisa, la hora sigue apartada
        $this->db->prepare("UPDATE citas SET anticipo_estado = 'por_revisar', anticipo_metodo = ?, anticipo_comprobante = ?, anticipo_referencia = ?,
                                   expira_en = CASE WHEN estado = 'pendiente' THEN GREATEST(COALESCE(expira_en, now()), now() + make_interval(mins => ?)) ELSE expira_en END
                             WHERE id = ?")
                 ->execute([$metodo, $nombre, mb_substr(trim($referencia), 0, 100) ?: null, max(30, $min), $citaId]);
        if ($anterior && preg_match('/^[a-f0-9]{32}\.jpg$/', $anterior)) @unlink($this->carpeta() . '/' . $anterior);
        $ini = new \DateTimeImmutable($c['inicio']);
        (new Notificaciones($this->db))->avisoSimple((int) $c['salon_id'], (int) $c['profesional_id'], $citaId,
            "{$c['cliente']} subió el comprobante del anticipo (" . number_format((float) $c['anticipo'], 2, ',', '.') . " dólares) para su cita del "
            . $ini->format('d/m H:i') . '. Revísalo y confírmalo.');
    }

    public function rutaComprobante(int $salonId, int $citaId): ?string
    {
        $st = $this->db->prepare('SELECT anticipo_comprobante FROM citas WHERE id = ? AND salon_id = ?');
        $st->execute([$citaId, $salonId]);
        $f = (string) $st->fetchColumn();
        if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $f)) return null;
        $ruta = $this->carpeta() . '/' . $f;
        return is_file($ruta) ? $ruta : null;
    }

    /**
     * El salón confirma que recibió el anticipo (o Payphone lo aprobó).
     * Entra a la caja hoy; si el salón acepta las reservas solo, la cita queda reservada.
     */
    public function confirmarPago(int $salonId, int $citaId, ?string $metodo = null, ?string $referencia = null, ?string $fecha = null): void
    {
        $fecha ??= date('Y-m-d H:i:s');
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $st = $this->db->prepare('SELECT c.*, cl.nombre AS cliente, s.acepta_reservas FROM citas c JOIN salones s ON s.id = c.salon_id
                                   LEFT JOIN clientes cl ON cl.id = c.cliente_id WHERE c.id = ? AND c.salon_id = ? FOR UPDATE OF c');
            $st->execute([$citaId, $salonId]);
            $c = $st->fetch();
            if (!$c) throw new RuntimeException('No se encontró la cita.');
            if ($c['anticipo_estado'] === 'pagado') {
                if ($propia) $this->db->commit();
                return;
            }
            if (!in_array($c['anticipo_estado'], ['pendiente', 'por_revisar', 'rechazado'], true) || (float) $c['anticipo'] <= 0) {
                throw new RuntimeException('Esta cita no tiene anticipo por confirmar.');
            }
            $metodo = in_array($metodo, Fidelidad::METODOS, true) ? $metodo : ($c['anticipo_metodo'] ?: 'transferencia');
            $nuevoEstado = ($c['estado'] === 'pendiente' && $c['acepta_reservas'] === 'automatico') ? 'reservada' : $c['estado'];
            $this->db->prepare("UPDATE citas SET anticipo_estado = 'pagado', anticipo_metodo = ?, anticipo_referencia = COALESCE(?, anticipo_referencia),
                                       anticipo_pagado_en = ?, estado = ?, expira_en = NULL
                                 WHERE id = ?")
                     ->execute([$metodo, $referencia, $fecha, $nuevoEstado, $citaId]);
            $this->db->prepare("INSERT INTO ingresos (salon_id, fecha, concepto, referencia_id, cliente_id, descripcion, metodo_pago, monto)
                                VALUES (?,?,'anticipo',?,?,?,?,?)")
                     ->execute([$salonId, $fecha, $citaId, $c['cliente_id'],
                                'Anticipo de ' . ($c['cliente'] ?: 'cliente') . ' · cita del ' . (new \DateTimeImmutable($c['inicio']))->format('d/m H:i'),
                                $metodo, $c['anticipo']]);
            if ($propia) $this->db->commit();
            if ($nuevoEstado === 'reservada' && $c['estado'] === 'pendiente' && $propia) {
                (new Telegram($this->db))->confirmacionAlCliente($salonId, $citaId);
            }
        } catch (\Throwable $e) {
            if ($propia) $this->db->rollBack();
            throw $e;
        }
    }

    /** El comprobante no corresponde: el cliente puede subir otro. */
    public function rechazarComprobante(int $salonId, int $citaId): void
    {
        $st = $this->db->prepare("UPDATE citas SET anticipo_estado = 'rechazado' WHERE id = ? AND salon_id = ? AND anticipo_estado = 'por_revisar'");
        $st->execute([$citaId, $salonId]);
        if ($st->rowCount() !== 1) throw new RuntimeException('No hay un comprobante por revisar en esta cita.');
    }

    // ------------------------------------------------------------------
    // Payphone (botón de pagos con tarjeta)
    // ------------------------------------------------------------------

    /** Datos para mostrar la cajita de Payphone. Guarda el código de la transacción en la cita. */
    public function prepararPayphone(int $citaId): array
    {
        $c = $this->cita($citaId);
        $conf = $this->config((int) $c['salon_id']);
        if (!$conf['payphone_listo']) throw new RuntimeException('Este salón no tiene activado el pago con tarjeta.');
        if (!in_array($c['anticipo_estado'], ['pendiente', 'rechazado'], true)) throw new RuntimeException('Esta cita no tiene anticipo por pagar.');
        $tx = 'TS' . $citaId . '-' . bin2hex(random_bytes(4));
        $this->db->prepare('UPDATE citas SET anticipo_referencia = ? WHERE id = ?')->execute([$tx, $citaId]);
        $centavos = (int) round((float) $c['anticipo'] * 100);
        return [
            'token'             => $conf['payphone_token'],
            'storeId'           => $conf['payphone_store_id'],
            'clientTransactionId' => $tx,
            'amount'            => $centavos,
            'amountWithoutTax'  => $centavos,
            'currency'          => 'USD',
            'reference'         => mb_substr('Anticipo cita ' . $conf['nombre'], 0, 100),
        ];
    }

    /**
     * Payphone devuelve al cliente con ?id=...&clientTransactionId=...
     * Se pregunta a Payphone si el pago fue aprobado y por cuánto. Devuelve [estado, token de la cita].
     * @param callable|null $http para pruebas: fn(string $url, array $cuerpo, string $token): ?array
     */
    public function confirmarPayphone(int $id, string $clientTxId, ?callable $http = null): array
    {
        if (!preg_match('/^TS(\d+)-[a-f0-9]{8}$/', $clientTxId, $m)) throw new RuntimeException('Pago no reconocido.');
        $st = $this->db->prepare('SELECT c.id, c.salon_id, c.token, c.anticipo, c.anticipo_estado, c.anticipo_referencia, s.payphone_token
                                    FROM citas c JOIN salones s ON s.id = c.salon_id WHERE c.id = ?');
        $st->execute([(int) $m[1]]);
        $c = $st->fetch();
        if (!$c || $c['anticipo_referencia'] !== $clientTxId) throw new RuntimeException('Pago no reconocido.');
        if ($c['anticipo_estado'] === 'pagado') return ['aprobado', $c['token']];
        $http ??= [self::class, 'llamarPayphone'];
        $r = $http(self::apiPayphone() . '/api/button/V2/Confirm', ['id' => $id, 'clientTxId' => $clientTxId], (string) $c['payphone_token']);
        $estado = (string) ($r['transactionStatus'] ?? '');
        $monto = (int) ($r['amount'] ?? -1);
        if ($estado === 'Approved' && $monto === (int) round((float) $c['anticipo'] * 100)
            && ($r['clientTransactionId'] ?? $clientTxId) === $clientTxId) {
            $this->confirmarPago((int) $c['salon_id'], (int) $c['id'], 'payphone', 'Payphone ' . ($r['transactionId'] ?? $id));
            return ['aprobado', $c['token']];
        }
        return [$estado === 'Canceled' ? 'cancelado' : 'no_aprobado', $c['token']];
    }

    public static function llamarPayphone(string $url, array $cuerpo, string $token): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($cuerpo),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log("TuSalón Payphone: confirmación falló (HTTP $codigo)");
            return null;
        }
        return json_decode((string) $r, true);
    }

    /** Anticipos pagados que el salón todavía no descontó (para el cobro). */
    public function disponible(array $cita): float
    {
        return ($cita['anticipo_estado'] ?? '') === 'pagado' ? round((float) $cita['anticipo'], 2) : 0.0;
    }
}
