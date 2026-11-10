<?php
declare(strict_types=1);

namespace TuSalon;

use PDO;
use RuntimeException;

require_once __DIR__ . '/TukanDemos.php';

/**
 * "Todo se pide demo": el formulario público "Solicitar demo" (?r=registro) ya no crea un salón.
 * Guarda el pedido (nombre, salón, ciudad, WhatsApp, correo, qué necesita y los módulos que le interesan), que llega
 * al Panel Tukán Central como solicitud `demo_pedida`. Tukán lo atiende y crea la demo (`crear_demo` con
 * `solicitud_id`) o lo descarta. Estados: nueva → contactada → demo_creada | descartada.
 */
final class PedidosDemo
{
    public const ESTADOS = ['nueva' => 'Nueva', 'contactada' => 'Contactada', 'demo_creada' => 'Demo creada', 'descartada' => 'Descartada'];
    /** Pedidos por IP por hora (para que nadie llene la lista). */
    public const MAX_POR_HORA = 5;

    /** Lo que faltó o está mal en el último pedido: campo => mensaje (para mostrarlo junto a cada campo). */
    public array $errores = [];

    public function __construct(private PDO $db) {}

    /** Valida y guarda un pedido. Devuelve su id. */
    public function crear(array $d, string $ip = ''): int
    {
        // Los 7 datos obligatorios de las demos automáticas (TukanDemos::validar, 10 nov 2026). Se aceptan también los nombres de
        // antes del formulario: «salon» = nombre comercial y «whatsapp» = celular.
        if (!isset($d['nombre_comercial'])) $d['nombre_comercial'] = $d['salon'] ?? '';
        if (!isset($d['celular'])) $d['celular'] = $d['whatsapp'] ?? '';
        [$v, $this->errores] = \TukanDemos::validar($d, 'salón o barbería');
        [$nombre, $salon, $telefono, $wa, $direccion, $ciudad, $email] = [$v['nombre'], $v['nombre_comercial'], $v['telefono'], $v['celular'], $v['direccion'], $v['ciudad'], $v['email']];
        $necesita = mb_substr(trim((string) ($d['necesita'] ?? '')), 0, 600);
        if ($this->errores) throw new RuntimeException(implode(' ', $this->errores));
        if ($ip !== '') {
            $st = $this->db->prepare("SELECT count(*) FROM pedidos_demo WHERE ip = ? AND creado > now() - interval '1 hour'");
            $st->execute([$ip]);
            if ((int) $st->fetchColumn() >= self::MAX_POR_HORA) throw new RuntimeException('Ya recibimos tus pedidos. Te escribimos pronto por WhatsApp.');
        }
        // El mismo correo con un pedido abierto: se actualiza en vez de repetir
        $st = $this->db->prepare("SELECT id FROM pedidos_demo WHERE lower(email) = ? AND estado IN ('nueva','contactada') ORDER BY id DESC LIMIT 1");
        $st->execute([$email]);
        $modulos = Modulos::texto(is_array($d['modulos'] ?? null) ? $d['modulos'] : []);
        if ($id = $st->fetchColumn()) {
            $this->db->prepare('UPDATE pedidos_demo SET nombre = ?, salon = ?, ciudad = ?, whatsapp = ?, telefono = ?, direccion = ?, necesita = COALESCE(?, necesita),
                                                    modulos = CASE WHEN length(?::text) = 0 THEN modulos ELSE ? END, actualizado = now() WHERE id = ?')
                     ->execute([$nombre, $salon, $ciudad, $wa, $telefono, $direccion, $necesita ?: null, $modulos, $modulos, $id]);
            return (int) $id;
        }
        $st = $this->db->prepare('INSERT INTO pedidos_demo (nombre, salon, ciudad, whatsapp, telefono, direccion, email, necesita, modulos, ip) VALUES (?,?,?,?,?,?,?,?,?,?) RETURNING id');
        $st->execute([$nombre, $salon, $ciudad, $wa, $telefono, $direccion, $email, $necesita ?: null, $modulos, mb_substr($ip, 0, 45) ?: null]);
        return (int) $st->fetchColumn();
    }

    public function pedido(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM pedidos_demo WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** Pedidos (los abiertos primero; con $todos también los cerrados de los últimos 120 días). */
    public function lista(bool $todos = false): array
    {
        $sql = 'SELECT * FROM pedidos_demo' . ($todos ? " WHERE estado IN ('nueva','contactada') OR actualizado > now() - interval '120 days'"
                                                       : " WHERE estado IN ('nueva','contactada')");
        return $this->db->query($sql . " ORDER BY estado IN ('nueva','contactada') DESC, creado DESC, id DESC LIMIT 300")->fetchAll();
    }

    public function marcar(int $id, string $estado, string $quien, string $nota = '', ?int $salonId = null): void
    {
        if (!isset(self::ESTADOS[$estado])) throw new RuntimeException('Estado no válido.');
        $this->db->prepare('UPDATE pedidos_demo SET estado = ?, atendido_por = ?, nota = COALESCE(?, nota), salon_creado_id = COALESCE(?, salon_creado_id), actualizado = now() WHERE id = ?')
                 ->execute([$estado, mb_substr($quien, 0, 200), mb_substr(trim($nota), 0, 300) ?: null, $salonId, $id]);
    }
}
