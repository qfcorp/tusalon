<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;

/**
 * Avisos por Telegram con el bot de TuSalón.
 *
 * Configuración (config/.env):
 *   TELEGRAM_BOT_TOKEN        token que da @BotFather
 *   TELEGRAM_BOT_USERNAME     nombre del bot sin @ (ej. TuSalonAvisosBot)
 *   TELEGRAM_WEBHOOK_SECRET   texto secreto que Telegram envía en cada aviso al sistema
 *   TELEGRAM_API_BASE         (solo pruebas) para simular Telegram
 *
 * Cada dueño o peluquero conecta su propio Telegram con un enlace de un solo uso.
 * Las solicitudes llegan con botones "Aceptar" y "Rechazar".
 */
final class Telegram
{
    public function __construct(private PDO $db) {}

    public static function configurado(): bool
    {
        return (string) getenv('TELEGRAM_BOT_TOKEN') !== '' && (string) getenv('TELEGRAM_BOT_USERNAME') !== '';
    }

    /** Llama a la API de Telegram. Nunca detiene el sistema si Telegram no responde. */
    public function api(string $metodo, array $datos): ?array
    {
        $token = (string) getenv('TELEGRAM_BOT_TOKEN');
        if ($token === '') return null;
        $base = rtrim((string) (getenv('TELEGRAM_API_BASE') ?: 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot$token/$metodo");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($datos, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $r = curl_exec($ch);
        $codigo = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r === false || $codigo >= 400) {
            error_log("TuSalón Telegram: $metodo falló (HTTP $codigo)");
            return null;
        }
        return json_decode((string) $r, true);
    }

    /** Enlace para que un usuario conecte su Telegram (código de un solo uso). */
    public function enlaceConectar(int $usuarioId): ?string
    {
        if (!self::configurado()) return null;
        $codigo = bin2hex(random_bytes(12));
        $this->db->prepare('UPDATE usuarios SET telegram_codigo = ? WHERE id = ?')->execute([$codigo, $usuarioId]);
        return 'https://t.me/' . getenv('TELEGRAM_BOT_USERNAME') . '?start=' . $codigo;
    }

    public function desconectar(int $usuarioId): void
    {
        $this->db->prepare('UPDATE usuarios SET telegram_chat_id = NULL, telegram_codigo = NULL WHERE id = ?')->execute([$usuarioId]);
    }

    /** Mensaje de solicitud con botones (si el usuario puede responder). */
    public function avisarReserva(int $citaId, array $usuarioIds): int
    {
        if (!self::configurado() || !$usuarioIds) return 0;
        $agenda = new Agenda($this->db);
        $st = $this->db->prepare('SELECT salon_id FROM citas WHERE id = ?');
        $st->execute([$citaId]);
        $salonId = (int) $st->fetchColumn();
        $c = $agenda->cita($salonId, $citaId);
        if (!$c) return 0;
        $st = $this->db->prepare('SELECT acepta_reservas, nombre FROM salones WHERE id = ?');
        $st->execute([$salonId]);
        $salon = $st->fetch();

        $ini = new \DateTimeImmutable($c['inicio']);
        $servicios = implode(' + ', array_column($c['lista_servicios'], 'nombre'));
        $precio = array_sum(array_column($c['lista_servicios'], 'precio'));
        $texto = ($c['estado'] === 'pendiente' ? "🔔 Solicitud de cita\n" : "✅ Nueva cita en línea\n")
               . "{$salon['nombre']}\n\n"
               . "Cliente: {$c['cliente']}\n"
               . "Servicio: $servicios (\$" . number_format($precio, 2, ',', '.') . ")\n"
               . "Con: {$c['profesional']}\n"
               . 'Día: ' . Agenda::DIAS[(int) $ini->format('w')] . ' ' . $ini->format('j/n') . ' a las ' . $ini->format('H:i');

        $in = implode(',', array_fill(0, count($usuarioIds), '?'));
        $st = $this->db->prepare("SELECT id, rol, profesional_id, telegram_chat_id FROM usuarios
                                   WHERE id IN ($in) AND activo AND telegram_chat_id IS NOT NULL");
        $st->execute(array_values($usuarioIds));
        $enviados = 0;
        foreach ($st->fetchAll() as $usr) {
            $datos = ['chat_id' => (int) $usr['telegram_chat_id'], 'text' => $texto];
            if ($c['estado'] === 'pendiente' && Agenda::puedeResponder($salon['acepta_reservas'], $usr, (int) $c['profesional_id'])) {
                $datos['reply_markup'] = ['inline_keyboard' => [[
                    ['text' => 'Aceptar', 'callback_data' => "ac:$citaId"],
                    ['text' => 'Rechazar', 'callback_data' => "re:$citaId"],
                ]]];
            }
            if ($this->api('sendMessage', $datos) !== null) $enviados++;
        }
        return $enviados;
    }

    /** Texto simple a varios usuarios del salón que tengan Telegram conectado. */
    public function textoAUsuarios(array $usuarioIds, string $texto): int
    {
        if (!self::configurado() || !$usuarioIds) return 0;
        $in = implode(',', array_fill(0, count($usuarioIds), '?'));
        $st = $this->db->prepare("SELECT telegram_chat_id FROM usuarios WHERE id IN ($in) AND activo AND telegram_chat_id IS NOT NULL");
        $st->execute(array_values($usuarioIds));
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $chat) {
            if ($this->api('sendMessage', ['chat_id' => (int) $chat, 'text' => $texto]) !== null) $n++;
        }
        return $n;
    }

    /** Enlace para que un CLIENTE con cuenta conecte su Telegram (recordatorios y confirmación). */
    public function enlaceCliente(int $clienteId): ?string
    {
        if (!self::configurado()) return null;
        $codigo = 'c' . bin2hex(random_bytes(12));
        $this->db->prepare('UPDATE clientes SET telegram_codigo = ? WHERE id = ?')->execute([$codigo, $clienteId]);
        return 'https://t.me/' . getenv('TELEGRAM_BOT_USERNAME') . '?start=' . $codigo;
    }

    public function desconectarCliente(int $clienteId): void
    {
        $this->db->prepare('UPDATE clientes SET telegram_chat_id = NULL, telegram_codigo = NULL WHERE id = ?')->execute([$clienteId]);
    }

    /** Mensaje al cliente (si conectó su Telegram). $citaId agrega botones Confirmo / Cancelar. */
    public function enviarACliente(?int $chatId, string $texto, ?int $citaIdBotones = null): bool
    {
        if (!self::configurado() || !$chatId) return false;
        $datos = ['chat_id' => $chatId, 'text' => $texto];
        if ($citaIdBotones) {
            $datos['reply_markup'] = ['inline_keyboard' => [[
                ['text' => 'Confirmo que voy ✅', 'callback_data' => "cc:$citaIdBotones"],
                ['text' => 'Cancelar cita', 'callback_data' => "cx:$citaIdBotones"],
            ]]];
        }
        return $this->api('sendMessage', $datos) !== null;
    }

    /** Cuando el salón acepta, el cliente recibe la confirmación con el valor a cancelar. */
    public function confirmacionAlCliente(int $salonId, int $citaId): bool
    {
        $agenda = new Agenda($this->db);
        $c = $agenda->cita($salonId, $citaId);
        if (!$c || !$c['cliente_telegram']) return false;
        return $this->enviarACliente((int) $c['cliente_telegram'], '✅ ' . $agenda->mensajeConfirmacion($c, $c['salon'], Agenda::urlBase()));
    }

    /** Procesa lo que Telegram envía al sistema (webhook): conexión y botones. */
    public function procesar(array $update): void
    {
        // 1) /start <código>: conectar el Telegram de un usuario
        if (isset($update['message']['text'], $update['message']['chat']['id'])) {
            $chat = (int) $update['message']['chat']['id'];
            if (preg_match('/^\/start\s+c([a-f0-9]{24})$/', trim($update['message']['text']), $m)) {
                $st = $this->db->prepare('UPDATE clientes SET telegram_chat_id = ?, telegram_codigo = NULL
                                           WHERE telegram_codigo = ? RETURNING nombre');
                $st->execute([$chat, 'c' . $m[1]]);
                $nombre = $st->fetchColumn();
                $this->api('sendMessage', ['chat_id' => $chat, 'text' => $nombre
                    ? 'Listo, ' . explode(' ', trim((string) $nombre))[0] . '. Aquí te llegará la confirmación de tus citas y un recordatorio el día anterior.'
                    : 'Ese enlace ya se usó o caducó. Genera uno nuevo desde tu cuenta.']);
            } elseif (preg_match('/^\/start\s+([a-f0-9]{24})$/', trim($update['message']['text']), $m)) {
                $st = $this->db->prepare('UPDATE usuarios SET telegram_chat_id = ?, telegram_codigo = NULL
                                           WHERE telegram_codigo = ? AND activo RETURNING nombre');
                $st->execute([$chat, $m[1]]);
                $nombre = $st->fetchColumn();
                $this->api('sendMessage', ['chat_id' => $chat, 'text' => $nombre
                    ? "Listo, $nombre. Aquí te llegarán las solicitudes de cita de TuSalón."
                    : 'Ese enlace ya se usó o caducó. Genera uno nuevo desde TuSalón.']);
            } else {
                $this->api('sendMessage', ['chat_id' => $chat,
                    'text' => 'Para recibir avisos, conecta tu Telegram desde TuSalón (Solicitudes → Avisos por Telegram).']);
            }
            return;
        }

        // 2) Botones Aceptar / Rechazar
        if (isset($update['callback_query'])) {
            $q = $update['callback_query'];
            $chat = (int) ($q['message']['chat']['id'] ?? 0);
            $respuesta = 'No se pudo procesar.';
            if (preg_match('/^(cc|cx):(\d+)$/', (string) ($q['data'] ?? ''), $m)) {
                $respuesta = $this->botonCliente($chat, (int) $m[2], $m[1] === 'cc', $q);
            } elseif (preg_match('/^(ac|re):(\d+)$/', (string) ($q['data'] ?? ''), $m)) {
                $st = $this->db->prepare('SELECT * FROM usuarios WHERE telegram_chat_id = ? AND activo');
                $st->execute([$chat]);
                $usuarios = $st->fetchAll();
                $citaId = (int) $m[2];
                $st = $this->db->prepare('SELECT salon_id FROM citas WHERE id = ?');
                $st->execute([$citaId]);
                $salonId = (int) $st->fetchColumn();
                $usr = null;
                foreach ($usuarios as $x) {
                    if ((int) $x['salon_id'] === $salonId) { $usr = $x; break; }
                }
                if (!$usr) {
                    $respuesta = 'Esta cita no es de tu salón.';
                } else {
                    try {
                        (new Agenda($this->db))->responderSolicitud($salonId, $citaId, $usr, $m[1] === 'ac');
                        $respuesta = $m[1] === 'ac' ? 'Cita aceptada ✅' : 'Solicitud rechazada';
                        $texto = ($q['message']['text'] ?? '') . "\n\n" . ($m[1] === 'ac' ? '✅ Aceptada' : '❌ Rechazada') . " por {$usr['nombre']}";
                        $this->api('editMessageText', ['chat_id' => $chat, 'message_id' => $q['message']['message_id'] ?? 0, 'text' => $texto]);
                        if ($m[1] === 'ac') $this->confirmacionAlCliente($salonId, $citaId);
                    } catch (\RuntimeException $e) {
                        $respuesta = $e->getMessage();
                    }
                }
            }
            $this->api('answerCallbackQuery', ['callback_query_id' => $q['id'] ?? '', 'text' => $respuesta]);
        }
    }

    /** El cliente toca "Confirmo" o "Cancelar" en el recordatorio. Solo vale desde el Telegram del dueño de la cita. */
    private function botonCliente(int $chat, int $citaId, bool $confirmar, array $q): string
    {
        $st = $this->db->prepare('SELECT c.id FROM citas c JOIN clientes cl ON cl.id = c.cliente_id
                                   WHERE c.id = ? AND cl.telegram_chat_id = ?');
        $st->execute([$citaId, $chat]);
        if (!$st->fetchColumn()) return 'Esta cita no está a tu nombre.';
        $agenda = new Agenda($this->db);
        try {
            if ($confirmar) {
                $agenda->confirmarPorCliente($citaId);
                $fin = '✅ Confirmaste tu cita. ¡Te esperamos!';
            } else {
                $agenda->cancelarPorCliente($citaId);
                $fin = '❌ Cancelaste tu cita. Gracias por avisar.';
            }
            $this->api('editMessageText', ['chat_id' => $chat, 'message_id' => $q['message']['message_id'] ?? 0,
                'text' => ($q['message']['text'] ?? '') . "\n\n" . $fin]);
            return $confirmar ? 'Cita confirmada' : 'Cita cancelada';
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }
}
